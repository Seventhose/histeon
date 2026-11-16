<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * The site's own CSS recipes (docs/spec/design-system.md, «Ricette del sito»): written or changed in the Design
 * system, used with `@name` in the CSS panel and in the Recipes panel. Same name as a Histeon CSS recipe: the site's
 * wins, and removing it brings Histeon's back. They live in the settings document (key `recipes`), so they have
 * versions and restore. A recipe is only CSS declarations that the editor writes into a selector, which the styles
 * pipeline checks again; here nothing that loads or runs anything is accepted.
 */
final class SiteRecipes {

	public const OPTION    = 'xb_site_recipes';
	public const MAX       = 200;
	public const MAX_BYTES = 4096;

	/** Why a recipe cannot be saved (null: it can). */
	public static function problem( string $name, mixed $css ): ?string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $name ) || str_ends_with( $name, '-' ) || str_contains( $name, '--' ) ) {
			return __( 'Nome: da 1 a 40 caratteri fra lettere minuscole, cifre e trattini singoli.', 'xb' );
		}
		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return __( 'La ricetta è vuota.', 'xb' );
		}
		if ( strlen( $css ) > self::MAX_BYTES ) {
			return __( 'La ricetta supera 4 KB.', 'xb' );
		}
		// Read through strtolower(), ASCII letters alone: the `i` flag and `\s` of PCRE follow the PHP locale, and in a
		// Turkish one `@IMPORT` passed (T31, P1).
		if ( str_contains( $css, '<' ) || 1 === preg_match( '/url[ \t\n\x0B\f\r]*\(|@import|expression[ \t\n\x0B\f\r]*\(|javascript:|behavior[ \t\n\x0B\f\r]*:|-moz-binding/', strtolower( $css ) ) ) {
			return __( 'Non sono ammessi markup, url(), @import, expression(), behavior e -moz-binding.', 'xb' );
		}
		$depth = 0;
		foreach ( str_split( $css ) as $c ) {
			$depth += '{' === $c ? 1 : ( '}' === $c ? -1 : 0 );
			if ( $depth < 0 ) {
				break;
			}
		}
		return 0 === $depth ? null : __( 'Graffe non bilanciate.', 'xb' );
	}

	/**
	 * @param mixed $raw `{ "card-hero": "padding: …;" }`.
	 * @return array<string, string>
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function validate( mixed $raw ): array {
		if ( null === $raw || array() === $raw ) {
			return array();
		}
		if ( ! is_array( $raw ) || count( $raw ) > self::MAX ) {
			/* translators: %d: maximum number of recipes */
			throw new \InvalidArgumentException( sprintf( __( 'Ricette del sito: serve un oggetto nome → CSS, al massimo %d.', 'xb' ), self::MAX ) );
		}
		$out = array();
		foreach ( $raw as $name => $css ) {
			$name    = (string) $name;
			$problem = self::problem( $name, $css );
			if ( null !== $problem ) {
				throw new \InvalidArgumentException( '@' . $name . ': ' . $problem );
			}
			$out[ $name ] = trim( (string) $css );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The recipes in dev (the builder is the only place they are used).
	 *
	 * @return array<string, string>
	 */
	public static function current(): array {
		$copy = \XB\Plugin::instance()->versioning->working_copy( \XB\Versioning\Documents::SETTINGS );
		try {
			return self::validate( $copy['payload']['recipes'] ?? get_option( self::OPTION, array() ) );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}
}
