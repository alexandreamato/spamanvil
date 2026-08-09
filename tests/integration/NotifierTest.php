<?php
/**
 * Integration tests for smart email notifications (1.13.0).
 *
 * The core promise: WordPress' per-comment emails are held for comments
 * SpamAnvil will evaluate, and notifications go out only AFTER the verdict —
 * ham → post author; permanently unclassifiable → moderator; spam → silence.
 */
class NotifierTest extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		SpamAnvil_Activator::activate();
	}

	public function set_up() {
		parent::set_up();
		update_option( 'spamanvil_enabled', '1' );
		update_option( 'spamanvil_email_mode', 'smart' );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function guest_comment( $args = array() ) {
		return (int) self::factory()->comment->create( array_merge( array(
			'comment_approved'     => '0',
			'user_id'              => 0,
			'comment_author'       => 'Guest Commenter',
			'comment_author_email' => 'guest@example.org',
		), $args ) );
	}

	// --- insert-time suppression ----------------------------------------------

	public function test_smart_mode_suppresses_moderation_email_at_insert() {
		$id = $this->guest_comment();
		$this->assertFalse(
			apply_filters( 'notify_moderator', true, $id ),
			'Smart mode must hold the insert-time moderation email for comments SpamAnvil will evaluate.'
		);
	}

	public function test_off_mode_keeps_default_notifications() {
		update_option( 'spamanvil_email_mode', 'off' );
		$id = $this->guest_comment();
		$this->assertTrue( apply_filters( 'notify_moderator', true, $id ) );
	}

	public function test_disabled_plugin_keeps_default_notifications() {
		update_option( 'spamanvil_enabled', '0' );
		$id = $this->guest_comment();
		$this->assertTrue( apply_filters( 'notify_moderator', true, $id ) );
	}

	public function test_moderator_comments_keep_normal_notifications() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = $this->guest_comment( array( 'user_id' => $admin ) );
		$this->assertTrue(
			apply_filters( 'notify_moderator', true, $id ),
			'Comments SpamAnvil skips (moderators) must keep their normal notifications.'
		);
	}

	// --- post-verdict sends -----------------------------------------------------

	public function test_insert_path_is_suppressed_but_deferred_send_passes() {
		update_option( 'comments_notify', 1 );
		$author = self::factory()->user->create( array( 'user_email' => 'post-author@example.org' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		$id     = $this->guest_comment( array(
			'comment_post_ID'  => $post,
			'comment_approved' => '1',
		) );

		// What core calls at insert time: suppressed by the filter.
		wp_new_comment_notify_postauthor( $id );
		$this->assertEmpty(
			tests_retrieve_phpmailer_instance()->mock_sent,
			'Insert-time post-author email must be held back.'
		);

		// The deliberate post-verdict send: passes through the same filter.
		SpamAnvil_Notifier::send_postauthor( $id );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotEmpty( $mailer->mock_sent, 'Post-verdict notification must be delivered.' );
		$this->assertSame( 'post-author@example.org', $mailer->get_recipient( 'to' )->address );
	}

	public function test_moderation_email_sent_when_classification_fails_for_good() {
		update_option( 'moderation_notify', 1 );
		$post = self::factory()->post->create();
		$id   = $this->guest_comment( array( 'comment_post_ID' => $post ) );

		SpamAnvil_Notifier::send_moderator( $id );

		$this->assertNotEmpty(
			tests_retrieve_phpmailer_instance()->mock_sent,
			'When the classifier permanently fails, the moderation email must finally go out.'
		);
	}

	public function test_no_deferred_sends_in_digest_mode() {
		update_option( 'spamanvil_email_mode', 'digest' );
		update_option( 'comments_notify', 1 );
		$author = self::factory()->user->create( array( 'user_email' => 'a@example.org' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		$id     = $this->guest_comment( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );

		SpamAnvil_Notifier::send_postauthor( $id );

		$this->assertEmpty(
			tests_retrieve_phpmailer_instance()->mock_sent,
			'Digest mode must not send per-comment emails.'
		);
	}

	// --- daily digest -----------------------------------------------------------

	public function test_digest_mode_sends_summary_email() {
		update_option( 'spamanvil_email_mode', 'digest' );
		( new SpamAnvil_Stats() )->increment( 'spam_detected', 7 );

		SpamAnvil_Notifier::send_digest();

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotEmpty( $mailer->mock_sent, 'Digest must be emailed when there was activity.' );
		$this->assertStringContainsString( 'SpamAnvil', $mailer->mock_sent[0]['subject'] );
		$this->assertStringContainsString( '7', $mailer->mock_sent[0]['subject'] );
	}

	public function test_digest_skips_quiet_days() {
		update_option( 'spamanvil_email_mode', 'digest' );

		SpamAnvil_Notifier::send_digest();

		$this->assertEmpty(
			tests_retrieve_phpmailer_instance()->mock_sent,
			'No activity and nothing pending → no digest email.'
		);
	}

	public function test_digest_not_sent_in_smart_mode() {
		( new SpamAnvil_Stats() )->increment( 'spam_detected', 3 );

		SpamAnvil_Notifier::send_digest();

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
