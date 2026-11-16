<?php
/**
 * Removes what XB stores, when the plugin is deleted from the Plugins screen.
 *
 * Kept: post content (pages built with XB stay in the database; without the plugin their blocks render nothing,
 * reinstalling XB brings them back) and component posts (wp_block).
 * Removed: XB tables (history, releases, audit, deploys), settings and secrets, form submissions (personal data),
 * scripts and component metadata, static bundles, scheduled events, capabilities.
 *
 * @package XB
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'commits', 'refs', 'releases', 'release_items', 'audit', 'deploys' ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'xb_' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// Explicit names only: other plugins may use an "xb_" prefix too.
$xb_options = array( 'xb_cms_waiting_language_links', 'xb_version', 'xb_db_version', 'xb_framework', 'xb_styles', 'xb_global_styles', 'xb_css_media_gen', 'xb_component_refs', 'xb_loop_presets', 'xb_current_release', 'xb_preview_secret', 'xb_cloudflare', 'xb_deploy_on_release', 'xb_forms_secret', 'xb_forms_configured', 'xb_framework_loading', 'xb_css_safelist', 'xb_canvas_origins', 'xb_signing_key', 'xb_trusted_keys', 'xb_require_signature', 'xb_css_global_file', 'xb_reset', 'xb_etch_import', 'xb_loop_types', 'xb_loop_taxonomies', 'xb_sources', 'xb_content', 'xb_content_rewrite', 'xb_public_fields', 'xb_named_queries', 'xb_local_fonts', 'xb_css_recipes', 'xb_acss_compat', 'xb_facets', 'xb_library_sections', 'xb_remote_inbound', 'xb_remote_outbound', 'xb_remote_site_id', 'xb_cms_link', 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_tombstones', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_query_ids', 'xb_page_profile_source_ids', 'xb_page_profile_relational_bases', 'xb_page_profile_default_locale', 'xb_cms_pending_contents', 'xb_scheduled_release', 'xb_scheduled_release_last' );
foreach ( $xb_options as $xb_option ) {
	delete_option( $xb_option );
}
// Network-level: the update source decides which code the whole installation receives.
delete_site_option( 'xb_updates' );
delete_site_transient( 'xb_updates_latest' );
// Rows XB creates with a known prefix (replay markers, rate counters, per-user transients and their timeouts).
foreach ( array( 'xb_form_seen_', 'xb_forms_rate_', '_transient_xb_settings_error_', '_transient_timeout_xb_settings_error_', '_transient_xb_migration_report_', '_transient_timeout_xb_migration_report_', '_transient_xb_etch_report_', '_transient_timeout_xb_etch_report_', 'xb_remote_operation_', 'xb_remote_project_lock_', '_transient_xb_cms_', '_transient_timeout_xb_cms_' ) as $xb_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $xb_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$submissions = get_posts(
	array(
		'post_type'   => 'xb_submission',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $submissions as $id ) {
	wp_delete_post( (int) $id, true );
}

foreach ( array( '_xb_script', 'xb_component_key', 'xb_component_schema', '_xb_email_hash', '_xb_page_profile_pending_fields', '_xb_page_profile_pending_media', '_xb_page_profile_pending_file', '_xb_page_profile_creating' ) as $meta_key ) {
	delete_metadata( 'post', 0, $meta_key, '', true );
}
foreach ( array( 'xb_shortcuts', 'xb_connector_seed', 'xb_connector_secret' ) as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}

foreach ( array( 'xb_static_deploy', 'xb_forms_cleanup' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
// The scheduled release's event carries the record's id: every one goes, whatever its argument.
wp_unschedule_hook( 'xb_scheduled_release' );

foreach ( wp_roles()->role_objects as $role ) {
	foreach ( array( 'xb_build', 'xb_publish', 'xb_manage_deploy', 'xb_edit_content' ) as $cap ) {
		$role->remove_cap( $cap );
	}
}

remove_role( 'xb_client' );

foreach ( array( 'xb-static', 'xb-cache' ) as $xb_dir ) {
	$xb_path = trailingslashit( wp_upload_dir()['basedir'] ) . $xb_dir;
	if ( ! is_dir( $xb_path ) ) {
		continue;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $xb_path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		if ( $item instanceof SplFileInfo ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	rmdir( $xb_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
