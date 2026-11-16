<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

/**
 * A drawing written inside a stylesheet, `url("data:image/svg+xml;charset=utf-8,…")`: the mask of a WordPress image
 * block, the arrow of a select, the grain of a background — docs/security/threat-model.md T31.
 *
 * A browser shows an SVG used as a CSS image without running its scripts and without loading anything it names, so
 * such a URL loads no remote resource. The check below is a second line: the same text could end up elsewhere (an
 * editor, an export). It accepts only one written form and an inert drawing, and refuses in any doubt:
 *
 * - the URL is `data:` + the type `image/svg+xml`, optionally `;charset=utf-8` (or `utf8`) and/or `;base64`, then a
 *   comma and a body of URL characters only: no quote, backslash, parenthesis, space, `#`, `<`, `>` or brace, so the
 *   `url()` around it ends where a browser ends it and nothing in it can close a `<style>`;
 * - at most MAX_URL characters, and MAX_BYTES once decoded (percent-encoding, then base64) as valid UTF-8;
 * - no doctype, entity declaration, comment, CDATA or processing instruction (`<!`, `<?`);
 * - a well-formed XML document read by libxml without network or DTD, with one `<svg>` root, at most MAX_ELEMENTS
 *   elements, all of a closed list of static ones (no script, foreignObject, style, image, a, use of other files,
 *   animate or set), in the SVG namespace or none;
 * - attributes without `on*`, `href`/`xlink:href`/`src` only as `#id`, namespaces only SVG and XLink, and every other
 *   value (entities decoded) naming nothing outside the drawing: `url(#id)` only, no `@import`, `data:`, script
 *   schemes, `expression`, `image-set`, backslash, `<` or `&`.
 *
 * The lists follow staticInlineSvg() of Histeon Publish (packages/cms/src/css-inline-svg.ts), which writes these URLs
 * in exactly the accepted form, so a drawing it keeps passes here.
 */
final class InlineSvg {

	/** Decoded drawing: as Publish's MAX_INLINE_SVG_BYTES, far above the drawings of real sheets (WordPress: under 1.5 kB). */
	public const MAX_BYTES = 65536;

	/** The URL as written: every byte percent-encoded is three characters, plus the type. */
	public const MAX_URL = 3 * self::MAX_BYTES + 64;

	/** Drawings kept in one sheet; past this a url() is removed as any other. WordPress's own sheets have at most 23. */
	public const MAX_PER_SHEET = 64;

	/** Elements of one drawing, as Publish. */
	public const MAX_ELEMENTS = 2000;

	private const SVG_NS   = 'http://www.w3.org/2000/svg';
	private const XLINK_NS = 'http://www.w3.org/1999/xlink';
	private const XML_NS   = 'http://www.w3.org/XML/1998/namespace';
	private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

	/** Static shapes, their paint servers, clips, masks, filters and text (lower case). Nothing that runs, links or loads. */
	private const ELEMENTS = array(
		'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'filter', 'feblend',
		'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
		'fedistantlight', 'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'femerge',
		'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
	);

	/** The types a browser reads as SVG that are accepted, spaces around `;` removed and lower case. */
	private const TYPES = array(
		'image/svg+xml',
		'image/svg+xml;charset=utf-8',
		'image/svg+xml;charset=utf8',
		'image/svg+xml;base64',
		'image/svg+xml;charset=utf-8;base64',
		'image/svg+xml;charset=utf8;base64',
	);

	/**
	 * True when the value of a CSS url() is a data URL of an inert SVG drawing in the accepted form. Anything else,
	 * including any data URL of another type, is false.
	 */
	public static function inert_data_url( string $url ): bool {
		// As the URL parser reads it: leading and trailing controls and spaces go. Inside, the closed set of characters
		// below leaves no tab or newline to drop.
		$url = trim( $url, "\x00..\x20" );
		if ( strlen( $url ) > self::MAX_URL || 1 !== preg_match( '#^[dD][aA][tT][aA]:([A-Za-z0-9/+.;= -]*),([A-Za-z0-9%._~!$&*+,;=:@/?-]*)$#D', $url, $m ) ) {
			return false;
		}
		$type = strtolower( (string) preg_replace( '/ *; */', ';', trim( $m[1], ' ' ) ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		// A data URL body is percent-decoded first, base64 after (fetch spec, data: URL processor).
		$body = rawurldecode( $m[2] );
		if ( str_ends_with( $type, ';base64' ) ) {
			if ( 1 !== preg_match( '#^[A-Za-z0-9+/]*={0,2}$#D', $body ) ) {
				return false;
			}
			$body = base64_decode( $body, true );
			if ( false === $body ) {
				return false;
			}
		}
		return self::inert_svg( $body );
	}

	/** True when the text is an inert SVG document (see the class comment). */
	public static function inert_svg( string $svg ): bool {
		if ( '' === $svg || strlen( $svg ) > self::MAX_BYTES || ! mb_check_encoding( $svg, 'UTF-8' ) ) {
			return false;
		}
		$text = ltrim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $svg ) );
		// Read as an HTML parser and an XML parser read it alike: no markup declaration, comment, CDATA or processing
		// instruction (they are what the two read differently), no control character, and nothing a text check would
		// already call active.
		if ( 1 !== preg_match( '/^<[sS][vV][gG][ \t\n\x0B\f\r>\/]/', $text ) || 1 === preg_match( '/<[!?]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text ) || ! self::static_text( $text, true ) ) {
			return false;
		}
		if ( ! class_exists( \XMLReader::class ) ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		try {
			$reader = new \XMLReader();
			// No network, no DTD loading, no entity substitution, no huge documents (all off by default; NONET explicit).
			if ( ! $reader->XML( $text, 'UTF-8', LIBXML_NONET ) ) {
				return false;
			}
			$ok = self::walk( $reader );
			$reader->close();
			return $ok && array() === libxml_get_errors();
		} catch ( \Throwable ) {
			return false;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	private static function walk( \XMLReader $reader ): bool {
		$elements = 0;
		$root     = true;
		while ( true ) {
			$read = @$reader->read(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- errors are collected by libxml and refuse the drawing.
			if ( ! $read ) {
				return 0 < $elements;
			}
			switch ( $reader->nodeType ) {
				case \XMLReader::ELEMENT:
					if ( ++$elements > self::MAX_ELEMENTS || ! self::element( $reader, $root ) ) {
						return false;
					}
					$root = false;
					break;
				case \XMLReader::END_ELEMENT:
				case \XMLReader::WHITESPACE:
				case \XMLReader::SIGNIFICANT_WHITESPACE:
					break;
				case \XMLReader::TEXT:
					if ( ! self::static_text( (string) $reader->value, false ) ) {
						return false;
					}
					break;
				default:
					// CDATA, comments, processing instructions, entity references, doctypes: refused.
					return false;
			}
		}
	}

	private static function element( \XMLReader $reader, bool $root ): bool {
		$name = strtolower( (string) $reader->localName );
		$ns   = (string) $reader->namespaceURI;
		if ( ( '' !== $ns && self::SVG_NS !== $ns ) || ! in_array( $name, self::ELEMENTS, true ) || ( $root && 'svg' !== $name ) ) {
			return false;
		}
		if ( ! $reader->hasAttributes ) {
			return true;
		}
		$ok = true;
		for ( $more = $reader->moveToFirstAttribute(); $more && $ok; $more = $reader->moveToNextAttribute() ) {
			$ok = self::attribute( (string) $reader->namespaceURI, (string) $reader->localName, (string) $reader->name, (string) $reader->value );
		}
		$reader->moveToElement();
		return $ok;
	}

	private static function attribute( string $ns, string $local, string $qualified, string $value ): bool {
		$lower = strtolower( $local );
		if ( self::XMLNS_NS === $ns ) {
			// Only the two declarations a drawing needs, with their own namespaces.
			return ( 'xmlns' === $qualified && self::SVG_NS === $value ) || ( 'xmlns:xlink' === $qualified && self::XLINK_NS === $value );
		}
		if ( self::XLINK_NS === $ns ) {
			return 'href' === $local && self::internal( $value );
		}
		if ( self::XML_NS === $ns ) {
			return in_array( $local, array( 'space', 'lang' ), true ) && self::static_value( $value );
		}
		if ( '' !== $ns || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9-]*$/D', $local ) || str_starts_with( $lower, 'on' ) ) {
			return false;
		}
		if ( 'href' === $lower || 'src' === $lower ) {
			return self::internal( $value );
		}
		return self::static_value( $value );
	}

	/** A reference to an element of the same drawing. */
	private static function internal( string $value ): bool {
		return 1 === preg_match( '/^#[A-Za-z][A-Za-z0-9_-]*$/D', $value );
	}

	/** An attribute value naming nothing outside the drawing: as staticValue() of Publish, plus a few CSS loaders. */
	private static function static_value( string $value ): bool {
		if ( 1 === preg_match( '/[\x00\\\\<&]/', $value ) ) {
			return false;
		}
		return self::static_text( $value, false );
	}

	/**
	 * Text naming nothing outside the drawing: no script scheme, nested data URL, import or CSS loader, and every
	 * `url(` a `url(#id)`. On the whole document ($markup) the url() check skips nothing; on a text node or a value
	 * it reads the entity-decoded text.
	 */
	private static function static_text( string $text, bool $markup ): bool {
		if ( str_contains( $text, "\0" ) ) {
			return false;
		}
		$flat = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', $text ) );
		foreach ( array( 'javascript:', 'vbscript:', 'data:', '@import', 'expression(', 'image-set(', 'cross-fade(', 'element(', 'image(', 'src(', '-moz-binding', 'behavior:' ) as $needle ) {
			if ( str_contains( $flat, $needle ) ) {
				return false;
			}
		}
		if ( ! $markup && str_contains( $text, '<' ) ) {
			return false;
		}
		preg_match_all( '/[uU][rR][lL][ \t\n\x0B\f\r]*\(([^)]*)\)?/', $text, $calls );
		foreach ( $calls[1] as $argument ) {
			if ( 1 !== preg_match( '/^[ \t\n\x0B\f\r]*(["\']?)#[A-Za-z][A-Za-z0-9_-]*\1[ \t\n\x0B\f\r]*$/D', $argument ) ) {
				return false;
			}
		}
		return true;
	}
}
