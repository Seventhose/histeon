<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Media\Collections;

/**
 * The media library for an agent working through the Abilities (doc 26 voce 210): list with filters, alternative
 * text, collections. Uploading and compressing need the builder's encoder, so they live in the builder's public API
 * (window.xb), not here. Deleting a file is never offered: WordPress has no bin for media.
 */
final class MediaTools {

	public const PER_PAGE = 40;

	/** MIME filters by kind, as the builder's media manager uses them. */
	private const KINDS = array(
		'image' => 'image',
		'video' => 'video',
		'audio' => 'audio',
		'pdf'   => 'application/pdf',
	);

	/**
	 * @param array<string, mixed> $input `{search?, type?, collection?, missing_alt?, page?}`; collection 0 = none.
	 * @return array{items: list<array<string, mixed>>, pages: int}
	 */
	public static function list( array $input ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
			's'              => mb_substr( (string) ( $input['search'] ?? '' ), 0, 100 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$kind = (string) ( $input['type'] ?? '' );
		if ( isset( self::KINDS[ $kind ] ) ) {
			$args['post_mime_type'] = self::KINDS[ $kind ];
		}
		if ( array_key_exists( 'collection', $input ) ) {
			$collection        = (int) $input['collection'];
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one filter the agent asked for.
				0 === $collection
					? array(
						'taxonomy' => Collections::TAXONOMY,
						'operator' => 'NOT EXISTS',
					)
					: array(
						'taxonomy'         => Collections::TAXONOMY,
						'terms'            => array( $collection ),
						'include_children' => false,
					),
			);
		}
		if ( ! empty( $input['missing_alt'] ) ) {
			$args['post_mime_type'] = 'image';
			$args['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the images to fix.
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_attachment_image_alt',
					'value' => '',
				),
			);
		}
		$query = new \WP_Query( $args );
		return array(
			'items' => array_values( array_map( array( self::class, 'item' ), array_filter( $query->posts, static fn ( mixed $p ): bool => $p instanceof \WP_Post ) ) ),
			'pages' => (int) $query->max_num_pages,
		);
	}

	/** @return array<string, mixed> */
	public static function item( \WP_Post $post ): array {
		$meta  = wp_get_attachment_metadata( $post->ID );
		$terms = wp_get_object_terms( $post->ID, Collections::TAXONOMY, array( 'fields' => 'ids' ) );
		return array(
			'id'          => $post->ID,
			'url'         => (string) wp_get_attachment_url( $post->ID ),
			'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime'        => (string) $post->post_mime_type,
			// Old or foreign attachments may lack any of these keys, whatever the stubs say.
			'width'       => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0, // @phpstan-ignore nullCoalesce.offset
			'height'      => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0, // @phpstan-ignore nullCoalesce.offset
			'bytes'       => is_array( $meta ) ? (int) ( $meta['filesize'] ?? 0 ) : 0, // @phpstan-ignore nullCoalesce.offset
			'collections' => is_array( $terms ) ? array_map( 'intval', $terms ) : array(),
		);
	}

	/**
	 * @param array<string, mixed> $input `{id, alt}`.
	 * @return array<string, mixed>
	 */
	public static function set_alt( array $input ): array {
		$post = self::image( (int) ( $input['id'] ?? 0 ) );
		update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( mb_substr( (string) ( $input['alt'] ?? '' ), 0, 500 ) ) );
		return self::item( $post );
	}

	/**
	 * @param array<string, mixed> $input `{id, collections}`: the collections the file belongs to, all of them.
	 * @return array<string, mixed>
	 */
	public static function set_collections( array $input ): array {
		$post = self::image( (int) ( $input['id'] ?? 0 ), false );
		$ids  = array_values( array_filter( array_map( 'intval', is_array( $input['collections'] ?? null ) ? $input['collections'] : array() ), static fn ( int $id ): bool => term_exists( $id, Collections::TAXONOMY ) !== null ) );
		wp_set_object_terms( $post->ID, $ids, Collections::TAXONOMY );
		return self::item( $post );
	}

	/** @return list<array{id: int, name: string, parent: int, count: int}> */
	public static function collections(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Collections::TAXONOMY,
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'id'     => $term->term_id,
				'name'   => $term->name,
				'parent' => $term->parent,
				'count'  => $term->count,
			);
		}
		return $out;
	}

	/** A library item this user may change, or an error the ability returns. */
	private static function image( int $id, bool $only_images = true ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			throw new \InvalidArgumentException( __( 'File inesistente.', 'xb' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new \InvalidArgumentException( __( 'Non puoi modificare questo file.', 'xb' ) );
		}
		if ( $only_images && ! wp_attachment_is_image( $post ) ) {
			throw new \InvalidArgumentException( __( 'Il testo alternativo è delle immagini.', 'xb' ) );
		}
		return $post;
	}
}
