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
		unset( $_POST['spamanvil_hp'] );
		parent::tear_down();
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
}
