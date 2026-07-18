<?php
/**
 * Unit tests for SpamAnvil_Admin::review_notice_due() — the pure gate that decides
 * whether the "leave a review" admin notice should appear. Only ask once the plugin
 * has delivered value (>=50 comments checked) AND has been installed a while, and
 * never after a permanent dismiss or during a snooze.
 */

use PHPUnit\Framework\TestCase;

class ReviewNoticeTest extends TestCase {

	private const DAY   = 86400;
	private const NOW   = 1_000_000_000; // fixed "now" for deterministic tests.
	private const OLD   = self::NOW - 30 * self::DAY; // installed 30 days ago.
	private const FRESH = self::NOW - 2 * self::DAY;  // installed 2 days ago.

	private function due( $dismissed, $snooze, $checked, $activated ) {
		return SpamAnvil_Admin::review_notice_due( $dismissed, $snooze, $checked, $activated, self::NOW );
	}

	public function test_shown_when_value_delivered_and_installed_long_enough() {
		$this->assertTrue( $this->due( false, 0, 50, self::OLD ) );
		$this->assertTrue( $this->due( false, 0, 5000, self::OLD ) );
	}

	public function test_never_shown_after_permanent_dismiss() {
		$this->assertFalse( $this->due( true, 0, 99999, self::OLD ) );
	}

	public function test_hidden_while_snoozed_then_returns_after_snooze_lapses() {
		$this->assertFalse( $this->due( false, self::NOW + self::DAY, 200, self::OLD ), 'Active snooze suppresses the ask.' );
		$this->assertTrue( $this->due( false, self::NOW - self::DAY, 200, self::OLD ), 'Once the snooze is in the past, it can show again.' );
	}

	public function test_not_shown_before_enough_comments_checked() {
		$this->assertFalse( $this->due( false, 0, 49, self::OLD ) );
		$this->assertFalse( $this->due( false, 0, 0, self::OLD ) );
	}

	public function test_not_shown_before_the_plugin_has_had_time_to_prove_itself() {
		// Enough comments, but installed only 2 days ago.
		$this->assertFalse( $this->due( false, 0, 500, self::FRESH ) );
	}

	public function test_unknown_activation_time_falls_back_to_value_only() {
		// activated_at = 0 (option missing) → age gate is skipped, count still applies.
		$this->assertTrue( $this->due( false, 0, 50, 0 ) );
		$this->assertFalse( $this->due( false, 0, 10, 0 ) );
	}

	public function test_boundary_exactly_seven_days_and_fifty_checked() {
		$exactly_7d = self::NOW - 7 * self::DAY;
		$this->assertTrue( $this->due( false, 0, 50, $exactly_7d ), 'At exactly the threshold it should show.' );
	}

	public function test_filterable_min_checked_lets_low_traffic_sites_ask_earlier() {
		// 10 comments is below the default (50), so nothing shows...
		$this->assertFalse( SpamAnvil_Admin::review_notice_due( false, 0, 10, self::OLD, self::NOW ) );
		// ...but a filtered-down threshold of 10 makes it show.
		$this->assertTrue( SpamAnvil_Admin::review_notice_due( false, 0, 10, self::OLD, self::NOW, 10, 7 * self::DAY ) );
	}

	public function test_filterable_min_age_can_delay_the_ask() {
		$installed_10d = self::NOW - 10 * self::DAY;
		// Default 7-day gate: 10 days installed → shows.
		$this->assertTrue( SpamAnvil_Admin::review_notice_due( false, 0, 100, $installed_10d, self::NOW ) );
		// Raise the age gate to 30 days → hidden.
		$this->assertFalse( SpamAnvil_Admin::review_notice_due( false, 0, 100, $installed_10d, self::NOW, 50, 30 * self::DAY ) );
	}
}
