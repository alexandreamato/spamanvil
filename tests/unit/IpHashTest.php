<?php
/**
 * Unit tests for SpamAnvil_IP_Manager::compute_ip_hash() — the salted, keyed hash
 * used to store blocked IPs. It must be a keyed HMAC (not a bare SHA-256), so a
 * leaked hash can't be reversed by brute-forcing the small IPv4 space.
 */

use PHPUnit\Framework\TestCase;

class IpHashTest extends TestCase {

	private const IP   = '203.0.113.7';
	private const SALT = 'a-per-site-secret';

	public function test_is_deterministic_for_the_same_ip_and_salt() {
		$a = SpamAnvil_IP_Manager::compute_ip_hash( self::IP, self::SALT );
		$b = SpamAnvil_IP_Manager::compute_ip_hash( self::IP, self::SALT );
		$this->assertSame( $a, $b, 'Same IP + salt must hash identically (so blocks match).' );
	}

	public function test_is_a_64_char_hex_string() {
		$h = SpamAnvil_IP_Manager::compute_ip_hash( self::IP, self::SALT );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $h );
	}

	public function test_is_salted_not_a_bare_sha256() {
		// Regression guard for the pre-1.11.2 behaviour: hash('sha256', $ip) was
		// reversible by precomputing the IP space. The keyed HMAC must differ from it.
		$this->assertNotSame(
			hash( 'sha256', self::IP ),
			SpamAnvil_IP_Manager::compute_ip_hash( self::IP, self::SALT ),
			'Hash must be keyed with the salt, not a plain SHA-256 of the IP.'
		);
	}

	public function test_different_salt_yields_different_hash() {
		$this->assertNotSame(
			SpamAnvil_IP_Manager::compute_ip_hash( self::IP, self::SALT ),
			SpamAnvil_IP_Manager::compute_ip_hash( self::IP, 'a-different-secret' ),
			'A different per-site salt must change the hash (proves it is keyed).'
		);
	}

	public function test_different_ips_yield_different_hashes() {
		$this->assertNotSame(
			SpamAnvil_IP_Manager::compute_ip_hash( '203.0.113.7', self::SALT ),
			SpamAnvil_IP_Manager::compute_ip_hash( '203.0.113.8', self::SALT )
		);
	}
}
