<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * Named media and container queries — docs/spec/styles.md §6. Written once in the site settings
 * (`tablet` = `(width < 64em)`) and used anywhere in CSS as `@media (--tablet)` or `@container card (--tablet)`,
 * the syntax of the CSS custom media draft. Resolved when the CSS is built, on the site and in the builder
 * preview; an unknown name is left as written (the browser ignores that rule).
 */
final class NamedQueries {

	public const OPTION = 'xb_named_queries';
	public const MAX    = 30;

	/** Used until the settings define their own. */
	public const DEFAULTS = array(
		'mobile'  => '(width < 40em)',
		'tablet'  => '(width < 64em)',
		'desktop' => '(width >= 64em)',
	);

	/**
	 * The queries a page sees: the dev version in a signed preview, the live one otherwise.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'queries', $dev ) ? $dev['queries'] : get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::DEFAULTS;
		}
		try {
			return self::validate( $stored );
		} catch ( \InvalidArgumentException ) {
			return self::DEFAULTS;
		}
	}

	/**
	 * Checks the queries a builder saves: a name, and a condition made of the characters a media or container
	 * condition uses — nothing that can close the rule, open a block or load a resource (condition()).
	 *
	 * @return array<string, string>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $queries ): array {
		if ( ! is_array( $queries ) || count( $queries ) > self::MAX ) {
			throw new \InvalidArgumentException( __( 'Elenco delle media query non valido.', 'xb' ) );
		}
		$out = array();
		foreach ( $queries as $name => $query ) {
			$name  = (string) $name;
			$query = is_string( $query ) ? trim( preg_replace( '/\s+/', ' ', $query ) ?? '' ) : '';
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,30}$/', $name ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Nome di media query non valido: "%s" (minuscole, cifre e trattini).', 'xb' ), mb_substr( $name, 0, 40 ) ) );
			}
			if ( ! self::condition( $query ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Condizione non valida per "%s": per esempio (width < 64em) oppure (orientation: portrait).', 'xb' ), $name ) );
			}
			$out[ $name ] = $query;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A condition that can be written into a sheet: the characters a media or container condition uses, balanced
	 * parentheses, and nothing that names a resource (`url(`, `image-set`, `src(`, an address that starts with `//`)
	 * or opens a comment. resolve() asks again before every substitution: the queries can change after a style was
	 * saved and filtered (threat model T31).
	 */
	private static function condition( string $query ): bool {
		return '' !== $query && strlen( $query ) <= 200
			&& 1 === preg_match( '/^[a-zA-Z0-9 ().,:<>=\/%+*-]+\z/', $query )
			// Read through strtolower(), with the white space written out: the `i` flag and `\s` follow the PHP locale (T31, P1).
			&& 1 !== preg_match( '/url[ \t\n\x0B\f\r]*\(|image-set|src[ \t\n\x0B\f\r]*\(|expression|\/\/|\/\*|\*\/|<[ \t\n\x0B\f\r]*\/|<!/', strtolower( $query ) )
			&& self::balanced( $query );
	}

	private static function balanced( string $query ): bool {
		$depth = 0;
		foreach ( str_split( $query ) as $c ) {
			$depth += '(' === $c ? 1 : ( ')' === $c ? -1 : 0 );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/**
	 * `@media (--tablet)` → `@media (width < 64em)`, in the prelude of a real @media or @container rule only.
	 *
	 * The page substitutes after the styles were filtered (Documents::restricted_styles()), so nothing written here may
	 * put together what the filter never saw: `content:"@media" ur(--x)` with `x` = `l(https://other.example/x)` printed
	 * a url() (threat model T31). Three guards, each enough on its own:
	 * - the sheet is read as the CSS tokenizer reads it (strings, comments, escapes and brackets skipped), and only an
	 *   `@media` or `@container` where a statement starts counts: at the start, or after `{`, `}` or `;`;
	 * - its prelude, up to the first `{`, `;` or `}`, holds no string, comment, escape or `url(`: otherwise it stays as
	 *   written;
	 * - `(--name)` is replaced only when it stands alone: right after the at-keyword or after a character that cannot
	 *   continue a name, and followed by neither such a character nor `(`. So no name is put together across it.
	 * Twin of resolveNamedQueries() in packages/dsl/src/css.ts, both checked against vectors/css.json `namedQueries`.
	 *
	 * @param array<string, string>|null $queries Defaults to all().
	 */
	public static function resolve( string $css, ?array $queries = null ): string {
		if ( ! str_contains( $css, '(--' ) ) {
			return $css;
		}
		$queries ??= self::all();
		$out      = '';
		$copied   = 0;
		$length   = strlen( $css );
		$stack    = '';    // The brackets left open, one byte each.
		$round    = 0;     // How many of them are `(` or `[`: inside those nothing is a statement.
		$start    = true;  // A statement can start here.
		$at_rule  = false; // At the top level, the statement under way started with an at-keyword: a `;` ends it.
		for ( $i = 0; $i < $length; ) {
			$c = $css[ $i ];
			if ( ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\f" === $c ) {
				++$i;
				continue;
			}
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}
			if ( '@' === $c && $start && 0 === $round ) {
				$at_rule = '' === $stack;
				$start   = false;
				if ( 1 === preg_match( '/\G@(?:[mM][eE][dD][iI][aA]|[cC][oO][nN][tT][aA][iI][nN][eE][rR])(?![a-zA-Z0-9_\\\\-]|[^\x00-\x7F])/', $css, $m, 0, $i ) ) {
					$from    = $i + strlen( $m[0] );
					// The prelude ends at the first `{` or `;`, and at a `}` too: the one that closes the block it is written
					// in (`.a{@media print}`). Reading on to a `{` further down took the rules in between for a prelude, and
					// wrote them twice. Nothing already written is written again ($from < $copied).
					$prelude = substr( $css, $from, strcspn( $css, '{;}', $from ) );
					if ( $from < $copied || '' === $prelude || strlen( $prelude ) !== strcspn( $prelude, "\"'\\" ) || str_contains( $prelude, '/*' ) || false !== stripos( $prelude, 'url(' ) ) {
						$i = $from;
						continue;
					}
					$out   .= substr( $css, $copied, $from - $copied ) . self::substitute( $prelude, $queries );
					$copied = $from + strlen( $prelude );
					$i      = $from;
					continue;
				}
				++$i;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				// A string ends at its quote, at a new line (left there) or where the sheet ends.
				for ( ++$i; $i < $length && $css[ $i ] !== $c && "\n" !== $css[ $i ] && "\r" !== $css[ $i ] && "\f" !== $css[ $i ]; ++$i ) {
					$i += '\\' === $css[ $i ] ? 1 : 0;
				}
				$i += $i < $length && $css[ $i ] === $c ? 1 : 0;
			} elseif ( '\\' === $c ) {
				$i += 2;
			} elseif ( '(' === $c || '[' === $c || '{' === $c ) {
				$stack .= $c;
				$round += '{' === $c ? 0 : 1;
				++$i;
				if ( '{' === $c ) {
					$start = 0 === $round;
					continue;
				}
			} elseif ( ')' === $c || ']' === $c || '}' === $c ) {
				++$i;
				// Only the bracket that matches closes: any other is a character like the rest.
				if ( '' !== $stack && ( ')' === $c ? '(' : ( ']' === $c ? '[' : '{' ) ) === $stack[-1] ) {
					$stack  = substr( $stack, 0, -1 );
					$round -= '}' === $c ? 0 : 1;
					if ( '}' === $c ) {
						$start   = 0 === $round;
						$at_rule = false;
						continue;
					}
				}
			} elseif ( ';' === $c ) {
				++$i;
				// Inside a block a `;` ends a declaration; at the top level it ends only an at-rule (`@layer a;`).
				if ( 0 === $round && ( '' !== $stack || $at_rule ) ) {
					$start   = true;
					$at_rule = false;
					continue;
				}
			} else {
				++$i;
			}
			$start = false;
		}
		return '' === $out ? $css : $out . substr( $css, $copied );
	}

	/**
	 * The `(--name)` of a prelude replaced with their conditions (third guard of resolve()).
	 *
	 * @param array<mixed> $queries
	 */
	private static function substitute( string $prelude, array $queries ): string {
		$name = static fn ( string $c ): bool => '' !== $c && ( ctype_alnum( $c ) || '_' === $c || '-' === $c || ord( $c ) >= 0x80 );
		return (string) preg_replace_callback(
			'/\(--([a-z][a-z0-9-]{0,30})\)/',
			static function ( array $q ) use ( $prelude, $queries, $name ): string {
				[ $whole, $at ] = $q[0];
				$query          = $queries[ $q[1][0] ] ?? null;
				$after          = $prelude[ $at + strlen( $whole ) ] ?? '';
				if ( ! is_string( $query ) || ! self::condition( $query ) || ( $at > 0 && $name( $prelude[ $at - 1 ] ) ) || $name( $after ) || '(' === $after ) {
					return $whole;
				}
				// `@media(--tablet)`: a space, so the condition never continues the at-keyword.
				return ( 0 === $at ? ' ' : '' ) . $query;
			},
			$prelude,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}
}
