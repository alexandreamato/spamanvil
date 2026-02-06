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

		// Author URL — strong spam indicator. Most legitimate commenters don't include URLs.
		if ( ! empty( $author_url ) ) {
			$signals[] = array(
				'name'   => 'has_url',
				'score'  => 40,
				'weight' => 15,
				'detail' => 'Author provided a URL (common spam tactic for link promotion)',
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

		// Language mismatch: English comment on non-English site (or vice-versa).
		$lang_mismatch = $this->detect_language_mismatch( $content );
		if ( $lang_mismatch ) {
			$signals[] = array(
				'name'   => 'language_mismatch',
				'score'  => 70,
				'weight' => 20,
				'detail' => $lang_mismatch,
			);
		}

		// Name/email script mismatch (e.g. Cyrillic name + Latin email).
		$script_mismatch = $this->detect_name_email_mismatch( $author, $author_email );
		if ( $script_mismatch ) {
			$signals[] = array(
				'name'   => 'name_email_mismatch',
				'score'  => 65,
				'weight' => 12,
				'detail' => $script_mismatch,
			);
		}

		// Brand-name / keyword-stuffed author name.
		$brand_author = $this->detect_brand_name_author( $author );
		if ( $brand_author ) {
			$signals[] = array(
				'name'   => 'brand_name_author',
				'score'  => 75,
				'weight' => 15,
				'detail' => $brand_author,
			);
		}

		// Generic praise template phrases.
		$generic_praise = $this->detect_generic_praise( $content );
		if ( $generic_praise ) {
			$signals[] = array(
				'name'   => 'generic_praise',
				'score'  => 60,
				'weight' => 10,
				'detail' => $generic_praise,
			);
		}

		// Combo: Author URL + generic/vague comment = almost certainly link spam.
		// The only purpose of these comments is to promote the author's URL.
		if ( ! empty( $author_url ) && $generic_praise ) {
			$signals[] = array(
				'name'   => 'url_with_generic_praise',
				'score'  => 90,
				'weight' => 25,
				'detail' => 'Generic praise + author URL: comment exists solely to promote the link',
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

	/**
	 * Detect language mismatch between comment and site locale.
	 *
	 * Uses common word frequency to estimate if the comment is in English,
	 * then compares against the site's configured language.
	 *
	 * @return string|false Detail string if mismatch detected, false otherwise.
	 */
	private function detect_language_mismatch( $content ) {
		$locale = get_locale();
		$site_is_english = ( strpos( $locale, 'en_' ) === 0 || $locale === 'en' );

		// Common English function words (appear in most English text).
		$english_words = array(
			'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all',
			'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has',
			'have', 'been', 'this', 'that', 'with', 'will', 'your',
			'from', 'they', 'were', 'been', 'said', 'each', 'which',
			'their', 'would', 'there', 'about', 'could', 'other',
		);

		$lower   = strtolower( $content );
		$words   = preg_split( '/[\s\p{P}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
		$total   = count( $words );

		if ( $total < 5 ) {
			return false;
		}

		$english_count = 0;
		foreach ( $words as $word ) {
			if ( in_array( $word, $english_words, true ) ) {
				$english_count++;
			}
		}

		$english_ratio = $english_count / $total;

		// Comment appears to be English (>15% function words).
		$comment_is_english = $english_ratio > 0.15;

		if ( ! $site_is_english && $comment_is_english ) {
			return sprintf( 'Comment appears to be in English but site language is %s', $locale );
		}

		if ( $site_is_english && ! $comment_is_english && $english_ratio < 0.05 ) {
			return sprintf( 'Comment appears to be non-English but site language is %s', $locale );
		}

		return false;
	}

	/**
	 * Detect script mismatch between author name and email.
	 *
	 * E.g. Cyrillic name with a Latin-character email local part.
	 *
	 * @return string|false Detail string if mismatch detected, false otherwise.
	 */
	private function detect_name_email_mismatch( $author, $email ) {
		if ( empty( $author ) || empty( $email ) ) {
			return false;
		}

		$name_has_cyrillic  = (bool) preg_match( '/[\p{Cyrillic}]{2,}/u', $author );
		$name_has_cjk       = (bool) preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]{2,}/u', $author );
		$name_has_arabic     = (bool) preg_match( '/[\p{Arabic}]{2,}/u', $author );

		$local = substr( $email, 0, strpos( $email, '@' ) );
		if ( $local === false ) {
			return false;
		}

		$email_is_latin = (bool) preg_match( '/^[a-zA-Z0-9._\-+]+$/', $local );

		if ( $email_is_latin && ( $name_has_cyrillic || $name_has_cjk || $name_has_arabic ) ) {
			return 'Author name uses non-Latin script but email is Latin-only';
		}

		return false;
	}

	/**
	 * Detect author names that look like brands, keywords, or URL spam.
	 *
	 * E.g. "LK21", "Live Draw SDY", "paito sdy lotto", "Backlink Workshop".
	 *
	 * @return string|false Detail string if detected, false otherwise.
	 */
	private function detect_brand_name_author( $author ) {
		if ( empty( $author ) ) {
			return false;
		}

		$lower = strtolower( trim( $author ) );

		// Author name has 4+ words (unusual for real names).
		$word_count = str_word_count( $lower );
		if ( $word_count >= 4 ) {
			return sprintf( 'Author name has %d words (possible keyword stuffing)', $word_count );
		}

		// Commercial / SEO keywords in author name.
		$commercial_patterns = array(
			'/\b(buy|cheap|discount|free|best|top|online|shop|store|deal|offer|price)\b/i',
			'/\b(seo|backlink|ranking|index|serp|marketing|agency|service)\b/i',
			'/\b(loan|casino|bet|gambling|forex|crypto|trading|invest)\b/i',
			'/\b(pill|drug|pharmacy|viagra|cialis|supplement)\b/i',
			'/\b(download|watch|stream|movie|film|episode)\b/i',
			// Gambling / lottery terms (common in Indonesian/Asian spam).
			'/\b(lotto|togel|paito|slot|jackpot|gacor|prediksi|bocoran|pengeluaran|keluaran|toto|result)\b/i',
			'/\b(live\s*draw|prize|pools?|angka|bandar|agen|taruhan|judi)\b/i',
			// Piracy / streaming terms.
			'/\b(layarkaca|nonton|drakor|bioskop|subtitle|indoxxi|ganool|rebahin)\b/i',
			// Porn / adult terms.
			'/\b(xxx|porn|sex|adult|nude|webcam|onlyfans|escort|hookup)\b/i',
		);

		foreach ( $commercial_patterns as $pattern ) {
			if ( preg_match( $pattern, $author ) ) {
				return 'Author name contains commercial/spam keywords';
			}
		}

		// Alphanumeric mix — check each word individually.
		// Catches "LK21", "Layarkaca21", "X3bet", "Site123" even in multi-word names.
		$author_words = preg_split( '/\s+/', trim( $author ) );
		foreach ( $author_words as $word ) {
			if ( preg_match( '/^[A-Za-z]+\d{2,}$/', $word ) || preg_match( '/^\d+[A-Za-z]+\d*$/', $word ) ) {
				return sprintf( 'Author name contains brand/code pattern: %s', $word );
			}
		}

		return false;
	}

	/**
	 * Detect generic praise template phrases commonly used in spam.
	 *
	 * @return string|false Detail string if detected, false otherwise.
	 */
	private function detect_generic_praise( $content ) {
		if ( mb_strlen( $content ) < 15 ) {
			return false;
		}

		$lower = strtolower( $content );

		$praise_patterns = array(
			'fantastic resource',
			'great article',
			'wonderful post',
			'amazing blog',
			'excellent write-up',
			'keep up the good work',
			'keep up the great work',
			'i\'m definitely bookmarking',
			'i am definitely bookmarking',
			'bookmarking this',
			'everything is very open',
			'clear clarification',
			'clear explanation of',
			'very informative article',
			'i needed to thank you',
			'just wanted to say',
			'stumbled upon this',
			'i stumbled upon',
			'very nice post',
			'nice post. i learn',
			'magnificent website',
			'very good article',
			'incredible article',
			'pretty section of content',
			'certainly a lot to learn',
			'much appreciated',
			'i will bookmark your',
			'looking forward to more',
			'you have made some decent points',
			'you made some good points',
			// Longer template spam phrases.
			'i have been surfing on-line',
			'i have been surfing online',
			'i have been browsing on-line',
			'i have been browsing online',
			'your site might be having browser compatibility',
			'your website might be having browser compatibility',
			'your blog might be having browser compatibility',
			'i do not know who you are but',
			'i think this is among the most',
			'appreciation to my father',
			'i think your site might be having',
			'what a information of un-ambiguity',
			'somebody with a little more knowledge',
			'this is the right site for everyone',
			'this is the right website for everyone',
			'magnificent goods from you',
			'i was recommended this website',
			'i was suggested this website',
			'i just could not go away your website',
			'i just could not leave your site',
		);

		$matches = 0;
		$found   = array();
		foreach ( $praise_patterns as $phrase ) {
			if ( strpos( $lower, $phrase ) !== false ) {
				$matches++;
				$found[] = $phrase;
			}
		}

		if ( $matches >= 2 ) {
			return sprintf( 'Multiple generic praise phrases: %s', implode( ', ', array_slice( $found, 0, 3 ) ) );
		}

		if ( $matches === 1 ) {
			return sprintf( 'Generic spam template phrase: %s', $found[0] );
		}

		return false;
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
