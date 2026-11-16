<?php
/**
 * Plugin Name:       Histeon
 * Description:       Histeon (from ἱστός, the loom and the web woven on it): code-first visual builder on top of Gutenberg. Open source, no license keys, no telemetry.
 * Version:           0.5.14
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/Seventhose/histeon
 * Text Domain:       xb
 *
 * @package XB
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

const XB_VERSION = '0.5.14';
const XB_FILE    = __FILE__;

// Doc 23 item 125: on an older PHP Histeon does not load, and says so in the administration instead of failing
// somewhere later. WordPress refuses to install or update it there, but a site can lose its PHP version afterwards.
if ( version_compare( PHP_VERSION, '8.3', '<' ) ) { // @phpstan-ignore if.alwaysFalse (analysed on 8.3+, the point is older runtimes)
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( sprintf( /* translators: %s: PHP version */ __( 'Histeon richiede PHP 8.3 o superiore, e questo sito usa PHP %s: Histeon è fermo finché PHP non viene aggiornato, e le pagine costruite con Histeon non si vedono come dovrebbero. Chiedi all’hosting PHP 8.3 o superiore.', 'xb' ), PHP_VERSION ) )
				);
			}
		}
	);
	return;
}

// Releases ship without Composer's autoloader; the source tree is PSR-4 under src/.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'XB\\' ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 3 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\XB\Capabilities::grant();
		\XB\Versioning\Schema::install();
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\XB\Modules\StaticBridge::histos_deactivated();
		// A scheduled release does not survive a deactivation (docs/spec/versioning.md §11.4 i).
		\XB\Versioning\Scheduled::deactivated();
	}
);
// Modules that load like plugins (ADR 0015): before plugins_loaded, as WordPress would load them.
\XB\Modules\FatalGuard::register();
\XB\Modules\Registry::load_early();
\XB\Plugin::boot();

if ( ! function_exists( 'xb_last_checks' ) ) {
	/**
	 * The outcome of the page checks the last release published from the builder went out with (V4.1), read-only:
	 * null, or `version`, `at` (ISO 8601, UTC, the server's), `errors`, `warnings`, `rules` (rule => count) and, when
	 * the round said it, `pages` (`checked` of `total` pages of the release: with fewer checked, the counts say nothing
	 * of the others), checked again as it is read. A reminder written by the browser of whoever published, not a proof:
	 * never base a permission, a block or a statement of conformity on it.
	 *
	 * @return array{version: string, at: string, errors: int, warnings: int, rules: array<string, int>, pages?: array{checked: int, total: int}}|null
	 */
	function xb_last_checks(): ?array {
		return \XB\Versioning\ReleaseChecks::last();
	}
}
