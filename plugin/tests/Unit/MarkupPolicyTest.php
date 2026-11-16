<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XB\Security\MarkupPolicy;
use XB\Security\Violation;

final class MarkupPolicyTest extends TestCase {

	private MarkupPolicy $policy;

	protected function setUp(): void {
		$this->policy = new MarkupPolicy();
	}

	/** @return iterable<string, array{string, string, ?string}> */
	public static function tags(): iterable {
		yield 'div for restricted' => array( 'div', MarkupPolicy::TIER_RESTRICTED, null );
		yield 'svg gradient keeps camelCase' => array( 'linearGradient', MarkupPolicy::TIER_RESTRICTED, null );
		yield 'custom element' => array( 'my-widget', MarkupPolicy::TIER_RESTRICTED, null );
		yield 'script for restricted' => array( 'script', MarkupPolicy::TIER_RESTRICTED, Violation::TAG_TRUSTED_ONLY );
		yield 'iframe for restricted' => array( 'iframe', MarkupPolicy::TIER_RESTRICTED, Violation::TAG_TRUSTED_ONLY );
		yield 'svg animation for restricted' => array( 'animate', MarkupPolicy::TIER_RESTRICTED, Violation::TAG_NOT_ALLOWED );
		yield 'foreignObject for restricted' => array( 'foreignObject', MarkupPolicy::TIER_RESTRICTED, Violation::TAG_NOT_ALLOWED );
		yield 'script for trusted' => array( 'script', MarkupPolicy::TIER_TRUSTED, null );
		yield 'body even for trusted' => array( 'body', MarkupPolicy::TIER_TRUSTED, Violation::TAG_FORBIDDEN );
		yield 'meta even for trusted' => array( 'meta', MarkupPolicy::TIER_TRUSTED, Violation::TAG_FORBIDDEN );
		yield 'uppercase first letter' => array( 'Div', MarkupPolicy::TIER_TRUSTED, Violation::TAG_INVALID );
		yield 'markup injection in name' => array( 'img src=x', MarkupPolicy::TIER_TRUSTED, Violation::TAG_INVALID );
	}

	#[DataProvider( 'tags' )]
	public function test_tags( string $tag, string $tier, ?string $expected ): void {
		$this->assertSame( $expected, $this->policy->check_tag( $tag, $tier )?->code );
	}

	/** @return iterable<string, array{string, string|true, string, ?string}> */
	public static function attributes(): iterable {
		$r = MarkupPolicy::TIER_RESTRICTED;
		$t = MarkupPolicy::TIER_TRUSTED;
		yield 'class' => array( 'class', 'btn btn--primary', $r, null );
		yield 'boolean' => array( 'disabled', true, $r, null );
		yield 'aria and data' => array( 'data-x', '{item.id}', $r, null );
		yield 'onclick restricted' => array( 'onclick', 'alert(1)', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'ONLOAD uppercase' => array( 'ONLOAD', 'x()', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'alpine shorthand' => array( '@click', 'open = true', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'alpine x-html' => array( 'x-html', 'payload', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'vue binding' => array( ':href', 'url', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'htmx' => array( 'hx-get', '/x', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'srcdoc' => array( 'srcdoc', '<p>x</p>', $r, Violation::ATTR_TRUSTED_ONLY );
		// Note 104 of the 3 November audit: the data- spellings TransferMarkup refuses too.
		yield 'htmx data-' => array( 'data-hx-get', '/x', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'angular data-' => array( 'data-ng-click', 'x()', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'angular ng_' => array( 'ng_bind_html', 'x', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'datastar data-on-' => array( 'data-on-click', 'x()', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'knockout data-bind' => array( 'data-bind', 'html: x', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'data-bind- prefix' => array( 'data-bind-text', 'x', $r, Violation::ATTR_TRUSTED_ONLY );
		yield 'data-online is plain data' => array( 'data-online', '1', $r, null );
		yield 'onclick trusted' => array( 'onclick', 'alert(1)', $t, null );
		yield 'invalid name' => array( 'a"b', 'x', $t, Violation::ATTR_INVALID );
		yield 'pseudo attribute' => array( 'xb:label', 'x', $t, Violation::ATTR_INVALID );
		yield 'https link' => array( 'href', 'https://example.org', $r, null );
		yield 'relative link' => array( 'href', '/contatti/?a=b:c#x', $r, null );
		yield 'mailto' => array( 'href', 'mailto:info@example.org', $r, null );
		yield 'dynamic url' => array( 'href', '{post.url}', $r, null );
		yield 'javascript url' => array( 'href', 'javascript:alert(1)', $r, Violation::ATTR_UNSAFE_URL );
		yield 'obfuscated scheme' => array( 'href', " JaVa\tScRiPt:alert(1)", $r, Violation::ATTR_UNSAFE_URL );
		yield 'entity encoded colon' => array( 'href', 'javascript&colon;alert(1)', $r, Violation::ATTR_UNSAFE_URL );
		yield 'numeric entities' => array( 'href', '&#106;avascript:alert(1)', $r, Violation::ATTR_UNSAFE_URL );
		yield 'static prefix before interpolation' => array( 'href', 'javascript:{x}', $r, Violation::ATTR_UNSAFE_URL );
		yield 'data url image' => array( 'src', 'data:image/svg+xml;base64,AAAA', $r, Violation::ATTR_UNSAFE_URL );
		yield 'srcset ok' => array( 'srcset', '/a.jpg 1x, https://cdn.example.org/b.jpg 2x', $r, null );
		yield 'srcset with bad candidate' => array( 'srcset', '/a.jpg 1x, javascript:x 2x', $r, Violation::ATTR_UNSAFE_URL );
		yield 'svg xlink' => array( 'xlink:href', 'javascript:x', $r, Violation::ATTR_UNSAFE_URL );
		yield 'javascript url trusted' => array( 'href', 'javascript:void(0)', $t, null );
	}

	#[DataProvider( 'attributes' )]
	public function test_attributes( string $name, string|true $value, string $tier, ?string $expected ): void {
		$this->assertSame( $expected, $this->policy->check_attribute( $name, $value, $tier )?->code );
	}

	public function test_element_collects_every_violation(): void {
		$violations = $this->policy->check_element(
			'iframe',
			array(
				'src'    => 'javascript:x',
				'onload' => 'x()',
				'class'  => 'ok',
			),
			MarkupPolicy::TIER_RESTRICTED
		);
		$this->assertSame(
			array( Violation::TAG_TRUSTED_ONLY, Violation::ATTR_UNSAFE_URL, Violation::ATTR_TRUSTED_ONLY ),
			array_map( static fn ( Violation $v ): string => $v->code, $violations )
		);
	}

	public function test_protocol_list_is_configurable(): void {
		$policy = new MarkupPolicy( array( 'https' ) );
		$this->assertNotNull( $policy->check_attribute( 'href', 'http://example.org', MarkupPolicy::TIER_RESTRICTED ) );
		$this->assertNull( $policy->check_attribute( 'href', 'https://example.org', MarkupPolicy::TIER_RESTRICTED ) );
	}

	public function test_portable_form_endpoints_are_same_site_paths_or_https(): void {
		foreach ( array( '/contatti', 'invio', './invio', '?q=1', '/invio?a=1&amp;b=2', 'https://forms.example/submit', 'HTTPS://forms.example/x' ) as $ok ) {
			$this->assertTrue( MarkupPolicy::safe_form_action( $ok ), $ok );
		}
		foreach ( array( '', ' /x', '/x ', "/a\tb", "/a\u{00A0}b", '#f', '//evil.example', '/\\evil.example', 'http://x.example', 'https:x', 'https:///x', 'https://u:p@x.example', 'https://x.example/#f', 'javascript:alert(1)', 'javascript&colon;alert(1)', 'java&Tab;script:alert(1)', 'mailto:a@b.example', '{site.url}/x' ) as $bad ) {
			$this->assertFalse( MarkupPolicy::safe_form_action( $bad ), $bad );
		}
	}

	/** The control set is the one Histeon Publish uses: C1 controls such as U+0085 and the BOM U+FEFF are refused too. */
	public function test_portable_form_endpoints_refuse_c1_controls_and_the_byte_order_mark(): void {
		foreach ( array( "/invio\u{0085}", "https://forms.example/invio\u{0085}", "/in\u{FEFF}vio", "\u{FEFF}https://forms.example/x", '/invio&#x85;', '/invio&#xFEFF;', "/invio\u{009F}", "/x\xC3" ) as $bad ) {
			$this->assertFalse( MarkupPolicy::safe_form_action( $bad ), bin2hex( $bad ) );
		}
		$this->assertTrue( MarkupPolicy::safe_decoded_form_action( '/invio?a=1&b=2' ) );
		$this->assertFalse( MarkupPolicy::safe_decoded_form_action( "/invio\u{0085}" ) );
		// Decoded once only: a reference that survives the browser's decoding stays text, it is never decoded again.
		$this->assertFalse( MarkupPolicy::safe_decoded_form_action( 'https:&#47;&#47;evil.example/' ) );
	}
}
