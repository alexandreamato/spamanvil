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

	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'spamanvil_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'spamanvil_activation_redirect' );

		// Skip redirect on bulk activation, AJAX, or network admin.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect guard.
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=spamanvil&welcome=1' ) );
		exit;
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
		// Load CSS on plugin settings page and main dashboard (for widget).
		if ( 'index.php' === $hook ) {
			wp_enqueue_style(
				'spamanvil-admin',
				SPAMANVIL_PLUGIN_URL . 'admin/css/admin.css',
				array(),
				SPAMANVIL_VERSION
			);
			return;
		}

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
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'spamanvil_ajax' ),
			'has_provider' => ( '' !== get_option( 'spamanvil_primary_provider', '' ) ),
			'providers_url' => admin_url( 'options-general.php?page=spamanvil&tab=providers' ),
			'strings'  => array(
				'testing'    => __( 'Testing...', 'spamanvil' ),
				'success'    => __( 'Connection successful!', 'spamanvil' ),
				'error'      => __( 'Connection failed:', 'spamanvil' ),
				'unblocking' => __( 'Unblocking...', 'spamanvil' ),
				'unblocked'  => __( 'IP unblocked successfully', 'spamanvil' ),
				'confirm'    => __( 'Are you sure?', 'spamanvil' ),
				'applied'    => __( 'Applied! Save to confirm.', 'spamanvil' ),
				'scanning'      => __( 'Scanning...', 'spamanvil' ),
				'scan_done'     => __( 'Scan complete!', 'spamanvil' ),
				'processing'    => __( 'Processing...', 'spamanvil' ),
				'process_done'  => __( 'Done!', 'spamanvil' ),
				'process_batch'     => __( 'Processing batch...', 'spamanvil' ),
				'process_stop'      => __( 'Stop', 'spamanvil' ),
				'process_stopping'  => __( 'Stopping...', 'spamanvil' ),
				'process_stopped'   => __( 'Stopped.', 'spamanvil' ),
				'process_retrying'  => __( 'Connection error, retrying...', 'spamanvil' ),
				'process_failed'    => __( 'Failed after multiple retries.', 'spamanvil' ),
				'items_min'         => __( 'items/min', 'spamanvil' ),
				'spam'              => __( 'Spam', 'spamanvil' ),
				'ham'               => __( 'Ham', 'spamanvil' ),
				'confirm_clear_key' => __( 'Are you sure you want to delete this API key?', 'spamanvil' ),
				'enter_key'         => __( 'Enter API key', 'spamanvil' ),
				'confirm_load_words' => __( 'This will merge an extended spam word list into your current list. Continue?', 'spamanvil' ),
				'words_added'       => __( 'new words added. Save to confirm.', 'spamanvil' ),
				'words_loaded'      => __( 'Extended list loaded. Save to confirm.', 'spamanvil' ),
				'no_provider'       => __( 'No provider configured.', 'spamanvil' ),
				'configure_provider' => __( 'Configure a Provider', 'spamanvil' ),
				'batch_all_failed'  => __( 'Batch failed — check Logs tab for details.', 'spamanvil' ),
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

		$is_welcome    = isset( $_GET['welcome'] ) && '1' === $_GET['welcome']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$show_welcome  = $is_welcome && ! get_option( 'spamanvil_dismiss_welcome' );
		$show_setup    = get_option( 'spamanvil_enabled', '1' ) === '1'
			&& empty( get_option( 'spamanvil_primary_provider', '' ) )
			&& ! get_option( 'spamanvil_dismiss_setup' );
		$show_review   = ! get_option( 'spamanvil_dismiss_review' )
			&& $this->stats->get_total( 'comments_checked' ) >= 50;

		?>
		<div class="wrap spamanvil-wrap">
			<h1><?php esc_html_e( 'SpamAnvil Settings', 'spamanvil' ); ?></h1>

			<?php if ( $show_welcome ) : ?>
				<div class="notice notice-info is-dismissible spamanvil-dismissible" data-notice="spamanvil_dismiss_welcome">
					<p>
						<strong><?php esc_html_e( 'Welcome to SpamAnvil!', 'spamanvil' ); ?></strong>
						<?php esc_html_e( 'Thank you for installing SpamAnvil. To get started, configure an AI provider below.', 'spamanvil' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil&tab=providers' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Configure a Provider', 'spamanvil' ); ?></a>
						<a href="https://software.amato.com.br/spamanvil-antispam-plugin-for-wordpress/" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'Read the Docs', 'spamanvil' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $show_setup ) : ?>
				<div class="notice notice-warning is-dismissible spamanvil-dismissible" data-notice="spamanvil_dismiss_setup">
					<p>
						<strong><?php esc_html_e( 'SpamAnvil is enabled but no provider is configured.', 'spamanvil' ); ?></strong>
						<?php esc_html_e( 'Comments cannot be analyzed until you configure at least one AI provider.', 'spamanvil' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil&tab=providers' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Configure a Provider', 'spamanvil' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $show_review ) : ?>
				<div class="notice notice-info is-dismissible spamanvil-dismissible" data-notice="spamanvil_dismiss_review">
					<p>
						<?php
						printf(
							/* translators: %s: number of comments checked */
							esc_html__( 'SpamAnvil has checked %s comments for you! If it\'s helping keep your site clean, would you mind leaving a quick review? It really helps!', 'spamanvil' ),
							'<strong>' . esc_html( number_format_i18n( $this->stats->get_total( 'comments_checked' ) ) ) . '</strong>'
						);
						?>
					</p>
					<p>
						<a href="https://wordpress.org/support/plugin/spamanvil/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary"><?php esc_html_e( 'Leave a Review', 'spamanvil' ); ?></a>
						<a href="https://github.com/sponsors/alexandreamato" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'or buy me a beer ☕', 'spamanvil' ); ?></a>
						<button type="button" class="button spamanvil-dismiss-btn" data-notice="spamanvil_dismiss_review"><?php esc_html_e( 'No thanks, don\'t ask again', 'spamanvil' ); ?></button>
					</p>
				</div>
			<?php endif; ?>

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

			<div class="spamanvil-footer-card">
				<?php
				printf(
					/* translators: %s: sponsor link */
					esc_html__( "What's the next WordPress problem I'll solve and make free? I'm tired of expensive solutions for simple problems. %s", 'spamanvil' ),
					'<a href="https://github.com/sponsors/alexandreamato" target="_blank" rel="noopener noreferrer" class="spamanvil-sponsor-link">' . esc_html__( 'Buy me a beer ☕', 'spamanvil' ) . '</a>'
				);
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
		update_option( 'spamanvil_anvil_mode', isset( $_POST['spamanvil_anvil_mode'] ) ? '1' : '0' );
		update_option( 'spamanvil_threshold', absint( $_POST['spamanvil_threshold'] ?? 70 ) );
		update_option( 'spamanvil_heuristic_auto_spam', absint( $_POST['spamanvil_heuristic_auto_spam'] ?? 95 ) );
		update_option( 'spamanvil_batch_size', absint( $_POST['spamanvil_batch_size'] ?? 5 ) );
		update_option( 'spamanvil_log_retention', absint( $_POST['spamanvil_log_retention'] ?? 30 ) );
		update_option( 'spamanvil_skip_moderators', isset( $_POST['spamanvil_skip_moderators'] ) ? '1' : '0' );
		update_option( 'spamanvil_delete_data', isset( $_POST['spamanvil_delete_data'] ) ? '1' : '0' );
		update_option( 'spamanvil_privacy_notice', isset( $_POST['spamanvil_privacy_notice'] ) ? '1' : '0' );
	}

	private function save_provider_settings() {
		check_admin_referer( 'spamanvil_providers' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'spamanvil_primary_provider', sanitize_text_field( wp_unslash( $_POST['spamanvil_primary_provider'] ?? '' ) ) );
		update_option( 'spamanvil_fallback_provider', sanitize_text_field( wp_unslash( $_POST['spamanvil_fallback_provider'] ?? '' ) ) );
		update_option( 'spamanvil_fallback2_provider', sanitize_text_field( wp_unslash( $_POST['spamanvil_fallback2_provider'] ?? '' ) ) );

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

		// Prompts are plain-text templates sent to the LLM API, not rendered as HTML.
		// They intentionally contain angle-bracket tags like <comment_data> and <number 0-100>.
		// wp_kses_post() would strip those, so we use wp_unslash() only.
		// Field is admin-only (manage_options) and output via esc_textarea().
		if ( isset( $_POST['spamanvil_system_prompt'] ) ) {
			update_option( 'spamanvil_system_prompt', wp_unslash( $_POST['spamanvil_system_prompt'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional: plain-text LLM prompt template, not HTML. Contains <comment_data> tags that wp_kses would strip.
		}
		if ( isset( $_POST['spamanvil_user_prompt'] ) ) {
			update_option( 'spamanvil_user_prompt', wp_unslash( $_POST['spamanvil_user_prompt'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional: plain-text LLM prompt template, not HTML. Contains <comment_data> tags that wp_kses would strip.
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

		// Count pending comments before scanning for the already_queued stat.
		$pending_count = (int) wp_count_comments()->moderated;

		// Capture stats before to compute auto_spam count.
		$heuristic_before = $this->stats->get_total( 'heuristic_blocked' );

		$enqueued = $this->queue->auto_enqueue_pending( 0 ); // 0 = scan all (manual action).

		$heuristic_after = $this->stats->get_total( 'heuristic_blocked' );
		$auto_spam       = $heuristic_after - $heuristic_before;
		$already_queued  = max( 0, $pending_count - $enqueued - $auto_spam );

		// Trigger immediate cron run so the queue starts processing without waiting.
		if ( $enqueued > 0 ) {
			spawn_cron();
		}

		wp_send_json_success( array(
			'enqueued'       => $enqueued,
			'auto_spam'      => $auto_spam,
			'already_queued' => $already_queued,
		) );
	}

	public function ajax_process_queue() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		if ( '' === get_option( 'spamanvil_primary_provider', '' ) ) {
			wp_send_json_error( __( 'No provider configured. Go to the Providers tab to set one up.', 'spamanvil' ) );
		}

		// Extend PHP execution time — 45s is safe for most hosting environments.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 45 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for long-running LLM API calls.
		}

		$before       = $this->queue->get_queue_status();
		$stats_before = $this->stats->get_summary( 1 );

		// Time guard: stop processing after 25s to finish well within server timeouts.
		$attempted = $this->queue->process_batch( true, 25 );

		$after       = $this->queue->get_queue_status();
		$stats_after = $this->stats->get_summary( 1 );

		$completed = max( 0, $after['completed'] - $before['completed'] );
		$remaining = $after['queued'] + $after['failed'] + $after['max_retries'];

		wp_send_json_success( array(
			'processed'  => $completed,
			'attempted'  => $attempted,
			'remaining'  => $remaining,
			'queue'      => $after,
			'batch_spam' => max( 0, $stats_after['spam_detected'] - $stats_before['spam_detected'] ),
			'batch_ham'  => max( 0, $stats_after['ham_approved'] - $stats_before['ham_approved'] ),
			'alltime'    => array(
				'ai'        => $this->stats->get_total( 'spam_detected' ),
				'heuristic' => $this->stats->get_total( 'heuristic_blocked' ),
				'ip'        => $this->stats->get_total( 'ip_blocked' ),
			),
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

	public function ajax_clear_api_key() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		$provider_slug = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$config        = SpamAnvil_Provider_Factory::get_provider_config( $provider_slug );

		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid provider.', 'spamanvil' ) );
		}

		if ( defined( $config['constant_key'] ) ) {
			wp_send_json_error( __( 'Key is defined in wp-config.php and cannot be cleared from here.', 'spamanvil' ) );
		}

		delete_option( $config['option_key'] );

		wp_send_json_success( __( 'API key cleared.', 'spamanvil' ) );
	}

	public function ajax_dismiss_notice() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		$notice = isset( $_POST['notice'] ) ? sanitize_text_field( wp_unslash( $_POST['notice'] ) ) : '';

		$allowed = array( 'spamanvil_dismiss_welcome', 'spamanvil_dismiss_review', 'spamanvil_dismiss_setup' );

		if ( ! in_array( $notice, $allowed, true ) ) {
			wp_send_json_error( __( 'Invalid notice.', 'spamanvil' ) );
		}

		update_option( $notice, '1' );

		wp_send_json_success();
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

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'spamanvil_dashboard_widget',
			__( 'SpamAnvil', 'spamanvil' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$alltime_spam      = $this->stats->get_total( 'spam_detected' );
		$alltime_heuristic = $this->stats->get_total( 'heuristic_blocked' );
		$alltime_ip        = $this->stats->get_total( 'ip_blocked' );
		$alltime_blocked   = $alltime_spam + $alltime_heuristic + $alltime_ip;

		?>
		<div class="spamanvil-widget">
			<div class="spamanvil-widget-number"><?php echo esc_html( number_format_i18n( $alltime_blocked ) ); ?></div>
			<div class="spamanvil-widget-label"><?php esc_html_e( 'Spam Comments Blocked', 'spamanvil' ); ?></div>
			<div class="spamanvil-widget-breakdown">
				<?php
				printf(
					/* translators: 1: LLM spam count, 2: heuristic count, 3: IP blocked count */
					esc_html__( '%1$s by AI  |  %2$s by Heuristics  |  %3$s by IP Blocking', 'spamanvil' ),
					'<strong>' . esc_html( number_format_i18n( $alltime_spam ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $alltime_heuristic ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $alltime_ip ) ) . '</strong>'
				);
				?>
			</div>
			<div class="spamanvil-widget-links">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil' ) ); ?>"><?php esc_html_e( 'Settings', 'spamanvil' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=spamanvil&tab=stats' ) ); ?>"><?php esc_html_e( 'Statistics', 'spamanvil' ); ?></a>
				<?php if ( $alltime_blocked >= 20 && ! get_option( 'spamanvil_dismiss_review' ) ) : ?>
					<a href="https://wordpress.org/support/plugin/spamanvil/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="spamanvil-widget-rate"><?php esc_html_e( 'Rate ★★★★★', 'spamanvil' ); ?></a>
				<?php endif; ?>
				<a href="https://github.com/sponsors/alexandreamato" target="_blank" rel="noopener noreferrer" class="spamanvil-widget-rate"><?php esc_html_e( 'Sponsor ☕', 'spamanvil' ); ?></a>
			</div>
		</div>
		<?php
	}
}
