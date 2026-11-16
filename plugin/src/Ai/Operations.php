<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Blocks;
use XB\Security\MarkupPolicy;
use XB\Spec;

/**
 * Structured edits of an XB tree by uid — the format agents use (docs/spec/ai.md).
 *
 *   {op: "set_text",        uid, content}
 *   {op: "set_attribute",   uid, name, value}        value null removes it, true makes it boolean
 *   {op: "set_tag",         uid, tag}
 *   {op: "set_label",       uid, label}
 *   {op: "insert_html",     parent?, index?, html}   plain HTML, converted with the WordPress HTML API
 *   {op: "remove",          uid}
 *   {op: "move",            uid, parent?, index?}
 *
 * Operations are validated one by one; the whole batch is rejected if any fails. The result is saved as a
 * dev commit by the caller, where the sanitizer applies the caller's permissions.
 */
final class Operations {

	public const MAX = 100;

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param list<mixed>                 $operations
	 * @return array<array<string, mixed>>
	 */
	public static function apply( array $blocks, array $operations ): array {
		if ( count( $operations ) > self::MAX ) {
			throw new \InvalidArgumentException( sprintf( /* translators: %d: maximum number of operations */ __( 'Al massimo %d operazioni per volta.', 'xb' ), self::MAX ) );
		}
		foreach ( $operations as $i => $op ) {
			if ( ! is_array( $op ) || ! is_string( $op['op'] ?? null ) ) {
				throw new \InvalidArgumentException( sprintf( /* translators: %d: operation index */ __( 'Operazione %d non valida.', 'xb' ), $i ) );
			}
			try {
				$blocks = self::one( $blocks, $op );
			} catch ( \InvalidArgumentException $e ) {
				throw new \InvalidArgumentException( sprintf( /* translators: 1: operation index, 2: operation name, 3: error */ __( 'Operazione %1$d (%2$s): %3$s', 'xb' ), $i, $op['op'], $e->getMessage() ) );
			}
		}
		return $blocks;
	}

	/**
	 * Compact, readable view of a tree for agents: no innerHTML/innerContent noise.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function outline( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null;
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( null === $name ) {
				continue;
			}
			$node = array( 'type' => str_starts_with( $name, 'xb/' ) ? substr( $name, 3 ) : $name ) + $attrs;
			if ( 'xb/passthrough' !== $name && is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
				$node['children'] = self::outline( $block['innerBlocks'] );
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param array<string, mixed>        $op
	 * @return array<array<string, mixed>>
	 */
	private static function one( array $blocks, array $op ): array {
		$uid = is_string( $op['uid'] ?? null ) ? $op['uid'] : '';
		switch ( $op['op'] ) {
			case 'set_text':
				return self::edit(
					$blocks,
					$uid,
					static function ( array $block ) use ( $op ): array {
						if ( 'xb/text' !== $block['blockName'] || ! is_string( $op['content'] ?? null ) ) {
							throw new \InvalidArgumentException( __( 'serve un nodo di testo e un contenuto stringa', 'xb' ) );
						}
						$block['attrs']['content'] = $op['content'];
						return $block;
					}
				);
			case 'set_attribute':
				return self::edit(
					$blocks,
					$uid,
					static function ( array $block ) use ( $op ): array {
						$name  = is_string( $op['name'] ?? null ) ? $op['name'] : '';
						$value = $op['value'] ?? null;
						if ( 'xb/element' !== $block['blockName'] || 1 !== preg_match( MarkupPolicy::ATTRIBUTE_PATTERN, $name ) || str_starts_with( $name, 'xb:' ) ) {
							throw new \InvalidArgumentException( __( 'attributo non valido o nodo non elemento', 'xb' ) );
						}
						if ( null !== $value && true !== $value && ! is_string( $value ) ) {
							throw new \InvalidArgumentException( __( 'il valore deve essere stringa, true o null', 'xb' ) );
						}
						$attributes = is_array( $block['attrs']['attributes'] ?? null ) ? $block['attrs']['attributes'] : array();
						if ( null === $value ) {
							unset( $attributes[ $name ] );
						} else {
							$attributes[ $name ] = $value;
						}
						if ( array() === $attributes ) {
							unset( $block['attrs']['attributes'] );
						} else {
							$block['attrs']['attributes'] = $attributes;
						}
						return $block;
					}
				);
			case 'set_tag':
				return self::edit(
					$blocks,
					$uid,
					static function ( array $block ) use ( $op ): array {
						$tag = is_string( $op['tag'] ?? null ) ? $op['tag'] : '';
						if ( 'xb/element' !== $block['blockName'] || 1 !== preg_match( MarkupPolicy::TAG_PATTERN, $tag ) ) {
							throw new \InvalidArgumentException( __( 'tag non valido o nodo non elemento', 'xb' ) );
						}
						if ( in_array( $tag, Spec::VOID_ELEMENTS, true ) && array() !== ( $block['innerBlocks'] ?? array() ) ) {
							throw new \InvalidArgumentException( __( 'un elemento vuoto non può avere figli', 'xb' ) );
						}
						$block['attrs']['tag'] = $tag;
						return $block;
					}
				);
			case 'set_label':
				return self::edit(
					$blocks,
					$uid,
					static function ( array $block ) use ( $op ): array {
						$label = is_string( $op['label'] ?? null ) ? trim( str_replace( array( '{', '}' ), '', $op['label'] ) ) : '';
						if ( ! in_array( $block['blockName'], array( 'xb/element', 'xb/component' ), true ) ) {
							throw new \InvalidArgumentException( __( 'solo elementi e componenti hanno un nome', 'xb' ) );
						}
						if ( '' === $label ) {
							unset( $block['attrs']['label'] );
						} else {
							$block['attrs']['label'] = mb_substr( $label, 0, 80 );
						}
						return $block;
					}
				);
			case 'insert_html':
				$html = is_string( $op['html'] ?? null ) ? $op['html'] : '';
				return self::insert( $blocks, $op['parent'] ?? null, $op['index'] ?? null, HtmlImport::to_blocks( $html ) );
			case 'remove':
				[ $blocks, $removed ] = self::take( $blocks, $uid );
				if ( null === $removed ) {
					throw new \InvalidArgumentException( __( 'uid non trovato', 'xb' ) );
				}
				return $blocks;
			case 'move':
				[ $without, $moved ] = self::take( $blocks, $uid );
				if ( null === $moved ) {
					throw new \InvalidArgumentException( __( 'uid non trovato', 'xb' ) );
				}
				if ( is_string( $op['parent'] ?? null ) && self::contains( array( $moved ), $op['parent'] ) ) {
					throw new \InvalidArgumentException( __( 'non si sposta un nodo dentro sé stesso', 'xb' ) );
				}
				return self::insert( $without, $op['parent'] ?? null, $op['index'] ?? null, array( $moved ) );
		}
		throw new \InvalidArgumentException( __( 'operazione sconosciuta', 'xb' ) );
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param callable(array<string, mixed>): array<string, mixed> $fn
	 * @return array<array<string, mixed>>
	 */
	private static function edit( array $blocks, string $uid, callable $fn ): array {
		$found = false;
		$out   = self::map( $blocks, $uid, $fn, $found );
		if ( ! $found ) {
			throw new \InvalidArgumentException( __( 'uid non trovato', 'xb' ) );
		}
		return $out;
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param callable(array<string, mixed>): array<string, mixed> $fn
	 * @return array<array<string, mixed>>
	 */
	private static function map( array $blocks, string $uid, callable $fn, bool &$found ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ( $block['attrs']['uid'] ?? null ) === $uid && str_starts_with( (string) ( $block['blockName'] ?? '' ), 'xb/' ) ) {
				$blocks[ $i ] = $fn( $block );
				$found        = true;
				return $blocks;
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && 'xb/passthrough' !== ( $block['blockName'] ?? null ) ) {
				$blocks[ $i ]['innerBlocks'] = self::map( $block['innerBlocks'], $uid, $fn, $found );
				if ( $found ) {
					return $blocks;
				}
			}
		}
		return $blocks;
	}

	/**
	 * Removes a node; returns [tree, node|null].
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return array{0: array<array<string, mixed>>, 1: array<string, mixed>|null}
	 */
	private static function take( array $blocks, string $uid ): array {
		foreach ( $blocks as $i => $block ) {
			// Only Histeon nodes, as map(): a block of another plugin with a uid attribute is not the agent's to move.
			if ( ( $block['attrs']['uid'] ?? null ) === $uid && str_starts_with( (string) ( $block['blockName'] ?? '' ), 'xb/' ) ) {
				array_splice( $blocks, $i, 1 );
				return array( array_values( $blocks ), $block );
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && 'xb/passthrough' !== ( $block['blockName'] ?? null ) ) {
				[ $inner, $taken ] = self::take( $block['innerBlocks'], $uid );
				if ( null !== $taken ) {
					$blocks[ $i ]['innerBlocks'] = $inner;
					return array( $blocks, $taken );
				}
			}
		}
		return array( $blocks, null );
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param array<array<string, mixed>> $inserted
	 * @return array<array<string, mixed>>
	 */
	private static function insert( array $blocks, mixed $parent, mixed $index, array $inserted ): array {
		if ( null === $parent ) {
			$position = is_int( $index ) ? max( 0, min( $index, count( $blocks ) ) ) : count( $blocks );
			array_splice( $blocks, $position, 0, $inserted );
			return array_values( $blocks );
		}
		if ( ! is_string( $parent ) ) {
			throw new \InvalidArgumentException( __( 'parent deve essere un uid', 'xb' ) );
		}
		return self::edit(
			$blocks,
			$parent,
			static function ( array $block ) use ( $index, $inserted ): array {
				$is_loop = 'xb/loop' === $block['blockName'];
				$accepts = in_array( $block['blockName'], array( 'xb/branch', 'xb/loop', 'xb/slot-fill' ), true )
					|| ( 'xb/element' === $block['blockName'] && ! in_array( (string) ( $block['attrs']['tag'] ?? '' ), array_merge( Spec::VOID_ELEMENTS, Spec::RAW_TEXT_ELEMENTS ), true ) );
				if ( ! $accepts ) {
					throw new \InvalidArgumentException( __( 'questo nodo non accetta figli', 'xb' ) );
				}
				$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
				// A loop's {:else} (a last branch without a test) stays last: nothing goes after it.
				$last = end( $children );
				$end  = $is_loop && is_array( $last ) && 'xb/branch' === ( $last['blockName'] ?? null ) && ! isset( $last['attrs']['test'] ) ? count( $children ) - 1 : count( $children );
				$position = is_int( $index ) ? max( 0, min( $index, $end ) ) : $end;
				array_splice( $children, $position, 0, $inserted );
				$block['innerBlocks'] = $children;
				return $block;
			}
		);
	}

	/** @param array<array<string, mixed>> $blocks */
	private static function contains( array $blocks, string $uid ): bool {
		foreach ( $blocks as $block ) {
			if ( ( $block['attrs']['uid'] ?? null ) === $uid || ( is_array( $block['innerBlocks'] ?? null ) && self::contains( $block['innerBlocks'], $uid ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<array<string, mixed>> $blocks */
	public static function serialize( array $blocks ): string {
		return Blocks::serialize( $blocks );
	}
}
