<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\SettingsScreen;
use XB\Framework\Frameworks;
use XB\Plugin;

final class FrameworkTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Frameworks::OPTION, false );
		delete_option( Frameworks::OPTION );
		add_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( self::class, 'die_handler' ) );
		parent::tearDown();
	}

	public static function die_handler(): callable {
		return static function ( mixed $message ): void {
			throw new \RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
		};
	}

	public function test_defaults_to_xb_tokens_until_chosen(): void {
		$frameworks = Plugin::instance()->frameworks;
		$this->assertFalse( $frameworks->chosen() );
		$this->assertSame( 'xb-tokens', $frameworks->active()->id() );
		$this->assertContains( 'xb-space-m', $frameworks->active()->variables()['xb'] );
		$this->assertFalse( $frameworks->select( 'not-a-framework' ) );
	}

	public function test_core_framework_when_installed(): void {
		$frameworks = Plugin::instance()->frameworks;
		$core       = $frameworks->all()['core-framework'];
		if ( ! $core->available() || array() === $core->variables() ) {
			$this->markTestSkipped( 'Core Framework non installato o senza preset nel WordPress di sviluppo.' );
		}
		$this->assertTrue( $frameworks->select( 'core-framework' ) );
		$this->assertStringContainsString( '--xb-space-m:var(--space-m);', $frameworks->alias_css() );
		$this->assertStringContainsString( '--xb-space-xxl:var(--space-2xl);', $frameworks->alias_css() );
		$this->assertNotEmpty( $core->stylesheets() );
	}

	public function test_acss_when_installed(): void {
		$frameworks = Plugin::instance()->frameworks;
		$acss       = $frameworks->all()['acss'];
		if ( ! $acss->available() ) {
			$this->markTestSkipped( 'Automatic.css non installato nel WordPress di sviluppo.' );
		}
		$this->assertTrue( $frameworks->select( 'acss' ) );
		$this->assertStringContainsString( '--xb-space-m:var(--space-m);', $frameworks->alias_css() );
		$this->assertStringContainsString( '--xb-shadow-s:var(--box-shadow-1);', $frameworks->alias_css() );
		$this->assertContains( 'primary', $acss->variables()['acss'] );
	}

	public function test_aliases_only_point_to_existing_variables(): void {
		$frameworks = Plugin::instance()->frameworks;
		$active     = $frameworks->active()->id();
		$checked    = 0;
		foreach ( $frameworks->all() as $provider ) {
			if ( ! $provider->available() || ! $frameworks->select( $provider->id() ) ) {
				continue;
			}
			++$checked;
			preg_match_all( '/var\(--([a-z0-9-]+)\)/', $frameworks->alias_css(), $m );
			$known = array_merge( ...array_values( $provider->variables() ?: array( array() ) ) );
			foreach ( $m[1] as $variable ) {
				$this->assertContains( $variable, $known, $provider->id() );
			}
		}
		$frameworks->select( $active );
		$this->assertGreaterThan( 0, $checked, 'at least Histeon CSS is always there to check' );
	}

	public function test_rest_exposes_names_to_builders_only(): void {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/xb/v1/framework' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'xb-tokens', $response->get_data()['active']['id'] );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/xb/v1/framework' ) )->get_status() );
	}

	public function test_settings_require_nonce_and_capabilities(): void {
		$_POST['xb_framework'] = 'none';
		try {
			SettingsScreen::save();
			$this->fail( 'saved without a nonce' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'wp_die', $e->getMessage() );
		}
		$this->assertFalse( Plugin::instance()->frameworks->chosen() );

		wp_set_current_user( $this->user( 'editor' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsScreen::ACTION );
		try {
			SettingsScreen::save();
			$this->fail( 'saved without permissions' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permessi', $e->getMessage() );
		} finally {
			unset( $_POST['xb_framework'], $_REQUEST['_wpnonce'] );
		}
		$this->assertFalse( Plugin::instance()->frameworks->chosen() );
	}

	public function test_frontend_enqueues_xb_tokens_and_aliases(): void {
		Plugin::instance()->frameworks->select( 'xb-tokens' );
		Plugin::instance()->frameworks->enqueue();
		$this->assertTrue( wp_style_is( 'xb-tokens', 'enqueued' ) );
		wp_dequeue_style( 'xb-tokens' );
	}
}
