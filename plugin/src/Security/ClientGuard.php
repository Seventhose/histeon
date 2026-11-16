<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

use XB\Capabilities;
use XB\Spec;

/**
 * Client mode outside the builder (docs/spec/client-mode.md): someone with `xb_edit_content` but not `xb_build`
 * may edit a page in WordPress (title, excerpt…) but never the content of a page made with Histeon there — in the
 * block editor's code view, the classic editor, quick edit or REST. That content changes only through the builder,
 * where the server checks it (ContentOnly) and it waits in development for a release.
 */
final class ClientGuard {

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_content' ), 5, 2 );
	}

	public static function rest(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( self::class, 'refuse' ), 10, 2 );
		}
	}

	private static function stored( int $id ): string {
		$post = get_post( $id );
		return $post instanceof \WP_Post ? $post->post_content : '';
	}

	/** Whether this write changes Histeon content for a client. */
	private static function blocked( int $id, string $new ): bool {
		if ( ! Capabilities::content_only() ) {
			return false;
		}
		$old = $id > 0 ? self::stored( $id ) : '';
		// Compared as blocks: the block editor may write the same content with another spelling (title-only saves).
		$same = static fn ( string $a, string $b ): bool => $a === $b || serialize_blocks( parse_blocks( $a ) ) === serialize_blocks( parse_blocks( $b ) );
		return ( Spec::has_xb_blocks( $old ) || Spec::has_xb_blocks( $new ) ) && ! $same( $old, $new );
	}

	/**
	 * REST (the block editor): a clear refusal instead of a silent one.
	 *
	 * @param \stdClass|\WP_Error $prepared
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse( $prepared, \WP_REST_Request $request ) {
		unset( $request );
		if ( $prepared instanceof \WP_Error || ! property_exists( $prepared, 'post_content' ) ) {
			return $prepared;
		}
		$id = isset( $prepared->ID ) ? (int) $prepared->ID : 0;
		if ( self::blocked( $id, (string) $prepared->post_content ) ) {
			return new \WP_Error( 'xb_content_only', __( 'Le pagine fatte con Histeon si modificano dal builder, in modalità contenuti.', 'xb' ), array( 'status' => 403 ) );
		}
		return $prepared;
	}

	/**
	 * Every other way in (classic editor, quick edit, wp_insert_post): the stored content stays.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function keep_content( array $data, array $postarr ): array {
		$id  = isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$new = is_string( $data['post_content'] ?? null ) ? wp_unslash( $data['post_content'] ) : '';
		if ( self::blocked( $id, $new ) ) {
			$data['post_content'] = wp_slash( $id > 0 ? self::stored( $id ) : '' );
		}
		return $data;
	}
}
