<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always clear scheduled hooks (safe, no data loss).
wp_clear_scheduled_hook( 'spamanvil_process_queue' );
wp_clear_scheduled_hook( 'spamanvil_cleanup_logs' );

// Only delete data if the user opted in.
if ( '1' !== get_option( 'spamanvil_delete_data', '0' ) ) {
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Uninstall must drop custom plugin tables and clean up options directly.
// Variables are local to this uninstall script, not true globals.

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'spamanvil_queue',
	$wpdb->prefix . 'spamanvil_blocked_ips',
	$wpdb->prefix . 'spamanvil_stats',
	$wpdb->prefix . 'spamanvil_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
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
