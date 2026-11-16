<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/**
 * Text merge by uid — docs/spec/versioning.md §4. Pure PHP on parse_blocks() arrays.
 *
 * The builder changes structure on `dev` while clients edit texts on `prod` (Gutenberg). Before a dev
 * version is saved over a newer prod, or published, client texts are carried over:
 * for every xb/text node and every written string prop of a component instance, with b = base, d = dev, p = prod —
 *   p unchanged (p = b) or equal to d → keep d
 *   only prod changed (d = b)          → take p
 *   both changed differently           → conflict: keep d, report it
 */
final class Merge {

	/**
	 * @param array<array<string, mixed>> $dev
	 * @param array<array<string, mixed>> $base
	 * @param array<array<string, mixed>> $prod
	 * @return array{blocks: array<array<string, mixed>>, merged: int, conflicts: list<array{uid: string, dev: string, prod: string}>}
	 */
	public static function three_way( array $dev, array $base, array $prod ): array {
		$base_texts = self::texts( $base );
		$prod_texts = self::texts( $prod );
		$merged     = 0;
		$conflicts  = array();
		$blocks     = self::map_texts(
			$dev,
			static function ( string $uid, string $d ) use ( $base_texts, $prod_texts, &$merged, &$conflicts ): string {
				$p = $prod_texts[ $uid ] ?? null;
				if ( null === $p || $p === $d ) {
					return $d;
				}
				$b = $base_texts[ $uid ] ?? null;
				if ( $b === $p ) {
					return $d;
				}
				if ( $b === $d ) {
					++$merged;
					return $p;
				}
				$conflicts[] = array(
					'uid'  => $uid,
					'dev'  => $d,
					'prod' => $p,
				);
				return $d;
			}
		);
		return array(
			'blocks'    => $blocks,
			'merged'    => $merged,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Rollback that keeps content: structure from $target, texts from $current wherever the node still exists.
	 *
	 * @param array<array<string, mixed>> $target
	 * @param array<array<string, mixed>> $current
	 * @return array<array<string, mixed>>
	 */
	public static function keep_texts( array $target, array $current ): array {
		$texts = self::texts( $current );
		return self::map_texts( $target, static fn ( string $uid, string $content ): string => $texts[ $uid ] ?? $content );
	}

	/**
	 * uid → content of every non-verbatim xb/text, and `uid/prop` → value of every string prop of an instance.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return array<string, string>
	 */
	public static function texts( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/text' === ( $block['blockName'] ?? null ) && is_string( $attrs['uid'] ?? null ) && is_string( $attrs['content'] ?? null ) && ( $attrs['verbatim'] ?? false ) !== true ) {
				$out[ $attrs['uid'] ] = $attrs['content'];
			}
			if ( 'xb/component' === ( $block['blockName'] ?? null ) && is_string( $attrs['uid'] ?? null ) ) {
				foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $name => $value ) {
					if ( is_string( $value ) ) {
						$out[ $attrs['uid'] . '/' . $name ] = $value;
					}
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$out += self::texts( $block['innerBlocks'] );
			}
		}
		return $out;
	}

	/**
	 * @param array<array<string, mixed>>     $blocks
	 * @param callable(string, string): string $fn uid, content → new content
	 * @return array<array<string, mixed>>
	 */
	private static function map_texts( array $blocks, callable $fn ): array {
		foreach ( $blocks as $i => $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/text' === ( $block['blockName'] ?? null ) && is_string( $attrs['uid'] ?? null ) && is_string( $attrs['content'] ?? null ) && ( $attrs['verbatim'] ?? false ) !== true ) {
				$attrs['content']        = $fn( $attrs['uid'], $attrs['content'] );
				$blocks[ $i ]['attrs'] = $attrs;
			}
			if ( 'xb/component' === ( $block['blockName'] ?? null ) && is_string( $attrs['uid'] ?? null ) && is_array( $attrs['props'] ?? null ) ) {
				foreach ( $attrs['props'] as $name => $value ) {
					if ( is_string( $value ) ) {
						$attrs['props'][ $name ] = $fn( $attrs['uid'] . '/' . $name, $value );
					}
				}
				$blocks[ $i ]['attrs'] = $attrs;
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $i ]['innerBlocks'] = self::map_texts( $block['innerBlocks'], $fn );
			}
		}
		return $blocks;
	}
}
