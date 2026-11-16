<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import\Etch;

/**
 * What the styles saved by Etch need beyond their text — docs/spec/import-etch.md §6, «Stili»:
 * - custom media queries (`@custom-media --nav (width < 64em);` in a global sheet, `@media (--nav)` anywhere),
 *   which no browser understands: their conditions are written in place of the names;
 * - the order in which a page printed the selector styles: the order its elements name them, page by page, so that
 *   of two rules of equal specificity on the same element the same one wins;
 * - which read-only entries (the defaults Etch stores in the site's own option) the elements use.
 * Used by the import (Migration) and while Etch is off (Runtime).
 */
final class StyleData {

	/** Custom media read at most: a site has a handful. */
	private const MAX_MEDIA = 200;
	private const MAX_DEPTH = 100;

	/**
	 * The custom media of every global sheet: name (`--nav`) → condition.
	 *
	 * @param mixed $sheets The value of `etch_global_stylesheets`.
	 * @return array<string, string>
	 */
	public static function custom_media( mixed $sheets ): array {
		$found = array();
		foreach ( is_array( $sheets ) ? $sheets : array() as $sheet ) {
			$css = is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ? $sheet['css'] : '';
			if ( ! str_contains( $css, '@custom-media' ) ) {
				continue;
			}
			preg_match_all( '/@custom-media\s+(--[A-Za-z_][\w-]*)\s+([^;{}]+);/i', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $m, PREG_SET_ORDER );
			foreach ( $m as $match ) {
				if ( count( $found ) >= self::MAX_MEDIA ) {
					break 2;
				}
				$condition = trim( (string) preg_replace( '/\s+/', ' ', $match[2] ) );
				if ( self::valid_condition( $condition ) ) {
					$found[ $match[1] ] = $condition;
				}
			}
		}
		// A definition may name another one: resolved here, so each condition is final.
		$out = array();
		foreach ( $found as $name => $condition ) {
			$resolved = $condition;
			for ( $i = 0; $i < 5 && str_contains( $resolved, '(--' ); $i++ ) {
				$resolved = self::substitute( $resolved, $found );
			}
			if ( ! str_contains( $resolved, '(--' ) ) {
				$out[ $name ] = $resolved;
			}
		}
		return $out;
	}

	/**
	 * A condition made only of what a media query uses: nothing that can close the rule, open a block or load a
	 * resource (the same characters as Histeon's named queries, Styles\NamedQueries::validate()).
	 */
	private static function valid_condition( string $condition ): bool {
		if ( '' === $condition || strlen( $condition ) > 300 || 1 !== preg_match( '/^[a-zA-Z0-9\s().,:<>=\/%+*_-]+$/', $condition ) || 1 === preg_match( '/url\s*\(|expression/i', $condition ) ) {
			return false;
		}
		$depth = 0;
		foreach ( str_split( $condition ) as $c ) {
			$depth += '(' === $c ? 1 : ( ')' === $c ? -1 : 0 );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/** The sheet without its `@custom-media` statements (they mean nothing once resolved). */
	public static function without_definitions( string $css ): string {
		return str_contains( $css, '@custom-media' ) ? (string) preg_replace( '/@custom-media\s+--[A-Za-z_][\w-]*\s+[^;{}]+;[ \t]*\n?/i', '', $css ) : $css;
	}

	/**
	 * `@media (--nav)` → `@media (width < 64em)`, in every @media prelude (nested ones too). An unknown name stays as
	 * written: Histeon's named queries may still resolve it (Styles\NamedQueries), or the browser ignores the rule.
	 *
	 * @param array<string, string> $media custom_media().
	 */
	public static function resolve( string $css, array $media ): string {
		if ( array() === $media || ! str_contains( $css, '(--' ) ) {
			return $css;
		}
		return (string) preg_replace_callback(
			'/@media\b([^{;]*)/i',
			static function ( array $m ) use ( $media ): string {
				$prelude = trim( $m[1] );
				// The whole query is one name: its condition replaces it as it is (a list or a media type included).
				if ( 1 === preg_match( '/^\(\s*(--[A-Za-z_][\w-]*)\s*\)$/', $prelude, $only ) && isset( $media[ $only[1] ] ) ) {
					return '@media ' . self::boolean( $media[ $only[1] ] ) . ' ';
				}
				return '@media' . self::substitute( $m[1], $media );
			},
			$css
		);
	}

	/**
	 * Names inside a longer condition (`(--nav) and (hover: hover)`): a condition in parentheses goes in as it is,
	 * any other is put in parentheses.
	 *
	 * @param array<string, string> $media
	 */
	private static function substitute( string $text, array $media ): string {
		return (string) preg_replace_callback(
			'/\(\s*(--[A-Za-z_][\w-]*)\s*\)/',
			static function ( array $q ) use ( $media ): string {
				if ( ! isset( $media[ $q[1] ] ) ) {
					return $q[0];
				}
				$condition = $media[ $q[1] ];
				if ( 'true' === strtolower( $condition ) ) {
					return '(width >= 0)';
				}
				if ( 'false' === strtolower( $condition ) ) {
					return '(not (width >= 0))';
				}
				return self::one_group( $condition ) ? $condition : '(' . $condition . ')';
			},
			$text
		);
	}

	/** `true` and `false`, which a custom media may be, as media queries. */
	private static function boolean( string $condition ): string {
		return match ( strtolower( $condition ) ) {
			'true'  => 'all',
			'false' => 'not all',
			default => $condition,
		};
	}

	/** Whether the condition is one parenthesized group: `(a) and (b)` is not, `((a) and (b))` is. */
	private static function one_group( string $condition ): bool {
		if ( ! str_starts_with( $condition, '(' ) || ! str_ends_with( $condition, ')' ) ) {
			return false;
		}
		$depth = 0;
		$len   = strlen( $condition );
		for ( $i = 0; $i < $len; $i++ ) {
			$depth += '(' === $condition[ $i ] ? 1 : ( ')' === $condition[ $i ] ? -1 : 0 );
			if ( 0 === $depth && $i < $len - 1 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The keys of the selector styles in the order Etch printed them. A page printed the styles its elements name, in
	 * the order it met them (an element's own list in its order, then its children; a component where it is used),
	 * so the page around the content comes first, then the content, then what follows it. Histeon prints one order
	 * for the whole site: every document's own order is kept where the documents agree (merge()), and where nothing
	 * decides, the parts of the templates before their content come first, then pages, posts and templates without
	 * content, then the parts after the content, then components used nowhere.
	 *
	 * @param list<\WP_Post> $posts Documents with Etch content (components and template parts among them).
	 * @return list<string>
	 */
	public static function order( array $posts ): array {
		$components = array();
		$parts      = array();
		$sequences  = array();
		foreach ( $posts as $post ) {
			$sequence = array();
			self::walk( parse_blocks( $post->post_content ), $sequence, 0 );
			$sequences[ $post->ID ] = $sequence;
			if ( 'wp_block' === $post->post_type ) {
				$components[ $post->ID ] = $sequence;
			} elseif ( 'wp_template_part' === $post->post_type ) {
				$parts[ $post->post_name ] = $sequence;
			}
		}
		$used    = array();
		$before  = array();
		$middle  = array();
		$after   = array();
		$frames  = array();
		$content = array();
		$graph   = array();
		foreach ( $posts as $post ) {
			if ( 'wp_block' === $post->post_type || 'wp_template_part' === $post->post_type ) {
				continue;
			}
			$keys = self::expand( $sequences[ $post->ID ], $components, $parts, $used, array() );
			$at   = array_search( "\0content", $keys, true );
			if ( 'wp_template' === $post->post_type && false !== $at ) {
				$frame    = array( array_slice( $keys, 0, $at ), array_values( array_diff( array_slice( $keys, $at + 1 ), array( "\0content" ) ) ) );
				$before   = array_merge( $before, $frame[0] );
				$after    = array_merge( $after, $frame[1] );
				$frames[ md5( (string) wp_json_encode( $frame ) ) ] = $frame;
			} else {
				$middle = array_merge( $middle, $keys );
				// A template without the content's place is a whole page; a page or a post sits inside a template.
				if ( 'wp_template' === $post->post_type ) {
					self::link( array_diff( $keys, array( "\0content" ) ), $graph );
				} else {
					$content[ md5( (string) wp_json_encode( $keys ) ) ] = $keys;
				}
			}
		}
		// Which template draws which page is WordPress's to decide at request time: each content goes in every frame.
		foreach ( $content as $keys ) {
			foreach ( array() === $frames ? array( array( array(), array() ) ) : $frames as $frame ) {
				self::link( array_merge( $frame[0], $keys, $frame[1] ), $graph );
			}
		}
		$rest = array();
		foreach ( $components as $id => $sequence ) {
			if ( ! isset( $used[ 'c' . $id ] ) ) {
				$keys = self::expand( $sequence, $components, $parts, $used, array( 'c' . $id => true ) );
				$rest = array_merge( $rest, $keys );
				self::link( $keys, $graph );
			}
		}
		$rank = array();
		foreach ( array_merge( $before, $middle, $after, $rest ) as $key ) {
			if ( "\0content" !== $key && ! isset( $rank[ $key ] ) ) {
				$rank[ $key ] = count( $rank );
			}
		}
		return self::merge( $graph, $rank );
	}

	/**
	 * What one page printed, as links of the graph merge() reads: each key it met first, before the next one.
	 *
	 * @param array<string>                                                   $chain
	 * @param array{after?: array<string, list<string>>, waiting?: array<string, int>, edges?: array<string, array<string, true>>} $graph
	 */
	private static function link( array $chain, array &$graph ): void {
		$seen = array();
		$prev = null;
		foreach ( $chain as $key ) {
			if ( "\0content" === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( null !== $prev && ! isset( $graph['edges'][ $prev ][ $key ] ) ) {
				$graph['edges'][ $prev ][ $key ] = true;
				$graph['after'][ $prev ][]       = $key;
				$graph['waiting'][ $key ]        = ( $graph['waiting'][ $key ] ?? 0 ) + 1;
			}
			$prev = $key;
		}
	}

	/**
	 * One order out of the pages' own (link()): a key comes out only after the keys some page put before it. Among the
	 * keys that may come out, the lowest rank first; when pages disagree (two in opposite orders), the waiting key of
	 * lowest rank comes out anyway.
	 *
	 * @param array{after?: array<string, list<string>>, waiting?: array<string, int>, edges?: array<string, array<string, true>>} $graph
	 * @param array<string, int> $rank Every key of the graph.
	 * @return list<string>
	 */
	private static function merge( array $graph, array $rank ): array {
		$after   = $graph['after'] ?? array();
		$waiting = array();
		foreach ( $rank as $key => $unused ) {
			$waiting[ $key ] = $graph['waiting'][ $key ] ?? 0;
		}
		$ready = new \SplPriorityQueue();
		$ready->setExtractFlags( \SplPriorityQueue::EXTR_DATA );
		foreach ( $waiting as $key => $count ) {
			if ( 0 === $count ) {
				$ready->insert( (string) $key, -$rank[ $key ] );
			}
		}
		$out  = array();
		$done = array();
		$left = $rank;
		asort( $left );
		while ( count( $out ) < count( $rank ) ) {
			if ( $ready->isEmpty() ) {
				// A cycle: pages disagree. The waiting key of lowest rank comes out anyway.
				foreach ( $left as $key => $unused ) {
					if ( ! isset( $done[ $key ] ) ) {
						$ready->insert( (string) $key, -$rank[ $key ] );
						break;
					}
				}
			}
			$key = (string) $ready->extract();
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			$done[ $key ] = true;
			$out[]        = $key;
			foreach ( $after[ $key ] ?? array() as $next ) {
				if ( 0 === --$waiting[ $next ] && ! isset( $done[ $next ] ) ) {
					$ready->insert( $next, -$rank[ $next ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Style keys in document order, with the places of components, template parts and the content.
	 *
	 * @param array<mixed>                      $blocks
	 * @param list<string|array{0: string, 1: int|string}> $out
	 */
	private static function walk( array $blocks, array &$out, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( str_starts_with( $name, 'etch/' ) && is_array( $attrs['styles'] ?? null ) ) {
				foreach ( $attrs['styles'] as $key ) {
					if ( is_string( $key ) && '' !== $key ) {
						$out[] = $key;
					}
				}
			}
			if ( 'etch/component' === $name && is_numeric( $attrs['ref'] ?? null ) ) {
				$out[] = array( 'c', (int) $attrs['ref'] );
			} elseif ( 'core/template-part' === $name && is_string( $attrs['slug'] ?? null ) ) {
				$out[] = array( 'p', $attrs['slug'] );
			} elseif ( 'core/post-content' === $name ) {
				$out[] = "\0content";
			}
			self::walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $out, $depth + 1 );
		}
	}

	/**
	 * A sequence with its components and template parts in place (each once per path: one including itself stops).
	 *
	 * @param list<string|array{0: string, 1: int|string}>                     $sequence
	 * @param array<int, list<string|array{0: string, 1: int|string}>>         $components
	 * @param array<string, list<string|array{0: string, 1: int|string}>>      $parts
	 * @param array<string, true>                                              $used
	 * @param array<string, true>                                              $stack
	 * @return list<string>
	 */
	private static function expand( array $sequence, array $components, array $parts, array &$used, array $stack ): array {
		$out = array();
		foreach ( $sequence as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
				continue;
			}
			$id    = $item[0] . $item[1];
			$inner = 'c' === $item[0] ? ( $components[ (int) $item[1] ] ?? null ) : ( $parts[ (string) $item[1] ] ?? null );
			if ( null === $inner || isset( $stack[ $id ] ) || count( $stack ) >= 16 ) {
				continue;
			}
			$used[ $id ] = true;
			// The content of a part is not the page's: only a template marks where the content goes.
			$out = array_merge( $out, array_values( array_filter( self::expand( $inner, $components, $parts, $used, $stack + array( $id => true ) ), static fn ( string $k ): bool => "\0content" !== $k ) ) );
		}
		return $out;
	}

	/**
	 * Selector styles sorted as order() says; keys not in it keep their relative place, after the others.
	 *
	 * @param array<string, mixed> $styles Key → entry, as Etch stores them.
	 * @param list<string>         $order
	 * @return array<string, mixed>
	 */
	public static function sorted( array $styles, array $order ): array {
		$out = array();
		foreach ( $order as $key ) {
			if ( array_key_exists( $key, $styles ) ) {
				$out[ $key ] = $styles[ $key ];
			}
		}
		foreach ( $styles as $key => $entry ) {
			if ( ! array_key_exists( (string) $key, $out ) ) {
				$out[ (string) $key ] = $entry;
			}
		}
		return $out;
	}
}
