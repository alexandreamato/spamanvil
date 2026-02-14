<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Template variables in an included view file, scoped by the calling method.

$summary = $this->stats->get_summary( 30 );
$daily   = $this->stats->get_daily( 30 );

$alltime_spam      = $this->stats->get_total( 'spam_detected' );
$alltime_heuristic = $this->stats->get_total( 'heuristic_blocked' );
$alltime_ip        = $this->stats->get_total( 'ip_blocked' );
$alltime_blocked   = $alltime_spam + $alltime_heuristic + $alltime_ip;
?>

<div class="spamanvil-hero-banner">
	<div class="spamanvil-hero-number"><?php echo esc_html( number_format_i18n( $alltime_blocked ) ); ?></div>
	<div class="spamanvil-hero-label"><?php esc_html_e( 'Spam Comments Blocked', 'spamanvil' ); ?></div>
	<div class="spamanvil-hero-breakdown">
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
</div>

<h2><?php esc_html_e( 'Last 30 Days', 'spamanvil' ); ?></h2>

<div class="spamanvil-stats-grid">
	<div class="spamanvil-stat-card">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['comments_checked'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'Comments Checked', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card spamanvil-stat-danger">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['spam_detected'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'Spam Detected (LLM)', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card spamanvil-stat-success">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['ham_approved'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'Approved', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card spamanvil-stat-warning">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['heuristic_blocked'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'Heuristic Blocked', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['ip_blocked'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'IP Blocked', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['llm_calls'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'LLM API Calls', 'spamanvil' ); ?></div>
	</div>

	<div class="spamanvil-stat-card <?php echo $summary['llm_errors'] > 0 ? 'spamanvil-stat-danger' : ''; ?>">
		<div class="stat-number"><?php echo esc_html( number_format_i18n( $summary['llm_errors'] ) ); ?></div>
		<div class="stat-label"><?php esc_html_e( 'LLM Errors', 'spamanvil' ); ?></div>
	</div>
</div>

<?php
// Build contextual tips from stats data.
$tips = array();

$total_checked = $summary['comments_checked'];
$ip_blocking   = get_option( 'spamanvil_ip_blocking_enabled', '1' );
$has_fallback  = ! empty( get_option( 'spamanvil_fallback_provider', '' ) );
$has_provider  = ! empty( get_option( 'spamanvil_primary_provider', '' ) );

// High spam rate + IP blocking disabled.
if ( $total_checked > 0 && $summary['spam_detected'] > 0 ) {
	$spam_rate = $summary['spam_detected'] / $total_checked;
	if ( $spam_rate > 0.5 && '1' !== $ip_blocking ) {
		$tips[] = array(
			'type' => 'warning',
			'icon' => 'shield',
			'text' => __( 'Over half of your comments are spam. Enable IP Blocking in the IP Management tab to automatically block repeat offenders.', 'spamanvil' ),
		);
	}
}

// High API error rate.
if ( $summary['llm_calls'] > 0 && $summary['llm_errors'] > 0 ) {
	$error_rate = $summary['llm_errors'] / $summary['llm_calls'];
	if ( $error_rate > 0.1 ) {
		$tips[] = array(
			'type' => 'warning',
			'icon' => 'warning',
			'text' => __( 'Your LLM error rate is above 10%. Check your provider configuration in the Providers tab, or consider adding a fallback provider.', 'spamanvil' ),
		);
	}
}

// No fallback + errors.
if ( ! $has_fallback && $summary['llm_errors'] > 0 ) {
	$tips[] = array(
		'type' => 'info',
		'icon' => 'backup',
		'text' => __( 'You have no fallback provider configured. Adding one ensures comments are still analyzed if your primary provider is unavailable.', 'spamanvil' ),
	);
}

// 100+ comments checked + no review dismissed — gentle nudge.
if ( $this->stats->get_total( 'comments_checked' ) >= 100 && ! get_option( 'spamanvil_dismiss_review' ) ) {
	$tips[] = array(
		'type' => 'info',
		'icon' => 'star-filled',
		'text' => sprintf(
			/* translators: %s: link to review page */
			__( 'Enjoying SpamAnvil? A %s on WordPress.org helps other site owners discover it.', 'spamanvil' ),
			'<a href="https://wordpress.org/support/plugin/spamanvil/reviews/#new-post" target="_blank" rel="noopener noreferrer">' . esc_html__( '5-star review', 'spamanvil' ) . '</a>'
		),
	);
}

// Heuristic catching more than LLM — positive reinforcement.
if ( $summary['heuristic_blocked'] > 0 && $summary['heuristic_blocked'] > $summary['spam_detected'] ) {
	$tips[] = array(
		'type' => 'success',
		'icon' => 'yes-alt',
		'text' => __( 'Your heuristic rules are catching more spam than the LLM — that means obvious spam is being blocked instantly without API calls, saving you money.', 'spamanvil' ),
	);
}

// No activity — remind to configure.
if ( $total_checked === 0 && ! $has_provider ) {
	$tips[] = array(
		'type' => 'warning',
		'icon' => 'admin-generic',
		'text' => sprintf(
			/* translators: %s: link to providers tab */
			__( 'No comments have been analyzed yet. %s to start protecting your site.', 'spamanvil' ),
			'<a href="' . esc_url( admin_url( 'options-general.php?page=spamanvil&tab=providers' ) ) . '">' . esc_html__( 'Configure a provider', 'spamanvil' ) . '</a>'
		),
	);
}

if ( ! empty( $tips ) ) :
?>
<div class="spamanvil-tips-card">
	<h3><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Tips & Insights', 'spamanvil' ); ?></h3>
	<ul class="spamanvil-tips-list">
		<?php foreach ( $tips as $tip ) : ?>
			<li class="spamanvil-tip-<?php echo esc_attr( $tip['type'] ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $tip['icon'] ); ?>"></span>
				<?php echo wp_kses( $tip['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array() ) ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Daily Activity (Last 30 Days)', 'spamanvil' ); ?></h2>

<?php if ( ! empty( $daily ) ) : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Checked', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Spam', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Approved', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Heuristic', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'IP Blocked', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Errors', 'spamanvil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_reverse( $daily, true ) as $date => $data ) : ?>
				<tr>
					<td><?php echo esc_html( $date ); ?></td>
					<td><?php echo esc_html( $data['comments_checked'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $data['spam_detected'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $data['ham_approved'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $data['heuristic_blocked'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $data['ip_blocked'] ?? 0 ); ?></td>
					<td><?php echo esc_html( $data['llm_errors'] ?? 0 ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p><?php esc_html_e( 'No statistics available yet.', 'spamanvil' ); ?></p>
<?php endif; ?>
