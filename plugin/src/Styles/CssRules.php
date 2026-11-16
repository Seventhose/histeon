<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * A stylesheet split into rules, each knowing what must be on the page for it to possibly apply —
 * docs/spec/styles.md §3. This is how CSS is loaded "only when the element is present".
 *
 * - A style rule applies if at least one selector of its list can match: every class (`.x`) and id (`#x`) the
 *   selector names outside functional pseudo-classes is present on the page. `:is()`, `:where()`, `:not()`,
 *   `:has()` and attribute or type selectors never exclude a rule (conservative: when unsure, keep).
 * - `@media`, `@supports`, `@container`, `@layer {}`, `@scope`, `@starting-style` are descended into and dropped
 *   when nothing inside is kept. Every other at-rule (`@font-face`, `@keyframes`, `@property`, `@import`,
 *   `@layer a, b;` …) and `:root`/element rules are always kept.
 * - Nested rules (native nesting) follow their parent rule.
 *
 * What is printed is meant to be a list of statements, each closed on its own: whatever a page keeps or leaves out,
 * no statement is read otherwise for it, and none goes on into what is printed next (threat model T31, V2 of the
 * fifth review, W1 to W3 of the seventh). It is what the code aims at and what its tests look for, not a proof.
 * At the top level a statement is an at-rule that ends with `;`, or a prelude and its block. A sheet is read from its
 * start for as long as nothing is in doubt (open_blocks()): up to there this reading, CssCompiler and a browser end
 * strings, addresses and blocks at the same places, so what is printed is a part of what was written, with every
 * string, address and block as it was there. Not printed:
 * - a `}` with no block to close (the reading goes on after it);
 * - text that is no statement (`color:red;` outside a block, or a `@` that starts no name: `@;`, `@ layer a;`): on
 *   the page it would spoil the rule that follows (the reading goes on after it). The same inside a group that holds
 *   only rules (`@media`, `@supports`, `@container`, `@layer`, `@starting-style`, `@document`, when no `@scope` is
 *   around them): there too a `;` ends nothing for a browser, and when the page left out the rule that follows the
 *   text, the text took the rule after that one (X1 of the eighth review). Inside `@scope`, and inside any group
 *   within it, this reading takes the block to hold declarations, and there all the text stays where it was written.
 *   That is true of `@scope` itself; of a group inside it, for two browsers of three: Chromium 156 reads no
 *   declaration in `@scope (.a){@media all{color:red}}`, Firefox 157 and WebKit 27.2 do. So inside `@scope` X1 is
 *   not closed (below);
 * - from the first place in doubt, where this reading and a browser may not agree on where a string, an address or a
 *   block ends, everything up to the end of the sheet: where the next statement starts is in doubt too.
 * Taking these away has a price that is known: a browser given the sheet as written throws away the rule that comes
 * after loose text, after a lone `;` (`.a{x:1};.b{y:2}`) or after an at-rule ended by a `}` with no block
 * (`@layer a } .b{y:2}`), and the page keeps that rule. Which rules hold may so differ from the sheet as written;
 * where a string, an address or a block ends does not.
 * Known and not closed (D1 of the tenth review): inside `@scope` the rules a page leaves out, or the `;` dropped
 * after a block, can change how the rules that stay are read. Three forms, all with harmless sheets:
 * - a custom property that holds a block, in the three browsers and both ways: `--x: .c {b} .d{…};` (as written,
 *   and on a page with `.c`, `.d` is part of the value; with no `.c` it is printed `.d{…}` and holds),
 *   `--x: .c {b}; .d{…}` (the `;` alone after the block is not printed: with `.c` on the page `--x: .c{b}.d{…}` is
 *   printed and `.d` no longer holds), `--y:{}; .d{…}` (no class in play: every page prints `--y:{}.d{…}`, and `.d`
 *   no longer holds);
 * - any loose text right in `@scope` that is no valid declaration (`x;`, `@ x;`, `x:y;`, `.q;`): a page with no `.c`
 *   prints `x;.d{…}` out of `x; .c{…} .d{…}`, and in WebKit 27.2 `.d` no longer holds (not with `color:red;` nor
 *   `@é x;`);
 * - loose text in a group inside `@scope` (`@scope (.a){@media all{x; .c{…} .d{…}}}`): with no `.c`, `.d` no longer
 *   holds in Chromium 156 and WebKit 27.2 (in Chromium with a true declaration too, `color:red;`), Firefox 157 well.
 * The effect is low: it is within one statement of one sheet, the author touches only rules of the same sheet, the
 * filter judges addresses the same way inside a value, and no request to another site was seen; outside `@scope`
 * the same random sheets lost or took no rule in the three browsers. Left so on purpose for `d;` in
 * `--x: .c {b} d;`: taking it out would be worse, since with `.c` on the page the property would run on into the next
 * rule for all three. Two ways to narrow it, not tried and not decided (the owner's choice): (a) not to hand
 * $declarations to the groups inside `@scope` (closes the third form; costs the loose declarations of
 * `@scope{@media{…}}`, which Chromium does not read already); (b) inside `@scope` not to read as a rule a prelude
 * that starts with `--name:`, and to keep the `;` after a block (closes the first). The second form does not close
 * without taking declarations away from `@scope`.
 * Nothing else is taken away: between statements only comments and the white space of CSS (a NUL and a vertical tab
 * are not: they stay where they were written), and around a prelude only white space that no backslash escapes
 * (prelude()).
 * A block left open at the end of the sheet is closed there, as a browser does. Strings are read with the one rule
 * of CssCompiler::string_end(), so what CssCompiler printed as one rule is one rule here. Sheets are read one by one
 * (StyleCollector::rules()): what is in doubt in one ends with it.
 *
 * Node shapes (plain arrays, so a parsed sheet can be cached as JSON):
 * - ['r', selector, body, requirements]  requirements: list of alternatives, each a list of 'c:name' / 'i:name';
 *                                         an empty alternative means "always".
 * - ['g', prelude, children]
 * - ['x', css]                            raw, always kept
 *
 * @phpstan-type Node array{0: string, 1: mixed, 2?: mixed, 3?: mixed}
 */
final class CssRules {

	/** Bumped when the node format changes (invalidates caches). */
	public const VERSION = 5;
	private const MAX_DEPTH  = 32;
	/**
	 * White space for CSS. PHP's own lists (trim(), ctype_space()) also hold NUL and the vertical tab, which a browser
	 * reads as part of a name or as a token of their own: dropped, the rule printed would not be the one written.
	 */
	private const SPACE      = " \t\n\r\f";
	private const GROUPS     = array( 'media', 'supports', 'container', 'layer', 'scope', 'starting-style', 'document', '-moz-document' );
	/**
	 * A `@` that starts an at-rule: a name follows it (a letter, `_` or an escape, each also after one `-`, or `--`).
	 * Anything else (`@;`, `@ x`, `@1x`, `@-;`) is loose text. So is a `@` before a character that is not ASCII
	 * (`@é x;`): the three browsers read a name there today, but which of those characters start a name is not the
	 * same in every version of the CSS syntax, no at-rule a browser knows is named so, and with a block of its own
	 * (`@é{…}`) it is printed all the same (X5 of the eighth review).
	 */
	private const AT_RULE    = '/^@(?:--|-?(?:[A-Za-z_]|\\\\))/';

	/** @return list<array<int, mixed>> */
	public static function parse( string $css ): array {
		$i = 0;
		return self::parse_block( $css, $i, 0 );
	}

	/**
	 * Keeps the rules that can apply to a page with these classes and ids.
	 *
	 * @param array<mixed> $nodes
	 */
	public static function select( array $nodes, Presence $page ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			switch ( $node[0] ?? '' ) {
				case 'x':
					$out .= (string) $node[1];
					break;
				case 'g':
					$inner = self::select( (array) $node[2], $page );
					if ( '' !== $inner ) {
						$out .= $node[1] . '{' . $inner . '}';
					}
					break;
				case 'r':
					foreach ( (array) $node[3] as $alternative ) {
						if ( $page->has_all( (array) $alternative ) ) {
							$out .= $node[1] . '{' . $node[2] . '}';
							break;
						}
					}
					break;
			}
		}
		return $out;
	}

	/**
	 * Whole sheet back to text (same rules, comments dropped).
	 *
	 * @param array<mixed> $nodes
	 */
	public static function all( array $nodes ): string {
		return self::select( $nodes, Presence::everything() );
	}

	/**
	 * What one selector list needs: one alternative per complex selector.
	 *
	 * @return list<list<string>>
	 */
	public static function requirements( string $selectors ): array {
		$out = array();
		foreach ( CssCompiler::split_list( $selectors ) as $selector ) {
			$out[] = self::selector_needs( $selector );
		}
		return array() === $out ? array( array() ) : $out;
	}

	/** @return list<string> */
	private static function selector_needs( string $selector ): array {
		$needs = array();
		$len   = strlen( $selector );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selector[ $i ];
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '[' === $c ) {
				$i = self::skip_until( $selector, $i + 1, ']' );
			} elseif ( '(' === $c ) {
				// Functional pseudo-class arguments (:not(.x), :is(.a, .b), :nth-child(2 of .x)): never required.
				$i = self::skip_until( $selector, $i + 1, ')' );
			} elseif ( '"' === $c || "'" === $c ) {
				$i = CssCompiler::string_end( $selector, $i );
			} elseif ( '.' === $c || '#' === $c ) {
				[ $name, $end ] = self::ident( $selector, $i + 1 );
				if ( '' !== $name ) {
					$needs[] = ( '.' === $c ? 'c:' : 'i:' ) . $name;
					$i       = $end - 1;
				}
			}
		}
		return array_values( array_unique( $needs ) );
	}

	/**
	 * A CSS identifier starting at $i, with escapes decoded (`.md\:hidden` → `md:hidden`).
	 *
	 * @return array{0: string, 1: int} Name and the offset after it.
	 */
	private static function ident( string $s, int $i ): array {
		$name = '';
		$len  = strlen( $s );
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				if ( 1 === preg_match( '/\G[0-9a-fA-F]{1,6}\s?/', $s, $m, 0, $i + 1 ) ) {
					$code  = (int) hexdec( trim( $m[0] ) );
					$name .= ( $code > 0 && $code <= 0x10FFFF ) ? mb_chr( $code, 'UTF-8' ) : "\u{FFFD}";
					$i    += 1 + strlen( $m[0] );
				} else {
					$name .= $s[ $i + 1 ];
					$i    += 2;
				}
			} elseif ( ctype_alnum( $c ) || '-' === $c || '_' === $c || ord( $c ) >= 0x80 ) {
				$name .= $c;
				++$i;
			} else {
				break;
			}
		}
		return array( $name, $i );
	}

	// ------------------------------------------------------------------ parsing

	/**
	 * The nodes of a block. $declarations says that the block holds declarations too (`@scope`, and every group inside
	 * it, which Chromium 156 reads with no declarations: D1 in the class comment): only there text that is no statement
	 * is kept.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function parse_block( string $css, int &$i, int $depth, bool $declarations = false ): array {
		$nodes = array();
		$len   = strlen( $css );
		while ( $i < $len ) {
			$i = self::skip_space_and_comments( $css, $i, $nodes );
			if ( $i >= $len ) {
				break;
			}
			if ( '}' === $css[ $i ] ) {
				// A `}` with no block to close: dropped, and the reading goes on. Stopping here lost every rule that came
				// after it; printed, it would spoil the rule that follows.
				++$i;
				continue;
			}
			$stop    = self::scan( $css, $i, '{;}' );
			$prelude = self::prelude( substr( $css, $i, $stop - $i ) );
			if ( $stop >= $len || '{' !== $css[ $stop ] ) {
				// A statement (`@import …;`, `@layer a, b;`), kept as written. Text that is no statement is kept where the
				// block holds declarations (`@scope`), and nowhere else: at the top level and in a group of rules a `;`
				// ends nothing for a browser, and the text would run into the rule printed next, or into the one after
				// it when the page leaves that one out (X1 of the eighth review). A `@` no name follows is such text
				// too (W3 of the seventh review).
				if ( 0 === $depth && '' !== $prelude && 0 !== self::open_blocks( $prelude ) ) {
					$i = $len;
					break;
				}
				if ( '' !== $prelude && ( $declarations || 1 === preg_match( self::AT_RULE, $prelude ) ) ) {
					$nodes[] = array( 'x', $prelude . ';' );
				}
				// A `}` is left where it is: the next turn drops it.
				$i = $stop < $len && ';' === $css[ $stop ] ? $stop + 1 : $stop;
				continue;
			}
			$close = self::matching_brace( $css, $stop );
			if ( 0 === $depth ) {
				// Top level: only a statement read with nothing in doubt, closed here when the sheet ends before it does.
				// After one in doubt nothing more is read: where the next statement starts is in doubt too.
				$open = self::open_blocks( substr( $css, $i, $close + 1 - $i ) );
				if ( null === $open || ( $open > 0 && $close < $len ) ) {
					$i = $len;
					break;
				}
				if ( $open > 0 ) {
					$css  .= str_repeat( '}', $open );
					$len  += $open;
					$close = $len - 1;
				}
			}
			$body = substr( $css, $stop + 1, $close - $stop - 1 );
			$i    = $close + 1;
			if ( str_starts_with( $prelude, '@' ) ) {
				$name = strtolower( (string) preg_replace( '/^@([-\w]+).*$/s', '$1', $prelude ) );
				if ( in_array( $name, self::GROUPS, true ) && $depth < self::MAX_DEPTH ) {
					$j       = 0;
					$nodes[] = array( 'g', $prelude, self::parse_block( $body, $j, $depth + 1, $declarations || 'scope' === $name ) );
				} else {
					$nodes[] = array( 'x', $prelude . '{' . $body . '}' );
				}
				continue;
			}
			$nodes[] = array( 'r', $prelude, $body, self::requirements( $prelude ) );
		}
		return $nodes;
	}

	/**
	 * A prelude without the white space around it. A space written after a backslash is not white space: it is a
	 * character of a name (`.a\ `) and stays where it is. Taken away, the backslash would take the `{` or the `;`
	 * printed after it, and the rule would not end where it was written to end (W2 of the seventh review).
	 */
	private static function prelude( string $text ): string {
		$text = ltrim( $text, self::SPACE );
		$kept = rtrim( $text, self::SPACE );
		$bars = strlen( $kept ) - strlen( rtrim( $kept, '\\' ) );
		return 1 === $bars % 2 && strlen( $kept ) < strlen( $text ) ? $kept . $text[ strlen( $kept ) ] : $kept;
	}

	/**
	 * How many `{` a statement leaves open, or null when it cannot be read with nothing in doubt: when this reading
	 * and a browser may not agree on where a string, an address or a block ends. A statement that gives 0 is closed
	 * for both, so it can be printed or left out without changing how anything else is read. In doubt:
	 * - a string that is not closed, or that holds a new line (a browser ends it there);
	 * - a comment that is not closed;
	 * - a backslash before a new line or at the end, and a name written with an escape before a `(` (it may be url();
	 * - a `url(` whose address has no quotes and holds a quote, a `(`, a `[`, a `]`, a backslash, a brace or a comment
	 *   opener, or is not closed: read as an address and read as the arguments of a function, it must end at the same
	 *   `)`. The three letters before the `(` are enough to come here, so a longer name does too (`xurl(`, `#url(`),
	 *   which a browser reads as a function: there a `[` opens a block that the `)` does not close (W1 of the seventh
	 *   review). A `]` closes nothing for a browser, but scan() counts round and square brackets together and takes
	 *   it for the end of the `(`: a `;` after it in the address would then end a statement inside a group, and the
	 *   white space before that `;` would be taken out of the address;
	 * - a `{`, a `}` or a `;` inside round or square brackets, and a bracket closed by the other kind;
	 * - a round or square bracket still open at the end.
	 */
	private static function open_blocks( string $text ): ?int {
		$len   = strlen( $text );
		$stack = '';
		$name  = -1; // Where the text after the last escape starts.
		for ( $i = 0; $i < $len; $i++ ) {
			$i += strcspn( $text, "\\\"'/()[]{};", $i );
			if ( $i >= $len ) {
				break;
			}
			$c   = $text[ $i ];
			$top = '' === $stack ? '' : $stack[-1];
			if ( '\\' === $c ) {
				$next = $text[ $i + 1 ] ?? "\n";
				if ( "\n" === $next || "\r" === $next || "\f" === $next ) {
					return null;
				}
				$name = ++$i + 1;
			} elseif ( '"' === $c || "'" === $c ) {
				$end = CssCompiler::string_end( $text, $i );
				if ( $end >= $len || strcspn( $text, "\n\r\f", $i, $end - $i ) < $end - $i ) {
					return null;
				}
				$i = $end;
			} elseif ( '/' === $c ) {
				if ( '*' === ( $text[ $i + 1 ] ?? '' ) ) {
					$end = strpos( $text, '*/', $i + 2 );
					if ( false === $end ) {
						return null;
					}
					$i = $end + 1;
				}
			} elseif ( '(' === $c ) {
				// Only name characters and white space between the last escape and this `(`: one name, for a browser.
				if ( $name >= 0 && 1 === preg_match( '/\G[A-Za-z0-9_\-\s\x80-\xff]*+\(/', $text, $m, 0, $name ) ) {
					return null;
				}
				$name = -1;
				$from = $i + 1 + strspn( $text, " \t\n\r\f", $i + 1 );
				if ( $i >= 3 && 0 === strcasecmp( substr( $text, $i - 3, 3 ), 'url' ) && '"' !== ( $text[ $from ] ?? '' ) && "'" !== ( $text[ $from ] ?? '' ) ) {
					$end = strpos( $text, ')', $from );
					if ( false === $end || strcspn( $text, "\"'([]\\{}", $from, $end - $from ) < $end - $from || str_contains( substr( $text, $from, $end - $from ), '/*' ) ) {
						return null;
					}
					$i = $end;
				} else {
					$stack .= '(';
				}
			} elseif ( '[' === $c ) {
				$stack .= '[';
			} elseif ( ')' === $c || ']' === $c ) {
				if ( ( ')' === $c ? '(' : '[' ) === $top ) {
					$stack = substr( $stack, 0, -1 );
				} elseif ( '(' === $top || '[' === $top ) {
					return null;
				}
			} else {
				if ( '(' === $top || '[' === $top || ( '}' === $c && '' === $stack ) ) {
					return null;
				}
				if ( '{' === $c ) {
					$stack .= '{';
				} elseif ( '}' === $c ) {
					$stack = substr( $stack, 0, -1 );
				}
			}
		}
		return '' === $stack || '{' === $stack[-1] ? strlen( $stack ) : null;
	}

	/**
	 * Skips whitespace and comments; `/*! … *\/` (license) comments are kept as raw nodes.
	 *
	 * @param list<array<int, mixed>> $nodes
	 */
	private static function skip_space_and_comments( string $css, int $i, array &$nodes ): int {
		$len = strlen( $css );
		while ( $i < $len ) {
			if ( str_contains( self::SPACE, $css[ $i ] ) ) {
				++$i;
			} elseif ( str_starts_with( substr( $css, $i, 2 ), '/*' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				// One left open at the end is not kept: printed, it would take in everything printed after it.
				if ( false !== $end && '!' === ( $css[ $i + 2 ] ?? '' ) ) {
					$nodes[] = array( 'x', substr( $css, $i, $end + 2 - $i ) . "\n" );
				}
				$i = false === $end ? $len : $end + 2;
			} else {
				break;
			}
		}
		return $i;
	}

	/** First top-level occurrence of a stop character, skipping strings, comments and (), [] groups. */
	private static function scan( string $css, int $i, string $stops ): int {
		$len   = strlen( $css );
		$depth = 0;
		for ( ; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = CssCompiler::string_end( $css, $i );
			} elseif ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 1;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ( ')' === $c || ']' === $c ) && $depth > 0 ) {
				--$depth;
			} elseif ( 0 === $depth && str_contains( $stops, $c ) ) {
				return $i;
			}
		}
		return $len;
	}

	private static function matching_brace( string $css, int $open ): int {
		$len   = strlen( $css );
		$depth = 0;
		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = CssCompiler::string_end( $css, $i );
			} elseif ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 1;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				return $i;
			}
		}
		return $len;
	}

	/** Offset of the matching close character (nesting of the same pair respected, strings skipped). */
	private static function skip_until( string $s, int $i, string $close ): int {
		$open  = ')' === $close ? '(' : '[';
		$depth = 1;
		$len   = strlen( $s );
		for ( ; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = CssCompiler::string_end( $s, $i );
			} elseif ( $c === $open ) {
				++$depth;
			} elseif ( $c === $close && 0 === --$depth ) {
				return $i;
			}
		}
		return $len;
	}
}
