<?php
/**
 * Without the builder: the content types and taxonomies the site declares (option `xb_content`, which the builder
 * writes and keeps), registered with the same arguments, so their contents keep their addresses — doc 25 of
 * Histeon Publish, §8. Fields are not registered here: their values stay in post meta, where the legami read them,
 * and the field plugin the owner chooses will edit them.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

add_action(
	'init',
	static function (): void {
		$declared = get_option( 'xb_content', array() );
		if ( ! is_array( $declared ) ) {
			return;
		}
		$text = static fn ( mixed $value, string $fallback ): string => is_string( $value ) && '' !== $value ? $value : $fallback;
		foreach ( is_array( $declared['types'] ?? null ) ? $declared['types'] : array() as $slug => $type ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/', $slug ) || ! is_array( $type ) || post_type_exists( $slug ) ) {
				continue;
			}
			$public   = ! empty( $type['public'] );
			$base     = $text( $type['slug'] ?? null, $slug );
			$supports = is_array( $type['supports'] ?? null ) ? array_values( array_filter( $type['supports'], 'is_string' ) ) : array( 'title', 'editor' );
			register_post_type(
				$slug,
				array(
					'labels'              => array(
						'name'          => $text( $type['plural'] ?? null, $slug ),
						'singular_name' => $text( $type['singular'] ?? null, $slug ),
					),
					'public'              => $public,
					'publicly_queryable'  => $public,
					'exclude_from_search' => ! $public,
					'show_in_nav_menus'   => $public,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_rest'        => true,
					'has_archive'         => $public && ! empty( $type['archive'] ) ? $base : false,
					'rewrite'             => $public ? array(
						'slug'       => $base,
						'with_front' => false,
					) : false,
					'query_var'           => $public,
					'hierarchical'        => ! empty( $type['hierarchical'] ),
					'menu_icon'           => $text( $type['icon'] ?? null, 'dashicons-admin-post' ),
					'supports'            => array() === $supports ? array( 'title' ) : $supports,
					'capability_type'     => $text( $type['capability'] ?? null, 'post' ),
					'map_meta_cap'        => true,
					'delete_with_user'    => false,
				)
			);
		}
		foreach ( is_array( $declared['taxonomies'] ?? null ) ? $declared['taxonomies'] : array() as $slug => $taxonomy ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $slug ) || ! is_array( $taxonomy ) || taxonomy_exists( $slug ) ) {
				continue;
			}
			$public = ! empty( $taxonomy['public'] );
			$types  = is_array( $taxonomy['types'] ?? null ) ? array_values( array_filter( $taxonomy['types'], 'is_string' ) ) : array();
			register_taxonomy(
				$slug,
				$types,
				array(
					'labels'             => array(
						'name'          => $text( $taxonomy['plural'] ?? null, $slug ),
						'singular_name' => $text( $taxonomy['singular'] ?? null, $slug ),
					),
					'public'             => $public,
					'publicly_queryable' => $public,
					'show_ui'            => true,
					'show_in_rest'       => true,
					'show_admin_column'  => true,
					'show_in_nav_menus'  => $public,
					'hierarchical'       => ! empty( $taxonomy['hierarchical'] ),
					'query_var'          => $public,
					'rewrite'            => $public ? array(
						'slug'         => $text( $taxonomy['slug'] ?? null, $slug ),
						'with_front'   => false,
						'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
					) : false,
				)
			);
		}
	},
	9
);
