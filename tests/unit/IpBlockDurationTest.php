<?php
/**
 * Unit tests for the IP block escalation cap (1.12.0). Unbounded doubling
 * produced multi-year bans (level 19 ≈ 718 years) — false positives became
 * permanent and high levels overflow DATETIME. Now capped at 30 days.
 */

use PHPUnit\Framework\TestCase;

class IpBlockDurationTest extends TestCase {

	public function test_early_levels_keep_doubling() {
		$this->assertSame( 24, SpamAnvil_IP_Manager::block_hours_for_level( 1 ) );
		$this->assertSame( 48, SpamAnvil_IP_Manager::block_hours_for_level( 2 ) );
		$this->assertSame( 96, SpamAnvil_IP_Manager::block_hours_for_level( 3 ) );
		$this->assertSame( 192, SpamAnvil_IP_Manager::block_hours_for_level( 4 ) );
		$this->assertSame( 384, SpamAnvil_IP_Manager::block_hours_for_level( 5 ) );
	}

	public function test_cap_kicks_in_at_level_6() {
		$this->assertSame( 720, SpamAnvil_IP_Manager::block_hours_for_level( 6 ) );
	}

	public function test_high_levels_stay_capped() {
		$this->assertSame( 720, SpamAnvil_IP_Manager::block_hours_for_level( 19 ) );
		$this->assertSame( 720, SpamAnvil_IP_Manager::block_hours_for_level( 1000 ) );
		$this->assertSame( 720, SpamAnvil_IP_Manager::block_hours_for_level( PHP_INT_MAX ) );
	}

	public function test_degenerate_levels_are_clamped() {
		$this->assertSame( 24, SpamAnvil_IP_Manager::block_hours_for_level( 0 ) );
		$this->assertSame( 24, SpamAnvil_IP_Manager::block_hours_for_level( -5 ) );
	}
}
