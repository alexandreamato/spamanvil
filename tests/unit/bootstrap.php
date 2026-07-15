<?php
/**
 * Bootstrap for the fast "unit" test suite.
 *
 * These tests exercise the plugin's pure-logic classes (Encryptor, Heuristics)
 * WITHOUT a WordPress install or database. The handful of WordPress functions
 * those classes call are stubbed below. Anything that touches $wpdb (the queue
 * state machine, timezone/retry logic) lives in tests/integration/ and runs
 * against a real MySQL + WordPress test suite in CI.
 */

// The plugin classes guard with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mutable test state the stubs read from.
$GLOBALS['__spamanvil_test_options'] = array();
$GLOBALS['__spamanvil_test_locale']  = 'en_US';
$GLOBALS['__spamanvil_test_salt']    = 'unit-test-salt';

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__spamanvil_test_options'] )
			? $GLOBALS['__spamanvil_test_options'][ $key ]
			: $default;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return $GLOBALS['__spamanvil_test_locale'];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	// The classes under test only read the (unfiltered) value back.
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_extract_urls' ) ) {
	// Minimal stand-in for WordPress' URL extractor (protocol URLs only;
	// Heuristics adds its own www.* regex on top).
	function wp_extract_urls( $content ) {
		preg_match_all( '#https?://[^\s"\'<>()]+#i', (string) $content, $matches );
		return array_values( array_unique( $matches[0] ) );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return $GLOBALS['__spamanvil_test_salt'] . '-' . $scheme;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = preg_replace( '/<[^>]*>/', '', (string) $str );
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
		return trim( preg_replace( '/\s{2,}/', ' ', $str ) );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Load the classes under test directly (no autoloader needed).
$plugin_includes = dirname( __DIR__, 2 ) . '/spamanvil/includes';
require_once $plugin_includes . '/class-spamanvil-encryptor.php';
require_once $plugin_includes . '/class-spamanvil-heuristics.php';
require_once $plugin_includes . '/providers/class-spamanvil-provider.php';
