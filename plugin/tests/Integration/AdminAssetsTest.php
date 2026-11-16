<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\Menu;

/** The admin stylesheet on every Histeon screen, whatever the menu is called. */
final class AdminAssetsTest extends IntegrationTestCase {

	public function test_own_screens_are_recognised_by_slug_not_by_title(): void {
		foreach ( array( 'toplevel_page_xb', 'histos_page_xb-pages', 'histos_page_xb-releases', 'xb_page_xb-settings', 'whatever_page_xb-history' ) as $hook ) {
			$this->assertTrue( Menu::is_own_screen( $hook ), $hook );
		}
		foreach ( array( 'toplevel_page_core-framework', 'edit.php', 'histos_page_other', 'settings_page_xbox' ) as $hook ) {
			$this->assertFalse( Menu::is_own_screen( $hook ), $hook );
		}
		// The real hooks WordPress gave the submenus.
		global $_registered_pages;
		foreach ( array( 'xb-pages', 'xb-releases', 'xb-settings', 'xb-history', 'xb-templates' ) as $slug ) {
			$hook = get_plugin_page_hookname( $slug, Menu::SLUG );
			$this->assertTrue( Menu::is_own_screen( $hook ), $hook );
		}
		unset( $_registered_pages );
	}
}
