<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Styles\Selectors;

/** The pure rewriting rules of the selector manager — docs/spec/styles.md §4. */
final class SelectorsTest extends TestCase {

	private static function renamer( string $from, string $to ): \Closure {
		return static fn ( string $raw ): ?string => Selectors::renamed( $raw, $from, $to );
	}

	public function test_renames_the_class_and_its_bem_children_only(): void {
		$this->assertSame( 'box', Selectors::renamed( 'card', 'card', 'box' ) );
		$this->assertSame( 'box__title', Selectors::renamed( 'card__title', 'card', 'box' ) );
		$this->assertSame( 'box--dark', Selectors::renamed( 'card--dark', 'card', 'box' ) );
		$this->assertSame( 'box__title--big', Selectors::renamed( 'card__title--big', 'card', 'box' ) );
		$this->assertNull( Selectors::renamed( 'cardigan', 'card', 'box' ) );
		$this->assertNull( Selectors::renamed( 'card-alt', 'card', 'box' ), 'a single dash is another class' );
		$this->assertNull( Selectors::renamed( 'my-card', 'card', 'box' ) );
	}

	public function test_selectors_keep_attribute_selectors_strings_and_comments(): void {
		$rename = self::renamer( 'card', 'box' );
		$this->assertSame( '.box:hover > .box__title, :not(.box--dark)', Selectors::rename_in_selector( '.card:hover > .card__title, :not(.card--dark)', $rename ) );
		$this->assertSame( '[class*="card"] .cardigan', Selectors::rename_in_selector( '[class*="card"] .cardigan', $rename ) );
		$this->assertSame( '/* .card */ .box', Selectors::rename_in_selector( '/* .card */ .card', $rename ) );
		$this->assertSame( '.box--md\:big .card\:x', Selectors::rename_in_selector( '.card--md\:big .card\:x', $rename ), 'escaped names: only a real BEM prefix' );
	}

	public function test_sheets_rewrite_preludes_but_never_declarations(): void {
		$rename = self::renamer( 'card', 'box' );
		$css    = '.card{background:url(a.card.png);content:".card"} &__title{x:1} @media (width > 1px){.card .card__icon{gap:1px}} @supports selector(.card){.card{y:2}}';
		$this->assertSame(
			'.box{background:url(a.card.png);content:".card"} &__title{x:1} @media (width > 1px){.box .box__icon{gap:1px}} @supports selector(.card){.box{y:2}}',
			Selectors::rename_in_sheet( $css, $rename )
		);
		$this->assertSame( 'color:red', Selectors::rename_in_sheet( 'color:red', $rename ) );
	}

	public function test_class_attributes_skip_interpolations_and_unknown_starts(): void {
		$rename = static function ( string $token, bool $open ): ?string {
			return $open && 'card' === $token ? null : Selectors::renamed( $token, 'card', 'box' );
		};
		$this->assertSame( 'box {x} box--{v} {y}-card card{z}', Selectors::class_attribute( 'card {x} card--{v} {y}-card card{z}', $rename ) );
		$this->assertSame( "box\nbox__a", Selectors::class_attribute( "card\ncard__a", $rename ) );
		$this->assertSame( '{props.card}', Selectors::class_attribute( '{props.card}', $rename ) );
	}

	public function test_alternatives_include_hoisted_bem_rules(): void {
		$this->assertSame( array( array( 'c:card' ), array( 'c:card__title' ) ), Selectors::alternatives( '.card', 'gap:1rem; &__title{x:1}' ) );
		$this->assertSame( array( array( 'c:card' ) ), Selectors::alternatives( '.card', '' ) );
		$this->assertContains( array(), Selectors::alternatives( 'h1', 'margin:0' ) );
	}
}
