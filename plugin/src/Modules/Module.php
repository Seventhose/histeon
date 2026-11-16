<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Modules;

/**
 * A module of Histeon — ADR 0015: a part that can be turned on and off (Histeon CSS, Static Bridge, Lingua Bridge).
 * Callables are given by the registry; a module that is not bundled yet (`bundled` false) only describes itself.
 */
final class Module {

	/**
	 * @param string             $id          Stable id (`histos-css`).
	 * @param string             $name        Shown name.
	 * @param string             $description One sentence: what it does.
	 * @param string             $icon        Dashicon name, without the prefix.
	 * @param string             $version     Version of the bundled code ('' when not bundled).
	 * @param string             $php         Minimum PHP version.
	 * @param bool               $bundled     Its code ships with Histeon.
	 * @param bool               $default_on  State before anyone chooses.
	 * @param string             $standalone  Plugin file of the separate plugin (`static-bridge/static-bridge.php`), or ''.
	 * @param string             $settings    Admin URL of its settings, or ''.
	 * @param (callable(): void)|null $boot    Loads it (only when on).
	 * @param (callable(): void)|null $enable  Runs once when it is turned on (like a plugin's activation).
	 * @param (callable(): void)|null $disable Runs once when it is turned off (never deletes data).
	 * @param string             $logo        URL of its mark (docs/brand.md), or '' for the dashicon.
	 * @param string             $menu        Slug of the top-level admin menu it adds when it runs, gathered under Histeon (ModuleMenus).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description,
		public readonly string $icon,
		public readonly string $version,
		public readonly string $php,
		public readonly bool $bundled,
		public readonly bool $default_on,
		public readonly string $standalone = '',
		public readonly string $settings = '',
		private readonly mixed $boot = null,
		private readonly mixed $enable = null,
		private readonly mixed $disable = null,
		public readonly string $logo = '',
		public readonly string $menu = '',
	) {}

	public function boot(): void {
		if ( is_callable( $this->boot ) ) {
			( $this->boot )();
		}
	}

	public function enable(): void {
		if ( is_callable( $this->enable ) ) {
			( $this->enable )();
		}
	}

	public function disable(): void {
		if ( is_callable( $this->disable ) ) {
			( $this->disable )();
		}
	}

	/** The PHP it needs is there. */
	public function supported(): bool {
		return version_compare( PHP_VERSION, $this->php, '>=' );
	}
}
