<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Plugin;
use XB\Render\Context;
use XB\Templates\Templates;

/**
 * The proof of the conversion without writing anything — docs/spec/export-core.md §12: each document is drawn twice,
 * as an anonymous visitor would see it, once by Histeon from its blocks and once by WordPress from the converted
 * blocks (Fondamenta's blocks must be registered: Fondamenta 0.2 or later active), and the two HTML are compared
 * as DOM: same elements, same attributes, same text. A template is drawn with the sample the builder would use.
 */
final class Prova {

	/** Classes WordPress adds to core text blocks at render time: told apart from real differences. */
	public const CORE_CLASSES = array( 'wp-block-heading', 'wp-block-list', 'wp-block-paragraph' );

	public static function ready(): bool {
		return \WP_Block_Type_Registry::get_instance()->is_registered( 'fondamenta/elemento' );
	}

	/**
	 * The conversion of one document: blocks and the converter's notes, in the context the document renders in.
	 * $trusted: a file of the theme, not written by an author (no restricted filter).
	 *
	 * @return array{blocks: list<array<string, mixed>>, notes: list<array<string, string>>, counts: array<string, int>}
	 */
	public static function convert( \WP_Post $post, bool $trusted = false ): array {
		return self::within(
			$post,
			static function () use ( $post, $trusted ): array {
				// A component that becomes a synced pattern: its post is the pattern, converted.
				$key     = 'wp_block' === $post->post_type ? (string) get_post_meta( $post->ID, \XB\Render\ComponentRegistry::META_KEY, true ) : '';
				$pattern = '' !== $key ? Converter::pattern( $key ) : null;
				if ( null !== $pattern && $pattern['id'] === $post->ID ) {
					return array(
						'blocks' => $pattern['blocks'],
						'notes'  => array(),
						'counts' => array( 'core/block' => 1 ),
					);
				}
				$converter = new Converter( Report::kind( $post ) );
				$blocks    = $converter->convert( array_values( parse_blocks( $trusted ? $post->post_content : self::content( $post ) ) ), Context::from_globals() );
				return array(
					'blocks' => $blocks,
					'notes'  => $converter->notes,
					'counts' => $converter->blocks,
				);
			}
		);
	}

	/**
	 * The two renderings of a document and how they differ.
	 *
	 * @return array{esito: string, differenze: list<string>, prima: string, dopo: string, notes: list<array<string, string>>, counts: array<string, int>}
	 */
	public static function compare( \WP_Post $post ): array {
		if ( ! self::ready() ) {
			throw new \RuntimeException( __( 'Per la prova servono i blocchi di Fondamenta: attiva Fondamenta 0.2 o successiva.', 'xb' ) );
		}
		return self::within(
			$post,
			static function () use ( $post ): array {
				$renderer = Plugin::instance()->renderer;
				$renderer->reset_budget();
				$blocks    = array_values( parse_blocks( self::content( $post ) ) );
				$before    = $renderer->render_blocks( $blocks, Context::from_globals() );
				$converter = new Converter( Report::kind( $post ) );
				$converted = $converter->convert( $blocks, Context::from_globals() );
				// The synced patterns are still Histeon components in the database: drawn here as converted.
				$patterns = Converter::converted_patterns();
				$draw     = static function ( mixed $pre, array $parsed ) use ( $patterns ): mixed {
					$ref = (int) ( $parsed['attrs']['ref'] ?? 0 );
					if ( 'core/block' !== ( $parsed['blockName'] ?? null ) || ! isset( $patterns[ $ref ] ) ) {
						return $pre;
					}
					$html = '';
					foreach ( $patterns[ $ref ] as $inner ) {
						$html .= ( new \WP_Block( $inner, array( 'pattern/overrides' => is_array( $parsed['attrs']['content'] ?? null ) ? $parsed['attrs']['content'] : array() ) ) )->render();
					}
					return $html;
				};
				// The filtered lists, as the site theme will give them to Fondamenta.
				$facets = static fn ( array $own ): array => $own + \XB\Render\Facets::portable();
				add_filter( 'pre_render_block', $draw, 10, 2 );
				add_filter( 'fondamenta_facets', $facets );
				try {
					$after = do_blocks( \XB\Blocks::serialize( $converted ) );
				} finally {
					remove_filter( 'pre_render_block', $draw, 10 );
					remove_filter( 'fondamenta_facets', $facets );
				}
				$a         = self::canonical( $before, false );
				$b         = self::canonical( $after, false );
				$esito     = 'uguale';
				$diff      = array();
				if ( $a !== $b ) {
					$core_a = self::canonical( $before, true );
					$core_b = self::canonical( $after, true );
					$esito  = $core_a === $core_b ? 'classi del core' : 'diverso';
					$diff   = 'diverso' === $esito ? self::differences( $core_a, $core_b ) : array();
				}
				return array(
					'esito'      => $esito,
					'differenze' => $diff,
					'prima'      => $before,
					'dopo'       => $after,
					'notes'      => $converter->notes,
					'counts'     => $converter->blocks,
				);
			}
		);
	}

	/**
	 * Runs a callback with the globals of the document shown, as an anonymous visitor: the document itself for a
	 * content, the builder's sample for a template or part.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private static function within( \WP_Post $post, callable $callback ): mixed {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
				return Templates::with_context( $post, 0, $callback );
			}
			global $wp_query, $wp_the_query;
			$saved = array( $GLOBALS['post'] ?? null, $wp_query, $wp_the_query );
			$query = new \WP_Query(
				array(
					'p'           => $post->ID,
					'post_type'   => $post->post_type,
					'post_status' => 'any',
				)
			);
			$wp_query        = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
			$wp_the_query    = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $post );
			try {
				return $callback();
			} finally {
				[ $GLOBALS['post'], $wp_query, $wp_the_query ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				wp_reset_postdata();
			}
		} finally {
			wp_set_current_user( $user );
		}
	}

	/** The content the conversion reads: the live version, through the restricted filter for an untrusted author. */
	private static function content( \WP_Post $post ): string {
		return \XB\Import\Etch\Migration::author_trusted( $post ) ? $post->post_content : Plugin::instance()->sanitizer->restricted_content( $post->post_content );
	}

	/**
	 * HTML as one line per element or text: attributes sorted, classes sorted, spaces collapsed, comments dropped;
	 * with $core, without the classes WordPress adds to core text blocks.
	 */
	public static function canonical( string $html, bool $core ): string {
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="fondamenta-prova">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementById( 'fondamenta-prova' );
		$out  = array();
		if ( $root instanceof \DOMElement ) {
			foreach ( $root->childNodes as $child ) {
				self::lines( $child, 0, $core, $out );
			}
		}
		return implode( "\n", $out );
	}

	/** @param list<string> $out */
	private static function lines( \DOMNode $node, int $depth, bool $core, array &$out ): void {
		if ( $node instanceof \DOMText ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
			if ( '' !== $text ) {
				$out[] = str_repeat( ' ', $depth ) . '"' . $text . '"';
			} elseif ( '' !== $node->textContent && $node->previousSibling instanceof \DOMElement && $node->nextSibling instanceof \DOMElement ) {
				// A space between two elements changes how inline ones break: it counts.
				$out[] = str_repeat( ' ', $depth ) . '␠';
			}
			return;
		}
		if ( ! $node instanceof \DOMElement ) {
			return;
		}
		$attributes = array();
		foreach ( $node->attributes ?? array() as $attribute ) {
			$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $attribute->value ) );
			// Counters and random ids a form plugin writes anew at each drawing (Fluent Forms): not a difference.
			$value = (string) preg_replace( array( '/\bff_form_instance_\d+_\d+\b/', '/\b([a-z_]+)_[0-9a-f]{32}\b/', '/\bff_\d+(?:_\d+)?_/' ), array( 'ff_form_instance_N', '$1_N', 'ff_N_' ), $value );
			if ( 'class' === $attribute->name ) {
				$classes = array_filter( explode( ' ', $value ), static fn ( string $c ): bool => '' !== $c && ! ( $core && in_array( $c, self::CORE_CLASSES, true ) ) );
				sort( $classes );
				if ( array() === $classes ) {
					continue;
				}
				$value = implode( ' ', $classes );
			}
			$attributes[ $attribute->name ] = $attribute->name . '="' . $value . '"';
		}
		ksort( $attributes );
		$out[] = str_repeat( ' ', $depth ) . '<' . $node->tagName . ( array() === $attributes ? '' : ' ' . implode( ' ', $attributes ) ) . '>';
		foreach ( $node->childNodes as $child ) {
			self::lines( $child, $depth + 1, $core, $out );
		}
	}

	/**
	 * The first lines that differ, with the line before for context.
	 *
	 * @return list<string>
	 */
	private static function differences( string $a, string $b ): array {
		$left  = explode( "\n", $a );
		$right = explode( "\n", $b );
		$out   = array();
		$max   = max( count( $left ), count( $right ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( ( $left[ $i ] ?? null ) !== ( $right[ $i ] ?? null ) ) {
				$out[] = '- ' . mb_substr( trim( $left[ $i ] ?? '(niente)' ), 0, 160 );
				$out[] = '+ ' . mb_substr( trim( $right[ $i ] ?? '(niente)' ), 0, 160 );
				break;
			}
		}
		if ( count( $left ) !== count( $right ) ) {
			/* translators: 1: lines before, 2: lines after */
			$out[] = sprintf( __( '%1$d righe prima, %2$d dopo', 'xb' ), count( $left ), count( $right ) );
		}
		return $out;
	}
}
