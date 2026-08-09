<?php
/**
 * Unit tests for prompt line-ending normalization (1.14.1, field audit N1).
 *
 * Browsers submit textarea content with CRLF, so an unmodified default that was
 * ever re-saved hashed differently from the LF source string — silently blocking
 * the 1.12.0 security migration. normalize_prompt() makes hashing ending-agnostic.
 */

use PHPUnit\Framework\TestCase;

class PromptNormalizeTest extends TestCase {

	public function test_crlf_becomes_lf() {
		$this->assertSame( "a\nb\nc", SpamAnvil_Activator::normalize_prompt( "a\r\nb\r\nc" ) );
	}

	public function test_lone_cr_becomes_lf() {
		$this->assertSame( "a\nb", SpamAnvil_Activator::normalize_prompt( "a\rb" ) );
	}

	public function test_lf_is_untouched() {
		$this->assertSame( "a\nb", SpamAnvil_Activator::normalize_prompt( "a\nb" ) );
	}

	public function test_hash_is_ending_agnostic_for_the_real_default() {
		// The exact failure from the field audit: the browser-submitted (CRLF) copy
		// of a default must hash identically to the LF source string.
		$default = SpamAnvil_Activator::get_default_user_prompt();
		$crlf    = str_replace( "\n", "\r\n", $default );

		$this->assertNotSame( md5( $default ), md5( $crlf ), 'Precondition: raw hashes must differ.' );
		$this->assertSame(
			md5( SpamAnvil_Activator::normalize_prompt( $default ) ),
			md5( SpamAnvil_Activator::normalize_prompt( $crlf ) )
		);
	}
}
