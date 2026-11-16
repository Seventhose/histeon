<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Modules;

/**
 * Static Bridge as a module of Histeon — ADR 0015, phase 3. Its code is the release ZIP pinned by modules.json, in
 * modules/static-bridge/, loaded by Registry::load_early() as WordPress would load the plugin; it needs PHP 8.4.
 * Turning it on runs Static Bridge's own preflight first (memory, PHP, WordPress, file functions): if it fails,
 * nothing changes and its reasons are shown; then what activating the plugin runs. Turning it off runs what
 * deactivating it runs (scheduled jobs, routing marker, Apache rules); the static copies and settings stay.
 */
final class StaticBridge {

	public const ID   = 'static-bridge';
	public const MAIN = 'static-bridge/static-bridge.php';
	public const PHP  = '8.4';

	public static function file(): string {
		return dirname( XB_FILE ) . '/modules/static-bridge/static-bridge.php';
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

	private static function load(): bool {
		if ( ! class_exists( '\\Static_Bridge_Plugin', false ) && self::bundled() && version_compare( PHP_VERSION, self::PHP, '>=' ) ) {
			require_once self::file();
		}
		return class_exists( '\\Static_Bridge_Plugin', false );
	}

	/**
	 * Static Bridge's web-server rules (Apache and Nginx) serve the static copies only while
	 * wp-content/static-bridge/.enabled exists. Its own deactivation removes that marker; when the module stops
	 * some other way (FatalGuard pauses it, XB_SAFE_MODE or XB_DISABLE_MODULES keep it off, Histeon is deactivated)
	 * nothing would, and pages made private or deleted since the last copy would stay public. This removes the
	 * markers without loading the module's code (which may be what failed): the site falls back to WordPress, and
	 * the next publish in Static Bridge turns the copies on again. Not when the separate plugin owns them.
	 */
	public static function stop_routing(): void {
		if ( self::standalone() ) {
			return;
		}
		$root = trailingslashit( WP_CONTENT_DIR ) . 'static-bridge';
		if ( is_link( $root ) || ! is_dir( $root ) ) {
			return;
		}
		foreach ( array( '.enabled', '.strict' ) as $marker ) {
			$file = $root . '/' . $marker;
			if ( is_file( $file ) && ! is_link( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/** Histeon deactivated with the module on: the module's own deactivation if its code is loaded, else the markers. */
	public static function histos_deactivated(): void {
		$state = get_option( Registry::OPTION, array() );
		if ( ! is_array( $state ) || empty( $state[ self::ID ] ) ) {
			return;
		}
		if ( class_exists( '\\Static_Bridge_Plugin', false ) && ! self::standalone() ) {
			\Static_Bridge_Plugin::deactivate();
			return;
		}
		self::stop_routing();
	}

	private static function standalone(): bool {
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		return in_array( self::MAIN, (array) get_option( 'active_plugins', array() ), true ) || isset( $network[ self::MAIN ] );
	}

	/** Preflight, then as activating the plugin. */
	public static function enable(): void {
		if ( ! self::load() ) {
			throw new \InvalidArgumentException( __( 'Il codice di Static Bridge non è nel pacchetto di Histeon, o PHP è più vecchio della 8.4.', 'xb' ) );
		}
		$report = \Static_Bridge_Plugin::activation_preflight();
		if ( empty( $report['ok'] ) ) {
			$errors = is_array( $report['errors'] ?? null ) ? array_map( 'strval', $report['errors'] ) : array();
			/* translators: %s: reasons */
			throw new \InvalidArgumentException( sprintf( __( 'Static Bridge non si accende: %s', 'xb' ), implode( ' · ', $errors ) ) );
		}
		\Static_Bridge_Plugin::activate();
	}

	public static function disable(): void {
		if ( class_exists( '\\Static_Bridge_Plugin', false ) ) {
			\Static_Bridge_Plugin::deactivate();
		}
	}

	/**
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
