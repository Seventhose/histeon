<?php
/**
 * @package HistosCss
 */

declare(strict_types=1);

namespace HistosCss;

use HistosCss\Core\Design;
use HistosCss\Core\Recipes;
use HistosCss\Core\ThemeJson;
use HistosCss\Core\Tokens;

/**
 * Histeon CSS on its own (ADR 0015, M5; docs/spec/histos-css.md §9): the framework of Histeon for a site without the
 * builder. The core (colours, scales, tokens, classes, recipes, Gutenberg presets) is Histeon's own code, packaged by
 * scripts/package-histos-css.sh; this class is what differs: the parameters live in an option instead of Histeon's
 * versioning, and saving applies them at once. With Histeon active it stays out of the way: Histeon includes it.
 */
final class Plugin {

	public const OPTION = 'histos_css_design';
	public const REST   = 'histos-css/v1';

	public static function boot(): void {
		if ( defined( 'XB_FILE' ) ) {
			add_action( 'admin_notices', array( self::class, 'histos_notice' ) );
			return;
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'styles' ), 5 );
		add_action( 'enqueue_block_assets', array( self::class, 'styles' ), 5 );
		add_filter( 'wp_theme_json_data_theme', array( ThemeJson::class, 'apply' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 85 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'panel' ), 30 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'panel' ), 30 );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function histos_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Histeon CSS è già incluso in Histeon: il plugin separato resta fermo e puoi disattivarlo.', 'histos-css' ) . '</p></div>';
		}
	}

	/** @return array<string, string|int|float> */
	public static function design(): array {
		try {
			return Design::validate( get_option( self::OPTION, array() ) );
		} catch ( \InvalidArgumentException ) {
			return Design::DEFAULTS;
		}
	}

	private static function url( string $path ): string {
		return plugins_url( $path, HISTOS_CSS_FILE );
	}

	private static function version( string $path ): string {
		$file = dirname( HISTOS_CSS_FILE ) . '/' . $path;
		return is_file( $file ) ? (string) filemtime( $file ) : HISTOS_CSS_VERSION;
	}

	/** Tokens, the site's parameters (only what differs from the defaults), and the classes. */
	public static function styles(): void {
		wp_enqueue_style( 'histos-css-tokens', self::url( 'assets/xb-tokens.css' ), array(), self::version( 'assets/xb-tokens.css' ) );
		$changes = Tokens::css_changes( self::design() );
		// Both hooks run on the site (wp_enqueue_scripts, enqueue_block_assets): the parameters once.
		if ( '' !== $changes && ! wp_styles()->get_data( 'histos-css-tokens', 'after' ) ) {
			// Built from validated parameters only (Design::validate): no character can end the rule or the element.
			wp_add_inline_style( 'histos-css-tokens', $changes );
		}
		wp_enqueue_style( 'histos-css', self::url( 'assets/histos.css' ), array( 'histos-css-tokens' ), self::version( 'assets/histos.css' ) );
	}

	public static function can(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	public static function routes(): void {
		$can = array( self::class, 'can' );
		register_rest_route(
			self::REST,
			'/tokens',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'get_tokens' ), 'permission_callback' => $can ),
				array( 'methods' => 'POST', 'callback' => array( self::class, 'save_tokens' ), 'permission_callback' => $can ),
			)
		);
		register_rest_route( self::REST, '/design/preview', array( 'methods' => 'POST', 'callback' => array( self::class, 'preview' ), 'permission_callback' => $can ) );
		register_rest_route( self::REST, '/framework', array( 'methods' => 'GET', 'callback' => array( self::class, 'framework' ), 'permission_callback' => $can ) );
	}

	/** What the panel loads: the parameters, the defaults and how readable the palette is. */
	public static function get_tokens(): \WP_REST_Response {
		$design = self::design();
		return new \WP_REST_Response(
			array(
				'tokens'   => (object) array(),
				'names'    => array(),
				'editable' => true,
				'head'     => '',
				'base'     => '',
				'design'   => (object) $design,
				'defaults' => (object) Design::DEFAULTS,
				'contrast' => Tokens::contrast( $design ),
			)
		);
	}

	/** Saves the parameters (only what differs from the defaults): on the site at once. */
	public static function save_tokens( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$design = Design::validate( $request['design'] ?? array() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'histos_css_invalid_design', $e->getMessage(), array( 'status' => 400 ) );
		}
		update_option( self::OPTION, Design::changes( $design ), true );
		return new \WP_REST_Response( array( 'head' => '', 'base' => '' ) );
	}

	public static function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$design = Design::validate( $request['design'] ?? array() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'histos_css_invalid_design', $e->getMessage(), array( 'status' => 400 ) );
		}
		$out = array(
			'css'      => Tokens::css_changes( $design ),
			'design'   => (object) $design,
			'contrast' => Tokens::contrast( $design ),
		);
		// «Export tokens (DTCG)»: every printed token, light and dark, only when asked.
		if ( true === ( $request['tokens'] ?? false ) ) {
			$out['tokens'] = Tokens::export( $design );
		}
		return new \WP_REST_Response( $out );
	}

	public static function framework(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'active'  => array( 'id' => 'histos-css', 'label' => 'Histeon CSS' ),
				'recipes' => Recipes::all(),
			)
		);
	}

	private static function allowed(): bool {
		return is_user_logged_in() && self::can() && is_admin_bar_showing();
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$icon = esc_url( self::url( 'assets/histos-css-icon.svg' ) );
		$bar->add_node(
			array(
				'id'    => 'xb-histos-css',
				'title' => '<span class="ab-icon" aria-hidden="true" style="width:20px;height:20px;margin:6px 6px 0 0;padding:0;background-color:currentColor;-webkit-mask:url(' . $icon . ') center/contain no-repeat;mask:url(' . $icon . ') center/contain no-repeat"></span><span class="ab-label">Histeon CSS</span>',
				'href'  => '#xb-histos-css',
				'meta'  => array( 'title' => __( 'Apri il pannello Histeon CSS (Alt+Maiusc+H)', 'histos-css' ) ),
			)
		);
	}

	/** The same panel as in Histeon, talking to this plugin's routes. */
	public static function panel(): void {
		if ( ! self::allowed() ) {
			return;
		}
		// The drawer built with Histeon's editor (editor/src/histos-css), copied here by scripts/package-histos-css.sh.
		wp_enqueue_script( 'histos-css-panel', self::url( 'assets/panel.js' ), array(), self::version( 'assets/panel.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'histos-css-panel',
			'XBHistosCss',
			array(
				'rest'      => esc_url_raw( rest_url( self::REST . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'tokensCss' => self::url( 'assets/xb-tokens.css' ),
				'mark'      => self::url( 'assets/histos-css-mark.svg' ),
				'settings'  => admin_url( 'themes.php?page=histos-css' ),
				'active'    => true,
				'savedText' => __( 'Salvato: è già sul sito.', 'histos-css' ),
				'english'   => ! str_starts_with( get_user_locale(), 'it' ),
			)
		);
	}

	public static function menu(): void {
		add_theme_page( 'Histeon CSS', 'Histeon CSS', 'edit_theme_options', 'histos-css', array( self::class, 'page' ) );
	}

	public static function page(): void {
		echo '<div class="wrap"><h1>Histeon CSS</h1>';
		echo '<p>' . esc_html__( 'Colori, testi, spazi e forme del sito si scelgono nel pannello Histeon CSS: si apre da ogni pagina dalla barra di amministrazione, o con Alt+Maiusc+H, e mostra subito le modifiche sulla pagina che hai davanti.', 'histos-css' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( home_url( '/#xb-histos-css' ) ) . '">' . esc_html__( 'Apri il pannello sul sito', 'histos-css' ) . '</a></p>';
		echo '<p>' . esc_html__( 'Nelle pagine usa le variabili --xb-* (per esempio var(--xb-space-m), var(--xb-primary)) e le classi di Histeon CSS; nell\'editor a blocchi la palette e le dimensioni del testo sono già quelle del sito.', 'histos-css' ) . '</p></div>';
	}
}
