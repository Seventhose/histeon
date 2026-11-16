<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/**
 * Security headers of the static site (PIANO.md §8.12): a closed list, so a site may change their values
 * (`xb_static_security_headers`) but never add other headers. They reach every response: the `_headers` file
 * of the static ZIP (Cloudflare Pages, Netlify), the assets config of the Workers deploy, and the Worker's own
 * responses (forms).
 */
final class SecurityHeaders {

	/** Header names, and their default values. A strict CSP would break the pages' inline scripts: framing only. */
	private const DEFAULTS = array(
		'X-Content-Type-Options'  => 'nosniff',
		'X-Frame-Options'         => 'SAMEORIGIN',
		'Referrer-Policy'         => 'strict-origin-when-cross-origin',
		'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), payment=()',
		'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
	);

	/** File name in the static bundle: read by the hosts that support it, never served as a page. */
	public const FILE = '_headers';

	/**
	 * The headers, after the filter: only known names, values on one line of printable text (or left out
	 * with an empty value).
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$values = apply_filters( 'xb_static_security_headers', self::DEFAULTS );
		$out    = array();
		foreach ( self::DEFAULTS as $name => $default ) {
			$value = is_array( $values ) && array_key_exists( $name, $values ) ? $values[ $name ] : $default;
			if ( is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 1000 && 1 === preg_match( '/^[\x20-\x7e]+$/', $value ) ) {
				$out[ $name ] = trim( $value );
			}
		}
		return $out;
	}

	/** The `_headers` file: every path gets them. */
	public static function file(): string {
		$lines = array( '/*' );
		foreach ( self::all() as $name => $value ) {
			$lines[] = '  ' . $name . ': ' . $value;
		}
		return implode( "\n", $lines ) . "\n";
	}
}
