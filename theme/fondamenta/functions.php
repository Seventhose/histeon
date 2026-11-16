<?php
/**
 * Fondamenta: the parent block theme that takes the place of XB Blank.
 *
 * Two modes, chosen by XB_VERSION (the builder plugin defines it, and plugins load before the theme):
 * - with the builder active the theme adds only its own blocks (inc/blocchi.php) and the builder renders
 *   everything else, as XB Blank did;
 * - without it the theme is «fermo»: it registers nothing yet and tells an administrator what is missing.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

add_action(
	'after_setup_theme',
	static function (): void {
		remove_theme_support( 'core-block-patterns' );
		load_theme_textdomain( 'fondamenta', get_template_directory() . '/languages' );
	}
);

// Written in Italian; every other language without a file of its own gets English (as the builder does).
add_filter(
	'load_translation_file',
	static function ( string $file, string $domain, string $locale = '' ): string {
		if ( 'fondamenta' !== $domain || '' === $locale || str_starts_with( $locale, 'it' ) || is_readable( $file ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/' . preg_quote( $locale, '/' ) . '(\.l10n\.php|\.mo)$/', 'en_US$1', $file );
		return is_readable( $english ) ? $english : $file;
	},
	10,
	3
);

// The same for the editor script's JSON file (fondamenta-<locale>-<md5>.json).
add_filter(
	'load_script_translation_file',
	static function ( string|false $file, string $handle, string $domain ): string|false {
		unset( $handle );
		if ( false === $file || 'fondamenta' !== $domain || is_readable( $file ) || 1 !== preg_match( '/-([a-z]{2,3}(?:_[A-Za-z0-9_]+)?)-[0-9a-f]{32}\.json$/', $file, $m ) || str_starts_with( $m[1], 'it' ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $m[1], '/' ) . '-([0-9a-f]{32}\.json)$/', '-en_US-$1', $file );
		return is_readable( $english ) ? $english : $file;
	},
	10,
	3
);

add_filter( 'should_load_remote_block_patterns', '__return_false' );

// Fondamenta is not on WordPress.org: a theme published there with the same slug must never be offered as its update.
add_filter(
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( ! str_contains( $url, '//api.wordpress.org/themes/update-check/' ) || ! isset( $args['body']['themes'] ) ) {
			return $args;
		}
		$themes = json_decode( (string) $args['body']['themes'], true );
		if ( is_array( $themes ) ) {
			unset( $themes['themes']['fondamenta'] );
			$args['body']['themes'] = (string) wp_json_encode( $themes );
		}
		return $args;
	},
	10,
	2
);

// The blocks are there in both modes: a page converted while the builder is still active needs them too.
require __DIR__ . '/inc/blocchi.php';
require __DIR__ . '/inc/filtri.php';
require __DIR__ . '/inc/campi.php';
require __DIR__ . '/inc/stili.php';

if ( defined( 'XB_VERSION' ) ) {
	return;
}

// Without the builder: the site's content types and taxonomies, from what the builder declared.
require __DIR__ . '/inc/tipi.php';
require __DIR__ . '/inc/comportamenti.php';

add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo fondamenta_stopped_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built.
	}
);

/**
 * The «fermo» notice: which plugin is missing and what it leaves without a page.
 */
function fondamenta_stopped_notice(): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugin = get_plugins()['xb/xb.php'] ?? null;
	$name   = is_array( $plugin ) && '' !== (string) ( $plugin['Name'] ?? '' ) ? (string) $plugin['Name'] : 'xb';
	$found  = fondamenta_stopped_counts();
	$lines  = array();
	// A site theme on top of Fondamenta brings its own templates and sheet: then only contents still made with the
	// builder's blocks are missing.
	$site_theme = is_child_theme();
	if ( 0 === $found['contents'] && $site_theme && array() === $found['types'] ) {
		return '';
	}
	if ( $found['contents'] > 0 ) {
		/* translators: %d: number of pages, posts and other contents */
		$lines[] = sprintf( _n( '%d contenuto è fatto con i suoi blocchi e ora non mostra nulla.', '%d contenuti sono fatti con i suoi blocchi e ora non mostrano nulla.', $found['contents'], 'fondamenta' ), $found['contents'] );
	}
	if ( ! $site_theme ) {
		$lines[] = __( 'I template e le parti di Fondamenta usano gli stessi blocchi: il sito pubblico resta senza testata, piè di pagina e contenuto.', 'fondamenta' );
	}
	if ( array() !== $found['types'] ) {
		/* translators: %s: names of content types, separated by commas */
		$lines[] = sprintf( __( 'I tipi di contenuto %s non sono registrati: i loro contenuti restano nel database ma non hanno un indirizzo.', 'fondamenta' ), '«' . implode( '», «', $found['types'] ) . '»' );
	}
	if ( ! $site_theme ) {
		$lines[] = __( 'Gli stili del sito non vengono stampati.', 'fondamenta' );
	}
	if ( $found['contents'] > 0 ) {
		$lines[] = __( 'Per convertire queste pagine nei blocchi di Fondamenta serve il plugin attivo: riattivalo.', 'fondamenta' );
	}

	$head = is_array( $plugin )
		/* translators: %s: plugin name */
		? sprintf( __( 'Fondamenta è ferma: il plugin «%s», che disegna le pagine di questo sito, non è attivo.', 'fondamenta' ), $name )
		: __( 'Fondamenta è ferma: il plugin che disegna le pagine di questo sito (cartella «xb») non è installato.', 'fondamenta' );
	$out = '<div class="notice notice-warning"><p><strong>' . esc_html( $head ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
	foreach ( $lines as $line ) {
		$out .= '<li>' . esc_html( $line ) . '</li>';
	}
	$out .= '</ul>';
	if ( is_array( $plugin ) && current_user_can( 'activate_plugin', 'xb/xb.php' ) ) {
		$out .= '<p><a class="button" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Vai ai plugin', 'fondamenta' ) . '</a></p>';
	}
	return $out . '</div>';
}

/**
 * What the missing plugin leaves without a page: contents still made of its blocks (templates and parts apart), and
 * the content types it registered. Read once every ten minutes: the notice shows on every admin screen.
 *
 * @return array{contents: int, types: list<string>}
 */
function fondamenta_stopped_counts(): array {
	$cached = get_transient( 'fondamenta_fermo' );
	if ( is_array( $cached ) && isset( $cached['contents'], $cached['types'] ) ) {
		return $cached;
	}
	global $wpdb;
	$contents = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count no API gives; cached below.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'wp_template', 'wp_template_part') AND post_status IN ('publish', 'private', 'future', 'draft', 'pending') AND post_content LIKE %s",
			'%' . $wpdb->esc_like( '<!-- wp:xb/' ) . '%'
		)
	);
	$declared = get_option( 'xb_content', array() );
	$types    = array();
	foreach ( is_array( $declared ) && is_array( $declared['types'] ?? null ) ? $declared['types'] : array() as $key => $type ) {
		if ( is_string( $key ) && ! post_type_exists( $key ) ) {
			$types[] = is_array( $type ) && is_string( $type['plural'] ?? null ) && '' !== $type['plural'] ? $type['plural'] : $key;
		}
	}
	$found = array(
		'contents' => $contents,
		'types'    => $types,
	);
	set_transient( 'fondamenta_fermo', $found, 10 * MINUTE_IN_SECONDS );
	return $found;
}
