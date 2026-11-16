<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * `url.path` and `url.query.<name>` (sanitized text). Values are escaped on output like any other value.
 * Pages that depend on the query string are served dynamically by Static Bridge.
 */
final class UrlNode implements Node {

	public function get( string $key ): mixed {
		if ( 'path' === $key ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			return is_string( $path ) ? $path : '/';
		}
		if ( 'query' === $key ) {
			return new class() implements Node {
				public function get( string $key ): mixed {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display value.
					return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : null;
				}
			};
		}
		return null;
	}
}
