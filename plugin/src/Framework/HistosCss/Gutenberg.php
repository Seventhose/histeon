<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

use XB\Plugin;

/**
 * Histeon CSS in the block editor's pickers (ThemeJson), on a Histeon site whose framework is Histeon CSS.
 */
final class Gutenberg {

	public static function register(): void {
		add_filter( 'wp_theme_json_data_theme', array( self::class, 'theme_json' ) );
		add_action( 'init', array( self::class, 'block_styles' ), 20 );
	}

	/** The token styles of WordPress's own blocks (lista della spesa 139), with the same conditions as the pickers. */
	public static function block_styles(): void {
		if ( ! self::on() ) {
			return;
		}
		ThemeJson::register_block_styles();
	}

	private static function on(): bool {
		return Module::active() && 'xb-tokens' === Plugin::instance()->frameworks->active()->id() && 0 !== (int) SiteDesign::current()['block_palette'];
	}

	public static function theme_json( \WP_Theme_JSON_Data $data ): \WP_Theme_JSON_Data {
		// The panel can leave the block editor's palette as the theme has it (block_palette, doc 19 §5.3.12).
		if ( ! self::on() ) {
			return $data;
		}
		return ThemeJson::apply( $data );
	}
}
