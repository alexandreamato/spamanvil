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
			// Router chain: openrouter/free tries the free-model pool; if it fails,
			// openrouter/auto picks a suitable paid model. Both are OpenRouter-managed
			// routers, so this default never goes stale when individual models churn.
			'default_model' => 'openrouter/free, openrouter/auto',
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
			'default_model' => 'claude-sonnet-5',
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
		if ( ! empty( $overrides['api_key'] ) ) {
			$api_key = $overrides['api_key'];
		} else {
			$api_key = $this->resolve_api_key( $config, $provider_slug );
			if ( is_wp_error( $api_key ) ) {
				return $api_key; // Decryption failure — distinct, actionable error.
			}
		}

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

		// Use override model if provided, otherwise the first entry of the stored
		// model list (the field accepts a comma-separated fallback chain since 1.12.0).
		if ( ! empty( $overrides['model'] ) ) {
			$model = $overrides['model'];
		} else {
			$chain = $this->get_model_chain( $provider_slug );
			$model = ! empty( $chain ) ? $chain[0] : '';
		}

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

		$fallback2 = get_option( 'spamanvil_fallback2_provider', '' );

		if ( ! empty( $fallback2 ) ) {
			$provider = $this->create( $fallback2 );
			if ( ! is_wp_error( $provider ) ) {
				return $provider;
			}
		}

		return new WP_Error(
			'spamanvil_no_provider',
			__( 'No LLM provider is configured. Please configure a provider in the plugin settings.', 'spamanvil' )
		);
	}

	/**
	 * Get ordered list of configured provider slugs for the fallback chain.
	 *
	 * @return array Provider slugs (primary, fallback, fallback2) — only those that are set.
	 */
	public function get_provider_chain() {
		$slugs = array();
		$seen  = array();

		$keys = array( 'spamanvil_primary_provider', 'spamanvil_fallback_provider', 'spamanvil_fallback2_provider' );

		foreach ( $keys as $key ) {
			$slug = get_option( $key, '' );
			if ( ! empty( $slug ) && ! isset( $seen[ $slug ] ) ) {
				$slugs[]          = $slug;
				$seen[ $slug ]    = true;
			}
		}

		return $slugs;
	}

	/**
	 * Whether a provider in the configured chain has a stored key that can no
	 * longer be decrypted.
	 *
	 * True when a DB-stored (non wp-config) key is present but decrypt() returns ''
	 * — typically because AUTH_SALT rotated since the key was saved. Used to warn the
	 * admin explicitly instead of failing silently with provider='none'.
	 *
	 * Only the primary/fallback chain is checked: a stale key left behind on a
	 * provider that is no longer selected doesn't affect classification, and it
	 * shouldn't keep a sitewide error notice alive (the Providers tab still flags
	 * it inline via has_broken_stored_key()).
	 *
	 * @return bool
	 */
	public function has_undecryptable_key() {
		foreach ( $this->get_provider_chain() as $slug ) {
			if ( $this->has_broken_stored_key( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a specific provider has a DB-stored key that no longer decrypts.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public function has_broken_stored_key( $slug ) {
		if ( ! isset( self::$provider_configs[ $slug ] ) ) {
			return false;
		}

		$config = self::$provider_configs[ $slug ];

		// Keys defined in wp-config.php are plain constants — never encrypted.
		if ( defined( $config['constant_key'] ) ) {
			return false;
		}

		$encrypted = get_option( $config['option_key'], '' );

		return ! empty( $encrypted ) && '' === $this->encryptor->decrypt( $encrypted );
	}

	private function resolve_api_key( $config, $provider_slug = '' ) {
		// Check wp-config constant first.
		if ( defined( $config['constant_key'] ) ) {
			return constant( $config['constant_key'] );
		}

		// Fall back to encrypted DB value.
		$encrypted = get_option( $config['option_key'], '' );

		if ( empty( $encrypted ) ) {
			return ''; // Genuinely not configured.
		}

		$decrypted = $this->encryptor->decrypt( $encrypted );

		if ( '' === $decrypted ) {
			// A key IS stored but can't be decrypted. Name the provider and the likely
			// cause so the admin can act, instead of a generic one-size-fits-all message:
			// a malformed stored value points at a corrupted option; a well-formed value
			// that fails authentication points at rotated security keys (AUTH_SALT).
			$looks_malformed = ! (bool) base64_decode( preg_replace( '/^g:/', '', $encrypted ), true );
			$cause           = $looks_malformed
				? __( 'the stored value is corrupted', 'spamanvil' )
				: __( 'the site security keys (AUTH_SALT) likely changed since it was saved', 'spamanvil' );

			return new WP_Error(
				'spamanvil_key_decrypt_failed',
				sprintf(
					/* translators: 1: provider slug, 2: probable cause */
					__( 'The stored API key for "%1$s" could not be decrypted — %2$s. Re-enter the API key on the Providers tab, or define it in wp-config.php.', 'spamanvil' ),
					$provider_slug,
					$cause
				)
			);
		}

		return $decrypted;
	}

	/**
	 * Parse a stored model option into an ordered list of model ids.
	 *
	 * The model field accepts a comma- or newline-separated chain (1.12.0), e.g.
	 * "openai/gpt-oss-20b:free, meta-llama/llama-3.3-70b-instruct:free" — models are
	 * tried in order until one answers. Pure, unit-tested.
	 *
	 * @param string $raw Raw option value.
	 * @return array Ordered, de-duplicated list of model ids (possibly empty).
	 */
	public static function parse_model_list( $raw ) {
		$parts  = preg_split( '/[,\n]+/', (string) $raw );
		$models = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part && ! in_array( $part, $models, true ) ) {
				$models[] = $part;
			}
		}
		return $models;
	}

	/**
	 * Ordered model chain configured for a provider (stored list, or the default model).
	 *
	 * @param string $slug Provider slug.
	 * @return array Model ids in the order they should be tried.
	 */
	public function get_model_chain( $slug ) {
		if ( ! isset( self::$provider_configs[ $slug ] ) ) {
			return array();
		}

		$config = self::$provider_configs[ $slug ];
		$models = self::parse_model_list( get_option( $config['model_option'], '' ) );

		if ( empty( $models ) ) {
			// Defaults can be chains too (e.g. OpenRouter's "free, auto" routers).
			$models = self::parse_model_list( $config['default_model'] );
		}

		return $models;
	}

	/**
	 * Whether a WP_Error code denotes a *permanent* configuration problem — one that
	 * retrying can never fix (missing/undecryptable key, no provider or model set).
	 * Transient problems (network, rate limits, provider outages) are NOT listed here.
	 *
	 * Pure, unit-tested: drives the queue's pause behaviour, so a config error must
	 * never burn retry attempts or flood the logs once per item per cycle (1.12.0).
	 *
	 * @param string $code WP_Error code.
	 * @return bool
	 */
	public static function is_permanent_config_error_code( $code ) {
		return in_array(
			(string) $code,
			array(
				'spamanvil_no_api_key',
				'spamanvil_key_decrypt_failed',
				'spamanvil_no_model',
				'spamanvil_unknown_provider',
				'spamanvil_no_provider',
				'spamanvil_config_error',
			),
			true
		);
	}

	/**
	 * Fingerprint of the provider configuration the queue depends on.
	 *
	 * Changes whenever the chain, any chained provider's model list, or any stored
	 * key changes — used to auto-resume a paused queue and to gate the max_retries
	 * resurrection cycle (re-trying items only makes sense after the config moved).
	 *
	 * @return string
	 */
	public function get_config_hash() {
		$parts = array();

		foreach ( $this->get_provider_chain() as $slug ) {
			$parts[] = $slug;

			if ( ! isset( self::$provider_configs[ $slug ] ) ) {
				continue;
			}

			$config  = self::$provider_configs[ $slug ];
			$parts[] = (string) get_option( $config['model_option'], $config['default_model'] );
			// The raw encrypted value (not the decrypted key) is enough: re-saving a
			// key re-encrypts with a fresh IV, so the value — and the hash — changes.
			$parts[] = defined( $config['constant_key'] ) ? 'const' : md5( (string) get_option( $config['option_key'], '' ) );
		}

		return md5( implode( '|', $parts ) );
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

	/**
	 * Whether a provider WP_Error indicates the *model* is unavailable (deprecated,
	 * removed, no endpoints) as opposed to auth, rate-limit or network problems.
	 * Free models — especially on OpenRouter — churn often, so this is common.
	 *
	 * @param mixed $error Result from a provider call.
	 * @return bool
	 */
	public function is_model_unavailable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$msg = strtolower( $error->get_error_message() );

		// Deliberately does NOT match auth (401/403), rate-limit (429) or credit errors.
		return (bool) preg_match(
			'/http 404|\b404\b|no endpoints|not a valid model|model not found|model does not exist|unknown model|no such model|invalid model|model[^.]{0,30}unavailable/i',
			$msg
		);
	}

	/**
	 * Pick the first free model from a model list, excluding a given id.
	 *
	 * @param array  $models  Normalized list from parse_models_response().
	 * @param string $exclude Model id to skip (the one that just failed).
	 * @return string A free model id, or '' if none.
	 */
	public function pick_free_model( array $models, $exclude = '' ) {
		foreach ( $models as $m ) {
			if ( ! empty( $m['free'] ) && ! empty( $m['id'] ) && $m['id'] !== $exclude ) {
				return (string) $m['id'];
			}
		}
		return '';
	}

	/**
	 * Fetch the provider's live model list and return a free alternative to $exclude_model.
	 *
	 * @param string $slug          Provider slug.
	 * @param string $exclude_model The model that failed.
	 * @return string A free model id, or '' if none is available.
	 */
	public function find_free_alternative( $slug, $exclude_model ) {
		$provider = $this->create( $slug );

		if ( is_wp_error( $provider ) || ! method_exists( $provider, 'list_models' ) ) {
			return '';
		}

		$models = $provider->list_models();
		if ( is_wp_error( $models ) ) {
			return '';
		}

		return $this->pick_free_model( $models, $exclude_model );
	}
}
