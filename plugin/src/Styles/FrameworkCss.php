<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * The framework stylesheet on demand: its <link> becomes an inline <style> with only the rules the page uses.
 *
 * The file is parsed once per version (path, size, mtime) and cached as JSON in uploads/xb-cache/ (data, never
 * code). Relative url()/@import references are made absolute first, since inline CSS resolves them against the
 * page, not the stylesheet. Anything unexpected — file outside wp-content, not .css, too big, unreadable —
 * leaves the original <link> untouched.
 */
final class FrameworkCss {

	private const MAX_FILE = 5 * 1024 * 1024;

	/** @var array<string, array<mixed>> */
	private static array $memo = array();

	/** @param list<string> $urls */
	public static function inline( string $html, array $urls, Presence $page ): string {
		foreach ( $urls as $n => $url ) {
			$file = self::file_for( $url );
			if ( null === $file ) {
				continue;
			}
			$link = self::find_link( $html, $url );
			if ( null === $link ) {
				continue;
			}
			$css  = CssCompiler::sanitize( CssRules::select( self::rules( $file, $url ), $page ) );
			$id   = 'xb-framework-' . (int) $n;
			$html = substr_replace( $html, '<style id="' . $id . '">' . $css . '</style>', $link[0], $link[1] );
		}
		return $html;
	}

	/**
	 * The stylesheet parsed into rules (cached).
	 *
	 * @return array<mixed>
	 */
	public static function rules( string $file, string $url ): array {
		$key = md5( $file . '|' . (string) filesize( $file ) . '|' . (string) filemtime( $file ) . '|' . $url . '|' . CssRules::VERSION );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$cached = RulesCache::get( 'fw-' . md5( $file ), $key );
		if ( null !== $cached ) {
			return self::$memo[ $key ] = $cached;
		}
		$css   = self::absolutize( (string) file_get_contents( $file ), $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$rules = CssRules::parse( $css );
		RulesCache::put( 'fw-' . md5( $file ), $key, $rules );
		return self::$memo[ $key ] = $rules;
	}

	/** Local file behind a stylesheet URL: a readable .css inside wp-content, not too big; otherwise null. */
	public static function file_for( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $path || ! str_starts_with( $path, trailingslashit( $base ) ) || ( null !== $host && wp_parse_url( content_url(), PHP_URL_HOST ) !== $host ) ) {
			return null;
		}
		$real = realpath( WP_CONTENT_DIR . '/' . rawurldecode( substr( $path, strlen( trailingslashit( $base ) ) ) ) );
		$root = realpath( WP_CONTENT_DIR );
		if ( false === $real || false === $root || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) || ! str_ends_with( strtolower( $real ), '.css' ) ) {
			return null;
		}
		return is_readable( $real ) && filesize( $real ) <= self::MAX_FILE ? $real : null;
	}

	/**
	 * Offset and length of the <link rel="stylesheet"> whose href points at the same path, or null.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function find_link( string $html, string $url ): ?array {
		$want = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match_all( '/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		foreach ( $matches[0] as [ $tag, $offset ] ) {
			$p = new \WP_HTML_Tag_Processor( $tag );
			if ( ! $p->next_tag( array( 'tag_name' => 'link' ) ) ) {
				continue;
			}
			$rel  = strtolower( (string) $p->get_attribute( 'rel' ) );
			$href = $p->get_attribute( 'href' );
			if ( is_string( $href ) && in_array( 'stylesheet', preg_split( '/\s+/', $rel ) ?: array(), true ) && (string) wp_parse_url( $href, PHP_URL_PATH ) === $want && in_array( $p->get_attribute( 'media' ), array( null, 'all', '' ), true ) ) {
				return array( (int) $offset, strlen( $tag ) );
			}
		}
		return null;
	}

	/** Relative url() and @import references resolved against the stylesheet URL. */
	public static function absolutize( string $css, string $url ): string {
		$base = (string) strtok( $url, '?#' );
		$fix  = static function ( string $ref ) use ( $base ): string {
			$ref = trim( $ref );
			if ( '' === $ref || str_starts_with( $ref, 'data:' ) || str_starts_with( $ref, '#' ) || str_starts_with( $ref, '/' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) ) {
				return $ref;
			}
			return \WP_Http::make_absolute_url( $ref, $base );
		};
		$css = (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]*)\1\s*\)/i',
			static fn ( array $m ): string => 'url(' . $m[1] . $fix( $m[2] ) . $m[1] . ')',
			$css
		);
		return (string) preg_replace_callback(
			'/@import\s+(["\'])([^"\']+)\1/i',
			static fn ( array $m ): string => '@import ' . $m[1] . $fix( $m[2] ) . $m[1],
			$css
		);
	}
}
