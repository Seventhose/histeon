<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * The site's own variables (docs/spec/design-system.md, «Variabili del sito»): `--xb-site-<name>`, added from the
 * Design system next to the Histeon CSS tokens. The prefix keeps them apart from every token Histeon CSS has or will
 * have. They live in the settings document (key `siteTokens`, versions, releases, restore) and are printed right
 * after the token overrides. Values follow the same rule as the overrides: plain CSS values only.
 */
final class SiteTokens {

	public const OPTION = 'xb_site_tokens';
	public const PREFIX = 'xb-site-';
	public const MAX    = 200;

	/** Why a bare name (without `--xb-site-`) cannot be used (null: it can). */
	public static function name_problem( string $name ): ?string {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $name ) && ! str_ends_with( $name, '-' ) && ! str_contains( $name, '--' )
			? null
			: __( 'Nome: da 1 a 40 caratteri fra lettere minuscole, cifre e trattini singoli.', 'xb' );
	}

	/**
	 * @param mixed $raw `{ "brand-glow": "oklch(…)" }`, names without the prefix.
	 * @return array<string, string>
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function validate( mixed $raw ): array {
		if ( null === $raw || array() === $raw ) {
			return array();
		}
		if ( ! is_array( $raw ) || count( $raw ) > self::MAX ) {
			/* translators: %d: maximum number of variables */
			throw new \InvalidArgumentException( sprintf( __( 'Variabili del sito: serve un oggetto nome → valore, al massimo %d.', 'xb' ), self::MAX ) );
		}
		$out = array();
		foreach ( $raw as $name => $value ) {
			$name    = (string) $name;
			$problem = self::name_problem( $name ) ?? ( is_string( $value ) ? TokenOverrides::problem( $value ) : __( 'serve un testo.', 'xb' ) );
			if ( null !== $problem ) {
				throw new \InvalidArgumentException( '--' . self::PREFIX . $name . ': ' . $problem );
			}
			$out[ $name ] = trim( (string) $value );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The variables a page sees: dev in a signed preview (and the builder canvas), live otherwise.
	 *
	 * @return array<string, string>
	 */
	public static function current( bool $dev = false ): array {
		$payload = $dev || Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored  = is_array( $payload ) && array_key_exists( 'siteTokens', $payload ) ? $payload['siteTokens'] : get_option( self::OPTION, array() );
		try {
			return self::validate( $stored );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/** The declarations `--xb-site-x:…;`, '' without variables. Values were validated: nothing can close the rule. */
	public static function declarations( bool $dev = false ): string {
		$rules = '';
		foreach ( self::current( $dev ) as $name => $value ) {
			$rules .= '--' . self::PREFIX . $name . ':' . $value . ';';
		}
		return $rules;
	}
}
