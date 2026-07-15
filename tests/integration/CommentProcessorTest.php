<?php
/**
 * Integration tests for the honeypot spam trap (v1.5.0).
 */
class CommentProcessorTest extends WP_UnitTestCase {

	/** @var SpamAnvil_Comment_Processor */
	private $processor;
	/** @var SpamAnvil_Queue */
	private $queue;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		SpamAnvil_Activator::activate();
	}

	public function set_up() {
		parent::set_up();
		update_option( 'spamanvil_enabled', '1' );
		update_option( 'spamanvil_honeypot_enabled', '1' );
		update_option( 'spamanvil_primary_provider', '' );

		$stats      = new SpamAnvil_Stats();
		$heuristics = new SpamAnvil_Heuristics();
		$ip_manager = new SpamAnvil_IP_Manager();
		$this->queue = new SpamAnvil_Queue(
			new SpamAnvil_Provider_Factory( new SpamAnvil_Encryptor() ),
			$stats,
			$heuristics,
			$ip_manager
		);
		$this->processor = new SpamAnvil_Comment_Processor( $heuristics, $ip_manager, $this->queue, $stats );
	}

	public function tear_down() {
		unset( $_POST['spamanvil_hp'], $_POST['spamanvil_ts'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tear_down();
	}

	private function signed_ts( $seconds_ago = 0 ) {
		$ts = time() - (int) $seconds_ago;
		return $ts . '.' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) );
	}

	private function new_comment() {
		return (int) self::factory()->comment->create( array(
			'comment_approved'     => '0',
			'comment_author'       => 'Maria Silva',
			'comment_author_email' => 'maria@example.com',
			'comment_content'      => 'I followed the tutorial step about the entitlement and it worked, thanks.',
		) );
	}

	private function queued_count( $comment_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}spamanvil_queue WHERE comment_id = %d",
			$comment_id
		) );
	}

	public function test_filled_honeypot_marks_spam_without_queueing() {
		$_POST['spamanvil_hp'] = 'http://bot-filled.example';
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertSame( 'spam', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 1, (int) ( new SpamAnvil_Stats() )->get_total( 'honeypot_blocked' ) );
		$this->assertSame( 0, $this->queued_count( $comment_id ), 'A honeypot hit must not reach the LLM queue.' );
	}

	public function test_empty_honeypot_falls_through_to_normal_processing() {
		unset( $_POST['spamanvil_hp'] );
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 1, $this->queued_count( $comment_id ), 'A normal comment should be enqueued for analysis.' );
	}

	public function test_disabled_honeypot_ignores_filled_field() {
		update_option( 'spamanvil_honeypot_enabled', '0' );
		$_POST['spamanvil_hp'] = 'http://bot-filled.example';
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );
	}

	public function test_render_honeypot_outputs_hidden_field() {
		ob_start();
		$this->processor->render_honeypot();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="spamanvil_hp"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
	}

	public function test_render_honeypot_respects_toggle() {
		update_option( 'spamanvil_honeypot_enabled', '0' );
		ob_start();
		$this->processor->render_honeypot();
		$this->assertSame( '', ob_get_clean() );
	}

	// --- Time trap ------------------------------------------------------------

	public function test_time_trap_flags_too_fast_submission() {
		$_POST['spamanvil_ts'] = $this->signed_ts( 0 ); // submitted "now" → elapsed ~0s.
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertSame( 'spam', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 1, (int) ( new SpamAnvil_Stats() )->get_total( 'timetrap_blocked' ) );
		$this->assertSame( 0, $this->queued_count( $comment_id ) );
	}

	public function test_time_trap_allows_slow_enough_submission() {
		$_POST['spamanvil_ts'] = $this->signed_ts( 10 ); // 10s elapsed → human-plausible.
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 1, $this->queued_count( $comment_id ) );
	}

	public function test_time_trap_fails_open_on_invalid_signature() {
		$_POST['spamanvil_ts'] = time() . '.forged-signature'; // fast but tampered.
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 1, $this->queued_count( $comment_id ) );
	}

	public function test_time_trap_disabled_ignores_fast_submission() {
		update_option( 'spamanvil_timetrap_enabled', '0' );
		$_POST['spamanvil_ts'] = $this->signed_ts( 0 );
		$comment_id = $this->new_comment();

		$this->processor->process_new_comment( $comment_id, '0' );

		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );
	}

	public function test_render_time_trap_outputs_signed_field() {
		ob_start();
		$this->processor->render_time_trap();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="spamanvil_ts"', $html );
		$this->assertMatchesRegularExpression( '/value="\d+\.[a-f0-9]{64}"/', $html );
	}

	// --- Rate limit -----------------------------------------------------------

	public function test_rate_limit_blocks_after_threshold() {
		update_option( 'spamanvil_ratelimit_enabled', '1' );
		update_option( 'spamanvil_ratelimit_max', 3 );
		update_option( 'spamanvil_ratelimit_window', 60 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		// The first 3 within the window pass through.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertIsArray( $this->processor->check_rate_limit( array( 'comment_content' => 'hi' ) ) );
		}

		// The 4th trips a 429 (wp_die → WPDieException in the test harness).
		$this->expectException( 'WPDieException' );
		$this->processor->check_rate_limit( array( 'comment_content' => 'hi' ) );
	}

	public function test_rate_limit_disabled_never_blocks() {
		update_option( 'spamanvil_ratelimit_enabled', '0' );
		update_option( 'spamanvil_ratelimit_max', 1 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.6';

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertIsArray( $this->processor->check_rate_limit( array( 'comment_content' => 'hi' ) ) );
		}
	}

	public function test_rate_limit_is_per_ip() {
		update_option( 'spamanvil_ratelimit_enabled', '1' );
		update_option( 'spamanvil_ratelimit_max', 2 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->processor->check_rate_limit( array() );
		$this->processor->check_rate_limit( array() );

		// A different IP starts its own counter — not blocked.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->assertIsArray( $this->processor->check_rate_limit( array() ) );
	}
}
