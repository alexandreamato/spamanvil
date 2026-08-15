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

	/**
	 * The 1.12.0–1.15.0 default system prompt scored any comment in a language other
	 * than the site's at 75+, and generic praise at 70+ — so polite, short or simply
	 * non-English comments from real readers were auto-marked as spam (measured at
	 * 3 false positives in 7 across two models before 1.16.0 changed the rules).
	 * Installs still running that default must be migrated by the upgrade.
	 */
	public function test_pre_116_system_prompt_is_recognised_as_a_legacy_default() {
		$legacy = file_get_contents( dirname( __DIR__ ) . '/fixtures/system-prompt-1.12.0.txt' );

		$this->assertContains(
			md5( SpamAnvil_Activator::normalize_prompt( $legacy ) ),
			SpamAnvil_Activator::LEGACY_SYSTEM_PROMPT_HASHES,
			'Precondition: the fixture must be the real 1.12.0–1.15.0 default.'
		);
	}

	public function test_pre_116_system_prompt_migrates_on_upgrade() {
		$legacy = file_get_contents( dirname( __DIR__ ) . '/fixtures/system-prompt-1.12.0.txt' );

		// Both as shipped (LF) and as a browser would have re-saved it (CRLF).
		foreach ( array( $legacy, str_replace( "\n", "\r\n", $legacy ) ) as $stored ) {
			update_option( 'spamanvil_system_prompt', $stored );
			SpamAnvil_Activator::activate();

			$this->assertSame(
				SpamAnvil_Activator::get_default_system_prompt(),
				get_option( 'spamanvil_system_prompt' ),
				'An unmodified pre-1.16.0 default must be replaced by the current one.'
			);
		}
	}

	public function test_current_default_is_not_listed_as_legacy() {
		// Guards the release checklist: shipping a new default without recording the
		// outgoing one would leave every existing install on the old prompt forever.
		$this->assertNotContains(
			md5( SpamAnvil_Activator::normalize_prompt( SpamAnvil_Activator::get_default_system_prompt() ) ),
			SpamAnvil_Activator::LEGACY_SYSTEM_PROMPT_HASHES,
			'The current default must never be in the legacy list — it would migrate to itself.'
		);
	}

	public function test_customized_system_prompt_is_never_touched() {
		$custom = file_get_contents( dirname( __DIR__ ) . '/fixtures/system-prompt-1.12.0.txt' )
			. "\n\nExtra house rule: approve anything from our staff.";

		update_option( 'spamanvil_system_prompt', $custom );
		SpamAnvil_Activator::activate();

		$this->assertSame( $custom, get_option( 'spamanvil_system_prompt' ) );
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
