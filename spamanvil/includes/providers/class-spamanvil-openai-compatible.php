<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_OpenAI_Compatible extends SpamAnvil_Provider {

	protected $provider_slug;

	private static $endpoints = array(
		'openai'      => 'https://api.openai.com/v1/chat/completions',
		'openrouter'  => 'https://openrouter.ai/api/v1/chat/completions',
		'featherless' => 'https://api.featherless.ai/v1/chat/completions',
	);

	public function __construct( $api_key, $model, $api_url = '', $provider_slug = 'openai' ) {
		parent::__construct( $api_key, $model, $api_url );
		$this->provider_slug = $provider_slug;
	}

	public function get_name() {
		$names = array(
			'openai'      => 'OpenAI',
			'openrouter'  => 'OpenRouter',
			'featherless' => 'Featherless.ai',
			'generic'     => 'Generic OpenAI-Compatible',
		);
		return isset( $names[ $this->provider_slug ] ) ? $names[ $this->provider_slug ] : $this->provider_slug;
	}

	protected function get_endpoint_url() {
		if ( 'generic' === $this->provider_slug && ! empty( $this->api_url ) ) {
			return esc_url_raw( $this->api_url );
		}

		return isset( self::$endpoints[ $this->provider_slug ] )
			? self::$endpoints[ $this->provider_slug ]
			: '';
	}

	protected function get_headers() {
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
		);

		if ( 'openrouter' === $this->provider_slug ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'SpamAnvil';
		}

		return $headers;
	}

	protected function build_request_body( $system_prompt, $user_prompt ) {
		return array(
			'model'       => $this->model,
			'temperature' => 0,
			'max_tokens'  => 400,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);
	}

	protected function parse_response_body( $body ) {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new WP_Error(
				'spamanvil_parse_error',
				__( 'Failed to parse API response JSON', 'spamanvil' )
			);
		}

		if ( isset( $data['error'] ) ) {
			$msg = is_array( $data['error'] )
				? ( $data['error']['message'] ?? wp_json_encode( $data['error'] ) )
				: $data['error'];
			return new WP_Error( 'spamanvil_api_error', $msg );
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'spamanvil_unexpected_format',
				__( 'Unexpected API response format', 'spamanvil' )
			);
		}

		return $data['choices'][0]['message']['content'];
	}

	/**
	 * Fetch the provider's available models for the settings-page picker.
	 *
	 * @return array|WP_Error List of models (each: id, name, and optionally context/free),
	 *                        or WP_Error on failure.
	 */
	public function list_models() {
		$url = $this->get_models_url();

		if ( '' === $url ) {
			return new WP_Error(
				'spamanvil_no_models_endpoint',
				__( 'This provider does not support listing models.', 'spamanvil' )
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'headers' => $this->get_headers(),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'spamanvil_models_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Model list request failed (HTTP %d). Check the API key.', 'spamanvil' ),
					$code
				)
			);
		}

		return $this->parse_models_response( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Resolve the /models endpoint for this provider.
	 *
	 * @return string URL, or '' if unknown.
	 */
	protected function get_models_url() {
		$map = array(
			'openai'      => 'https://api.openai.com/v1/models',
			'openrouter'  => 'https://openrouter.ai/api/v1/models',
			'featherless' => 'https://api.featherless.ai/v1/models',
		);

		if ( isset( $map[ $this->provider_slug ] ) ) {
			return $map[ $this->provider_slug ];
		}

		// Generic: derive from the configured chat URL (…/chat/completions → …/models).
		if ( 'generic' === $this->provider_slug && ! empty( $this->api_url ) ) {
			return preg_replace( '#/chat/completions/?$#', '/models', $this->api_url );
		}

		return '';
	}

	/**
	 * Parse a /models JSON body into a normalized, sorted list.
	 *
	 * Pure (no I/O) so it can be unit tested. Handles the OpenAI-style {"data":[{id}]}
	 * shape and OpenRouter's richer entries (name, context_length, pricing → free flag).
	 *
	 * @param string $body Raw JSON response body.
	 * @return array|WP_Error Normalized model list, or WP_Error on an unexpected shape.
	 */
	public function parse_models_response( $body ) {
		$data = json_decode( (string) $body, true );

		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error(
				'spamanvil_models_parse',
				__( 'Unexpected model list format from the provider.', 'spamanvil' )
			);
		}

		$models = array();

		foreach ( $data['data'] as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) ) {
				continue;
			}

			$id    = (string) $m['id'];
			$entry = array(
				'id'   => $id,
				'name' => isset( $m['name'] ) && '' !== $m['name'] ? (string) $m['name'] : $id,
			);

			if ( isset( $m['context_length'] ) ) {
				$entry['context'] = (int) $m['context_length'];
			}

			if ( isset( $m['pricing'] ) && is_array( $m['pricing'] ) ) {
				$prompt     = isset( $m['pricing']['prompt'] ) ? (float) $m['pricing']['prompt'] : null;
				$completion = isset( $m['pricing']['completion'] ) ? (float) $m['pricing']['completion'] : null;
				$entry['free'] = ( 0.0 === $prompt && 0.0 === $completion );
			}

			$models[] = $entry;
		}

		// Free models first, then alphabetical by id.
		usort(
			$models,
			function ( $a, $b ) {
				$af = ! empty( $a['free'] );
				$bf = ! empty( $b['free'] );
				if ( $af !== $bf ) {
					return $af ? -1 : 1;
				}
				return strcasecmp( $a['id'], $b['id'] );
			}
		);

		return $models;
	}
}
