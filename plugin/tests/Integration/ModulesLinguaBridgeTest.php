<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Modules\LinguaBridge;
use XB\Modules\Registry;

/** Lingua Bridge as a module of Histeon — ADR 0015, phase 2. Needs modules/lingua-bridge (scripts/modules.sh). */
final class ModulesLinguaBridgeTest extends IntegrationTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! LinguaBridge::bundled() ) {
			$this->markTestSkipped( 'Modulo non estratto: esegui scripts/modules.sh.' );
		}
		$this->set_option( Registry::OPTION, get_option( Registry::OPTION, false ) );
		$this->set_option( 'active_plugins', get_option( 'active_plugins', array() ) );
		Registry::reset();
		$this->dir = sys_get_temp_dir() . '/xb-early-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir . '/modules/probe' );
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
		foreach ( (array) glob( $this->dir . '/modules/probe/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		@rmdir( $this->dir . '/modules/probe' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $this->dir . '/modules' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		Registry::reset();
		parent::tearDown();
	}

	public function test_it_is_bundled_off_by_default_with_its_release_version(): void {
		delete_option( Registry::OPTION );
		$module = Registry::get( LinguaBridge::ID );
		$this->assertNotNull( $module );
		$this->assertTrue( $module->bundled );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $module->version );
		$this->assertSame( self::pinned( LinguaBridge::ID ), $module->version, 'the release pinned by modules.json' );
		$this->assertSame( Registry::OFF, Registry::status( $module ) );
	}

	public function test_early_loading_follows_the_choice_php_and_the_separate_plugin(): void {
		$name  = 'probe-' . wp_generate_password( 6, false, false );
		$file  = $this->dir . '/modules/probe/' . $name . '.php';
		file_put_contents( $file, "<?php\n\$GLOBALS['xb_probe_loaded'][] = '" . $name . "';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$table = array(
			'probe' => array(
				'file'       => 'modules/probe/' . $name . '.php',
				'php'        => '7.4',
				'standalone' => 'probe/probe.php',
				'default'    => false,
			),
		);
		$GLOBALS['xb_probe_loaded'] = array();
		$this->assertSame( array(), Registry::load_early( $table, $this->dir ), 'off by default: not loaded' );
		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( 'probe/probe.php' ) ) );
		update_option( Registry::OPTION, array( 'probe' => true ) );
		$this->assertSame( array(), Registry::load_early( $table, $this->dir ), 'the separate plugin is active: never two copies' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( 'probe/probe.php' ) ) ) );
		$this->assertSame( array(), Registry::load_early( array( 'probe' => array( 'php' => '99.0' ) + $table['probe'] ), $this->dir ), 'PHP too old' );
		$this->assertSame( array( 'probe' ), Registry::load_early( $table, $this->dir ) );
		$this->assertSame( array( $name ), $GLOBALS['xb_probe_loaded'] );
		$this->assertSame( array(), Registry::load_early( $table, $this->dir ), 'once' );
	}

	public function test_turning_it_on_installs_it_and_off_keeps_its_data(): void {
		update_option( Registry::OPTION, array() );
		Registry::set( LinguaBridge::ID, true );
		$this->assertTrue( class_exists( '\\LinguaBridge\\Storage' ), 'its code is loaded' );
		$this->assertTrue( \LinguaBridge\Storage::is_available(), 'its tables, as activating the plugin creates them' );
		$module = Registry::get( LinguaBridge::ID );
		$this->assertNotNull( $module );
		$this->assertSame( Registry::ON, Registry::status( $module ) );
		$this->assertArrayNotHasKey( 'activate', LinguaBridge::plugin_actions( array( 'activate' => 'Attiva' ) ), 'the separate plugin cannot be activated on top' );

		Registry::set( LinguaBridge::ID, false );
		$this->assertSame( Registry::OFF, Registry::status( $module ) );
		$this->assertTrue( \LinguaBridge\Storage::is_available(), 'turning it off deletes nothing' );
		$this->assertArrayHasKey( 'activate', LinguaBridge::plugin_actions( array( 'activate' => 'Attiva' ) ) );
	}
}
