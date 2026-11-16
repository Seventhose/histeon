<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Versioning\Merge;

final class MergeTest extends TestCase {

	/** @return array<string, mixed> */
	private static function text( string $uid, string $content ): array {
		return array(
			'blockName'    => 'xb/text',
			'attrs'        => array(
				'uid'     => $uid,
				'content' => $content,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function el( string $uid, array $children ): array {
		return array(
			'blockName'    => 'xb/element',
			'attrs'        => array(
				'uid' => $uid,
				'tag' => 'div',
			),
			'innerBlocks'  => $children,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $children ), null ),
		);
	}

	public function test_three_way(): void {
		$base = array( self::el( 'd1', array( self::text( 'a', 'A' ), self::text( 'b', 'B' ), self::text( 'c', 'C' ) ) ) );
		// Dev restructured and changed b; prod (client) changed a and b.
		$dev  = array( self::el( 'd1', array( self::text( 'c', 'C' ), self::text( 'a', 'A' ), self::text( 'b', 'B dev' ), self::text( 'n', 'new' ) ) ) );
		$prod = array( self::el( 'd1', array( self::text( 'a', 'A client' ), self::text( 'b', 'B client' ), self::text( 'c', 'C' ) ) ) );

		$result = Merge::three_way( $dev, $base, $prod );
		$texts  = Merge::texts( $result['blocks'] );
		$this->assertSame( 'A client', $texts['a'], 'only prod changed: take prod' );
		$this->assertSame( 'B dev', $texts['b'], 'both changed: keep dev' );
		$this->assertSame( 'new', $texts['n'] );
		$this->assertSame( 1, $result['merged'] );
		$this->assertSame( array( array( 'uid' => 'b', 'dev' => 'B dev', 'prod' => 'B client' ) ), $result['conflicts'] );
		$this->assertSame( 'c', $result['blocks'][0]['innerBlocks'][0]['attrs']['uid'], 'structure comes from dev' );
	}

	public function test_identical_changes_are_not_conflicts(): void {
		$result = Merge::three_way( array( self::text( 'a', 'X' ) ), array( self::text( 'a', 'A' ) ), array( self::text( 'a', 'X' ) ) );
		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( 0, $result['merged'] );
	}

	public function test_props_of_an_instance_merge_like_texts(): void {
		$instance = static fn ( array $props ): array => array(
			'blockName'    => 'xb/component',
			'attrs'        => array( 'uid' => 'libro001', 'ref' => 'articolo/libro', 'props' => $props ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$base   = array( $instance( array( 'title' => 'Base', 'author' => 'Base', 'width' => 3 ) ) );
		$dev    = array( $instance( array( 'title' => 'Base', 'author' => 'Dev', 'width' => 3 ) ) );
		$prod   = array( $instance( array( 'title' => 'Gutenberg', 'author' => 'Base', 'width' => 3 ) ) );
		$result = Merge::three_way( $dev, $base, $prod );
		$this->assertSame( array( 'title' => 'Gutenberg', 'author' => 'Dev', 'width' => 3 ), $result['blocks'][0]['attrs']['props'] );
		$this->assertSame( 1, $result['merged'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_keep_texts_for_rollback(): void {
		$old     = array( self::el( 'd1', array( self::text( 'a', 'old A' ), self::text( 'gone', 'G' ) ) ) );
		$current = array( self::text( 'a', 'current A' ), self::text( 'z', 'Z' ) );
		$texts   = Merge::texts( Merge::keep_texts( $old, $current ) );
		$this->assertSame( array( 'a' => 'current A', 'gone' => 'G' ), $texts );
	}
}
