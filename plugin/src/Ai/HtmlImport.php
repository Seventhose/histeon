<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

/**
 * Plain HTML → XB blocks, with WordPress's spec-compliant HTML API (WP_HTML_Processor).
 * For "paste HTML" and for agents that write markup. Comments are dropped; text keeps `{…}` so dynamic
 * expressions written in the HTML still work. Nothing is trusted here: the result goes through the same
 * sanitizer as any save.
 */
final class HtmlImport {

	private const MAX_BYTES = 200000;
	private const VOID      = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * @return list<array<string, mixed>> Blocks in parse_blocks() shape.
	 */
	public static function to_blocks( string $html ): array {
		if ( strlen( $html ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( __( 'HTML troppo lungo.', 'xb' ) );
		}
		$processor = \WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			throw new \InvalidArgumentException( __( 'HTML non interpretabile.', 'xb' ) );
		}
		// Blocks are built in a flat list with parent indexes, then assembled: no references to juggle.
		$nodes = array( array( 'block' => null, 'parent' => -1 ) );
		$stack = array( 0 ); // Open element node indexes; 0 = the fragment root (depth 2: HTML > BODY).
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#tag' === $type && $processor->is_tag_closer() ) {
				continue;
			}
			if ( '#text' !== $type && '#tag' !== $type ) {
				continue; // Comments, doctypes, processing instructions: dropped.
			}
			// A token at depth d has its parent at depth d-1, i.e. at stack size d-2.
			$parent_size = max( 1, (int) $processor->get_current_depth() - 2 );
			while ( count( $stack ) > $parent_size ) {
				array_pop( $stack );
			}
			$parent = (int) end( $stack );
			if ( '#text' === $type ) {
				$text = (string) $processor->get_modifiable_text();
				if ( '' !== trim( $text ) ) {
					$nodes[] = array(
						'block'  => self::block( 'xb/text', array( 'content' => esc_html( (string) preg_replace( '/\s+/', ' ', $text ) ) ) ),
						'parent' => $parent,
					);
				}
				continue;
			}
			$tag   = strtolower( (string) $processor->get_tag() );
			$attrs = array();
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$value          = $processor->get_attribute( (string) $name );
				$attrs[ (string) $name ] = true === $value ? true : (string) $value;
			}
			$element = array( 'tag' => $tag );
			if ( array() !== $attrs ) {
				$element['attributes'] = $attrs;
			}
			$nodes[] = array(
				'block'  => self::block( 'xb/element', $element ),
				'parent' => $parent,
			);
			$index   = count( $nodes ) - 1;
			if ( in_array( strtoupper( $tag ), array( 'SCRIPT', 'STYLE', 'TEXTAREA' ), true ) ) {
				$text = (string) $processor->get_modifiable_text();
				if ( '' !== $text ) {
					$nodes[] = array(
						'block'  => self::block(
							'xb/text',
							array(
								'content'  => $text,
								'verbatim' => true,
							)
						),
						'parent' => $index,
					);
				}
				continue;
			}
			if ( ! in_array( strtoupper( $tag ), self::VOID, true ) ) {
				$stack[] = $index;
			}
		}
		return self::assemble( $nodes, 0 );
	}

	/**
	 * @param list<array{block: array<string, mixed>|null, parent: int}> $nodes
	 * @return list<array<string, mixed>>
	 */
	private static function assemble( array $nodes, int $parent ): array {
		$out = array();
		foreach ( $nodes as $index => $node ) {
			if ( $node['parent'] === $parent && null !== $node['block'] ) {
				$block                 = $node['block'];
				$block['innerBlocks']  = self::assemble( $nodes, $index );
				$block['innerContent'] = array_fill( 0, count( $block['innerBlocks'] ), null );
				$out[]                 = $block;
			}
		}
		return $out;
	}


	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function block( string $name, array $attrs ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => array( 'uid' => Uids::fresh() ) + $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

}
