<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Ai\Operations;

/** `{#loop …}{:else}…{/loop}` (D1): the else branch shows when the loop has no items, and stays last. */
final class LoopElseTest extends IntegrationTestCase {

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<array<string, mixed>> $empty
	 * @return array<string, mixed>
	 */
	private function loop( string $expr, array $items, array $empty ): array {
		return $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => $expr ), 'as' => 'e' ), array_merge( $items, array( $this->block( 'xb/branch', array(), $empty ) ) ) );
	}

	public function test_the_else_branch_shows_only_without_items(): void {
		$this->set_option( \XB\Render\Sources::OPTION, array( 'zq_pieni' => array( 'type' => 'json', 'data' => array( array( 'nome' => 'Uno' ), array( 'nome' => 'Due' ) ) ), 'zq_vuoti' => array( 'type' => 'json', 'data' => array() ) ) );
		$full  = $this->render( array( $this->loop( 'data.zq_pieni', array( $this->el( 'p', array(), array( $this->text( '[{e.nome}]' ) ) ) ), array( $this->el( 'p', array(), array( $this->text( 'Niente da mostrare' ) ) ) ) ) ) );
		$empty = $this->render( array( $this->loop( 'data.zq_vuoti', array( $this->el( 'p', array(), array( $this->text( '[{e.nome}]' ) ) ) ), array( $this->el( 'p', array(), array( $this->text( 'Niente da mostrare' ) ) ) ) ) ) );
		$this->assertStringContainsString( '[Uno]', $full );
		$this->assertStringContainsString( '[Due]', $full );
		$this->assertStringNotContainsString( 'Niente da mostrare', $full );
		$this->assertStringContainsString( 'Niente da mostrare', $empty );
		$this->assertStringNotContainsString( '[', $empty );
	}

	public function test_an_agent_inserting_into_a_loop_lands_before_its_else(): void {
		$blocks = parse_blocks( $this->markup( array( $this->loop( 'data.x', array( $this->el( 'p', array(), array( $this->text( 'voce' ) ), 'zqitem01' ) ), array( $this->text( 'vuoto' ) ) ) ) ) );
		$loop   = (string) $blocks[0]['attrs']['uid'];
		$after  = Operations::apply( $blocks, array( array( 'op' => 'insert_html', 'parent' => $loop, 'html' => '<p>nuova</p>' ) ) );
		$names  = array_column( $after[0]['innerBlocks'], 'blockName' );
		$this->assertSame( array( 'xb/element', 'xb/element', 'xb/branch' ), $names );
	}
}
