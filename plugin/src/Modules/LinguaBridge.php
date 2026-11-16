<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Modules;

/**
 * Lingua Bridge as a module of Histeon — ADR 0015, phase 2. Its code is the release ZIP pinned by modules.json, in
 * modules/lingua-bridge/, loaded by Registry::load_early() as WordPress would load the plugin. Turning it on runs
 * what activating the plugin runs, plus the router: if the site keeps its language URLs with the plugin off, the
 * router in mu-plugins is rewritten to point at the module (it may still point at a separate copy that is off).
 */
final class LinguaBridge {

	public const ID   = 'lingua-bridge';
	public const MAIN = 'lingua-bridge/lingua-bridge.php';

	public static function file(): string {
		return dirname( XB_FILE ) . '/modules/lingua-bridge/lingua-bridge.php';
	}

	public static function bundled(): bool {
		return is_readable( self::file() );
	}

	public static function version(): string {
		if ( ! self::bundled() || ! function_exists( 'get_file_data' ) ) {
			return '';
		}
		return (string) ( get_file_data( self::file(), array( 'version' => 'Version' ) )['version'] ?? '' );
	}

	/** Loads the code if this request has not (turned on now, or a test). */
	private static function load(): bool {
		if ( ! defined( 'LINGUA_BRIDGE_VERSION' ) && self::bundled() ) {
			require_once self::file();
		}
		return class_exists( '\\LinguaBridge\\Storage' );
	}

	/** As activating the plugin: tables, router, rewrite rules. */
	public static function enable(): void {
		if ( ! self::load() ) {
			throw new \InvalidArgumentException( __( 'Il codice di Lingua Bridge non è nel pacchetto di Histeon.', 'xb' ) );
		}
		\LinguaBridge\Storage::install();
		$settings = \LinguaBridge\Languages::settings();
		\LinguaBridge\Router::sync( ! empty( $settings['keep_routing'] ) );
		flush_rewrite_rules( false );
	}

	/** As deactivating the plugin: its rewrite rules go; its data and the router (if wanted) stay. */
	public static function disable(): void {
		if ( class_exists( '\\LinguaBridge\\Routing' ) ) {
			\LinguaBridge\Routing::deactivate();
		}
	}

	/**
	 * On the Plugins screen, the separate Lingua Bridge cannot be activated while the module is on: the same code
	 * twice would stop the site with a fatal error.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function plugin_actions( array $actions ): array {
		if ( Registry::enabled( self::ID ) && isset( $actions['activate'] ) ) {
			unset( $actions['activate'] );
			$actions['xb-module'] = '<span>' . esc_html__( 'Attivo come modulo di Histeon', 'xb' ) . '</span>';
		}
		return $actions;
	}
}
