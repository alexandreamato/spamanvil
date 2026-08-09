<?php
/**
 * Unit tests for the smart email notifications' pure logic (1.13.0):
 * mode validation and the digest body builder.
 */

use PHPUnit\Framework\TestCase;

class NotifierUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__spamanvil_test_options'] = array();
	}

	public function test_mode_defaults_to_smart() {
		$this->assertSame( 'smart', SpamAnvil_Notifier::get_mode() );
	}

	public function test_invalid_mode_falls_back_to_smart() {
		$GLOBALS['__spamanvil_test_options']['spamanvil_email_mode'] = 'bogus';
		$this->assertSame( 'smart', SpamAnvil_Notifier::get_mode() );
	}

	public function test_stored_modes_are_respected() {
		foreach ( array( 'off', 'smart', 'digest' ) as $mode ) {
			$GLOBALS['__spamanvil_test_options']['spamanvil_email_mode'] = $mode;
			$this->assertSame( $mode, SpamAnvil_Notifier::get_mode() );
		}
	}

	public function test_digest_body_contains_counts() {
		$body = SpamAnvil_Notifier::build_digest_body( 'My Blog', 42, 3, 0, 'https://example.org/mod' );

		$this->assertStringContainsString( 'My Blog', $body );
		$this->assertStringContainsString( '42', $body );
		$this->assertStringContainsString( '3', $body );
	}

	public function test_digest_body_links_moderation_queue_only_when_pending() {
		$with    = SpamAnvil_Notifier::build_digest_body( 'Blog', 1, 0, 5, 'https://example.org/mod' );
		$without = SpamAnvil_Notifier::build_digest_body( 'Blog', 1, 0, 0, 'https://example.org/mod' );

		$this->assertStringContainsString( 'https://example.org/mod', $with );
		$this->assertStringContainsString( '5', $with );
		$this->assertStringNotContainsString( 'https://example.org/mod', $without );
	}
}
