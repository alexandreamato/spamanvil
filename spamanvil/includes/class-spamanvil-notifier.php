<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart email notifications (1.13.0).
 *
 * WordPress emails "a comment is awaiting moderation" the moment a comment is
 * held — before SpamAnvil has evaluated it. On a spammed site that means one
 * email per spam attempt, all of them pointless: the classifier removes the
 * comment minutes later. This class inverts the order:
 *
 *   - `smart` (default): suppress WordPress' insert-time notifications for
 *     comments SpamAnvil will evaluate, then notify AFTER the verdict —
 *     spam → silence; ham → post-author notification (respecting
 *     `comments_notify`); classification permanently failed → the moderation
 *     email, because a human decision is genuinely needed.
 *   - `digest`: no per-comment email at all; one daily summary instead.
 *   - `off`: WordPress default behavior, untouched.
 *
 * Comments SpamAnvil skips (e.g. logged-in moderators) keep their normal
 * notifications in every mode.
 */
class SpamAnvil_Notifier {

	/**
	 * True while this class re-sends a notification on purpose after a verdict,
	 * so the suppression filters below let that send pass through.
	 *
	 * @var bool
	 */
	private static $sending_deferred = false;

	/**
	 * Current email mode, validated. Defaults to 'smart' for everyone.
	 *
	 * @return string 'off' | 'smart' | 'digest'
	 */
	public static function get_mode() {
		$mode = get_option( 'spamanvil_email_mode', 'smart' );
		return in_array( $mode, array( 'off', 'smart', 'digest' ), true ) ? $mode : 'smart';
	}

	/**
	 * Filter: notify_moderator — suppress the insert-time moderation email for
	 * comments SpamAnvil is about to evaluate.
	 *
	 * @param bool $maybe_notify Whether WordPress would send.
	 * @param int  $comment_id   Comment ID.
	 * @return bool
	 */
	public static function filter_moderator_notify( $maybe_notify, $comment_id ) {
		return self::maybe_suppress( $maybe_notify, $comment_id );
	}

	/**
	 * Filter: notify_post_author — suppress the insert-time post-author email.
	 * Matters mostly in Open Mode, where comments are approved (and would be
	 * announced) instantly, before the classifier has seen them.
	 *
	 * @param bool $maybe_notify Whether WordPress would send.
	 * @param int  $comment_id   Comment ID.
	 * @return bool
	 */
	public static function filter_postauthor_notify( $maybe_notify, $comment_id ) {
		return self::maybe_suppress( $maybe_notify, $comment_id );
	}

	/**
	 * Shared suppression decision for both filters.
	 *
	 * @param bool $maybe_notify Whether WordPress would send.
	 * @param int  $comment_id   Comment ID.
	 * @return bool
	 */
	private static function maybe_suppress( $maybe_notify, $comment_id ) {
		if ( self::$sending_deferred ) {
			return $maybe_notify; // Deliberate post-verdict send — let it through.
		}

		if ( '1' !== get_option( 'spamanvil_enabled', '1' ) || 'off' === self::get_mode() ) {
			return $maybe_notify;
		}

		// Comments SpamAnvil won't evaluate keep their normal notifications.
		$comment = get_comment( $comment_id );
		if ( ! $comment || self::is_skipped_user( (int) $comment->user_id ) ) {
			return $maybe_notify;
		}

		return false;
	}

	/**
	 * Mirror of SpamAnvil_Comment_Processor::should_skip_user() for a stored comment.
	 *
	 * @param int $user_id Comment author's user ID (0 for guests).
	 * @return bool
	 */
	private static function is_skipped_user( $user_id ) {
		if ( '1' !== get_option( 'spamanvil_skip_moderators', '1' ) ) {
			return false;
		}

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			return $user && $user->has_cap( 'moderate_comments' );
		}

		return false;
	}

	/**
	 * Post-verdict: the comment is ham and approved — tell the post author now.
	 * wp_new_comment_notify_postauthor() itself honors `comments_notify` and only
	 * mails for approved comments, so this is safe to call unconditionally.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function send_postauthor( $comment_id ) {
		if ( 'smart' !== self::get_mode() || '1' !== get_option( 'spamanvil_enabled', '1' ) ) {
			return;
		}

		self::$sending_deferred = true;
		wp_new_comment_notify_postauthor( $comment_id );
		self::$sending_deferred = false;
	}

	/**
	 * Post-verdict: classification failed for good (max retries) — the comment
	 * stays pending and a human decision is genuinely needed, so send the
	 * moderation email WordPress would have sent at insert time.
	 * wp_new_comment_notify_moderator() honors `moderation_notify` and only mails
	 * for comments still pending.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function send_moderator( $comment_id ) {
		if ( 'smart' !== self::get_mode() || '1' !== get_option( 'spamanvil_enabled', '1' ) ) {
			return;
		}

		self::$sending_deferred = true;
		wp_new_comment_notify_moderator( $comment_id );
		self::$sending_deferred = false;
	}

	/**
	 * Cron: daily digest email (digest mode only). One summary instead of
	 * per-comment notifications; skipped entirely when nothing happened.
	 */
	public static function send_digest() {
		if ( 'digest' !== self::get_mode() || '1' !== get_option( 'spamanvil_enabled', '1' ) ) {
			return;
		}

		$stats   = new SpamAnvil_Stats();
		$summary = $stats->get_summary( 1 ); // Since yesterday.
		$pending = (int) wp_count_comments()->moderated;

		$spam_blocked = (int) $summary['spam_detected']
			+ (int) $summary['heuristic_blocked']
			+ (int) $summary['ip_blocked']
			+ (int) ( $summary['honeypot_blocked'] ?? 0 )
			+ (int) ( $summary['timetrap_blocked'] ?? 0 );

		if ( 0 === $spam_blocked && 0 === (int) $summary['ham_approved'] && 0 === $pending ) {
			return; // Quiet day — no email.
		}

		$body = self::build_digest_body(
			get_bloginfo( 'name' ),
			$spam_blocked,
			(int) $summary['ham_approved'],
			$pending,
			admin_url( 'edit-comments.php?comment_status=moderated' )
		);

		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: 1: site name, 2: number of spam comments blocked */
				__( '[%1$s] SpamAnvil daily digest — %2$d spam blocked', 'spamanvil' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$spam_blocked
			),
			$body
		);
	}

	/**
	 * Plain-text digest body. Pure and static (unit-tested).
	 *
	 * @param string $site_name      Site name.
	 * @param int    $spam_blocked   Spam comments blocked in the period.
	 * @param int    $ham_approved   Legitimate comments approved.
	 * @param int    $pending        Comments currently awaiting moderation.
	 * @param string $moderation_url Admin URL of the moderation queue.
	 * @return string
	 */
	public static function build_digest_body( $site_name, $spam_blocked, $ham_approved, $pending, $moderation_url ) {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: site name */
			__( 'SpamAnvil activity on %s in the last 24 hours:', 'spamanvil' ),
			$site_name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of spam comments blocked */
			__( '- Spam blocked: %d', 'spamanvil' ),
			(int) $spam_blocked
		);
		$lines[] = sprintf(
			/* translators: %d: number of legitimate comments approved */
			__( '- Legitimate comments approved: %d', 'spamanvil' ),
			(int) $ham_approved
		);

		if ( (int) $pending > 0 ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: number of comments awaiting moderation */
				__( '- Awaiting your moderation: %d', 'spamanvil' ),
				(int) $pending
			);
			$lines[] = $moderation_url;
		}

		$lines[] = '';
		$lines[] = __( 'You receive this summary instead of one email per comment (SpamAnvil email mode: Daily digest).', 'spamanvil' );

		return implode( "\n", $lines );
	}
}
