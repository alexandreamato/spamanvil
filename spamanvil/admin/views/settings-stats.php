<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Template variables in an included view file, scoped by the calling method.

$summary = $this->stats->get_summary( 30 );
$daily   = $this->stats->get_daily( 30 );
?>

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
