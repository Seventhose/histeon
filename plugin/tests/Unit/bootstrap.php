<?php
/**
 * Unit tests run without WordPress: the gettext functions the pure code calls return the Italian source text.
 */

declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );
		return 1 === $number ? $single : $plural;
	}
}
