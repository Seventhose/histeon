<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\HistosCss\Module as HistosCss;
use XB\Modules\FatalGuard;
use XB\Modules\Registry;

/** A module that stops the site turns itself off; wp-config.php can keep modules off — docs/spec/modules.md. */
final class FatalGuardTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Registry::OPTION, get_option( Registry::OPTION, false ) );
		$this->set_option( FatalGuard::PAUSED, get_option( FatalGuard::PAUSED, false ) );
		delete_option( FatalGuard::PAUSED );
		update_option( Registry::OPTION, array() );
		Registry::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'xb_modules_forced_off' );
		Registry::reset();
		parent::tearDown();
	}

	public function test_files_are_told_apart_by_module(): void {
		$base = dirname( XB_FILE );
		$this->assertSame( 'lingua-bridge', FatalGuard::module_for( $base . '/modules/lingua-bridge/includes/class-editor.php' ) );
		$this->assertSame( HistosCss::ID, FatalGuard::module_for( $base . '/src/Framework/HistosCss/Tokens.php' ) );
		$this->assertNull( FatalGuard::module_for( $base . '/src/Plugin.php' ), 'Histeon itself is not a module' );
		$this->assertNull( FatalGuard::module_for( ABSPATH . 'wp-includes/post.php' ) );
	}

	public function test_a_fatal_error_in_a_module_turns_it_off_and_says_why(): void {
		$this->assertTrue( HistosCss::active() );
		$this->assertNull( FatalGuard::handle( array( 'type' => E_WARNING, 'message' => 'x', 'file' => dirname( XB_FILE ) . '/src/Framework/HistosCss/Tokens.php', 'line' => 1 ) ), 'a warning is not fatal' );
		$id = FatalGuard::handle(
			array(
				'type'    => E_ERROR,
				'message' => 'Uncaught Error: Call to undefined function nope() in ' . dirname( XB_FILE ) . '/src/Framework/HistosCss/Tokens.php:12 <b>bold</b>',
				'file'    => dirname( XB_FILE ) . '/src/Framework/HistosCss/Tokens.php',
				'line'    => 12,
			)
		);
		$this->assertSame( HistosCss::ID, $id );
		$this->assertFalse( HistosCss::active(), 'off from the next moment on' );
		$module = Registry::get( HistosCss::ID );
		$this->assertNotNull( $module );
		$this->assertSame( Registry::PAUSED, Registry::status( $module ) );
		$entry = FatalGuard::paused( HistosCss::ID );
		$this->assertNotNull( $entry );
		$this->assertSame( 'src/Framework/HistosCss/Tokens.php', $entry['file'], 'no path outside the plugin' );
		$this->assertStringNotContainsString( '<b>', $entry['message'] );
		$this->assertStringNotContainsString( dirname( XB_FILE ), $entry['message'] );

		wp_set_current_user( 1 );
		ob_start();
		FatalGuard::notice();
		$this->assertStringContainsString( 'Histeon CSS', (string) ob_get_clean(), 'the administrators are told' );

		Registry::set( HistosCss::ID, true );
		$this->assertTrue( HistosCss::active(), 'turned on again once fixed' );
		$this->assertNull( FatalGuard::paused( HistosCss::ID ) );
		ob_start();
		FatalGuard::notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_errors_outside_modules_change_nothing(): void {
		$this->assertNull( FatalGuard::handle( array( 'type' => E_ERROR, 'message' => 'x', 'file' => dirname( XB_FILE ) . '/src/Plugin.php', 'line' => 1 ) ) );
		$this->assertTrue( HistosCss::active() );
		$this->assertSame( false, get_option( FatalGuard::PAUSED ) );
	}

	/** Note 6 of the 3 November audit: running out of memory or time inside a module does not turn it off. */
	public function test_memory_and_time_limits_do_not_pause_a_module(): void {
		$file = dirname( XB_FILE ) . '/src/Framework/HistosCss/Panel.php';
		$this->assertSame( HistosCss::ID, FatalGuard::module_for( $file ) );
		foreach ( array( 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 'Maximum execution time of 30 seconds exceeded' ) as $message ) {
			$this->assertNull( FatalGuard::handle( array( 'type' => E_ERROR, 'message' => $message, 'file' => $file, 'line' => 1 ) ), $message );
		}
		$this->assertTrue( HistosCss::active() );
		$this->assertSame( false, get_option( FatalGuard::PAUSED ) );
	}

	public function test_wp_config_can_keep_modules_off(): void {
		add_filter( 'xb_modules_forced_off', static fn (): array => array( HistosCss::ID ) );
		$module = Registry::get( HistosCss::ID );
		$this->assertNotNull( $module );
		$this->assertSame( Registry::FORCED, Registry::status( $module ) );
		$this->assertFalse( HistosCss::active() );
		try {
			Registry::set( HistosCss::ID, true );
			$this->fail( 'a module kept off by wp-config.php cannot be turned on from the screen' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		remove_all_filters( 'xb_modules_forced_off' );
		add_filter( 'xb_modules_forced_off', static fn (): array => array( '*' ) );
		$table = array( 'probe' => array( 'file' => 'xb.php', 'php' => '7.4', 'standalone' => 'probe/probe.php', 'default' => true ) );
		$this->assertSame( array(), Registry::load_early( $table, dirname( XB_FILE ) ), 'safe mode: nothing loads' );
	}
}
