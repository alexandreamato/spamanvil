<?php
/**
 * Unit tests for SpamAnvil_Activator::free_only_chain() (1.19.0): the paid
 * openrouter/auto fallback is removed from chains the plugin itself wrote, and
 * never from a chain the user chose.
 */

use PHPUnit\Framework\TestCase;

class FreeOnlyChainTest extends TestCase {

	public function test_old_default_becomes_free_only() {
		$this->assertSame( 'openrouter/free', SpamAnvil_Activator::free_only_chain( 'openrouter/free, openrouter/auto' ) );
		$this->assertSame( 'openrouter/free', SpamAnvil_Activator::free_only_chain( 'openrouter/free,openrouter/auto' ) );
	}

	public function test_wizard_chain_keeps_its_free_winner() {
		// 1.17.0 wizard: "<free winner>, <original chain>".
		$this->assertSame(
			'qwen/qwen3-8b:free, openrouter/free',
			SpamAnvil_Activator::free_only_chain( 'qwen/qwen3-8b:free, openrouter/free, openrouter/auto' )
		);
	}

	public function test_user_chain_with_a_paid_model_of_their_own_is_left_alone() {
		$this->assertNull( SpamAnvil_Activator::free_only_chain( 'openai/gpt-4o-mini, openrouter/free, openrouter/auto' ) );
	}

	public function test_user_chosen_orders_are_left_alone() {
		$this->assertNull( SpamAnvil_Activator::free_only_chain( 'openrouter/auto' ) );
		$this->assertNull( SpamAnvil_Activator::free_only_chain( 'openrouter/auto, openrouter/free' ) );
		$this->assertNull( SpamAnvil_Activator::free_only_chain( 'openrouter/free, openrouter/auto, anthropic/claude-sonnet-5' ) );
	}

	public function test_already_free_or_empty_is_left_alone() {
		$this->assertNull( SpamAnvil_Activator::free_only_chain( 'openrouter/free' ) );
		$this->assertNull( SpamAnvil_Activator::free_only_chain( '' ) );
	}
}
