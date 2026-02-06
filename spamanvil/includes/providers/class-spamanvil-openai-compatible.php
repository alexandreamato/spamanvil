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
			'max_tokens'  => 200,
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
}
