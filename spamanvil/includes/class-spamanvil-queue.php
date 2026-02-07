<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: All queries target custom plugin table (spamanvil_queue).
// Table name comes from $wpdb->prefix and is safe.

class SpamAnvil_Queue {

	private $table;
	private $provider_factory;
	private $stats;
	private $heuristics;
	private $ip_manager;

	public function __construct(
		SpamAnvil_Provider_Factory $provider_factory,
		SpamAnvil_Stats $stats,
		SpamAnvil_Heuristics $heuristics,
		SpamAnvil_IP_Manager $ip_manager
	) {
		global $wpdb;
		$this->table            = $wpdb->prefix . 'spamanvil_queue';
		$this->provider_factory = $provider_factory;
		$this->stats            = $stats;
		$this->heuristics       = $heuristics;
		$this->ip_manager       = $ip_manager;
	}

	public function enqueue( $comment_id, $heuristic_score = 0 ) {
		global $wpdb;

		$wpdb->insert(
			$this->table,
			array(
				'comment_id'      => absint( $comment_id ),
				'status'          => 'queued',
				'heuristic_score' => intval( $heuristic_score ),
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Process a batch of queued items.
	 *
	 * @param bool $force When true (manual "Process Queue Now"), retry failed items
	 *                    immediately regardless of backoff schedule.
	 */
	public function process_batch( $force = false ) {
		// Prevent concurrent execution with a transient lock.
		$lock_key = 'spamanvil_queue_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 300 ); // 5-minute lock.

		try {
			$batch_size = (int) get_option( 'spamanvil_batch_size', 5 );
			$items      = $this->claim_items( $batch_size, $force );

			foreach ( $items as $item ) {
				$this->process_single( $item );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	private function claim_items( $limit, $force = false ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// Reclaim items stuck in 'processing' for over 10 minutes (stale from crashed runs).
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'queued' WHERE status = 'processing' AND updated_at <= %s",
				$stale_cutoff
			)
		);

		if ( $force ) {
			// Manual trigger: grab all queued, failed and max_retries items immediately.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					WHERE status IN ('queued', 'failed', 'max_retries')
					ORDER BY created_at ASC
					LIMIT %d",
					$limit
				)
			);
		} else {
			// Cron: only grab failed items whose retry_at has passed.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table}
					WHERE (status = 'queued')
					   OR (status = 'failed' AND retry_at IS NOT NULL AND retry_at <= %s)
					ORDER BY created_at ASC
					LIMIT %d",
					$now,
					$limit
				)
			);
		}

		if ( empty( $items ) ) {
			return array();
		}

		$ids = wp_list_pluck( $items, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( $force ) {
			// Reset attempts so max_retries items get a fresh retry cycle.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET status = 'processing', updated_at = %s, attempts = 0 WHERE id IN ($placeholders)",
					array_merge( array( $now ), $ids )
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET status = 'processing', updated_at = %s WHERE id IN ($placeholders)",
					array_merge( array( $now ), $ids )
				)
			);
		}

		return $items;
	}

	public function process_single( $item ) {
		$comment = get_comment( $item->comment_id );

		if ( ! $comment ) {
			$this->update_status( $item->id, 'completed', array( 'reason' => 'Comment deleted' ) );
			return;
		}

		// Build prompts.
		$system_prompt = get_option( 'spamanvil_system_prompt', SpamAnvil_Activator::get_default_system_prompt() );
		$user_prompt   = $this->build_user_prompt( $comment, $item );

		$system_prompt = apply_filters( 'spamanvil_prompt', $system_prompt, 'system', $comment );
		$user_prompt   = apply_filters( 'spamanvil_prompt', $user_prompt, 'user', $comment );

		do_action( 'spamanvil_before_analysis', $comment, $item );

		// Choose strategy: Anvil Mode (all providers) or normal chain (first success).
		$anvil_mode = get_option( 'spamanvil_anvil_mode', '0' ) === '1';

		if ( $anvil_mode ) {
			$result = $this->try_anvil_mode( $item, $comment, $system_prompt, $user_prompt );
		} else {
			$result = $this->try_provider_chain( $item, $comment, $system_prompt, $user_prompt );
		}

		if ( is_wp_error( $result ) ) {
			// All providers failed.
			$error_msg = $result->get_error_message();
			$this->handle_failure( $item, $error_msg );
			return;
		}

		// Apply threshold.
		$threshold = (int) get_option( 'spamanvil_threshold', 70 );
		$threshold = apply_filters( 'spamanvil_threshold', $threshold, $comment );
		$is_spam   = $result['score'] >= $threshold;

		// Update queue item.
		$this->update_status( $item->id, 'completed', array(
			'score'    => $result['score'],
			'reason'   => $result['reason'],
			'provider' => $result['provider'],
			'model'    => $result['model'],
		) );

		// Log evaluation (in Anvil Mode, individual results are already logged).
		if ( ! $anvil_mode ) {
			$this->stats->log_evaluation( array(
				'comment_id'         => $item->comment_id,
				'score'              => $result['score'],
				'provider'           => $result['provider'],
				'model'              => $result['model'],
				'reason'             => $result['reason'],
				'heuristic_score'    => $item->heuristic_score,
				'heuristic_details'  => '',
				'processing_time_ms' => $result['processing_time_ms'],
			) );
		}

		// Update comment status.
		if ( $is_spam ) {
			wp_spam_comment( $item->comment_id );
			$this->stats->increment( 'spam_detected' );

			// Record IP spam attempt.
			$ip = get_comment_author_IP( $item->comment_id );
			if ( ! empty( $ip ) ) {
				$this->ip_manager->record_spam_attempt( $ip );
			}

			do_action( 'spamanvil_spam_detected', $comment, $result );
		} else {
			wp_set_comment_status( $item->comment_id, 'approve' );
			$this->stats->increment( 'ham_approved' );
		}

		$this->stats->increment( 'comments_checked' );

		do_action( 'spamanvil_after_analysis', $comment, $result, $is_spam );
	}

	/**
	 * Try each provider in the fallback chain until one succeeds.
	 *
	 * @param object     $item           Queue item.
	 * @param WP_Comment $comment        Comment object.
	 * @param string     $system_prompt  System prompt.
	 * @param string     $user_prompt    User prompt.
	 * @return array|WP_Error LLM result array on success, WP_Error if all providers failed.
	 */
	private function try_provider_chain( $item, $comment, $system_prompt, $user_prompt ) {
		$chain  = $this->provider_factory->get_provider_chain();
		$errors = array();

		if ( empty( $chain ) ) {
			$this->stats->increment( 'llm_errors' );
			$error_msg = 'No LLM provider configured';
			$this->stats->log_evaluation( array(
				'comment_id'        => $item->comment_id,
				'score'             => null,
				'provider'          => 'none',
				'model'             => 'none',
				'reason'            => 'Provider error: ' . $error_msg,
				'heuristic_score'   => $item->heuristic_score,
				'heuristic_details' => '',
			) );
			return new WP_Error( 'spamanvil_no_provider', $error_msg );
		}

		foreach ( $chain as $slug ) {
			$provider = $this->provider_factory->create( $slug );

			if ( is_wp_error( $provider ) ) {
				$errors[] = $slug . ': ' . $provider->get_error_message();
				continue;
			}

			$start_ms = microtime( true );
			$result   = $provider->analyze( $system_prompt, $user_prompt );
			$elapsed  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
			$this->stats->increment( 'llm_calls' );

			if ( ! is_wp_error( $result ) ) {
				// Success — return immediately.
				return $result;
			}

			// This provider failed — log the error and try next.
			$error_msg = $result->get_error_message();
			$errors[]  = $slug . ': ' . $error_msg;
			$this->stats->increment( 'llm_errors' );
			$this->stats->log_evaluation( array(
				'comment_id'         => $item->comment_id,
				'score'              => null,
				'provider'           => $slug,
				'model'              => '',
				'reason'             => 'LLM error (trying next provider): ' . $error_msg,
				'heuristic_score'    => $item->heuristic_score,
				'heuristic_details'  => '',
				'processing_time_ms' => $elapsed,
			) );
		}

		// All providers failed.
		$combined = implode( ' | ', $errors );
		return new WP_Error( 'spamanvil_all_providers_failed', $combined );
	}

	/**
	 * Anvil Mode: send comment to ALL configured providers and return the highest score.
	 *
	 * Each provider's result is logged individually. If any provider flags the comment
	 * as spam, the highest score is returned so the threshold check catches it.
	 *
	 * @param object     $item           Queue item.
	 * @param WP_Comment $comment        Comment object.
	 * @param string     $system_prompt  System prompt.
	 * @param string     $user_prompt    User prompt.
	 * @return array|WP_Error Highest-scoring result on success, WP_Error if all providers failed.
	 */
	private function try_anvil_mode( $item, $comment, $system_prompt, $user_prompt ) {
		$chain   = $this->provider_factory->get_provider_chain();
		$results = array();
		$errors  = array();

		if ( empty( $chain ) ) {
			$this->stats->increment( 'llm_errors' );
			$error_msg = 'No LLM provider configured';
			$this->stats->log_evaluation( array(
				'comment_id'        => $item->comment_id,
				'score'             => null,
				'provider'          => 'none',
				'model'             => 'none',
				'reason'            => 'Provider error: ' . $error_msg,
				'heuristic_score'   => $item->heuristic_score,
				'heuristic_details' => '',
			) );
			return new WP_Error( 'spamanvil_no_provider', $error_msg );
		}

		foreach ( $chain as $slug ) {
			$provider = $this->provider_factory->create( $slug );

			if ( is_wp_error( $provider ) ) {
				$errors[] = $slug . ': ' . $provider->get_error_message();
				continue;
			}

			$start_ms = microtime( true );
			$result   = $provider->analyze( $system_prompt, $user_prompt );
			$elapsed  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
			$this->stats->increment( 'llm_calls' );

			if ( is_wp_error( $result ) ) {
				$error_msg = $result->get_error_message();
				$errors[]  = $slug . ': ' . $error_msg;
				$this->stats->increment( 'llm_errors' );
				$this->stats->log_evaluation( array(
					'comment_id'         => $item->comment_id,
					'score'              => null,
					'provider'           => $slug,
					'model'              => '',
					'reason'             => 'Anvil Mode — LLM error: ' . $error_msg,
					'heuristic_score'    => $item->heuristic_score,
					'heuristic_details'  => '',
					'processing_time_ms' => $elapsed,
				) );
				continue;
			}

			// Log this provider's result individually.
			$this->stats->log_evaluation( array(
				'comment_id'         => $item->comment_id,
				'score'              => $result['score'],
				'provider'           => $result['provider'],
				'model'              => $result['model'],
				'reason'             => 'Anvil Mode — ' . $result['reason'],
				'heuristic_score'    => $item->heuristic_score,
				'heuristic_details'  => '',
				'processing_time_ms' => $result['processing_time_ms'],
			) );

			$results[] = $result;
		}

		if ( empty( $results ) ) {
			$combined = implode( ' | ', $errors );
			return new WP_Error( 'spamanvil_all_providers_failed', $combined );
		}

		// Return the result with the highest score (most suspicious verdict).
		usort( $results, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		return $results[0];
	}

	private function build_user_prompt( $comment, $item ) {
		$template = get_option( 'spamanvil_user_prompt', SpamAnvil_Activator::get_default_user_prompt() );

		$post = get_post( $comment->comment_post_ID );

		// Run heuristics for prompt context.
		$heuristic_analysis = $this->heuristics->analyze( array(
			'comment_content'      => $comment->comment_content,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_author_url'   => $comment->comment_author_url,
		) );

		$heuristic_data = $this->heuristics->format_for_prompt( $heuristic_analysis );

		// Sanitize comment content for prompt - truncate oversized content.
		$safe_content = $this->sanitize_for_prompt( $comment->comment_content );

		// URL analysis for prompt context.
		$author_has_url = ! empty( $comment->comment_author_url ) ? 'YES — be more critical of this comment' : 'No';
		$url_count      = count( wp_extract_urls( $comment->comment_content ) );

		$replacements = array(
			'{site_language}'   => self::get_site_language_name(),
			'{post_title}'      => $post ? $post->post_title : '',
			'{post_excerpt}'    => $post ? wp_trim_words( $post->post_content, 50, '...' ) : '',
			'{author_name}'     => $comment->comment_author,
			'{author_email}'    => $comment->comment_author_email,
			'{author_url}'      => $comment->comment_author_url,
			'{author_has_url}'  => $author_has_url,
			'{url_count}'       => $url_count,
			'{heuristic_data}'  => $heuristic_data,
			'{heuristic_score}' => $heuristic_analysis['score'],
			'{comment_content}' => $safe_content,
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Get human-readable site language name from WordPress locale.
	 *
	 * @return string Language name (e.g. "Portuguese (Brazil)", "English (US)").
	 */
	private static function get_site_language_name() {
		$locale = get_locale();

		$languages = array(
			'en_US' => 'English (US)',
			'en_GB' => 'English (UK)',
			'en_AU' => 'English (Australia)',
			'en_CA' => 'English (Canada)',
			'pt_BR' => 'Portuguese (Brazil)',
			'pt_PT' => 'Portuguese (Portugal)',
			'es_ES' => 'Spanish (Spain)',
			'es_MX' => 'Spanish (Mexico)',
			'es_AR' => 'Spanish (Argentina)',
			'fr_FR' => 'French (France)',
			'fr_CA' => 'French (Canada)',
			'de_DE' => 'German',
			'de_AT' => 'German (Austria)',
			'de_CH' => 'German (Switzerland)',
			'it_IT' => 'Italian',
			'nl_NL' => 'Dutch',
			'ru_RU' => 'Russian',
			'ja'    => 'Japanese',
			'zh_CN' => 'Chinese (Simplified)',
			'zh_TW' => 'Chinese (Traditional)',
			'ko_KR' => 'Korean',
			'ar'    => 'Arabic',
			'hi_IN' => 'Hindi',
			'tr_TR' => 'Turkish',
			'pl_PL' => 'Polish',
			'sv_SE' => 'Swedish',
			'da_DK' => 'Danish',
			'nb_NO' => 'Norwegian',
			'fi'    => 'Finnish',
			'he_IL' => 'Hebrew',
			'th'    => 'Thai',
			'vi'    => 'Vietnamese',
			'id_ID' => 'Indonesian',
			'uk'    => 'Ukrainian',
			'cs_CZ' => 'Czech',
			'el'    => 'Greek',
			'ro_RO' => 'Romanian',
			'hu_HU' => 'Hungarian',
		);

		if ( isset( $languages[ $locale ] ) ) {
			return $languages[ $locale ];
		}

		// Fallback: try just the language part (e.g. 'es' from 'es_CL').
		$lang = substr( $locale, 0, 2 );
		foreach ( $languages as $code => $name ) {
			if ( strpos( $code, $lang ) === 0 ) {
				return $name;
			}
		}

		// Last resort: return the locale code itself.
		return $locale;
	}

	/**
	 * Sanitize comment content to reduce prompt injection risks.
	 *
	 * Truncates extremely long content to prevent oversized payloads.
	 * The actual injection defense relies on:
	 * 1. <comment_data> boundary tags in the prompt template
	 * 2. System prompt explicitly instructing LLM to ignore comment instructions
	 * 3. Strict JSON response validation
	 * 4. Heuristic detection of injection patterns (raises spam score)
	 */
	private function sanitize_for_prompt( $content ) {
		if ( mb_strlen( $content ) > 5000 ) {
			$content = mb_substr( $content, 0, 5000 ) . "\n[Content truncated at 5000 characters]";
		}

		return $content;
	}

	private function handle_failure( $item, $error_message ) {
		global $wpdb;

		$attempts = (int) $item->attempts + 1;
		$max_retries = 3;

		if ( $attempts >= $max_retries ) {
			// Max retries exceeded - leave as pending for manual review.
			$wpdb->update(
				$this->table,
				array(
					'status'     => 'max_retries',
					'attempts'   => $attempts,
					'reason'     => sanitize_text_field( substr( $error_message, 0, 500 ) ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $item->id ),
				null,
				array( '%d' )
			);
			return;
		}

		// Exponential backoff: 60s, 300s, 900s.
		$delays    = array( 60, 300, 900 );
		$delay     = isset( $delays[ $attempts - 1 ] ) ? $delays[ $attempts - 1 ] : 900;
		$retry_at  = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$wpdb->update(
			$this->table,
			array(
				'status'     => 'failed',
				'attempts'   => $attempts,
				'retry_at'   => $retry_at,
				'reason'     => sanitize_text_field( substr( $error_message, 0, 500 ) ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $item->id ),
			null,
			array( '%d' )
		);
	}

	private function update_status( $id, $status, $data = array() ) {
		global $wpdb;

		$update = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( isset( $data['score'] ) ) {
			$update['score'] = intval( $data['score'] );
		}
		if ( isset( $data['reason'] ) ) {
			$update['reason'] = sanitize_text_field( $data['reason'] );
		}
		if ( isset( $data['provider'] ) ) {
			$update['provider'] = sanitize_text_field( $data['provider'] );
		}
		if ( isset( $data['model'] ) ) {
			$update['model'] = sanitize_text_field( $data['model'] );
		}

		$wpdb->update(
			$this->table,
			$update,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);
	}

	public function get_queue_status() {
		global $wpdb;

		return array(
			'queued'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'queued' ) ),
			'processing'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'processing' ) ),
			'failed'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'failed' ) ),
			'max_retries' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'max_retries' ) ),
			'completed'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", 'completed' ) ),
		);
	}
}
