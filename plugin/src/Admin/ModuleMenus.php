<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Modules\Registry;

/**
 * The modules' menus under Histeon — ADR 0015 (M6), docs/spec/modules.md §Menu. A module that Histeon runs (Lingua
 * Bridge, Static Bridge) adds its own top-level menu; here its entries move under Histeon, at the end of its menu. Only the
 * menu moves: each page stays registered as the module registered it, so its address, its screen id and the hook
 * its scripts wait for (`toplevel_page_static-bridge`) are unchanged. A module running as a separate plugin keeps
 * its own menu, and so does a user who cannot open Histeon.
 */
final class ModuleMenus {

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menus' ), 999 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
	}

	/**
	 * Top-level menus of the modules Histeon runs now: slug => module name.
	 *
	 * @return array<string, string>
	 */
	public static function slugs(): array {
		$out = array();
		foreach ( Registry::all() as $module ) {
			if ( '' !== $module->menu && Registry::ON === Registry::status( $module ) ) {
				$out[ $module->menu ] = $module->name;
			}
		}
		return $out;
	}

	/** The admin_menu hook (which passes WordPress's own argument: '' or a context). */
	public static function menus(): void {
		self::gather();
	}

	/**
	 * Moves those menus under Histeon: the module's first page with its name, its other pages indented below it.
	 *
	 * @param array<string, string>|null $slugs slug => name (default: slugs()).
	 */
	public static function gather( ?array $slugs = null ): void {
		global $menu, $submenu;
		// Who cannot open Histeon (a translator with edit_posts only) keeps the module's own menu.
		if ( ! current_user_can( 'xb_build' ) ) {
			return;
		}
		foreach ( $slugs ?? self::slugs() as $slug => $name ) {
			$top = null;
			foreach ( (array) $menu as $item ) {
				if ( is_array( $item ) && ( $item[2] ?? null ) === $slug ) {
					$top = $item;
					break;
				}
			}
			if ( null === $top ) {
				continue;
			}
			$children = is_array( $submenu[ $slug ] ?? null ) ? $submenu[ $slug ] : array();
			remove_menu_page( $slug );
			add_submenu_page( Menu::SLUG, (string) ( $top[3] ?? $name ), $name, (string) $top[1], $slug );
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || $slug === ( $child[2] ?? null ) ) {
					continue;
				}
				add_submenu_page( Menu::SLUG, (string) ( $child[3] ?? $child[0] ), '– ' . (string) $child[0], (string) $child[1], (string) $child[2] );
			}
		}
	}

	/**
	 * On a module's page, Histeon is the open menu.
	 *
	 * @param mixed $parent
	 */
	public static function parent_file( mixed $parent ): mixed {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which menu to highlight.
		if ( '' === $page ) {
			return $parent;
		}
		foreach ( array_keys( self::slugs() ) as $slug ) {
			if ( $page === $slug || str_starts_with( $page, $slug . '-' ) ) {
				return Menu::SLUG;
			}
		}
		return $parent;
	}
}
