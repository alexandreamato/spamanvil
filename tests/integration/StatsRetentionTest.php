<?php
/**
 * Integration tests for the all-time stat totals against the 90-day purge.
 *
 * The stats table is pruned to 90 days, but the "Spam Comments Blocked" hero,
 * the dashboard widget and the review-request gate all read *all-time* totals
 * from it. Before 1.15.0 those numbers therefore shrank by a day every day on
 * any install older than three months. cleanup_old_logs() now banks what it is
 * about to delete; these tests pin that down.
 */
class StatsRetentionTest extends WP_UnitTestCase {

	/** @var SpamAnvil_Stats */
	private $stats;

	/** @var string */
	private $table;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		SpamAnvil_Activator::activate();
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$this->stats = new SpamAnvil_Stats();
		$this->table = $wpdb->prefix . 'spamanvil_stats';

		$wpdb->query( "DELETE FROM {$this->table}" ); // phpcs:ignore WordPress.DB
		delete_option( SpamAnvil_Stats::ARCHIVED_TOTALS_OPTION );
	}

	/**
	 * Write a counter directly for a given day (increment() only ever writes today).
	 *
	 * @param int    $days_ago Age of the row in days.
	 * @param string $key      Stat key.
	 * @param int    $value    Counter value.
	 */
	private function seed_day( $days_ago, $key, $value ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$this->table,
			array(
				'stat_date'  => gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) ),
				'stat_key'   => $key,
				'stat_value' => $value,
			)
		);
	}

	public function test_all_time_total_survives_the_purge() {
		$this->seed_day( 200, 'spam_detected', 100 ); // Purged.
		$this->seed_day( 120, 'spam_detected', 50 );  // Purged.
		$this->seed_day( 10, 'spam_detected', 7 );    // Kept.

		$before = $this->stats->get_total( 'spam_detected' );
		$this->assertSame( 157, $before );

		$this->stats->cleanup_old_logs();

		$this->assertSame(
			157,
			$this->stats->get_total( 'spam_detected' ),
			'The all-time total must not drop when old daily rows are pruned.'
		);
	}

	public function test_purge_actually_removes_the_old_rows() {
		global $wpdb;

		$this->seed_day( 200, 'spam_detected', 100 );
		$this->seed_day( 10, 'spam_detected', 7 );

		$this->stats->cleanup_old_logs();

		$live = (int) $wpdb->get_var( "SELECT COALESCE(SUM(stat_value),0) FROM {$this->table}" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 7, $live, 'Only rows inside the retention window should remain.' );

		$archived = get_option( SpamAnvil_Stats::ARCHIVED_TOTALS_OPTION, array() );
		$this->assertSame( 100, (int) $archived['spam_detected'] );
	}

	public function test_repeated_purges_do_not_double_count() {
		$this->seed_day( 200, 'spam_detected', 100 );
		$this->seed_day( 10, 'spam_detected', 7 );

		$this->stats->cleanup_old_logs();
		$this->stats->cleanup_old_logs();
		$this->stats->cleanup_old_logs();

		$this->assertSame(
			107,
			$this->stats->get_total( 'spam_detected' ),
			'Archiving must be tied to the rows actually deleted, not re-added on every cron run.'
		);
	}

	public function test_each_key_is_archived_separately() {
		$this->seed_day( 200, 'spam_detected', 40 );
		$this->seed_day( 200, 'heuristic_blocked', 9 );
		$this->seed_day( 200, 'comments_checked', 60 );

		$this->stats->cleanup_old_logs();

		$this->assertSame( 40, $this->stats->get_total( 'spam_detected' ) );
		$this->assertSame( 9, $this->stats->get_total( 'heuristic_blocked' ) );
		$this->assertSame( 60, $this->stats->get_total( 'comments_checked' ) );
		$this->assertSame( 0, $this->stats->get_total( 'never_used_key' ) );
	}

	public function test_retention_of_zero_keeps_everything() {
		update_option( 'spamanvil_log_retention', 0 );

		$this->seed_day( 200, 'spam_detected', 100 );

		$this->stats->cleanup_old_logs();

		$archived = get_option( SpamAnvil_Stats::ARCHIVED_TOTALS_OPTION, array() );
		$this->assertSame( array(), $archived, 'Nothing is purged, so nothing should be archived.' );
		$this->assertSame( 100, $this->stats->get_total( 'spam_detected' ) );

		update_option( 'spamanvil_log_retention', 30 );
	}
}
