<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Comment_Processor {

	private $heuristics;
	private $ip_manager;
	private $queue;
	private $stats;

	public function __construct(
		SpamAnvil_Heuristics $heuristics,
		SpamAnvil_IP_Manager $ip_manager,
		SpamAnvil_Queue $queue,
		SpamAnvil_Stats $stats
	) {
		$this->heuristics = $heuristics;
		$this->ip_manager = $ip_manager;
		$this->queue      = $queue;
		$this->stats      = $stats;
	}

	/**
	 * Hook: preprocess_comment (priority 10)
	 * Check if IP is blocked before comment is saved.
	 */
	public function check_blocked_ip( $commentdata ) {
		if ( ! $this->is_enabled() ) {
			return $commentdata;
		}

		if ( $this->should_skip_user() ) {
			return $commentdata;
		}

		$ip = $this->ip_manager->get_client_ip();

		if ( $this->ip_manager->is_blocked( $ip ) ) {
			$this->stats->increment( 'ip_blocked' );
			wp_die(
				esc_html__( 'Your comment has been blocked. If you believe this is an error, please contact the site administrator.', 'spamanvil' ),
				esc_html__( 'Comment Blocked', 'spamanvil' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		return $commentdata;
	}

	/**
	 * Hook: pre_comment_approved (priority 99)
	 * Hold comment as pending if in async mode.
	 */
	public function hold_for_review( $approved, $commentdata ) {
		if ( ! $this->is_enabled() ) {
			return $approved;
		}

		if ( $this->should_skip_user() ) {
			return $approved;
		}

		// If already marked as spam or trash, leave it.
		if ( 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		$mode = get_option( 'spamanvil_mode', 'async' );

		if ( 'async' === $mode ) {
			return 0; // Hold as pending.
		}

		return $approved;
	}

	/**
	 * Hook: comment_post (priority 10)
	 * Run heuristics and either auto-block, enqueue, or process immediately.
	 */
	public function process_new_comment( $comment_id, $comment_approved ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip if already spam.
		if ( 'spam' === $comment_approved ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		if ( $this->should_skip_user( $comment->user_id ) ) {
			return;
		}

		// Honeypot: a hidden field only an automated bot would fill. Catch it here,
		// before any heuristic or LLM work, at zero cost. Marked as spam (recoverable
		// from the Spam folder) rather than hard-blocked, in case of a rare false positive.
		if ( $this->honeypot_triggered() ) {
			wp_spam_comment( $comment_id );
			$this->stats->increment( 'honeypot_blocked' );
			$this->stats->increment( 'comments_checked' );
			$this->stats->log_evaluation( array(
				'comment_id'        => $comment_id,
				'score'             => 100,
				'provider'          => 'honeypot',
				'model'             => 'form-trap',
				'reason'            => 'Hidden honeypot field was filled (bot submission)',
				'heuristic_score'   => 100,
				'heuristic_details' => '',
			) );

			$ip = get_comment_author_IP( $comment_id );
			if ( ! empty( $ip ) ) {
				$this->ip_manager->record_spam_attempt( $ip );
			}

			return;
		}

		// Run heuristics.
		$analysis = $this->heuristics->analyze( array(
			'comment_content'      => $comment->comment_content,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_author_url'   => $comment->comment_author_url,
		) );

		$heuristic_threshold = (int) get_option( 'spamanvil_heuristic_auto_spam', 95 );

		// Auto-spam if heuristic score is very high.
		if ( $analysis['score'] >= $heuristic_threshold ) {
			wp_spam_comment( $comment_id );
			$this->stats->increment( 'heuristic_blocked' );
			$this->stats->increment( 'comments_checked' );
			$this->stats->log_evaluation( array(
				'comment_id'        => $comment_id,
				'score'             => $analysis['score'],
				'provider'          => 'heuristics',
				'model'             => 'regex',
				'reason'            => 'Auto-blocked by heuristic analysis',
				'heuristic_score'   => $analysis['score'],
				'heuristic_details' => $this->heuristics->format_for_prompt( $analysis ),
			) );

			$ip = get_comment_author_IP( $comment_id );
			if ( ! empty( $ip ) ) {
				$this->ip_manager->record_spam_attempt( $ip );
			}

			return;
		}

		$mode = get_option( 'spamanvil_mode', 'async' );

		if ( 'async' === $mode ) {
			$this->queue->enqueue( $comment_id, $analysis['score'] );
			spawn_cron();
		} else {
			// Sync mode: process immediately.
			$item = (object) array(
				'id'              => 0,
				'comment_id'      => $comment_id,
				'status'          => 'processing',
				'heuristic_score' => $analysis['score'],
				'attempts'        => 0,
			);
			$this->queue->process_single( $item );
		}
	}

	private function is_enabled() {
		return '1' === get_option( 'spamanvil_enabled', '1' );
	}

	private function should_skip_user( $user_id = null ) {
		if ( '1' !== get_option( 'spamanvil_skip_moderators', '1' ) ) {
			return false;
		}

		if ( null !== $user_id && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			return $user && $user->has_cap( 'moderate_comments' );
		}

		return is_user_logged_in() && current_user_can( 'moderate_comments' );
	}

	/**
	 * Hook: comment_form — output a hidden honeypot field.
	 *
	 * Positioned off-screen and marked aria-hidden with tabindex -1 and autocomplete off,
	 * so real users (and screen readers) never fill it, but form-filling bots do.
	 */
	public function render_honeypot() {
		if ( ! $this->is_enabled() || '1' !== get_option( 'spamanvil_honeypot_enabled', '1' ) ) {
			return;
		}

		echo '<div class="spamanvil-hp" style="position:absolute!important;left:-9999px!important;top:-9999px!important;height:0;width:0;overflow:hidden;" aria-hidden="true">';
		echo '<label for="spamanvil_hp">' . esc_html__( 'Leave this field empty', 'spamanvil' ) . '</label>';
		echo '<input type="text" name="spamanvil_hp" id="spamanvil_hp" value="" tabindex="-1" autocomplete="off">';
		echo '</div>';
	}

	/**
	 * Whether the honeypot field was filled on the current submission.
	 *
	 * @return bool
	 */
	private function honeypot_triggered() {
		if ( '1' !== get_option( 'spamanvil_honeypot_enabled', '1' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading the comment payload during comment submission; no nonce is available here.
		$value = isset( $_POST['spamanvil_hp'] ) ? trim( (string) wp_unslash( $_POST['spamanvil_hp'] ) ) : '';

		return '' !== $value;
	}
}
