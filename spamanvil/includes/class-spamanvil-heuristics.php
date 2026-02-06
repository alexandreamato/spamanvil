<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpamAnvil_Heuristics {

	private $spam_words = array();

	public function __construct() {
		$words_text = get_option( 'spamanvil_spam_words', '' );
		if ( ! empty( $words_text ) ) {
			$this->spam_words = array_filter( array_map( 'trim', explode( "\n", strtolower( $words_text ) ) ) );
		}
	}

	public function analyze( $comment_data ) {
		$content      = isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '';
		$author       = isset( $comment_data['comment_author'] ) ? $comment_data['comment_author'] : '';
		$author_email = isset( $comment_data['comment_author_email'] ) ? $comment_data['comment_author_email'] : '';
		$author_url   = isset( $comment_data['comment_author_url'] ) ? $comment_data['comment_author_url'] : '';

		$signals = array();

		// URL analysis.
		$urls      = $this->extract_urls( $content );
		$url_count = count( $urls );
		if ( $url_count > 0 ) {
			$url_score = min( 100, $url_count * 15 );
			$signals[] = array(
				'name'   => 'url_count',
				'score'  => $url_score,
				'weight' => 25,
				'detail' => sprintf( '%d URL(s) found in comment', $url_count ),
			);
		}

		// Spam words.
		$found_words = $this->check_spam_words( $content . ' ' . $author . ' ' . $author_url );
		if ( ! empty( $found_words ) ) {
			$word_score = min( 100, count( $found_words ) * 25 );
			$signals[]  = array(
				'name'   => 'spam_words',
				'score'  => $word_score,
				'weight' => 30,
				'detail' => sprintf( 'Spam words found: %s', implode( ', ', array_slice( $found_words, 0, 5 ) ) ),
			);
		}

		// Character repetition.
		$repetition_ratio = $this->check_repetition( $content );
		if ( $repetition_ratio > 0.3 ) {
			$rep_score = min( 100, (int) ( $repetition_ratio * 150 ) );
			$signals[] = array(
				'name'   => 'repetition',
				'score'  => $rep_score,
				'weight' => 10,
				'detail' => sprintf( 'High character repetition: %.0f%%', $repetition_ratio * 100 ),
			);
		}

		// Comment length.
		$length = mb_strlen( $content );
		if ( $length < 5 ) {
			$signals[] = array(
				'name'   => 'too_short',
				'score'  => 40,
				'weight' => 10,
				'detail' => sprintf( 'Very short comment: %d characters', $length ),
			);
		} elseif ( $length > 5000 ) {
			$signals[] = array(
				'name'   => 'too_long',
				'score'  => 30,
				'weight' => 5,
				'detail' => sprintf( 'Very long comment: %d characters', $length ),
			);
		}

		// Email domain analysis.
		$email_score = $this->analyze_email( $author_email );
		if ( $email_score > 0 ) {
			$signals[] = array(
				'name'   => 'email_suspicious',
				'score'  => $email_score,
				'weight' => 10,
				'detail' => 'Suspicious email domain',
			);
		}

		// Author URL.
		if ( ! empty( $author_url ) ) {
			$signals[] = array(
				'name'   => 'has_url',
				'score'  => 20,
				'weight' => 5,
				'detail' => 'Author provided a URL',
			);
		}

		// Author name looks like URL or has special characters.
		if ( preg_match( '/https?:|www\.|\.com|\.net|\.org/i', $author ) ) {
			$signals[] = array(
				'name'   => 'author_name_url',
				'score'  => 80,
				'weight' => 15,
				'detail' => 'Author name contains URL-like text',
			);
		}

		// All caps detection.
		$upper_ratio = $this->get_uppercase_ratio( $content );
		if ( $upper_ratio > 0.7 && $length > 20 ) {
			$signals[] = array(
				'name'   => 'all_caps',
				'score'  => 50,
				'weight' => 5,
				'detail' => sprintf( 'Excessive caps: %.0f%%', $upper_ratio * 100 ),
			);
		}

		// Prompt injection detection - boost heuristic score if injection patterns found.
		$injection_score = $this->detect_prompt_injection( $content );
		if ( $injection_score > 0 ) {
			$signals[] = array(
				'name'   => 'prompt_injection',
				'score'  => $injection_score,
				'weight' => 20,
				'detail' => 'Potential prompt injection patterns detected',
			);
		}

		$total_score = $this->calculate_weighted_score( $signals );
		$total_score = apply_filters( 'spamanvil_heuristic_score', $total_score, $signals, $comment_data );

		return array(
			'score'   => $total_score,
			'signals' => $signals,
		);
	}

	public function format_for_prompt( $analysis ) {
		if ( empty( $analysis['signals'] ) ) {
			return 'No suspicious patterns detected by pre-analysis.';
		}

		$lines = array();
		foreach ( $analysis['signals'] as $signal ) {
			$lines[] = sprintf( '- %s (signal score: %d/100)', $signal['detail'], $signal['score'] );
		}

		return implode( "\n", $lines );
	}

	private function extract_urls( $content ) {
		$urls = wp_extract_urls( $content );

		// Also catch URLs without protocol.
		preg_match_all( '/\b(?:www\.)[a-zA-Z0-9\-]+(?:\.[a-zA-Z]{2,})+/i', $content, $matches );
		if ( ! empty( $matches[0] ) ) {
			$urls = array_merge( $urls, $matches[0] );
		}

		return array_unique( $urls );
	}

	private function check_spam_words( $text ) {
		$text  = strtolower( $text );
		$found = array();

		foreach ( $this->spam_words as $word ) {
			if ( empty( $word ) ) {
				continue;
			}
			if ( mb_stripos( $text, $word ) !== false ) {
				$found[] = $word;
			}
		}

		return $found;
	}

	private function check_repetition( $content ) {
		if ( mb_strlen( $content ) < 10 ) {
			return 0.0;
		}

		$chars      = mb_str_split( $content );
		$total      = count( $chars );
		$repeated   = 0;
		$prev       = '';

		foreach ( $chars as $char ) {
			if ( $char === $prev ) {
				$repeated++;
			}
			$prev = $char;
		}

		return $repeated / $total;
	}

	private function analyze_email( $email ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return 30;
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

		// Disposable email domains (common ones).
		$disposable = array(
			'mailinator.com', 'guerrillamail.com', 'tempmail.com',
			'throwaway.email', 'yopmail.com', 'sharklasers.com',
			'guerrillamailblock.com', 'grr.la', 'dispostable.com',
			'trashmail.com', 'temp-mail.org',
		);

		if ( in_array( $domain, $disposable, true ) ) {
			return 70;
		}

		// Very long random-looking local part.
		$local = substr( $email, 0, strpos( $email, '@' ) );
		if ( strlen( $local ) > 20 && preg_match( '/[0-9]{5,}/', $local ) ) {
			return 40;
		}

		return 0;
	}

	private function get_uppercase_ratio( $content ) {
		$alpha = preg_replace( '/[^a-zA-Z]/', '', $content );

		if ( strlen( $alpha ) < 10 ) {
			return 0.0;
		}

		$upper = preg_replace( '/[^A-Z]/', '', $alpha );

		return strlen( $upper ) / strlen( $alpha );
	}

	/**
	 * Detect common prompt injection patterns in comment content.
	 *
	 * Returns a score 0-100 indicating likelihood of prompt injection.
	 */
	private function detect_prompt_injection( $content ) {
		$lower = strtolower( $content );
		$score = 0;

		$injection_patterns = array(
			// Direct instruction override attempts.
			'/ignore\s+(all\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?|directives?)/i',
			'/disregard\s+(all\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?)/i',
			'/forget\s+(all\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?)/i',
			// Role-play / persona manipulation.
			'/you\s+are\s+now\s+(a|an|the)\s/i',
			'/act\s+as\s+(a|an|the|if)\s/i',
			'/pretend\s+(you\s+are|to\s+be)\s/i',
			'/new\s+instructions?:/i',
			'/system\s*:\s/i',
			// Output manipulation.
			'/respond\s+with\s+(only|just|exactly)\s/i',
			'/output\s+(only|just|exactly)\s/i',
			'/your\s+(new\s+)?task\s+is\s/i',
			'/return\s+a?\s*score\s+of\s+\d/i',
			// JSON manipulation attempts.
			'/"score"\s*:\s*\d/i',
			'/\{\s*"score"\s*:/i',
		);

		foreach ( $injection_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				$score += 30;
			}
		}

		return min( 100, $score );
	}

	private function calculate_weighted_score( $signals ) {
		if ( empty( $signals ) ) {
			return 0;
		}

		$weighted_sum   = 0;
		$total_weight   = 0;

		foreach ( $signals as $signal ) {
			$weighted_sum += $signal['score'] * $signal['weight'];
			$total_weight += $signal['weight'];
		}

		if ( $total_weight === 0 ) {
			return 0;
		}

		return min( 100, (int) round( $weighted_sum / $total_weight ) );
	}
}
