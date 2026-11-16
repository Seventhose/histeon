<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Export\Etch\Expressions;
use XB\Import\Etch\Expressions as ImportExpressions;

/** Expression rewrite for the Etch export (docs/spec/export-etch.md §3): the inverse of the importer's. */
final class EtchExportExpressionsTest extends TestCase {

	public function test_rewrites_only_what_differs(): void {
		$e = new Expressions();
		$this->assertSame( "this.date.dateFormat('j F Y')", $e->rewrite( "this.date.date('j F Y')" ) );
		$this->assertSame( 'this.title.toUpperCase().toSlug()', $e->rewrite( 'this.title.upper().slug()' ) );
		$this->assertSame( 'item.lower', $e->rewrite( 'item.lower' ), 'a field called lower stays a field' );
		$this->assertSame( 'this.date', $e->rewrite( 'this.date' ), 'the date field is the same in both' );
		$this->assertSame( 'item.permalink.relative', $e->rewrite( 'item.url', array( 'this', 'item' ) ) );
		$this->assertSame( 'link.url', $e->rewrite( 'link.url', array( 'this' ) ), 'a list item is not a post' );
		$this->assertSame( 'this.image.url', $e->rewrite( 'this.image.url' ), 'only the address of the post itself' );
		$this->assertSame( "'x.date(1) this.url'", $e->rewrite( "'x.date(1) this.url'" ), 'strings are never touched' );
	}

	public function test_every_export_reads_back_as_the_same_expression(): void {
		$import = new ImportExpressions();
		$e      = new Expressions();
		foreach ( array( "this.date.date('Y')", 'this.url', 'item.url ?? this.url', 'props.title', 'item.date.date("j") ?? item.title' ) as $xb ) {
			$etch = $e->expression( $xb, array( 'this', 'item' ) );
			$this->assertNotNull( $etch );
			$this->assertSame( $xb, $import->rewrite( $etch ), $xb );
		}
		$this->assertSame( array(), $e->issues );
		$e->expression( 'this.permalink' );
		$this->assertCount( 1, $e->issues, 'what would not come back the same is reported' );
	}

	public function test_templates_literal_braces_and_invalid_expressions(): void {
		$e = new Expressions();
		$this->assertSame( 'Il {this.date.dateFormat("Y")} <a href="{this.permalink.relative}">x</a>', $e->template( 'Il {this.date.date("Y")} <a href="{this.url}">x</a>' ) );
		$this->assertSame( 'a &#123;b} c', $e->template( 'a \{b\} c' ), 'a literal brace never becomes an Etch expression' );
		$this->assertSame( 'a  b', $e->template( 'a {this.(} b' ) );
		$this->assertCount( 1, $e->issues );
		$this->assertSame( 'x &#123;y&#125;', Expressions::literal( 'x {y}' ) );
		// The text of an element: Etch prints it escaped, so entities are decoded and a brace is an expression that prints it.
		$this->assertSame( 'Blog & News', $e->template( 'Blog &amp; News', array( 'this' ), true ) );
		$this->assertSame( 'a {"{"}b{"}"} <c> {this.title} & {"{"}d{"}"}', $e->template( 'a \{b\} &lt;c&gt; {this.title} &amp; &#123;d&#125;', array( 'this' ), true ) );
		$this->assertSame( 'Blog &amp; News', $e->template( 'Blog &amp; News' ), 'an attribute keeps its entities' );
		$this->assertSame( 'x {"{"}y{"}"}', Expressions::literal_text( 'x {y}' ) );
	}

	public function test_constants(): void {
		$this->assertSame( array( 3 ), Expressions::constant( '3' ) );
		$this->assertSame( array( 'futuri' ), Expressions::constant( '"futuri"' ) );
		$this->assertSame( array( null ), Expressions::constant( 'null' ) );
		$this->assertSame( array( array( array( 'label' => 'Home', 'url' => '/' ) ) ), Expressions::constant( '[{label: "Home", url: "/"}]' ) );
		$this->assertNull( Expressions::constant( 'props.items' ) );
		$this->assertNull( Expressions::constant( '[1, x]' ) );
	}
}
