<?php
/**
 * Unit tests for SpamAnvil_Encryptor: authenticated AES-256-GCM API-key storage,
 * with backward-compatible reads of the legacy AES-256-CBC format.
 */

use PHPUnit\Framework\TestCase;

class EncryptorTest extends TestCase {

	private $encryptor;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__spamanvil_test_salt'] = 'unit-test-salt';
		$this->encryptor                  = new SpamAnvil_Encryptor();
	}

	public function test_round_trip_recovers_plaintext() {
		$secret    = 'sk-test-1234567890ABCDEF';
		$encrypted = $this->encryptor->encrypt( $secret );

		$this->assertNotSame( $secret, $encrypted, 'Ciphertext must differ from plaintext.' );
		$this->assertNotSame( '', $encrypted, 'Encryption of a non-empty value must not be empty.' );
		$this->assertSame( $secret, $this->encryptor->decrypt( $encrypted ) );
	}

	public function test_iv_is_random_so_ciphertext_differs_each_time() {
		$secret = 'same-plaintext';
		$a      = $this->encryptor->encrypt( $secret );
		$b      = $this->encryptor->encrypt( $secret );

		$this->assertNotSame( $a, $b, 'A random IV must make each ciphertext unique.' );
		$this->assertSame( $secret, $this->encryptor->decrypt( $a ) );
		$this->assertSame( $secret, $this->encryptor->decrypt( $b ) );
	}

	public function test_empty_input_returns_empty_string() {
		$this->assertSame( '', $this->encryptor->encrypt( '' ) );
		$this->assertSame( '', $this->encryptor->decrypt( '' ) );
	}

	public function test_tampered_ciphertext_fails_closed() {
		$encrypted = $this->encryptor->encrypt( 'sensitive-key' );

		// Flip the tail of the base64 blob — decryption must fail to '' (not throw, not leak).
		$tampered = substr( $encrypted, 0, -4 ) . ( substr( $encrypted, -4 ) === 'AAAA' ? 'BBBB' : 'AAAA' );

		$this->assertSame( '', $this->encryptor->decrypt( $tampered ) );
	}

	public function test_garbage_input_returns_empty_string() {
		$this->assertSame( '', $this->encryptor->decrypt( 'not-valid-base64-!!!' ) );
		$this->assertSame( '', $this->encryptor->decrypt( 'YQ==' ) ); // Too short to hold an IV.
	}

	public function test_key_is_salt_derived_so_wrong_salt_cannot_decrypt() {
		$encrypted = $this->encryptor->encrypt( 'my-api-key' );

		// Rotate the salt and rebuild — the derived key changes, so decryption must fail closed.
		$GLOBALS['__spamanvil_test_salt'] = 'a-completely-different-salt';
		$other                            = new SpamAnvil_Encryptor();

		$this->assertSame( '', $other->decrypt( $encrypted ) );
	}

	public function test_new_values_use_the_authenticated_gcm_format() {
		$encrypted = $this->encryptor->encrypt( 'sk-modern-key' );

		$this->assertStringStartsWith( 'g:', $encrypted, 'New ciphertext must carry the GCM format marker.' );
		$this->assertSame( 'sk-modern-key', $this->encryptor->decrypt( $encrypted ) );
	}

	public function test_reads_legacy_cbc_values_written_before_1_10() {
		$secret = 'sk-legacy-key-2024';
		$legacy = $this->legacy_cbc_encrypt( $secret );

		$this->assertStringStartsNotWith( 'g:', $legacy, 'Legacy fixture must be in the old unprefixed CBC format.' );
		$this->assertSame( $secret, $this->encryptor->decrypt( $legacy ), 'Upgrading must not invalidate an already-saved key.' );
	}

	public function test_legacy_cbc_value_fails_closed_under_a_rotated_salt() {
		$legacy = $this->legacy_cbc_encrypt( 'sk-legacy-key' );

		$GLOBALS['__spamanvil_test_salt'] = 'a-completely-different-salt';
		$other                            = new SpamAnvil_Encryptor();

		$this->assertSame( '', $other->decrypt( $legacy ) );
	}

	/**
	 * Reproduce the pre-1.10 storage format: base64( iv(16) . ciphertext ), no marker,
	 * AES-256-CBC, keyed the same way the encryptor derives its key from the salt.
	 */
	private function legacy_cbc_encrypt( $plain_text ) {
		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$encrypted = openssl_encrypt( $plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $encrypted );
	}

	public function test_mask_behaviour() {
		$this->assertSame( '', $this->encryptor->mask( '' ) );
		$this->assertSame( '****', $this->encryptor->mask( 'abcd' ) );
		$this->assertSame( '**', $this->encryptor->mask( 'ab' ) );
		$this->assertSame( '*******wxyz', $this->encryptor->mask( 'abcdefgwxyz' ) );
		$this->assertStringEndsWith( 'CDEF', $this->encryptor->mask( 'sk-secret-value-CDEF' ) );
	}
}
