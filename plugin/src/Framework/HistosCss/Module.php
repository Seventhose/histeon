<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

use XB\Modules\Registry;

/**
 * Histeon CSS as a module of Histeon — ADR 0015 (M5). On: its classes (histos.css), the design parameters, the
 * panel, the block editor's palette and its recipes. Off: only the base --xb-* tokens stay (xb-tokens.css and the
 * Design system panel's own values), because components written with them must keep working.
 */
final class Module {

	public const ID = 'histos-css';

	public static function active(): bool {
		return Registry::enabled( self::ID );
	}

	/** Called by the registry when the module is on. */
	public static function boot(): void {
		Panel::register();
		Gutenberg::register();
	}
}
