<?php
/**
 * Integration tests for the upgrade migrations (1.14.1, field audit N1/N2):
 * CRLF-stored default prompts must still migrate, and legacy multi-century IP
 * bans must be capped to the 30-day ceiling.
 */
class ActivatorMigrationTest extends WP_UnitTestCase {

	/**
	 * The 1.5.0–1.11.3 default user prompt, verbatim (LF). Its normalized MD5 must
	 * match SpamAnvil_Activator::LEGACY_USER_PROMPT_HASHES[0].
	 */
	const LEGACY_USER_PROMPT_V1 = 'Analyze this comment for spam:

Site language: {site_language}

Post title: {post_title}
Post excerpt: {post_excerpt}

Comment author: {author_name}
Comment author email: {author_email}
Comment author URL: {author_url}
Author has URL: {author_has_url}
URLs in comment body: {url_count}

Pre-analysis data:
{heuristic_data}
Pre-analysis score: {heuristic_score}/100

<comment_data>
{comment_content}
</comment_data>';

	public static function set_up_before_class() {
		parent::set_up_before_class();
		SpamAnvil_Activator::activate();
	}

	public function test_legacy_fixture_matches_the_recorded_hash() {
		$this->assertContains(
			md5( SpamAnvil_Activator::normalize_prompt( self::LEGACY_USER_PROMPT_V1 ) ),
			SpamAnvil_Activator::LEGACY_USER_PROMPT_HASHES,
			'Precondition: the test fixture must be the real 1.11.3 default.'
		);
	}

	public function test_crlf_saved_default_prompt_still_migrates() {
		// The field-audit N1 scenario: the admin once pressed Save on the Prompt tab,
		// so the browser stored the unmodified default with CRLF line endings.
		update_option( 'spamanvil_user_prompt', str_replace( "\n", "\r\n", self::LEGACY_USER_PROMPT_V1 ) );

		SpamAnvil_Activator::activate();

		$this->assertSame(
			SpamAnvil_Activator::get_default_user_prompt(),
			get_option( 'spamanvil_user_prompt' ),
			'A CRLF-stored unmodified default must migrate to the current default.'
		);
		$this->assertStringContainsString( '<commenter_data>', get_option( 'spamanvil_user_prompt' ) );
	}

	public function test_customized_prompt_is_never_touched() {
		$custom = "My own prompt with {comment_content} and special rules.\r\nSecond line.";
		update_option( 'spamanvil_user_prompt', $custom );

		SpamAnvil_Activator::activate();

		$this->assertSame( $custom, get_option( 'spamanvil_user_prompt' ) );
	}

	public function test_legacy_multi_century_ip_bans_are_capped() {
		global $wpdb;
		$table = $wpdb->prefix . 'spamanvil_blocked_ips';

		$wpdb->insert( $table, array(
			'ip_hash'          => str_repeat( 'a', 64 ),
			'ip_display'       => '203.0.113.x',
			'attempts'         => 60,
			'escalation_level' => 19,
			'blocked_until'    => '2743-11-07 00:00:00',
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
		) );
		$id = $wpdb->insert_id;

		$wpdb->insert( $table, array(
			'ip_hash'          => str_repeat( 'b', 64 ),
			'ip_display'       => '203.0.113.y',
			'attempts'         => 3,
			'escalation_level' => 2,
			'blocked_until'    => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ),
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
		) );
		$sane_id = $wpdb->insert_id;

		SpamAnvil_Activator::activate();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		$this->assertLessThanOrEqual(
			time() + 31 * DAY_IN_SECONDS,
			strtotime( $row->blocked_until . ' UTC' ),
			'A multi-century legacy ban must be capped to the 30-day ceiling.'
		);
		$this->assertSame( 6, (int) $row->escalation_level );

		$sane = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $sane_id ) );
		$this->assertSame( 2, (int) $sane->escalation_level, 'Rows already within bounds are untouched.' );
		$this->assertLessThanOrEqual( time() + 3 * DAY_IN_SECONDS, strtotime( $sane->blocked_until . ' UTC' ) );
	}
}
