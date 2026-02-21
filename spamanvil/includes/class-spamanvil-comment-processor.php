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
}
