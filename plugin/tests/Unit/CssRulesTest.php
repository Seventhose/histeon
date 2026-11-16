<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Styles\CssCompiler;
use XB\Styles\CssRules;
use XB\Styles\Presence;

final class CssRulesTest extends TestCase {

	private static function page( string ...$tokens ): Presence {
		$page = new Presence();
		$page->add_safelist( array_values( $tokens ) );
		return $page;
	}

	private static function pick( string $css, Presence $page ): string {
		return CssRules::select( CssRules::parse( $css ), $page );
	}

	public function test_keeps_only_rules_whose_classes_and_ids_are_present(): void {
		$css = '.a{color:red}.b{color:blue}#hero{margin:0}.a .c{x:1}.a.b{y:2}';
		$this->assertSame( '.a{color:red}', self::pick( $css, self::page( '.a' ) ) );
		$this->assertSame( '.a{color:red}.b{color:blue}.a.b{y:2}', self::pick( $css, self::page( '.a', '.b' ) ) );
		$this->assertSame( '#hero{margin:0}', self::pick( $css, self::page( '#hero' ) ) );
	}

	public function test_any_selector_of_a_list_is_enough(): void {
		$this->assertSame( '.a, .b{color:red}', self::pick( '.a, .b{color:red}', self::page( '.b' ) ) );
		$this->assertSame( '', self::pick( '.a, .b{color:red}', self::page( '.c' ) ) );
	}

	public function test_rules_without_classes_or_ids_are_always_kept(): void {
		$css = ':root{--x:1}h1{margin:0}*,::before{box-sizing:border-box}[class*="grid--"]{display:grid}';
		$this->assertSame( $css, self::pick( $css, self::page() ) );
	}

	public function test_functional_pseudo_classes_never_exclude(): void {
		$css = '.a:not(.b){x:1}:is(.c, .d) .e{y:2}.f:has(> .g){z:3}';
		$this->assertSame( '.a:not(.b){x:1}', self::pick( $css, self::page( '.a' ) ) );
		$this->assertSame( ':is(.c, .d) .e{y:2}', self::pick( $css, self::page( '.e' ) ) );
		$this->assertSame( '.f:has(> .g){z:3}', self::pick( $css, self::page( '.f' ) ) );
	}

	public function test_escaped_class_names(): void {
		$this->assertSame( '.md\:hidden{display:none}', self::pick( '.md\:hidden{display:none}', self::page( 'md:hidden' ) ) );
		$this->assertSame( '.w-1\/2{width:50%}', self::pick( '.w-1\/2{width:50%}', self::page( 'w-1/2' ) ) );
		$this->assertSame( '.\31 0col{x:1}', self::pick( '.\31 0col{x:1}', self::page( '10col' ) ) );
	}

	public function test_groups_are_descended_and_dropped_when_empty(): void {
		$css = '@layer base{.a{x:1}.b{y:2}}@media (width>40em){.b{z:3}}@supports (display:grid){h1{w:4}}';
		$this->assertSame( '@layer base{.a{x:1}}@supports (display:grid){h1{w:4}}', self::pick( $css, self::page( '.a' ) ) );
	}

	public function test_other_at_rules_and_statements_are_always_kept(): void {
		$css = '@layer reset, base;@import url("x.css");@font-face{font-family:X;src:url(x.woff2)}@keyframes spin{to{rotate:1turn}}';
		$this->assertSame( $css, self::pick( $css, self::page() ) );
	}

	public function test_nested_rules_follow_their_parent(): void {
		$css = '.card{padding:1rem;&:hover{color:red}& .unused{x:1}@media (width>40em){padding:2rem}}';
		$this->assertSame( $css, self::pick( $css, self::page( '.card' ) ) );
		$this->assertSame( '', self::pick( $css, self::page( '.unused' ) ) );
	}

	public function test_strings_comments_and_braces_inside_values(): void {
		$css = "/* a { b } */.a::before{content:\"} .b {\"}/*! keep: MIT */.b{x:1}";
		$this->assertSame( ".a::before{content:\"} .b {\"}/*! keep: MIT */\n", self::pick( $css, self::page( '.a' ) ) );
	}

	public function test_safelist_prefixes(): void {
		$this->assertSame( '.is-open{x:1}', self::pick( '.is-open{x:1}.was-open{y:2}', self::page( '.is-*' ) ) );
	}

	public function test_everything_keeps_every_rule(): void {
		$css = '.a{x:1}@media print{#b{y:2}}';
		$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
	}

	// ---- Threat model T31, V2 of the fifth review: what is printed is a list of statements, each closed on its own.

	/**
	 * The statements of the top level as a browser reads them, or null when the text is not a list of closed
	 * statements: a string, a comment, a bracket or a block left open, a new line in a string, a `}` with no block,
	 * text that no block or `;` of an at-rule ends. A name written with an escape before a `(` gives null too: it may
	 * be `url(`, and this reading does not decode it. As in CssRules::open_blocks(), that is any `(` with only name
	 * characters and white space between the last escape and it: in `\41 url(` the space ends the escape and the name
	 * goes on, so a browser reads the function `Aurl(` (X3 of the eighth review).
	 *
	 * @return list<string>|null
	 */
	private static function statements( string $css ): ?array {
		$out   = array();
		$stack = '';
		$start = -1;
		$at    = false;
		$name  = -1; // Where the text after the last escape starts.
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 1;
				continue;
			}
			if ( $start < 0 ) {
				if ( str_contains( " \t\n\r\f", $c ) ) {
					continue;
				}
				$start = $i;
				$at    = 1 === preg_match( '/\G@(?:--|-?(?:[A-Za-z_\x80-\xff\x00]|\\\\[^\n\r\f]))/', $css, $m, 0, $i );
			}
			if ( '\\' === $c ) {
				if ( $i + 1 >= $len ) {
					return null;
				}
				if ( ! str_contains( "\n\r\f", $css[ $i + 1 ] ) ) {
					$name = ++$i + 1;
				}
			} elseif ( '"' === $c || "'" === $c ) {
				for ( ++$i; $i < $len && $css[ $i ] !== $c; $i++ ) {
					if ( str_contains( "\n\r\f", $css[ $i ] ) ) {
						return null;
					}
					$i += '\\' === $css[ $i ] ? 1 : 0;
				}
				if ( $i >= $len ) {
					return null;
				}
			} elseif ( '(' === $c ) {
				if ( $name >= 0 && 1 === preg_match( '/\G[A-Za-z0-9_\-\s\x80-\xff]*+\(/', $css, $m, 0, $name ) ) {
					return null;
				}
				$name = -1;
				// The name before the `(`: `url` alone, with no quote after, is an address that ends at the first `)`.
				if ( self::address( $css, $i ) ) {
					for ( ++$i; $i < $len && ')' !== $css[ $i ]; $i++ ) {
						$i += '\\' === $css[ $i ] ? 1 : 0;
					}
					if ( $i >= $len ) {
						return null;
					}
				} else {
					$stack .= '(';
				}
			} elseif ( '[' === $c || '{' === $c ) {
				$stack .= $c;
			} elseif ( ')' === $c || ']' === $c || '}' === $c ) {
				if ( '' !== $stack && array( ')' => '(', ']' => '[', '}' => '{' )[ $c ] === $stack[-1] ) {
					$stack = substr( $stack, 0, -1 );
					if ( '' === $stack && '}' === $c ) {
						$out[] = substr( $css, $start, $i + 1 - $start );
						$start = -1;
					}
				} elseif ( '' === $stack && '}' === $c ) {
					return null;
				}
			} elseif ( ';' === $c && '' === $stack && $at ) {
				$out[] = substr( $css, $start, $i + 1 - $start );
				$start = -1;
			}
		}
		return '' === $stack && $start < 0 ? $out : null;
	}

	/**
	 * Whether the `(` at $i opens an address without quotes: the name before it is `url` alone (not `xurl`, `#url`,
	 * `@url`, `1url`, `-url`) and no quote follows. A name written with an escape is not looked at: for CssRules it
	 * is in doubt, and statements() gives null before coming here.
	 */
	private static function address( string $css, int $i ): bool {
		$from = $i;
		while ( $from > 0 && 1 === preg_match( '/[A-Za-z0-9_\-\x80-\xff\x00]/', $css[ $from - 1 ] ) ) {
			--$from;
		}
		$before = $from > 0 ? $css[ $from - 1 ] : '';
		$quote  = $css[ $i + 1 + strspn( $css, " \t\n\r\f", $i + 1 ) ] ?? '';
		return 'url' === strtolower( substr( $css, $from, $i - $from ) ) && ! in_array( $before, array( '#', '@', '\\' ), true ) && '"' !== $quote && "'" !== $quote;
	}

	/** Read as a browser reads it: a text made only of closed statements. */
	private static function closed( string $css ): bool {
		return null !== self::statements( $css );
	}

	/**
	 * A text cut into the pieces whose white space counts. A string, an address without quotes, an escape (with the
	 * white space that ends a hexadecimal one) and a comment are one piece each; every other byte is a piece of its
	 * own. White space between pieces is no piece: it is remembered on the piece that follows it.
	 *
	 * @return list<array{0: string, 1: bool}> Each piece, and whether white space comes before it.
	 */
	private static function pieces( string $css ): array {
		$out   = array();
		$space = false;
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i += $n ) {
			$c = $css[ $i ];
			$n = 1;
			if ( str_contains( " \t\n\r\f", $c ) ) {
				$space = true;
				continue;
			}
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$n   = ( false === $end ? $len : $end + 2 ) - $i;
			} elseif ( '\\' === $c ) {
				// Before a new line or at the end it is no escape: a piece of its own.
				$n = 1 === preg_match( '/\G\\\\(?:[0-9A-Fa-f]{1,6}(?:\r\n|[ \t\n\r\f])?|[^\n\r\f])/', $css, $m, 0, $i ) ? strlen( $m[0] ) : 1;
			} elseif ( '"' === $c || "'" === $c ) {
				// Up to the quote that closes it, or to the new line where a browser ends it.
				for ( $j = $i + 1; $j < $len && $css[ $j ] !== $c && ! str_contains( "\n\r\f", $css[ $j ] ); $j++ ) {
					$j += '\\' === $css[ $j ] ? 1 : 0;
				}
				$n = min( $len, $j < $len && $css[ $j ] === $c ? $j + 1 : $j ) - $i;
			} elseif ( '(' === $c && self::address( $css, $i ) ) {
				for ( $j = $i + 1; $j < $len && ')' !== $css[ $j ]; $j++ ) {
					$j += '\\' === $css[ $j ] ? 1 : 0;
				}
				$n = min( $len, $j + 1 ) - $i;
			}
			$out[] = array( substr( $css, $i, $n ), $space );
			$space = false;
		}
		return $out;
	}

	/**
	 * Whether a printed text is the text given: its pieces (pieces()) are pieces of the sheet, in the same order and
	 * with the same white space between them. The printed text is taken run by run, a run going up to its next `{`,
	 * `;` or `}`:
	 * - inside a run nothing of the sheet may be left out, and white space is where it was written;
	 * - before a run the sheet may hold more (comments, text that is not printed, a `}` with no block), and the white
	 *   space before its first piece and before the `{`, `;` or `}` that ends it may have been taken away;
	 * - a `;` that the sheet does not have there is one that was added, when the sheet has a `}` there or has ended;
	 *   so is a `}`, when the sheet has ended (a block left open);
	 * - the white space that ends a hexadecimal escape may be gone before a `{` or a `;`: the name is the same;
	 * - after a license comment (`/*!`) there may be the new line that CssRules prints.
	 * It sees a piece that is closed and is not what was written (`url(a]b;c)` for `url(a]b ;c)`, `x\;` for `x\ ;`).
	 * It does not see what was left out between two runs: that is what the fixed cases say, one by one.
	 */
	private static function written( string $printed, string $sheet ): bool {
		$p    = self::pieces( $printed );
		$s    = self::pieces( $sheet );
		$ends = array( '{', ';', '}' );
		$at   = 0; // The first piece of the sheet that no run has used.
		for ( $i = 0, $n = count( $p ); $i < $n; $i = $last + 1 ) {
			for ( $last = $i; $last < $n - 1 && ! in_array( $p[ $last ][0], $ends, true ); $last++ );
			// The first place of the sheet, from $at on, where the whole run is found.
			for ( $next = null; null === $next && $at <= count( $s ); $at++ ) {
				$y = $at;
				for ( $x = $i; $x <= $last; $x++ ) {
					[ $text, $space ] = $p[ $x ];
					$end              = in_array( $text, $ends, true );
					$there            = $s[ $y ] ?? array( '', false );
					$same             = $text === $there[0]
						|| ( $x < $last && strlen( $text ) > 1 && '\\' === $text[0] && in_array( $p[ $x + 1 ][0], array( '{', ';' ), true ) && rtrim( $there[0], " \t\n\r\f" ) === $text );
					$note             = $x > 0 && str_starts_with( $p[ $x - 1 ][0], '/*!' );
					if ( $same && ( $note || ( $x === $i || $end ? ! $space || $there[1] : $space === $there[1] ) ) ) {
						++$y;
					} elseif ( ! ( $y >= count( $s ) ? ';' === $text || '}' === $text : ';' === $text && '}' === $there[0] ) ) {
						continue 2;
					}
				}
				$next = $y;
			}
			if ( null === $next ) {
				return false;
			}
			$at = $next;
		}
		return true;
	}

	public function test_a_rule_the_compiler_printed_is_never_cut(): void {
		// One rule for CssCompiler, where a string does not end at a new line. Reading it with another rule cut it
		// after `k=`, in the middle of an address, and printed that.
		$rule = CssCompiler::compile( '.s', "a:\"\n\";content:\"a;b}c\";src:url(\"media/a.png?k=}{\")" );
		$this->assertSame( ".s{a:\"\n\";content:\"a;b}c\";src:url(\"media/a.png?k=}{\");}", $rule );
		$printed = CssRules::all( CssRules::parse( $rule ) );
		$this->assertStringNotContainsString( 'k=;', $printed );
		$this->assertSame( '', $printed, 'a string that holds a new line is in doubt: the rule is not printed' );
		// The same string on one line is no doubt: the rule comes back whole.
		$plain = CssCompiler::compile( '.s', 'a:"x";content:"a;b}c";src:url("media/a.png?k=}{")' );
		$this->assertSame( $plain, CssRules::all( CssRules::parse( $plain ) ) );
	}

	public function test_an_extra_closing_brace_does_not_end_the_reading(): void {
		$this->assertSame( '.a{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.a{x:1}}.b{y:2}' ) ) );
		$this->assertSame( '.a{x:1}.b{y:2}', CssRules::all( CssRules::parse( '}}.a{x:1}} }.b{y:2}}' ) ) );
		$this->assertSame( '@media print{.a{x:1}}.b{y:2}', CssRules::all( CssRules::parse( '@media print{.a{x:1}}}.b{y:2}' ) ) );
		// What CssCompiler prints for a style with one `}` too many, then the next style.
		$css = CssCompiler::compile( '.s', 'color:red}' ) . "\n" . CssCompiler::compile( '.after', 'color:green' ) . "\n";
		$this->assertSame( '.s{color:red}.after{color:green;}', CssRules::all( CssRules::parse( $css ) ) );
	}

	public function test_what_is_in_doubt_is_not_printed_nor_what_follows_it_in_the_sheet(): void {
		$doubts = array(
			'a new line in a string'             => ".c{content:\"x\ny\"}",
			'a string that is not closed'        => '.c{content:"x}',
			'a backslash before a new line'      => ".c{x:\\\n1}",
			'a name with an escape before a ('   => '.c{b:\\75rl(x)}',
			'a quote in an address with none'    => '.c{b:url(x"y")}',
			'a ( in an address without quotes'   => '.c{b:url(x(y)}',
			'a comment in an address'            => '.c{b:url(/*x*/y)}',
			'an address that is not closed'      => '.c{b:url(x}',
			'a ; inside round brackets'          => '.c{w:calc(1px;)}',
			'a brace inside square brackets'     => '.c{--j:[{}]}',
			'a bracket closed by the other kind' => '.c{w:(1]}',
			'an at-rule in doubt'                => "@import \"x\ny.css\";",
		);
		foreach ( $doubts as $what => $doubt ) {
			$this->assertSame( '.a{x:1}', CssRules::all( CssRules::parse( '.a{x:1}' . $doubt . '.b{y:2}' ) ), $what );
			$this->assertSame( '', CssRules::all( CssRules::parse( $doubt . '.b{y:2}' ) ), $what );
		}
		// A comment left open takes nothing with it: the license comment is not kept either.
		$this->assertSame( '.a{x:1}', CssRules::all( CssRules::parse( '.a{x:1}/* open .b{y:2}' ) ) );
		$this->assertSame( "/*! MIT */\n.a{x:1}", CssRules::all( CssRules::parse( '/*! MIT */.a{x:1}/*! open .b{y:2}' ) ) );
	}

	public function test_what_only_looks_in_doubt_is_printed_as_before(): void {
		$css = '.md\\:a:not(.b){x:1}.a{b:url(data:image/png;base64,AAA=)}.b{c:url( "x(y);z" )}.c{content:"}{;"}.d{w:calc(1px + (2 * 1rem))}'
			. '[data-x="a]b"]{y:2}.e{content:"\\201C";f:"it\'s"}.f\\(x\\){g:1}.g{x:1)}';
		$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
	}

	public function test_text_that_is_no_statement_is_not_printed_at_the_top_level(): void {
		$this->assertSame( '.a{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.a{x:1}color:red;.b{y:2}' ) ) );
		$this->assertSame( '.a{x:1}', CssRules::all( CssRules::parse( '.a{x:1}color:red' ) ) );
		$this->assertSame( '.a{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.a{x:1}color:red}.b{y:2}' ) ) );
		// Inside `@scope` the block holds it (declarations): kept, as before.
		$scope = '@scope (.c){color:red;.b{y:2}}';
		$this->assertSame( $scope, CssRules::all( CssRules::parse( $scope ) ) );
		// An at-rule with no `;` at the end of the sheet is a statement: closed with one.
		$this->assertSame( '.a{x:1}@layer a, b;', CssRules::all( CssRules::parse( '.a{x:1}@layer a, b' ) ) );
	}

	public function test_a_nul_and_a_vertical_tab_are_not_white_space(): void {
		// For a browser they are part of the selector (a NUL is a name character, a vertical tab a token of its own):
		// taken away as white space, the rule printed would match `h2`, which the rule written does not.
		foreach ( array( "\0", "\x0B" ) as $byte ) {
			$this->assertSame( $byte . 'h2{x:1}.a{y:2}', CssRules::all( CssRules::parse( $byte . 'h2{x:1}.a{y:2}' ) ) );
			$this->assertSame( '.a{y:2}h2 ' . $byte . '{x:1}', CssRules::all( CssRules::parse( ".a{y:2}\nh2 " . $byte . "\n{x:1}" ) ) );
			// Before an at-keyword it makes the statement text that is no statement: not printed at the top level.
			$this->assertSame( '.a{y:2}', CssRules::all( CssRules::parse( $byte . '@layer a, b;.a{y:2}' ) ) );
			// Inside `@scope` it stays where it was written; in a group of rules it is text that is no statement.
			$this->assertSame( '@scope (.c){.a{y:2}' . $byte . ';}', CssRules::all( CssRules::parse( '@scope (.c){.a{y:2}' . $byte . '}' ) ) );
			$this->assertSame( '@media print{.a{y:2}}', CssRules::all( CssRules::parse( '@media print{.a{y:2}' . $byte . '}' ) ) );
		}
		// The white space of CSS, form feed included, is still skipped.
		$this->assertSame( 'h2{x:1}.a{y:2}', CssRules::all( CssRules::parse( "\f\t h2\r\n{x:1}\f.a{y:2}" ) ) );
	}

	public function test_a_block_left_open_at_the_end_of_the_sheet_is_closed(): void {
		$this->assertSame( '.a{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.a{x:1}.b{y:2' ) ) );
		$this->assertSame( '.a{&:hover{x:1}}', CssRules::all( CssRules::parse( '.a{&:hover{x:1' ) ) );
		$this->assertSame( '@media print{.a{x:1}}', CssRules::all( CssRules::parse( '@media print{.a{x:1' ) ) );
		$this->assertSame( '@font-face{src:url("x.woff2")}', CssRules::all( CssRules::parse( '@font-face{src:url("x.woff2")' ) ) );
	}

	// ---- W1, W2 and W3 of the seventh review: three ways a printed piece was not closed.

	public function test_a_square_bracket_in_an_address_without_quotes_is_in_doubt(): void {
		// The three letters before the `(` are `url`, but the name is longer: a browser reads a function, where the `[`
		// opens a block that the `)` does not close. Printed, it took in everything printed after it.
		foreach ( array( '.a{--x:xurl(a[b)}', '.a{--x:#url(a[b)}', '.a{--x:-url(a[b)}', '.a{--x:1url(a[b)}', '.a{color:xurl(a[b)}', '@x xurl(a[b);', '@media all{.a{--x:xurl(a[b)}}', '.a{--x:url(a[b)}' ) as $css ) {
			$this->assertSame( '.z{x:1}', CssRules::all( CssRules::parse( '.z{x:1}' . $css . '.b{y:2}' ) ), $css );
		}
		// Brackets that close where both readings say so are printed as before.
		foreach ( array( '.a{--x:xcalc(a[b])}.b{y:2}', '.a[x]{--x:calc(1px)}.b{y:2}', '.a{--x:url("a[b")}.b{y:2}' ) as $css ) {
			$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		}
	}

	public function test_a_closing_square_bracket_in_an_address_without_quotes_is_in_doubt(): void {
		// Found while correcting W1. Inside a group the `]` was taken for the end of the `(`, the `;` after it for the
		// end of a statement, and the space before that `;` was taken out of the address: `url(a]b;c)` was printed
		// where `url(a]b ;c)` was written, an address a browser reads where it read none.
		foreach ( array( '@scope (.a){x:url(a]b ;c);.b{y:2}}', '@media all{.a{x:1}y:xurl(a]b ;c)}', '.a{x:url(a]b)}' ) as $css ) {
			$this->assertSame( '.z{x:1}', CssRules::all( CssRules::parse( '.z{x:1}' . $css . '.b{y:2}' ) ), $css );
		}
		// With quotes the address is a string, and is printed as it was written.
		$css = '@scope (.a){x:url("a]b ;c");.b{y:2}}';
		$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
	}

	public function test_a_space_written_with_a_backslash_stays_in_the_prelude(): void {
		// It is a character of the name. Taken away, the backslash took the `{` or the `;` printed after it.
		foreach ( array( '.a\\ {x:1}.b{y:2}', ".a\\\t{x:1}.b{y:2}", '@media all{.b\\ {x:1}}.b{y:2}', '@scope (.a){x\\ ;.b{x:1}}.b{y:2}', '@media all\\ {.b{x:1}}.b{y:2}', '@layer a\\ ;.b{y:2}' ) as $css ) {
			$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		}
		$this->assertSame( '.a\\ {x:1}.b{y:2}', CssRules::all( CssRules::parse( " .a\\  \n {x:1} .b {y:2}" ) ) );
		// White space that no backslash escapes is taken away as before.
		$this->assertSame( '.a\\\\{x:1}', CssRules::all( CssRules::parse( '.a\\\\ {x:1}' ) ) );
		$this->assertSame( '.a\\41{x:1}', CssRules::all( CssRules::parse( '.a\\41 {x:1}' ) ) );
	}

	public function test_an_at_sign_that_starts_no_name_is_not_an_at_rule(): void {
		// For a browser it is loose text, and its `;` ends nothing: printed, it spoiled the rule that follows.
		foreach ( array( '@;', '@ layer a;', '@1x;', '@-;', '@-1;', "@\n;", "@\0x;" ) as $loose ) {
			$this->assertSame( '.z{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.z{x:1}' . $loose . '.b{y:2}' ) ), $loose );
		}
		$this->assertSame( '.z{x:1}', CssRules::all( CssRules::parse( '.z{x:1}@' ) ) );
		// A name follows the `@`: an at-rule, printed as before.
		foreach ( array( '@--x;', '@--;', '@layer a;', '@-moz-x y;', '@_x;', '@\\6c ayer a;', '@-\\6c ayer a;', '@\\ x;' ) as $rule ) {
			$this->assertSame( '.z{x:1}' . $rule . '.b{y:2}', CssRules::all( CssRules::parse( '.z{x:1}' . $rule . '.b{y:2}' ) ), $rule );
		}
		// Inside `@scope` the block holds it, and with a block of its own it ends there: both as written.
		foreach ( array( '@scope (.a){@ x;.b{y:2}}', '@ media{.a{x:1}}.b{y:2}' ) as $css ) {
			$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		}
	}

	// ---- X1 and X5 of the eighth review.

	public function test_an_at_sign_before_a_character_that_is_not_ascii_is_not_an_at_rule(): void {
		// The three browsers read a name there today; which of those characters start a name is not the same in every
		// version of the CSS syntax, and no at-rule a browser knows is named so: it is taken as loose text.
		foreach ( array( "@\xc3\xa9 x;", "@-\xc3\xa9 x;", "@\xc2\xa0x;", "@\xc3\x97;", "@\xe2\x80\xa8x;", "@\xf0\x9f\x98\x80 x;", "@\x80;" ) as $loose ) {
			$this->assertSame( '.z{x:1}.b{y:2}', CssRules::all( CssRules::parse( '.z{x:1}' . $loose . '.b{y:2}' ) ), $loose );
			$this->assertSame( '@media all{.b{y:2}}', CssRules::all( CssRules::parse( '@media all{' . $loose . '.b{y:2}}' ) ), $loose );
		}
		// After `--` a name has started whatever follows; later in a name such a character is one of its own.
		foreach ( array( "@--\xc3\xa9 x;", "@x\xc3\xa9 y;", "@-x\xc3\xa9;" ) as $rule ) {
			$this->assertSame( '.z{x:1}' . $rule . '.b{y:2}', CssRules::all( CssRules::parse( '.z{x:1}' . $rule . '.b{y:2}' ) ), $rule );
		}
		// With a block of its own it ends there, and is printed as written.
		foreach ( array( "@\xc3\xa9{x:1}.b{y:2}", "@\xc3\xa9 x{.a{x:1}}.b{y:2}", "@media all{@\xc3\xa9{x:1}.b{y:2}}" ) as $css ) {
			$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		}
	}

	public function test_text_that_is_no_statement_is_not_printed_in_a_group_of_rules(): void {
		// For a browser a `;` ends nothing there, as at the top level: the text is the start of the rule that follows.
		// Printed, when the page left that rule out it took the one after it (`x;.d{…}`), and `.d` held no more.
		foreach ( array( '@media all', '@layer q', '@supports (color:red)', '@container (width>1px)', '@starting-style', '@media all{@layer q', '@MEDIA all' ) as $group ) {
			$end   = str_contains( $group, '{' ) ? '}}' : '}';
			$nodes = CssRules::parse( $group . '{x; .c{color:red} .d{color:green}' . $end );
			$this->assertSame( $group . '{.c{color:red}.d{color:green}' . $end, CssRules::all( $nodes ), $group );
			$this->assertSame( $group . '{.d{color:green}' . $end, CssRules::select( $nodes, self::page( '.d' ) ), $group );
		}
		foreach ( array( '@ x;', 'color:red;', '--x:1;', '@1x;', 'x' ) as $loose ) {
			$this->assertSame( '@media all{.d{color:green}}', self::pick( '@media all{' . $loose . ' .c{color:red} .d{color:green}}', self::page( '.d' ) ), $loose );
		}
		$this->assertSame( '@media all{.a{x:1}}', CssRules::all( CssRules::parse( '@media all{.a{x:1} x}' ) ) );
		// A group that held nothing else is dropped with it.
		$this->assertSame( '.b{y:2}', CssRules::all( CssRules::parse( '@media all{x}.b{y:2}' ) ) );
		// An at-rule that ends with `;` is a statement there too, and stays.
		$css = '@media all{@layer a, b;.d{color:green}}';
		$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		// Inside `@scope`, and inside a group within it, a block holds declarations: all of it as written.
		foreach ( array( '@scope (.a){color:red;.c{gap:0}.d{color:green}}', '@scope (.a){@media all{color:red;.c{gap:0}x;}}', '@media all{@scope (.a){color:red;.d{gap:0}}}' ) as $css ) {
			$this->assertSame( $css, CssRules::all( CssRules::parse( $css ) ) );
		}
		$this->assertSame( '@scope (.a){color:red;.d{color:green}}', self::pick( '@scope (.a){color:red; .c{gap:0} .d{color:green}}', self::page( '.d' ) ) );
		// After the `@scope` has ended, the group around it holds only rules again.
		$this->assertSame( '@media all{@scope (.a){color:red;}.d{z:1}}', self::pick( '@media all{@scope (.a){color:red;.c{x:1}}y;.d{z:1}}', self::page( '.d' ) ) );
		// Known and not closed: a custom property with a block inside `@scope` is read as a rule and the text after it.
		// The whole class is in test_inside_scope_x1_is_not_closed().
		$this->assertSame( '@scope (.a){d;.d{color:green}}', self::pick( '@scope (.a){--x: .c {b} d; .d{color:green}}', self::page( '.d' ) ) );
	}

	public function test_inside_scope_x1_is_not_closed(): void {
		// D1 of the tenth review, the known limit: inside `@scope` the rules a page leaves out, or the `;` dropped after a
		// block, can change how the rules that stay are read. This test pins what CssRules prints today, which is NOT
		// what a browser reads off the sheet as written; it is not a property to keep. If the owner takes way (a) or (b)
		// (CssRules class comment), these assertions change with it. Pages: all the classes (`.c`, `.d`), and no `.c`.
		$cases = array(
			// 1. A custom property that holds a block, in the three browsers and both ways. Written, and with `.c`, `.d`
			// is part of the value; with no `.c` it is a rule of its own and holds.
			'@scope (.a){--x: .c {b} .d{color:green};}' => array( '@scope (.a){--x: .c{b}.d{color:green}}', '@scope (.a){.d{color:green}}' ),
			// The `;` alone after the block is not printed: with `.c`, `.d` becomes part of the value and no longer holds.
			'@scope (.a){--x: .c {b}; .d{color:green}}' => array( '@scope (.a){--x: .c{b}.d{color:green}}', '@scope (.a){.d{color:green}}' ),
			// No class in play: every page prints `--y:{}.d{…}`, and `.d` no longer holds, where the sheet as written has it.
			'@scope (.a){--y:{}; .d{color:green}}' => array( '@scope (.a){--y:{}.d{color:green}}', '@scope (.a){--y:{}.d{color:green}}' ),
			// 2. Loose text right in `@scope` that is no valid declaration: with no `.c` it runs into `.d` in WebKit 27.2.
			'@scope (.a){x; .c{color:red} .d{color:green}}' => array( '@scope (.a){x;.c{color:red}.d{color:green}}', '@scope (.a){x;.d{color:green}}' ),
			'@scope (.a){@ x; .c{color:red} .d{color:green}}' => array( '@scope (.a){@ x;.c{color:red}.d{color:green}}', '@scope (.a){@ x;.d{color:green}}' ),
			'@scope (.a){x:y; .c{color:red} .d{color:green}}' => array( '@scope (.a){x:y;.c{color:red}.d{color:green}}', '@scope (.a){x:y;.d{color:green}}' ),
			'@scope (.a){.q; .c{color:red} .d{color:green}}' => array( '@scope (.a){.q;.c{color:red}.d{color:green}}', '@scope (.a){.q;.d{color:green}}' ),
			// 3. Loose text in a group inside `@scope`: with no `.c`, `.d` no longer holds in Chromium 156 and WebKit 27.2;
			// in Chromium with a true declaration too, since it reads no declaration in a `@media` inside `@scope`.
			'@scope (.a){@media all{x; .c{color:red} .d{color:green}}}' => array( '@scope (.a){@media all{x;.c{color:red}.d{color:green}}}', '@scope (.a){@media all{x;.d{color:green}}}' ),
			'@scope (.a){@media all{color:red; .c{color:red} .d{color:green}}}' => array( '@scope (.a){@media all{color:red;.c{color:red}.d{color:green}}}', '@scope (.a){@media all{color:red;.d{color:green}}}' ),
		);
		foreach ( $cases as $css => list( $with_c, $without_c ) ) {
			$this->assertSame( $with_c, self::pick( $css, self::page( '.c', '.d' ) ), $css );
			$this->assertSame( $with_c, CssRules::all( CssRules::parse( $css ) ), $css );
			$this->assertSame( $without_c, self::pick( $css, self::page( '.d' ) ), $css );
		}
	}

	// ---- X2 and X3 of the eighth review: the reading of the tests themselves.

	public function test_the_reading_of_the_tests_takes_a_name_with_an_escape_before_a_bracket_as_in_doubt(): void {
		// `\41 url(`: the space ends the escape and the name goes on (`Aurl(`), a function where the `[` opens a block.
		foreach ( array( '.a{--x:\\41 url(a[b)}', ".a{--x:\\41\turl(a[b)}", '.a{--x:\\41  url(a[b)}', '.a{--x:\\url(a[b)}', '.a{--x:\\75rl(a[b)}', '.a{--x:x\\ url(a[b)}', '.a{--x:\\41 x url(a[b)}' ) as $css ) {
			$this->assertSame( false, self::closed( $css ), $css );
			$this->assertSame( '', CssRules::all( CssRules::parse( $css ) ), $css );
		}
		// No escape in the name: an address, which ends at its `)`; and an escape that is not in the name.
		foreach ( array( '.a{--x:url(a[b)}', '.a{--x: url(a[b)}', '.a\\41{--x:url(a[b)}', '.a{--x:\\41,url(a[b)}', '.a{--x:"\\41 " url(a[b)}' ) as $css ) {
			$this->assertSame( true, self::closed( $css ), $css );
		}
	}

	public function test_the_reading_of_the_tests_sees_a_piece_that_is_closed_and_is_not_the_text_given(): void {
		$not = array(
			// W8: a space taken out of an address. Closed, and an address where a browser read none.
			array( '@scope (.a){x:url(a]b;c);.b{y:2}}', '@scope (.a){x:url(a]b ;c);.b{y:2}}' ),
			// W2, the form that stays closed: the backslash takes the `;` and the text becomes part of the next rule.
			array( '@media all{x\;.b{x:1}}', '@media all{x\\ ;.b{x:1}}' ),
			array( '.a\\{x:1}', '.a\\ {x:1}' ),
			// White space taken away or put in: between two pieces, in a string, in an address, after an escape.
			array( '.a.b{x:1}', '.a .b{x:1}' ),
			array( '.a .b{x:1}', '.a.b{x:1}' ),
			array( '.a{x:"a b"}', '.a{x:"a  b"}' ),
			array( '.a{x:url(a.png)}', '.a{x:url( a.png)}' ),
			array( '.a\\41b{x:1}', '.a\\41 b{x:1}' ),
			array( '.a\\41 b{x:1}', '.a\\41b{x:1}' ),
			// A piece that is not in the sheet, a piece out of its place, a byte left out inside a run, a block put in.
			array( '.a{x:1}.c{y:2}', '.a{x:1}.b{y:2}' ),
			array( '.b{y:2}.a{x:1}', '.a{x:1}.b{y:2}' ),
			array( '.a{x:1}', '.ab{x:1}' ),
			array( '.a{x:1}', '.a{x:11}' ),
			array( '.a{x:1}{}', '.a{x:1}' ),
		);
		foreach ( $not as [ $printed, $sheet ] ) {
			$this->assertSame( false, self::written( $printed, $sheet ), $printed );
			$this->assertSame( true, self::written( $sheet, $sheet ), $sheet );
		}
		// The first two are closed: a test that only asks for that takes them for good.
		$this->assertSame( true, self::closed( $not[0][0] ) && self::closed( $not[1][0] ) );
		$yes = array(
			// What CssRules takes away or adds: white space and comments between statements and around a prelude, text
			// that is no statement, a `}` with no block, a `;` after an at-rule, the `}` of a block left open.
			array( '.a{x:1}.b{y:2}', " .a {x:1}\n/* c */ .b\t{y:2} " ),
			array( '.b{y:2}', '.a{x:1}color:red;}.b{y:2}' ),
			array( '@layer a;.b{y:2}', '@layer a } .b{y:2}' ),
			array( '@media print{.a{x:1}}', '@media print { .a{x:1} x ' ),
			array( '.a\\41{x:1}@layer b\\41;', ".a\\41 {x:1}@layer b\\41\n;" ),
			array( '.a\\ {x:1}', ".a\\  \n{x:1}" ),
			array( "/*! MIT */\n", '.a{x:1} /*! MIT */.b{y:2}' ),
			array( "@scope (.a){/*! MIT */\n.b{y:2}}", '@scope (.a){ /*! MIT */.b{y:2}' ),
			array( '@scope (.a){.b{y:2}x;}', '@scope (.a){.b{y:2} x }' ),
			array( '.a /*c*/{ x : url( a.png ) }', '.a /*c*/ { x : url( a.png ) }' ),
		);
		foreach ( $yes as [ $printed, $sheet ] ) {
			$this->assertSame( true, self::written( $printed, $sheet ), $printed );
		}
	}

	public function test_single_signs_dropped_into_ordinary_sheets_leave_every_printed_piece_closed(): void {
		// Not a proof: 6000 sheets from a fixed seed. Every page and every node is read back by statements() above (it
		// is closed), and every node by written() (it is the text given: X2 of the eighth review). The signs are the
		// ones a generator made of whole pieces does not have: a bracket on its own, a backslash before a space, a
		// lone `@`; the last four are pieces where white space is part of a name or of an address.
		$signs  = array( '{', '}', '(', ')', '[', ']', ';', ':', ',', '@', '\\', ' ', "\n", "\t", '"', "'", '/*', '*/', 'url(', 'xurl(', 'x', '1', '-', '.a', '\\ ', '@ ', '@;', "\0", "\x0B", '\\41 ', ' ;', 'url( a )', 'url(a]b ;c)' );
		$sheets = array(
			'.a{x:1}.b{y:2}',
			'.a .c{x:calc(1px + (2 * 3px))}.b[data-x="1"]{y:2}',
			'@layer base, parts;.a{x:1}@media (min-width:1px){.a{x:1}.b{y:2}}.b{y:2}',
			'.a{x:url(a.png);&:hover{y:2}}@font-face{font-family:"x";src:url("a.woff2")}.b{content:"a;b}c"}',
			'@scope (.a){x:1;.b{y:2}}/*! note */.b{y:2}',
		);
		mt_srand( 20261115 );
		$printed = 0;
		for ( $n = 0; $n < 6000; $n++ ) {
			$sheet = $sheets[ $n % count( $sheets ) ];
			for ( $k = mt_rand( 1, 3 ); $k > 0; $k-- ) {
				$sheet = substr_replace( $sheet, $signs[ mt_rand( 0, count( $signs ) - 1 ) ], mt_rand( 0, strlen( $sheet ) ), 0 );
			}
			$nodes = CssRules::parse( $sheet );
			foreach ( array( Presence::everything(), self::page( '.a' ), self::page( '.b' ) ) as $page ) {
				$this->assertSame( true, self::closed( CssRules::select( $nodes, $page ) ), $sheet );
			}
			foreach ( $nodes as $node ) {
				$piece = CssRules::select( array( $node ), Presence::everything() );
				$this->assertSame( true, self::closed( $piece ), $sheet );
				$this->assertSame( true, self::written( $piece, $sheet ), $sheet );
			}
			$printed += count( $nodes );
		}
		// The reading is not so strict that nothing is left to check.
		$this->assertSame( true, $printed > 6000 );
	}

	public function test_every_printed_piece_is_closed_whatever_the_page_keeps(): void {
		$sheets = array(
			'.a{x:1}}.b{y:2}',
			".a{x:1}.c{content:\"x\ny\"}.b{y:2}",
			'.a{x:1}.b{content:"x}',
			'.a{&:hover{x:1',
			'@media print{.a{x:1}.b{y:2',
			'.a{x:1}color:red;.b{y:2}/* open',
			CssCompiler::compile( '.a', "a:\"\n\";content:\"a;b}c\";src:url(\"media/a.png?k=}{\")" ) . "\n" . CssCompiler::compile( '.b', 'y:2' ),
			CssCompiler::compile( '.a', "--a:\"\n;content:\"}\";color:red" ) . "\n" . CssCompiler::compile( '.b', 'y:2' ),
			CssCompiler::compile( '.a', 'x:1;&__b{y:2}&:hover{z:3' ),
			'.a{--x:xurl(a[b)}.b{y:2}',
			'.a\\ {x:1}.b{y:2}',
			'@media all{.a\\ {x:1}}.b{y:2}',
			'.a{x:1}@;.b{y:2}',
			'.a{x:1}@ layer a;.b{y:2}',
		);
		foreach ( $sheets as $sheet ) {
			$nodes = CssRules::parse( $sheet );
			foreach ( array( self::page(), self::page( '.a' ), self::page( '.b' ), self::page( '.a', '.b' ), Presence::everything() ) as $page ) {
				$this->assertSame( true, self::closed( CssRules::select( $nodes, $page ) ), $sheet );
			}
			foreach ( $nodes as $node ) {
				$this->assertSame( true, self::closed( CssRules::select( array( $node ), Presence::everything() ) ), $sheet );
			}
		}
	}

	public function test_malformed_input_does_not_hang_or_throw(): void {
		foreach ( array( '.a{', '}}}', '.a{color:"unterminated}', '@media (x{', str_repeat( '@media x{', 200 ) . '.a{b:c}', '/* open' ) as $css ) {
			$this->assertGreaterThanOrEqual( 0, strlen( self::pick( $css, self::page( '.a' ) ) ) );
		}
	}
}
