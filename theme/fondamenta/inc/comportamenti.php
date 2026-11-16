<?php
/**
 * Without the builder: its interactions (tabs, menus, reveal, video, map — `data-xb-*` attributes), with the same
 * two files, loaded only on pages whose elements use them. The files are the builder's own, copied by
 * theme/build.sh (GPL-3.0-or-later). Without them every element stays usable: panels open, links instead of players.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

add_filter(
	'render_block',
	static function ( string $html, array $block ): string {
		if ( 'fondamenta/elemento' !== ( $block['blockName'] ?? null ) || ! empty( $GLOBALS['fondamenta_comportamenti'] ) ) {
			return $html;
		}
		foreach ( is_array( $block['attrs']['attributi'] ?? null ) ? $block['attrs']['attributi'] : array() as $pair ) {
			if ( is_array( $pair ) && in_array( $pair[0] ?? null, array( 'data-xb-tabs', 'data-xb-toggle', 'data-xb-reveal', 'data-xb-video', 'data-xb-map' ), true ) ) {
				$GLOBALS['fondamenta_comportamenti'] = true;
				break;
			}
		}
		return $html;
	},
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( empty( $GLOBALS['fondamenta_comportamenti'] ) ) {
			return;
		}
		$base    = get_template_directory_uri() . '/assets/';
		$version = (string) wp_get_theme( 'fondamenta' )->get( 'Version' );
		wp_enqueue_script( 'fondamenta-comportamenti', $base . 'behaviors.js', array(), $version, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_style( 'fondamenta-comportamenti', $base . 'behaviors.css', array(), $version );
	}
);
