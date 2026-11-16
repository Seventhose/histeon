<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Media;

use XB\Rest\Controller;

/**
 * Where a file of the media library is used (E1, docs/spec/media.md): the contents that name it, at any of its
 * sizes and also inside block attributes (where slashes are escaped), and the posts that have it as featured
 * image. For whoever can upload files; contents they cannot edit are only counted.
 */
final class Usage {

	/** At most this many contents are read to confirm a match: enough to say where a file is used. */
	private const CANDIDATES = 200;

	public static function register(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/media/(?P<id>\d+)/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
			)
		);
	}

	public static function rest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'xb_not_found', __( 'File inesistente.', 'xb' ), array( 'status' => 404 ) );
		}
		$items  = array();
		$hidden = 0;
		$found  = self::find( $id );
		foreach ( $found as $post_id => $featured ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$hidden;
				continue;
			}
			$type    = get_post_type_object( $post->post_type );
			$items[] = array(
				'id'       => $post_id,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'type'     => $type instanceof \WP_Post_Type ? (string) $type->labels->singular_name : $post->post_type,
				'status'   => $post->post_status,
				'featured' => $featured,
				'link'     => (string) get_edit_post_link( $post_id, 'raw' ),
			);
		}
		$response = new \WP_REST_Response(
			array(
				'items'  => $items,
				'hidden' => $hidden,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * The contents that use the file: post id => whether it is (also) their featured image.
	 *
	 * @return array<int, bool>
	 */
	public static function find( int $id ): array {
		global $wpdb;
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$out  = array();
		if ( '' !== $file ) {
			$dot  = strrpos( $file, '.' );
			$stem = false === $dot ? $file : substr( $file, 0, $dot );
			$ext  = false === $dot ? '' : substr( $file, $dot + 1 );
			// Its sizes (-300x150), WordPress's own scaled copy, an edited copy (-e123), and the modern formats it may be served in.
			$pattern  = '#' . preg_quote( $stem, '#' ) . '(?:-scaled|-e\d+)?(?:-\d+x\d+)?\.(?:' . preg_quote( $ext, '#' ) . '|webp|avif)(?![a-z0-9])#i';
			$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a search across contents, not cacheable.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision') AND post_status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY post_modified DESC LIMIT %d',
					array_merge( $statuses, array( '%' . $wpdb->esc_like( $stem ) . '%', '%' . $wpdb->esc_like( str_replace( '/', '\\/', $stem ) ) . '%', self::CANDIDATES ) )
				)
			);
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( 1 === preg_match( $pattern, str_replace( '\\/', '/', (string) $row->post_content ) ) ) {
					$out[ (int) $row->ID ] = false;
				}
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$featured = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT %d", (string) $id, self::CANDIDATES ) );
		foreach ( $featured as $post_id ) {
			if ( 'revision' !== get_post_type( (int) $post_id ) ) {
				$out[ (int) $post_id ] = true;
			}
		}
		return $out;
	}
}
