<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Styles\CssAssets;
use XB\Versioning\Documents;

final class CssAssetsTest extends TestCase {

	private const IMAGE = '550e8400-e29b-41d4-a716-446655440001';
	private const FONT = '550e8400-e29b-41d4-a716-446655440002';

	public function test_url_scanner_ignores_comments_and_strings_and_keeps_source_offsets(): void {
		$css = '/* url(ignored.png) */ .hero{content:"url(fake.png)";background:url( ../media/hero.png?v=2 )}@font-face{src:URL("fonts/brand.woff2#regular")}';
		$this->assertSame(
			array( '../media/hero.png?v=2', 'fonts/brand.woff2#regular' ),
			array_column( CssAssets::urls( $css ), 'value' )
		);
	}

	public function test_local_media_links_are_resolved_without_changing_the_transfer_source(): void {
		$css = '.hero{background:url(../media/hero.png?v=2)}@font-face{src:url("fonts/brand.woff2#regular")}';
		$mappings = array(
			array( 'reference' => '../media/hero.png?v=2', 'assetId' => self::IMAGE ),
			array( 'reference' => 'fonts/brand.woff2#regular', 'assetId' => self::FONT ),
		);
		$resolved = CssAssets::rewrite(
			$css,
			$mappings,
			static fn ( string $id ): string => self::IMAGE === $id ? 'https://wp.example/uploads/hero.png' : 'https://wp.example/uploads/brand.woff2'
		);
		$this->assertSame( '.hero{background:url("https://wp.example/uploads/hero.png?v=2")}@font-face{src:url("https://wp.example/uploads/brand.woff2#regular")}', $resolved );
		$this->assertSame( array( '../media/hero.png?v=2', 'fonts/brand.woff2#regular' ), array_column( CssAssets::urls( $css ), 'value' ) );

		$validated = CssAssets::validate_profile( $css, $mappings, array( self::IMAGE => true, self::FONT => true ), array( self::IMAGE => 'image/png', self::FONT => 'font/woff2' ) );
		$this->assertSame( $mappings, $validated );
	}

	/**
	 * The query or fragment of a linked reference is copied into the printed URL. A quoted value may hold a
	 * line continuation (a backslash before a newline): written back with the backslash doubled, the string would end
	 * at the newline and what follows would be live CSS that the restricted-tier check never saw.
	 */
	public function test_the_rewritten_url_cannot_end_its_string_early(): void {
		$reference = "/media/a.png?x\\\n}body{background:url(https://evil.example/x)}";
		$css       = '.a{background:url("' . $reference . '")}';
		$resolved  = CssAssets::rewrite( $css, array( array( 'reference' => $reference, 'assetId' => self::IMAGE ) ), static fn (): string => 'https://wp.example/uploads/a.png' );
		$this->assertCount( 1, CssAssets::urls( $resolved ), 'one url() and nothing after it' );
		$this->assertStringNotContainsString( "\n", $resolved );
		$this->assertStringNotContainsString( '\\', $resolved );
		$this->assertSame( '.a{background:url("https://wp.example/uploads/a.png?x%5C%0A%7Dbody%7Bbackground:url%28https://evil.example/x%29%7D")}', $resolved );
		// The attachment URL itself is written the same way.
		$this->assertSame( '.b{background:url("https://wp.example/up%22loads/b.png")}', CssAssets::rewrite( '.b{background:url(b.png)}', array( array( 'reference' => 'b.png', 'assetId' => self::IMAGE ) ), static fn (): string => 'https://wp.example/up"loads/b.png' ) );
	}

	/** One linked reference, `a.png`, and where its media is. */
	private static function linked( string $css, ?string $address = 'https://wp.example/uploads/a.png', string $reference = 'a.png' ): string {
		return CssAssets::rewrite( $css, array( array( 'reference' => $reference, 'assetId' => self::IMAGE ) ), static fn (): ?string => $address );
	}

	/**
	 * The `?…` or `#…` of a reference is chosen by whoever writes the CSS: it is printed with nothing in it that a
	 * reader of CSS could take for a comment, a function, the end of a declaration or of a string (threat model T31, T1).
	 */
	public function test_the_suffix_of_a_reference_is_written_inert(): void {
		$reference = 'a.png?v=(1);x/*,@!$[]{}<>|^`&y=1+2%20#top~-._:/';
		$this->assertSame(
			'.a{background:url("https://wp.example/uploads/a.png?v=%281%29%3Bx/%2A%2C%40%21%24%5B%5D%7B%7D%3C%3E%7C%5E%60&y=1+2%20#top~-._:/")}',
			self::linked( '.a{background:url("' . $reference . '")}', reference: $reference )
		);
		// A quote or a backslash of the reference cannot end the string either.
		$this->assertSame( '.a{background:url("https://wp.example/uploads/a.png?%5C%27%5C%22")}', self::linked( '.a{background:url("a.png?\\\'\\"")}', reference: 'a.png?\\\'\\"' ) );
		// The address of the media is the one the library gives, written the same way.
		$this->assertSame( '.a{background:url("https://wp.example/uploads/a%281%29.png?v=2")}', self::linked( '.a{background:url(a.png?v=2)}', 'https://wp.example/uploads/a(1).png', 'a.png?v=2' ) );
	}

	/** What is written between the quotes of the one `url("…")` of a sheet. */
	private static function written( string $css ): string {
		return 1 === preg_match( '/url\("([^"]*)"\)/', $css, $found ) ? $found[1] : '(no url("…") written)';
	}

	/**
	 * The address of a media is given by the media library, not by whoever writes the CSS, but it is written like the
	 * suffix all the same: only letters, digits and `- . _ ~ : / ? # & = + %` stay. A reader of CSS that gets to the
	 * `url("…")` while it is inside a string reads the address as CSS (V1 of the fifth review): a `;`, a parenthesis, a
	 * bracket, a `*`, a `,`, an `@`, a `!` or a `$` of the address would be live CSS there (threat model T31, T1).
	 */
	public function test_the_address_of_a_media_is_written_inert(): void {
		$this->assertSame(
			'https://wp.example:8080/uploads/2026/11/m%281%29%3Ba%2Cb%2Ac%5B2%5D%21%24%402x.png?w=10&h=20+1%20#x~-._',
			self::written( self::linked( '.a{background:url(a.png)}', 'https://wp.example:8080/uploads/2026/11/m(1);a,b*c[2]!$@2x.png?w=10&h=20+1%20#x~-._' ) )
		);
		$addresses = array(
			// The plain ones are written byte for byte: scheme, port, path, query and fragment.
			'https://wp.example/wp-content/uploads/2026/11/hero-2x_v1.2~a.png',
			'http://wp.example:8080/uploads/hero.png?ver=6.4&w=300#top',
			'//cdn.wp.example/uploads/hero.png',
			'/wp-content/uploads/hero.png',
			// What is already percent-encoded stays as it is, never encoded twice.
			'https://wp.example/uploads/il%20mio%20file.png?fit=300%2C200',
			'https://wp.example/uploads/caf%C3%A9.png',
			// What CSS reads is percent-encoded.
			'https://wp.example/uploads/hero@2x.png',
			'https://wp.example/uploads/m1;a,b*c.png',
			'https://wp.example/uploads/a(1).png',
			'https://wp.example/uploads/a[1]!$.png?size[]=1&crop=1,2;x=(3)*@4',
			"https://wp.example/uploads/a'b\"c\\d{e}<f>|g^h`i j\n.png",
			"https://wp.example/uploads/caf\xc3\xa9.png",
			'https://wp.example/uploads/a.png?x=/*y*/',
		);
		foreach ( $addresses as $address ) {
			$written = self::written( self::linked( '.a{background:url(a.png)}', $address ) );
			$this->assertSame( 1, preg_match( '/\A[A-Za-z0-9\-._~:\/?#&=+%]+\z/', $written ), 'nothing in it that CSS reads: ' . $written );
			// The same address for a server: percent-decoded, it is the one the library gave.
			$this->assertSame( rawurldecode( $address ), rawurldecode( $written ), $address );
			// Written the same way with the media of another url() found or gone, and whatever the reference adds.
			$this->assertSame( '.a{background:url("' . $written . '?v=%282%29")}', self::linked( '.a{background:url("a.png?v=(2)")}', $address, 'a.png?v=(2)' ), $address );
		}
		foreach ( array_slice( $addresses, 0, 6 ) as $address ) {
			$this->assertSame( $address, self::written( self::linked( '.a{background:url(a.png)}', $address ) ), $address );
		}
	}

	/**
	 * An `@` or a bracket before the path is a user name or an IPv6 host: percent-encoded it would be another host
	 * name, as it stands it is something CSS reads. Such an address cannot be written both inert and the same, so the
	 * media is not linked: `url()`, as for a media that is gone.
	 */
	public function test_an_address_that_cannot_be_written_inert_is_not_linked(): void {
		foreach ( array( 'https://utente@wp.example/uploads/a.png', 'https://utente:segreto@wp.example/uploads/a.png', 'http://[::1]:8080/uploads/a.png', '//utente@wp.example/uploads/a.png', '//[2001:db8::1]/uploads/a.png', 'https://wp.example\\@altro.example/a.png' ) as $address ) {
			$this->assertSame( '.a{background:url()}', self::linked( '.a{background:url(a.png)}', $address ), $address );
			$this->assertSame( '.a{background:url()}', self::linked( '.a{background:url("a.png?v=2")}', $address, 'a.png?v=2' ), $address );
		}
		// After the host the same characters are only part of a name: written percent-encoded, the media is linked.
		$this->assertSame( '.a{background:url("https://wp.example/uploads/a%402x%5B1%5D.png?u=x%40y")}', self::linked( '.a{background:url(a.png)}', 'https://wp.example/uploads/a@2x[1].png?u=x@y' ) );
		$this->assertSame( '.a{background:url("/uploads//a%402x.png")}', self::linked( '.a{background:url(a.png)}', '/uploads//a@2x.png' ) );
	}

	/**
	 * V1 of the fifth review: sheets that the filter leaves as written and where closed_urls() and urls() agree, but
	 * where the browser, or the text as CssCompiler prints it, is inside a double-quoted string when it gets to the
	 * rewritten url(). There the first quote of `url("…")` ends that string and the address is read as CSS. Harmless
	 * sheets, the ones of the review. The form with the media found is still read otherwise than the one with the media
	 * gone (the declaration that holds the url() is another), but the address adds nothing that CSS reads: the two
	 * forms differ by an inert text and two quotes, whatever the address of the media.
	 */
	public function test_an_address_read_outside_its_string_is_still_inert(): void {
		$sheets = array(
			// A backslash and CR LF inside a string: the browser goes on with the string, the filter ends it at the LF.
			".k::before{--rotta:\"\n;content:\"\\\r\n due url(a.png) tre\";color:red}",
			// A hex escape that is no letter, then a new line, inside a string.
			".k::before{--rotta:\"\n;content:\"\\31\n due url(a.png) tre\";color:red}",
			// A broken string whose new line CssCompiler trims away when it prints the style.
			"--a:\\\";content:\"\n;background:url(\"a.png\") \";color:red",
			"--a:\\\";--x:\"\n;background:url(\"a.png\");color:red",
		);
		$addresses = array(
			'https://wp.example/uploads/m1.png'           => 'https://wp.example/uploads/m1.png',
			'https://wp.example/uploads/m1;x.png'         => 'https://wp.example/uploads/m1%3Bx.png',
			'https://wp.example/uploads/m(1).png'         => 'https://wp.example/uploads/m%281%29.png',
			'https://wp.example/uploads/m1;a,b*c.png'     => 'https://wp.example/uploads/m1%3Ba%2Cb%2Ac.png',
			'https://wp.example/uploads/m[1]!$@2x.png'    => 'https://wp.example/uploads/m%5B1%5D%21%24%402x.png',
		);
		foreach ( $sheets as $css ) {
			$gone = self::linked( $css, null );
			$this->assertNotSame( $css, $gone, 'the reference is rewritten: ' . $css );
			$this->assertSame( 1, substr_count( $gone, 'url()' ), $css );
			foreach ( $addresses as $address => $inert ) {
				$this->assertSame( str_replace( 'url()', 'url("' . $inert . '")', $gone ), self::linked( $css, $address ), $address . ' in ' . $css );
			}
		}
	}

	/**
	 * A media is written only into a url() that the filter of restricted CSS reads as one closed address in the same
	 * place (Documents::closed_urls()). Where the reading of the mappings (urls()) finds a url() that the filter reads
	 * otherwise, the text stays as written: the two readings cannot be played against each other (threat model T31, T1).
	 */
	public function test_a_media_is_written_only_where_the_filter_reads_a_closed_url(): void {
		$found = 'url("https://wp.example/uploads/a.png")';
		// The plain forms are still linked: without quotes, with either quote, with spaces, with any case.
		$this->assertSame( ".a{background:$found}.b{background:$found}.c{background:$found}.d{background:$found}", self::linked( ".a{background:url(a.png)}.b{background:url( 'a.png' )}.c{background:URL(\"a.png\")}.d{background:url(a.png\n)}" ) );
		$this->assertSame( '.a{background:url()}', self::linked( '.a{background:url(a.png)}', null ) );

		$unchanged = array(
			// A comment or a space before the parenthesis: a name and a parenthesis for the browser, no url().
			'.a{background:url/**/(a.png)}',
			'.a{background:url (a.png)}',
			// After a string only spaces and the parenthesis: a comment there makes it no url() for the browser.
			'.a{background:url("a.png"/**/)}',
			// A string that holds a new line is a broken string for the browser.
			".a{background:url(\"a.png\n\")}",
			// A name right after an escape, an `@` or a `#` is another name, or no name.
			'.a{background:\\!url(a.png)}',
			'.a{background:\\2d url(a.png)}',
			'.a{background:#url(a.png)}',
			'@url(a.png);',
		);
		foreach ( $unchanged as $css ) {
			$this->assertNotSame( array(), array_filter( CssAssets::urls( $css ), static fn ( array $url ): bool => 'a.png' === trim( $url['value'] ) ), 'the reading of the mappings finds the reference: ' . $css );
			$this->assertSame( $css, self::linked( $css ), $css );
			$this->assertSame( $css, self::linked( $css, null ), $css );
		}
	}

	/** A letter written with an escape is read written out by the filter and by the browser: nothing is linked in that sheet. */
	public function test_a_sheet_with_a_letter_written_as_an_escape_is_not_rewritten(): void {
		foreach ( array( '.\\62 ox{color:red}.a{background:url(a.png)}', '.a{background:url(a.png)}.b{c\\6flor:red}', '.a{background:\\75rl(a.png)}.b{background:url(a.png)}' ) as $css ) {
			$this->assertSame( $css, self::linked( $css ), $css );
		}
		// Any other escape is no letter and changes nothing: class names and strings are written with them.
		$this->assertSame( '.md\\:hero{background:url("https://wp.example/uploads/a.png")}.q::before{content:"\\201C"}', self::linked( '.md\\:hero{background:url(a.png)}.q::before{content:"\\201C"}' ) );
	}

	/**
	 * After a url( that the browser reads as no url at all, the filter and the browser go on from different places:
	 * nothing is linked from there on, neither a url() inside the broken one nor the ones after it.
	 */
	public function test_nothing_is_rewritten_from_a_broken_url_on(): void {
		$found = 'url("https://wp.example/uploads/a.png")';
		$this->assertSame( ".z{background:$found}.a{background:url(\"x\" url(a.png))}.b{background:url(a.png)}", self::linked( '.z{background:url(a.png)}.a{background:url("x" url(a.png))}.b{background:url(a.png)}' ) );
		$this->assertSame( ".z{background:$found}.a{background:url(x y) url(a.png)}.b{background:url(a.png)}", self::linked( '.z{background:url(a.png)}.a{background:url(x y) url(a.png)}.b{background:url(a.png)}' ) );
		// Left open at the end of the sheet.
		$this->assertSame( ".z{background:$found}.a{background:url(a.png", self::linked( '.z{background:url(a.png)}.a{background:url(a.png' ) );
	}

	/** What Documents::closed_urls() names: where each closed url() starts and ends, in the CSS as written. */
	public function test_the_filter_names_the_urls_it_reads_closed(): void {
		$css = '/* url(no.png) */.a{content:"url(no.png)";background:url( a.png ) , URL("b.png")}';
		$this->assertSame( array( 'url( a.png )', 'URL("b.png")' ), array_map( static fn ( int $start, int $end ): string => substr( $css, $start, $end - $start ), array_keys( Documents::closed_urls( $css ) ), Documents::closed_urls( $css ) ) );
		$this->assertSame( array(), Documents::closed_urls( '.a{color:red}' ) );
		$this->assertSame( array(), Documents::closed_urls( '.a{background:\\75rl(a.png)}' ) );
		$this->assertSame( array( 14 => 24 ), Documents::closed_urls( '.a{background:url(a.png)}.b{background:url(b c)}.c{background:url(c.png)}' ) );
	}

	public function test_bundle_root_asset_links_survive_validation_and_rewrite(): void {
		$css = '@font-face{src:url("/framework/fonts/brand.woff2?v=3#latin")} .hero{background:url(/media/hero.avif)}';
		$mappings = array(
			array( 'reference' => '/framework/fonts/brand.woff2?v=3#latin', 'assetId' => self::FONT ),
			array( 'reference' => '/media/hero.avif', 'assetId' => self::IMAGE ),
		);
		$validated = CssAssets::validate_profile(
			$css,
			$mappings,
			array( self::FONT => true, self::IMAGE => true ),
			array( self::FONT => 'font/woff2', self::IMAGE => 'image/avif' )
		);
		$this->assertSame( $mappings, $validated );
		$this->assertSame(
			'@font-face{src:url("https://wp.example/uploads/brand.woff2?v=3#latin")} .hero{background:url("https://wp.example/uploads/hero.avif")}',
			CssAssets::rewrite(
				$css,
				$validated,
				static fn ( string $id ): string => self::FONT === $id ? 'https://wp.example/uploads/brand.woff2' : 'https://wp.example/uploads/hero.avif'
			)
		);
	}

	public function test_transfer_rejects_unlinked_imported_or_unsupported_resources(): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'Every CSS url() must be linked' );
		CssAssets::validate_profile( '.hero{background:url(unlinked.png)}', array(), array(), array() );
	}

	public function test_external_css_resources_remain_external_and_do_not_need_a_project_asset(): void {
		$this->assertSame(
			array(),
			CssAssets::validate_profile( '.hero{background:url(https://cdn.example.test/texture.png);mask:url(#mask)}', array(), array(), array() )
		);
	}

	public function test_transfer_rejects_local_css_imports(): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'Local CSS imports cannot be transferred' );
		CssAssets::validate_profile( '@import "./fonts.css";', array(), array(), array() );
	}

	public function test_external_css_imports_remain_external(): void {
		$this->assertSame( array(), CssAssets::validate_profile( '@import url("https://cdn.example.test/fonts.css");', array(), array(), array() ) );
	}

	public function test_import_text_inside_comments_and_strings_is_not_a_css_import(): void {
		$this->assertSame( array(), CssAssets::validate_profile( '/* @import "ignored.css"; */ .x{content:"@import fake.css"}', array(), array(), array() ) );
	}

	public function test_transfer_rejects_mapping_to_a_missing_asset(): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'outside the transfer profile' );
		CssAssets::validate_profile(
			'@font-face{src:url(brand.woff2)}',
			array( array( 'reference' => 'brand.woff2', 'assetId' => self::FONT ) ),
			array(),
			array()
		);
	}

	public function test_transfer_rejects_svg_and_unknown_mapping_properties(): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'unsupported image or font format' );
		CssAssets::validate_profile(
			'.hero{background:url(hero.svg)}',
			array( array( 'reference' => 'hero.svg', 'assetId' => self::IMAGE ) ),
			array( self::IMAGE => true ),
			array( self::IMAGE => 'image/svg+xml' )
		);
	}

	public function test_transfer_rejects_extra_mapping_fields(): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'Unexpected fields' );
		CssAssets::validate_profile(
			'@font-face{src:url(brand.woff2)}',
			array( array( 'reference' => 'brand.woff2', 'assetId' => self::FONT, 'url' => 'https://wrong.example/' ) ),
			array( self::FONT => true ),
			array( self::FONT => 'font/woff2' )
		);
	}
}
