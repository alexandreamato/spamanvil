<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Anthropic extends SpamAnvil_Provider {

	public function get_name() {
		return 'Anthropic Claude';
	}

	protected function get_endpoint_url() {
		return 'https://api.anthropic.com/v1/messages';
	}

	protected function get_headers() {
		return array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
		);
	}

	protected function build_request_body( $system_prompt, $user_prompt ) {
		return array(
			'model'       => $this->model,
			'max_tokens'  => 200,
			'temperature' => 0,
			'system'      => $system_prompt,
			'messages'    => array(
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

		if ( ! isset( $data['content'][0]['text'] ) ) {
			return new WP_Error(
				'spamanvil_unexpected_format',
				__( 'Unexpected API response format', 'spamanvil' )
			);
		}

		return $data['content'][0]['text'];
	}
}
