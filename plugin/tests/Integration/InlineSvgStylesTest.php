<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Security\InlineSvg;
use XB\Styles\CssCompiler;
use XB\Versioning\Documents;

/**
 * V5.8: the restricted tier of the styles keeps an inert SVG drawing written in a sheet,
 * `url("data:image/svg+xml;charset=utf-8,…")`, and nothing else of data: — docs/security/threat-model.md T31.
 */
final class InlineSvgStylesTest extends IntegrationTestCase {

	/** The drawing as Histeon Publish writes it (staticInlineSvg: every character but A-Z a-z 0-9 - _ . ~ encoded). */
	private static function url( string $svg, string $type = 'image/svg+xml;charset=utf-8' ): string {
		return 'data:' . $type . ',' . rawurlencode( $svg );
	}

	private static function filter( string $css ): string {
		return Documents::restricted_styles( array( 'styles' => array( array( 'css' => $css ) ) ) )['styles'][0]['css'];
	}

	/** The mask of WordPress's rounded image block and the arrow of a select. */
	private const MASK  = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>';
	private const ARROW = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><path fill='none' stroke='#343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/></svg>";

	public function test_lawful_drawings_pass_unchanged(): void {
		$this->assertTrue( class_exists( \XMLReader::class ), 'The site of the tests has XMLReader.' );
		foreach ( array(
			'.wp-block-image.is-style-rounded img{border-radius:0;mask-image:url("' . self::url( self::MASK ) . '")}',
			'.select{background:#fff url("' . self::url( self::ARROW ) . '") no-repeat right .75rem center/16px 12px}',
			".x{mask:url('" . self::url( self::MASK ) . "')}",
			'.x{mask:url(' . self::url( self::MASK ) . ')}',
			'.x{mask:url("data:image/svg+xml;base64,' . base64_encode( self::MASK ) . '")}',
			'.x{mask:url("data:image/svg+xml;charset=utf8;base64,' . base64_encode( self::MASK ) . '")}',
			'.x{mask:url("' . self::url( self::MASK, 'image/svg+xml' ) . '")}',
			// Case and spaces of the type as a browser reads them.
			'.x{mask:url("DATA:Image/SVG+XML ; Charset=UTF-8,' . rawurlencode( self::MASK ) . '")}',
			// Gradients, clips and uses of the same drawing; a comment of the sheet elsewhere stays.
			'/* onda */ .x{background:url("' . self::url( '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient><path id="p" d="M0 0h10"/></defs><rect width="10" height="10" fill="url(#g)" style="fill:url(\'#g\')"/><use href="#p"/><use xlink:href="#p"/><text x="1" y="9">Ciao &amp; addio</text></svg>' ) . '")}',
		) as $css ) {
			$this->assertSame( $css, self::filter( $css ), $css );
		}
	}

	public function test_active_or_linking_drawings_are_removed(): void {
		$svg = static fn ( string $inner, string $attrs = '' ): string => '<svg xmlns="http://www.w3.org/2000/svg"' . $attrs . '>' . $inner . '</svg>';
		foreach ( array(
			'script'             => $svg( '<script>alert(1)</script>' ),
			'script upper'       => $svg( '<SCRIPT>alert(1)</SCRIPT>' ),
			'foreignObject'      => $svg( '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>' ),
			'event'              => $svg( '<rect width="1" height="1"/>', ' onload="alert(1)"' ),
			'event upper'        => $svg( '<rect ONCLICK="alert(1)"/>' ),
			'href remote'        => $svg( '<use href="https://evil.example/a.svg#x"/>' ),
			'href other file'    => $svg( '<use href="a.svg#x"/>' ),
			'xlink remote'       => $svg( '<use xlink:href="//evil.example/a.svg#x"/>', ' xmlns:xlink="http://www.w3.org/1999/xlink"' ),
			'xlink other prefix' => $svg( '<use l:href="//evil.example/a#x"/>', ' xmlns:l="http://www.w3.org/1999/xlink"' ),
			'href entities'      => $svg( '<use href="&#x6A;avascript:alert(1)"/>' ),
			'src'                => $svg( '<rect src="https://evil.example/x"/>' ),
			'image'              => $svg( '<image href="#a"/>' ),
			'feImage'            => $svg( '<filter id="f"><feImage href="https://evil.example/x"/></filter>' ),
			'a'                  => $svg( '<a href="#a"><rect/></a>' ),
			'style element'      => $svg( '<style>@import "https://evil.example/a.css";</style>' ),
			'style element url'  => $svg( '<style>rect{fill:url(https://evil.example/x)}</style>' ),
			'style attr import'  => $svg( '<rect style="@import \'x\'"/>' ),
			'style attr url'     => $svg( '<rect style="fill:url(https://evil.example/x)"/>' ),
			'fill url'           => $svg( '<rect fill="url(https://evil.example/x#g)"/>' ),
			'fill url entity'    => $svg( '<rect fill="u&#x72;l(https://evil.example/x)"/>' ),
			'cursor'             => $svg( '<rect cursor="url(//evil.example/c.cur), auto"/>' ),
			'nested data'        => $svg( '<rect fill="url(data:image/svg+xml,x)"/>' ),
			'text url'           => $svg( '<text>url(https://evil.example/x)</text>' ),
			'text markup'        => $svg( '<desc>&lt;/style&gt;&lt;script&gt;alert(1)&lt;/script&gt;</desc>' ),
			'iframe'             => $svg( '<iframe src="https://evil.example/"/>' ),
			'object'             => $svg( '<object data="https://evil.example/"/>' ),
			'embed'              => $svg( '<embed src="https://evil.example/"/>' ),
			'animate href'       => $svg( '<a><animate attributeName="href" to="javascript:alert(1)"/></a>' ),
			'set href'           => $svg( '<use href="#a"><set attributeName="href" to="https://evil.example/x"/></use>' ),
			'animate'            => $svg( '<rect><animate attributeName="width" to="2"/></rect>' ),
			'entity'             => '<!DOCTYPE svg [<!ENTITY x SYSTEM "https://evil.example/x">]>' . $svg( '<text>&x;</text>' ),
			'doctype'            => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $svg( '' ),
			'stylesheet pi'      => '<?xml-stylesheet href="https://evil.example/a.css"?>' . $svg( '' ),
			'xml declaration'    => '<?xml version="1.0"?>' . $svg( '' ),
			'comment'            => $svg( '<!-- x --><rect/>' ),
			'cdata'              => $svg( '<text><![CDATA[x]]></text>' ),
			'undefined entity'   => $svg( '<text>&nbsp;</text>' ),
			'not well formed'    => $svg( '<rect>' ),
			'not svg'            => '<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>',
			'xhtml namespace'    => '<svg xmlns="http://www.w3.org/1999/xhtml"><rect/></svg>',
			'other namespace'    => $svg( '<rect/>', ' xmlns:h="http://www.w3.org/1999/xhtml"' ),
			'two roots'          => $svg( '' ) . $svg( '' ),
			'xml base'           => $svg( '<rect xml:base="https://evil.example/"/>' ),
			'unknown element'    => $svg( '<cursor href="https://evil.example/c.png"/>' ),
		) as $name => $drawing ) {
			$this->assertFalse( InlineSvg::inert_data_url( self::url( $drawing ) ), $name );
			$this->assertFalse( InlineSvg::inert_data_url( 'data:image/svg+xml;base64,' . base64_encode( $drawing ) ), $name . ' (base64)' );
			$this->assertSame( '.x{mask:url()}', self::filter( '.x{mask:url("' . self::url( $drawing ) . '")}' ), $name );
		}
	}

	public function test_only_the_svg_type_in_one_written_form_passes(): void {
		$mask = rawurlencode( self::MASK );
		foreach ( array(
			'data:text/html,' . $mask,
			'data:text/html;charset=utf-8,' . $mask,
			'data:text/css,' . rawurlencode( '@import "https://evil.example/a.css";' ),
			'data:application/xml,' . $mask,
			'data:application/xhtml+xml,' . $mask,
			'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
			'data:image/svg,' . $mask,
			'data:image/svg+xml;charset=iso-8859-1,' . $mask,
			'data:image/svg+xml;charset="utf-8",' . $mask,
			'data:image/svg+xml;foo=bar,' . $mask,
			'data:image%2Fsvg+xml,' . $mask,
			'data:,' . $mask,
			// Written with characters a CSS string or a style element could trip on.
			'data:image/svg+xml;utf8,' . self::MASK,
			"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg'/>",
			'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22/%3E',
			'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Crect%20fill=%22#fff%22/%3E%3C/svg%3E',
			'data:image/svg+xml,' . $mask . '\\',
			'data:image/svg+xml,' . $mask . ')',
			"data:image/svg+xml,\n" . $mask,
			'data:image/svg+xml;base64,' . base64_encode( self::MASK ) . '!',
			'data:image/svg+xml;base64,' . chunk_split( base64_encode( self::MASK ), 20, ' ' ),
			'data:image/svg+xml,' . rawurlencode( "\xFF" . self::MASK ),
			'data:image/svg+xml,' . rawurlencode( mb_convert_encoding( self::MASK, 'UTF-16' ) ),
			'data:image/svg+xml,',
		) as $url ) {
			$this->assertFalse( InlineSvg::inert_data_url( $url ), $url );
		}
		$this->assertSame( '.x{background:url()}', self::filter( '.x{background:url("data:image/png;base64,iVBORw0KGgo=")}' ) );
		$this->assertSame( '.x{background:url()}', self::filter( '.x{background:url("data:text/html,' . $mask . '")}' ) );
	}

	/** Escapes, comments, nesting, image-set() and @import: no side way around the check. */
	public function test_no_side_way_around_the_check(): void {
		$url = self::url( self::MASK );
		foreach ( array(
			'scheme escaped'     => '.x{mask:url("d\\61ta:image/svg+xml;charset=utf-8,' . rawurlencode( self::MASK ) . '")}',
			'type escaped'       => '.x{mask:url("data:image/svg\\2b xml;charset=utf-8,' . rawurlencode( self::MASK ) . '")}',
			'body escaped'       => '.x{mask:url("data:image/svg+xml;charset=utf-8,\\25 3Csvg\\25 3E")}',
			'function escaped'   => '.x{mask:\\75rl("' . $url . '")}',
			'comment inside'     => '.x{mask:url("data:image/svg+xml;charset=utf-8,%3Csvg/**/%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%2F%3E")}',
			'nested url'         => '.x{mask:url("data:image/svg+xml,url(https://evil.example/n)")}',
			'nested unquoted'    => '.x{mask:url(data:image/svg+xml,url(https://evil.example/n))}',
			'quote break'        => '.x{mask:url("' . $url . '\\")} .y{background:url(https://evil.example/q)}',
			'string escape'      => '.x{mask:url("' . $url . '\\22 ) .y{background:url(https://evil.example/s)}")}',
			// image-set() keeps its own rule, which takes no data:, with or without a `;` in the type.
			'image-set'          => '.x{mask:image-set("' . self::url( self::MASK, 'image/svg+xml' ) . '" 1x)}',
			'image-set charset'  => '.x{mask:image-set("' . $url . '" 1x)}',
			'image-set url'      => '.x{mask:-webkit-image-set(url("' . self::url( self::MASK, 'image/svg+xml' ) . '") 1x)}',
			'import'             => '@import url("data:text/css,' . rawurlencode( '@import "https://evil.example/i.css";' ) . '");',
			'import svg'         => '@import url("' . $url . '");',
			'style closer'       => '.x{mask:url("data:image/svg+xml,</style><script>alert(1)</script>")}',
			'style closer coded' => '.x{mask:url("' . self::url( '<svg xmlns="http://www.w3.org/2000/svg"><title></style><script>alert(1)</script></title></svg>' ) . '")}',
		) as $name => $css ) {
			$out = self::filter( $css );
			$this->assertStringNotContainsString( 'evil.example', $out, $name );
			$this->assertStringNotContainsStringIgnoringCase( 'data:', $out, $name . ': ' . $out );
		}
		// Whatever passes is printed in <style> with no way to close it.
		$kept = self::filter( '.x{mask:url("' . $url . '")}' );
		$this->assertStringNotContainsString( '<', CssCompiler::compile( '.x', 'mask:url("' . $url . '")' ) );
		$this->assertStringNotContainsString( '<', $kept );
	}

	public function test_size_and_count_limits(): void {
		$big = '<svg xmlns="http://www.w3.org/2000/svg"><desc>' . str_repeat( 'a', InlineSvg::MAX_BYTES ) . '</desc></svg>';
		$this->assertFalse( InlineSvg::inert_data_url( self::url( $big ) ) );
		$this->assertSame( '.x{mask:url()}', self::filter( '.x{mask:url("' . self::url( $big ) . '")}' ) );
		$fits = '<svg xmlns="http://www.w3.org/2000/svg"><desc>' . str_repeat( 'a', InlineSvg::MAX_BYTES - 100 ) . '</desc></svg>';
		$this->assertTrue( InlineSvg::inert_data_url( self::url( $fits ) ) );
		$css = '.x{mask:url("' . self::url( $fits ) . '")}';
		$this->assertSame( $css, self::filter( $css ) );
		// Encoded past the limit of the written URL, even if it would decode small enough.
		$this->assertFalse( InlineSvg::inert_data_url( 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . str_repeat( '%20', InlineSvg::MAX_URL ) ) );
		// Past MAX_PER_SHEET drawings in one sheet the next url() goes, as any other.
		$rule = '.x{mask:url("' . self::url( self::MASK ) . '")}';
		$out  = self::filter( str_repeat( $rule, InlineSvg::MAX_PER_SHEET + 1 ) );
		$this->assertSame( str_repeat( $rule, InlineSvg::MAX_PER_SHEET ) . '.x{mask:url()}', $out );
	}

	/** Remote addresses keep the old rule: only this site, and only http(s). */
	public function test_remote_addresses_keep_the_old_rule(): void {
		$css = '.a{background:url(/wp-content/uploads/a.png)} .b{mask:url("' . self::url( self::MASK ) . '")}';
		$this->assertSame( $css, self::filter( $css ) );
		$this->assertSame( '.a{background:url()} .b{mask:url("' . self::url( self::MASK ) . '")}', self::filter( '.a{background:url(https://evil.example/a)} .b{mask:url("' . self::url( self::MASK ) . '")}' ) );
	}

	/** An author without unfiltered_html saving from the builder gets the same rule: the drawing stays, the beacon goes. */
	public function test_restricted_author_keeps_inert_drawings(): void {
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$author = $this->user( 'author' );
		$user   = get_userdata( $author );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$heads = $this->request( 'GET', '/styles' )->get_data();
		$body  = 'mask-image:url("' . self::url( self::MASK ) . '");background:url("data:text/html,x") url(https://evil.example/b)';
		$res   = $this->request(
			'POST',
			'/styles',
			array(
				'head'    => $heads['head'],
				'base'    => $heads['base'],
				'styles'  => array( array( 'id' => 'svg1', 'selector' => '.x', 'css' => $body ) ),
				'globals' => array(),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'mask-image:url("' . self::url( self::MASK ) . '");background:url() url()', (string) $res->get_data()['styles'][0]['css'] );
	}
}
