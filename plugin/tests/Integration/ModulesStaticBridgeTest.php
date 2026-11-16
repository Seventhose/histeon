<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Modules\Registry;
use XB\Modules\StaticBridge;

/**
 * Static Bridge as a module of Histeon — ADR 0015, phase 3. Needs modules/static-bridge (scripts/modules.sh). The dev
 * WordPress runs PHP 8.3: here the module must say it needs 8.4 and refuse to turn on; turning it on is verified on
 * a PHP 8.4 copy (docs/spec/modules.md).
 */
final class ModulesStaticBridgeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! StaticBridge::bundled() ) {
			$this->markTestSkipped( 'Modulo non estratto: esegui scripts/modules.sh.' );
		}
		$this->set_option( Registry::OPTION, get_option( Registry::OPTION, false ) );
		Registry::reset();
	}

	/**
	 * The version the bundled release declares in its readme (`Stable tag`). That the release is the one modules.json
	 * pins, scripts/modules.sh checks by SHA-256 before putting it in modules/.
	 */
	private static function pinned( string $id ): string {
		$readme = (string) @file_get_contents( dirname( __DIR__, 2 ) . '/modules/' . $id . '/readme.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 1 === preg_match( '/^Stable tag:\s*(\d+\.\d+\.\d+)\s*$/mi', $readme, $m ) ? $m[1] : '';
	}

	protected function tearDown(): void {
		Registry::reset();
		parent::tearDown();
	}

	/**
	 * The web server serves the static copies while wp-content/static-bridge/.enabled exists. A module stopped by
	 * FatalGuard, by XB_DISABLE_MODULES or by deactivating Histeon skips its own deactivation: the markers go anyway,
	 * so pages made private or deleted since the last copy do not stay public.
	 */
	public function test_every_way_the_module_stops_takes_the_static_copies_offline(): void {
		$root = trailingslashit( WP_CONTENT_DIR ) . 'static-bridge';
		$had  = is_dir( $root );
		wp_mkdir_p( $root );
		$this->set_option( \XB\Modules\FatalGuard::PAUSED, get_option( \XB\Modules\FatalGuard::PAUSED, false ) );
		$markers = static function () use ( $root ): void {
			file_put_contents( $root . '/.enabled', "enabled\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $root . '/.strict', "strict\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		};
		try {
			$markers();
			update_option( Registry::OPTION, array( StaticBridge::ID => true ) );
			\XB\Modules\FatalGuard::pause( StaticBridge::ID, __FILE__, 1, 'test' );
			$this->assertFileDoesNotExist( $root . '/.enabled', 'paused after a fatal error' );
			$this->assertFileDoesNotExist( $root . '/.strict' );

			$markers();
			update_option( Registry::OPTION, array( StaticBridge::ID => true ) );
			$forced = static fn (): array => array( StaticBridge::ID );
			add_filter( 'xb_modules_forced_off', $forced );
			Registry::load_early();
			remove_filter( 'xb_modules_forced_off', $forced );
			$this->assertFileDoesNotExist( $root . '/.enabled', 'kept off by wp-config.php' );

			$markers();
			StaticBridge::histos_deactivated();
			$this->assertFileDoesNotExist( $root . '/.enabled', 'Histeon deactivated' );

			$markers();
			update_option( Registry::OPTION, array( StaticBridge::ID => false ) );
			StaticBridge::histos_deactivated();
			$this->assertFileExists( $root . '/.enabled', 'a module that was off owns nothing to take down' );
			update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( StaticBridge::MAIN ) ) );
			StaticBridge::stop_routing();
			$this->assertFileExists( $root . '/.enabled', 'the separate plugin owns its markers' );
		} finally {
			update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( StaticBridge::MAIN ) ) ) );
			foreach ( array( '.enabled', '.strict' ) as $marker ) {
				if ( is_file( $root . '/' . $marker ) ) {
					unlink( $root . '/' . $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
			if ( ! $had ) {
				rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	public function test_it_is_bundled_with_its_release_and_needs_php_84(): void {
		$module = Registry::get( StaticBridge::ID );
		$this->assertNotNull( $module );
		$this->assertTrue( $module->bundled );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $module->version );
		$this->assertSame( self::pinned( StaticBridge::ID ), $module->version, 'the release pinned by modules.json' );
		$this->assertSame( '8.4', $module->php );
		if ( version_compare( PHP_VERSION, '8.4', '>=' ) ) {
			$this->assertContains( Registry::status( $module ), array( Registry::OFF, Registry::STANDALONE ) );
			return;
		}
		$this->assertSame( Registry::PHP, Registry::status( $module ) );
		$this->expectException( \InvalidArgumentException::class );
		Registry::set( StaticBridge::ID, true );
	}

	public function test_early_loading_skips_it_on_older_php(): void {
		update_option( Registry::OPTION, array( StaticBridge::ID => true ) );
		$loaded = Registry::load_early( array( StaticBridge::ID => Registry::EARLY[ StaticBridge::ID ] ) );
		if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
			$this->assertSame( array(), $loaded, 'never loaded where its PHP is missing' );
			$this->assertFalse( class_exists( '\\Static_Bridge_Plugin', false ) );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}
}
