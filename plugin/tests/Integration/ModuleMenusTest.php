<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\Menu;
use XB\Admin\ModuleMenus;

/**
 * The modules' menus under Histeon (ADR 0015, M6): the entries move, the pages stay as the module registered them.
 * WordPress picks the page and the hook its scripts wait for the way admin.php does; that is what is checked here.
 */
final class ModuleMenusTest extends IntegrationTestCase {

	/** @var array<string, mixed> */
	private array $globals = array();

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages' ) as $name ) {
			$this->globals[ $name ] = $GLOBALS[ $name ] ?? null;
			$GLOBALS[ $name ]       = array();
		}
	}

	protected function tearDown(): void {
		foreach ( $this->globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		parent::tearDown();
	}

	/** What admin.php does to find the hook of ?page=$page. */
	private static function hook( string $page ): string {
		$hook = get_plugin_page_hook( $page, 'admin.php' );
		return (string) ( $hook ?? get_plugin_page_hook( $page, $page ) );
	}

	public function test_entries_move_under_histos_and_pages_keep_their_hooks(): void {
		add_menu_page( 'Histeon', 'Histeon', 'xb_build', Menu::SLUG, '__return_null' );
		// A module as Static Bridge and Lingua Bridge register themselves: a top-level page and its sub-pages.
		add_menu_page( 'Modulo finto', 'Modulo finto', 'manage_options', 'xb-modulo', '__return_null' );
		add_submenu_page( 'xb-modulo', 'Impostazioni del modulo', 'Impostazioni', 'manage_options', 'xb-modulo-settings', '__return_null' );
		$before = array( self::hook( 'xb-modulo' ), self::hook( 'xb-modulo-settings' ) );
		$this->assertSame( 'toplevel_page_xb-modulo', $before[0] );

		ModuleMenus::gather( array( 'xb-modulo' => 'Modulo' ) );

		$this->assertNotContains( 'xb-modulo', array_column( (array) $GLOBALS['menu'], 2 ), 'its own top-level entry is gone' );
		$under = array_column( (array) $GLOBALS['submenu'][ Menu::SLUG ], 2 );
		$this->assertContains( 'xb-modulo', $under );
		$this->assertContains( 'xb-modulo-settings', $under );
		$this->assertSame( $before, array( self::hook( 'xb-modulo' ), self::hook( 'xb-modulo-settings' ) ), 'same page hooks: the module\'s scripts still load' );
	}

	public function test_a_module_not_run_by_histos_keeps_its_menu(): void {
		add_menu_page( 'Altro', 'Altro', 'manage_options', 'xb-altro', '__return_null' );
		ModuleMenus::gather( array() );
		$this->assertContains( 'xb-altro', array_column( (array) $GLOBALS['menu'], 2 ) );
		$this->assertSame( array(), array_diff_key( ModuleMenus::slugs(), array( 'lingua-bridge' => 1, 'static-bridge' => 1 ) ) );
	}

	public function test_who_cannot_open_histos_keeps_the_module_menu(): void {
		$translator = $this->user( 'editor' );
		wp_set_current_user( $translator );
		add_menu_page( 'Lingue', 'Lingue', 'edit_posts', 'xb-lingue', '__return_null' );
		ModuleMenus::gather( array( 'xb-lingue' => 'Lingue' ) );
		$this->assertContains( 'xb-lingue', array_column( (array) $GLOBALS['menu'], 2 ), 'an editor without xb_build still finds it' );
	}

	public function test_the_admin_menu_hook_runs_it_without_errors(): void {
		add_menu_page( 'Histeon', 'Histeon', 'xb_build', Menu::SLUG, '__return_null' );
		do_action( 'admin_menu', '' ); // As wp-admin/includes/menu.php fires it.
		$this->addToAssertionCount( 1 );
	}
}
