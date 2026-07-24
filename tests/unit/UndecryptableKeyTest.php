<?php
/**
 * Unit tests for SpamAnvil_Provider_Factory's undecryptable-key detection:
 * the sitewide notice must fire only for providers in the configured chain,
 * while has_broken_stored_key() flags any provider inline on the Providers tab.
 */

use PHPUnit\Framework\TestCase;

class UndecryptableKeyTest extends TestCase {

	private $factory;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__spamanvil_test_options'] = array();
		$GLOBALS['__spamanvil_test_salt']    = 'unit-test-salt';
		$this->factory                       = new SpamAnvil_Provider_Factory( new SpamAnvil_Encryptor() );
	}

	/** Encrypt under a different salt — under the current salt the value no longer decrypts. */
	private function broken_ciphertext( $plain = 'sk-old-key' ) {
		$GLOBALS['__spamanvil_test_salt'] = 'salt-before-rotation';
		$stale                            = ( new SpamAnvil_Encryptor() )->encrypt( $plain );
		$GLOBALS['__spamanvil_test_salt'] = 'unit-test-salt';
		return $stale;
	}

	private function valid_ciphertext( $plain = 'sk-good-key' ) {
		return ( new SpamAnvil_Encryptor() )->encrypt( $plain );
	}

	public function test_broken_key_on_primary_provider_triggers_notice() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';
		$GLOBALS['__spamanvil_test_options']['spamanvil_openai_api_key']   = $this->broken_ciphertext();

		$this->assertTrue( $this->factory->has_undecryptable_key() );
	}

	public function test_broken_key_on_fallback_provider_triggers_notice() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider']   = 'openrouter';
		$GLOBALS['__spamanvil_test_options']['spamanvil_openrouter_api_key'] = $this->valid_ciphertext();
		$GLOBALS['__spamanvil_test_options']['spamanvil_fallback_provider']  = 'gemini';
		$GLOBALS['__spamanvil_test_options']['spamanvil_gemini_api_key']     = $this->broken_ciphertext();

		$this->assertTrue( $this->factory->has_undecryptable_key() );
	}

	public function test_broken_key_on_unused_provider_does_not_trigger_notice() {
		// The 1.11.2 regression: a stale key on a provider outside the configured
		// chain kept the sitewide notice alive forever — while the Providers tab
		// rendered that provider as if no key were stored, so re-entering the keys
		// in use could never make the notice go away.
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider']   = 'openrouter';
		$GLOBALS['__spamanvil_test_options']['spamanvil_openrouter_api_key'] = $this->valid_ciphertext();
		$GLOBALS['__spamanvil_test_options']['spamanvil_openai_api_key']     = $this->broken_ciphertext();

		$this->assertFalse( $this->factory->has_undecryptable_key() );
		$this->assertTrue(
			$this->factory->has_broken_stored_key( 'openai' ),
			'The Providers tab must still flag the stale key inline.'
		);
	}

	public function test_valid_key_on_chain_provider_is_healthy() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';
		$GLOBALS['__spamanvil_test_options']['spamanvil_openai_api_key']   = $this->valid_ciphertext();

		$this->assertFalse( $this->factory->has_undecryptable_key() );
		$this->assertFalse( $this->factory->has_broken_stored_key( 'openai' ) );
	}

	public function test_no_stored_key_is_not_broken() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_primary_provider'] = 'openai';

		$this->assertFalse( $this->factory->has_undecryptable_key() );
		$this->assertFalse( $this->factory->has_broken_stored_key( 'openai' ) );
		$this->assertFalse( $this->factory->has_broken_stored_key( 'unknown-provider' ) );
	}
}
