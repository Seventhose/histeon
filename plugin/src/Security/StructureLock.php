<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

use XB\Spec;

/**
 * A page whose structure is locked (lista della spesa 138): whoever builds with Histeon (`xb_build`) locks it from the
 * block editor; anyone else who may edit it (an Editor, an Author) changes only texts and pictures there, as a client
 * does in the builder. The block editor opens content-only for them, and the server holds the same rule as client
 * mode (ContentOnly::check): blocks, order and attributes stay; texts with the same expressions and the pictures of
 * an <img> may change. A write that breaks it is refused (REST) or keeps the stored content (every other way in).
 */
final class StructureLock {

	public const META = 'xb_structure_locked';

	public static function register(): void {
		foreach ( array( 'page', 'post' ) as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					// Only who builds locks or unlocks: the lock is what keeps the others' hands off the structure.
					'auth_callback'     => static fn (): bool => current_user_can( 'xb_build' ),
					'sanitize_callback' => static fn ( mixed $value ): bool => (bool) $value,
				)
			);
		}
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_structure' ), 6, 2 );
	}

	public static function rest(): void {
		foreach ( array( 'page', 'post' ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( self::class, 'refuse' ), 10, 2 );
		}
	}

	public static function locked( int $id ): bool {
		return $id > 0 && (bool) get_post_meta( $id, self::META, true );
	}

	/** Whether the current user is held to the lock on this post. */
	public static function applies( int $id ): bool {
		return self::locked( $id ) && ! current_user_can( 'xb_build' );
	}

	/** Why this write changes a locked structure, or '' when it does not. */
	public static function violation( int $id, string $new ): string {
		if ( ! self::applies( $id ) ) {
			return '';
		}
		$post = get_post( $id );
		$old  = $post instanceof \WP_Post ? $post->post_content : '';
		if ( ! Spec::has_xb_blocks( $old ) && ! Spec::has_xb_blocks( $new ) ) {
			return '';
		}
		if ( $old === $new || serialize_blocks( parse_blocks( $old ) ) === serialize_blocks( parse_blocks( $new ) ) ) {
			return '';
		}
		return ContentOnly::check( $old, $new );
	}

	/**
	 * @param \stdClass|\WP_Error $prepared
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse( $prepared, \WP_REST_Request $request ) {
		unset( $request );
		if ( $prepared instanceof \WP_Error || ! property_exists( $prepared, 'post_content' ) ) {
			return $prepared;
		}
		$reason = self::violation( isset( $prepared->ID ) ? (int) $prepared->ID : 0, (string) $prepared->post_content );
		if ( '' !== $reason ) {
			/* translators: %s: what changed */
			return new \WP_Error( 'xb_structure_locked', sprintf( __( 'La struttura di questa pagina è bloccata: qui si cambiano solo testi e immagini (%s).', 'xb' ), $reason ), array( 'status' => 403 ) );
		}
		return $prepared;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function keep_structure( array $data, array $postarr ): array {
		$id  = isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$new = is_string( $data['post_content'] ?? null ) ? wp_unslash( $data['post_content'] ) : '';
		if ( '' !== self::violation( $id, $new ) ) {
			$post                 = get_post( $id );
			$data['post_content'] = wp_slash( $post instanceof \WP_Post ? $post->post_content : '' );
		}
		return $data;
	}
}
