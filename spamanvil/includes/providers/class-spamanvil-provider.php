<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SpamAnvil_Provider {

	protected $api_key;
	protected $model;
	protected $api_url;

	public function __construct( $api_key, $model, $api_url = '' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
		$this->api_url = $api_url;
	}

	abstract public function get_name();

	abstract protected function build_request_body( $system_prompt, $user_prompt );

	abstract protected function parse_response_body( $body );

	abstract protected function get_endpoint_url();

	abstract protected function get_headers();

	public function analyze( $system_prompt, $user_prompt ) {
		$start_time = microtime( true );

		$url     = $this->get_endpoint_url();
		$headers = $this->get_headers();
		$body    = $this->build_request_body( $system_prompt, $user_prompt );

		$response = $this->make_request( $url, $headers, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$body_text = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'spamanvil_api_error',
				sprintf(
					/* translators: 1: HTTP code, 2: response body */
					__( 'API returned HTTP %1$d: %2$s', 'spamanvil' ),
					$http_code,
					substr( $body_text, 0, 500 )
				)
			);
		}

		$body_text = wp_remote_retrieve_body( $response );
		$content   = $this->parse_response_body( $body_text );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$result = $this->validate_response( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$elapsed_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

		$result['provider']            = $this->get_name();
		$result['model']               = $this->model;
		$result['processing_time_ms']  = $elapsed_ms;

		return $result;
	}

	public function test_connection() {
		$system = 'You are a test assistant. Respond with exactly: {"status":"ok"}';
		$user   = 'Test connection. Respond with: {"status":"ok"}';

		$start = microtime( true );

		$url     = $this->get_endpoint_url();
		$headers = $this->get_headers();
		$body    = $this->build_request_body( $system, $user );

		$response = $this->make_request( $url, $headers, $body );

		$elapsed_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$body_text   = wp_remote_retrieve_body( $response );
			$error_detail = '';

			// Try to extract a readable error message from the API response.
			$body_data = json_decode( $body_text, true );
			if ( is_array( $body_data ) && isset( $body_data['error'] ) ) {
				$error_detail = is_array( $body_data['error'] )
					? ( $body_data['error']['message'] ?? '' )
					: $body_data['error'];
			}

			if ( ! empty( $error_detail ) ) {
				$message = sprintf(
					/* translators: 1: HTTP code, 2: error detail */
					__( 'HTTP %1$d - %2$s', 'spamanvil' ),
					$http_code,
					$error_detail
				);
			} else {
				$message = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Connection test failed with HTTP %d', 'spamanvil' ),
					$http_code
				);
			}

			return new WP_Error( 'spamanvil_test_failed', $message );
		}

		return array(
			'success'     => true,
			'response_ms' => $elapsed_ms,
			'model'       => $this->model,
		);
	}

	protected function make_request( $url, $headers, $body ) {
		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		);

		return wp_safe_remote_post( $url, $args );
	}

	protected function validate_response( $content ) {
		// Strip markdown code block wrappers if present.
		$content = trim( $content );
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );
		$content = trim( $content );

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'spamanvil_invalid_json',
				sprintf(
					/* translators: %s: raw response content */
					__( 'Invalid JSON response: %s', 'spamanvil' ),
					substr( $content, 0, 300 )
				)
			);
		}

		if ( ! isset( $data['score'] ) ) {
			return new WP_Error(
				'spamanvil_missing_score',
				__( 'Response missing "score" field', 'spamanvil' )
			);
		}

		$score = (int) $data['score'];

		if ( $score < 0 || $score > 100 ) {
			return new WP_Error(
				'spamanvil_invalid_score',
				sprintf(
					/* translators: %d: the invalid score */
					__( 'Score %d is out of range (0-100)', 'spamanvil' ),
					$score
				)
			);
		}

		return array(
			'score'  => $score,
			'reason' => isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : '',
		);
	}
}
