<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

/**
 * Which tags and attributes an author may store in xb/element blocks — docs/security/threat-model.md §4.
 *
 * Two tiers:
 * - trusted:    the author has `unfiltered_html`. Anything except document-level elements.
 * - restricted: everyone else. Allowlisted tags, no script-bearing attributes, URL schemes checked.
 *
 * The policy is enforced on every write path (REST, Gutenberg save, import, AI operations), never only in the editor.
 * Pure PHP on purpose: no WordPress calls, so it is unit-testable and portable. The WordPress adapter supplies
 * the tier (current_user_can('unfiltered_html')) and the protocol list (wp_allowed_protocols()).
 */
final class MarkupPolicy {

	public const TIER_TRUSTED    = 'trusted';
	public const TIER_RESTRICTED = 'restricted';

	public const TAG_PATTERN       = '/^[a-z][a-zA-Z0-9]*(?:-[a-zA-Z0-9]+)*$/';
	public const ATTRIBUTE_PATTERN = '/^[a-zA-Z_:@][a-zA-Z0-9_:.@-]*$/';

	/** Document-level elements: meaningless or dangerous inside a block tree, for every tier. */
	public const FORBIDDEN_TAGS = array( 'html', 'head', 'body', 'base', 'meta', 'link', 'frame', 'frameset' );

	/** Content elements a restricted author may use. Custom elements (with a hyphen) are also allowed. */
	private const RESTRICTED_TAGS = array(
		// HTML.
		'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'button',
		'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'div',
		'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
		'hgroup', 'hr', 'i', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'main', 'map', 'mark', 'menu',
		'meter', 'nav', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'progress', 'q', 'rp', 'rt',
		'ruby', 's', 'samp', 'search', 'section', 'select', 'small', 'source', 'span', 'strong', 'sub', 'summary',
		'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'track', 'u', 'ul', 'var',
		'video', 'wbr',
		// SVG without animation or foreign content.
		'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'textPath',
		'defs', 'symbol', 'use', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern', 'marker',
		'title', 'desc',
	);

	/** Attributes whose value is a URL (or a list of URLs). */
	public const URL_ATTRIBUTES = array(
		'href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'longdesc', 'xlink:href', 'data',
		'codebase', 'manifest', 'ping', 'srcset', 'imagesrcset',
	);

	/** Attributes that execute or inject markup no matter the prefix rules below. */
	private const TRUSTED_ONLY_ATTRIBUTES = array( 'srcdoc', 'data-bind' );

	/**
	 * Attribute prefixes used by event handlers and client frameworks that evaluate their value as code
	 * (Alpine, Vue, htmx, Angular, Knockout, Datastar), also in their `data-` spellings, as TransferMarkup refuses
	 * them. `on` covers onclick, onload, …
	 */
	private const TRUSTED_ONLY_PREFIXES = array( 'on', '@', ':', 'x-', 'v-', 'hx-', 'ng-', 'ng_', 'data-hx-', 'data-ng-', 'data-on-', 'data-bind-' );

	/** Mirrors the core default of wp_allowed_protocols(). */
	public const DEFAULT_PROTOCOLS = array(
		'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet',
		'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn',
	);

	/** @var array<string, true> */
	private readonly array $protocols;

	/**
	 * @param list<string> $allowed_protocols URL schemes allowed for restricted authors.
	 */
	public function __construct( array $allowed_protocols = self::DEFAULT_PROTOCOLS ) {
		$this->protocols = array_fill_keys( array_map( 'strtolower', $allowed_protocols ), true );
	}

	public function check_tag( string $tag, string $tier ): ?Violation {
		if ( 1 !== preg_match( self::TAG_PATTERN, $tag ) ) {
			return new Violation( Violation::TAG_INVALID, $tag, sprintf( /* translators: %s: HTML tag name */ __( 'Nome di tag non valido "%s".', 'xb' ), $tag ) );
		}
		if ( in_array( strtolower( $tag ), self::FORBIDDEN_TAGS, true ) ) {
			return new Violation( Violation::TAG_FORBIDDEN, $tag, sprintf( /* translators: %s: HTML tag name */ __( '<%s> non è ammesso in un blocco.', 'xb' ), $tag ) );
		}
		if ( self::TIER_TRUSTED === $tier ) {
			return null;
		}
		if ( in_array( $tag, self::RESTRICTED_TAGS, true ) || str_contains( $tag, '-' ) ) {
			return null;
		}
		$code = in_array( strtolower( $tag ), array( 'script', 'style', 'iframe', 'object', 'embed', 'form' ), true )
			? Violation::TAG_TRUSTED_ONLY
			: Violation::TAG_NOT_ALLOWED;
		return new Violation( $code, $tag, sprintf( /* translators: %s: HTML tag name */ __( '<%s> richiede il permesso unfiltered_html.', 'xb' ), $tag ) );
	}

	/**
	 * @param string|true $value Attribute value as stored; may contain `{expression}` interpolations.
	 */
	public function check_attribute( string $name, string|true $value, string $tier ): ?Violation {
		// data-xb-uid is the builder's own annotation: written by an author it would pick another element in the
		// canvas and stop static deploys, which refuse bundles that carry it.
		if ( 1 !== preg_match( self::ATTRIBUTE_PATTERN, $name ) || str_starts_with( $name, 'xb:' ) || 'data-xb-uid' === strtolower( $name ) ) {
			return new Violation( Violation::ATTR_INVALID, $name, sprintf( /* translators: %s: attribute name */ __( 'Nome di attributo non valido "%s".', 'xb' ), $name ) );
		}
		if ( self::TIER_TRUSTED === $tier ) {
			return null;
		}
		$lower = strtolower( $name );
		if ( in_array( $lower, self::TRUSTED_ONLY_ATTRIBUTES, true ) || $this->has_trusted_only_prefix( $lower ) ) {
			return new Violation(
				Violation::ATTR_TRUSTED_ONLY,
				$name,
				sprintf( /* translators: %s: attribute name */ __( 'L\'attributo "%s" può eseguire codice e richiede il permesso unfiltered_html.', 'xb' ), $name )
			);
		}
		if ( is_string( $value ) && in_array( $lower, self::URL_ATTRIBUTES, true ) && ! $this->urls_are_safe( $lower, $value ) ) {
			return new Violation(
				Violation::ATTR_UNSAFE_URL,
				$name,
				sprintf( /* translators: %s: attribute name */ __( 'Protocollo non ammesso nell\'attributo "%s".', 'xb' ), $name )
			);
		}
		return null;
	}

	/**
	 * @param array<string, string|true> $attributes
	 * @return list<Violation>
	 */
	public function check_element( string $tag, array $attributes, string $tier ): array {
		$violations = array();
		$tag_violation = $this->check_tag( $tag, $tier );
		if ( null !== $tag_violation ) {
			$violations[] = $tag_violation;
		}
		foreach ( $attributes as $name => $value ) {
			$attribute_violation = $this->check_attribute( (string) $name, $value, $tier );
			if ( null !== $attribute_violation ) {
				$violations[] = $attribute_violation;
			}
		}
		return $violations;
	}

	/**
	 * Whether a form endpoint may travel in a portable CMS profile (docs/spec/cms-bridge.md): a path of the same site or
	 * an HTTPS URL, static, without credentials, fragment, whitespace or control characters. Entities are decoded
	 * first, as the browser would: `javascript&colon;` is `javascript:`. This does not change who may write a
	 * `<form>` on the site: that stays trusted-only (TRUSTED_ONLY tags).
	 */
	public static function safe_form_action( string $value ): bool {
		return self::safe_decoded_form_action( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The same rule on a value already decoded as the browser reads it (\XB\Remote\TransferMarkup decodes once, after
	 * its numeric-reference check, so nothing is decoded twice). Control characters are `\p{Cc}` (C1 included, such as
	 * U+0085), separators `\p{Z}` and U+FEFF, as in Histeon Publish; a value that is not valid UTF-8 is refused.
	 */
	public static function safe_decoded_form_action( string $value ): bool {
		if ( '' === $value || 0 !== preg_match( '/[\p{Cc}\p{Z}\x{FEFF}]/u', $value ) || false !== strpbrk( $value, '\\#{}' ) || str_starts_with( $value, '//' ) ) {
			return false;
		}
		// ASCII letters alone, whatever the PHP locale: with the `i` flag of PCRE a Turkish locale does not read `I` as a
		// letter of `[a-z]`, and `JAVASCRIPT:` was no scheme, so a path of this site (T31, P1).
		if ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9+.-]*:/', $value ) ) {
			if ( 1 !== preg_match( '#^https://[^/]#', strtolower( $value ) ) ) {
				return false;
			}
			$parts = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure policy, also used outside WordPress (unit tests); scheme-relative URLs are refused above.
			return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== (string) ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] );
		}
		// No scheme, no `//`, no `#`, no backslash: a relative reference has no authority, credentials or fragment.
		// parse_url() would read `/eventi/2026:10` or `1:80` as host and port, so it is not used here.
		return true;
	}

	private function has_trusted_only_prefix( string $lower_name ): bool {
		foreach ( self::TRUSTED_ONLY_PREFIXES as $prefix ) {
			if ( str_starts_with( $lower_name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private function urls_are_safe( string $attribute, string $value ): bool {
		$urls = in_array( $attribute, array( 'srcset', 'imagesrcset' ), true )
			? array_map( static fn ( string $candidate ): string => (string) preg_replace( '/\s+\S+$/', '', trim( $candidate ) ), explode( ',', $value ) )
			: ( 'ping' === $attribute ? (array) preg_split( '/\s+/', trim( $value ) ) : array( $value ) );

		foreach ( $urls as $url ) {
			if ( ! $this->url_is_safe( (string) $url ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Static URLs are checked here. A URL that starts with an interpolation (`{post.url}`) is resolved at render
	 * time and escaped with esc_url() there; a static prefix before the interpolation is still checked.
	 */
	private function url_is_safe( string $url ): bool {
		$decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Browsers ignore ASCII whitespace and control characters inside the scheme ("java\tscript:").
		$normalized = strtolower( (string) preg_replace( '/[\x00-\x20\x7F]+/', '', $decoded ) );
		$static     = strstr( $normalized, '{', true );
		$static     = false === $static ? $normalized : $static;

		$colon = strpos( $static, ':' );
		if ( false === $colon ) {
			return true; // Relative URL, fragment or query, or a dynamic URL whose scheme is not known yet.
		}
		$before = substr( $static, 0, $colon );
		if ( strpbrk( $before, '/?#' ) !== false ) {
			return true; // The colon belongs to the path or query, not to a scheme.
		}
		return isset( $this->protocols[ $before ] );
	}
}
