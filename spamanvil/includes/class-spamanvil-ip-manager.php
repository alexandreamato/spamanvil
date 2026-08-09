<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: All queries target custom plugin table (spamanvil_blocked_ips).
// Table name comes from $wpdb->prefix and is safe.

class SpamAnvil_IP_Manager {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'spamanvil_blocked_ips';
	}

	public function is_blocked( $ip ) {
		if ( empty( $ip ) ) {
			return false;
		}

		// Skip blocking for logged-in moderators.
		if ( is_user_logged_in() && current_user_can( 'moderate_comments' ) ) {
			return false;
		}

		if ( '1' !== get_option( 'spamanvil_ip_blocking_enabled', '1' ) ) {
			return false;
		}

		global $wpdb;

		$ip_hash = $this->hash_ip( $ip );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT blocked_until FROM {$this->table} WHERE ip_hash = %s",
				$ip_hash
			)
		);

		if ( ! $row ) {
			return false;
		}

		if ( null === $row->blocked_until ) {
			return false;
		}

		// Check if block has expired.
		if ( strtotime( $row->blocked_until ) < time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Escalating block duration in hours, capped at 30 days.
	 *
	 * 24h, 48h, 96h, 192h, 384h, then a hard ceiling of 720h (30 days): unbounded
	 * doubling produced multi-year — effectively permanent — bans by level ~10, which
	 * turns any false positive into a life sentence and can overflow the DATETIME
	 * column at high levels (fixed 1.12.0). Pure and static (unit-tested).
	 *
	 * @param int $level Escalation level (1-based).
	 * @return int Hours to block.
	 */
	public static function block_hours_for_level( $level ) {
		$level = max( 1, (int) $level );

		// pow() overflows to INF around level ~1030; short-circuit well before that.
		if ( $level >= 6 ) {
			return 720;
		}

		return (int) min( 24 * pow( 2, $level - 1 ), 720 );
	}

	public function record_spam_attempt( $ip ) {
		if ( empty( $ip ) || '1' !== get_option( 'spamanvil_ip_blocking_enabled', '1' ) ) {
			return;
		}

		global $wpdb;

		$ip_hash    = $this->hash_ip( $ip );
		$ip_display = $this->mask_ip( $ip );
		$threshold  = (int) get_option( 'spamanvil_ip_block_threshold', 3 );
		$now        = current_time( 'mysql' );

		// Try to update existing record.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attempts, escalation_level FROM {$this->table} WHERE ip_hash = %s",
				$ip_hash
			)
		);

		if ( $existing ) {
			$new_attempts = $existing->attempts + 1;
			$update_data  = array(
				'attempts'   => $new_attempts,
				'updated_at' => $now,
			);

			if ( $new_attempts >= $threshold ) {
				$new_level = $existing->escalation_level + 1;
				$hours     = self::block_hours_for_level( $new_level );
				$update_data['blocked_until']    = gmdate( 'Y-m-d H:i:s', time() + ( $hours * 3600 ) );
				$update_data['escalation_level'] = $new_level;
			}

			$wpdb->update(
				$this->table,
				$update_data,
				array( 'id' => $existing->id ),
				null,
				array( '%d' )
			);
		} else {
			$insert_data = array(
				'ip_hash'    => $ip_hash,
				'ip_display' => $ip_display,
				'attempts'   => 1,
				'created_at' => $now,
				'updated_at' => $now,
			);

			if ( 1 >= $threshold ) {
				$insert_data['blocked_until']    = gmdate( 'Y-m-d H:i:s', time() + ( 24 * 3600 ) );
				$insert_data['escalation_level'] = 1;
			}

			$wpdb->insert( $this->table, $insert_data );
		}
	}

	public function get_blocked_list( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'items'    => $items ? $items : array(),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function unblock_ip( $id ) {
		global $wpdb;

		return $wpdb->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	public function hash_ip( $ip ) {
		return self::compute_ip_hash( $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Salted, keyed hash of an IP address.
	 *
	 * Keyed with a per-site secret so a stored hash cannot be reversed by brute-forcing
	 * the (small, fully enumerable) IP space — an unsalted SHA-256 of an IPv4 address is
	 * trivially reversible with a precomputed table, which would defeat the point of
	 * hashing. Pure and static so it can be unit-tested without a WordPress bootstrap.
	 *
	 * @param string $ip   The IP address.
	 * @param string $salt Per-site secret (e.g. wp_salt( 'nonce' )).
	 * @return string 64-char hex HMAC-SHA-256.
	 */
	public static function compute_ip_hash( $ip, $salt ) {
		return hash_hmac( 'sha256', (string) $ip, (string) $salt );
	}

	public function mask_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '***';
			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', $ip );
			$count = count( $parts );
			if ( $count > 2 ) {
				$parts[ $count - 1 ] = '****';
				$parts[ $count - 2 ] = '****';
			}
			return implode( ':', $parts );
		}

		return '***.***.***';
	}

	/**
	 * Resolve the client IP from the admin-configured trusted source.
	 *
	 * Trusting the left-most X-Forwarded-For value (the pre-1.10 behaviour) trusts
	 * a client-supplied header: an attacker can send a different forged IP on every
	 * request and never be blocked or rate-limited. Which header we trust is now an
	 * admin choice ({@see spamanvil_trusted_ip_header}) and defaults to REMOTE_ADDR,
	 * which is never spoofable. An admin only opts into a proxy header when they know
	 * their edge sets it (e.g. CF-Connecting-IP behind Cloudflare).
	 *
	 * @return string A valid IP, or '' when none could be resolved.
	 */
	public function get_client_ip() {
		$source = get_option( 'spamanvil_trusted_ip_header', 'remote_addr' );
		return self::resolve_client_ip( $source, $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized/validated inside resolve_client_ip().
	}

	/**
	 * Pure IP resolution: pick the first valid IP for the chosen source.
	 *
	 * REMOTE_ADDR is always the final fallback, so a missing or invalid proxy
	 * header never yields an empty IP. Kept static and side-effect-free so it can
	 * be unit-tested without a WordPress/DB bootstrap.
	 *
	 * @param string $source One of: remote_addr, cf, x_real_ip, xff_last, auto.
	 * @param array  $server A $_SERVER-shaped array.
	 * @return string A valid IP, or ''.
	 */
	public static function resolve_client_ip( $source, array $server ) {
		switch ( $source ) {
			case 'cf':
				$candidates = array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' );
				break;
			case 'x_real_ip':
				$candidates = array( 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
				break;
			case 'xff_last':
				$candidates = array( '__XFF_RIGHTMOST__', 'REMOTE_ADDR' );
				break;
			case 'auto':
				// Prefer proxy-set headers; never the client-supplied left-most XFF.
				$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', '__XFF_RIGHTMOST__', 'REMOTE_ADDR' );
				break;
			case 'remote_addr':
			default:
				$candidates = array( 'REMOTE_ADDR' );
				break;
		}

		foreach ( $candidates as $key ) {
			$raw = '__XFF_RIGHTMOST__' === $key
				? self::forwarded_for_rightmost( $server )
				: ( isset( $server[ $key ] ) ? $server[ $key ] : '' );

			$ip = self::sanitize_ip( $raw );
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * The right-most X-Forwarded-For value — appended by the nearest trusted
	 * proxy. The left-most value is client-supplied and therefore spoofable.
	 *
	 * @param array $server A $_SERVER-shaped array.
	 * @return string Raw (unsanitized) candidate, or ''.
	 */
	private static function forwarded_for_rightmost( array $server ) {
		if ( empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			return '';
		}
		$parts = explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] );
		return end( $parts );
	}

	/**
	 * Sanitize and validate a raw header value into a canonical IP string.
	 *
	 * @param mixed $raw Raw header value.
	 * @return string A valid IP, or ''.
	 */
	private static function sanitize_ip( $raw ) {
		$ip = trim( sanitize_text_field( wp_unslash( (string) $raw ) ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
