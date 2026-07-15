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
		// Run a realistic spam-classification round-trip so the test exercises the SAME
		// path as production (HTTP + parsing). A model that returns HTTP 200 but output
		// this plugin can't parse (e.g. a reasoning model emitting <think> blocks) will
		// correctly fail here instead of showing a false "green".
		$system = 'You are a spam detection system. Respond with ONLY a JSON object of the form {"score": <integer 0-100>, "reason": "<short text>"}. Output no other text.';
		$user   = "Evaluate this blog comment and respond with the JSON only:\n<comment_data>\nGreat article! Visit my site http://cheap-deals.example for amazing offers.\n</comment_data>";

		$result = $this->analyze( $system, $user );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'response_ms'  => isset( $result['processing_time_ms'] ) ? $result['processing_time_ms'] : 0,
			'model'        => $this->model,
			'sample_score' => $result['score'],
		);
	}

	protected function make_request( $url, $headers, $body ) {
		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		);

		return wp_safe_remote_post( $url, $args );
	}

	protected function validate_response( $content ) {
		$content = trim( (string) $content );

		// Reasoning models (Qwen3, DeepSeek-R1, etc.) prepend a <think>…</think>
		// block; remove it so it doesn't swallow the JSON.
		$content = preg_replace( '#<think\b[^>]*>.*?</think>#is', '', $content );

		// Strip markdown code fences if the model wrapped the JSON in a block.
		$content = trim( $content );
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```\s*$/', '', $content );
		$content = trim( $content );

		// Many models add prose around the JSON. Extract the first balanced {…}
		// object instead of demanding the whole response be pure JSON.
		$json = $this->extract_json_object( $content );
		$data = ( null !== $json ) ? json_decode( $json, true ) : null;

		// Last resort: pull a bare "score": N out of the raw text.
		if ( ! is_array( $data ) || ! isset( $data['score'] ) ) {
			if ( preg_match( '/["\']?score["\']?\s*[:=]\s*(\d{1,3})/i', $content, $m ) ) {
				$data = array(
					'score'  => (int) $m[1],
					'reason' => $this->extract_reason_fallback( $content ),
				);
			}
		}

		if ( ! is_array( $data ) || ! isset( $data['score'] ) ) {
			return new WP_Error(
				'spamanvil_invalid_json',
				sprintf(
					/* translators: %s: raw response content */
					__( 'Could not parse a score from the response: %s', 'spamanvil' ),
					substr( $content, 0, 300 )
				)
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
			'reason' => isset( $data['reason'] ) ? sanitize_text_field( (string) $data['reason'] ) : '',
		);
	}

	/**
	 * Extract the first balanced JSON object from arbitrary text.
	 *
	 * Scans from the first "{" tracking brace depth and string state (so braces or
	 * quotes inside string values don't fool it). Byte-based scanning is safe because
	 * the delimiters {, }, ", \ are all ASCII and never collide with UTF-8 payload bytes.
	 *
	 * @param string $text Text possibly containing a JSON object amid prose.
	 * @return string|null The "{…}" substring, or null if none is balanced.
	 */
	protected function extract_json_object( $text ) {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}

		$len       = strlen( $text );
		$depth     = 0;
		$in_string = false;
		$escaped   = false;

		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $ch ) {
					$escaped = true;
				} elseif ( '"' === $ch ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $ch ) {
				$in_string = true;
			} elseif ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return null; // Unbalanced / truncated.
	}

	/**
	 * Best-effort extraction of a "reason" string when the JSON itself is malformed.
	 *
	 * @param string $text Raw response text.
	 * @return string Reason, or '' if none found.
	 */
	protected function extract_reason_fallback( $text ) {
		if ( preg_match( '/["\']?reason["\']?\s*[:=]\s*["\']([^"\']{0,300})/i', $text, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		return '';
	}
}
