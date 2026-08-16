<?php
/**
 * Recovery screen: comments the pre-1.16.0 prompt may have flagged by mistake.
 *
 * Rendered by SpamAnvil_Admin::render_recovery_screen() at
 * options-general.php?page=spamanvil&tab=recovery.
 *
 * @var array  $candidates Rows from SpamAnvil_Stats::find_probable_false_positives().
 * @var int    $restored   How many were restored by the previous request.
 * @var string $logs_url   Link to the Logs tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap spamanvil-wrap spamanvil-recovery">

	<h1><?php esc_html_e( 'Review comments that may have been wrongly flagged', 'spamanvil' ); ?></h1>

	<?php if ( $restored > 0 ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of comments restored */
					esc_html( _n( '%d comment restored and published.', '%d comments restored and published.', $restored, 'spamanvil' ) ),
					(int) $restored
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="spamanvil-recovery-intro">
		<p>
			<?php esc_html_e( 'Until version 1.16.0, the default AI prompt told the model that a comment written in a language other than the site language was "highly suspicious" and that generic praise was spam on its own. Both rules are gone, but comments flagged under them are still in your spam folder.', 'spamanvil' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Listed below are spam-flagged comments that carry no link and no author website, and whose score sits just above your threshold — the shape a wrongly flagged reader has, and the shape real spam almost never has. Read them, tick the genuine ones, and restore them.', 'spamanvil' ); ?>
		</p>
		<p class="spamanvil-recovery-deadline">
			<strong><?php esc_html_e( 'Worth doing sooner than later:', 'spamanvil' ); ?></strong>
			<?php esc_html_e( 'WordPress permanently deletes spam comments older than 30 days on its own, so anything recoverable stays recoverable only for a while.', 'spamanvil' ); ?>
		</p>
	</div>

	<?php if ( empty( $candidates ) ) : ?>

		<div class="notice notice-success inline">
			<p>
				<strong><?php esc_html_e( 'Nothing to review.', 'spamanvil' ); ?></strong>
				<?php esc_html_e( 'No spam-flagged comment on this site matches that pattern.', 'spamanvil' ); ?>
			</p>
		</div>
		<p><a href="<?php echo esc_url( $logs_url ); ?>" class="button"><?php esc_html_e( 'Back to the logs', 'spamanvil' ); ?></a></p>

	<?php else : ?>

		<form method="post">
			<?php wp_nonce_field( 'spamanvil_recovery' ); ?>

			<table class="widefat striped spamanvil-recovery-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="spamanvil-recovery-all" /></td>
						<th><?php esc_html_e( 'Comment', 'spamanvil' ); ?></th>
						<th><?php esc_html_e( 'Author', 'spamanvil' ); ?></th>
						<th><?php esc_html_e( 'Score', 'spamanvil' ); ?></th>
						<th><?php esc_html_e( 'Why it was flagged', 'spamanvil' ); ?></th>
						<th><?php esc_html_e( 'Date', 'spamanvil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $candidates as $row ) : ?>
						<tr>
							<th class="check-column">
								<input type="checkbox" name="spamanvil_restore[]" value="<?php echo esc_attr( $row->comment_ID ); ?>" />
							</th>
							<td class="spamanvil-recovery-content"><?php echo esc_html( wp_trim_words( $row->comment_content, 45 ) ); ?></td>
							<td><?php echo esc_html( $row->comment_author ); ?></td>
							<td><span class="spamanvil-score"><?php echo esc_html( (int) $row->score ); ?></span></td>
							<td class="spamanvil-recovery-reason"><?php echo esc_html( $row->reason ); ?></td>
							<td><?php echo esc_html( $row->comment_date ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" name="spamanvil_restore_comments" value="1" class="button button-primary">
					<?php esc_html_e( 'Restore selected comments', 'spamanvil' ); ?>
				</button>
				<a href="<?php echo esc_url( $logs_url ); ?>" class="button"><?php esc_html_e( 'Back to the logs', 'spamanvil' ); ?></a>
			</p>

			<p class="description">
				<?php esc_html_e( 'Restoring publishes the comment. If the commenter\'s IP was blocked after repeated flags, clear it on the IP Management tab as well.', 'spamanvil' ); ?>
			</p>
		</form>

	<?php endif; ?>

</div>
