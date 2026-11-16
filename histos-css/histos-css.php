<?php
/**
 * Plugin Name: Histeon CSS
 * Description: Il framework CSS libero di Histeon, per qualsiasi sito WordPress: token fluidi, palette OKLCH, classi, recipes, il pannello in popup (Alt+Maiusc+H) e la palette nell'editor a blocchi. Nessuna licenza.
 * Version: 0.3.2
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author: Histeon
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: histos-css
 *
 * @package HistosCss
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HISTOS_CSS_VERSION = '0.3.1';
const HISTOS_CSS_FILE    = __FILE__;

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'HistosCss\\' ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'HistosCss\\' ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

add_action( 'plugins_loaded', array( \HistosCss\Plugin::class, 'boot' ) );

// Written in Italian; every other language without a file of its own gets English (languages/histos-css-en_US).
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'histos-css', false, dirname( plugin_basename( HISTOS_CSS_FILE ) ) . '/languages' );
	},
	0
);
add_filter(
	'load_translation_file',
	static function ( string $file, string $domain, string $locale = '' ): string {
		if ( 'histos-css' !== $domain || '' === $locale || str_starts_with( $locale, 'it' ) || 'en_US' === $locale || is_readable( $file ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $locale, '/' ) . '(\.l10n\.php|\.mo)$/', '-en_US$1', $file );
		return is_readable( $english ) ? $english : $file;
	},
	10,
	3
);
