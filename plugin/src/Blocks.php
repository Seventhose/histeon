<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

/** Helpers for block arrays as produced by parse_blocks(). */
final class Blocks {

	/**
	 * Normalizes arrays to the exact shape serialize_blocks() expects.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}>
	 */
	public static function shape( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$inner = is_array( $block['innerBlocks'] ?? null ) ? self::shape( $block['innerBlocks'] ) : array();
			$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null;
			// XB blocks are dynamic and hold no static HTML: one placeholder per child, always in sync.
			$xb    = null !== $name && str_starts_with( $name, 'xb/' );
			$out[] = array(
				'blockName'    => $name,
				'attrs'        => is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				'innerBlocks'  => $inner,
				'innerHTML'    => $xb ? '' : ( is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '' ),
				'innerContent' => ! $xb && is_array( $block['innerContent'] ?? null )
					? array_values( array_filter( $block['innerContent'], static fn ( mixed $c ): bool => null === $c || is_string( $c ) ) )
					: array_fill( 0, count( $inner ), null ),
			);
		}
		return $out;
	}

	/** @param array<array<string, mixed>> $blocks */
	public static function serialize( array $blocks ): string {
		return serialize_blocks( self::shape( $blocks ) );
	}
}
