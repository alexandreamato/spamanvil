<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Template variables in an included view file, scoped by the calling method.

settings_errors( 'spamanvil' );

$enabled           = get_option( 'spamanvil_enabled', '1' );
$mode              = get_option( 'spamanvil_mode', 'async' );
$anvil_mode        = get_option( 'spamanvil_anvil_mode', '0' );
$threshold         = (int) get_option( 'spamanvil_threshold', 70 );
$heuristic_auto    = (int) get_option( 'spamanvil_heuristic_auto_spam', 95 );
$batch_size        = (int) get_option( 'spamanvil_batch_size', 5 );
$log_retention     = (int) get_option( 'spamanvil_log_retention', 30 );
$skip_moderators   = get_option( 'spamanvil_skip_moderators', '1' );
$privacy_notice    = get_option( 'spamanvil_privacy_notice', '1' );

$queue_status         = $this->queue->get_queue_status();
$threshold_suggestion = $this->stats->get_threshold_suggestion();
?>

<form method="post" action="">
	<?php wp_nonce_field( 'spamanvil_general' ); ?>
	<input type="hidden" name="spamanvil_tab" value="general">
	<input type="hidden" name="spamanvil_save_settings" value="1">

	<div class="spamanvil-card">
		<h2><?php esc_html_e( 'Queue Status', 'spamanvil' ); ?></h2>
		<div class="spamanvil-status-grid">
			<div class="status-item">
				<span class="status-number"><?php echo esc_html( $queue_status['queued'] ); ?></span>
				<span class="status-label"><?php esc_html_e( 'Queued', 'spamanvil' ); ?></span>
			</div>
			<div class="status-item">
				<span class="status-number"><?php echo esc_html( $queue_status['processing'] ); ?></span>
				<span class="status-label"><?php esc_html_e( 'Processing', 'spamanvil' ); ?></span>
			</div>
			<div class="status-item">
				<span class="status-number"><?php echo esc_html( $queue_status['failed'] ); ?></span>
				<span class="status-label"><?php esc_html_e( 'Failed (Retrying)', 'spamanvil' ); ?></span>
			</div>
			<div class="status-item">
				<span class="status-number"><?php echo esc_html( $queue_status['max_retries'] ); ?></span>
				<span class="status-label"><?php esc_html_e( 'Max Retries', 'spamanvil' ); ?></span>
			</div>
		</div>
		<?php $total_actionable = $queue_status['queued'] + $queue_status['failed'] + $queue_status['max_retries']; ?>
		<p>
			<button type="button" class="button button-secondary spamanvil-process-queue-btn" <?php disabled( $total_actionable, 0 ); ?>>
				<?php esc_html_e( 'Process Queue Now', 'spamanvil' ); ?>
			</button>
			<button type="button" class="button button-secondary spamanvil-stop-queue-btn" style="display:none;">
				<?php esc_html_e( 'Stop', 'spamanvil' ); ?>
			</button>
			<span class="spamanvil-process-queue-result"></span>
		</p>
		<div class="spamanvil-progress-wrap">
			<div class="spamanvil-progress-bar">
				<div class="spamanvil-progress-fill" style="width: 0%;"></div>
				<div class="spamanvil-progress-text"></div>
			</div>
			<div class="spamanvil-progress-details"></div>
		</div>
	</div>

	<?php
	$pending_count = wp_count_comments();
	$pending_count = $pending_count->moderated;
	?>
	<div class="spamanvil-card">
		<h2><?php esc_html_e( 'Scan Pending Comments', 'spamanvil' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: number of pending comments */
				esc_html__( 'There are %s comments awaiting moderation. You can scan them now to run heuristic analysis and enqueue them for LLM evaluation.', 'spamanvil' ),
				'<strong>' . esc_html( number_format_i18n( $pending_count ) ) . '</strong>'
			);
			?>
		</p>
		<button type="button" class="button button-secondary spamanvil-scan-pending-btn" <?php disabled( $pending_count, 0 ); ?>>
			<?php esc_html_e( 'Scan Pending Comments', 'spamanvil' ); ?>
		</button>
		<span class="spamanvil-scan-pending-result"></span>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Plugin', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_enabled" value="1" <?php checked( $enabled, '1' ); ?>>
					<?php esc_html_e( 'Enable SpamAnvil comment checking', 'spamanvil' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Processing Mode', 'spamanvil' ); ?></th>
			<td>
				<select name="spamanvil_mode">
					<option value="async" <?php selected( $mode, 'async' ); ?>>
						<?php esc_html_e( 'Async (WP-Cron) - Recommended', 'spamanvil' ); ?>
					</option>
					<option value="sync" <?php selected( $mode, 'sync' ); ?>>
						<?php esc_html_e( 'Sync (Immediate) - Slower page load', 'spamanvil' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Async mode holds comments as pending and processes them in the background. Sync mode processes immediately but adds latency to comment submission.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Anvil Mode', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_anvil_mode" value="1" <?php checked( $anvil_mode, '1' ); ?>>
					<?php esc_html_e( 'Send comments to ALL configured providers', 'spamanvil' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'If any provider flags a comment as spam, it is blocked. Uses more API calls but provides stronger protection. Requires at least 2 providers configured.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<?php esc_html_e( 'Spam Threshold', 'spamanvil' ); ?>
			</th>
			<td>
				<input type="range" name="spamanvil_threshold" min="0" max="100" step="5"
					   value="<?php echo esc_attr( $threshold ); ?>"
					   class="spamanvil-range" data-display="threshold-display">
				<span id="threshold-display" class="spamanvil-range-value"><?php echo esc_html( $threshold ); ?></span>
				<p class="description">
					<?php esc_html_e( 'Comments scoring at or above this threshold will be marked as spam. Lower = more aggressive. (Default: 70)', 'spamanvil' ); ?>
				</p>
				<?php if ( $threshold_suggestion ) : ?>
					<div class="spamanvil-threshold-suggestion">
						<strong><?php esc_html_e( 'AI Suggestion:', 'spamanvil' ); ?></strong>
						<?php
						printf(
							/* translators: 1: suggested threshold, 2: accuracy percentage, 3: total samples */
							esc_html__( 'Based on %3$s evaluated comments, the optimal threshold is %1$s (accuracy: %2$s%%).', 'spamanvil' ),
							'<strong>' . esc_html( $threshold_suggestion['threshold'] ) . '</strong>',
							esc_html( $threshold_suggestion['accuracy'] ),
							esc_html( number_format_i18n( $threshold_suggestion['total_samples'] ) )
						);
						?>
						<?php if ( (int) $threshold !== (int) $threshold_suggestion['threshold'] ) : ?>
							<button type="button" class="button button-small spamanvil-apply-suggestion"
									data-value="<?php echo esc_attr( $threshold_suggestion['threshold'] ); ?>">
								<?php esc_html_e( 'Apply suggestion', 'spamanvil' ); ?>
							</button>
						<?php endif; ?>
						<br>
						<span class="description">
							<?php
							printf(
								/* translators: 1: spam count, 2: ham count, 3: false positive count, 4: false negative count */
								esc_html__( 'Data: %1$s spam, %2$s legitimate. At this threshold: %3$s false positives, %4$s false negatives.', 'spamanvil' ),
								esc_html( number_format_i18n( $threshold_suggestion['spam_count'] ) ),
								esc_html( number_format_i18n( $threshold_suggestion['ham_count'] ) ),
								esc_html( number_format_i18n( $threshold_suggestion['false_positives'] ) ),
								esc_html( number_format_i18n( $threshold_suggestion['false_negatives'] ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<?php esc_html_e( 'Heuristic Auto-Spam Threshold', 'spamanvil' ); ?>
			</th>
			<td>
				<input type="range" name="spamanvil_heuristic_auto_spam" min="50" max="100" step="5"
					   value="<?php echo esc_attr( $heuristic_auto ); ?>"
					   class="spamanvil-range" data-display="heuristic-display">
				<span id="heuristic-display" class="spamanvil-range-value"><?php echo esc_html( $heuristic_auto ); ?></span>
				<p class="description">
					<?php esc_html_e( 'Comments with heuristic scores at or above this value will be auto-blocked without calling the LLM. (Default: 95)', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Batch Size', 'spamanvil' ); ?></th>
			<td>
				<input type="number" name="spamanvil_batch_size" min="1" max="20"
					   value="<?php echo esc_attr( $batch_size ); ?>" class="small-text">
				<p class="description">
					<?php esc_html_e( 'Number of comments to process per cron run. (Default: 5)', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Log Retention', 'spamanvil' ); ?></th>
			<td>
				<select name="spamanvil_log_retention">
					<option value="7" <?php selected( $log_retention, 7 ); ?>>7 <?php esc_html_e( 'days', 'spamanvil' ); ?></option>
					<option value="30" <?php selected( $log_retention, 30 ); ?>>30 <?php esc_html_e( 'days', 'spamanvil' ); ?></option>
					<option value="90" <?php selected( $log_retention, 90 ); ?>>90 <?php esc_html_e( 'days', 'spamanvil' ); ?></option>
				</select>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Skip Moderators', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_skip_moderators" value="1" <?php checked( $skip_moderators, '1' ); ?>>
					<?php esc_html_e( 'Skip spam checking for users with moderate_comments capability', 'spamanvil' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Delete Data on Uninstall', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_delete_data" value="1" <?php checked( get_option( 'spamanvil_delete_data', '0' ), '1' ); ?>>
					<?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'spamanvil' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'When disabled, your settings, statistics, logs, and blocked IPs are preserved if you reinstall the plugin. Enable this only if you want a complete removal.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Privacy Notice', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_privacy_notice" value="1" <?php checked( $privacy_notice, '1' ); ?>>
					<?php esc_html_e( 'Show privacy notice to commenters', 'spamanvil' ); ?>
				</label>
				<p class="description spamanvil-notice">
					<?php esc_html_e( 'LGPD/Privacy Notice: This plugin sends comment content (including author name, email, URL, and comment text) to third-party AI services for spam analysis. Ensure this complies with your local data protection regulations.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
