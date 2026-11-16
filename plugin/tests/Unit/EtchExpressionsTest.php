<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Import\Etch\Expressions;

/** Expression rewrite for the Etch importer (docs/spec/import-etch.md §3). */
final class EtchExpressionsTest extends TestCase {

	public function test_rewrites_only_what_differs(): void {
		$e = new Expressions();
		$this->assertSame( "this.date.date('j F Y')", $e->rewrite( "this.date.dateFormat('j F Y')" ) );
		$this->assertSame( 'item.url', $e->rewrite( 'item.permalink.relative' ) );
		$this->assertSame( 'item.url', $e->rewrite( 'item.permalink . full' ) );
		$this->assertSame( 'this.title', $e->rewrite( 'this.title' ), 'this is the current post in both languages' );
		$this->assertSame( "'dateFormat permalink.relative'", $e->rewrite( "'dateFormat permalink.relative'" ), 'strings are never touched' );
		$this->assertSame( 'dateFormat', $e->rewrite( 'dateFormat' ), 'only as a member' );
	}

	public function test_invalid_expressions_are_reported_not_guessed(): void {
		$e = new Expressions();
		$this->assertNull( $e->expression( 'this.(' ) );
		$this->assertCount( 1, $e->issues );
		$this->assertSame( 'a \{x y\} b', $e->template( 'a {x y} b' ) );
		$this->assertSame( 'a \{ b', $e->template( 'a { b' ) );
	}

	public function test_templates_and_custom_fields(): void {
		$e = new Expressions();
		$this->assertSame( 'Il {item.date.date("Y")} – {this.evento.ora}', $e->template( 'Il {item.date.dateFormat("Y")} – {this.evento.ora}', array( 'this', 'item' ) ) );
		$this->assertSame( array( 'evento' => true ), $e->custom );
		$this->assertSame( 'literal } brace', $e->template( 'literal } brace' ), 'a lone closing brace is already literal' );
	}

	public function test_etch_modifiers_with_another_name(): void {
		$e = new Expressions();
		$this->assertSame( 'post-{this.title.slug()}', $e->template( 'post-{this.title.toSlug()}' ) );
		$this->assertSame( '{item.title.upper()} {item.title.lower()}', $e->template( '{item.title.toUpperCase()} {item.title.toLowerCase()}', array( 'this', 'item' ) ) );
		$this->assertSame( 'this.date.date("Y")', $e->expression( 'this.date.format("Y")' ), 'format() is the old name of dateFormat()' );
		$this->assertSame( 'item.format', $e->rewrite( 'item.format' ), 'a field called format stays a field' );
		$this->assertSame( array(), $e->issues );
	}

	public function test_numeric_path_segments_become_indexes(): void {
		$e = new Expressions();
		$this->assertSame( 'item.categories[0].name', $e->expression( 'item.categories.0.name', array( 'this', 'item' ) ) );
		$this->assertSame( 'this.rows[0][1]', $e->rewrite( 'this.rows.0.1' ) );
		$this->assertSame( 'this.items.first()[2]', $e->rewrite( 'this.items.first().2' ) );
		$this->assertSame( 'this.price > 1.5', $e->expression( 'this.price > 1.5' ), 'a number is no path' );
		$this->assertSame( '"a.0.b"', $e->rewrite( '"a.0.b"' ), 'strings are never touched' );
		$this->assertSame( array(), $e->issues );
	}

	public function test_truthy_falsy_and_comparisons_become_operators(): void {
		$e = new Expressions();
		$this->assertSame( 'this.fields.prezzo ? true : false', $e->expression( 'this.fields.prezzo.isTruthy()' ) );
		$this->assertSame( 'this.fields.prezzo ? "Pieno" : "Vuoto"', $e->expression( 'this.fields.prezzo.isTruthy("Pieno", "Vuoto")' ) );
		$this->assertSame( 'this.excerpt ? "Ha testo" : "Vuoto"', $e->expression( 'this.excerpt.isFalsy("Vuoto", "Ha testo")' ) );
		$this->assertSame( 'this.excerpt ? false : true', $e->expression( 'this.excerpt.isFalsy()' ) );
		$this->assertSame( 'item.fields.posti < 3', $e->expression( 'item.fields.posti.less(3)', array( 'this', 'item' ) ) );
		$this->assertSame( '(item.status == "active") ? "attivo" : "spento"', $e->expression( 'item.status.equal("active", "attivo", "spento")', array( 'this', 'item' ) ) );
		$this->assertSame( 'this.categories.includes("news") ? "is-news" : ""', $e->expression( 'this.categories.includes("news", "is-news", "")' ) );
		$this->assertSame( 'this.categories.includes("news")', $e->expression( 'this.categories.includes("news")' ), 'plain includes is the same' );
		$this->assertSame( '(this.title.slice(0, 5) == "Ciao ") ? "a" : "b"', $e->expression( 'this.title.startsWith("Ciao ", "a", "b")' ) );
		$this->assertSame( 'this.url.slice(-4) == ".pdf"', $e->expression( 'this.url.endsWith(".pdf")' ) );
		$this->assertSame( 'this.tags[0]', $e->expression( 'this.tags.at(0)' ) );
		$this->assertSame( 'this.tags.slice(2, 2)', $e->expression( 'this.tags.slice(2, 4)' ), 'Etch ends, Histeon counts' );
		$this->assertSame( 'this.excerpt.truncateWords(20, "...")', $e->expression( 'this.excerpt.truncateWords(20)' ), 'Etch\'s ellipsis' );
		$this->assertSame( '!(this.title ? true : false)', $e->expression( '!this.title.isTruthy()' ) );
		$this->assertSame( array(), $e->issues );
	}

	public function test_modifiers_without_an_xb_form_are_reported(): void {
		$e = new Expressions();
		$this->assertNull( $e->expression( 'this.fields.prezzo.round(2)' ) );
		$this->assertNull( $e->expression( 'this.tags.slice(-2, -1)' ) );
		$this->assertNull( $e->expression( 'this.tags.at(-1)' ) );
		$this->assertCount( 3, $e->issues );
		$this->assertStringContainsString( 'round', $e->issues[0] );
		$this->assertSame( 'Prezzo: \{this.fields.prezzo.toInt()\}', $e->template( 'Prezzo: {this.fields.prezzo.toInt()}' ) );
	}

	/** Fields of Etch's own post data that Histeon names otherwise: not plugin fields (7 November). */
	public function test_etch_post_fields_with_another_name(): void {
		$e = new Expressions();
		$this->assertSame( 'item.image.url', $e->expression( 'item.featuredImage', array( 'this', 'item' ) ) );
		$this->assertSame( 'this.image.url', $e->expression( 'this.thumbnail' ) );
		$this->assertSame( 'item.date.date("j F Y")', $e->expression( 'item.post_date.dateFormat("j F Y")', array( 'this', 'item' ) ) );
		$this->assertSame( '{this.readingTime} min · {this.title}', $e->template( '{this.readingTime} min · {this.post_title}' ) );
		$this->assertSame( 'url.query.s', $e->expression( 'url.parameter.s' ), 'the query string' );
		$this->assertSame( 'faq.thumbnail', $e->expression( 'faq.thumbnail', array( 'this' ) ), 'only on posts: static data keeps its keys' );
		$this->assertSame( array(), $e->custom, 'none of them is reported as a plugin field' );
		$this->assertSame( array(), $e->issues );
	}
}
