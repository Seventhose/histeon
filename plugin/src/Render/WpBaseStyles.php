<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

/**
 * WP's base rules for blocks (`wp-block-library`: `:where(figure){margin:0 0 1em}` and the like) left out of a
 * Histeon page, so that it looks as in the builder and in a Histeon Publish build, which never had them (doc 23 of
 * Publish, list 192). A page keeps them as soon as it renders a block of WP or of another plugin: an article
 * written in the block editor relies on them.
 *
 * The handle stays registered with no file, so styles that depend on it and inline CSS added to it still print.
 * Block themes render the template before `wp_head`, so the blocks are known when styles are enqueued.
 */
final class WpBaseStyles {

	/** The handles whose file holds the base rules. */
	public const HANDLES = array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles' );

	/** Blocks that only hold others: what is inside them decides. */
	private const WRAPPERS = array( 'core/template-part', 'core/block', 'core/pattern' );

	private static bool $foreign = false;

	public static function register(): void {
		add_filter( 'render_block', array( self::class, 'observe' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'apply' ), 100 );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	public static function observe( string $html, array $block ): string {
		$name = $block['blockName'] ?? null;
		if ( is_string( $name ) && ! str_starts_with( $name, 'xb/' ) && ! in_array( $name, self::WRAPPERS, true ) ) {
			self::$foreign = true;
		}
		return $html;
	}

	/** Whether the page being served is a Histeon template that rendered no block but Histeon's. */
	public static function histeon_only(): bool {
		global $_wp_current_template_content;
		$histeon = is_string( $_wp_current_template_content ) && str_contains( $_wp_current_template_content, '<!-- wp:xb/' );
		/** Filter: false keeps WP's base block rules on this page too. */
		return (bool) apply_filters( 'xb_drop_wp_base_styles', $histeon && ! self::$foreign );
	}

	public static function apply(): void {
		if ( is_admin() || ! self::histeon_only() ) {
			return;
		}
		$styles = wp_styles();
		foreach ( self::HANDLES as $handle ) {
			if ( isset( $styles->registered[ $handle ] ) ) {
				$styles->registered[ $handle ]->src = false;
			}
		}
	}

	/** Tests: forget the blocks seen so far. */
	public static function reset(): void {
		self::$foreign = false;
	}
}
