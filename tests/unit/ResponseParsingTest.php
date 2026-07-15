<?php
/**
 * Unit tests for SpamAnvil_Provider::validate_response() — the JSON extraction that
 * broke on reasoning models (v1.3.0 fix). These are the regression net for the
 * incident where valid credentials produced 100% classification failures.
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal concrete provider exposing the protected parsing methods for testing.
 */
class _SpamAnvil_TestProvider extends SpamAnvil_Provider {
	public function get_name() {
		return 'test';
	}
	protected function build_request_body( $system_prompt, $user_prompt ) {
		return array();
	}
	protected function parse_response_body( $body ) {
		return $body;
	}
	protected function get_endpoint_url() {
		return 'https://example.test';
	}
	protected function get_headers() {
		return array();
	}
	public function parse( $content ) {
		return $this->validate_response( $content );
	}
}

class ResponseParsingTest extends TestCase {

	private $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->provider = new _SpamAnvil_TestProvider( 'key', 'model' );
	}

	private function score( $content ) {
		$r = $this->provider->parse( $content );
		$this->assertIsArray( $r, 'Expected a parsed result, got: ' . ( is_wp_error( $r ) ? $r->get_error_message() : gettype( $r ) ) );
		return $r['score'];
	}

	public function test_pure_json() {
		$this->assertSame( 90, $this->score( '{"score": 90, "reason": "spam"}' ) );
	}

	public function test_markdown_fenced_json() {
		$this->assertSame( 15, $this->score( "```json\n{\"score\": 15, \"reason\": \"ok\"}\n```" ) );
	}

	public function test_reasoning_think_block_is_stripped() {
		$content = "<think>\nThe comment has a promotional URL and generic praise, so it is likely spam.\n</think>\n{\"score\": 88, \"reason\": \"promotional link\"}";
		$this->assertSame( 88, $this->score( $content ) );
	}

	public function test_prose_around_json() {
		$content = "Sure! Here is my assessment of the comment:\n{\"score\": 72, \"reason\": \"looks spammy\"}\nLet me know if you need anything else.";
		$this->assertSame( 72, $this->score( $content ) );
	}

	public function test_prose_before_and_reasoning_and_fence_combined() {
		$content = "<think>hmm, borderline</think>\nHere you go:\n```json\n{\"score\": 40, \"reason\": \"uncertain\"}\n```";
		$this->assertSame( 40, $this->score( $content ) );
	}

	public function test_nested_object_and_braces_in_reason() {
		$content = '{"score": 60, "reason": "mentions {curly} braces and a nested {\"x\":1} shape"}';
		$this->assertSame( 60, $this->score( $content ) );
	}

	public function test_regex_fallback_when_json_is_malformed() {
		// Missing closing brace → not valid JSON, but a score is still recoverable.
		$content = 'The score: 95 because this is clearly spam with links.';
		$this->assertSame( 95, $this->score( $content ) );
	}

	public function test_reason_is_extracted_by_regex_fallback() {
		$r = $this->provider->parse( 'score = 80, reason = "generic praise + link"' );
		$this->assertIsArray( $r );
		$this->assertSame( 80, $r['score'] );
		$this->assertStringContainsString( 'generic praise', $r['reason'] );
	}

	public function test_unparseable_content_returns_error() {
		$r = $this->provider->parse( 'I cannot help with that request.' );
		$this->assertTrue( is_wp_error( $r ) );
		$this->assertSame( 'spamanvil_invalid_json', $r->get_error_code() );
	}

	public function test_out_of_range_score_is_rejected() {
		$r = $this->provider->parse( '{"score": 150, "reason": "x"}' );
		$this->assertTrue( is_wp_error( $r ) );
		$this->assertSame( 'spamanvil_invalid_score', $r->get_error_code() );
	}

	public function test_reason_is_sanitized() {
		$r = $this->provider->parse( '{"score": 30, "reason": "<script>alert(1)</script> ok"}' );
		$this->assertIsArray( $r );
		$this->assertStringNotContainsString( '<script>', $r['reason'] );
	}
}
