<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Template variables in an included view file, scoped by the calling method.

$page_num = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
$logs     = $this->stats->get_logs( $page_num, 25 );
?>

<h2><?php esc_html_e( 'Evaluation Logs', 'spamanvil' ); ?></h2>

<?php if ( empty( $logs['items'] ) ) : ?>
	<p><?php esc_html_e( 'No evaluation logs yet.', 'spamanvil' ); ?></p>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th class="column-id"><?php esc_html_e( 'ID', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Comment', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Author', 'spamanvil' ); ?></th>
				<th class="column-score"><?php esc_html_e( 'LLM Score', 'spamanvil' ); ?></th>
				<th class="column-score"><?php esc_html_e( 'Heuristic', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'spamanvil' ); ?></th>
				<th class="column-time"><?php esc_html_e( 'Time (ms)', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Date', 'spamanvil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $logs['items'] as $log ) :
				$score_class = '';
				if ( null !== $log->score ) {
					if ( $log->score >= 70 ) {
						$score_class = 'spamanvil-score-high';
					} elseif ( $log->score >= 40 ) {
						$score_class = 'spamanvil-score-medium';
					} else {
						$score_class = 'spamanvil-score-low';
					}
				}
			?>
				<tr>
					<td><?php echo esc_html( $log->comment_id ); ?></td>
					<td>
						<?php
						if ( ! empty( $log->comment_content ) ) {
							echo esc_html( wp_trim_words( $log->comment_content, 10, '...' ) );
						} else {
							esc_html_e( '[deleted]', 'spamanvil' );
						}
						?>
					</td>
					<td><?php echo esc_html( $log->comment_author ?? '' ); ?></td>
					<td>
						<?php if ( null !== $log->score ) : ?>
							<span class="spamanvil-score <?php echo esc_attr( $score_class ); ?>">
								<?php echo esc_html( $log->score ); ?>
							</span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php echo null !== $log->heuristic_score ? esc_html( $log->heuristic_score ) : '&mdash;'; ?>
					</td>
					<td>
						<?php echo esc_html( $log->provider ?? '' ); ?>
						<?php if ( $log->model ) : ?>
							<br><small><?php echo esc_html( $log->model ); ?></small>
						<?php endif; ?>
					</td>
					<td class="column-reason" title="<?php echo esc_attr( $log->reason ?? '' ); ?>"><?php echo esc_html( $log->reason ?? '' ); ?></td>
					<td><?php echo null !== $log->processing_time_ms ? esc_html( number_format_i18n( $log->processing_time_ms ) ) : '&mdash;'; ?></td>
					<td><?php echo esc_html( $log->created_at ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $logs['pages'] > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page_num,
						'total'   => $logs['pages'],
					) )
				);
				?>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>
