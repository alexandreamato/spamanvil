<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Admin {

	// Review-request gating: only ask once the plugin has clearly delivered value
	// (>= this many comments checked) AND has been installed long enough.
	private const REVIEW_MIN_CHECKED     = 50;
	private const REVIEW_MIN_AGE_SECONDS = 604800;  // 7 days.
	private const REVIEW_SNOOZE_SECONDS  = 1209600; // 14 days ("Maybe later").

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

	/**
	 * Show an admin warning when SpamAnvil is silently failing — no provider configured,
	 * or a backlog of comments stuck in failed/max_retries (broken key, unparseable model).
	 * The result is cached for 5 minutes so this doesn't query on every admin page load.
	 */
	public function maybe_show_health_notice() {
		if ( ! current_user_can( 'manage_options' ) || '1' !== get_option( 'spamanvil_enabled', '1' ) ) {
			return;
		}

		$health = get_transient( 'spamanvil_health_check' );
		if ( false === $health ) {
			$status = $this->queue->get_queue_status();

			// Comments waiting but WP-Cron not firing (typically DISABLE_WP_CRON without
			// a system cron hitting wp-cron.php) starves the whole async pipeline.
			$last_run  = (int) get_option( 'spamanvil_last_cron_run', 0 );
			$cron_dead = ( (int) $status['queued'] > 0
				&& 'async' === get_option( 'spamanvil_mode', 'async' )
				&& ( time() - $last_run ) > 30 * MINUTE_IN_SECONDS ) ? 1 : 0;

			$health = array(
				'stuck'     => (int) $status['failed'] + (int) $status['max_retries'],
				'no_prov'   => ( '' === get_option( 'spamanvil_primary_provider', '' ) ) ? 1 : 0,
				'bad_key'   => $this->provider_factory->has_undecryptable_key() ? 1 : 0,
				'paused'    => $this->queue->is_paused() ? 1 : 0,
				'cron_dead' => $cron_dead,
			);
			set_transient( 'spamanvil_health_check', $health, 5 * MINUTE_IN_SECONDS );
		}

		$health = array_merge( array( 'paused' => 0, 'cron_dead' => 0 ), (array) $health );

		if ( $health['stuck'] < 5 && empty( $health['no_prov'] ) && empty( $health['bad_key'] )
			&& empty( $health['paused'] ) && empty( $health['cron_dead'] ) ) {
			return; // Healthy enough — stay quiet.
		}

		$providers_url = admin_url( 'options-general.php?page=spamanvil&tab=providers' );
		$logs_url      = admin_url( 'options-general.php?page=spamanvil&tab=logs' );

		// A paused queue is the most severe state: nothing is being classified at all.
		if ( ! empty( $health['paused'] ) ) {
			$info   = $this->queue->get_pause_info();
			$reason = $info && ! empty( $info['message'] ) ? $info['message'] : '';
			echo '<div class="notice notice-error"><p><strong>SpamAnvil:</strong> ';
			esc_html_e( 'comment classification is paused because of a configuration error. Fix the provider settings and processing resumes automatically.', 'spamanvil' );
			if ( '' !== $reason ) {
				echo '<br><em>' . esc_html( $reason ) . '</em>';
			}
			printf( ' <a href="%s">%s</a></p></div>', esc_url( $providers_url ), esc_html__( 'Open the Providers tab', 'spamanvil' ) );
			return;
		}

		// A stored key that no longer decrypts is the most actionable — surface it first.
		if ( ! empty( $health['bad_key'] ) ) {
			echo '<div class="notice notice-error"><p><strong>SpamAnvil:</strong> ';
			esc_html_e( 'a saved API key can no longer be decrypted — your site security keys (AUTH_SALT) likely changed. Re-enter the API key so classification can resume.', 'spamanvil' );
			printf( ' <a href="%s">%s</a></p></div>', esc_url( $providers_url ), esc_html__( 'Open the Providers tab', 'spamanvil' ) );
			return;
		}

		// Comments queued but the cron never runs — the site owner must fix WP-Cron.
		if ( ! empty( $health['cron_dead'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>SpamAnvil:</strong> ';
			esc_html_e( 'comments are waiting in the queue but WP-Cron has not run recently.', 'spamanvil' );
			echo ' ';
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
				esc_html_e( 'DISABLE_WP_CRON is enabled on this site — make sure a real (system) cron job calls wp-cron.php regularly, or comments will never be classified.', 'spamanvil' );
			} else {
				esc_html_e( 'Low-traffic sites may need a real (system) cron job calling wp-cron.php, or use the "Process Queue Now" button on the Logs tab.', 'spamanvil' );
			}
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-error"><p><strong>SpamAnvil:</strong> ';
		if ( ! empty( $health['no_prov'] ) ) {
			esc_html_e( 'no AI provider is configured, so comments are not being classified.', 'spamanvil' );
		} else {
			printf(
				/* translators: %d: number of comments stuck in failed/max-retries */
				esc_html__( '%d comment(s) could not be classified (failed or max retries) — check the provider configuration and API key.', 'spamanvil' ),
				(int) $health['stuck']
			);
		}
		printf( ' <a href="%s">%s</a></p></div>', esc_url( $logs_url ), esc_html__( 'View SpamAnvil logs', 'spamanvil' ) );
	}

	/**
	 * Pure decision: is the review request due? Side-effect-free so it can be
	 * unit-tested without a WordPress bootstrap.
	 *
	 * @param bool $dismissed        Whether the user permanently dismissed the ask.
	 * @param int  $snooze_until     Unix time until which the ask is snoozed.
	 * @param int  $comments_checked Total comments the plugin has classified.
	 * @param int  $activated_at     Unix time the plugin was activated (0 if unknown).
	 * @param int  $now              Current Unix time.
	 * @param int  $min_checked      Min comments checked before asking (filterable; default 50).
	 * @param int  $min_age_seconds  Min seconds installed before asking (filterable; default 7 days).
	 * @return bool
	 */
	public static function review_notice_due( $dismissed, $snooze_until, $comments_checked, $activated_at, $now, $min_checked = self::REVIEW_MIN_CHECKED, $min_age_seconds = self::REVIEW_MIN_AGE_SECONDS ) {
		if ( $dismissed ) {
			return false;
		}
		if ( (int) $snooze_until > (int) $now ) {
			return false;
		}
		if ( (int) $comments_checked < (int) $min_checked ) {
			return false;
		}
		// Give the plugin time to prove itself first (avoids day-one asks on high-traffic sites).
		if ( (int) $activated_at > 0 && ( (int) $now - (int) $activated_at ) < (int) $min_age_seconds ) {
			return false;
		}
		return true;
	}

	private function should_show_review_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		/**
		 * Filters the minimum number of comments SpamAnvil must have classified
		 * before it asks for a review. Lower it on low-traffic sites; raise it to
		 * ask later.
		 *
		 * @param int $min_checked Default 50.
		 */
		$min_checked = (int) apply_filters( 'spamanvil_review_min_checked', self::REVIEW_MIN_CHECKED );

		/**
		 * Filters the minimum time (in seconds) the plugin must have been installed
		 * before it asks for a review.
		 *
		 * @param int $min_age_seconds Default 604800 (7 days).
		 */
		$min_age = (int) apply_filters( 'spamanvil_review_min_age_seconds', self::REVIEW_MIN_AGE_SECONDS );

		return self::review_notice_due(
			(bool) get_option( 'spamanvil_dismiss_review' ),
			(int) get_option( 'spamanvil_review_snooze_until', 0 ),
			(int) $this->stats->get_total( 'comments_checked' ),
			(int) get_option( 'spamanvil_activated_at', 0 ),
			time(),
			$min_checked,
			$min_age
		);
	}

	/**
	 * Global admin notice asking for a review once the plugin has earned it.
	 * Uses nonce'd links (handled in maybe_handle_review_action) so it works on
	 * any admin screen without depending on the plugin's JS being enqueued there.
	 */
	public function maybe_show_review_notice() {
		if ( ! $this->should_show_review_notice() ) {
			return;
		}

		$checked    = number_format_i18n( (int) $this->stats->get_total( 'comments_checked' ) );
		$snooze_url  = wp_nonce_url( add_query_arg( 'spamanvil_review', 'snooze' ), 'spamanvil_review_action' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'spamanvil_review', 'dismiss' ), 'spamanvil_review_action' );
		$review_url  = wp_nonce_url( add_query_arg( 'spamanvil_review', 'review' ), 'spamanvil_review_action' );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: number of comments checked */
					esc_html__( 'SpamAnvil has checked %s comments on your site. If it has been useful, an honest review helps other people find it — and keeps the plugin free.', 'spamanvil' ),
					'<strong>' . esc_html( $checked ) . '</strong>'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $review_url ); ?>" class="button button-primary"><?php esc_html_e( 'Leave a review ★★★★★', 'spamanvil' ); ?></a>
				<a href="<?php echo esc_url( $snooze_url ); ?>" class="button"><?php esc_html_e( 'Maybe later', 'spamanvil' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link"><?php esc_html_e( 'I already did / don\'t ask again', 'spamanvil' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the review-notice buttons (snooze / dismiss / review) on admin_init.
	 */
	public function maybe_handle_review_action() {
		if ( empty( $_GET['spamanvil_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'spamanvil_review_action' );

		$action = sanitize_key( wp_unslash( $_GET['spamanvil_review'] ) );

		if ( 'snooze' === $action ) {
			update_option( 'spamanvil_review_snooze_until', time() + self::REVIEW_SNOOZE_SECONDS );
		} elseif ( 'dismiss' === $action ) {
			update_option( 'spamanvil_dismiss_review', '1' );
		} elseif ( 'review' === $action ) {
			// Assume they're about to review — stop asking, then send them to WordPress.org.
			update_option( 'spamanvil_dismiss_review', '1' );
			wp_redirect( 'https://wordpress.org/support/plugin/spamanvil/reviews/#new-post' );
			exit;
		}

		wp_safe_redirect( remove_query_arg( array( 'spamanvil_review', '_wpnonce' ) ) );
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
				'testing'      => __( 'Testing...', 'spamanvil' ),
			'add_to_chain' => __( 'Add to model chain', 'spamanvil' ),
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
				'loading_models'    => __( 'Loading models…', 'spamanvil' ),
				'models_error'      => __( 'Could not load models:', 'spamanvil' ),
				'no_models_match'   => __( 'No models match your search.', 'spamanvil' ),
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

		// Whitelist the tab: $active_tab is used to build the view include path below,
		// so never let an unknown value through even though file_exists() already gates it.
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}

		$is_welcome    = isset( $_GET['welcome'] ) && '1' === $_GET['welcome']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$show_welcome  = $is_welcome && ! get_option( 'spamanvil_dismiss_welcome' );
		$show_setup    = get_option( 'spamanvil_enabled', '1' ) === '1'
			&& empty( get_option( 'spamanvil_primary_provider', '' ) )
			&& ! get_option( 'spamanvil_dismiss_setup' );
		// The review request is now a global admin notice (maybe_show_review_notice),
		// so it reaches users on any admin screen — not only this settings page.

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

		if ( ! in_array( $tab, array( 'general', 'providers', 'prompt', 'ip' ), true ) ) {
			return;
		}

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

		// Only report success to a user who could actually save. The per-tab save methods
		// no-op (return early) when the capability check fails, so showing an unconditional
		// "Settings saved." would be misleading for a user lacking manage_options.
		if ( current_user_can( 'manage_options' ) ) {
			add_settings_error( 'spamanvil', 'settings_saved', __( 'Settings saved.', 'spamanvil' ), 'success' );
		}
	}

	private function save_general_settings() {
		check_admin_referer( 'spamanvil_general' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'spamanvil_enabled', isset( $_POST['spamanvil_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_mode', sanitize_text_field( wp_unslash( $_POST['spamanvil_mode'] ?? 'async' ) ) );
		update_option( 'spamanvil_open_mode', isset( $_POST['spamanvil_open_mode'] ) ? '1' : '0' );

		$email_mode = sanitize_text_field( wp_unslash( $_POST['spamanvil_email_mode'] ?? 'smart' ) );
		update_option( 'spamanvil_email_mode', in_array( $email_mode, array( 'off', 'smart', 'digest' ), true ) ? $email_mode : 'smart' );
		update_option( 'spamanvil_anvil_mode', isset( $_POST['spamanvil_anvil_mode'] ) ? '1' : '0' );
		update_option( 'spamanvil_threshold', absint( $_POST['spamanvil_threshold'] ?? 70 ) );
		update_option( 'spamanvil_heuristic_auto_spam', absint( $_POST['spamanvil_heuristic_auto_spam'] ?? 95 ) );
		update_option( 'spamanvil_batch_size', absint( $_POST['spamanvil_batch_size'] ?? 5 ) );
		update_option( 'spamanvil_log_retention', absint( $_POST['spamanvil_log_retention'] ?? 30 ) );
		update_option( 'spamanvil_skip_moderators', isset( $_POST['spamanvil_skip_moderators'] ) ? '1' : '0' );
		update_option( 'spamanvil_honeypot_enabled', isset( $_POST['spamanvil_honeypot_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_timetrap_enabled', isset( $_POST['spamanvil_timetrap_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_timetrap_seconds', max( 1, min( 60, absint( $_POST['spamanvil_timetrap_seconds'] ?? 3 ) ) ) );
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
		update_option( 'spamanvil_auto_free_fallback', isset( $_POST['spamanvil_auto_free_fallback'] ) ? '1' : '0' );

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

		// Re-run the health check on the next admin page load so a fixed key
		// (or provider change) clears the sitewide notice immediately.
		delete_transient( 'spamanvil_health_check' );
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
		// Line endings are LF-normalized on save: browsers submit textareas with CRLF,
		// which made an unmodified default hash differently from the source string and
		// silently blocked default-prompt migrations (field audit N1, 1.14.1).
		if ( isset( $_POST['spamanvil_system_prompt'] ) ) {
			update_option( 'spamanvil_system_prompt', SpamAnvil_Activator::normalize_prompt( wp_unslash( $_POST['spamanvil_system_prompt'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional: plain-text LLM prompt template, not HTML. Contains <comment_data> tags that wp_kses would strip.
		}
		if ( isset( $_POST['spamanvil_user_prompt'] ) ) {
			update_option( 'spamanvil_user_prompt', SpamAnvil_Activator::normalize_prompt( wp_unslash( $_POST['spamanvil_user_prompt'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional: plain-text LLM prompt template, not HTML. Contains <comment_data> tags that wp_kses would strip.
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

		$ip_header_choices = array( 'remote_addr', 'cf', 'x_real_ip', 'xff_last', 'auto' );
		$ip_header         = isset( $_POST['spamanvil_trusted_ip_header'] ) ? sanitize_text_field( wp_unslash( $_POST['spamanvil_trusted_ip_header'] ) ) : 'remote_addr';
		update_option( 'spamanvil_trusted_ip_header', in_array( $ip_header, $ip_header_choices, true ) ? $ip_header : 'remote_addr' );
		update_option( 'spamanvil_ratelimit_enabled', isset( $_POST['spamanvil_ratelimit_enabled'] ) ? '1' : '0' );
		update_option( 'spamanvil_ratelimit_max', max( 1, min( 100, absint( $_POST['spamanvil_ratelimit_max'] ?? 5 ) ) ) );
		update_option( 'spamanvil_ratelimit_window', max( 5, min( 3600, absint( $_POST['spamanvil_ratelimit_window'] ?? 60 ) ) ) );
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
		// Ignore a masked key (the "****" placeholder for an unchanged field) so the
		// test uses the real stored key instead of testing the mask.
		if ( ! empty( $inline_key ) && false === strpos( $inline_key, '****' ) ) {
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

		// A green Test Connection proves the AI is reachable again — record it so the
		// queue gives parked max_retries items an early retry cycle (1.14.0).
		update_option( 'spamanvil_last_llm_success', time(), false );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: list a provider's available models for the settings-page picker.
	 */
	public function ajax_list_models() {
		check_ajax_referer( 'spamanvil_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spamanvil' ) );
		}

		$provider_slug = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		if ( empty( $provider_slug ) ) {
			wp_send_json_error( __( 'No provider specified.', 'spamanvil' ) );
		}

		$inline_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$inline_url = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';

		// No model is chosen yet — pass a placeholder so create() doesn't reject an empty
		// model. Ignore a masked key so the stored key is resolved instead.
		$overrides = array( 'model' => 'model-listing' );
		if ( ! empty( $inline_key ) && false === strpos( $inline_key, '****' ) ) {
			$overrides['api_key'] = $inline_key;
		}
		if ( ! empty( $inline_url ) ) {
			$overrides['api_url'] = $inline_url;
		}

		$provider = $this->provider_factory->create( $provider_slug, $overrides );
		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( $provider->get_error_message() );
		}

		if ( ! method_exists( $provider, 'list_models' ) ) {
			wp_send_json_error( __( 'This provider does not support listing models.', 'spamanvil' ) );
		}

		$models = $provider->list_models();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( $models->get_error_message() );
		}

		wp_send_json_success( array( 'models' => $models ) );
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
		delete_transient( 'spamanvil_health_check' );

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
