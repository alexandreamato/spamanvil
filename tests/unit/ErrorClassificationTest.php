<?php
/**
 * Unit tests for the 1.12.0 error classification and model-chain parsing —
 * the pure logic behind the queue pause (permanent config errors must never
 * burn retries or flood the logs) and the per-provider model fallback list.
 */

use PHPUnit\Framework\TestCase;

class ErrorClassificationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__spamanvil_test_options'] = array();
	}

	// ---- is_permanent_config_error_code() ----

	public function test_config_errors_are_permanent() {
		foreach ( array(
			'spamanvil_no_api_key',
			'spamanvil_key_decrypt_failed',
			'spamanvil_no_model',
			'spamanvil_unknown_provider',
			'spamanvil_no_provider',
			'spamanvil_config_error',
		) as $code ) {
			$this->assertTrue(
				SpamAnvil_Provider_Factory::is_permanent_config_error_code( $code ),
				"$code should be classified as permanent"
			);
		}
	}

	public function test_transient_errors_are_not_permanent() {
		foreach ( array(
			'spamanvil_api_error',
			'spamanvil_http_error',
			'spamanvil_parse_error',
			'spamanvil_unexpected_format',
			'spamanvil_all_providers_failed',
			'spamanvil_refusal',
			'',
		) as $code ) {
			$this->assertFalse(
				SpamAnvil_Provider_Factory::is_permanent_config_error_code( $code ),
				"'$code' should NOT be classified as permanent"
			);
		}
	}

	// ---- parse_model_list() ----

	public function test_single_model_is_a_list_of_one() {
		$this->assertSame(
			array( 'gpt-4o-mini' ),
			SpamAnvil_Provider_Factory::parse_model_list( 'gpt-4o-mini' )
		);
	}

	public function test_comma_separated_models_keep_order() {
		$this->assertSame(
			array( 'a/free-1:free', 'b/free-2:free', 'c/paid' ),
			SpamAnvil_Provider_Factory::parse_model_list( ' a/free-1:free , b/free-2:free,c/paid ' )
		);
	}

	public function test_newlines_also_separate_models() {
		$this->assertSame(
			array( 'model-a', 'model-b' ),
			SpamAnvil_Provider_Factory::parse_model_list( "model-a\nmodel-b" )
		);
	}

	public function test_duplicates_and_empty_entries_are_dropped() {
		$this->assertSame(
			array( 'x', 'y' ),
			SpamAnvil_Provider_Factory::parse_model_list( 'x,,y, x ,' )
		);
	}

	public function test_empty_input_gives_empty_list() {
		$this->assertSame( array(), SpamAnvil_Provider_Factory::parse_model_list( '' ) );
		$this->assertSame( array(), SpamAnvil_Provider_Factory::parse_model_list( ' , ' ) );
	}

	// ---- get_model_chain() / get_config_hash() ----

	private function make_factory() {
		return new SpamAnvil_Provider_Factory( new SpamAnvil_Encryptor() );
	}

	public function test_model_chain_falls_back_to_default_model() {
		$factory = $this->make_factory();
		$this->assertSame( array( 'gpt-4o-mini' ), $factory->get_model_chain( 'openai' ) );
	}

	public function test_openrouter_default_is_the_router_chain() {
		// The default itself is a chain: the free-pool router first, the paid
		// auto router as fallback — neither goes stale when models churn.
		$factory = $this->make_factory();
		$this->assertSame(
			array( 'openrouter/free', 'openrouter/auto' ),
			$factory->get_model_chain( 'openrouter' )
		);
	}

	public function test_model_chain_reads_stored_list() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_openrouter_model'] = 'free-a:free, free-b:free';
		$factory = $this->make_factory();
		$this->assertSame( array( 'free-a:free', 'free-b:free' ), $factory->get_model_chain( 'openrouter' ) );
	}

	public function test_config_hash_changes_when_model_changes() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';
		$factory = $this->make_factory();

		$before = $factory->get_config_hash();
		$GLOBALS['__spamanvil_test_options']['spamanvil_openai_model'] = 'gpt-5-nano';
		$after = $factory->get_config_hash();

		$this->assertNotSame( $before, $after );
	}

	public function test_config_hash_changes_when_key_changes() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';
		$factory = $this->make_factory();

		$before = $factory->get_config_hash();
		$GLOBALS['__spamanvil_test_options']['spamanvil_openai_api_key'] = 'g:freshly-encrypted-value';
		$after = $factory->get_config_hash();

		$this->assertNotSame( $before, $after );
	}

	public function test_config_hash_stable_when_nothing_changes() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';
		$factory = $this->make_factory();
		$this->assertSame( $factory->get_config_hash(), $factory->get_config_hash() );
	}
}
