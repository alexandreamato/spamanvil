<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Encryptor {

	// New values are written with authenticated AES-256-GCM (AEAD); legacy values
	// written with the unauthenticated AES-256-CBC cipher are still read (see decrypt()).
	private const METHOD_GCM = 'aes-256-gcm';
	private const METHOD_CBC = 'aes-256-cbc';
	private const GCM_PREFIX = 'g:'; // Marks the GCM format. Base64 never contains ':', so this is unambiguous.
	private const GCM_TAG_LEN = 16;

	private function get_key() {
		// Prefer AUTH_SALT (present on virtually all installs, so keys stay decryptable).
		// Fall back to wp_salt('auth') — always a strong, per-site value that WordPress
		// auto-generates and persists — instead of a static string that every misconfigured
		// site would share, which would let anyone reading the options table derive the key.
		$salt = defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ? AUTH_SALT : wp_salt( 'auth' );
		return hash( 'sha256', $salt, true );
	}

	public function encrypt( $plain_text ) {
		if ( empty( $plain_text ) ) {
			return '';
		}

		$key    = $this->get_key();
		$iv_len = openssl_cipher_iv_length( self::METHOD_GCM );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$tag    = '';

		$encrypted = openssl_encrypt( $plain_text, self::METHOD_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_LEN );

		if ( false === $encrypted ) {
			return '';
		}

		return self::GCM_PREFIX . base64_encode( $iv . $tag . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function decrypt( $encrypted_text ) {
		if ( empty( $encrypted_text ) ) {
			return '';
		}

		$key = $this->get_key();

		if ( 0 === strpos( $encrypted_text, self::GCM_PREFIX ) ) {
			return $this->decrypt_gcm( substr( $encrypted_text, strlen( self::GCM_PREFIX ) ), $key );
		}

		// No prefix — a legacy CBC value written before 1.10. Read it so an
		// upgrade never invalidates a key the user already saved.
		return $this->decrypt_cbc( $encrypted_text, $key );
	}

	private function decrypt_gcm( $payload, $key ) {
		$data = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $data ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::METHOD_GCM );

		if ( strlen( $data ) <= $iv_len + self::GCM_TAG_LEN ) {
			return '';
		}

		$iv         = substr( $data, 0, $iv_len );
		$tag        = substr( $data, $iv_len, self::GCM_TAG_LEN );
		$ciphertext = substr( $data, $iv_len + self::GCM_TAG_LEN );

		// Wrong key (e.g. AUTH_SALT rotated) or tampered data → GCM tag check fails → false.
		$decrypted = openssl_decrypt( $ciphertext, self::METHOD_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag );

		return ( false === $decrypted ) ? '' : $decrypted;
	}

	private function decrypt_cbc( $encrypted_text, $key ) {
		$data = base64_decode( $encrypted_text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $data ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::METHOD_CBC );

		if ( strlen( $data ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		$decrypted = openssl_decrypt( $ciphertext, self::METHOD_CBC, $key, OPENSSL_RAW_DATA, $iv );

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
