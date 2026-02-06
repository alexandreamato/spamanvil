<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'spamanvil_queue',
	$wpdb->prefix . 'spamanvil_blocked_ips',
	$wpdb->prefix . 'spamanvil_stats',
	$wpdb->prefix . 'spamanvil_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all plugin options.
$options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		'spamanvil_%'
	)
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'spamanvil_process_queue' );
wp_clear_scheduled_hook( 'spamanvil_cleanup_logs' );
