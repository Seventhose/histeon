<?php
/**
 * Removing the plugin removes its settings (the design parameters).
 *
 * @package HistosCss
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'histos_css_design' );
