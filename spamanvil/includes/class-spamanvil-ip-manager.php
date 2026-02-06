<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				// Escalating durations: 24h, 48h, 96h, 192h, 384h...
				$hours = 24 * pow( 2, $new_level - 1 );
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, safe.
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
		return hash( 'sha256', $ip );
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

	public function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
