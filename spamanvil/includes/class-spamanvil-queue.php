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

	public function process_batch() {
		// Prevent concurrent execution with a transient lock.
		$lock_key = 'spamanvil_queue_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 300 ); // 5-minute lock.

		try {
			$batch_size = (int) get_option( 'spamanvil_batch_size', 5 );
			$items      = $this->claim_items( $batch_size );

			foreach ( $items as $item ) {
				$this->process_single( $item );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	private function claim_items( $limit ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// Select queued items + failed items past retry_at, atomically claim them.
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

		if ( empty( $items ) ) {
			return array();
		}

		$ids = wp_list_pluck( $items, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'processing', updated_at = %s WHERE id IN ($placeholders)",
				array_merge( array( $now ), $ids )
			)
		);

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

		// Get provider.
		$provider = $this->provider_factory->create_with_fallback();

		if ( is_wp_error( $provider ) ) {
			$this->handle_failure( $item, $provider->get_error_message() );
			$this->stats->increment( 'llm_errors' );
			return;
		}

		// Call LLM.
		$result = $provider->analyze( $system_prompt, $user_prompt );
		$this->stats->increment( 'llm_calls' );

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $item, $result->get_error_message() );
			$this->stats->increment( 'llm_errors' );
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

		// Log evaluation.
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

		$replacements = array(
			'{post_title}'      => $post ? $post->post_title : '',
			'{post_excerpt}'    => $post ? wp_trim_words( $post->post_content, 50, '...' ) : '',
			'{author_name}'     => $comment->comment_author,
			'{author_email}'    => $comment->comment_author_email,
			'{author_url}'      => $comment->comment_author_url,
			'{heuristic_data}'  => $heuristic_data,
			'{heuristic_score}' => $heuristic_analysis['score'],
			'{comment_content}' => $safe_content,
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
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
