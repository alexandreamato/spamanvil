<?php
/**
 * Unit tests for SpamAnvil_IP_Manager::resolve_client_ip() — the trusted-header
 * selection that decides which request header identifies the visitor IP.
 *
 * The pre-1.10 code trusted the left-most X-Forwarded-For value, which the client
 * supplies and can forge on every request to evade IP blocking and rate limiting.
 * These tests pin down the fix: the source is configurable and defaults to the
 * un-spoofable REMOTE_ADDR.
 */

use PHPUnit\Framework\TestCase;

class IpManagerTest extends TestCase {

	private const CLIENT = '203.0.113.7';   // The spoofed / client-supplied value.
	private const PROXY  = '198.51.100.9';  // The real edge / proxy-set value.
	private const EDGE   = '192.0.2.1';     // The direct connection (REMOTE_ADDR).

	private function server( array $overrides = array() ) {
		return array_merge( array( 'REMOTE_ADDR' => self::EDGE ), $overrides );
	}

	public function test_default_remote_addr_ignores_forwarded_headers() {
		$server = $this->server( array(
			'HTTP_X_FORWARDED_FOR'  => self::CLIENT,
			'HTTP_X_REAL_IP'        => self::CLIENT,
			'HTTP_CF_CONNECTING_IP' => self::CLIENT,
		) );

		$this->assertSame(
			self::EDGE,
			SpamAnvil_IP_Manager::resolve_client_ip( 'remote_addr', $server ),
			'The default must trust only REMOTE_ADDR, never a forwardable header.'
		);
	}

	public function test_spoofed_xff_cannot_change_the_resolved_ip_by_default() {
		$a = SpamAnvil_IP_Manager::resolve_client_ip( 'remote_addr', $this->server( array( 'HTTP_X_FORWARDED_FOR' => '1.1.1.1' ) ) );
		$b = SpamAnvil_IP_Manager::resolve_client_ip( 'remote_addr', $this->server( array( 'HTTP_X_FORWARDED_FOR' => '2.2.2.2' ) ) );

		$this->assertSame( $a, $b, 'Rotating a forged X-Forwarded-For must not change the identity by default.' );
		$this->assertSame( self::EDGE, $a );
	}

	public function test_cloudflare_source_uses_cf_connecting_ip() {
		$server = $this->server( array(
			'HTTP_CF_CONNECTING_IP' => self::PROXY,
			'HTTP_X_FORWARDED_FOR'  => self::CLIENT, // Attacker-supplied — must be ignored.
		) );

		$this->assertSame( self::PROXY, SpamAnvil_IP_Manager::resolve_client_ip( 'cf', $server ) );
	}

	public function test_x_real_ip_source() {
		$server = $this->server( array( 'HTTP_X_REAL_IP' => self::PROXY ) );
		$this->assertSame( self::PROXY, SpamAnvil_IP_Manager::resolve_client_ip( 'x_real_ip', $server ) );
	}

	public function test_xff_last_takes_rightmost_not_client_supplied_leftmost() {
		// Chain: client-forged, then the real IP appended by the trusted proxy.
		$server = $this->server( array( 'HTTP_X_FORWARDED_FOR' => self::CLIENT . ', ' . self::PROXY ) );

		$this->assertSame(
			self::PROXY,
			SpamAnvil_IP_Manager::resolve_client_ip( 'xff_last', $server ),
			'xff_last must take the right-most (proxy-set) value, not the spoofable left-most one.'
		);
	}

	public function test_auto_prefers_proxy_header_but_never_leftmost_xff() {
		$server = $this->server( array(
			'HTTP_CF_CONNECTING_IP' => self::PROXY,
			'HTTP_X_FORWARDED_FOR'  => self::CLIENT . ', ' . self::PROXY,
		) );
		$this->assertSame( self::PROXY, SpamAnvil_IP_Manager::resolve_client_ip( 'auto', $server ) );
	}

	public function test_falls_back_to_remote_addr_when_chosen_header_absent() {
		// Configured for Cloudflare, but the CF header is missing on this request.
		$this->assertSame(
			self::EDGE,
			SpamAnvil_IP_Manager::resolve_client_ip( 'cf', $this->server() ),
			'A missing proxy header must fall back to REMOTE_ADDR, never return empty.'
		);
	}

	public function test_invalid_header_value_falls_back_and_never_returns_garbage() {
		$server = $this->server( array( 'HTTP_CF_CONNECTING_IP' => 'not-an-ip' ) );
		$this->assertSame( self::EDGE, SpamAnvil_IP_Manager::resolve_client_ip( 'cf', $server ) );
	}

	public function test_returns_empty_when_nothing_is_valid() {
		$this->assertSame( '', SpamAnvil_IP_Manager::resolve_client_ip( 'remote_addr', array( 'REMOTE_ADDR' => 'garbage' ) ) );
		$this->assertSame( '', SpamAnvil_IP_Manager::resolve_client_ip( 'cf', array() ) );
	}

	public function test_unknown_source_behaves_like_remote_addr() {
		$server = $this->server( array( 'HTTP_X_FORWARDED_FOR' => self::CLIENT ) );
		$this->assertSame( self::EDGE, SpamAnvil_IP_Manager::resolve_client_ip( 'bogus-value', $server ) );
	}

	public function test_ipv6_is_accepted() {
		$server = $this->server( array( 'HTTP_CF_CONNECTING_IP' => '2001:db8::1' ) );
		$this->assertSame( '2001:db8::1', SpamAnvil_IP_Manager::resolve_client_ip( 'cf', $server ) );
	}
}
