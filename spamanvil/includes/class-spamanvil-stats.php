<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Reason: All queries target custom plugin tables (spamanvil_stats, spamanvil_logs).
// Table names come from $wpdb->prefix and are safe. WordPress object cache is not
// applicable to custom tables, and $wpdb->prepare() is used for all user values.

class SpamAnvil_Stats {

	/**
	 * Counters banked from stat rows the 90-day purge removed, so all-time totals
	 * survive it. Autoload is off: it is only read on the stats screens.
	 */
	const ARCHIVED_TOTALS_OPTION = 'spamanvil_archived_totals';

	private $stats_table;
	private $logs_table;

	public function __construct() {
		global $wpdb;
		$this->stats_table = $wpdb->prefix . 'spamanvil_stats';
		$this->logs_table  = $wpdb->prefix . 'spamanvil_logs';
	}

	public function increment( $key, $value = 1 ) {
		global $wpdb;

		$today = current_time( 'Y-m-d' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->stats_table} (stat_date, stat_key, stat_value)
				VALUES (%s, %s, %d)
				ON DUPLICATE KEY UPDATE stat_value = stat_value + %d",
				$today,
				$key,
				$value,
				$value
			)
		);
	}

	/**
	 * All-time total for a counter.
	 *
	 * The stats table only keeps 90 days (see cleanup_old_logs()), so the live rows
	 * alone are a rolling window, not a total: the "Spam Comments Blocked" hero and
	 * the dashboard widget used to shrink a little every day on any install older
	 * than three months. Whatever the purge removes is banked in
	 * `spamanvil_archived_totals` first and added back here, so the number only ever
	 * grows.
	 *
	 * @param string $key Stat key.
	 * @return int
	 */
	public function get_total( $key ) {
		global $wpdb;

		$live = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(stat_value), 0) FROM {$this->stats_table} WHERE stat_key = %s",
				$key
			)
		);

		$archived = get_option( self::ARCHIVED_TOTALS_OPTION, array() );

		return $live + ( isset( $archived[ $key ] ) ? (int) $archived[ $key ] : 0 );
	}

	public function get_summary( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_key, SUM(stat_value) as total
				FROM {$this->stats_table}
				WHERE stat_date >= %s
				GROUP BY stat_key",
				$since
			)
		);

		$summary = array(
			'comments_checked'  => 0,
			'spam_detected'     => 0,
			'ham_approved'      => 0,
			'heuristic_blocked' => 0,
			'ip_blocked'        => 0,
			'llm_calls'         => 0,
			'llm_errors'        => 0,
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				$summary[ $row->stat_key ] = (int) $row->total;
			}
		}

		return $summary;
	}

	public function get_daily( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, stat_key, stat_value
				FROM {$this->stats_table}
				WHERE stat_date >= %s
				ORDER BY stat_date ASC",
				$since
			)
		);

		$daily = array();

		if ( $results ) {
			foreach ( $results as $row ) {
				if ( ! isset( $daily[ $row->stat_date ] ) ) {
					$daily[ $row->stat_date ] = array();
				}
				$daily[ $row->stat_date ][ $row->stat_key ] = (int) $row->stat_value;
			}
		}

		return $daily;
	}

	public function log_evaluation( $data ) {
		global $wpdb;

		$wpdb->insert(
			$this->logs_table,
			array(
				'comment_id'         => isset( $data['comment_id'] ) ? absint( $data['comment_id'] ) : 0,
				'score'              => isset( $data['score'] ) ? intval( $data['score'] ) : null,
				'provider'           => isset( $data['provider'] ) ? sanitize_text_field( $data['provider'] ) : null,
				'model'              => isset( $data['model'] ) ? sanitize_text_field( $data['model'] ) : null,
				'reason'             => isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : null,
				'heuristic_score'    => isset( $data['heuristic_score'] ) ? intval( $data['heuristic_score'] ) : null,
				'heuristic_details'  => isset( $data['heuristic_details'] ) ? sanitize_text_field( $data['heuristic_details'] ) : null,
				'processing_time_ms' => isset( $data['processing_time_ms'] ) ? absint( $data['processing_time_ms'] ) : null,
				'created_at'         => current_time( 'mysql' ),
			)
		);
	}

	public function get_logs( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->logs_table}" );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, c.comment_content, c.comment_author
				FROM {$this->logs_table} l
				LEFT JOIN {$wpdb->comments} c ON l.comment_id = c.comment_ID
				ORDER BY l.created_at DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'items'    => $items ? $items : array(),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Analyze historical data and suggest an optimal spam threshold.
	 *
	 * Cross-references log scores with actual comment statuses (spam vs approved)
	 * to find the threshold that minimizes misclassification.
	 *
	 * @param int $min_samples Minimum evaluated comments required.
	 * @return array|null Suggestion data or null if insufficient data.
	 */
	public function get_threshold_suggestion( $min_samples = 20 ) {
		global $wpdb;

		// Get scores paired with actual comment status (admin may have reclassified).
		$rows = $wpdb->get_results(
			"SELECT l.score, c.comment_approved
			FROM {$this->logs_table} l
			INNER JOIN {$wpdb->comments} c ON l.comment_id = c.comment_ID
			WHERE l.score IS NOT NULL
			AND c.comment_approved IN ('1', 'spam')
			ORDER BY l.score ASC"
		);

		if ( ! $rows || count( $rows ) < $min_samples ) {
			return null;
		}

		$spam_scores = array();
		$ham_scores  = array();

		foreach ( $rows as $row ) {
			if ( 'spam' === $row->comment_approved ) {
				$spam_scores[] = (int) $row->score;
			} else {
				$ham_scores[] = (int) $row->score;
			}
		}

		// Need both spam and ham samples to make a meaningful suggestion.
		if ( count( $spam_scores ) < 3 || count( $ham_scores ) < 3 ) {
			return null;
		}

		$total = count( $rows );

		// Test every threshold from 10 to 95 and find the one with best accuracy.
		$best_threshold = 70;
		$best_f1        = 0;

		for ( $t = 10; $t <= 95; $t += 5 ) {
			$tp = 0; // True positives: spam correctly caught.
			$fp = 0; // False positives: ham wrongly flagged.
			$fn = 0; // False negatives: spam wrongly approved.
			$tn = 0; // True negatives: ham correctly approved.

			foreach ( $spam_scores as $s ) {
				if ( $s >= $t ) {
					$tp++;
				} else {
					$fn++;
				}
			}

			foreach ( $ham_scores as $s ) {
				if ( $s >= $t ) {
					$fp++;
				} else {
					$tn++;
				}
			}

			// F1 score balances precision and recall.
			$precision = ( $tp + $fp ) > 0 ? $tp / ( $tp + $fp ) : 0;
			$recall    = ( $tp + $fn ) > 0 ? $tp / ( $tp + $fn ) : 0;
			$f1        = ( $precision + $recall ) > 0 ? 2 * ( $precision * $recall ) / ( $precision + $recall ) : 0;

			if ( $f1 > $best_f1 ) {
				$best_f1        = $f1;
				$best_threshold = $t;
			}
		}

		// Calculate metrics at the suggested threshold.
		$tp = $fp = $fn = $tn = 0;
		foreach ( $spam_scores as $s ) {
			if ( $s >= $best_threshold ) {
				$tp++;
			} else {
				$fn++;
			}
		}
		foreach ( $ham_scores as $s ) {
			if ( $s >= $best_threshold ) {
				$fp++;
			} else {
				$tn++;
			}
		}

		$accuracy = $total > 0 ? round( ( $tp + $tn ) / $total * 100, 1 ) : 0;

		return array(
			'threshold'       => $best_threshold,
			'accuracy'        => $accuracy,
			'total_samples'   => $total,
			'spam_count'      => count( $spam_scores ),
			'ham_count'       => count( $ham_scores ),
			'true_positives'  => $tp,
			'false_positives' => $fp,
			'false_negatives' => $fn,
			'true_negatives'  => $tn,
		);
	}

	public function cleanup_old_logs() {
		global $wpdb;

		$retention = (int) get_option( 'spamanvil_log_retention', 30 );

		if ( $retention <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention} days" ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->logs_table} WHERE created_at < %s",
				$cutoff
			)
		);

		// Also clean up old stats beyond 90 days — but bank the counters first, or the
		// all-time figures reported by get_total() would silently lose a day every day.
		$stats_cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		$this->archive_totals_before( $stats_cutoff );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->stats_table} WHERE stat_date < %s",
				$stats_cutoff
			)
		);
	}

	/**
	 * Add everything older than $cutoff to the archived all-time totals.
	 *
	 * Must run immediately before the matching DELETE: it sums the rows that are
	 * about to disappear, so calling it without deleting them double-counts.
	 *
	 * @param string $cutoff Y-m-d date; rows strictly older are archived.
	 */
	private function archive_totals_before( $cutoff ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_key, SUM(stat_value) AS total
				FROM {$this->stats_table}
				WHERE stat_date < %s
				GROUP BY stat_key",
				$cutoff
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$archived = get_option( self::ARCHIVED_TOTALS_OPTION, array() );
		if ( ! is_array( $archived ) ) {
			$archived = array();
		}

		foreach ( $rows as $row ) {
			$key              = (string) $row->stat_key;
			$archived[ $key ] = ( isset( $archived[ $key ] ) ? (int) $archived[ $key ] : 0 ) + (int) $row->total;
		}

		update_option( self::ARCHIVED_TOTALS_OPTION, $archived, false );
	}
}
