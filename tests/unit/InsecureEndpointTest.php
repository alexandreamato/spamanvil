<?php
/**
 * Unit tests for SpamAnvil_Provider_Factory::is_insecure_remote_url() (1.20.0):
 * warn when a custom endpoint would carry the API key and commenter data over
 * plain http across the internet, but not for a model on this machine or LAN.
 */

use PHPUnit\Framework\TestCase;

class InsecureEndpointTest extends TestCase {

	public function test_http_to_a_public_host_is_insecure() {
		$this->assertTrue( SpamAnvil_Provider_Factory::is_insecure_remote_url( 'http://api.example.com/v1/chat/completions' ) );
		$this->assertTrue( SpamAnvil_Provider_Factory::is_insecure_remote_url( 'HTTP://8.8.8.8:8080/v1' ) );
	}

	public function test_https_is_fine() {
		$this->assertFalse( SpamAnvil_Provider_Factory::is_insecure_remote_url( 'https://api.example.com/v1' ) );
	}

	public function test_local_and_private_hosts_are_fine() {
		foreach ( array(
			'http://localhost:11434/v1',
			'http://127.0.0.1:1234/v1',
			'http://[::1]:8000/v1',
			'http://192.168.1.20:8000/v1',
			'http://10.0.0.5/v1',
			'http://172.16.4.2/v1',
			'http://ollama.local/v1',
		) as $url ) {
			$this->assertFalse( SpamAnvil_Provider_Factory::is_insecure_remote_url( $url ), $url );
		}
	}

	public function test_empty_is_fine() {
		$this->assertFalse( SpamAnvil_Provider_Factory::is_insecure_remote_url( '' ) );
	}
}
