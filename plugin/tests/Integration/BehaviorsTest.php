<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Histeon's behaviour script is loaded only where a page uses it — docs/spec/interactions.md. */
final class BehaviorsTest extends IntegrationTestCase {

	protected function tearDown(): void {
		wp_dequeue_script( 'xb-behaviors' );
		wp_dequeue_style( 'xb-behaviors' );
		parent::tearDown();
	}

	public function test_loaded_only_with_a_behaviour(): void {
		$this->render( array( $this->el( 'div', array( 'class' => 'x' ), array( $this->text( 'niente' ) ) ) ) );
		$this->assertFalse( wp_script_is( 'xb-behaviors', 'enqueued' ) );
		$html = $this->render( array( $this->el( 'div', array( 'class' => 'tabs', 'data-xb-tabs' => true ), array() ) ) );
		$this->assertStringContainsString( 'data-xb-tabs', $html );
		$this->assertTrue( wp_script_is( 'xb-behaviors', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'xb-behaviors', 'enqueued' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'xb-behaviors', 'strategy' ) );
	}

	public function test_loaded_for_the_video_block_of_an_article(): void {
		$this->render( array( $this->el( 'a', array( 'class' => 'xb-art-videos-elenco', 'href' => 'https://example.org/' ), array( $this->text( 'non è il blocco' ) ) ) ) );
		$this->assertFalse( wp_script_is( 'xb-behaviors', 'enqueued' ), 'a class that only starts the same way is not the block' );
		$this->render( array( $this->el( 'a', array( 'class' => 'xb-art-video', 'href' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ), array( $this->text( 'Concerto' ) ) ) ) );
		$this->assertTrue( wp_script_is( 'xb-behaviors', 'enqueued' ) );
	}
}
