<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Modules;

/**
 * Histeon's modules — ADR 0015: which exist, which are on, and turning them on and off. The state lives in the
 * option `xb_modules` (id => bool); a module never chosen takes its default. A module is loaded only when it is
 * on, its code ships with Histeon, its PHP requirement is met and the same software is not active as a separate
 * plugin (never two copies of the same code).
 */
final class Registry {

	public const OPTION = 'xb_modules';
	public const ACTION = 'xb_module_toggle';

	/** States, as the Modules screen shows them. */
	public const ON         = 'on';
	public const OFF        = 'off';
	public const STANDALONE = 'standalone';
	public const PHP        = 'php';
	public const PLANNED    = 'planned';
	public const PAUSED     = 'paused';
	public const FORCED     = 'forced';

	/**
	 * Modules wp-config.php keeps off, whatever the screen says: XB_SAFE_MODE (all of them) or XB_DISABLE_MODULES
	 * (`lingua-bridge,static-bridge`). The way back when the admin itself does not open.
	 *
	 * @return list<string> Module ids, or ['*'] for all.
	 */
	public static function forced_off(): array {
		if ( defined( 'XB_SAFE_MODE' ) && XB_SAFE_MODE ) {
			$out = array( '*' );
		} else {
			$list = defined( 'XB_DISABLE_MODULES' ) ? (string) constant( 'XB_DISABLE_MODULES' ) : '';
			$out  = array_values( array_filter( array_map( 'trim', explode( ',', $list ) ) ) );
		}
		/** Tests and hosting tools only: the same list as the constants. */
		$out = function_exists( 'apply_filters' ) ? apply_filters( 'xb_modules_forced_off', $out ) : $out;
		return array_values( array_filter( is_array( $out ) ? $out : array(), 'is_string' ) );
	}

	private static function is_forced( string $id ): bool {
		$forced = self::forced_off();
		return in_array( '*', $forced, true ) || in_array( $id, $forced, true );
	}

	/** @var array<string, Module>|null */
	private static ?array $modules = null;
	/** @var array<string, bool> */
	private static array $booted = array();

	/** @return array<string, Module> */
	public static function all(): array {
		if ( null === self::$modules ) {
			$modules = array(
				new Module(
					'histos-css',
					'Histeon CSS',
					__( 'Il framework CSS libero di Histeon: token fluidi, palette OKLCH, classi su richiesta, ricette, il pannello in popup e l\'importazione da Automatic.css.', 'xb' ),
					'art',
					XB_VERSION,
					'8.3',
					true,
					true,
					'',
					\XB\Admin\SettingsScreen::url( '#xb-migration' ),
					array( \XB\Framework\HistosCss\Module::class, 'boot' ),
					null,
					null,
					plugins_url( 'assets/brand/histos-css-mark.svg', XB_FILE )
				),
				new Module(
					StaticBridge::ID,
					'Static Bridge',
					__( 'Il sito come copia statica: export, pubblicazione su Cloudflare, form e lingue.', 'xb' ),
					'cloud-upload',
					StaticBridge::version(),
					StaticBridge::PHP,
					StaticBridge::bundled(),
					false,
					StaticBridge::MAIN,
					admin_url( 'admin.php?page=static-bridge' ),
					null,
					array( StaticBridge::class, 'enable' ),
					array( StaticBridge::class, 'disable' ),
					menu: 'static-bridge'
				),
				new Module(
					LinguaBridge::ID,
					'Lingua Bridge',
					__( 'Traduzioni come pagine WordPress normali: editor sul sito, memoria, aggiornamenti delle frasi cambiate, IA.', 'xb' ),
					'translation',
					LinguaBridge::version(),
					'7.4',
					LinguaBridge::bundled(),
					false,
					LinguaBridge::MAIN,
					admin_url( 'admin.php?page=lingua-bridge-settings' ),
					null,
					array( LinguaBridge::class, 'enable' ),
					array( LinguaBridge::class, 'disable' ),
					menu: 'lingua-bridge'
				),
			);
			/** Other modules (tests, extensions): a list of Module. */
			$extra         = apply_filters( 'xb_modules', array() );
			self::$modules = array();
			foreach ( array_merge( $modules, is_array( $extra ) ? $extra : array() ) as $module ) {
				if ( $module instanceof Module && 1 === preg_match( '/^[a-z][a-z0-9-]{1,40}$/', $module->id ) ) {
					self::$modules[ $module->id ] = $module;
				}
			}
		}
		return self::$modules;
	}

	public static function get( string $id ): ?Module {
		return self::all()[ $id ] ?? null;
	}

	/** Chosen on or off by the administrator, else its default. */
	public static function chosen( Module $module ): bool {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) && array_key_exists( $module->id, $state ) ? (bool) $state[ $module->id ] : $module->default_on;
	}

	/** The separate plugin of this module is active on this site. */
	public static function standalone_active( Module $module ): bool {
		if ( '' === $module->standalone ) {
			return false;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $module->standalone, $active, true ) ) {
			return true;
		}
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		return isset( $network[ $module->standalone ] );
	}

	public static function status( Module $module ): string {
		if ( self::standalone_active( $module ) ) {
			return self::STANDALONE;
		}
		if ( ! $module->bundled ) {
			return self::PLANNED;
		}
		if ( ! $module->supported() ) {
			return self::PHP;
		}
		if ( self::is_forced( $module->id ) ) {
			return self::FORCED;
		}
		if ( ! self::chosen( $module ) && null !== FatalGuard::paused( $module->id ) ) {
			return self::PAUSED;
		}
		return self::chosen( $module ) ? self::ON : self::OFF;
	}

	public static function enabled( string $id ): bool {
		$module = self::get( $id );
		return null !== $module && self::ON === self::status( $module );
	}

	/**
	 * Modules that load like plugins, before `plugins_loaded`: called by xb.php right after the autoloader. No
	 * translation, no Module object: only files, the stored choice, PHP and the separate plugin's state.
	 *
	 * @var array<string, array{file: string, php: string, standalone: string, default: bool}>
	 */
	public const EARLY = array(
		LinguaBridge::ID => array(
			'file'       => 'modules/lingua-bridge/lingua-bridge.php',
			'php'        => '7.4',
			'standalone' => LinguaBridge::MAIN,
			'default'    => false,
		),
		StaticBridge::ID => array(
			'file'       => 'modules/static-bridge/static-bridge.php',
			'php'        => StaticBridge::PHP,
			'standalone' => StaticBridge::MAIN,
			'default'    => false,
		),
	);

	/**
	 * @param array<string, array{file: string, php: string, standalone: string, default: bool}>|null $table Tests only.
	 * @return list<string> The modules loaded.
	 */
	public static function load_early( ?array $table = null, ?string $base = null ): array {
		$state  = get_option( self::OPTION, array() );
		$state  = is_array( $state ) ? $state : array();
		$active = (array) get_option( 'active_plugins', array() );
		$sites  = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$loaded = array();
		foreach ( $table ?? self::EARLY as $id => $module ) {
			$file = ( $base ?? dirname( XB_FILE ) ) . '/' . $module['file'];
			$on   = array_key_exists( $id, $state ) ? (bool) $state[ $id ] : $module['default'];
			if ( $on && StaticBridge::ID === $id && null === $table && self::is_forced( (string) $id ) ) {
				StaticBridge::stop_routing(); // Kept off by wp-config.php: its static copies must not stay served.
			}
			if ( ! $on || self::is_forced( (string) $id ) || isset( self::$booted[ $id ] ) || ! is_readable( $file ) || version_compare( PHP_VERSION, $module['php'], '<' ) || in_array( $module['standalone'], $active, true ) || isset( $sites[ $module['standalone'] ] ) ) {
				continue;
			}
			self::$booted[ $id ] = true;
			require_once $file;
			$loaded[] = (string) $id;
		}
		if ( null === $table ) {
			add_filter( 'plugin_action_links_' . LinguaBridge::MAIN, array( LinguaBridge::class, 'plugin_actions' ) );
			add_filter( 'plugin_action_links_' . StaticBridge::MAIN, array( StaticBridge::class, 'plugin_actions' ) );
		}
		return $loaded;
	}

	/** Loads the modules that are on (once). */
	public static function boot(): void {
		foreach ( self::all() as $id => $module ) {
			if ( ! isset( self::$booted[ $id ] ) && self::enabled( $id ) ) {
				self::$booted[ $id ] = true;
				$module->boot();
			}
		}
	}

	/**
	 * Turns a module on or off: its own enable/disable steps, then the state. Never deletes the module's data.
	 *
	 * @throws \InvalidArgumentException With a message for the administrator.
	 */
	public static function set( string $id, bool $on ): void {
		$module = self::get( $id );
		if ( null === $module ) {
			throw new \InvalidArgumentException( __( 'Modulo sconosciuto.', 'xb' ) );
		}
		$status = self::status( $module );
		if ( self::STANDALONE === $status ) {
			throw new \InvalidArgumentException( __( 'È attivo come plugin separato: disattiva quello per usare il modulo.', 'xb' ) );
		}
		if ( self::PLANNED === $status ) {
			throw new \InvalidArgumentException( __( 'Questo modulo non è ancora incluso in Histeon.', 'xb' ) );
		}
		if ( self::FORCED === $status ) {
			throw new \InvalidArgumentException( __( 'È spento da wp-config.php (XB_SAFE_MODE o XB_DISABLE_MODULES): togli la costante per poterlo accendere.', 'xb' ) );
		}
		if ( $on && self::PHP === $status ) {
			/* translators: %s: PHP version */
			throw new \InvalidArgumentException( sprintf( __( 'Serve PHP %s o successivo.', 'xb' ), $module->php ) );
		}
		if ( $on === self::chosen( $module ) ) {
			return;
		}
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( $on ) {
			$module->enable();
			FatalGuard::clear( $module->id );
		} else {
			$module->disable();
		}
		$state[ $module->id ] = $on;
		update_option( self::OPTION, $state, true );
	}

	/** Forgets what was read (tests). */
	public static function reset(): void {
		self::$modules = null;
		$early         = array_intersect_key( self::$booted, self::EARLY );
		self::$booted  = $early;
	}
}
