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

	/**
	 * Comment meta holding the verdict-cache key that decided a comment, so a
	 * moderator's later correction can evict that entry.
	 */
	const VERDICT_KEY_META = '_spamanvil_verdict_key';

	/**
	 * Comment meta recording that a person (or another plugin) moderated the
	 * comment — the status they chose. While it is present the automatic paths
	 * leave the comment alone; a manual "Scan pending" is the request to re-analyze.
	 */
	const MODERATED_META = '_spamanvil_moderated';

	/**
	 * True while the plugin itself is changing a comment's status, so its own
	 * transitions are not mistaken for a moderator's decision.
	 *
	 * @var bool
	 */
	private static $applying_verdict = false;

	/**
	 * Shortest HTTP timeout worth starting a model call with. Below this the call
	 * would almost certainly be cut off, so the item waits for the next run instead.
	 */
	const MIN_CALL_SECONDS = 8;

	/**
	 * Default per-call HTTP timeout when the run has no deadline (sync mode).
	 */
	const DEFAULT_CALL_SECONDS = 60;

	/**
	 * microtime() by which the current batch must be done; 0 = no deadline.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Model calls made for the item being processed (decides how running out of
	 * time is recorded — see process_single()).
	 *
	 * @var int
	 */
	private $calls_this_item = 0;

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

		// All queue timestamps are stored in UTC (GMT) so they compare correctly
		// against the gmdate()-based cutoffs used in claim_items()/handle_failure().
		// Mixing local time (current_time('mysql')) with UTC cutoffs breaks the
		// SQL string comparisons on any site whose timezone is not UTC.
		$wpdb->insert(
			$this->table,
			array(
				'comment_id'      => absint( $comment_id ),
				'status'          => 'queued',
				'heuristic_score' => intval( $heuristic_score ),
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Process a batch of queued items.
	 *
	 * @param bool $force      When true (manual "Process Queue Now"), retry failed items
	 *                         immediately regardless of backoff schedule.
	 * @param int  $time_limit Maximum seconds to spend processing. 0 = no limit (cron default).
	 *                         When set, the loop stops after each item if elapsed time exceeds
	 *                         the limit, and releases remaining claimed items back to the queue.
	 * @return int Number of items processed.
	 */
	public function process_batch( $force = false, $time_limit = 0 ) {
		// Turned off on the General tab: no analysis, no API spend — manual runs included.
		// Queued items simply wait and are processed once SpamAnvil is switched back on.
		if ( ! SpamAnvil::is_enabled() ) {
			return 0;
		}

		// Prevent concurrent execution with a transient lock.
		$lock_key = 'spamanvil_queue_lock';
		if ( ! $force && get_transient( $lock_key ) ) {
			return 0;
		}
		set_transient( $lock_key, true, 300 ); // 5-minute lock.

		update_option( 'spamanvil_last_cron_run', time(), false );

		// Default time limit for cron: 50 seconds (safe for most hosts).
		if ( 0 === $time_limit && ! $force ) {
			$time_limit = 50;
		}

		$processed  = 0;
		$start_time = microtime( true );

		// The budget covers every model call, not just the gaps between comments: a
		// chain of models with 60s timeouts each could otherwise run for minutes past
		// the limit (the check used to happen only after a whole comment was done).
		$this->deadline = $time_limit > 0 ? $start_time + $time_limit : 0.0;

		try {
			// A queue paused on a permanent configuration error (missing/undecryptable
			// key, no provider) stays paused until the provider config changes — cron
			// runs return immediately instead of re-failing every item and flooding the
			// logs. Manual runs (force) always try again: if the config is still broken
			// the first item re-pauses; if it was fixed, is_paused() already resumed.
			if ( ! $force && $this->is_paused() ) {
				return 0;
			}
			$batch_size     = (int) get_option( 'spamanvil_batch_size', 5 );
			$auto_enqueued  = false;

			// Loop through batches until queue is empty or time runs out.
			do {
				$items = $this->claim_items( $batch_size, $force );

				if ( empty( $items ) ) {
					// Queue is empty — try to auto-enqueue pending WordPress comments.
					if ( ! $auto_enqueued && ! $force ) {
						$auto_enqueued  = true;
						$newly_enqueued = $this->auto_enqueue_pending();
						if ( $newly_enqueued > 0 ) {
							continue; // Re-enter loop to process newly enqueued items.
						}
					}
					break;
				}

				foreach ( $items as $item ) {
					$outcome = $this->process_single( $item );

					// A permanent config error paused the queue mid-batch: put the
					// remaining claimed items back and stop — retrying them now would
					// only produce identical failures and identical log rows.
					if ( 'paused' === $outcome || 'out_of_time' === $outcome ) {
						$current_index = array_search( $item, $items, true );
						$remaining     = array_slice( $items, $current_index + 1 );
						$remaining_ids = wp_list_pluck( $remaining, 'id' );
						if ( ! empty( $remaining_ids ) ) {
							$this->release_items( $remaining_ids );
						}
						return $processed;
					}

					$processed++;

					// Time guard: stop if approaching limit.
					if ( $time_limit > 0 ) {
						$elapsed = microtime( true ) - $start_time;
						if ( $elapsed >= $time_limit ) {
							// Release unclaimed items back to queue.
							$current_index = array_search( $item, $items, true );
							$remaining     = array_slice( $items, $current_index + 1 );
							$remaining_ids = wp_list_pluck( $remaining, 'id' );
							if ( ! empty( $remaining_ids ) ) {
								$this->release_items( $remaining_ids );
							}
							return $processed;
						}
					}
				}

				// After force-processing one batch, stop looping (AJAX handles its own loop).
				if ( $force ) {
					break;
				}

			} while ( true );
		} finally {
			delete_transient( $lock_key );
			$this->deadline = 0.0;
		}

		return $processed;
	}

	/**
	 * Release claimed items back to 'queued' status so they can be picked up next round.
	 *
	 * @param array $ids Queue item IDs to release.
	 */
	private function release_items( $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'queued', updated_at = %s WHERE id IN ($placeholders)",
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
	}

	/**
	 * Pause queue processing because of a permanent configuration error.
	 *
	 * The pause records the current provider-config hash: is_paused() auto-resumes
	 * as soon as the configuration changes, so the admin never has to "unpause"
	 * manually — fixing the key/provider is enough.
	 *
	 * @param string $code    WP_Error code that caused the pause.
	 * @param string $message Human-readable reason (shown in the health notice).
	 */
	public function pause( $code, $message ) {
		update_option(
			'spamanvil_queue_paused',
			array(
				'code'        => (string) $code,
				'message'     => (string) $message,
				'config_hash' => $this->provider_factory->get_config_hash(),
				'paused_at'   => time(),
			),
			false
		);
	}

	/**
	 * Whether the queue is paused on a configuration error.
	 *
	 * Auto-resumes (clears the pause) when the provider configuration changed since
	 * the pause was recorded — the admin fixed something, so it's worth trying again.
	 *
	 * @return bool
	 */
	public function is_paused() {
		$paused = get_option( 'spamanvil_queue_paused', array() );

		if ( empty( $paused ) || ! is_array( $paused ) ) {
			return false;
		}

		$stored_hash = isset( $paused['config_hash'] ) ? $paused['config_hash'] : '';
		if ( $this->provider_factory->get_config_hash() !== $stored_hash ) {
			$this->resume();
			return false;
		}

		return true;
	}

	/**
	 * Pause details for the admin health notice, or null when not paused.
	 *
	 * @return array|null { code, message, paused_at }
	 */
	public function get_pause_info() {
		$paused = get_option( 'spamanvil_queue_paused', array() );
		return ( ! empty( $paused ) && is_array( $paused ) ) ? $paused : null;
	}

	/**
	 * Clear the configuration pause.
	 */
	public function resume() {
		delete_option( 'spamanvil_queue_paused' );
	}

	/**
	 * Cron (daily): purge completed queue rows older than the log retention window.
	 * Failed/max_retries rows are never purged — they are pending work that the
	 * resurrection cycles will retry. Without this the queue table grew forever.
	 */
	public function purge_completed() {
		global $wpdb;

		$retention = (int) get_option( 'spamanvil_log_retention', 30 );
		if ( $retention <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE status = 'completed' AND updated_at <= %s",
				$cutoff
			)
		);
	}

	private function claim_items( $limit, $force = false ) {
		global $wpdb;

		// UTC (GMT) to match retry_at, which is written with gmdate() in handle_failure().
		$now = current_time( 'mysql', true );

		// Reclaim items stuck in 'processing' for over 10 minutes (stale from crashed runs).
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'queued' WHERE status = 'processing' AND updated_at <= %s",
				$stale_cutoff
			)
		);

		// Reclaim max_retries items — but never on the old unconditional hourly loop,
		// which recycled permanently-failing items forever (fixed in 1.12.0). A fresh
		// retry cycle is granted when (1.14.0):
		//   1. The provider configuration changed since the last cycle (the admin
		//      fixed something) — everything parked ≥1h gets a clean slate.
		//   2. The provider PROVED healthy again — a successful classification or Test
		//      Connection happened after the item's last failure — so an outage
		//      recovers within ~1h instead of waiting for the daily net. Capped at 5
		//      fast cycles per item, so a "poison" comment that every model always
		//      fails on cannot churn API calls and logs forever.
		//   3. The daily safety net (24h) — every item is eventually retried no
		//      matter what, with no cap.
		$config_hash  = $this->provider_factory->get_config_hash();
		$hash_changed = get_option( 'spamanvil_resurrect_config_hash', '' ) !== $config_hash;
		$hour_ago     = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$day_ago      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		if ( $hash_changed ) {
			$reclaimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET status = 'queued', attempts = 0, resurrections = 0
					WHERE status = 'max_retries' AND updated_at <= %s",
					$hour_ago
				)
			);
			if ( $reclaimed ) {
				update_option( 'spamanvil_resurrect_config_hash', $config_hash, false );
			}
		} else {
			$last_success = (int) get_option( 'spamanvil_last_llm_success', 0 );
			$success_dt   = $last_success > 0 ? gmdate( 'Y-m-d H:i:s', $last_success ) : '1970-01-01 00:00:00';

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET status = 'queued', attempts = 0, resurrections = resurrections + 1
					WHERE status = 'max_retries' AND (
						( updated_at <= %s AND updated_at < %s AND resurrections < 5 )
						OR updated_at <= %s
					)",
					$hour_ago,
					$success_dt,
					$day_ago
				)
			);
		}

		// Atomically claim up to $limit eligible items. Each row is taken with a
		// compare-and-swap UPDATE guarded by its current status, so two concurrent
		// runs (e.g. WP-Cron and a manual "Process Queue Now") can never claim the
		// same row and double-call the LLM. The previous SELECT-then-UPDATE was racy.
		$claimed = array();
		$budget  = $limit * 3; // Bound the work even under heavy contention.

		while ( count( $claimed ) < $limit && $budget-- > 0 ) {
			if ( $force ) {
				// Manual trigger: queued, failed and max_retries items are all eligible.
				$id = $wpdb->get_var(
					"SELECT id FROM {$this->table}
					WHERE status IN ('queued', 'failed', 'max_retries')
					ORDER BY created_at ASC
					LIMIT 1"
				);
			} else {
				// Cron: queued items, plus failed items whose retry_at has passed.
				$id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$this->table}
						WHERE (status = 'queued')
						   OR (status = 'failed' AND retry_at IS NOT NULL AND retry_at <= %s)
						ORDER BY created_at ASC
						LIMIT 1",
						$now
					)
				);
			}

			if ( ! $id ) {
				break; // No more eligible items.
			}

			if ( $force ) {
				$affected = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->table} SET status = 'processing', updated_at = %s, attempts = 0
						WHERE id = %d AND status IN ('queued', 'failed', 'max_retries')",
						$now,
						$id
					)
				);
			} else {
				$affected = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->table} SET status = 'processing', updated_at = %s
						WHERE id = %d
						  AND ( status = 'queued'
						        OR ( status = 'failed' AND retry_at IS NOT NULL AND retry_at <= %s ) )",
						$now,
						$id,
						$now
					)
				);
			}

			if ( $affected ) {
				$claimed[] = (int) $id;
			}
			// $affected === 0 means another worker claimed this row between our SELECT
			// and UPDATE; loop again — the next SELECT will skip it.
		}

		if ( empty( $claimed ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $claimed ), '%d' ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated list of %d, one per claimed id; the sniff cannot see placeholders built at runtime.
				"SELECT * FROM {$this->table} WHERE id IN ($placeholders) ORDER BY created_at ASC",
				$claimed
			)
		);
	}

	public function process_single( $item ) {
		$comment = get_comment( $item->comment_id );

		if ( ! $comment ) {
			$this->update_status( $item->id, 'completed', array( 'reason' => 'Comment deleted' ) );
			return;
		}

		// A person (or another plugin) already decided while the comment waited in the
		// queue: never overrule them — a comment sent to the trash must not come back
		// approved. The item is closed without spending an API call.
		$state  = $this->fresh_moderation_state( $item->comment_id );
		$status = $state['status'];
		if ( self::human_decided( $status, $this->expects_approved( $item ), $state['moderated'] ) ) {
			$this->update_status( $item->id, 'completed', array(
				'reason' => sprintf( 'Moderated manually before analysis (%s)', $status ),
			) );
			return 'skipped';
		}

		do_action( 'spamanvil_before_analysis', $comment, $item );

		$anvil_mode = get_option( 'spamanvil_anvil_mode', '0' ) === '1';

		// Build prompts.
		$system_prompt = get_option( 'spamanvil_system_prompt', SpamAnvil_Activator::get_default_system_prompt() );
		$user_prompt   = $this->build_user_prompt( $comment, $item );

		$system_prompt = apply_filters( 'spamanvil_prompt', $system_prompt, 'system', $comment );
		$user_prompt   = apply_filters( 'spamanvil_prompt', $user_prompt, 'user', $comment );

		// Reuse a recent verdict for an identical classification request to avoid
		// paying for repeated LLM calls on the same spam. Anvil Mode logs per-provider
		// results, so it always evaluates fresh and never uses the cache.
		$cache_key  = ( $anvil_mode || '1' !== get_option( 'spamanvil_cache_enabled', '1' ) )
			? ''
			: self::verdict_cache_key( $system_prompt, $user_prompt, $this->provider_factory->get_config_hash() );
		$result     = null;
		$from_cache = false;

		if ( $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['score'], $cached['reason'] ) ) {
				$result     = $cached;
				$from_cache = true;
			}
		}

		if ( ! $from_cache ) {
			$this->calls_this_item = 0;

			// Choose strategy: Anvil Mode (all providers) or normal chain (first success).
			if ( $anvil_mode ) {
				$result = $this->try_anvil_mode( $item, $comment, $system_prompt, $user_prompt );
			} else {
				$result = $this->try_provider_chain( $item, $comment, $system_prompt, $user_prompt );
			}

			if ( is_wp_error( $result ) && 'spamanvil_out_of_time' === $result->get_error_code() ) {
				// The batch budget ran out. If no model was even asked, the item goes back
				// untouched for the next run. If some were asked and none answered in time,
				// it counts as a normal failure (retry with backoff) — otherwise a model that
				// always hangs would keep the item cycling forever without ever using up
				// its retries.
				if ( 0 === $this->calls_this_item ) {
					if ( $item->id > 0 ) {
						$this->release_items( array( $item->id ) );
					}
					return 'out_of_time';
				}
				$this->handle_failure( $item, $result->get_error_message() );
				return 'out_of_time';
			}

			if ( is_wp_error( $result ) ) {
				// A permanent configuration error (missing/undecryptable key, no
				// provider/model) cannot be fixed by retrying: pause the queue instead
				// of burning this item's retry attempts. The item goes back to 'queued'
				// untouched and processing resumes automatically once the provider
				// configuration changes (config-hash comparison in is_paused()).
				if ( SpamAnvil_Provider_Factory::is_permanent_config_error_code( $result->get_error_code() ) ) {
					$this->pause( $result->get_error_code(), $result->get_error_message() );
					if ( $item->id > 0 ) {
						$this->release_items( array( $item->id ) );
					}
					return 'paused';
				}

				// Transient failure (network, rate limit, provider outage) — normal
				// retry/backoff cycle.
				$this->handle_failure( $item, $result->get_error_message() );
				return 'failed';
			}

			// Record provider health: a fresh successful call is the signal that lets
			// claim_items() give parked max_retries items an early retry cycle after an
			// outage. Throttled to one option write per minute.
			$last_success = (int) get_option( 'spamanvil_last_llm_success', 0 );
			if ( time() - $last_success > MINUTE_IN_SECONDS ) {
				update_option( 'spamanvil_last_llm_success', time(), false );
			}
		} else {
			$this->stats->increment( 'cache_hits' );
		}

		// Apply threshold.
		$threshold = (int) get_option( 'spamanvil_threshold', 70 );
		$threshold = apply_filters( 'spamanvil_threshold', $threshold, $comment );
		$is_spam   = $result['score'] >= $threshold;

		// The LLM call can take a minute: look again before acting. If someone
		// moderated the comment meanwhile, record the verdict but leave their decision
		// (and the verdict cache) alone.
		$state      = $this->fresh_moderation_state( $item->comment_id );
		$status     = $state['status'];
		$overridden = self::human_decided( $status, $this->expects_approved( $item ), $state['moderated'] );

		// Update queue item.
		$this->update_status( $item->id, 'completed', array(
			'score'    => $result['score'],
			'reason'   => $overridden
				? sprintf( 'Moderated manually during analysis (%s). AI verdict: %s', $status, $result['reason'] )
				: $result['reason'],
			'provider' => $result['provider'],
			'model'    => $result['model'],
		) );

		// Log evaluation (in Anvil Mode, individual results are already logged).
		if ( ! $anvil_mode ) {
			$this->stats->log_evaluation( array(
				'comment_id'         => $item->comment_id,
				'score'              => $result['score'],
				'provider'           => $from_cache ? $result['provider'] . ' (cached)' : $result['provider'],
				'model'              => $result['model'],
				'reason'             => $result['reason'],
				'heuristic_score'    => $item->heuristic_score,
				'heuristic_details'  => '',
				'processing_time_ms' => $from_cache ? 0 : ( isset( $result['processing_time_ms'] ) ? $result['processing_time_ms'] : 0 ),
			) );
		}

		if ( $overridden ) {
			do_action( 'spamanvil_after_analysis', $comment, $result, $is_spam );
			return;
		}

		// Cache the fresh verdict (raw score/reason; the threshold is applied per-read),
		// and remember which entry decided this comment so a moderator's correction
		// can evict it (see on_comment_status_change()).
		if ( $cache_key ) {
			if ( ! $from_cache ) {
				$this->store_verdict_cache( $cache_key, $result );
			}
			update_comment_meta( $item->comment_id, self::VERDICT_KEY_META, $cache_key );
		}

		// Update comment status. The flag tells on_comment_status_change() that this
		// transition is the plugin's own, not a moderator's.
		$previous_flag          = self::$applying_verdict;
		self::$applying_verdict = true;
		if ( $is_spam ) {
			wp_spam_comment( $item->comment_id );
			$this->stats->increment( 'spam_detected' );

			// Record IP spam attempt.
			$ip = $this->ip_manager->get_comment_ip( $item->comment_id );
			if ( ! empty( $ip ) ) {
				$this->ip_manager->record_spam_attempt( $ip );
			}

			do_action( 'spamanvil_spam_detected', $comment, $result );
		} else {
			wp_set_comment_status( $item->comment_id, 'approve' );
			$this->stats->increment( 'ham_approved' );

			// Smart email mode: the insert-time notification was held back — now that
			// the comment is verified ham and approved, tell the post author.
			SpamAnvil_Notifier::send_postauthor( $item->comment_id );
		}
		self::$applying_verdict = $previous_flag;

		$this->stats->increment( 'comments_checked' );

		do_action( 'spamanvil_after_analysis', $comment, $result, $is_spam );
	}

	/**
	 * Build the verdict-cache key for a classification request.
	 *
	 * Keyed on the exact prompts sent to the model, so the cache can only return a
	 * verdict for a request that is the same in every respect the model sees: the
	 * comment, the author's name/email/URL, the post it was left on, the site
	 * language and the prompt templates. Keying on content + author URL alone (until
	 * 1.18.1) let a verdict follow the text onto another post, another author, or
	 * past a prompt fix. Case and whitespace are normalized so trivial reposts of the
	 * same spam still share an entry.
	 *
	 * The provider configuration (chain, models, keys) is part of the key too
	 * (1.19.1): switching to another model must not keep serving the old model's
	 * verdicts.
	 *
	 * @param string $system_prompt System prompt, after filters.
	 * @param string $user_prompt   User prompt, after filters.
	 * @param string $config_hash   SpamAnvil_Provider_Factory::get_config_hash().
	 * @return string Transient key.
	 */
	public static function verdict_cache_key( $system_prompt, $user_prompt, $config_hash = '' ) {
		$normalize = function ( $text ) {
			return preg_replace( '/\s+/u', ' ', mb_strtolower( trim( (string) $text ) ) );
		};

		return 'spamanvil_vc_' . hash( 'sha256', (string) $config_hash . "\0" . $normalize( $system_prompt ) . "\0" . $normalize( $user_prompt ) );
	}

	/**
	 * Whether a comment's current status means someone other than the plugin has
	 * already decided it, so the queued analysis must not overwrite that decision.
	 *
	 * Spam and trash always count. "Approved" counts only when the plugin itself
	 * would have left the comment pending: in Open Mode and Sync mode comments are
	 * published before analysis, so approval there is the expected state.
	 *
	 * A recorded moderation (MODERATED_META) always wins, whatever the status —
	 * including a comment a moderator sent back to pending, which by status alone
	 * looks exactly like one still waiting for its first analysis.
	 *
	 * @param string|false $status           'approved' / 'unapproved' / 'spam' / 'trash'.
	 * @param bool         $expects_approved Whether an approved status is normal here.
	 * @param bool         $moderated        Whether a moderation was recorded.
	 * @return bool
	 */
	public static function human_decided( $status, $expects_approved, $moderated = false ) {
		if ( $moderated || 'spam' === $status || 'trash' === $status ) {
			return true;
		}

		return 'approved' === $status && ! $expects_approved;
	}

	/**
	 * Whether a comment being analyzed is expected to be approved already.
	 *
	 * @param object $item Queue item (id 0 = synchronous analysis at submit time).
	 * @return bool
	 */
	private function expects_approved( $item ) {
		if ( 0 === (int) $item->id ) {
			return true; // Sync mode: whatever WordPress decided at insert is expected.
		}

		return '1' === get_option( 'spamanvil_open_mode', '0' );
	}

	/**
	 * HTTP timeout for the next model call given the time left in the batch.
	 *
	 * Time is held back for the models still behind this one in the chain
	 * (MIN_CALL_SECONDS each). Without that reserve (1.20.0) a primary model that
	 * hangs took the whole budget on every attempt, the chain restarted from it on
	 * the next run, and the healthy fallback was never called at all. The call
	 * always gets at least MIN_CALL_SECONDS while that much time is left.
	 *
	 * @param float|null $remaining   Seconds left before the deadline; null = no deadline.
	 * @param int        $calls_after Models still behind this one in the chain.
	 * @param int        $default     Timeout to use when time is plentiful.
	 * @param int        $min         Below this, do not start a call at all.
	 * @return int Seconds to allow, or 0 when there is no time for a call.
	 */
	public static function call_timeout( $remaining, $calls_after = 0, $default = self::DEFAULT_CALL_SECONDS, $min = self::MIN_CALL_SECONDS ) {
		if ( null === $remaining ) {
			return (int) $default;
		}
		if ( $remaining < $min ) {
			return 0;
		}

		$share = max( $min, $remaining - ( max( 0, (int) $calls_after ) * $min ) );

		return (int) min( $default, floor( $share ), floor( $remaining ) );
	}

	/**
	 * Seconds left before the batch deadline, or null when there is none.
	 *
	 * @return float|null
	 */
	private function remaining_seconds() {
		return $this->deadline > 0 ? $this->deadline - microtime( true ) : null;
	}

	/**
	 * Model list per provider, in chain order ('' when none is configured, so
	 * create() surfaces the no-model error).
	 *
	 * @param string[] $chain Provider slugs.
	 * @return array<int, string[]>
	 */
	private function model_lists( $chain ) {
		$lists = array();
		foreach ( array_values( $chain ) as $slug ) {
			$models  = $this->provider_factory->get_model_chain( $slug );
			$lists[] = empty( $models ) ? array( '' ) : array_values( $models );
		}
		return $lists;
	}

	/**
	 * How many models of a chain come after position ($provider_index, $model_index).
	 *
	 * @param int[] $counts         Models per provider, in chain order.
	 * @param int   $provider_index Current provider position.
	 * @param int   $model_index    Current model position within that provider.
	 * @return int
	 */
	public static function calls_after( array $counts, $provider_index, $model_index ) {
		$after = max( 0, (int) $counts[ $provider_index ] - $model_index - 1 );
		for ( $i = $provider_index + 1, $n = count( $counts ); $i < $n; $i++ ) {
			$after += (int) $counts[ $i ];
		}
		return $after;
	}

	/**
	 * call_timeout() for the current batch.
	 *
	 * @return int
	 */
	private function call_budget( $calls_after = 0 ) {
		return self::call_timeout( $this->remaining_seconds(), $calls_after );
	}

	/**
	 * @param string[] $errors Errors gathered so far in the chain.
	 * @return WP_Error
	 */
	private function out_of_time_error( $errors ) {
		$message = 'Batch time budget exhausted before a model answered';
		if ( ! empty( $errors ) ) {
			$message .= ': ' . implode( ' | ', $errors );
		}
		return new WP_Error( 'spamanvil_out_of_time', $message );
	}

	/**
	 * Read a comment's status and moderation mark straight from the database.
	 *
	 * Not wp_get_comment_status(): that reads the per-request object cache, which
	 * the cron request filled when it started. A moderator acting in another request
	 * while the model was answering would be invisible to it — the re-check before
	 * applying a verdict would always see the stale copy (1.19.0 shipped exactly that).
	 *
	 * @param int $comment_id Comment ID.
	 * @return array{status: string|false, moderated: bool}
	 */
	private function fresh_moderation_state( $comment_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bypassing the cache is the point.
		$approved  = $wpdb->get_var( $wpdb->prepare(
			"SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d",
			$comment_id
		) );
		$moderated = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key = %s LIMIT 1",
			$comment_id,
			self::MODERATED_META
		) );
		// phpcs:enable

		return array(
			'status'    => self::status_name( $approved ),
			'moderated' => $moderated,
		);
	}

	/**
	 * Map a raw comment_approved value to wp_get_comment_status() vocabulary.
	 *
	 * @param string|null $approved Raw DB value.
	 * @return string|false
	 */
	public static function status_name( $approved ) {
		switch ( (string) $approved ) {
			case '1':
				return 'approved';
			case '0':
				return 'unapproved';
			case 'spam':
				return 'spam';
			case 'trash':
			case 'post-trashed':
				return 'trash';
		}
		return false;
	}

	/**
	 * Run a status change the plugin makes on its own behalf (verdicts, traps,
	 * heuristics), so on_comment_status_change() does not record it as a moderator's.
	 *
	 * @param callable $change Callback that changes the comment status.
	 * @return mixed The callback's return value.
	 */
	public static function as_plugin( $change ) {
		$previous               = self::$applying_verdict;
		self::$applying_verdict = true;
		try {
			return call_user_func( $change );
		} finally {
			self::$applying_verdict = $previous;
		}
	}

	/**
	 * Hook: transition_comment_status. A moderator's decision closes the matter.
	 *
	 * Open queue items for the comment are completed (no API call is spent on a
	 * comment someone already judged), and if the verdict cache decided it, that
	 * entry is evicted so the same text is not auto-judged the wrong way again.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_comment_status_change( $new_status, $old_status, $comment ) {
		if ( self::$applying_verdict || 'new' === $old_status || $new_status === $old_status ) {
			return;
		}

		if ( ! in_array( $new_status, array( 'approved', 'unapproved', 'spam', 'trash' ), true ) ) {
			return;
		}

		// Remembered durably, so every later automatic path — the queue, auto-enqueue
		// of pending comments — knows a person has already looked at this comment.
		update_comment_meta( $comment->comment_ID, self::MODERATED_META, $new_status );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table} SET status = 'completed', reason = %s, updated_at = %s
			WHERE comment_id = %d AND status IN ('queued', 'failed', 'max_retries')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			sprintf( 'Moderated manually (%s)', $new_status ),
			current_time( 'mysql', true ),
			$comment->comment_ID
		) );

		$key = get_comment_meta( $comment->comment_ID, self::VERDICT_KEY_META, true );
		if ( $key ) {
			delete_transient( $key );
			delete_comment_meta( $comment->comment_ID, self::VERDICT_KEY_META );
		}
	}

	/**
	 * Store a fresh LLM verdict for later reuse. Only score/reason/provider/model are
	 * cached; the spam threshold is applied at read time so threshold changes take effect.
	 *
	 * @param string $cache_key Key from verdict_cache_key().
	 * @param array  $result    LLM result array.
	 */
	private function store_verdict_cache( $cache_key, $result ) {
		$days = (int) get_option( 'spamanvil_cache_ttl_days', 7 );
		if ( $days < 1 ) {
			$days = 7;
		}

		set_transient(
			$cache_key,
			array(
				'score'    => (int) $result['score'],
				'reason'   => $result['reason'],
				'provider' => $result['provider'],
				'model'    => $result['model'],
			),
			$days * DAY_IN_SECONDS
		);
	}

	/**
	 * Try each provider — and each model in its configured model chain — until one succeeds.
	 *
	 * The model field accepts a comma-separated list (1.12.0), so a single provider can
	 * carry its own fallback sequence (e.g. two free OpenRouter models, then a paid one).
	 * Order: providers in chain order; within a provider, models in list order; the
	 * dynamic free-model discovery (auto free fallback) runs as a last resort per provider.
	 *
	 * @param object     $item           Queue item.
	 * @param WP_Comment $comment        Comment object.
	 * @param string     $system_prompt  System prompt.
	 * @param string     $user_prompt    User prompt.
	 * @return array|WP_Error LLM result array on success. WP_Error with code
	 *                        'spamanvil_config_error' when every failure was a permanent
	 *                        configuration problem (drives the queue pause), otherwise
	 *                        'spamanvil_all_providers_failed'.
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

		$all_permanent = true;

		$model_lists = $this->model_lists( $chain );
		$counts      = array_map( 'count', $model_lists );

		foreach ( array_values( $chain ) as $p_index => $slug ) {
			$models = $model_lists[ $p_index ];

			$last_error = null;

			foreach ( $models as $m_index => $model ) {
				$provider = $this->provider_factory->create( $slug, '' !== $model ? array( 'model' => $model ) : array() );

				if ( is_wp_error( $provider ) ) {
					// Creation failures (key/config) are per-provider, not per-model —
					// trying the rest of the model list without a usable key is pointless.
					$error_msg = $provider->get_error_message();
					$errors[]  = $slug . ': ' . $error_msg;
					$this->stats->increment( 'llm_errors' );
					$this->stats->log_evaluation( array(
						'comment_id'        => $item->comment_id,
						'score'             => null,
						'provider'          => $slug,
						'model'             => '',
						'reason'            => 'Provider unavailable (' . $provider->get_error_code() . '): ' . $error_msg,
						'heuristic_score'   => $item->heuristic_score,
						'heuristic_details' => '',
					) );
					if ( ! SpamAnvil_Provider_Factory::is_permanent_config_error_code( $provider->get_error_code() ) ) {
						$all_permanent = false;
					}
					continue 2; // Next provider.
				}

				$timeout = $this->call_budget( self::calls_after( $counts, $p_index, $m_index ) );
				if ( 0 === $timeout ) {
					return $this->out_of_time_error( $errors );
				}
				$provider->set_timeout( $timeout );

				$start_ms = microtime( true );
				$result   = $provider->analyze( $system_prompt, $user_prompt );
				$elapsed  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
				$this->stats->increment( 'llm_calls' );
				++$this->calls_this_item;

				if ( ! is_wp_error( $result ) ) {
					// Success — return immediately.
					return $result;
				}

				// Runtime failure (model gone, rate limit, network) — transient by
				// definition here; log it and move to the next model in the list.
				$all_permanent = false;
				$last_error    = $result;
				$error_msg     = $result->get_error_message();
				$errors[]      = $slug . ( '' !== $model ? '/' . $model : '' ) . ': ' . $error_msg;
				$this->stats->increment( 'llm_errors' );
				$this->stats->log_evaluation( array(
					'comment_id'         => $item->comment_id,
					'score'              => null,
					'provider'           => $slug,
					'model'              => $model,
					'reason'             => 'LLM error (trying next model/provider): ' . $error_msg,
					'heuristic_score'    => $item->heuristic_score,
					'heuristic_details'  => '',
					'processing_time_ms' => $elapsed,
				) );
			}

			// The whole configured model list failed — as a last resort, auto-discover
			// a free replacement model (if the option is enabled and the error matches).
			if ( $last_error ) {
				$switched = $this->try_free_model_fallback( $item, $slug, $last_error, $system_prompt, $user_prompt );
				if ( ! is_wp_error( $switched ) ) {
					return $switched;
				}
			}
		}

		// All providers failed.
		$combined = implode( ' | ', $errors );

		if ( $all_permanent && ! empty( $errors ) ) {
			return new WP_Error( 'spamanvil_config_error', $combined );
		}

		return new WP_Error( 'spamanvil_all_providers_failed', $combined );
	}

	/**
	 * When a provider's configured model is unavailable, find a free alternative from the
	 * provider's live model list, retry with it, and (on success) persist it so the plugin
	 * self-heals. Free models — especially OpenRouter's — are deprecated/removed frequently.
	 *
	 * @param object     $item           Queue item.
	 * @param string     $slug           Provider slug.
	 * @param WP_Error   $original_error The model-unavailable error.
	 * @param string     $system_prompt  System prompt.
	 * @param string     $user_prompt    User prompt.
	 * @return array|WP_Error Result array on a successful switch, or the original error.
	 */
	private function try_free_model_fallback( $item, $slug, $original_error, $system_prompt, $user_prompt ) {
		if ( '1' !== get_option( 'spamanvil_auto_free_fallback', '1' ) ) {
			return $original_error;
		}

		if ( ! $this->provider_factory->is_model_unavailable_error( $original_error ) ) {
			return $original_error;
		}

		// Discovery lists models over HTTP and then makes one more call; not worth
		// starting when the batch cannot afford it.
		// Listing is an HTTP call too: it gets what is left minus the time the
		// classification call after it needs, never the fixed 30s it used to.
		$remaining = $this->remaining_seconds();
		if ( null !== $remaining && $remaining < 2 * self::MIN_CALL_SECONDS ) {
			return $original_error;
		}
		$list_timeout = null === $remaining
			? 30
			: (int) min( 30, floor( $remaining - self::MIN_CALL_SECONDS ) );

		$model_chain   = $this->provider_factory->get_model_chain( $slug );
		$current_model = ! empty( $model_chain ) ? $model_chain[0] : '';
		$alt           = $this->provider_factory->find_free_alternative( $slug, $current_model, $list_timeout );

		if ( '' === $alt ) {
			return $original_error;
		}

		$provider = $this->provider_factory->create( $slug, array( 'model' => $alt ) );
		if ( is_wp_error( $provider ) ) {
			return $original_error;
		}

		$timeout = $this->call_budget();
		if ( 0 === $timeout ) {
			return $original_error;
		}
		$provider->set_timeout( $timeout );

		$result = $provider->analyze( $system_prompt, $user_prompt );
		$this->stats->increment( 'llm_calls' );
		++$this->calls_this_item;

		if ( is_wp_error( $result ) ) {
			return $original_error;
		}

		// The substitute works — count it and record the switch in the logs. Persist it
		// only when a single model was configured: a user-defined model *list* (1.12.0)
		// is deliberate configuration that auto-discovery must not overwrite.
		$stored_list = SpamAnvil_Provider_Factory::parse_model_list( get_option( 'spamanvil_' . $slug . '_model', '' ) );
		if ( count( $stored_list ) <= 1 ) {
			update_option( 'spamanvil_' . $slug . '_model', $alt );
		}
		$this->stats->increment( 'model_auto_switched' );
		$this->stats->log_evaluation( array(
			'comment_id'        => $item->comment_id,
			'score'             => null,
			'provider'          => $slug,
			'model'             => $alt,
			'reason'            => sprintf( 'Model "%s" was unavailable — auto-switched to free model "%s".', $current_model, $alt ),
			'heuristic_score'   => $item->heuristic_score,
			'heuristic_details' => '',
		) );

		return $result;
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

		$all_permanent = true;
		$out_of_time   = false;

		$model_lists = $this->model_lists( $chain );
		$counts      = array_map( 'count', $model_lists );

		foreach ( array_values( $chain ) as $p_index => $slug ) {
			$models = $model_lists[ $p_index ];

			foreach ( $models as $m_index => $model ) {
				$provider = $this->provider_factory->create( $slug, '' !== $model ? array( 'model' => $model ) : array() );

				if ( is_wp_error( $provider ) ) {
					$error_msg = $provider->get_error_message();
					$errors[]  = $slug . ': ' . $error_msg;
					$this->stats->increment( 'llm_errors' );
					$this->stats->log_evaluation( array(
						'comment_id'        => $item->comment_id,
						'score'             => null,
						'provider'          => $slug,
						'model'             => '',
						'reason'            => 'Anvil Mode — provider unavailable (' . $provider->get_error_code() . '): ' . $error_msg,
						'heuristic_score'   => $item->heuristic_score,
						'heuristic_details' => '',
					) );
					if ( ! SpamAnvil_Provider_Factory::is_permanent_config_error_code( $provider->get_error_code() ) ) {
						$all_permanent = false;
					}
					continue 2; // Creation failures are per-provider — next provider.
				}

				$timeout = $this->call_budget( self::calls_after( $counts, $p_index, $m_index ) );
				if ( 0 === $timeout ) {
					$out_of_time = true;
					break 2; // Out of time: judge on the results gathered so far.
				}
				$provider->set_timeout( $timeout );

				$start_ms = microtime( true );
				$result   = $provider->analyze( $system_prompt, $user_prompt );
				$elapsed  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
				++$this->calls_this_item;
				$this->stats->increment( 'llm_calls' );

				if ( is_wp_error( $result ) ) {
					$all_permanent = false;
					$error_msg     = $result->get_error_message();
					$errors[]      = $slug . ( '' !== $model ? '/' . $model : '' ) . ': ' . $error_msg;
					$this->stats->increment( 'llm_errors' );
					$this->stats->log_evaluation( array(
						'comment_id'         => $item->comment_id,
						'score'              => null,
						'provider'           => $slug,
						'model'              => $model,
						'reason'             => 'Anvil Mode — LLM error: ' . $error_msg,
						'heuristic_score'    => $item->heuristic_score,
						'heuristic_details'  => '',
						'processing_time_ms' => $elapsed,
					) );
					continue; // Try the provider's next model.
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
				continue 2; // One verdict per provider — next provider.
			}
		}

		if ( empty( $results ) ) {
			if ( $out_of_time ) {
				return $this->out_of_time_error( $errors );
			}

			$combined = implode( ' | ', $errors );

			if ( $all_permanent && ! empty( $errors ) ) {
				return new WP_Error( 'spamanvil_config_error', $combined );
			}

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

		// Every commenter-controlled field is sanitized before interpolation: boundary
		// tags stripped, newlines collapsed, length capped. Without this, an author
		// *name* like "Ignore all previous instructions..." lands in the prompt outside
		// the <comment_data> isolation block — a prompt-injection channel (fixed 1.12.0).
		$replacements = array(
			'{site_language}'   => self::get_site_language_name(),
			'{post_title}'      => self::sanitize_prompt_field( $post ? $post->post_title : '', 300 ),
			'{post_excerpt}'    => self::sanitize_prompt_field( $post ? wp_trim_words( $post->post_content, 50, '...' ) : '', 1000 ),
			'{author_name}'     => self::sanitize_prompt_field( $comment->comment_author, 200 ),
			'{author_email}'    => self::sanitize_prompt_field( $comment->comment_author_email, 200 ),
			'{author_url}'      => self::sanitize_prompt_field( $comment->comment_author_url, 300 ),
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
		// Neutralize the boundary tags. Without this, a spammer could embed a literal
		// </comment_data> (or </commenter_data>) in their comment to close the isolation
		// boundary early and smuggle instructions (e.g. "score 5") outside it.
		$content = preg_replace( '#</?comment(er)?_data>#i', '', $content );

		if ( mb_strlen( $content ) > 5000 ) {
			$content = mb_substr( $content, 0, 5000 ) . "\n[Content truncated at 5000 characters]";
		}

		return $content;
	}

	/**
	 * Sanitize a single-line commenter-controlled field for prompt interpolation.
	 *
	 * Strips the isolation boundary tags, collapses newlines (a multi-line author
	 * "name" is an injection attempt by definition — these fields are single-line),
	 * and caps the length. Pure and static so it is unit-testable.
	 *
	 * @param string $value   Field value.
	 * @param int    $max_len Maximum length to keep.
	 * @return string
	 */
	public static function sanitize_prompt_field( $value, $max_len = 200 ) {
		$value = preg_replace( '#</?comment(er)?_data>#i', '', (string) $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = trim( preg_replace( '/\s{2,}/', ' ', $value ) );

		if ( mb_strlen( $value ) > $max_len ) {
			$value = mb_substr( $value, 0, $max_len ) . '…';
		}

		return $value;
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
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $item->id ),
				null,
				array( '%d' )
			);

			// Smart email mode: classification failed for good, so a human decision is
			// genuinely needed — send the moderation email that was held at insert time.
			SpamAnvil_Notifier::send_moderator( $item->comment_id );

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
				'updated_at' => current_time( 'mysql', true ),
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
			'updated_at' => current_time( 'mysql', true ),
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

	/**
	 * Auto-enqueue pending WordPress comments that are not already in the queue.
	 *
	 * Scans for comments with 'hold' status, runs heuristics on each, and either
	 * auto-spams (high heuristic score) or enqueues for LLM analysis.
	 *
	 * @param int $limit Max comments to scan. 0 = unlimited. Default 100 (safe for cron).
	 * @return int Number of comments enqueued for LLM analysis.
	 */
	public function auto_enqueue_pending( $limit = 100, $manual = false ) {
		global $wpdb;

		// Skip if SpamAnvil is off or no provider is configured — nothing to process.
		if ( ! SpamAnvil::is_enabled() || '' === get_option( 'spamanvil_primary_provider', '' ) ) {
			return 0;
		}

		// Get comment IDs already in the queue (active statuses).
		$already_queued_ids = $wpdb->get_col(
			"SELECT comment_id FROM {$this->table} WHERE status IN ('queued', 'processing', 'failed', 'max_retries')"
		);

		$query = array(
			'status' => 'hold',
			'number' => $limit,
		);

		// A comment a moderator sent back to pending is theirs: the automatic path
		// leaves it alone. The manual "Scan pending" button is the explicit request
		// to analyze it again, so it includes them and clears the mark below.
		if ( ! $manual ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::MODERATED_META,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$comments = get_comments( $query );

		if ( empty( $comments ) ) {
			return 0;
		}

		$enqueued             = 0;
		$heuristic_threshold  = (int) get_option( 'spamanvil_heuristic_auto_spam', 95 );

		foreach ( $comments as $comment ) {
			if ( in_array( (string) $comment->comment_ID, $already_queued_ids, true ) ) {
				continue;
			}

			// Run heuristics.
			$analysis = $this->heuristics->analyze( array(
				'comment_content'      => $comment->comment_content,
				'comment_author'       => $comment->comment_author,
				'comment_author_email' => $comment->comment_author_email,
				'comment_author_url'   => $comment->comment_author_url,
			) );

			if ( $manual ) {
				delete_comment_meta( $comment->comment_ID, self::MODERATED_META );
			}

			if ( $analysis['score'] >= $heuristic_threshold ) {
				$comment_id = $comment->comment_ID;
				self::as_plugin( function () use ( $comment_id ) {
					return wp_spam_comment( $comment_id );
				} );
				$this->stats->increment( 'heuristic_blocked' );
				$this->stats->increment( 'comments_checked' );
				$this->stats->log_evaluation( array(
					'comment_id'        => $comment->comment_ID,
					'score'             => $analysis['score'],
					'provider'          => 'heuristics',
					'model'             => 'regex',
					'reason'            => 'Auto-blocked by heuristic analysis (auto-enqueue)',
					'heuristic_score'   => $analysis['score'],
					'heuristic_details' => $this->heuristics->format_for_prompt( $analysis ),
				) );

				$ip = $this->ip_manager->get_comment_ip( $comment->comment_ID );
				if ( ! empty( $ip ) ) {
					$this->ip_manager->record_spam_attempt( $ip );
				}
			} else {
				$this->enqueue( $comment->comment_ID, $analysis['score'] );
				$enqueued++;
			}
		}

		return $enqueued;
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
