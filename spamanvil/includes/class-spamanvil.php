<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil {

	private static $instance = null;

	private $encryptor;
	private $heuristics;
	private $ip_manager;
	private $stats;
	private $provider_factory;
	private $queue;
	private $comment_processor;
	private $admin;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		$this->instantiate_components();
		$this->check_db_version();
		$this->define_hooks();
		$this->ensure_cron_scheduled();
	}

	private function instantiate_components() {
		$this->encryptor        = new SpamAnvil_Encryptor();
		$this->heuristics       = new SpamAnvil_Heuristics();
		$this->ip_manager       = new SpamAnvil_IP_Manager();
		$this->stats            = new SpamAnvil_Stats();
		$this->provider_factory = new SpamAnvil_Provider_Factory( $this->encryptor );
		$this->queue            = new SpamAnvil_Queue(
			$this->provider_factory,
			$this->stats,
			$this->heuristics,
			$this->ip_manager
		);
		$this->comment_processor = new SpamAnvil_Comment_Processor(
			$this->heuristics,
			$this->ip_manager,
			$this->queue,
			$this->stats
		);

		if ( is_admin() ) {
			$this->admin = new SpamAnvil_Admin(
				$this->encryptor,
				$this->provider_factory,
				$this->stats,
				$this->ip_manager,
				$this->queue,
				$this->heuristics
			);
		}
	}

	private function define_hooks() {
		// Custom cron interval.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// Comment processing hooks.
		add_filter( 'preprocess_comment', array( $this->comment_processor, 'check_blocked_ip' ), 10 );
		add_filter( 'pre_comment_approved', array( $this->comment_processor, 'hold_for_review' ), 99, 2 );
		add_action( 'comment_post', array( $this->comment_processor, 'process_new_comment' ), 10, 2 );

		// Cron hooks.
		add_action( 'spamanvil_process_queue', array( $this->queue, 'process_batch' ) );
		add_action( 'spamanvil_cleanup_logs', array( $this->stats, 'cleanup_old_logs' ) );

		// Admin hooks.
		if ( is_admin() && $this->admin ) {
			add_action( 'admin_menu', array( $this->admin, 'add_menu_page' ) );
			add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
			add_action( 'admin_init', array( $this->admin, 'maybe_redirect_after_activation' ) );
			add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
			add_action( 'wp_dashboard_setup', array( $this->admin, 'register_dashboard_widget' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_spamanvil_test_connection', array( $this->admin, 'ajax_test_connection' ) );
			add_action( 'wp_ajax_spamanvil_unblock_ip', array( $this->admin, 'ajax_unblock_ip' ) );
			add_action( 'wp_ajax_spamanvil_scan_pending', array( $this->admin, 'ajax_scan_pending' ) );
			add_action( 'wp_ajax_spamanvil_process_queue', array( $this->admin, 'ajax_process_queue' ) );
			add_action( 'wp_ajax_spamanvil_clear_api_key', array( $this->admin, 'ajax_clear_api_key' ) );
			add_action( 'wp_ajax_spamanvil_dismiss_notice', array( $this->admin, 'ajax_dismiss_notice' ) );
		}

		// Plugin action links.
		add_filter( 'plugin_action_links_' . SPAMANVIL_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	public function add_cron_interval( $schedules ) {
		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'spamanvil' ),
		);
		return $schedules;
	}

	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=spamanvil' ),
			__( 'Settings', 'spamanvil' )
		);
		array_unshift( $links, $settings_link );

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			'https://wordpress.org/support/plugin/spamanvil/reviews/#new-post',
			esc_html__( 'Rate ★★★★★', 'spamanvil' )
		);
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			'https://software.amato.com.br/spamanvil-antispam-plugin-for-wordpress/',
			esc_html__( 'Docs', 'spamanvil' )
		);
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#dba617;font-weight:600;">%s</a>',
			'https://github.com/sponsors/alexandreamato',
			esc_html__( 'Sponsor ☕', 'spamanvil' )
		);

		return $links;
	}

	private function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( 'spamanvil_process_queue' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'spamanvil_process_queue' );
		}
		if ( ! wp_next_scheduled( 'spamanvil_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'spamanvil_cleanup_logs' );
		}
	}

	private function check_db_version() {
		$current = get_option( 'spamanvil_db_version', '' );
		if ( $current !== SPAMANVIL_DB_VERSION ) {
			SpamAnvil_Activator::activate();
		}
	}

	// Getters for components (useful for extensions).
	public function get_encryptor() {
		return $this->encryptor;
	}

	public function get_provider_factory() {
		return $this->provider_factory;
	}

	public function get_stats() {
		return $this->stats;
	}

	public function get_queue() {
		return $this->queue;
	}
}
