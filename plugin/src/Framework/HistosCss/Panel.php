<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

use XB\Admin\SettingsScreen;
use XB\Plugin;

/**
 * The Histeon CSS panel — docs/spec/histos-css.md §6. A drawer that opens over any page, on the site or in the
 * admin (the «Histeon CSS» item of the admin bar, or Alt+Shift+H), without reloading: colours, type, space, shapes,
 * fonts and recipes (editor/src/histos-css). Every change is previewed at once on the page in front of you; «Salva in sviluppo» writes a
 * dev commit of the settings, published with a release like everything else. Only for users who can build.
 */
final class Panel {

	public const HANDLE = 'xb-histos-css-panel';

	public const SCRIPT = 'assets/builder/histos-css/panel.js';

	public static function register(): void {
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 85 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
	}

	/** The Histeon CSS mark (docs/brand.md). */
	public static function mark_url(): string {
		return plugins_url( 'assets/brand/histos-css-mark.svg', XB_FILE );
	}

	private static function allowed(): bool {
		return Module::active() && is_user_logged_in() && current_user_can( 'xb_build' ) && is_admin_bar_showing();
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'xb-histos-css',
				// The monochrome icon, coloured by the bar itself (currentColor), like WordPress's own icons.
				'title' => '<span class="ab-icon" aria-hidden="true" style="width:20px;height:20px;margin:6px 6px 0 0;padding:0;background-color:currentColor;-webkit-mask:url(' . esc_url( plugins_url( 'assets/brand/histos-css-icon.svg', XB_FILE ) ) . ') center/contain no-repeat;mask:url(' . esc_url( plugins_url( 'assets/brand/histos-css-icon.svg', XB_FILE ) ) . ') center/contain no-repeat"></span><span class="ab-label">Histeon CSS</span>',
				'href'  => '#xb-histos-css',
				'meta'  => array(
					'title' => __( 'Apri il pannello Histeon CSS (Alt+Maiusc+H)', 'xb' ),
				),
			)
		);
	}

	public static function enqueue(): void {
		if ( ! self::allowed() ) {
			return;
		}
		// The drawer is built with the editor (editor/src/histos-css): the same panel as the builder's Design system.
		wp_enqueue_script( self::HANDLE, plugins_url( self::SCRIPT, XB_FILE ), array(), Plugin::asset_version( self::SCRIPT ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			self::HANDLE,
			'XBHistosCss',
			array(
				'rest'      => esc_url_raw( rest_url( 'xb/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'tokensCss' => \XB\Framework\XbTokensProvider::url(),
				'mark'      => self::mark_url(),
				'settings'  => SettingsScreen::url(),
				'active'    => 'xb-tokens' === Plugin::instance()->frameworks->active()->id(),
				// The panel carries its English texts (editor/i18n/en): only whether to use them.
				'english'   => \XB\I18n::english( get_user_locale() ),
			)
		);
	}
}
