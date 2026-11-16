<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Modules;

/**
 * A module that stops the site is turned off by itself — docs/spec/modules.md §Errori fatali. When a fatal error
 * comes from a module's files, Histeon records it and turns that module off: the next request runs without it, the
 * rest of the site works, and the administrators are told what happened and where, until someone fixes the module
 * and turns it on again (Histeon → Moduli).
 *
 * The record is made inside WordPress's own fatal error handler (`wp_should_handle_php_error`), which ends the
 * request; a shutdown function covers sites that turned that handler off. Registered before any module loads.
 */
final class FatalGuard {

	public const PAUSED = 'xb_modules_paused';

	/** PHP errors that stop the request. */
	private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'wp_should_handle_php_error', array( self::class, 'on_error' ), 10, 2 );
		register_shutdown_function( array( self::class, 'on_shutdown' ) );
	}

	/**
	 * @param mixed $should
	 * @param mixed $error  error_get_last() shape.
	 */
	public static function on_error( mixed $should, mixed $error ): mixed {
		if ( is_array( $error ) ) {
			self::handle( $error );
		}
		return $should;
	}

	public static function on_shutdown(): void {
		$error = error_get_last();
		if ( is_array( $error ) ) {
			self::handle( $error );
		}
	}

	/**
	 * Records and pauses the module a fatal error comes from. Returns its id, or null.
	 *
	 * @param array<string, mixed> $error type, message, file, line.
	 */
	public static function handle( array $error ): ?string {
		$type = (int) ( $error['type'] ?? 0 );
		$file = (string) ( $error['file'] ?? '' );
		if ( 0 === ( $type & self::FATAL ) || '' === $file ) {
			return null;
		}
		// Out of memory or time: the request was too big, not the module broken. The file is only where it ran out.
		$message = (string) ( $error['message'] ?? '' );
		if ( str_starts_with( $message, 'Allowed memory size' ) || str_starts_with( $message, 'Maximum execution time' ) ) {
			return null;
		}
		$id = self::module_for( $file );
		if ( null === $id ) {
			return null;
		}
		try {
			self::pause( $id, $file, (int) ( $error['line'] ?? 0 ), (string) ( $error['message'] ?? '' ) );
		} catch ( \Throwable ) {
			return null; // The database may be the problem: nothing more to do in a dying request.
		}
		return $id;
	}

	/** The module whose files these are: modules/<id>/ for modules that load like plugins, src/Framework/HistosCss/ for Histeon CSS. */
	public static function module_for( string $file ): ?string {
		$file = str_replace( '\\', '/', $file );
		$base = str_replace( '\\', '/', dirname( XB_FILE ) );
		if ( 1 === preg_match( '#^' . preg_quote( $base, '#' ) . '/modules/([a-z][a-z0-9-]{1,40})/#', $file, $m ) ) {
			return $m[1];
		}
		if ( str_starts_with( $file, $base . '/src/Framework/HistosCss/' ) || str_starts_with( $file, $base . '/assets/histos-css/' ) ) {
			return \XB\Framework\HistosCss\Module::ID;
		}
		return null;
	}

	/** Turns the module off and keeps why (no path outside the plugin, at most 300 characters). */
	public static function pause( string $id, string $file, int $line, string $message ): void {
		$base    = str_replace( '\\', '/', dirname( XB_FILE ) ) . '/';
		$file    = str_replace( $base, '', str_replace( '\\', '/', $file ) );
		$message = str_replace( array( $base, str_replace( '\\', '/', ABSPATH ) ), '', str_replace( '\\', '/', $message ) );
		$message = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) ), 0, 300 );
		$paused  = get_option( self::PAUSED, array() );
		$paused  = is_array( $paused ) ? $paused : array();
		$paused[ $id ] = array(
			'time'    => time(),
			'file'    => mb_substr( $file, 0, 200 ),
			'line'    => $line,
			'message' => $message,
		);
		update_option( self::PAUSED, $paused, true );
		$state        = get_option( Registry::OPTION, array() );
		$state        = is_array( $state ) ? $state : array();
		$state[ $id ] = false;
		update_option( Registry::OPTION, $state, true );
		if ( StaticBridge::ID === $id ) {
			StaticBridge::stop_routing();
		}
	}

	/**
	 * Why a module was turned off by an error, if it was.
	 *
	 * @return array{time: int, file: string, line: int, message: string}|null
	 */
	public static function paused( string $id ): ?array {
		$paused = get_option( self::PAUSED, array() );
		$entry  = is_array( $paused ) ? ( $paused[ $id ] ?? null ) : null;
		if ( ! is_array( $entry ) ) {
			return null;
		}
		return array(
			'time'    => (int) ( $entry['time'] ?? 0 ),
			'file'    => (string) ( $entry['file'] ?? '' ),
			'line'    => (int) ( $entry['line'] ?? 0 ),
			'message' => (string) ( $entry['message'] ?? '' ),
		);
	}

	/** Forgets the error once the module is turned on again. */
	public static function clear( string $id ): void {
		$paused = get_option( self::PAUSED, array() );
		if ( is_array( $paused ) && isset( $paused[ $id ] ) ) {
			unset( $paused[ $id ] );
			update_option( self::PAUSED, $paused, true );
		}
	}

	/** Administrators see which module stopped and why, on every admin screen, until it is turned on again. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_build' ) ) {
			return;
		}
		$paused = get_option( self::PAUSED, array() );
		if ( ! is_array( $paused ) || array() === $paused ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Histeon ha spento un modulo dopo un errore fatale', 'xb' ) . '</strong> ' . esc_html__( 'Il resto del sito funziona. Correggi o aggiorna il modulo, poi riaccendilo.', 'xb' ) . '</p><ul style="list-style:disc;margin-left:1.4em">';
		foreach ( array_keys( $paused ) as $id ) {
			$entry  = self::paused( (string) $id );
			$module = Registry::get( (string) $id );
			if ( null === $entry ) {
				continue;
			}
			/* translators: 1: module, 2: file, 3: line, 4: message */
			echo '<li>' . esc_html( sprintf( __( '%1$s — %2$s, riga %3$d: %4$s', 'xb' ), null === $module ? (string) $id : $module->name, $entry['file'], $entry['line'], $entry['message'] ) ) . '</li>';
		}
		echo '</ul><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . \XB\Admin\ModulesScreen::SLUG ) ) . '">' . esc_html__( 'Apri Histeon → Moduli', 'xb' ) . '</a></p></div>';
	}
}
