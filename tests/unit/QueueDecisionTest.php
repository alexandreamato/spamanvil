<?php
/**
 * Unit tests for the 1.19.0 trust fixes that are pure logic: when a queued
 * analysis must defer to a decision already made, how the verdict cache is keyed,
 * and when an upgrade re-runs the migrations.
 */

use PHPUnit\Framework\TestCase;

class QueueDecisionTest extends TestCase {

	// --- human_decided() --------------------------------------------------------

	public function test_spam_and_trash_always_win() {
		foreach ( array( true, false ) as $expects_approved ) {
			$this->assertTrue( SpamAnvil_Queue::human_decided( 'spam', $expects_approved ) );
			$this->assertTrue( SpamAnvil_Queue::human_decided( 'trash', $expects_approved ) );
		}
	}

	public function test_approval_while_held_is_a_moderator_decision() {
		// Async mode holds every comment as pending, so an approved one was approved by someone.
		$this->assertTrue( SpamAnvil_Queue::human_decided( 'approved', false ) );
	}

	public function test_approval_is_expected_in_open_and_sync_mode() {
		$this->assertFalse( SpamAnvil_Queue::human_decided( 'approved', true ) );
	}

	public function test_pending_comment_is_still_ours_to_judge() {
		$this->assertFalse( SpamAnvil_Queue::human_decided( 'unapproved', false ) );
		$this->assertFalse( SpamAnvil_Queue::human_decided( false, false ) );
	}

	public function test_recorded_moderation_wins_even_when_pending() {
		// 1.19.1: a moderator sent it back to pending. By status alone that looks like
		// a comment still waiting for its first analysis.
		$this->assertTrue( SpamAnvil_Queue::human_decided( 'unapproved', false, true ) );
		$this->assertTrue( SpamAnvil_Queue::human_decided( 'approved', true, true ) );
	}

	public function test_raw_db_status_maps_to_status_names() {
		$this->assertSame( 'approved', SpamAnvil_Queue::status_name( '1' ) );
		$this->assertSame( 'unapproved', SpamAnvil_Queue::status_name( '0' ) );
		$this->assertSame( 'spam', SpamAnvil_Queue::status_name( 'spam' ) );
		$this->assertSame( 'trash', SpamAnvil_Queue::status_name( 'trash' ) );
		$this->assertSame( 'trash', SpamAnvil_Queue::status_name( 'post-trashed' ) );
		$this->assertFalse( SpamAnvil_Queue::status_name( null ) );
	}

	public function test_switching_model_changes_the_key() {
		$this->assertNotSame(
			SpamAnvil_Queue::verdict_cache_key( 'rules', 'comment', 'config-a' ),
			SpamAnvil_Queue::verdict_cache_key( 'rules', 'comment', 'config-b' )
		);
	}

	// --- verdict_cache_key() ----------------------------------------------------

	public function test_identical_requests_share_a_key_despite_case_and_spacing() {
		$this->assertSame(
			SpamAnvil_Queue::verdict_cache_key( 'System', "Post: A\nBuy   NOW" ),
			SpamAnvil_Queue::verdict_cache_key( 'system', 'post: a buy now' )
		);
	}

	public function test_anything_the_model_sees_changes_the_key() {
		// The 1.18.1 key saw only content + author URL: the same text on another post,
		// from another author, or under a fixed prompt reused the old verdict.
		$base = SpamAnvil_Queue::verdict_cache_key( 'rules v1', "Post: Lipedema\nAuthor: Ana\nGreat post" );

		$this->assertNotSame( $base, SpamAnvil_Queue::verdict_cache_key( 'rules v1', "Post: Casino\nAuthor: Ana\nGreat post" ) );
		$this->assertNotSame( $base, SpamAnvil_Queue::verdict_cache_key( 'rules v1', "Post: Lipedema\nAuthor: CheapPills\nGreat post" ) );
		$this->assertNotSame( $base, SpamAnvil_Queue::verdict_cache_key( 'rules v2', "Post: Lipedema\nAuthor: Ana\nGreat post" ) );
	}

	public function test_prompt_boundary_cannot_be_shifted() {
		// Moving text between the two prompts must not collide.
		$this->assertNotSame(
			SpamAnvil_Queue::verdict_cache_key( 'ab', 'c' ),
			SpamAnvil_Queue::verdict_cache_key( 'a', 'bc' )
		);
	}

	// --- call_timeout() (1.20.0) -------------------------------------------------

	public function test_no_deadline_uses_the_default_timeout() {
		$this->assertSame( 60, SpamAnvil_Queue::call_timeout( null ) );
	}

	public function test_timeout_shrinks_to_the_time_left() {
		// 50s batch, 38.7s already spent: the next call may take 11s, not 60.
		$this->assertSame( 11, SpamAnvil_Queue::call_timeout( 11.3 ) );
		$this->assertSame( 60, SpamAnvil_Queue::call_timeout( 300.0 ) );
	}

	public function test_no_call_is_started_without_enough_time() {
		$this->assertSame( 0, SpamAnvil_Queue::call_timeout( 7.9 ) );
		$this->assertSame( 0, SpamAnvil_Queue::call_timeout( -2.0 ) );
		$this->assertSame( 8, SpamAnvil_Queue::call_timeout( 8.0 ) );
	}

	// --- pick_comment_ip() (1.20.0) ----------------------------------------------

	public function test_resolved_ip_wins_over_the_proxy_address() {
		$this->assertSame( '203.0.113.7', SpamAnvil_IP_Manager::pick_comment_ip( '203.0.113.7', '10.0.0.1' ) );
	}

	public function test_stored_ip_is_the_fallback() {
		$this->assertSame( '198.51.100.2', SpamAnvil_IP_Manager::pick_comment_ip( '', '198.51.100.2' ) );
	}

	// --- needs_upgrade() --------------------------------------------------------

	public function test_plugin_release_without_schema_change_still_upgrades() {
		// The 1.16.0 gap: the default prompt changed, the schema version did not.
		$this->assertTrue( SpamAnvil_Activator::needs_upgrade( '1.5.0', '1.18.1', '1.5.0', '1.19.0' ) );
	}

	public function test_install_from_before_the_version_option_upgrades_once() {
		$this->assertTrue( SpamAnvil_Activator::needs_upgrade( '1.5.0', '', '1.5.0', '1.19.0' ) );
	}

	public function test_schema_change_upgrades() {
		$this->assertTrue( SpamAnvil_Activator::needs_upgrade( '1.4.0', '1.19.0', '1.5.0', '1.19.0' ) );
	}

	public function test_current_install_does_nothing() {
		$this->assertFalse( SpamAnvil_Activator::needs_upgrade( '1.5.0', '1.19.0', '1.5.0', '1.19.0' ) );
	}
}
