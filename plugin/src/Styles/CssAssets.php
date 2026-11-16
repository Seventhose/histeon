<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * Validates and resolves the local media references stored with Histeon CSS rules.
 * The source CSS and its relative references remain unchanged in the versioned profile;
 * only the CSS sent to a browser receives WordPress attachment URLs.
 */
final class CssAssets {

	/** @return list<array{value: string, start: int, end: int}> */
	public static function urls( string $css ): array {
		$urls   = array();
		$length = strlen( $css );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( '/' === $css[ $i ] && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}
			if ( '"' === $css[ $i ] || "'" === $css[ $i ] ) {
				$i = self::string_end( $css, $i );
				continue;
			}
			if ( 0 !== strncasecmp( substr( $css, $i, 3 ), 'url', 3 ) || preg_match( '/[A-Za-z0-9_-]/', $css[ $i - 1 ] ?? '' ) || preg_match( '/[A-Za-z0-9_-]/', $css[ $i + 3 ] ?? '' ) ) {
				continue;
			}
			$open = self::space( $css, $i + 3 );
			if ( '(' !== ( $css[ $open ] ?? '' ) ) {
				continue;
			}
			$cursor = self::space( $css, $open + 1 );
			$start  = $i;
			$value  = '';
			$quote  = $css[ $cursor ] ?? '';
			if ( '"' === $quote || "'" === $quote ) {
				$end = self::string_end( $css, $cursor );
				$value = substr( $css, $cursor + 1, $end - $cursor - 1 );
				$close = self::space( $css, $end + 1 );
				if ( ')' !== ( $css[ $close ] ?? '' ) ) {
					continue;
				}
				$i = $close;
			} else {
				$escaped = false;
				for ( ; $cursor < $length; ++$cursor ) {
					$char = $css[ $cursor ];
					if ( $escaped ) {
						$value  .= $char;
						$escaped = false;
					} elseif ( '\\' === $char ) {
						$escaped = true;
					} elseif ( ')' === $char ) {
						break;
					} else {
						$value .= $char;
					}
				}
				if ( $cursor >= $length ) {
					continue;
				}
				$i = $cursor;
			}
			$value = trim( $value );
			if ( '' !== $value ) {
				$urls[] = array( 'value' => $value, 'start' => $start, 'end' => $i + 1 );
			}
		}
		return $urls;
	}

	/**
	 * @param mixed               $raw_mappings
	 * @param array<string, true> $asset_ids
	 * @param array<string, string> $media_types
	 * @return list<array{reference: string, assetId: string}>
	 */
	public static function validate_profile( string $css, mixed $raw_mappings, array $asset_ids, array $media_types ): array {
		if ( self::has_local_import( $css ) ) {
			throw new \DomainException( 'Local CSS imports cannot be transferred with the Histeon page profile.' );
		}
		if ( ! is_array( $raw_mappings ) || ! array_is_list( $raw_mappings ) || count( $raw_mappings ) > 500 ) {
			throw new \DomainException( 'Invalid CSS media mapping list.' );
		}
		$mappings = array();
		foreach ( $raw_mappings as $mapping ) {
			if ( ! is_array( $mapping ) || ! is_string( $mapping['reference'] ?? null ) || '' === trim( $mapping['reference'] ) || strlen( $mapping['reference'] ) > 2048 || ! is_string( $mapping['assetId'] ?? null ) || ! self::valid_uuid( $mapping['assetId'] ) ) {
				throw new \DomainException( 'Invalid CSS media mapping.' );
			}
			self::assert_keys( $mapping, array( 'reference', 'assetId' ) );
			if ( isset( $mappings[ $mapping['reference'] ] ) || ! isset( $asset_ids[ $mapping['assetId'] ] ) ) {
				throw new \DomainException( 'CSS media mapping is duplicated or points to an asset outside the transfer profile.' );
			}
			if ( ! in_array( $media_types[ $mapping['assetId'] ] ?? '', array( 'image/avif', 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'font/otf', 'font/ttf', 'font/woff', 'font/woff2' ), true ) ) {
				throw new \DomainException( 'The CSS profile references an unsupported image or font format.' );
			}
			$mappings[ $mapping['reference'] ] = $mapping['assetId'];
		}
		foreach ( self::urls( $css ) as $url ) {
			if ( ! self::external_reference( $url['value'] ) && ! isset( $mappings[ $url['value'] ] ) ) {
				throw new \DomainException( 'Every CSS url() must be linked to a media file before transfer.' );
			}
		}
		return array_map( static fn ( string $reference, string $asset_id ): array => array( 'reference' => $reference, 'assetId' => $asset_id ), array_keys( $mappings ), array_values( $mappings ) );
	}

	/**
	 * Resolve only references explicitly linked to stable Histeon media IDs: receives the stored CSS, its mappings and
	 * what gives the address of a media, gives back the CSS for the browser.
	 *
	 * The reference is the one urls() reads (the reading Histeon Publish wrote the mapping with), but an address is
	 * written only into a url() that the filter of restricted CSS reads as one closed address in the very same place
	 * (Documents::closed_urls()): where the two readings do not agree the text stays as written. And what is written is
	 * `url("…")` with nothing in it that CSS reads (url_text(), inert_text()), or `url()` for a media that is gone or
	 * whose address cannot be written that way. So the CSS printed with its media is the CSS as stored with those url()
	 * exchanged for `url("…")` or `url()`, and which url() they are does not depend on the media found.
	 *
	 * This does not say that the browser reads each of those url() as a closed address too. It can be inside a string
	 * there (V1 of the fifth review: the browser does not end every string where the filter does, and CssCompiler trims
	 * a new line that ended one). Then `url()` is a piece of that string, while the first quote of `url("…")` ends it:
	 * the declaration that holds the url() is read otherwise with the media found than with the media gone. The address
	 * and the suffix are read as CSS there, which is why both are written inert, and the second quote opens a string
	 * again: what comes after the url() is read in the same state in the two forms (threat model T31, T1).
	 */
	public static function rewrite( string $css, mixed $raw_mappings, callable $asset_url ): string {
		if ( ! is_array( $raw_mappings ) ) {
			return $css;
		}
		$mappings = array();
		foreach ( $raw_mappings as $mapping ) {
			if ( is_array( $mapping ) && is_string( $mapping['reference'] ?? null ) && is_string( $mapping['assetId'] ?? null ) && self::valid_uuid( $mapping['assetId'] ) ) {
				$mappings[ $mapping['reference'] ] = $mapping['assetId'];
			}
		}
		if ( array() === $mappings ) {
			return $css;
		}
		$source = $css;
		$closed = null;
		foreach ( array_reverse( self::urls( $source ) ) as $url ) {
			$asset_id = $mappings[ $url['value'] ] ?? null;
			if ( null === $asset_id ) {
				continue;
			}
			// Read once, on the CSS as stored, and only for a sheet that has a linked reference.
			$closed ??= \XB\Versioning\Documents::closed_urls( $source );
			if ( ( $closed[ $url['start'] ] ?? null ) !== $url['end'] ) {
				continue;
			}
			$base_url = $asset_url( $asset_id );
			$address  = is_string( $base_url ) && '' !== $base_url ? self::url_text( $base_url ) : null;
			if ( null === $address ) {
				$replacement = 'url()';
			} else {
				$suffix_positions = array_filter( array( strpos( $url['value'], '?' ), strpos( $url['value'], '#' ) ), static fn ( int|false $position ): bool => false !== $position );
				$suffix = array() === $suffix_positions ? '' : substr( $url['value'], min( $suffix_positions ) );
				$replacement = 'url("' . $address . self::inert_text( $suffix ) . '")';
			}
			$css = substr( $css, 0, $url['start'] ) . $replacement . substr( $css, $url['end'] );
		}
		return $css;
	}

	/**
	 * The address of a media, as the media library gives it, written between the quotes of `url("…")`: receives the
	 * address, gives it back as inert_text() writes it, or null for an address that cannot be written that way.
	 *
	 * A reader of CSS can get to the `url("…")` while it is inside a double-quoted string (V1 of the fifth review of
	 * T31: the browser carries a string on over a backslash and CR LF, or over a hex escape and a new line, where the
	 * filter ends it; CssCompiler trims the new line that ended a broken string). There the first quote ends that
	 * string and the address is read as CSS. So the address holds nothing that CSS reads either: parentheses, brackets,
	 * `;`, `,`, `*`, `!`, `$` and `@` are percent-encoded like every byte outside the URL characters. A server decodes
	 * them in the path and in the query, so it is the same file. What stays is what an address cannot do without, and
	 * none of it opens or closes anything in CSS: `:` (scheme, port), `/`, `?`, `#`, `&`, `=`, `+` (a space in a query;
	 * encoded it would be a plus), `%` (what is already encoded is not encoded twice) and `- . _ ~`.
	 *
	 * Before the path an `@` ends a user name and brackets hold an IPv6 host: percent-encoded they would be part of
	 * another host name, which no browser accepts. Such an address cannot be both inert and the same: null, and
	 * rewrite() writes `url()` as for a media that is gone.
	 */
	private static function url_text( string $url ): ?string {
		if ( 1 === preg_match( '~^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?//[^/?#]*[@\[\]]~', $url ) ) {
			return null;
		}
		return self::inert_text( $url );
	}

	/**
	 * The `?…` or `#…` of a reference, which whoever writes the CSS chooses, written after the address of the media:
	 * only letters, digits and `- . _ ~ : / ? # & = + %` stay, every other byte is percent-encoded, parentheses, `*`,
	 * `;`, `,`, `@`, `!`, `$` and brackets too. No reader of CSS finds a comment, a function, the end of a declaration
	 * or of a string in it, whatever it was reading when it got there. What comes after `url("…")` is read in the same
	 * state as what comes after `url()`; the declaration that holds the url() is read otherwise in the two forms when
	 * the reader was inside a string (see url_text()), by this inert text and two quotes.
	 */
	private static function inert_text( string $suffix ): string {
		return (string) preg_replace_callback( '/[^A-Za-z0-9\-._~:\/?#&=+%]/', static fn ( array $m ): string => rawurlencode( $m[0] ), $suffix );
	}

	private static function space( string $css, int $from ): int {
		$length = strlen( $css );
		while ( $from < $length ) {
			// The bytes of ctype_space() with no locale set, written out: ctype_*() follows the PHP locale (T31, P1).
			if ( str_contains( " \t\n\x0B\f\r", $css[ $from ] ) ) {
				++$from;
			} elseif ( '/' === $css[ $from ] && '*' === ( $css[ $from + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $from + 2 );
				$from = false === $end ? $length : $end + 2;
			} else {
				break;
			}
		}
		return $from;
	}

	private static function string_end( string $css, int $from ): int {
		$quote = $css[ $from ];
		$length = strlen( $css );
		for ( $i = $from + 1; $i < $length; ++$i ) {
			if ( '\\' === $css[ $i ] ) {
				++$i;
			} elseif ( $quote === $css[ $i ] ) {
				return $i;
			}
		}
		return $length - 1;
	}

	/** @param array<string, mixed> $value
	 *  @param list<string>         $required
	 */
	private static function assert_keys( array $value, array $required ): void {
		$keys = array_keys( $value );
		sort( $keys );
		$expected = $required;
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new \DomainException( 'Unexpected fields in CSS media mapping.' );
		}
	}

	private static function valid_uuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( $value ) );
	}

	private static function has_local_import( string $css ): bool {
		$length = strlen( $css );
		$url_by_start = array();
		foreach ( self::urls( $css ) as $url ) {
			$url_by_start[ $url['start'] ] = $url;
		}
		for ( $index = 0; $index < $length; ++$index ) {
			if ( '/' === $css[ $index ] && '*' === ( $css[ $index + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $index + 2 );
				$index = false === $end ? $length : $end + 1;
				continue;
			}
			if ( '"' === $css[ $index ] || "'" === $css[ $index ] ) {
				$index = self::string_end( $css, $index );
				continue;
			}
			// ASCII letters alone, whatever the PHP locale: the `i` flag, `\b`, `\w` and `\s` of PCRE follow it (T31, P1).
			if ( '@' === $css[ $index ] && 1 === preg_match( '/^@import(?![a-z0-9_])/', strtolower( substr( $css, $index, 8 ) ) ) ) {
				$start = self::space( $css, $index + 7 );
				$first = $css[ $start ] ?? '';
				if ( '"' === $first || "'" === $first ) {
					$end = self::string_end( $css, $start );
					$value = substr( $css, $start + 1, $end - $start - 1 );
					$index = $end;
				} elseif ( 0 === strncasecmp( substr( $css, $start, 3 ), 'url', 3 ) ) {
					$resource = $url_by_start[ $start ] ?? null;
					if ( null === $resource ) {
						return true;
					}
					$value = $resource['value'];
					$index = $resource['end'] - 1;
				} else {
					return true;
				}
				if ( ! self::external_reference( trim( $value ) ) ) {
					return true;
				}
				continue;
			}
		}
		return false;
	}

	private static function external_reference( string $value ): bool {
		// Read through strtolower(), ASCII letters alone: the `i` flag of PCRE follows the PHP locale (T31, P1).
		$lower = strtolower( $value );
		return 1 === preg_match( '#^(?:https?:)?//#', $lower ) || str_starts_with( $value, '#' ) || str_starts_with( $lower, 'data:' );
	}
}
