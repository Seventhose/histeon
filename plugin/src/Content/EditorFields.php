<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Content;

use XB\Plugin;

/**
 * The "Campi" panel of the block editor (assets/editor/fields.js) for the fields defined in the Content Hub —
 * docs/spec/content.md §6. It is for whoever edits the post, with or without access to the builder: the values
 * are post meta, saved with the post, sanitized by type and allowed by edit_post (ContentTypes::register_field).
 */
final class EditorFields {

	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$registered = get_registered_meta_keys( 'post', $post->post_type );
		$fields     = array_values( array_filter( ContentTypes::fields_for( $post->post_type ), static fn ( array $f ): bool => isset( $registered[ $f['name'] ] ) ) );
		if ( array() === $fields || ! post_type_supports( $post->post_type, 'custom-fields' ) ) {
			return;
		}
		wp_enqueue_script(
			'xb-fields',
			plugin_dir_url( XB_FILE ) . 'assets/editor/fields.js',
			array( 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			Plugin::asset_version( 'assets/editor/fields.js' ),
			array( 'in_footer' => true )
		);
		\XB\I18n::script( 'xb-fields' );
		wp_add_inline_style( 'wp-components', '.xb-fields{display:grid;gap:16px}.xb-field-image{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.xb-field-image img{width:64px;height:64px;object-fit:cover;border-radius:2px;border:1px solid #ddd}.xb-field-rows{display:grid;gap:8px}.xb-field-row{display:grid;gap:8px;padding:8px;border:1px solid #ddd;border-radius:2px}.xb-field-row-head{display:flex;align-items:center;gap:2px}.xb-field-row-head strong{margin-right:auto}' );
		wp_add_inline_script(
			'xb-fields',
			'window.xbFields = ' . wp_json_encode(
				array(
					'title'   => __( 'Campi', 'xb' ),
					'fields'  => $fields,
					'maxRows' => ContentTypes::MAX_ROWS,
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);
	}
}
