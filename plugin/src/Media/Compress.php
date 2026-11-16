<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Media;

use XB\Plugin;
use XB\Rest\Controller;

/**
 * An image already in the library, made lighter where it is (doc 26 voce 209, docs/spec/media.md «Comprimere
 * un'immagine già caricata»), and the site's own compression presets.
 *
 * The builder encodes the new file (same format, so the address does not change) and sends it here. The file
 * replaces the attached one, every size WordPress made is drawn again from it under its own name (pages and
 * `srcset` written with those names keep working), and the original stays next to it until «Ripristina
 * l'originale». Only a lighter file of the same format, size and proportions is accepted.
 */
final class Compress {

	public const META    = '_xb_compressed';
	public const PRESETS = 'xb_media_presets';
	/** Formats compressed in place: what the builder can write again in the same format. */
	public const MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
	/** At most this many presets of the site. */
	public const MAX_PRESETS = 10;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_field(
			'attachment',
			'xb_compressed',
			array(
				'get_callback' => static function ( array $item ): ?array {
					if ( ! current_user_can( 'upload_files' ) ) {
						return null;
					}
					$done = get_post_meta( (int) $item['id'], self::META, true );
					return is_array( $done ) ? array(
						'before' => (int) ( $done['before'] ?? 0 ),
						'after'  => (int) ( $done['after'] ?? 0 ),
						'at'     => (int) ( $done['at'] ?? 0 ),
					) : null;
				},
				'schema'       => array(
					'type'     => array( 'object', 'null' ),
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				),
			)
		);
		$can = static fn ( \WP_REST_Request $r ): bool => current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $r['id'] );
		register_rest_route(
			Controller::NAMESPACE,
			'/media/(?P<id>\d+)/compress',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'compress' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/media/(?P<id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'restore' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/media/presets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( array( 'presets' => self::presets() ) ),
					'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save_presets' ),
					'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
				),
			)
		);
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public static function compress( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$path = self::image_path( $id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$file = $request->get_file_params()['file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return self::refuse( __( 'Manca il file compresso.', 'xb' ) );
		}
		$out = self::replace( $id, $file['tmp_name'] );
		return is_wp_error( $out ) ? $out : new \WP_REST_Response( $out );
	}

	/**
	 * Puts the lighter file `$tmp` in place of the image `$id` (the REST route checks that it was uploaded).
	 *
	 * @return array{id: int, before: int, after: int, width: int, height: int}|\WP_Error
	 */
	public static function replace( int $id, string $tmp ): array|\WP_Error {
		$path = self::image_path( $id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$old  = wp_getimagesize( $path );
		$new  = wp_getimagesize( $tmp );
		$mime = (string) get_post_mime_type( $id );
		if ( ! is_array( $old ) || ! is_array( $new ) || ( $new['mime'] ?? '' ) !== $mime ) {
			return self::refuse( __( 'Il file compresso deve essere un’immagine dello stesso formato: così l’indirizzo non cambia.', 'xb' ) );
		}
		[ $ow, $oh ] = array( (int) $old[0], (int) $old[1] );
		[ $nw, $nh ] = array( (int) $new[0], (int) $new[1] );
		if ( $nw < 1 || $nh < 1 || $nw > $ow || $nh > $oh || abs( $nw / $nh - $ow / $oh ) > 0.01 ) {
			return self::refuse( __( 'Il file compresso deve avere le stesse proporzioni e non essere più grande dell’originale.', 'xb' ) );
		}
		$before = (int) filesize( $path );
		$after  = (int) filesize( $tmp );
		if ( $after >= $before ) {
			return self::refuse( __( 'Il file compresso non è più leggero: l’immagine resta com’era.', 'xb' ) );
		}
		$done = get_post_meta( $id, self::META, true );
		if ( ! is_array( $done ) ) {
			$backup = self::backup_path( $path );
			if ( ! copy( $path, $backup ) ) {
				return self::refuse( __( 'Non ho potuto tenere una copia dell’originale: l’immagine resta com’era.', 'xb' ), 500 );
			}
			$done = array(
				'backup' => wp_basename( $backup ),
				'before' => $before,
				'width'  => $ow,
				'height' => $oh,
			);
		}
		if ( ! copy( $tmp, $path ) ) {
			return self::refuse( __( 'Non ho potuto scrivere il file compresso.', 'xb' ), 500 );
		}
		self::redraw_sizes( $id, $path, $nw, $nh );
		$done['after'] = (int) filesize( $path );
		$done['at']    = time();
		update_post_meta( $id, self::META, $done );
		Plugin::instance()->versioning->store()->audit( 'media-compress', 'attachment:' . $id, array( 'before' => (int) $done['before'], 'after' => $done['after'] ) );
		return array(
			'id'     => $id,
			'before' => (int) $done['before'],
			'after'  => $done['after'],
			'width'  => $nw,
			'height' => $nh,
		);
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public static function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$path = self::image_path( $id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$done   = get_post_meta( $id, self::META, true );
		$backup = is_array( $done ) && is_string( $done['backup'] ?? null ) ? dirname( $path ) . '/' . wp_basename( $done['backup'] ) : '';
		if ( '' === $backup || ! is_file( $backup ) ) {
			return self::refuse( __( 'Di questa immagine non c’è un originale da rimettere.', 'xb' ), 404 );
		}
		if ( ! copy( $backup, $path ) ) {
			return self::refuse( __( 'Non ho potuto rimettere l’originale.', 'xb' ), 500 );
		}
		self::redraw_sizes( $id, $path, (int) ( $done['width'] ?? 0 ), (int) ( $done['height'] ?? 0 ) );
		wp_delete_file( $backup );
		delete_post_meta( $id, self::META );
		Plugin::instance()->versioning->store()->audit( 'media-restore', 'attachment:' . $id );
		return new \WP_REST_Response(
			array(
				'id'    => $id,
				'bytes' => (int) filesize( $path ),
			)
		);
	}

	/**
	 * Every intermediate size drawn again from the file, under its own name and at its own size: what pages and
	 * `srcset` already name keeps working. A size larger than the new file stays as it was (never enlarged).
	 */
	private static function redraw_sizes( int $id, string $path, int $width, int $height ): void {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta['sizes'] ?? array() as $name => $size ) { // @phpstan-ignore nullCoalesce.offset
			$w = (int) ( $size['width'] ?? 0 );
			$h = (int) ( $size['height'] ?? 0 );
			if ( $w < 1 || $h < 1 || $w > $width || $h > $height || ! is_string( $size['file'] ?? null ) ) {
				continue;
			}
			$editor = wp_get_image_editor( $path );
			if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( $w, $h, true ) ) ) {
				continue;
			}
			$saved = $editor->save( dirname( $path ) . '/' . wp_basename( $size['file'] ), (string) ( $size['mime-type'] ?? get_post_mime_type( $id ) ) );
			if ( is_array( $saved ) ) {
				$meta['sizes'][ $name ]['filesize'] = (int) filesize( $saved['path'] );
			}
		}
		if ( $width > 0 && $height > 0 ) {
			$meta['width']  = $width;
			$meta['height'] = $height;
		}
		$meta['filesize'] = (int) filesize( $path );
		wp_update_attachment_metadata( $id, $meta );
	}

	/** The attached image file of a library item that can be compressed in place, or why not. */
	private static function image_path( int $id ): string|\WP_Error {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return self::refuse( __( 'File inesistente.', 'xb' ), 404 );
		}
		if ( ! in_array( (string) get_post_mime_type( $id ), self::MIMES, true ) ) {
			return self::refuse( __( 'Si comprimono sul posto solo immagini JPEG, PNG, WebP e AVIF.', 'xb' ) );
		}
		$path = get_attached_file( $id );
		return is_string( $path ) && is_file( $path ) ? $path : self::refuse( __( 'Il file dell’immagine non c’è più.', 'xb' ), 404 );
	}

	/** `foto.jpg` → `foto.xb-originale.jpg`, next to it (the original is as public as the file always was). */
	private static function backup_path( string $path ): string {
		$info = pathinfo( $path );
		return ( $info['dirname'] ?? '.' ) . '/' . $info['filename'] . '.xb-originale' . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
	}

	/**
	 * The site's presets: an id, a name, the longest side (0 keeps the size), the formats tried on upload and the
	 * quality of a lossy file.
	 *
	 * @return list<array{id: string, label: string, max: int, formats: list<string>, quality: int}>
	 */
	public static function presets(): array {
		$stored = get_option( self::PRESETS, array() );
		return self::clean( is_array( $stored ) ? $stored : array() );
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public static function save_presets( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$wanted = $request->get_json_params()['presets'] ?? null;
		if ( ! is_array( $wanted ) || count( $wanted ) > self::MAX_PRESETS ) {
			/* translators: %d: maximum number of presets */
			return self::refuse( sprintf( __( 'Al massimo %d preset.', 'xb' ), self::MAX_PRESETS ) );
		}
		$clean = self::clean( $wanted );
		update_option( self::PRESETS, $clean, false );
		return new \WP_REST_Response( array( 'presets' => $clean ) );
	}

	/**
	 * @param array<mixed> $list
	 * @return list<array{id: string, label: string, max: int, formats: list<string>, quality: int}>
	 */
	private static function clean( array $list ): array {
		$out  = array();
		$seen = array();
		foreach ( array_slice( $list, 0, self::MAX_PRESETS ) as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $preset['label'] ?? '' ) ) );
			$id    = sanitize_key( (string) ( $preset['id'] ?? '' ) );
			if ( '' === $label || '' === $id || isset( $seen[ $id ] ) || in_array( $id, array( 'web', 'light', 'original' ), true ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$formats     = array_values( array_intersect( array( 'avif', 'webp' ), is_array( $preset['formats'] ?? null ) ? array_map( 'strval', $preset['formats'] ) : array() ) );
			$out[]       = array(
				'id'      => mb_substr( $id, 0, 30 ),
				'label'   => mb_substr( $label, 0, 40 ),
				'max'     => max( 0, min( 8000, (int) ( $preset['max'] ?? 0 ) ) ),
				'formats' => array() === $formats ? array( 'avif', 'webp' ) : $formats,
				'quality' => max( 30, min( 95, (int) ( $preset['quality'] ?? 75 ) ) ),
			);
		}
		return $out;
	}

	private static function refuse( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'xb_media_compress', $message, array( 'status' => $status ) );
	}
}
