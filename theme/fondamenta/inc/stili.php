<?php
/**
 * WP's base rules for blocks (`wp-block-library`: `:where(figure){margin:0 0 1em}` and the like) left out of a page
 * made of Fondamenta's blocks and core text blocks, as the builder leaves them out of its own pages: the page looks
 * as it did when it was built. A block of WP or of another plugin brings them back, since it relies on them.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

/** Blocks a page may hold and still go without the base rules. */
const FONDAMENTA_SENZA_BASE = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/html', 'core/template-part', 'core/block', 'core/pattern', 'core/post-content' );

add_filter(
	'render_block',
	static function ( string $html, array $block ): string {
		$name = $block['blockName'] ?? null;
		if ( ! is_string( $name ) ) {
			return $html;
		}
		$own = str_starts_with( $name, 'fondamenta/' ) || str_starts_with( $name, 'xb/' );
		if ( $own ) {
			$GLOBALS['fondamenta_blocchi'] = true;
		} elseif ( ! in_array( $name, FONDAMENTA_SENZA_BASE, true ) ) {
			$GLOBALS['fondamenta_altri'] = true;
		}
		return $html;
	},
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$only = ! empty( $GLOBALS['fondamenta_blocchi'] ) && empty( $GLOBALS['fondamenta_altri'] );
		/** Filter: false keeps WP's base block rules on this page too. */
		if ( is_admin() || ! (bool) apply_filters( 'fondamenta_senza_stili_di_base', $only ) ) {
			return;
		}
		// The handles stay registered with no file: styles that depend on them and inline CSS added to them still print.
		$styles = wp_styles();
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles' ) as $handle ) {
			if ( isset( $styles->registered[ $handle ] ) ) {
				$styles->registered[ $handle ]->src = false;
			}
		}
	},
	101
);
