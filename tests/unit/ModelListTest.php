<?php
/**
 * Unit tests for SpamAnvil_OpenAI_Compatible::parse_models_response() — the model-picker
 * list parser (v1.4.0). Pure function, no network.
 */

use PHPUnit\Framework\TestCase;

class ModelListTest extends TestCase {

	private function provider( $slug = 'openrouter' ) {
		return new SpamAnvil_OpenAI_Compatible( 'key', 'model', '', $slug );
	}

	public function test_openai_style_list() {
		$body   = json_encode( array( 'data' => array( array( 'id' => 'gpt-4o-mini' ), array( 'id' => 'gpt-4o' ) ) ) );
		$models = $this->provider( 'openai' )->parse_models_response( $body );

		$this->assertIsArray( $models );
		$this->assertCount( 2, $models );
		$this->assertContains( 'gpt-4o-mini', array_column( $models, 'id' ) );
	}

	public function test_openrouter_marks_free_and_sorts_free_first() {
		$body = json_encode( array(
			'data' => array(
				array( 'id' => 'paid/model', 'name' => 'Paid', 'context_length' => 8000, 'pricing' => array( 'prompt' => '0.0005', 'completion' => '0.001' ) ),
				array( 'id' => 'free/model:free', 'name' => 'Free', 'context_length' => 16000, 'pricing' => array( 'prompt' => '0', 'completion' => '0' ) ),
			),
		) );

		$models = $this->provider( 'openrouter' )->parse_models_response( $body );

		$this->assertCount( 2, $models );
		$this->assertSame( 'free/model:free', $models[0]['id'], 'Free models sort first.' );
		$this->assertTrue( $models[0]['free'] );
		$this->assertSame( 16000, $models[0]['context'] );
		$this->assertFalse( $models[1]['free'] );
	}

	public function test_entries_without_id_are_skipped() {
		$body   = json_encode( array( 'data' => array( array( 'name' => 'no id' ), array( 'id' => 'good/model' ) ) ) );
		$models = $this->provider()->parse_models_response( $body );

		$this->assertCount( 1, $models );
		$this->assertSame( 'good/model', $models[0]['id'] );
	}

	public function test_unexpected_shape_returns_error() {
		$this->assertTrue( is_wp_error( $this->provider()->parse_models_response( 'not json' ) ) );
		$this->assertTrue( is_wp_error( $this->provider()->parse_models_response( json_encode( array( 'nope' => 1 ) ) ) ) );
	}
}
