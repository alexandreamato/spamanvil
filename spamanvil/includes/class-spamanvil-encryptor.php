<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Encryptor {

	private const METHOD = 'aes-256-cbc';

	private function get_key() {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'spamanvil-default-salt';
		return hash( 'sha256', $salt, true );
	}

	public function encrypt( $plain_text ) {
		if ( empty( $plain_text ) ) {
			return '';
		}

		$key    = $this->get_key();
		$iv_len = openssl_cipher_iv_length( self::METHOD );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( $plain_text, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function decrypt( $encrypted_text ) {
		if ( empty( $encrypted_text ) ) {
			return '';
		}

		$key  = $this->get_key();
		$data = base64_decode( $encrypted_text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $data ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::METHOD );

		if ( strlen( $data ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		$decrypted = openssl_decrypt( $ciphertext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $decrypted ) ? '' : $decrypted;
	}

	public function mask( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$len = strlen( $value );

		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}

		return str_repeat( '*', $len - 4 ) . substr( $value, -4 );
	}
}
