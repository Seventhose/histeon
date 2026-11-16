<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

/**
 * Languages (docs/spec/i18n.md). Histeon is written in Italian; English comes from `languages/xb-en_US.mo` for the
 * PHP side and from `assets/builder/strings-en_US.json` for the builder. Every language without a file of its own
 * gets English: a German or French user reads English rather than Italian.
 */
final class I18n {

	public const DOMAIN = 'xb';
	/** The fallback for every language that is not Italian. */
	public const FALLBACK = 'en_US';

	/** @var array<string, string>|null */
	private static ?array $editor = null;

	public static function register(): void {
		add_action( 'init', array( self::class, 'load' ), 0 );
		add_filter( 'load_translation_file', array( self::class, 'fallback' ), 10, 3 );
		add_filter( 'load_script_translation_file', array( self::class, 'script_fallback' ), 10, 3 );
	}

	/** A classic script of the plugin that uses wp.i18n with the `xb` domain. */
	public static function script( string $handle ): void {
		wp_set_script_translations( $handle, self::DOMAIN, dirname( XB_FILE ) . '/languages' );
	}

	/** The same fallback for the scripts' JSON files (`xb-<locale>-<md5>.json`). */
	public static function script_fallback( string|false $file, string $handle, string $domain ): string|false {
		if ( false === $file || self::DOMAIN !== $domain || is_readable( $file ) || 1 !== preg_match( '/-([a-z]{2,3}(?:_[A-Za-z0-9_]+)?)-[0-9a-f]{32}\.json$/', $file, $m ) || ! self::needs_fallback( $m[1] ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $m[1], '/' ) . '-([0-9a-f]{32}\.json)$/', '-' . self::FALLBACK . '-$1', $file );
		return is_readable( $english ) ? $english : $file;
	}

	public static function load(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( XB_FILE ) ) . '/languages' );
	}

	/** Italian is the source; a language without its own file gets the English one. */
	public static function fallback( string $file, string $domain, string $locale = '' ): string {
		if ( self::DOMAIN !== $domain || ! self::needs_fallback( $locale ) || is_readable( $file ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $locale, '/' ) . '(\.l10n\.php|\.mo)$/', '-' . self::FALLBACK . '$1', $file );
		return $english !== $file && is_readable( $english ) ? $english : $file;
	}

	private static function needs_fallback( string $locale ): bool {
		return '' !== $locale && ! str_starts_with( $locale, 'it' ) && self::FALLBACK !== $locale;
	}

	/** Whether this user reads Histeon in English (any language but Italian). */
	public static function english( ?string $locale = null ): bool {
		return ! str_starts_with( $locale ?? determine_locale(), 'it' );
	}

	/**
	 * The builder's own catalog (Italian → English), for a user who reads English; empty for Italian.
	 *
	 * @return array<string, string>
	 */
	public static function editor_strings(): array {
		if ( ! self::english() ) {
			return array();
		}
		if ( null === self::$editor ) {
			$file         = dirname( XB_FILE ) . '/assets/builder/strings-' . self::FALLBACK . '.json';
			$data         = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the plugin.
			self::$editor = is_array( $data ) ? array_filter( $data, static fn ( mixed $v, mixed $k ): bool => is_string( $k ) && is_string( $v ), ARRAY_FILTER_USE_BOTH ) : array();
		}
		return self::$editor;
	}
}
