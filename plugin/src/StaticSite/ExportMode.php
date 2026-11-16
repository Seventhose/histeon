<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/**
 * A request made by the exporter (loopback, anonymous). It carries `X-XB-Export: <expiry>.<hmac>`;
 * WordPress answers with the same value in `X-XB-Export-Render`, proving the page was rendered now
 * and not served from a cache. In export mode, everything a static copy must not contain is removed:
 * REST and XML-RPC discovery, shortlinks, oEmbed discovery, the generator tag, emoji scripts.
 */
final class ExportMode {

	public const HEADER = 'X-XB-Export';
	public const ECHO   = 'X-XB-Export-Render';

	private static bool $active = false;

	public static function boot(): void {
		add_action( 'init', array( self::class, 'detect' ), 0 );
	}

	public static function token(): string {
		$expiry = time() + 900;
		return $expiry . '.' . hash_hmac( 'sha256', 'xb-export|' . $expiry, wp_salt( 'auth' ) );
	}

	public static function valid( string $token ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() || (int) $parts[0] > time() + 1800 ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', 'xb-export|' . $parts[0], wp_salt( 'auth' ) ), $parts[1] );
	}

	public static function active(): bool {
		return self::$active;
	}

	public static function detect(): void {
		$token = isset( $_SERVER['HTTP_X_XB_EXPORT'] ) && is_string( $_SERVER['HTTP_X_XB_EXPORT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_XB_EXPORT'] ) ) : '';
		if ( '' === $token || ! self::valid( $token ) || is_user_logged_in() ) {
			return;
		}
		self::$active = true;
		add_action(
			'send_headers',
			static function () use ( $token ): void {
				header( self::ECHO . ': ' . $token );
				nocache_headers();
			}
		);
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		add_filter( 'show_admin_bar', '__return_false' );
	}
}
