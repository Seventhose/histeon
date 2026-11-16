<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Plugin;

/**
 * Tabs, toggles, reveal on scroll, videos and maps loaded on click (docs/spec/interactions.md): Histeon's own small script, loaded only on
 * pages whose rendered elements carry a data-xb-* behaviour attribute. Without it everything stays usable.
 */
final class Behaviors {

	public const ATTRIBUTES = array( 'data-xb-tabs', 'data-xb-toggle', 'data-xb-reveal', 'data-xb-video', 'data-xb-map' );

	/** @param array<mixed> $attributes An element's attributes, as stored. */
	public static function uses( array $attributes ): bool {
		foreach ( self::ATTRIBUTES as $name ) {
			if ( array_key_exists( $name, $attributes ) ) {
				return true;
			}
		}
		// The «Video» block of an article: a link the script turns into the player on a click.
		$class = $attributes['class'] ?? '';
		return is_string( $class ) && 1 === preg_match( '/(^|\s)xb-art-video(\s|$)/', $class );
	}

	public static function enqueue(): void {
		if ( did_action( 'wp_footer' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_script_is( 'xb-behaviors', 'enqueued' ) ) {
			return;
		}
		$base = plugin_dir_url( XB_FILE ) . 'assets/front/';
		wp_enqueue_script( 'xb-behaviors', $base . 'behaviors.js', array(), Plugin::asset_version( 'assets/front/behaviors.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_style( 'xb-behaviors', $base . 'behaviors.css', array(), Plugin::asset_version( 'assets/front/behaviors.css' ) );
	}
}
