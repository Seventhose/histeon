<?php
/**
 * XB Blank: the theme adds nothing; the XB plugin renders everything. Kept for compatibility: Fondamenta takes its
 * place with the same templates (theme/build.sh), and the plugin carries the site's templates across the switch.
 *
 * @package XB_Blank
 */

declare(strict_types=1);

add_action(
	'after_setup_theme',
	static function (): void {
		remove_theme_support( 'core-block-patterns' );
		load_theme_textdomain( 'xb-blank', get_template_directory() . '/languages' );
	}
);

// Written in Italian; every other language without a file of its own gets English (as the XB plugin does).
add_filter(
	'load_translation_file',
	static function ( string $file, string $domain, string $locale = '' ): string {
		if ( 'xb-blank' !== $domain || '' === $locale || str_starts_with( $locale, 'it' ) || is_readable( $file ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/' . preg_quote( $locale, '/' ) . '(\.l10n\.php|\.mo)$/', 'en_US$1', $file );
		return is_readable( $english ) ? $english : $file;
	},
	10,
	3
);

add_filter( 'should_load_remote_block_patterns', '__return_false' );

add_action(
	'admin_notices',
	static function (): void {
		if ( ! defined( 'XB_VERSION' ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'XB Blank richiede il plugin XB attivo: senza, i template non mostrano nulla.', 'xb-blank' ) . '</p></div>';
			}
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'switch_themes' ) || ! $screen instanceof WP_Screen || ! in_array( $screen->id, array( 'themes', 'dashboard' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Fondamenta prende il posto di XB Blank, con gli stessi template. Quando la attivi, i template e le parti che hai personalizzato in questo sito passano a Fondamenta.', 'xb-blank' ) . '</p>';
		if ( wp_get_theme( 'fondamenta' )->exists() ) {
			$url = wp_nonce_url( admin_url( 'themes.php?action=activate&stylesheet=fondamenta' ), 'switch-theme_fondamenta' );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Attiva Fondamenta', 'xb-blank' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'Installala da Aspetto → Temi → Aggiungi tema → Carica tema, con il file fondamenta-….zip della stessa release del plugin.', 'xb-blank' ) . '</p>';
		}
		echo '</div>';
	}
);
