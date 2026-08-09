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
		// No `temperature`: current Claude models (Opus 4.7+, Opus 5, Sonnet 5) removed
		// the sampling parameters — sending temperature returns HTTP 400.
		$body = array(
			'model'      => $this->model,
			'max_tokens' => 1024,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		// Sonnet 5 / Opus 5 run adaptive *thinking* by default when the field is
		// omitted — thinking tokens would eat the max_tokens budget and prepend a
		// thinking block before the JSON verdict. Classification wants the plain
		// answer, so disable it explicitly on those models. Fable/Mythos-class models
		// reject an explicit "disabled" (thinking is always on there), and older
		// models don't need the field — both omit it.
		if ( preg_match( '/^claude-(sonnet|opus)-5/', $this->model ) ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		}

		return $body;
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

		// Safety classifiers on newer models can decline a request: HTTP 200 with
		// stop_reason "refusal" and empty/partial content. Surface it as an error so
		// the chain falls through to the next model/provider instead of mis-parsing.
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error(
				'spamanvil_refusal',
				__( 'The model declined to evaluate this content (refusal stop reason)', 'spamanvil' )
			);
		}

		// The first content block is not necessarily text (e.g. a thinking block on
		// models with thinking enabled) — find the first text block instead of
		// hard-coding content[0].
		$text = self::extract_text_block( $data );

		if ( '' === $text ) {
			return new WP_Error(
				'spamanvil_unexpected_format',
				__( 'Unexpected API response format', 'spamanvil' )
			);
		}

		return $text;
	}

	/**
	 * First text block from a Messages API response. Pure and static (unit-tested).
	 *
	 * @param array $data Decoded response body.
	 * @return string Text content, or '' when no text block exists.
	 */
	public static function extract_text_block( $data ) {
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return '';
		}

		foreach ( $data['content'] as $block ) {
			if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				return (string) $block['text'];
			}
		}

		return '';
	}
}
