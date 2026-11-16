<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Media;

/**
 * Media collections — docs/spec/media.md. Nested folders for the media library, as a
 * private hierarchical taxonomy on attachments: no public pages, no query variable, no sitemap. Managed and
 * assigned by whoever can upload files, through WordPress's REST API (wp/v2/xb_media_collection and the
 * attachment's own field), so its permissions apply.
 */
final class Collections {

	public const TAXONOMY = 'xb_media_collection';

	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Raccolte', 'xb' ),
					'singular_name' => __( 'Raccolta', 'xb' ),
					'add_new_item'  => __( 'Nuova raccolta', 'xb' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => true,
				'show_in_rest'          => true,
				'rest_controller_class' => CollectionsController::class,
				'hierarchical'          => true,
				'query_var'             => false,
				'rewrite'               => false,
				// Count every attachment ("inherit" status), not only published posts.
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => array(
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'upload_files',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}
}
