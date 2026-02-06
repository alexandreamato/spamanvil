<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

settings_errors( 'spamanvil' );

$ip_enabled   = get_option( 'spamanvil_ip_blocking_enabled', '1' );
$ip_threshold = (int) get_option( 'spamanvil_ip_block_threshold', 3 );
$page_num     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$blocked_list = $this->ip_manager->get_blocked_list( $page_num );
?>

<form method="post" action="">
	<?php wp_nonce_field( 'spamanvil_ip' ); ?>
	<input type="hidden" name="spamanvil_tab" value="ip">
	<input type="hidden" name="spamanvil_save_settings" value="1">

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'IP Blocking', 'spamanvil' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spamanvil_ip_blocking_enabled" value="1" <?php checked( $ip_enabled, '1' ); ?>>
					<?php esc_html_e( 'Enable automatic IP blocking for repeat spam offenders', 'spamanvil' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Block Threshold', 'spamanvil' ); ?></th>
			<td>
				<input type="number" name="spamanvil_ip_block_threshold" min="1" max="20"
					   value="<?php echo esc_attr( $ip_threshold ); ?>" class="small-text">
				<p class="description">
					<?php esc_html_e( 'Number of spam attempts before blocking an IP. Blocks escalate: 24h, 48h, 96h, etc.', 'spamanvil' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>

<hr>

<h2><?php esc_html_e( 'Blocked IPs', 'spamanvil' ); ?></h2>

<?php if ( empty( $blocked_list['items'] ) ) : ?>
	<p><?php esc_html_e( 'No IPs are currently tracked.', 'spamanvil' ); ?></p>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'IP (Masked)', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Attempts', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Blocked Until', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Escalation Level', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Last Updated', 'spamanvil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'spamanvil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $blocked_list['items'] as $item ) :
				$is_active = $item->blocked_until && strtotime( $item->blocked_until ) > time();
			?>
				<tr>
					<td><?php echo esc_html( $item->ip_display ); ?></td>
					<td><?php echo esc_html( $item->attempts ); ?></td>
					<td>
						<?php if ( $item->blocked_until ) : ?>
							<span class="<?php echo $is_active ? 'spamanvil-active-block' : ''; ?>">
								<?php echo esc_html( $item->blocked_until ); ?>
							</span>
							<?php if ( $is_active ) : ?>
								<span class="spamanvil-badge spamanvil-badge-red"><?php esc_html_e( 'Active', 'spamanvil' ); ?></span>
							<?php else : ?>
								<span class="spamanvil-badge"><?php esc_html_e( 'Expired', 'spamanvil' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $item->escalation_level ); ?></td>
					<td><?php echo esc_html( $item->updated_at ); ?></td>
					<td>
						<button type="button"
								class="button button-small spamanvil-unblock-btn"
								data-id="<?php echo esc_attr( $item->id ); ?>">
							<?php esc_html_e( 'Remove', 'spamanvil' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $blocked_list['pages'] > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page_num,
						'total'   => $blocked_list['pages'],
					) )
				);
				?>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>
