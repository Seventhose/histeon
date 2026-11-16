<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/**
 * Splits strings with `{expression}` interpolations and `\{` `\}` escapes — docs/spec/dsl.md §3.5.
 * Mirrors findClosingBrace() in packages/dsl/src/expr.ts.
 */
final class Template {

	public const LITERAL = 'literal';
	public const EXPR    = 'expr';

	/** @var array<string, list<array{0: string, 1: string}>> */
	private static array $cache = array();

	/**
	 * @return list<array{0: string, 1: string}> Pairs of [kind, text]; literal text has escapes resolved.
	 * @throws SyntaxError On an unclosed brace.
	 */
	public static function split( string $value ): array {
		if ( isset( self::$cache[ $value ] ) ) {
			return self::$cache[ $value ];
		}
		$parts   = array();
		$literal = '';
		$length  = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $value[ $i ];
			if ( '\\' === $c && isset( $value[ $i + 1 ] ) && ( '{' === $value[ $i + 1 ] || '}' === $value[ $i + 1 ] ) ) {
				$literal .= $value[ $i + 1 ];
				++$i;
				continue;
			}
			if ( '{' === $c ) {
				$close = self::closing_brace( $value, $i );
				if ( '' !== $literal ) {
					$parts[] = array( self::LITERAL, $literal );
					$literal = '';
				}
				$parts[] = array( self::EXPR, substr( $value, $i + 1, $close - $i - 1 ) );
				$i       = $close;
				continue;
			}
			$literal .= $c;
		}
		if ( '' !== $literal ) {
			$parts[] = array( self::LITERAL, $literal );
		}
		if ( count( self::$cache ) > 2000 ) {
			self::$cache = array();
		}
		self::$cache[ $value ] = $parts;
		return $parts;
	}

	/** True when the whole value is exactly one interpolation. */
	public static function is_single( string $value ): bool {
		if ( '' === $value || '{' !== $value[0] ) {
			return false;
		}
		try {
			return self::closing_brace( $value, 0 ) === strlen( $value ) - 1;
		} catch ( SyntaxError ) {
			return false;
		}
	}

	/** @throws SyntaxError */
	public static function closing_brace( string $value, int $open ): int {
		$depth  = 0;
		$length = strlen( $value );
		for ( $i = $open; $i < $length; $i++ ) {
			$c = $value[ $i ];
			if ( '"' === $c || "'" === $c ) {
				++$i;
				while ( $i < $length && $value[ $i ] !== $c ) {
					if ( '\\' === $value[ $i ] ) {
						++$i;
					}
					++$i;
				}
				if ( $i >= $length ) {
					break;
				}
				continue;
			}
			if ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				return $i;
			}
		}
		throw new SyntaxError( __( 'Parentesi graffa "{" non chiusa', 'xb' ), $open );
	}
}
