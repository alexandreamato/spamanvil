<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Gemini extends SpamAnvil_Provider {

	public function get_name() {
		return 'Google Gemini';
	}

	protected function get_endpoint_url() {
		return 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $this->model ) . ':generateContent';
	}

	protected function get_headers() {
		return array(
			'Content-Type'   => 'application/json',
			'x-goog-api-key' => $this->api_key,
		);
	}

	protected function build_request_body( $system_prompt, $user_prompt ) {
		return array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $system_prompt ),
				),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $user_prompt ),
					),
				),
			),
			'generationConfig'  => array(
				'temperature'      => 0,
				'maxOutputTokens'  => 200,
				'responseMimeType' => 'application/json',
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

		if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error(
				'spamanvil_unexpected_format',
				__( 'Unexpected API response format', 'spamanvil' )
			);
		}

		return $data['candidates'][0]['content']['parts'][0]['text'];
	}
}
