<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Media;

use XB\Rest\Controller;

/**
 * YouTube and Vimeo videos that ask nothing of them before the visitor's click (E9, docs/spec/media.md): the server
 * reads the title (oEmbed) and downloads the poster once into the media library; the page shows that poster with a
 * link to the video, and Histeon's behaviours script puts the player there (youtube-nocookie.com, Vimeo with dnt=1)
 * only on click.
 */
final class Video {

	/** On the poster attachment: which video it is the poster of ("youtube:ID"), so a second insert reuses it. */
	public const POSTER_META = '_xb_video_poster';

	public static function register(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/video',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ) && current_user_can( 'upload_files' ),
				'args'                => array(
					'url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The provider and id of a YouTube or Vimeo address, or null.
	 *
	 * @return array{0: 'youtube'|'vimeo', 1: string}|null
	 */
	public static function parse( string $url ): ?array {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );
		if ( in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com' ), true ) ) {
			parse_str( (string) ( $parts['query'] ?? '' ), $query );
			$id = '/watch' === $path ? ( $query['v'] ?? '' ) : ( preg_match( '#^/(?:embed|shorts|live)/([^/]+)$#', $path, $m ) ? $m[1] : '' );
		} elseif ( 'youtu.be' === $host ) {
			$id = ltrim( $path, '/' );
		} elseif ( in_array( $host, array( 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ), true ) ) {
			return preg_match( '#^/(?:video/)?(\d{1,12})/?$#', $path, $m ) ? array( 'vimeo', $m[1] ) : null;
		} else {
			return null;
		}
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ? array( 'youtube', $id ) : null;
	}

	public static function rest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$video = self::parse( (string) $request['url'] );
		if ( null === $video ) {
			return new \WP_Error( 'xb_video', __( 'Serve l’indirizzo di un video YouTube o Vimeo.', 'xb' ), array( 'status' => 400 ) );
		}
		[ $provider, $id ] = $video;
		$watch             = 'youtube' === $provider ? 'https://www.youtube.com/watch?v=' . $id : 'https://vimeo.com/' . $id;
		$info              = self::oembed( $provider, $watch );
		$poster            = self::poster( $provider, $id, $info['thumbnail'] );
		$response          = new \WP_REST_Response(
			array(
				'provider' => $provider,
				'id'       => $id,
				'title'    => $info['title'],
				'watch'    => $watch,
				'embed'    => 'youtube' === $provider ? 'https://www.youtube-nocookie.com/embed/' . $id : 'https://player.vimeo.com/video/' . $id,
				'poster'   => $poster,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Title and poster address from the provider's oEmbed; empty when it does not answer.
	 *
	 * @return array{title: string, thumbnail: string}
	 */
	private static function oembed( string $provider, string $watch ): array {
		$endpoint = 'youtube' === $provider ? 'https://www.youtube.com/oembed?format=json&url=' : 'https://vimeo.com/api/oembed.json?url=';
		$response = wp_safe_remote_get(
			$endpoint . rawurlencode( $watch ),
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 64 * 1024,
			)
		);
		$data = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		return array(
			'title'     => is_string( $data['title'] ?? null ) ? mb_substr( sanitize_text_field( $data['title'] ), 0, 200 ) : '',
			'thumbnail' => is_string( $data['thumbnail_url'] ?? null ) ? $data['thumbnail_url'] : '',
		);
	}

	/**
	 * The poster in the media library: the one already downloaded for this video, or a new download from the
	 * provider's image host (and only from there). Null when there is none.
	 *
	 * @return array{id: int, url: string, width: int, height: int}|null
	 */
	private static function poster( string $provider, string $id, string $thumbnail ): ?array {
		$key      = $provider . ':' . $id;
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::POSTER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only posters carry it.
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$attachment = (int) ( $existing[0] ?? 0 );
		if ( 0 === $attachment ) {
			$source = 'youtube' === $provider ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' : ( 1 === preg_match( '#^https://i\.vimeocdn\.com/video/[A-Za-z0-9_.-]+$#', $thumbnail ) ? $thumbnail : '' );
			$attachment = '' === $source ? 0 : self::download( $source, $provider . '-' . $id, $key );
		}
		if ( 0 === $attachment ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $attachment );
		return array(
			'id'     => $attachment,
			'url'    => (string) wp_get_attachment_url( $attachment ),
			'width'  => is_array( $meta ) ? (int) $meta['width'] : 0,
			'height' => is_array( $meta ) ? (int) $meta['height'] : 0,
		);
	}

	/** Downloads an image into the media library; 0 when it is not a JPEG, PNG or WebP image. */
	private static function download( string $url, string $name, string $key ): int {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 3 * 1024 * 1024,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}
		$bytes = (string) wp_remote_retrieve_body( $response );
		$size  = '' === $bytes ? false : getimagesizefromstring( $bytes );
		$types = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);
		if ( false === $size || ! isset( $types[ $size['mime'] ] ) ) {
			return 0;
		}
		// The name is the provider and the id: letters, digits, - and _ only.
		$upload = wp_upload_bits( 'video-' . $name . '.' . $types[ $size['mime'] ], null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $size['mime'],
				'post_title'     => sanitize_text_field( 'Video ' . $name ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( 0 === $id ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, self::POSTER_META, $key );
		return $id;
	}
}
