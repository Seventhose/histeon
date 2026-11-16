<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XB\Remote\TransferMarkup;
use XB\Remote\TransferMarkupViolation;
use XB\Security\MarkupPolicy;
use XB\Security\Violation;
use XB\Spec;

/**
 * histos-transfer-markup-v1 against the fixture shared with Histeon Publish (tests/fixtures/histos-transfer-markup-v1.json,
 * a byte-identical copy of packages/histos-contract/fixtures/ in Publish): the same lists, grammars and reason codes.
 * The documents and render vectors run in Integration\TransferMarkupDocumentsTest (they need parse_blocks()).
 */
final class TransferMarkupVectorsTest extends TestCase {

	/**
	 * Pinned in both repositories (here and in Publish's packages/cms/test/transfer-markup.test.ts): any change of the
	 * shared fixture shows up as an edit on both sides.
	 */
	private const FIXTURE_SHA256 = 'f01eba9667cc68ed616403060c61a7433b9508e92b9ca337f0c332a7adcbfc47';

	/** @return array<string, mixed> */
	private static function fixture(): array {
		static $fixture = null;
		if ( null === $fixture ) {
			$decoded = json_decode( self::bytes(), true, 512, JSON_THROW_ON_ERROR );
			$fixture = is_array( $decoded ) ? $decoded : array();
		}
		return $fixture;
	}

	private static function bytes(): string {
		$bytes = file_get_contents( dirname( __DIR__ ) . '/fixtures/histos-transfer-markup-v1.json' );
		if ( false === $bytes ) {
			throw new \RuntimeException( 'The shared transfer markup fixture is not readable.' );
		}
		return $bytes;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{parent: string|null, svg: bool, form: bool, button: bool}
	 */
	private static function context( array $context ): array {
		return array(
			'parent' => is_string( $context['parent'] ?? null ) ? $context['parent'] : null,
			'svg'    => true === ( $context['svg'] ?? null ),
			'form'   => true === ( $context['form'] ?? null ),
			'button' => true === ( $context['button'] ?? null ),
		);
	}

	private static function outcome( callable $check ): string {
		try {
			$check();
			return 'accept';
		} catch ( TransferMarkupViolation $violation ) {
			return $violation->reason;
		}
	}

	/**
	 * @param array<string, mixed> $vector
	 */
	private static function label( int $index, array $vector, string $what ): string {
		return sprintf( '#%d %s → %s%s', $index, $what, (string) $vector['expect'], isset( $vector['note'] ) ? ' (' . (string) $vector['note'] . ')' : '' );
	}

	public function test_the_fixture_is_the_one_pinned_in_both_repositories(): void {
		$this->assertSame( self::FIXTURE_SHA256, hash( 'sha256', self::bytes() ) );
		$this->assertSame( 1, self::fixture()['version'] );
		$this->assertSame( TransferMarkup::ID, self::fixture()['id'] );
	}

	public function test_the_lists_and_grammars_are_the_constants_of_the_php_port(): void {
		$lists = self::fixture()['lists'];
		$this->assertSame( $lists['htmlTags'], TransferMarkup::HTML_TAGS );
		$this->assertSame( $lists['newHtmlTags'], TransferMarkup::NEW_HTML_TAGS );
		$this->assertSame( $lists['svgTags'], TransferMarkup::SVG_TAGS );
		$this->assertSame( $lists['svgTextTags'], TransferMarkup::SVG_TEXT_TAGS );
		$this->assertSame( $lists['voidTags'], TransferMarkup::VOID_TAGS );
		$this->assertSame( $lists['markupV1Tags'], TransferMarkup::MARKUP_V1_TAGS );
		$this->assertSame( $lists['inlineTags'], TransferMarkup::INLINE_TAGS );
		$this->assertSame( $lists['globalAttributes'], TransferMarkup::GLOBAL_ATTRIBUTES );
		$this->assertSame( $lists['globalAttributePrefixes'], TransferMarkup::GLOBAL_ATTRIBUTE_PREFIXES );
		$this->assertSame( $lists['elementAttributes'], TransferMarkup::ELEMENT_ATTRIBUTES );
		$this->assertSame( $lists['elementsWithoutGlobalAttributes'], TransferMarkup::ELEMENTS_WITHOUT_GLOBAL_ATTRIBUTES );
		$this->assertSame( $lists['svgCommonAttributes'], TransferMarkup::SVG_COMMON_ATTRIBUTES );
		$this->assertSame( $lists['svgTextAttributes'], TransferMarkup::SVG_TEXT_ATTRIBUTES );
		$this->assertSame( $lists['svgAttributePrefixes'], TransferMarkup::SVG_ATTRIBUTE_PREFIXES );
		$this->assertSame( $lists['svgTextParents'], TransferMarkup::SVG_TEXT_PARENTS );
		$this->assertSame( $lists['svgTagsWithCommonAttributes'], TransferMarkup::SVG_TAGS_WITH_COMMON_ATTRIBUTES );
		$this->assertSame( $lists['svgAttributes'], TransferMarkup::SVG_ATTRIBUTES );
		$this->assertSame( $lists['svgCamelCaseAttributes'], TransferMarkup::SVG_CAMEL_CASE_ATTRIBUTES );
		$this->assertSame( $lists['blockedAttributes'], TransferMarkup::BLOCKED_ATTRIBUTES );
		$this->assertSame( $lists['formOnlyAttributes'], TransferMarkup::FORM_ONLY_ATTRIBUTES );
		$this->assertSame( $lists['blockedPrefixes'], TransferMarkup::BLOCKED_PREFIXES );
		$this->assertSame( $lists['urlAttributes'], TransferMarkup::URL_ATTRIBUTES );
		$this->assertSame( $lists['urlSchemes'], TransferMarkup::URL_SCHEMES );
		$this->assertSame( $lists['contentBlockedParents'], TransferMarkup::CONTENT_BLOCKED_PARENTS );
		$this->assertSame( $lists['enums'], TransferMarkup::ENUMS );
		$this->assertSame( $lists['htmlValueRules'], TransferMarkup::HTML_VALUE_RULES );
		$this->assertSame( $lists['svgValueRules'], TransferMarkup::SVG_VALUE_RULES );
		$this->assertSame( $lists['grammars'], TransferMarkup::GRAMMARS );
		$this->assertSame( $lists['legacyReferences'], TransferMarkup::LEGACY_REFERENCES );
		$keys = array_keys( $lists );
		sort( $keys );
		$this->assertSame(
			array( 'blockedAttributes', 'blockedPrefixes', 'contentBlockedParents', 'elementAttributes', 'elementsWithoutGlobalAttributes', 'enums', 'formOnlyAttributes', 'globalAttributePrefixes', 'globalAttributes', 'grammars', 'htmlTags', 'htmlValueRules', 'inlineTags', 'legacyReferences', 'markupV1Tags', 'newHtmlTags', 'svgAttributePrefixes', 'svgAttributes', 'svgCamelCaseAttributes', 'svgCommonAttributes', 'svgTags', 'svgTagsWithCommonAttributes', 'svgTextAttributes', 'svgTextParents', 'svgTextTags', 'svgValueRules', 'urlAttributes', 'urlSchemes', 'voidTags' ),
			$keys
		);
		// Inline formatting is also what the renderer and the sanitizer allow inside xb/text.
		$this->assertSame( TransferMarkup::INLINE_TAGS, array_map( 'array_keys', Spec::TEXT_INLINE_HTML ) );
	}

	public function test_grammars_compile_as_pcre_with_ascii_classes_and_rules_point_to_known_entries(): void {
		foreach ( TransferMarkup::GRAMMARS as $name => $source ) {
			$this->assertStringNotContainsString( '~', $source, $name . ': the PCRE delimiter' );
			$this->assertDoesNotMatchRegularExpression( '/\\\\[sdwSDW]/', $source, $name . ': only ASCII classes written out' );
			$this->assertNotFalse( preg_match( TransferMarkup::pattern( $name ), '' ), $name . ' must compile' );
		}
		foreach ( TransferMarkup::HTML_VALUE_RULES as $key => $rule ) {
			if ( isset( $rule['enum'] ) ) {
				$this->assertArrayHasKey( $rule['enum'], TransferMarkup::ENUMS, $key );
			}
			if ( isset( $rule['grammar'] ) ) {
				$this->assertArrayHasKey( $rule['grammar'], TransferMarkup::GRAMMARS, $key );
			}
		}
		foreach ( TransferMarkup::SVG_VALUE_RULES as $key => $grammar ) {
			$this->assertArrayHasKey( $grammar, TransferMarkup::GRAMMARS, $key );
		}
		foreach ( TransferMarkup::SVG_ATTRIBUTE_PREFIXES as $prefix ) {
			$this->assertArrayHasKey( $prefix . '*', TransferMarkup::SVG_VALUE_RULES, $prefix );
		}
		$has_rule = static fn ( array $rules, string $tag, string $attribute ): bool => array_key_exists( $tag . '.' . $attribute, $rules ) || array_key_exists( $attribute, $rules );
		foreach ( TransferMarkup::SVG_ATTRIBUTES as $tag => $attributes ) {
			$all = in_array( $tag, TransferMarkup::SVG_TAGS_WITH_COMMON_ATTRIBUTES, true ) ? array_merge( $attributes, TransferMarkup::SVG_COMMON_ATTRIBUTES, TransferMarkup::SVG_TEXT_ATTRIBUTES ) : $attributes;
			foreach ( $all as $attribute ) {
				$this->assertTrue( $has_rule( TransferMarkup::SVG_VALUE_RULES, $tag, $attribute ), $tag . '.' . $attribute );
			}
		}
	}

	public function test_vectors_use_only_known_reason_codes(): void {
		$fixture = self::fixture();
		$reasons = array_merge( array( 'accept' ), TransferMarkup::REASONS );
		$count   = 0;
		foreach ( array( 'elements', 'children', 'texts', 'blocks', 'documents' ) as $group ) {
			foreach ( $fixture[ $group ] as $vector ) {
				$this->assertContains( $vector['expect'], $reasons, $group );
				++$count;
			}
		}
		$this->assertGreaterThan( 300, $count, 'the shared fixture carries its vectors' );
	}

	/** @return iterable<string, array{string, array<array-key, mixed>, array<string, mixed>, string}> */
	public static function element_vectors(): iterable {
		foreach ( self::fixture()['elements'] as $index => $vector ) {
			$what = '<' . $vector['tag'] . '> ' . substr( (string) json_encode( $vector['attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 80 ) . ' in ' . ( $vector['ctx']['parent'] ?? 'root' );
			yield self::label( $index, $vector, $what ) => array( $vector['tag'], $vector['attributes'], $vector['ctx'], $vector['expect'] );
		}
	}

	/**
	 * @param array<array-key, mixed> $attributes
	 * @param array<string, mixed>    $context
	 */
	#[DataProvider( 'element_vectors' )]
	public function test_element( string $tag, array $attributes, array $context, string $expect ): void {
		$this->assertSame( $expect, self::outcome( static fn () => TransferMarkup::check_element( $tag, $attributes, self::context( $context ) ) ) );
	}

	/** @return iterable<string, array{string, list<string>, string}> */
	public static function children_vectors(): iterable {
		foreach ( self::fixture()['children'] as $index => $vector ) {
			yield self::label( $index, $vector, '<' . $vector['tag'] . '> [' . implode( ', ', $vector['kinds'] ) . ']' ) => array( $vector['tag'], $vector['kinds'], $vector['expect'] );
		}
	}

	/** @param list<string> $kinds */
	#[DataProvider( 'children_vectors' )]
	public function test_children( string $tag, array $kinds, string $expect ): void {
		$this->assertSame( $expect, self::outcome( static fn () => TransferMarkup::check_children( $tag, $kinds ) ) );
	}

	/** @return iterable<string, array{string, bool, array<string, mixed>, string}> */
	public static function text_vectors(): iterable {
		foreach ( self::fixture()['texts'] as $index => $vector ) {
			$what = substr( (string) json_encode( $vector['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 60 ) . ( $vector['verbatim'] ? ' verbatim' : '' ) . ' in ' . ( $vector['ctx']['parent'] ?? 'root' );
			yield self::label( $index, $vector, $what ) => array( $vector['content'], $vector['verbatim'], $vector['ctx'], $vector['expect'] );
		}
	}

	/** @param array<string, mixed> $context */
	#[DataProvider( 'text_vectors' )]
	public function test_text( string $content, bool $verbatim, array $context, string $expect ): void {
		$this->assertSame( $expect, self::outcome( static fn () => TransferMarkup::check_text( $content, $verbatim, self::context( $context ) ) ) );
	}

	/** @return iterable<string, array{string, array<string, mixed>, string}> */
	public static function block_vectors(): iterable {
		foreach ( self::fixture()['blocks'] as $index => $vector ) {
			yield self::label( $index, $vector, $vector['name'] . ' in ' . ( $vector['ctx']['parent'] ?? 'root' ) . ( $vector['ctx']['svg'] ? ' (svg)' : '' ) ) => array( $vector['name'], $vector['ctx'], $vector['expect'] );
		}
	}

	/** @param array<string, mixed> $context */
	#[DataProvider( 'block_vectors' )]
	public function test_block( string $name, array $context, string $expect ): void {
		$this->assertSame( $expect, self::outcome( static fn () => TransferMarkup::check_block( $name, self::context( $context ) ) ) );
	}

	/**
	 * Threat model T26: transferred markup stays within the restricted tier of the builder's own policy, whoever owns
	 * the link. The only exception is `<form>`, a trusted-only tag that a transfer may carry with a static endpoint.
	 * Media identities are compared as WordPress writes them on import (an uploads URL), since MarkupPolicy does not
	 * know the `histos-media` scheme.
	 */
	public function test_every_accepted_element_passes_the_restricted_tier_of_markup_policy(): void {
		$policy  = new MarkupPolicy();
		$checked = 0;
		foreach ( self::fixture()['elements'] as $vector ) {
			if ( 'accept' !== $vector['expect'] ) {
				continue;
			}
			$attributes = array();
			foreach ( $vector['attributes'] as $name => $value ) {
				$attributes[ (string) $name ] = is_string( $value ) ? (string) preg_replace( '#histos-media://[0-9a-f-]{36}#', 'https://example.test/wp-content/uploads/foto.png', $value ) : $value;
			}
			$codes = array_map( static fn ( Violation $violation ): string => $violation->code, $policy->check_element( $vector['tag'], $attributes, MarkupPolicy::TIER_RESTRICTED ) );
			$this->assertSame( 'form' === $vector['tag'] ? array( Violation::TAG_TRUSTED_ONLY ) : array(), $codes, '<' . $vector['tag'] . '> ' . (string) json_encode( $vector['attributes'] ) );
			++$checked;
		}
		$this->assertGreaterThan( 50, $checked );
	}

	public function test_children_inherit_svg_form_and_button_and_controls_pass_the_context_through(): void {
		$in_svg = TransferMarkup::child_context( TransferMarkup::ROOT_CONTEXT, 'svg' );
		$this->assertSame( array( 'parent' => 'svg', 'svg' => true, 'form' => false, 'button' => false ), $in_svg );
		$this->assertSame(
			array( 'parent' => 'span', 'svg' => false, 'form' => true, 'button' => true ),
			TransferMarkup::child_context( TransferMarkup::child_context( TransferMarkup::child_context( TransferMarkup::ROOT_CONTEXT, 'form' ), 'button' ), 'span' )
		);
		$this->assertSame( 'control', TransferMarkup::child_kind( array( 'blockName' => 'xb/loop', 'attrs' => array() ) ) );
		$this->assertSame( 'space', TransferMarkup::child_kind( array( 'blockName' => 'xb/text', 'attrs' => array( 'content' => " \n" ) ) ) );
		$this->assertSame( 'verbatim', TransferMarkup::child_kind( array( 'blockName' => 'xb/text', 'attrs' => array( 'content' => 'x', 'verbatim' => true ) ) ) );
		$this->assertSame( 'element:source', TransferMarkup::child_kind( array( 'blockName' => 'xb/element', 'attrs' => array( 'tag' => 'source' ) ) ) );
		$this->assertSame( 'block:xb/raw', TransferMarkup::child_kind( array( 'blockName' => 'xb/raw', 'attrs' => array() ) ) );
	}

	public function test_urls_styles_and_form_endpoints_are_checked_on_the_decoded_value(): void {
		$this->assertTrue( TransferMarkup::url_is_safe( '/x?a=1&b=2' ) );
		$this->assertTrue( TransferMarkup::url_is_safe( '{item.url}' ) );
		$this->assertTrue( TransferMarkup::url_is_safe( '//example.org/x' ) );
		$this->assertFalse( TransferMarkup::url_is_safe( "java\nscript:alert(1)" ) );
		$this->assertFalse( TransferMarkup::url_is_safe( 'ftp://example.org/' ) );
		$this->assertFalse( TransferMarkup::url_is_safe( "javascript:\xC3" ), 'invalid UTF-8 is refused' );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: cross-fade(a, b)' ) );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: \\000075rl(x)' ) );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: \\75 rl(x)' ) );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: u/**/rl(x)' ), 'any /* counts: comments are never stripped' );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: url(x) /* unterminated' ) );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'color: red /* url(x)' ), 'any /* counts, unterminated too' );
		$this->assertTrue( TransferMarkup::style_loads_resources( "--a:'/*';background:url(https://tracker.example/x.gif);--b:'*/'" ), 'a /* inside a CSS string starts no comment' );
		$this->assertTrue( TransferMarkup::style_loads_resources( "background:\\75\r\nrl(https://tracker.example/x.png)" ), 'CR LF after a hex escape is one LF, consumed by the escape' );
		$this->assertSame( "a\u{FFFD}b", TransferMarkup::decode_css_escapes( "a\0b" ), 'NUL becomes U+FFFD before the escapes' );
		$this->assertTrue( TransferMarkup::style_loads_resources( 'background: -webkit-image-set(a 1x)' ) );
		$this->assertTrue( TransferMarkup::style_loads_resources( "color: red\xC3" ), 'invalid UTF-8 counts as a resource' );
		$this->assertFalse( TransferMarkup::style_loads_resources( 'color: var(--xb-primary)' ) );
		$this->assertSame( "a\u{FFFD}b", TransferMarkup::decode_css_escapes( 'a\\d800 b' ), 'surrogates become U+FFFD' );
		$this->assertSame( "a\u{FFFD}b", TransferMarkup::decode_css_escapes( 'a\\0 b' ) );
		$this->assertTrue( TransferMarkup::form_action_is_safe( '/cerca/' ) );
		$this->assertTrue( TransferMarkup::form_action_is_safe( '/eventi/2026:10' ), 'a relative path is never read as host:port' );
		$this->assertFalse( TransferMarkup::form_action_is_safe( 'https://@evil.example/x' ), 'an empty userinfo is still userinfo' );
		$this->assertTrue( TransferMarkup::form_action_is_safe( 'https://forms.example/invio' ) );
		$this->assertFalse( TransferMarkup::form_action_is_safe( "https://forms.example/invio\u{0085}" ) );
		$this->assertFalse( TransferMarkup::form_action_is_safe( "/invio\u{FEFF}" ) );
	}

	/** Endpoints Publish's URL parser refuses for their host, and parse_url() reads (review of A6): same outcome. */
	public function test_form_endpoints_with_a_forbidden_host_code_point_are_refused_as_in_publish(): void {
		foreach ( array( 'https://evil.example%40good/', 'https://1m%', 'https://a|b/', 'https://a^b/', 'https://a<b/', 'https://good.example%2Fevil/' ) as $action ) {
			$this->assertFalse( TransferMarkup::form_action_is_safe( $action ), $action );
		}
		foreach ( array( 'https://good.example:8443/x', 'https://[::1]/x', 'https://a.example/%40x?q=%7C', '/eventi/%40/x' ) as $action ) {
			$this->assertTrue( TransferMarkup::form_action_is_safe( $action ), $action );
		}
	}

	public function test_numeric_references_decode_identically_or_are_refused(): void {
		$this->assertTrue( TransferMarkup::references_are_safe( '?a=1&b=2 &amp; &#58; &#x3A; &#xA0; &#x10FFFD;' ) );
		foreach ( array( 'javascript&#58alert(1)', '&#128;', '&#9;', '&#0;', '&#xD800;', '&#x110000;', '&#xFDD0;', '&#xFFFE;', '&#x1FFFF;', '&#12345678;', '&#x;' ) as $bad ) {
			$this->assertFalse( TransferMarkup::references_are_safe( $bad ), $bad );
		}
		$this->assertSame( 'javascript:alert(1)', TransferMarkup::decode_attribute( 'javascript&#58;alert(1)' ) );
		$this->assertSame( 'reference', self::outcome( static fn () => TransferMarkup::decode_attribute( '&#58' ) ) );
	}

	public function test_srcset_is_read_only_in_the_shared_shape_and_media_identities_are_strict(): void {
		$this->assertSame(
			array(
				array(
					'url'        => 'a.png',
					'descriptor' => '1x',
				),
				array(
					'url'        => 'b.png',
					'descriptor' => '2x',
				),
			),
			TransferMarkup::parse_srcset( 'a.png 1x,  b.png 2x' )
		);
		$this->assertSame( array( array( 'url' => 'a.png', 'descriptor' => null ) ), TransferMarkup::parse_srcset( " a.png\t" ) );
		$this->assertNull( TransferMarkup::parse_srcset( 'a.png 1x 2x' ) );
		$this->assertNull( TransferMarkup::parse_srcset( '' ) );
		// Commas split candidates, so a data: URL never survives as one candidate and its scheme is refused (G6).
		$data = TransferMarkup::parse_srcset( 'data:image/png;base64,AAAA 1x' );
		$this->assertSame( 'data:image/png;base64', $data[0]['url'] ?? null );
		$this->assertFalse( TransferMarkup::url_is_safe( $data[0]['url'] ) );
		$this->assertSame( 'a1111111-1111-4111-8111-111111111111', TransferMarkup::media_id( 'histos-media://a1111111-1111-4111-8111-111111111111' ) );
		$this->assertNull( TransferMarkup::media_id( 'histos-media://A1111111-1111-4111-8111-111111111111' ) );
		$this->assertNull( TransferMarkup::media_id( "histos-media://a1111111-1111-4111-8111-111111111111\n" ) );
		$this->assertTrue( TransferMarkup::is_whole_interpolation( '{props.immagine}' ) );
		$this->assertTrue( TransferMarkup::is_whole_interpolation( '{props.x ?? "}"}' ) );
		$this->assertFalse( TransferMarkup::is_whole_interpolation( '{a}{b}' ) );
		$this->assertFalse( TransferMarkup::is_whole_interpolation( 'https://cdn.example/{props.nome}.png' ) );
	}

	public function test_a_violation_carries_a_known_reason_and_stays_a_domain_exception(): void {
		$violation = new TransferMarkupViolation( 'media', 'x' );
		$this->assertInstanceOf( \DomainException::class, $violation );
		$this->assertSame( 'media', $violation->reason );
		try {
			new TransferMarkupViolation( 'unknown', 'x' );
			self::fail( 'An unknown reason code was accepted.' );
		} catch ( \LogicException $error ) {
			$this->assertSame( \LogicException::class, $error::class );
		}
	}
}
