<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Content;

use XB\Render\ComponentRegistry;

/**
 * The components a writer adds to an article (scheda libro, riquadro…): ordinary Histeon components under the key
 * `articolo/…`, always available, as Etch's are while Etch is off (Import\Etch\Runtime): the definition comes with the
 * plugin and nothing is written to the site. A component of the same key stored on the site wins, so a site can change
 * one; Histeon Publish sends its own with the articles that use them. In Gutenberg they are patterns, whose props are
 * edited in the block's side panel.
 */
final class ArticleBlocks {

	private const PREFIX = 'articolo/';

	private const ENGLISH = array(
		'Tipo'                    => 'Type',
		'Titolo'                  => 'Title',
		'Testo'                   => 'Text',
		'Autore'                  => 'Author',
		'Copertina'               => 'Cover',
		'Editore'                 => 'Publisher',
		'Collegamento'            => 'Link',
		'Testo del collegamento'  => 'Link text',
		'Ritratto'                => 'Portrait',
		'Nome'                    => 'Name',
		'Biografia'               => 'Biography',
		'Nome del documento'      => 'Document name',
		'Indirizzo del documento' => 'Document address',
		'Indirizzo del video'     => 'Video address',
		'Immagine'                => 'Image',
		'Descrizione'             => 'Description',
		'Testo del pulsante'      => 'Button text',
		'Data e ora'              => 'Date and time',
		'Luogo'                   => 'Place',
		'Prima'                   => 'Before',
		'Dopo'                    => 'After',
		'Didascalia'              => 'Caption',
		"Indirizzo dell'audio"    => 'Audio address',
		'Indirizzo della mappa'   => 'Map address',
		'Domanda'                 => 'Question',
		'Risposta'                => 'Answer',
		'Data'                    => 'Date',
		'Che cosa accade'         => 'What happens',
		'Riferimento'             => 'Reference',
	);

	/** @var list<array<string, mixed>>|null */
	private static ?array $list = null;

	public static function register(): void {
		add_filter( 'xb_component_definition', array( self::class, 'definition' ), 20, 2 );
		register_block_pattern_category( 'histos-articolo', array( 'label' => __( 'Histeon · Per chi scrive', 'xb' ) ) );
		foreach ( self::all() as $component ) {
			if ( ! empty( $component['row'] ) ) {
				continue; // A row only exists inside its block.
			}
			$ref = (string) $component['ref'];
			register_block_pattern(
				'xb/' . str_replace( '/', '-', $ref ),
				array(
					'title'      => (string) $component['title'],
					'categories' => array( 'histos-articolo' ),
					'content'    => sprintf( '<!-- wp:xb/component %s /-->', (string) wp_json_encode( array( 'ref' => $ref ) ) ),
				)
			);
		}
	}

	/**
	 * @param mixed $definition
	 * @return mixed
	 */
	public static function definition( mixed $definition, string $ref ): mixed {
		if ( null !== $definition || ! str_starts_with( $ref, self::PREFIX ) || null !== ComponentRegistry::owner( $ref ) ) {
			return $definition;
		}
		foreach ( self::all() as $position => $component ) {
			if ( $ref === $component['ref'] ) {
				return array(
					// No post holds it: an id no post has, distinct for each.
					'id'     => -1 - $position,
					'blocks' => parse_blocks( (string) $component['content'] ),
					'props'  => $component['schema']['props'],
				);
			}
		}
		return $definition;
	}

	/**
	 * A block of the library used for the first time in Gutenberg enters the site as a component, so that it travels:
	 * the same as Publish does with its first use.
	 */
	public static function store_used( string $content ): void {
		// A component's key is site-wide and its wp_block belongs to who creates it: only someone who may create
		// components (xb_build) makes one, as in the builder. An author's article keeps working without it.
		if ( ! str_contains( $content, self::PREFIX ) || ! current_user_can( 'xb_build' ) ) {
			return;
		}
		foreach ( ComponentRegistry::refs_in( parse_blocks( $content ) ) as $ref ) {
			foreach ( array_filter( array( $ref, self::rows( $ref ) ) ) as $needed ) {
				self::store( (string) $needed );
			}
		}
	}

	private static function store( string $ref ): void {
		if ( null !== ComponentRegistry::owner( $ref ) ) {
			return;
		}
		foreach ( self::all() as $component ) {
			if ( $ref !== $component['ref'] ) {
				continue;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'wp_block',
						'post_status'  => 'publish',
						'post_title'   => (string) $component['title'],
						'post_content' => (string) $component['content'],
					)
				),
				true
			);
			if ( is_int( $id ) ) {
				update_post_meta( $id, ComponentRegistry::META_KEY, $ref );
				update_post_meta( $id, ComponentRegistry::META_SCHEMA, wp_slash( (string) wp_json_encode( $component['schema'] ) ) );
				ComponentRegistry::forget_refs();
			}
		}
	}

	/**
	 * Gutenberg's own blocks written in an article, as the plain elements Histeon carries: a paragraph added in the block
	 * editor must not stop the article from travelling. One with no plain form (an embed, a block of another plugin)
	 * travels whole in xb/passthrough, and may only come back as it left: assert_foreign_known(). Histeon blocks added
	 * there get the id they lack. Ids come from the position, so reading twice gives the same.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function from_core( array $blocks, string $path = '' ): array {
		$out = array();
		foreach ( $blocks as $position => $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && str_starts_with( $name, 'xb/' ) && ! is_string( $block['attrs']['uid'] ?? null ) ) {
				$block['attrs']['uid'] = 'g' . substr( md5( $path . '/' . $position . $name ), 0, 11 );
			}
			if ( null === $name && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			if ( is_string( $name ) && ! str_starts_with( $name, 'xb/' ) ) {
				$out[] = ( str_starts_with( $name, 'core/' ) ? self::element_of( $block, (int) $position ) : null ) ?? self::kept( $block, $path . '/' . $position );
				continue;
			}
			if ( 'xb/passthrough' === $name ) {
				$out[] = $block;
				continue;
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
				$block['innerBlocks'] = self::from_core( $block['innerBlocks'], $path . '/' . $position );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * A foreign block that goes out of the site comes back only as it left: from a project, which may hold anything,
	 * nothing new of this kind enters WordPress (a block runs with the rights of the site). One the site has held in an
	 * earlier version of the document also left from here, and a rollback brings it back.
	 *
	 * @param array<array<string, mixed>>                      $incoming
	 * @param array<array<string, mixed>>                      $current
	 * @param (callable(): array<array<string, mixed>>)|null $earlier
	 */
	public static function assert_foreign_known( array $incoming, array $current, ?callable $earlier, string $title ): void {
		$known = self::foreign( self::from_core( $current ) );
		foreach ( self::foreign( $incoming ) as $block ) {
			if ( ! in_array( $block, $known, true ) && null !== $earlier ) {
				$known   = array_merge( $known, self::foreign( self::from_core( $earlier() ) ) );
				$earlier = null;
			}
			if ( ! in_array( $block, $known, true ) ) {
				throw new \DomainException( sprintf( /* translators: %s: title of the article */ __( '«%s» contiene un blocco di WordPress che questo sito non ha, e un blocco così torna solo identico a com\'era. Toglilo dall\'articolo in Publish e invia di nuovo.', 'xb' ), $title ) );
			}
		}
	}

	/**
	 * What the xb/passthrough blocks hold, serialized.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private static function foreign( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( 'xb/passthrough' === ( $block['blockName'] ?? null ) ) {
				$out[] = serialize_blocks( $inner );
				continue;
			}
			array_push( $out, ...self::foreign( $inner ) );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function kept( array $block, string $path ): array {
		return array(
			'blockName'    => 'xb/passthrough',
			'attrs'        => array( 'uid' => 'p' . substr( md5( $path . (string) $block['blockName'] ), 0, 11 ) ),
			'innerBlocks'  => array( $block ),
			'innerHTML'    => '',
			'innerContent' => array( null ),
		);
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>|null
	 */
	private static function element_of( array $block, int $position ): ?array {
		$html = trim( (string) ( $block['innerHTML'] ?? '' ) );
		$uid  = static fn ( string $salt ): string => 'c' . substr( md5( $position . $salt . $html ), 0, 11 );
		switch ( $block['blockName'] ) {
			case 'core/paragraph':
			case 'core/heading':
				if ( 1 !== preg_match( '~^<(p|h[2-6])(?:\s[^>]*)?>(.*)</\1>$~is', $html, $m ) ) {
					return null;
				}
				return self::node( $uid( 'e' ), strtolower( $m[1] ), array(), array( self::text( $uid( 't' ), $m[2] ) ) );
			case 'core/separator':
				return self::node( $uid( 'e' ), 'hr', array(), array() );
			case 'core/image':
				if ( 1 !== preg_match( '~<img\b[^>]*\bsrc="([^"]+)"[^>]*>~i', $html, $m ) ) {
					return null;
				}
				$alt      = 1 === preg_match( '~\balt="([^"]*)"~i', $m[0], $a ) ? $a[1] : '';
				$image    = self::node( $uid( 'i' ), 'img', array( 'src' => html_entity_decode( $m[1] ), 'alt' => html_entity_decode( $alt ) ), array() );
				$children = array( $image );
				if ( 1 === preg_match( '~<figcaption[^>]*>(.*)</figcaption>~is', $html, $c ) ) {
					$children[] = self::node( $uid( 'c' ), 'figcaption', array(), array( self::text( $uid( 'f' ), $c[1] ) ) );
				}
				return self::node( $uid( 'e' ), 'figure', array(), $children );
			case 'core/list':
				$items = array();
				foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $at => $item ) {
					if ( 1 !== preg_match( '~^<li[^>]*>(.*)</li>$~is', trim( (string) ( $item['innerHTML'] ?? '' ) ), $m ) || array() !== ( $item['innerBlocks'] ?? array() ) ) {
						return null;
					}
					$items[] = self::node( $uid( 'l' . $at ), 'li', array(), array( self::text( $uid( 't' . $at ), $m[1] ) ) );
				}
				return self::node( $uid( 'e' ), empty( $block['attrs']['ordered'] ) ? 'ul' : 'ol', array(), $items );
			case 'core/quote':
				$lines = array();
				foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $at => $item ) {
					$line = 'core/paragraph' === ( $item['blockName'] ?? null ) ? self::element_of( $item, $position * 100 + (int) $at ) : null;
					if ( null === $line ) {
						return null;
					}
					$lines[] = $line;
				}
				return self::node( $uid( 'e' ), 'blockquote', array(), $lines );
		}
		return null;
	}

	/**
	 * @param array<string, string>       $attributes
	 * @param array<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function node( string $uid, string $tag, array $attributes, array $children ): array {
		$attrs = array( 'uid' => $uid, 'tag' => $tag );
		if ( array() !== $attributes ) {
			$attrs['attributes'] = $attributes;
		}
		return array( 'blockName' => 'xb/element', 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $children ), null ) );
	}

	/** @return array<string, mixed> */
	private static function text( string $uid, string $html ): array {
		// A brace typed in Gutenberg is a brace, not an expression.
		$content = preg_replace( '/(?<!\\\\)([{}])/', '\\\\$1', $html );
		return array( 'blockName' => 'xb/text', 'attrs' => array( 'uid' => $uid, 'content' => (string) $content ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
	}

	/**
	 * The side panel's words in the editor's language: a definition holds one label, and the library's are Italian.
	 *
	 * @param array<string, array<string, mixed>> $props
	 * @return array<string, array<string, mixed>>
	 */
	public static function labelled( array $props ): array {
		if ( str_starts_with( get_user_locale(), 'it' ) ) {
			return $props;
		}
		foreach ( $props as $name => $prop ) {
			$label = (string) ( $prop['label'] ?? '' );
			$props[ $name ]['label'] = self::ENGLISH[ $label ] ?? $label;
		}
		return $props;
	}

	/** The component whose rows a block holds, if it has rows. */
	public static function rows( string $ref ): ?string {
		foreach ( self::all() as $component ) {
			if ( $ref === $component['ref'] ) {
				return isset( $component['rows'] ) ? (string) $component['rows'] : null;
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> */
	private static function all(): array {
		if ( null === self::$list ) {
			$read       = json_decode( (string) file_get_contents( dirname( XB_FILE ) . '/assets/framework/article-components.json' ), true );
			self::$list = is_array( $read ) ? array_values( $read ) : array();
		}
		return self::$list;
	}
}
