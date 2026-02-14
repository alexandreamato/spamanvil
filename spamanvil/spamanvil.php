<?php
/**
 * Plugin Name:       SpamAnvil
 * Plugin URI:        https://software.amato.com.br/spamanvil-antispam-plugin-for-wordpress/
 * Description:       Blocks comment spam using AI/LLM services with support for multiple providers, async processing, and intelligent heuristics.
 * Version:           1.1.9
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Alexandre Amato
 * Author URI:        https://amato.com.br
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spamanvil
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPAMANVIL_VERSION', '1.1.9' );
define( 'SPAMANVIL_DB_VERSION', '1.0.0' );
define( 'SPAMANVIL_PLUGIN_FILE', __FILE__ );
define( 'SPAMANVIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPAMANVIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPAMANVIL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'SpamAnvil';

	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$class_file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	$paths = array(
		SPAMANVIL_PLUGIN_DIR . 'includes/' . $class_file,
		SPAMANVIL_PLUGIN_DIR . 'includes/providers/' . $class_file,
		SPAMANVIL_PLUGIN_DIR . 'admin/' . $class_file,
	);

	foreach ( $paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

/**
 * Plugin activation.
 */
function spamanvil_activate() {
	SpamAnvil_Activator::activate();
	set_transient( 'spamanvil_activation_redirect', true, 30 );
}
register_activation_hook( __FILE__, 'spamanvil_activate' );

/**
 * Plugin deactivation.
 */
function spamanvil_deactivate() {
	SpamAnvil_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'spamanvil_deactivate' );

/**
 * Initialize the plugin.
 */
function spamanvil_init() {
	$plugin = SpamAnvil::get_instance();
	$plugin->init();
}
add_action( 'plugins_loaded', 'spamanvil_init' );
