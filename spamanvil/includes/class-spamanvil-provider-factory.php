<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Provider_Factory {

	private $encryptor;

	public function __construct( SpamAnvil_Encryptor $encryptor ) {
		$this->encryptor = $encryptor;
	}

	private static $provider_configs = array(
		'openai'      => array(
			'class'         => 'SpamAnvil_OpenAI_Compatible',
			'constant_key'  => 'SPAMANVIL_OPENAI_API_KEY',
			'option_key'    => 'spamanvil_openai_api_key',
			'model_option'  => 'spamanvil_openai_model',
			'default_model' => 'gpt-4o-mini',
		),
		'openrouter'  => array(
			'class'         => 'SpamAnvil_OpenAI_Compatible',
			'constant_key'  => 'SPAMANVIL_OPENROUTER_API_KEY',
			'option_key'    => 'spamanvil_openrouter_api_key',
			'model_option'  => 'spamanvil_openrouter_model',
			'default_model' => 'meta-llama/llama-3.3-70b-instruct:free',
		),
		'featherless' => array(
			'class'         => 'SpamAnvil_OpenAI_Compatible',
			'constant_key'  => 'SPAMANVIL_FEATHERLESS_API_KEY',
			'option_key'    => 'spamanvil_featherless_api_key',
			'model_option'  => 'spamanvil_featherless_model',
			'default_model' => 'meta-llama/Meta-Llama-3.1-8B-Instruct',
		),
		'anthropic'   => array(
			'class'         => 'SpamAnvil_Anthropic',
			'constant_key'  => 'SPAMANVIL_ANTHROPIC_API_KEY',
			'option_key'    => 'spamanvil_anthropic_api_key',
			'model_option'  => 'spamanvil_anthropic_model',
			'default_model' => 'claude-sonnet-4-5-20250929',
		),
		'gemini'      => array(
			'class'         => 'SpamAnvil_Gemini',
			'constant_key'  => 'SPAMANVIL_GEMINI_API_KEY',
			'option_key'    => 'spamanvil_gemini_api_key',
			'model_option'  => 'spamanvil_gemini_model',
			'default_model' => 'gemini-2.0-flash',
		),
		'generic'     => array(
			'class'         => 'SpamAnvil_OpenAI_Compatible',
			'constant_key'  => 'SPAMANVIL_GENERIC_API_KEY',
			'option_key'    => 'spamanvil_generic_api_key',
			'model_option'  => 'spamanvil_generic_model',
			'url_option'    => 'spamanvil_generic_api_url',
			'default_model' => '',
		),
	);

	/**
	 * Create a provider instance.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param array  $overrides     Optional overrides: api_key, model, api_url.
	 * @return SpamAnvil_Provider|WP_Error
	 */
	public function create( $provider_slug, $overrides = array() ) {
		if ( ! isset( self::$provider_configs[ $provider_slug ] ) ) {
			return new WP_Error(
				'spamanvil_unknown_provider',
				sprintf(
					/* translators: %s: provider slug */
					__( 'Unknown provider: %s', 'spamanvil' ),
					$provider_slug
				)
			);
		}

		$config = self::$provider_configs[ $provider_slug ];

		// Use override API key if provided, otherwise resolve from constants/DB.
		$api_key = ! empty( $overrides['api_key'] ) ? $overrides['api_key'] : $this->resolve_api_key( $config );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'spamanvil_no_api_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'No API key configured for %s', 'spamanvil' ),
					$provider_slug
				)
			);
		}

		// Use override model if provided, otherwise read from DB/default.
		$model = ! empty( $overrides['model'] ) ? $overrides['model'] : get_option( $config['model_option'], $config['default_model'] );

		if ( empty( $model ) ) {
			return new WP_Error(
				'spamanvil_no_model',
				sprintf(
					/* translators: %s: provider name */
					__( 'No model configured for %s', 'spamanvil' ),
					$provider_slug
				)
			);
		}

		// Use override URL if provided, otherwise read from DB.
		$api_url = ! empty( $overrides['api_url'] ) ? $overrides['api_url'] : '';
		if ( empty( $api_url ) && isset( $config['url_option'] ) ) {
			$api_url = get_option( $config['url_option'], '' );
		}

		$class = $config['class'];

		if ( 'SpamAnvil_OpenAI_Compatible' === $class ) {
			return new $class( $api_key, $model, $api_url, $provider_slug );
		}

		return new $class( $api_key, $model, $api_url );
	}

	public function create_with_fallback() {
		$primary = get_option( 'spamanvil_primary_provider', '' );

		if ( ! empty( $primary ) ) {
			$provider = $this->create( $primary );
			if ( ! is_wp_error( $provider ) ) {
				return $provider;
			}
		}

		$fallback = get_option( 'spamanvil_fallback_provider', '' );

		if ( ! empty( $fallback ) ) {
			$provider = $this->create( $fallback );
			if ( ! is_wp_error( $provider ) ) {
				return $provider;
			}
		}

		return new WP_Error(
			'spamanvil_no_provider',
			__( 'No LLM provider is configured. Please configure a provider in the plugin settings.', 'spamanvil' )
		);
	}

	private function resolve_api_key( $config ) {
		// Check wp-config constant first.
		if ( defined( $config['constant_key'] ) ) {
			return constant( $config['constant_key'] );
		}

		// Fall back to encrypted DB value.
		$encrypted = get_option( $config['option_key'], '' );

		if ( ! empty( $encrypted ) ) {
			return $this->encryptor->decrypt( $encrypted );
		}

		return '';
	}

	public static function get_available_providers() {
		return array(
			'openai'      => __( 'OpenAI', 'spamanvil' ),
			'openrouter'  => __( 'OpenRouter', 'spamanvil' ),
			'featherless' => __( 'Featherless.ai', 'spamanvil' ),
			'anthropic'   => __( 'Anthropic Claude', 'spamanvil' ),
			'gemini'      => __( 'Google Gemini', 'spamanvil' ),
			'generic'     => __( 'Generic OpenAI-Compatible', 'spamanvil' ),
		);
	}

	public static function get_provider_config( $slug ) {
		return isset( self::$provider_configs[ $slug ] ) ? self::$provider_configs[ $slug ] : null;
	}
}
