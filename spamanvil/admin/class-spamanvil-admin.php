<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Admin {

	private $encryptor;
	private $provider_factory;
	private $stats;
	private $ip_manager;
	private $queue;
	private $heuristics;

	public function __construct(
		SpamAnvil_Encryptor $encryptor,
		SpamAnvil_Provider_Factory $provider_factory,
		SpamAnvil_Stats $stats,
		SpamAnvil_IP_Manager $ip_manager,
		SpamAnvil_Queue $queue,
		SpamAnvil_Heuristics $heuristics
	) {
		$this->encryptor        = $encryptor;
		$this->provider_factory = $provider_factory;
		$this->stats            = $stats;
		$this->ip_manager       = $ip_manager;
		$this->queue            = $queue;
		$this->heuristics       = $heuristics;
	}

	public function add_menu_page() {
		add_options_page(
			__( 'SpamAnvil', 'spamanvil' ),
			__( 'SpamAnvil', 'spamanvil' ),
			'manage_options',
			'spamanvil',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		// Handle form submissions.
		if ( isset( $_POST['spamanvil_save_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified per-tab inside handle_save_settings().
			$this->handle_save_settings();
		}
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_spamanvil' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'spamanvil-admin',
			SPAMANVIL_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SPAMANVIL_VERSION
		);

		wp_enqueue_script(
			'spamanvil-admin',
			SPAMANVIL_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			SPAMANVIL_VERSION,
			true
		);

		wp_localize_script( 'spamanvil-admin', 'spamAnvil', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'spamanvil_ajax' ),
			'strings'  => array(
				'testing'    => __( 'Testing...', 'spamanvil' ),
				'success'    => __( 'Connection successful!', 'spamanvil' ),
				'error'      => __( 'Connection failed:', 'spamanvil' ),
				'unblocking' => __( 'Unblocking...', 'spamanvil' ),
				'unblocked'  => __( 'IP unblocked successfully', 'spamanvil' ),
				'confirm'    => __( 'Are you sure?', 'spamanvil' ),
				'applied'    => __( 'Applied! Save to confirm.', 'spamanvil' ),
				'scanning'   => __( 'Scanning...', 'spamanvil' ),
				'scan_done'  => __( 'Scan complete!', 'spamanvil' ),
			),
		) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab navigation.
		$tabs       = array(
			'general'    => __( 'General', 'spamanvil' ),
			'providers'  => __( 'Providers', 'spamanvil' ),
			'prompt'     => __( 'Prompt', 'spamanvil' ),
			'ip'         => __( 'IP Management', 'spamanvil' ),
			'stats'      => __( 'Statistics', 'spamanvil' ),
			'logs'       => __( 'Logs', 'spamanvil' ),
		);

		?>
		<div class="wrap spamanvil-wrap">
			<h1><?php esc_html_e( 'SpamAnvil Settings', 'spamanvil' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="spamanvil-content">
				<?php
				$view_file = SPAMANVIL_PLUGIN_DIR . 'admin/views/settings-' . $active_tab . '.php';
				if ( file_exists( $view_file ) ) {
					include $view_file;
				}
				?>
			</div>
		</div>
		<?php
	}

	private function handle_save_settings() {
		$tab = isset( $_POST['spamanvil_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['spamanvil_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified per-tab inside each save method.

		switch ( $tab ) {
			case 'general':
				$this->save_general_settings();
				break;

			case 'providers':
				$this->save_provider_settings();
				break;

			case 'prompt':
				$this->save_prompt_settings();
				break;

			case 'ip':
				$this->save_ip_settings();
				break;
		}

		add_settings_error( 'spamanvil', 'settings_saved', __( 'Settings saved.', 'spamanvil' ), 'success' );
	}

	private function save_general_settings() {
		check_admin_referer( 'spamanvil_general' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'spamanvil_enabled', isset( $_POST['spamanvil_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_mode', sanitize_text_field( wp_unslash( $_POST['spamanvil_mode'] ?? 'async' ) ) );
		update_option( 'spamanvil_threshold', absint( $_POST['spamanvil_threshold'] ?? 70 ) );
		update_option( 'spamanvil_heuristic_auto_spam', absint( $_POST['spamanvil_heuristic_auto_spam'] ?? 95 ) );
		update_option( 'spamanvil_batch_size', absint( $_POST['spamanvil_batch_size'] ?? 5 ) );
		update_option( 'spamanvil_log_retention', absint( $_POST['spamanvil_log_retention'] ?? 30 ) );
		update_option( 'spamanvil_skip_moderators', isset( $_POST['spamanvil_skip_moderators'] ) ? '1' : '0' );
		update_option( 'spamanvil_privacy_notice', isset( $_POST['spamanvil_privacy_notice'] ) ? '1' : '0' );
	}

	private function save_provider_settings() {
		check_admin_referer( 'spamanvil_providers' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'spamanvil_primary_provider', sanitize_text_field( wp_unslash( $_POST['spamanvil_primary_provider'] ?? '' ) ) );
		update_option( 'spamanvil_fallback_provider', sanitize_text_field( wp_unslash( $_POST['spamanvil_fallback_provider'] ?? '' ) ) );

		$providers = array( 'openai', 'openrouter', 'featherless', 'anthropic', 'gemini', 'generic' );

		foreach ( $providers as $slug ) {
			// Save model.
			$model_key = 'spamanvil_' . $slug . '_model';
			if ( isset( $_POST[ $model_key ] ) ) {
				update_option( $model_key, sanitize_text_field( wp_unslash( $_POST[ $model_key ] ) ) );
			}

			// Save API key (only if changed - not masked value).
			$key_field = 'spamanvil_' . $slug . '_api_key';
			if ( isset( $_POST[ $key_field ] ) ) {
				$raw_key = sanitize_text_field( wp_unslash( $_POST[ $key_field ] ) );
				// Only update if not a masked value (contains asterisks means unchanged).
				if ( ! empty( $raw_key ) && strpos( $raw_key, '****' ) === false ) {
					update_option( $key_field, $this->encryptor->encrypt( $raw_key ) );
				}
			}

			// Generic provider URL.
			if ( 'generic' === $slug && isset( $_POST['spamanvil_generic_api_url'] ) ) {
				update_option( 'spamanvil_generic_api_url', esc_url_raw( wp_unslash( $_POST['spamanvil_generic_api_url'] ) ) );
			}
		}
	}

	private function save_prompt_settings() {
		check_admin_referer( 'spamanvil_prompt' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['spamanvil_system_prompt'] ) ) {
			update_option( 'spamanvil_system_prompt', wp_kses_post( wp_unslash( $_POST['spamanvil_system_prompt'] ) ) );
		}
		if ( isset( $_POST['spamanvil_user_prompt'] ) ) {
			update_option( 'spamanvil_user_prompt', wp_kses_post( wp_unslash( $_POST['spamanvil_user_prompt'] ) ) );
		}
		if ( isset( $_POST['spamanvil_spam_words'] ) ) {
			update_option( 'spamanvil_spam_words', sanitize_textarea_field( wp_unslash( $_POST['spamanvil_spam_words'] ) ) );
		}
	}

	private function save_ip_settings() {
		check_admin_referer( 'spamanvil_ip' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'spamanvil_ip_blocking_enabled', isset( $_POST['spamanvil_ip_blocking_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_ip_block_threshold', absint( $_POST['spamanvil_ip_block_threshold'] ?? 3 ) );
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		$provider_slug = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';

		if ( empty( $provider_slug ) ) {
			wp_send_json_error( __( 'No provider specified.', 'spamanvil' ) );
		}

		// Accept inline key/model/url from form fields so test works without saving first.
		$inline_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$inline_model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$inline_url = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';

		$overrides = array();
		if ( ! empty( $inline_key ) ) {
			$overrides['api_key'] = $inline_key;
		}
		if ( ! empty( $inline_model ) ) {
			$overrides['model'] = $inline_model;
		}
		if ( ! empty( $inline_url ) ) {
			$overrides['api_url'] = $inline_url;
		}

		$provider = $this->provider_factory->create( $provider_slug, $overrides );

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( $provider->get_error_message() );
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	public function ajax_scan_pending() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		// Get all comments with 'hold' status.
		$comments = get_comments( array( 'status' => 'hold', 'number' => 0 ) );

		if ( empty( $comments ) ) {
			wp_send_json_success( array(
				'enqueued'       => 0,
				'auto_spam'      => 0,
				'already_queued' => 0,
			) );
		}

		// Get comment IDs already in the queue.
		global $wpdb;
		$queue_table       = $wpdb->prefix . 'spamanvil_queue';
		$already_queued_ids = $wpdb->get_col( "SELECT comment_id FROM {$queue_table} WHERE status IN ('queued', 'processing', 'failed')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin table, no user input.

		$enqueued       = 0;
		$auto_spam      = 0;
		$already_queued = 0;

		$heuristic_threshold = (int) get_option( 'spamanvil_heuristic_auto_spam', 95 );

		foreach ( $comments as $comment ) {
			if ( in_array( (string) $comment->comment_ID, $already_queued_ids, true ) ) {
				$already_queued++;
				continue;
			}

			// Run heuristics.
			$analysis = $this->heuristics->analyze( array(
				'comment_content'      => $comment->comment_content,
				'comment_author'       => $comment->comment_author,
				'comment_author_email' => $comment->comment_author_email,
				'comment_author_url'   => $comment->comment_author_url,
			) );

			if ( $analysis['score'] >= $heuristic_threshold ) {
				wp_spam_comment( $comment->comment_ID );
				$this->stats->increment( 'heuristic_blocked' );
				$this->stats->increment( 'comments_checked' );
				$this->stats->log_evaluation( array(
					'comment_id'        => $comment->comment_ID,
					'score'             => $analysis['score'],
					'provider'          => 'heuristics',
					'model'             => 'regex',
					'reason'            => 'Auto-blocked by heuristic analysis (scan pending)',
					'heuristic_score'   => $analysis['score'],
					'heuristic_details' => $this->heuristics->format_for_prompt( $analysis ),
				) );

				$ip = get_comment_author_IP( $comment->comment_ID );
				if ( ! empty( $ip ) ) {
					$this->ip_manager->record_spam_attempt( $ip );
				}

				$auto_spam++;
			} else {
				$this->queue->enqueue( $comment->comment_ID, $analysis['score'] );
				$enqueued++;
			}
		}

		wp_send_json_success( array(
			'enqueued'       => $enqueued,
			'auto_spam'      => $auto_spam,
			'already_queued' => $already_queued,
		) );
	}

	public function ajax_unblock_ip() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid IP ID.', 'spamanvil' ) );
		}

		$this->ip_manager->unblock_ip( $id );

		wp_send_json_success( __( 'IP unblocked.', 'spamanvil' ) );
	}

	/**
	 * Get masked API key for display.
	 */
	public function get_masked_key( $provider_slug ) {
		$config = SpamAnvil_Provider_Factory::get_provider_config( $provider_slug );

		if ( ! $config ) {
			return '';
		}

		// Check constant first.
		if ( defined( $config['constant_key'] ) ) {
			return $this->encryptor->mask( constant( $config['constant_key'] ) );
		}

		$encrypted = get_option( $config['option_key'], '' );
		if ( ! empty( $encrypted ) ) {
			$decrypted = $this->encryptor->decrypt( $encrypted );
			return $this->encryptor->mask( $decrypted );
		}

		return '';
	}

	public function has_constant_key( $provider_slug ) {
		$config = SpamAnvil_Provider_Factory::get_provider_config( $provider_slug );
		return $config && defined( $config['constant_key'] );
	}
}
