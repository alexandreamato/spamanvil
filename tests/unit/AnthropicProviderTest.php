<?php
/**
 * Unit tests for the Anthropic provider's request/response handling (1.12.0):
 * no sampling params (removed on current Claude models — HTTP 400), thinking
 * disabled where adaptive thinking is on by default, content-array parsing
 * (first text block, not content[0]), and the refusal stop reason.
 */

use PHPUnit\Framework\TestCase;

/**
 * Exposes the protected request/response methods for testing.
 */
class _SpamAnvil_TestAnthropic extends SpamAnvil_Anthropic {
	public function request_body( $system, $user ) {
		return $this->build_request_body( $system, $user );
	}
	public function parse_body( $body ) {
		return $this->parse_response_body( $body );
	}
}

class AnthropicProviderTest extends TestCase {

	private function provider( $model ) {
		return new _SpamAnvil_TestAnthropic( 'key', $model );
	}

	// ---- build_request_body() ----

	public function test_no_temperature_is_sent() {
		$body = $this->provider( 'claude-sonnet-5' )->request_body( 'sys', 'user' );
		$this->assertArrayNotHasKey( 'temperature', $body );
		$this->assertArrayNotHasKey( 'top_p', $body );
		$this->assertArrayNotHasKey( 'top_k', $body );
	}

	public function test_thinking_disabled_on_sonnet_5_and_opus_5() {
		foreach ( array( 'claude-sonnet-5', 'claude-opus-5' ) as $model ) {
			$body = $this->provider( $model )->request_body( 'sys', 'user' );
			$this->assertSame( array( 'type' => 'disabled' ), $body['thinking'], $model );
		}
	}

	public function test_thinking_omitted_on_older_and_fable_models() {
		foreach ( array( 'claude-sonnet-4-5-20250929', 'claude-sonnet-4-6', 'claude-fable-5', 'claude-mythos-5', 'claude-haiku-4-5' ) as $model ) {
			$body = $this->provider( $model )->request_body( 'sys', 'user' );
			$this->assertArrayNotHasKey( 'thinking', $body, $model );
		}
	}

	public function test_max_tokens_has_headroom() {
		$body = $this->provider( 'claude-sonnet-5' )->request_body( 'sys', 'user' );
		$this->assertGreaterThanOrEqual( 1024, $body['max_tokens'] );
	}

	// ---- parse_response_body() ----

	public function test_plain_text_response_parses() {
		$body = json_encode( array(
			'stop_reason' => 'end_turn',
			'content'     => array( array( 'type' => 'text', 'text' => '{"score": 90, "reason": "spam"}' ) ),
		) );
		$this->assertSame( '{"score": 90, "reason": "spam"}', $this->provider( 'claude-sonnet-5' )->parse_body( $body ) );
	}

	public function test_thinking_block_before_text_is_skipped() {
		$body = json_encode( array(
			'stop_reason' => 'end_turn',
			'content'     => array(
				array( 'type' => 'thinking', 'thinking' => 'hmm...' ),
				array( 'type' => 'text', 'text' => '{"score": 10, "reason": "ham"}' ),
			),
		) );
		$this->assertSame( '{"score": 10, "reason": "ham"}', $this->provider( 'claude-sonnet-5' )->parse_body( $body ) );
	}

	public function test_refusal_stop_reason_is_an_error() {
		$body = json_encode( array( 'stop_reason' => 'refusal', 'content' => array() ) );
		$result = $this->provider( 'claude-opus-5' )->parse_body( $body );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'spamanvil_refusal', $result->get_error_code() );
	}

	public function test_api_error_object_is_an_error() {
		$body = json_encode( array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'temperature is not supported' ) ) );
		$result = $this->provider( 'claude-sonnet-5' )->parse_body( $body );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'spamanvil_api_error', $result->get_error_code() );
	}

	public function test_missing_text_block_is_unexpected_format() {
		$body = json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'thinking', 'thinking' => 'only thinking' ) ) ) );
		$result = $this->provider( 'claude-sonnet-5' )->parse_body( $body );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'spamanvil_unexpected_format', $result->get_error_code() );
	}

	public function test_extract_text_block_handles_malformed_content() {
		$this->assertSame( '', SpamAnvil_Anthropic::extract_text_block( array() ) );
		$this->assertSame( '', SpamAnvil_Anthropic::extract_text_block( array( 'content' => 'not-an-array' ) ) );
		$this->assertSame( '', SpamAnvil_Anthropic::extract_text_block( array( 'content' => array( 'loose string' ) ) ) );
	}
}
