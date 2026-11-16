<?php
/**
 * Plugin Name: Misura del server (solo sviluppo)
 * Description: Doc 26 di Histeon Publish, voce 223. In fondo a ogni pagina pubblica HTML scrive il tempo PHP e le query
 * al database in un commento, che `dev/bench-front.mjs` legge. Va in wp-content/mu-plugins di una copia di prova, mai
 * su un sito vero: dice a chiunque quanto lavora il server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'shutdown',
	static function (): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// A page fetched for a static copy (Histeon's exporter, a crawler): its copy has no PHP to measure.
		if ( isset( $_SERVER['HTTP_X_XB_EXPORT'] ) || false !== stripos( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 'static' ) ) {
			return;
		}
		$html = false;
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$html = false !== stripos( $header, 'text/html' );
			}
		}
		if ( ! $html ) {
			return;
		}
		global $wpdb;
		printf( "\n<!-- xb-bench php=%.1f query=%d -->", ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, (int) $wpdb->num_queries );
	},
	PHP_INT_MAX
);
