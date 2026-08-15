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

	/**
	 * The free pool is not all chat models. `openrouter/free` routed a real
	 * classification to nvidia/nemotron-3.5-content-safety:free, whose entire reply is
	 * "User Safety: safe" — and pick_free_model() used to return whatever came first
	 * in the list, which today is a code model.
	 */
	public function test_rejects_models_that_cannot_answer_a_classification_prompt() {
		foreach ( array(
			'nvidia/nemotron-3.5-content-safety:free',
			'meta-llama/llama-guard-4-12b',
			'openai/omni-moderation-latest',
			'cohere/north-mini-code:free',
			'qwen/qwen-2.5-coder-32b-instruct',
			'openai/text-embedding-3-large',
			'jina/jina-reranker-v2',
			'openai/whisper-large-v3',
			'google/lyria-3-pro-preview',
			'nvidia/nemotron-nano-12b-v2-vl:free',
			'black-forest-labs/flux-1-diffusion',
			'',
		) as $id ) {
			$this->assertFalse(
				SpamAnvil_Provider_Factory::is_plausible_chat_model( $id ),
				"{$id} should not be offered as a spam classifier."
			);
		}
	}

	public function test_accepts_general_purpose_chat_models() {
		foreach ( array(
			'google/gemma-4-31b-it:free',
			'nvidia/nemotron-3-super-120b-a12b:free',
			'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
			'openai/gpt-oss-20b:free',
			'openrouter/free',
			'deepseek/deepseek-chat',
			'anthropic/claude-sonnet-5',
		) as $id ) {
			$this->assertTrue(
				SpamAnvil_Provider_Factory::is_plausible_chat_model( $id ),
				"{$id} should be usable as a spam classifier."
			);
		}
	}

	public function test_pick_free_model_skips_task_specific_models() {
		$models = array(
			array( 'id' => 'cohere/north-mini-code:free', 'free' => true ),
			array( 'id' => 'nvidia/nemotron-3.5-content-safety:free', 'free' => true ),
			array( 'id' => 'google/gemma-4-31b-it:free', 'free' => true ),
		);

		$this->assertSame( 'google/gemma-4-31b-it:free', $this->factory->pick_free_model( $models ) );
	}

	/**
	 * The wizard stops on a rejected key instead of probing alternative models with a
	 * key that can never work.
	 */
	public function test_detects_auth_errors() {
		foreach ( array(
			'API returned HTTP 401: {"error":{"message":"Incorrect API key provided"}}',
			'API returned HTTP 403: Forbidden',
			'Unauthorized',
			'API key not valid. Please pass a valid API key.',
			'No API key configured for openrouter',
		) as $msg ) {
			$this->assertTrue( SpamAnvil_Provider_Factory::is_auth_error( $this->err( $msg ) ), $msg );
		}
	}

	public function test_does_not_treat_model_or_rate_limit_failures_as_auth() {
		foreach ( array(
			'API returned HTTP 429: rate limited',
			'API returned HTTP 404: No endpoints found',
			'Unexpected API response format',
			'Could not parse a score from the response: User Safety: safe',
		) as $msg ) {
			$this->assertFalse( SpamAnvil_Provider_Factory::is_auth_error( $this->err( $msg ) ), $msg );
		}

		$this->assertFalse( SpamAnvil_Provider_Factory::is_auth_error( 'not an error object' ) );
	}
}
