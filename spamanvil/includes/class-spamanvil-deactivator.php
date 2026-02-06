<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'spamanvil_process_queue' );
		wp_clear_scheduled_hook( 'spamanvil_cleanup_logs' );
	}
}
