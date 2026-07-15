<?php
/**
 * Unit tests for the auto free-model fallback helpers (v1.9.0):
 * SpamAnvil_Provider_Factory::is_model_unavailable_error() and pick_free_model().
 */

use PHPUnit\Framework\TestCase;

class ModelFallbackTest extends TestCase {

	private $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->factory = new SpamAnvil_Provider_Factory( new SpamAnvil_Encryptor() );
	}

	private function err( $message ) {
		return new WP_Error( 'spamanvil_api_error', $message );
	}

	public function test_detects_model_unavailable_errors() {
		$this->assertTrue( $this->factory->is_model_unavailable_error( $this->err( 'API returned HTTP 404: No endpoints found for meta-llama/x:free' ) ) );
		$this->assertTrue( $this->factory->is_model_unavailable_error( $this->err( 'model not found' ) ) );
		$this->assertTrue( $this->factory->is_model_unavailable_error( $this->err( 'openai/gpt-oss-20b:free is not a valid model ID' ) ) );
		$this->assertTrue( $this->factory->is_model_unavailable_error( $this->err( 'The model does not exist' ) ) );
		$this->assertTrue( $this->factory->is_model_unavailable_error( $this->err( 'Unknown model requested' ) ) );
	}

	public function test_does_not_treat_auth_or_rate_limit_as_model_error() {
		$this->assertFalse( $this->factory->is_model_unavailable_error( $this->err( 'HTTP 401 - Unauthorized: invalid API key' ) ) );
		$this->assertFalse( $this->factory->is_model_unavailable_error( $this->err( 'HTTP 429 - Rate limit exceeded' ) ) );
		$this->assertFalse( $this->factory->is_model_unavailable_error( $this->err( 'HTTP 402 - Insufficient credits' ) ) );
		$this->assertFalse( $this->factory->is_model_unavailable_error( $this->err( 'cURL error 28: Operation timed out' ) ) );
	}

	public function test_non_wp_error_is_not_a_model_error() {
		$this->assertFalse( $this->factory->is_model_unavailable_error( array( 'score' => 90 ) ) );
		$this->assertFalse( $this->factory->is_model_unavailable_error( 'just a string' ) );
	}

	public function test_pick_free_model_returns_first_free() {
		$models = array(
			array( 'id' => 'paid/model', 'free' => false ),
			array( 'id' => 'free/one', 'free' => true ),
			array( 'id' => 'free/two', 'free' => true ),
		);
		$this->assertSame( 'free/one', $this->factory->pick_free_model( $models ) );
	}

	public function test_pick_free_model_excludes_the_failed_model() {
		$models = array(
			array( 'id' => 'free/one', 'free' => true ),
			array( 'id' => 'free/two', 'free' => true ),
		);
		$this->assertSame( 'free/two', $this->factory->pick_free_model( $models, 'free/one' ) );
	}

	public function test_pick_free_model_returns_empty_when_none_free() {
		$models = array(
			array( 'id' => 'paid/a', 'free' => false ),
			array( 'id' => 'paid/b' ),
		);
		$this->assertSame( '', $this->factory->pick_free_model( $models ) );
		$this->assertSame( '', $this->factory->pick_free_model( array() ) );
	}
}
