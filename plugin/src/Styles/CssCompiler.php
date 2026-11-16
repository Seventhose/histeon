<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * Turns a style entry (selector + body written in the CSS panel) into CSS — docs/spec/styles.md.
 *
 * Browsers support native nesting (`&:hover`, `& .child`, nested `@media`), so those are kept as written.
 * The only rewrite is BEM concatenation, which native nesting cannot express: `&__title` and `&--large`
 * become sibling rules `.card__title` / `.card--large`, recursively.
 */
final class CssCompiler {

	public static function compile( string $selector, string $body ): string {
		$selector = trim( $selector );
		if ( ! self::valid_selector( $selector ) ) {
			return '';
		}
		$own      = '';
		$hoisted  = '';
		foreach ( self::statements( $body ) as [ $prelude, $block ] ) {
			if ( null === $block ) {
				$own .= $prelude . ';';
				continue;
			}
			if ( self::is_bem( $prelude ) ) {
				$hoisted .= self::compile( self::join( $selector, $prelude ), $block );
			} elseif ( self::is_group( $prelude ) ) {
				[ $inside, $lifted ] = self::group( $selector, $prelude, $block );
				$own     .= $inside;
				$hoisted .= $lifted;
			} else {
				$own .= $prelude . '{' . $block . '}';
			}
		}
		$css = ( '' === $own ? '' : $selector . '{' . $own . '}' ) . $hoisted;
		return self::sanitize( $css );
	}

	/**
	 * A selector that stays a selector once printed before its `{`: no braces, `;` or `<`, no comment opener, no
	 * trailing backslash (it would escape the `{`). Imports (Etch, releases, CMS profiles) store selectors written
	 * elsewhere; without this a selector could close the rule and add CSS that the restricted-tier filter never saw.
	 */
	public static function valid_selector( string $selector ): bool {
		$selector = trim( $selector );
		$trailing = strlen( $selector ) - strlen( rtrim( $selector, '\\' ) );
		return '' !== $selector && strlen( $selector ) <= 1024 && 1 !== preg_match( '#[{}<;]|/\*#', $selector ) && 0 === $trailing % 2;
	}

	/** `&__part` / `&--variant` (every selector of the list): a sibling rule with the concatenated name. */
	private static function is_bem( string $prelude ): bool {
		$nested = self::split_list( $prelude );
		return array() !== $nested && array() === array_filter( $nested, static fn ( string $part ): bool => 1 !== preg_match( '/^&(?:__|--)/', $part ) );
	}

	/** `.a, .b` + `&__x` → `.a__x,.b__x`. */
	private static function join( string $selector, string $prelude ): string {
		$joined = array();
		foreach ( self::split_list( $selector ) as $parent ) {
			foreach ( self::split_list( $prelude ) as $part ) {
				$joined[] = $parent . substr( $part, 1 );
			}
		}
		return implode( ',', $joined );
	}

	/** Conditional group rules that may sit inside a style rule. */
	private static function is_group( string $prelude ): bool {
		// Read through strtolower(), ASCII letters alone: the `i` flag and `\b` of PCRE follow the PHP locale (T31, P1).
		return 1 === preg_match( '/^@(?:media|container|supports|layer)(?![a-z0-9_])/', strtolower( $prelude ) );
	}

	/**
	 * A nested `@media` (or `@container`, `@supports`, `@layer`) of `$selector`: what stays nested, as written,
	 * and the BEM parts inside it, lifted as `@media …{.card__title{…}}` (native nesting cannot concatenate names).
	 * Only those parts move: everything else keeps its place, so the cascade is unchanged.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function group( string $selector, string $prelude, string $block ): array {
		$keep = '';
		$lift = '';
		foreach ( self::statements( $block ) as [ $inner, $body ] ) {
			if ( null === $body ) {
				$keep .= $inner . ';';
			} elseif ( self::is_bem( $inner ) ) {
				$lift .= self::compile( self::join( $selector, $inner ), $body );
			} elseif ( self::is_group( $inner ) ) {
				[ $k, $l ] = self::group( $selector, $inner, $body );
				$keep     .= $k;
				$lift     .= $l;
			} else {
				$keep .= $inner . '{' . $body . '}';
			}
		}
		if ( '' === $lift ) {
			return array( $prelude . '{' . $block . '}', '' );
		}
		return array( '' === $keep ? '' : $prelude . '{' . $keep . '}', $prelude . '{' . $lift . '}' );
	}

	/**
	 * Prevents breaking out of the surrounding <style> element: only `</` can end it (raw text), so only that is
	 * escaped. A bare `<` stays, as range media queries need it: `@media (width < 48em)`.
	 */
	public static function sanitize( string $css ): string {
		return str_replace( '</', '\3C /', $css );
	}

	/**
	 * Top-level statements of a rule body: [declaration, null] or [prelude, block body].
	 * Comments are dropped; strings and parentheses are respected.
	 *
	 * @return list<array{0: string, 1: ?string}>
	 */
	public static function statements( string $body ): array {
		$out    = array();
		$buffer = '';
		$length = strlen( $body );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $body[ $i ];
			if ( '/' === $c && '*' === ( $body[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $body, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$end     = self::string_end( $body, $i );
				$buffer .= substr( $body, $i, $end - $i + 1 );
				$i       = $end;
				continue;
			}
			if ( ';' === $c ) {
				if ( '' !== trim( $buffer ) ) {
					$out[] = array( trim( $buffer ), null );
				}
				$buffer = '';
				continue;
			}
			if ( '{' === $c ) {
				$close  = self::block_end( $body, $i );
				$out[]  = array( trim( $buffer ), substr( $body, $i + 1, $close - $i - 1 ) );
				$buffer = '';
				$i      = $close;
				continue;
			}
			$buffer .= $c;
		}
		if ( '' !== trim( $buffer ) ) {
			$out[] = array( trim( $buffer ), null );
		}
		return $out;
	}

	/**
	 * Splits a selector list on top-level commas (`:is(.a, .b)` stays whole).
	 *
	 * @return list<string>
	 */
	public static function split_list( string $selector ): array {
		$parts  = array();
		$depth  = 0;
		$buffer = '';
		$length = strlen( $selector );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $selector[ $i ];
			if ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = trim( $buffer );
				$buffer  = '';
				continue;
			}
			$buffer .= $c;
		}
		$parts[] = trim( $buffer );
		return array_values( array_filter( $parts, static fn ( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Where the string that opens at `$open` ends: the offset of its closing quote, or the length of the text when it
	 * is never closed. A backslash takes the next character with it; a new line does not end the string. The one rule
	 * for strings of everything that reads CSS to print it: CssRules reads with it too, so the two never disagree on
	 * where a string ends (threat model T31, V2 of the fifth review).
	 */
	public static function string_end( string $text, int $open ): int {
		$quote  = $text[ $open ];
		$length = strlen( $text );
		for ( $i = $open + 1; $i < $length; $i++ ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
			} elseif ( $text[ $i ] === $quote ) {
				return $i;
			}
		}
		return $length;
	}

	private static function block_end( string $text, int $open ): int {
		$depth  = 0;
		$length = strlen( $text );
		for ( $i = $open; $i < $length; $i++ ) {
			$c = $text[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$i = self::string_end( $text, $i );
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				return $i;
			}
		}
		return $length;
	}
}
