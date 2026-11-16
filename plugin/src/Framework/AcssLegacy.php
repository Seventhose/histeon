<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

use XB\Plugin;

/**
 * Automatic.css turned off, the site unchanged — docs/spec/histos-css.md §4. Automatic.css leaves the stylesheets it
 * generated in uploads/automatic-css/. When the plugin is off, the site has not been imported into Histeon CSS
 * (or Core Framework) yet and Histeon's framework is still Automatic.css (or none, or not chosen), Histeon serves those
 * last sheets itself, in the same order: every class and variable keeps working. A reminder (LegacyReminder)
 * recommends the import; nothing forces it. Only files Automatic.css wrote under uploads, resolved with realpath
 * (Carry::files); nothing of Automatic.css's code runs.
 */
final class AcssLegacy {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 5 );
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue' ), 5 );
	}

	/** Automatic.css is off, its sheets are there, and nothing has taken its place yet. */
	public static function needed(): bool {
		if ( class_exists( '\\Automatic_CSS\\API' ) ) {
			return false;
		}
		// The site chose Histeon CSS or Core Framework in Histeon: its pages are written for that framework, and sheets
		// left by trying Automatic.css out would only restyle them (titles, links and sections of the project).
		if ( in_array( get_option( Frameworks::OPTION ), array( 'xb-tokens', 'core-framework' ), true ) ) {
			return false;
		}
		$acss = Plugin::instance()->frameworks->all()['acss'] ?? null;
		if ( null === $acss || array() === Carry::files( $acss ) ) {
			return false;
		}
		$compat = Carry::compat();
		return null === $compat || 'acss' !== $compat['source'];
	}

	public static function enqueue(): void {
		if ( ! self::needed() ) {
			return;
		}
		foreach ( Plugin::instance()->frameworks->all()['acss']->stylesheets() as $n => $url ) {
			wp_enqueue_style( 'xb-acss-legacy-' . (int) $n, $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the URL carries the file's date.
		}
	}
}
