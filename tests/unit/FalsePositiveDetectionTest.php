<?php
/**
 * Unit tests for SpamAnvil_Stats::looks_like_false_positive() (v1.18.0).
 *
 * The rule that decides which spam-flagged comments the recovery screen offers back
 * to the admin. It has to be conservative in one direction only: showing a real spam
 * comment costs a moment of the admin's attention, while missing a wrongly flagged
 * reader loses that comment for good once WordPress purges the spam folder.
 */

use PHPUnit\Framework\TestCase;

class FalsePositiveDetectionTest extends TestCase {

	public function test_flags_a_linkless_comment_just_above_the_threshold() {
		$this->assertTrue(
			SpamAnvil_Stats::looks_like_false_positive( 78, 'Muito bom, obrigada pelo texto!', '', 'openrouter' )
		);
	}

	public function test_ignores_anything_carrying_a_link() {
		// Spam exists because of the link; a comment promoting one is not a false positive.
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 78, 'Great post! http://example.com/deals', '', 'openrouter' )
		);
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 78, 'Great post, see www.example.com', '', 'openrouter' )
		);
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 78, 'Nice information, thank you.', 'http://example.com/togel', 'openrouter' )
		);
	}

	public function test_ignores_confident_verdicts() {
		// The language rule alone produced scores in the 70s and low 80s; a model at 90+
		// had other reasons.
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 90, 'Muito bom!', '', 'openrouter' )
		);
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 98, 'Muito bom!', '', 'openrouter' )
		);
	}

	public function test_ignores_scores_below_the_threshold() {
		// These were never marked spam by score in the first place.
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 69, 'Muito bom!', '', 'openrouter' )
		);
	}

	public function test_respects_a_custom_threshold() {
		$this->assertTrue( SpamAnvil_Stats::looks_like_false_positive( 55, 'Obrigada!', '', 'openrouter', 50 ) );
		$this->assertFalse( SpamAnvil_Stats::looks_like_false_positive( 55, 'Obrigada!', '', 'openrouter', 60 ) );
	}

	public function test_ignores_deterministic_verdicts() {
		// A bot filled a field no human can see, or submitted faster than a human can
		// type, or tripped the local regex. None of those are the prompt's doing.
		foreach ( array( 'honeypot', 'timetrap', 'heuristic', 'ratelimit' ) as $provider ) {
			$this->assertFalse(
				SpamAnvil_Stats::looks_like_false_positive( 75, 'Obrigada!', '', $provider ),
				"{$provider} verdicts must not be offered for restoration."
			);
		}
	}

	public function test_matches_the_cached_provider_label() {
		// The verdict cache records "openrouter (cached)".
		$this->assertTrue(
			SpamAnvil_Stats::looks_like_false_positive( 75, 'Obrigada!', '', 'openrouter (cached)' )
		);
		$this->assertFalse(
			SpamAnvil_Stats::looks_like_false_positive( 75, 'Obrigada!', '', 'honeypot (cached)' )
		);
	}

	public function test_handles_empty_and_odd_input() {
		$this->assertTrue( SpamAnvil_Stats::looks_like_false_positive( 70, '', '', '' ) );
		$this->assertFalse( SpamAnvil_Stats::looks_like_false_positive( 0, '', '', '' ) );
		$this->assertFalse( SpamAnvil_Stats::looks_like_false_positive( 75, 'HTTPS://EXAMPLE.COM', '', 'openai' ) );
	}
}
