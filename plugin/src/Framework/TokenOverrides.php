<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * The site's own values for the Histeon Tokens (docs/spec/design-system.md): `--xb-primary: oklch(…)`, a spacing
 * step, a radius. They live in the settings document (versions, releases, restore) and are printed after
 * xb-tokens.css. Only token names the stylesheet defines, only plain CSS values: no rule, no URL, no at-rule.
 */
final class TokenOverrides {

	public const OPTION    = 'xb_token_overrides';
	private const MAX_VALUE = 200;

	/** @return list<string> Token names (without `--`) defined by xb-tokens.css. */
	public static function names(): array {
		$names = array();
		foreach ( ( new XbTokensProvider() )->variables() as $group ) {
			foreach ( $group as $name ) {
				$names[] = (string) $name;
			}
		}
		return $names;
	}

	/** Why a value is not a plain CSS value (null: it is). */
	public static function problem( string $value ): ?string {
		if ( '' === trim( $value ) ) {
			return __( 'Il valore è vuoto.', 'xb' );
		}
		if ( strlen( $value ) > self::MAX_VALUE ) {
			return __( 'Il valore è troppo lungo.', 'xb' );
		}
		// Read through strtolower(), ASCII letters alone: the `i` flag and `\s` of PCRE follow the PHP locale (T31, P1).
		if ( 1 === preg_match( '/[;{}<>"\'\\\\@!]|\/\*|url[ \t\n\x0B\f\r]*\(|expression[ \t\n\x0B\f\r]*\(|javascript:/', strtolower( $value ) ) ) {
			return __( 'Il valore contiene caratteri non ammessi (; { } < > virgolette @ ! url()).', 'xb' );
		}
		if ( 1 !== preg_match( '/^[\w\s.,%()+\-*\/#]+$/u', $value ) ) {
			return __( 'Solo valori CSS semplici: numeri, unità, colori, calc(), clamp(), var().', 'xb' );
		}
		$depth = 0;
		foreach ( str_split( $value ) as $c ) {
			$depth += '(' === $c ? 1 : ( ')' === $c ? -1 : 0 );
			if ( $depth < 0 ) {
				break;
			}
		}
		return 0 === $depth ? null : __( 'Parentesi non bilanciate.', 'xb' );
	}

	/**
	 * @param mixed $raw `{ "xb-primary": "oklch(…)" }`.
	 * @return array<string, string>
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function validate( mixed $raw ): array {
		if ( null === $raw || array() === $raw ) {
			return array();
		}
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( __( 'Token: serve un oggetto nome → valore.', 'xb' ) );
		}
		$known = array_flip( self::names() );
		$out   = array();
		foreach ( $raw as $name => $value ) {
			$name = ltrim( (string) $name, '-' );
			if ( ! isset( $known[ $name ] ) ) {
				/* translators: %s: token name */
				throw new \InvalidArgumentException( sprintf( __( 'Token sconosciuto: --%s', 'xb' ), $name ) );
			}
			if ( ! is_string( $value ) ) {
				/* translators: %s: token name */
				throw new \InvalidArgumentException( sprintf( __( 'Token --%s: serve un testo.', 'xb' ), $name ) );
			}
			$problem = self::problem( $value );
			if ( null !== $problem ) {
				throw new \InvalidArgumentException( '--' . $name . ': ' . $problem );
			}
			$out[ $name ] = trim( $value );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The overrides a page sees: dev in a signed preview (and the builder canvas), live otherwise.
	 *
	 * @return array<string, string>
	 */
	public static function current( bool $dev = false ): array {
		$payload = $dev || Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored  = is_array( $payload ) && array_key_exists( 'tokens', $payload ) ? $payload['tokens'] : get_option( self::OPTION, array() );
		try {
			return self::validate( $stored );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/**
	 * `:root{--xb-primary:…}` with the site's own variables after the overrides (SiteTokens), or '' without either.
	 * Values were validated: nothing here can close the rule.
	 */
	public static function css( bool $dev = false ): string {
		$rules = '';
		foreach ( self::current( $dev ) as $name => $value ) {
			$rules .= '--' . $name . ':' . $value . ';';
		}
		$rules .= SiteTokens::declarations( $dev );
		return '' === $rules ? '' : ':root{' . $rules . '}';
	}
}
