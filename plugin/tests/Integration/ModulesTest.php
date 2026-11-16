<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\HistosCss\Module as HistosCss;
use XB\Modules\Module;
use XB\Modules\Registry;
use XB\Plugin;

/** Histeon's modules: registry, states, switching — ADR 0015. */
final class ModulesTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Registry::OPTION, get_option( Registry::OPTION, false ) );
		$this->set_option( 'active_plugins', get_option( 'active_plugins', array() ) );
		$calls = &$this->calls;
		add_filter(
			'xb_modules',
			static function () use ( &$calls ): array {
				return array(
					new Module( 'test-mod', 'Test', 'Prova', 'admin-generic', '1.0', '8.0', true, false, '', '', static function () use ( &$calls ): void { $calls[] = 'boot'; }, static function () use ( &$calls ): void { $calls[] = 'enable'; }, static function () use ( &$calls ): void { $calls[] = 'disable'; } ),
					new Module( 'test-alone', 'Separato', 'Prova', 'admin-generic', '1.0', '8.0', true, true, 'test-alone/test-alone.php' ),
					new Module( 'test-future', 'Futuro', 'Prova', 'admin-generic', '1.0', '99.0', true, false ),
				);
			}
		);
		Registry::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'xb_modules' );
		Registry::reset();
		parent::tearDown();
	}

	public function test_histos_css_is_on_by_default_and_can_be_turned_off(): void {
		delete_option( Registry::OPTION );
		$tokens = Plugin::instance()->frameworks->all()['xb-tokens'];
		$this->assertTrue( HistosCss::active() );
		$this->assertSame( 'Histeon CSS', $tokens->label() );
		$this->assertNotEmpty( $tokens->classes() );
		$this->assertCount( 2, $tokens->stylesheets() );

		Registry::set( HistosCss::ID, false );
		$this->assertFalse( HistosCss::active() );
		$this->assertSame( array(), $tokens->classes(), 'no classes' );
		$this->assertCount( 1, $tokens->stylesheets(), 'only the base tokens: components still use them' );
		$this->assertSame( 404, $this->request( 'POST', '/design/preview', array( 'design' => array() ) )->get_status() );
		$recipes = (array) $this->request( 'GET', '/framework' )->get_data()['recipes'];
		$this->assertArrayNotHasKey( 'centra', $recipes, 'no Histeon CSS recipes' );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		add_filter( 'show_admin_bar', '__return_true' );
		\XB\Framework\HistosCss\Panel::admin_bar( $bar );
		remove_filter( 'show_admin_bar', '__return_true' );
		$this->assertNull( $bar->get_node( 'xb-histos-css' ), 'no panel' );

		Registry::set( HistosCss::ID, true );
		$this->assertTrue( HistosCss::active() );
		$this->assertArrayHasKey( 'centra', (array) $this->request( 'GET', '/framework' )->get_data()['recipes'] );
	}

	public function test_switching_runs_the_module_steps_once_and_boots_only_what_is_on(): void {
		$module = Registry::get( 'test-mod' );
		$this->assertNotNull( $module );
		$this->assertSame( Registry::OFF, Registry::status( $module ), 'its default' );
		Registry::boot();
		$this->assertNotContains( 'boot', $this->calls, 'off: not loaded' );
		Registry::set( 'test-mod', true );
		Registry::set( 'test-mod', true );
		$this->assertSame( array( 'enable' ), $this->calls, 'turning on twice runs its step once' );
		Registry::boot();
		Registry::boot();
		$this->assertSame( array( 'enable', 'boot' ), $this->calls, 'loaded once' );
		Registry::set( 'test-mod', false );
		$this->assertSame( array( 'enable', 'boot', 'disable' ), $this->calls );
		$this->assertSame( array( 'test-mod' => false ), array_intersect_key( (array) get_option( Registry::OPTION ), array( 'test-mod' => 1 ) ) );
	}

	public function test_a_separate_plugin_keeps_its_module_still(): void {
		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( 'test-alone/test-alone.php' ) ) );
		$module = Registry::get( 'test-alone' );
		$this->assertNotNull( $module );
		$this->assertSame( Registry::STANDALONE, Registry::status( $module ) );
		$this->assertFalse( Registry::enabled( 'test-alone' ), 'never two copies of the same code' );
		$this->expectException( \InvalidArgumentException::class );
		Registry::set( 'test-alone', true );
	}

	public function test_requirements_and_modules_not_bundled_yet(): void {
		$future = Registry::get( 'test-future' );
		$this->assertNotNull( $future );
		$this->assertSame( Registry::PHP, Registry::status( $future ) );
		try {
			Registry::set( 'test-future', true );
			$this->fail( 'a module whose PHP is missing cannot be turned on' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		$static = Registry::get( 'static-bridge' );
		$this->assertNotNull( $static );
		// Bundled once scripts/modules.sh has put its release in modules/: then off (or on) like any module.
		$expected = $static->bundled ? array( Registry::OFF, Registry::ON, Registry::STANDALONE, Registry::PHP ) : array( Registry::PLANNED, Registry::STANDALONE, Registry::PHP );
		$this->assertContains( Registry::status( $static ), $expected, $static->bundled ? 'bundled' : 'not bundled yet' );
		$this->expectException( \InvalidArgumentException::class );
		Registry::set( 'unknown-module', true );
	}

	public function test_the_screen_lists_the_modules_with_a_switch_each(): void {
		wp_set_current_user( 1 );
		ob_start();
		\XB\Admin\ModulesScreen::render();
		$html = (string) ob_get_clean();
		foreach ( array( 'Histeon CSS', 'Static Bridge', 'Lingua Bridge' ) as $name ) {
			$this->assertStringContainsString( $name, $html );
		}
		$this->assertStringContainsString( 'role="switch"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}
}
