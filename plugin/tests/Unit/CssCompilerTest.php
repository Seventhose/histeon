<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Styles\CssCompiler;
use XB\Styles\StyleRegistry;

final class CssCompilerTest extends TestCase {

	public function test_keeps_declarations_and_native_nesting(): void {
		$this->assertSame(
			'.card{padding:var(--space-m);&:hover{color:red}@media (width>40em){padding:0}}',
			self::squash( CssCompiler::compile( '.card', "padding: var(--space-m);\n&:hover { color:red }\n@media (width > 40em) { padding:0 }" ) )
		);
	}

	public function test_hoists_bem_suffixes_recursively(): void {
		$this->assertSame(
			'.card{color:red;}.card__title{font-weight:700;}.card__title--big{font-size:2em;}.card--dark{color:#fff;}',
			self::squash( CssCompiler::compile( '.card', 'color:red; &__title { font-weight:700; &--big { font-size:2em } } &--dark { color:#fff }' ) )
		);
	}

	public function test_combines_selector_lists(): void {
		$this->assertSame(
			'.a__x,.a__y,.b__x,.b__y{margin:0;}',
			self::squash( CssCompiler::compile( '.a, .b', '&__x, &__y { margin:0 }' ) )
		);
	}

	public function test_respects_strings_comments_and_parentheses(): void {
		$css = CssCompiler::compile( '.q', '/* note; { */ content: "a;b{c}"; &:is(.x, .y) { top:0 }' );
		$this->assertSame( '.q{content: "a;b{c}";&:is(.x, .y){ top:0 }}', $css );
	}

	public function test_cannot_close_the_style_element(): void {
		$this->assertStringNotContainsString( '</', CssCompiler::compile( '.x', 'content:"</style><script>alert(1)</script>"' ) );
	}

	/** A stored selector (Etch, release, CMS import) cannot close its rule and add CSS of its own. */
	public function test_a_selector_that_is_not_one_compiles_to_nothing(): void {
		foreach ( array( '.a{background:url(https://evil.example/x)} .b', '.a}.b', '.a;', '.a</style>', '.a/*', '.a\\', '' ) as $selector ) {
			$this->assertFalse( CssCompiler::valid_selector( $selector ), $selector );
			$this->assertSame( '', CssCompiler::compile( $selector, 'color:red' ), $selector );
		}
		foreach ( array( '.a', '.card:is(.x, .y) > p', '[data-x="1"]', '.a\\:hover', '.a\\\\' ) as $selector ) {
			$this->assertTrue( CssCompiler::valid_selector( $selector ), $selector );
		}
	}

	/** The one rule for strings, shared with CssRules (threat model T31, V2 of the fifth review). */
	public function test_a_string_ends_at_its_quote_and_nowhere_else(): void {
		$this->assertSame( 3, CssCompiler::string_end( '"ab"c', 0 ) );
		$this->assertSame( 6, CssCompiler::string_end( 'x:\'a"b\'', 2 ) );
		$this->assertSame( 5, CssCompiler::string_end( '"a\\"b"', 0 ), 'a backslash takes the next character with it' );
		$this->assertSame( 4, CssCompiler::string_end( "\"a\nb\"", 0 ), 'a new line does not end it' );
		$this->assertSame( 3, CssCompiler::string_end( '"ab', 0 ), 'never closed: the length of the text' );
		$this->assertSame( 3, CssCompiler::string_end( '"a\\', 0 ) );
		// So a declaration with a string left open is still printed to its end, as before.
		$this->assertSame( '.a{content:"x;color:red;}', CssCompiler::compile( '.a', 'content:"x;color:red' ) );
	}

	public function test_key_class(): void {
		$this->assertSame( 'card', StyleRegistry::key_class( '.card:hover > .x' ) );
		$this->assertSame( 'btn--primary', StyleRegistry::key_class( 'a.btn--primary' ) );
		$this->assertNull( StyleRegistry::key_class( 'h1, #main' ) );
	}

	private static function squash( string $css ): string {
		return (string) preg_replace( '/\s*([{};:,>])\s*/', '$1', $css );
	}
}
