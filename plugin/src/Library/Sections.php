<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Library;

use XB\Plugin;

/**
 * The section library (docs/spec/library.md): sections saved from the builder, each a clip (the copy-and-paste
 * format between Histeon sites: markup plus the styles of its classes), kept by name and moved between sites as a
 * `.xblibrary` file. A section is data: inserting it goes through the builder's paste dialog (class conflicts) and
 * the document's normal save (the inserting user's trust).
 */
final class Sections {

	public const OPTION   = 'xb_library_sections';
	public const FORMAT   = 'xb-library';
	public const VERSION  = 1;
	public const MAX      = 200;
	public const MAX_CLIP = 2_000_000;
	/** Pictures carried by a `.xblibrary` file (M2): each, and all together. */
	private const MAX_MEDIA_FILE  = 5_000_000;
	private const MAX_MEDIA_TOTAL = 20_000_000;
	/** Raster images only: what a section shows, never a document or an SVG (which can carry script). */
	private const MEDIA_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/** @return list<array{id: string, name: string, origin: string, author: int, created: string, clip: string}> */
	private static function stored(): array {
		$value = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $value ) ? $value : array() as $s ) {
			if ( is_array( $s ) && is_string( $s['id'] ?? null ) && is_string( $s['name'] ?? null ) && is_string( $s['clip'] ?? null ) ) {
				$out[] = array(
					'id'      => $s['id'],
					'name'    => $s['name'],
					'origin'  => is_string( $s['origin'] ?? null ) ? $s['origin'] : '',
					'author'  => is_int( $s['author'] ?? null ) ? $s['author'] : 0,
					'created' => is_string( $s['created'] ?? null ) ? $s['created'] : '',
					'clip'    => $s['clip'],
				);
			}
		}
		return $out;
	}

	/** @param list<array{id: string, name: string, origin: string, author: int, created: string, clip: string}> $sections */
	private static function store( array $sections ): void {
		update_option( self::OPTION, $sections, false );
	}

	/**
	 * The list, without the clips (they can be large).
	 *
	 * @return list<array{id: string, name: string, origin: string, author: int, created: string, size: int}>
	 */
	public static function index(): array {
		return array_map(
			static fn ( array $s ): array => array(
				'id'      => $s['id'],
				'name'    => $s['name'],
				'origin'  => $s['origin'],
				'author'  => (int) $s['author'],
				'created' => $s['created'],
				'size'    => strlen( $s['clip'] ),
			),
			self::stored()
		);
	}

	public static function get( string $id ): ?string {
		foreach ( self::stored() as $s ) {
			if ( $s['id'] === $id ) {
				return $s['clip'];
			}
		}
		return null;
	}

	/**
	 * Checks a clip's shape: what the builder makes with makeClip(), nothing else.
	 *
	 * @throws \InvalidArgumentException With a message for the user.
	 * @return array{origin: string, json: string}
	 */
	public static function clip( string $json ): array {
		$fail = static fn (): \InvalidArgumentException => new \InvalidArgumentException( __( 'Sezione non valida.', 'xb' ) );
		if ( strlen( $json ) > self::MAX_CLIP ) {
			throw new \InvalidArgumentException( __( 'Sezione troppo grande.', 'xb' ) );
		}
		$clip = json_decode( $json, true );
		if ( ! is_array( $clip ) || 'clip' !== ( $clip['xb'] ?? null ) || 1 !== ( $clip['version'] ?? null ) || ! is_string( $clip['markup'] ?? null ) || ! is_string( $clip['origin'] ?? null ) || ! is_array( $clip['styles'] ?? null ) || ! array_is_list( $clip['styles'] ) ) {
			throw $fail();
		}
		$styles = array();
		foreach ( $clip['styles'] as $style ) {
			if ( ! is_array( $style ) || ! is_string( $style['selector'] ?? null ) || ! is_string( $style['css'] ?? null ) ) {
				throw $fail();
			}
			$styles[] = array( 'selector' => $style['selector'], 'css' => $style['css'] );
		}
		$origin = mb_substr( sanitize_text_field( $clip['origin'] ), 0, 120 );
		return array(
			'origin' => $origin,
			'json'   => (string) wp_json_encode( array( 'xb' => 'clip', 'version' => 1, 'origin' => $origin, 'markup' => $clip['markup'], 'styles' => $styles ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function save( string $name, string $json, string $id = '' ): string {
		$entry    = self::entry( $name, $json, $id );
		$sections = self::stored();
		if ( count( $sections ) >= self::MAX ) {
			/* translators: %d: maximum number of sections */
			throw new \InvalidArgumentException( sprintf( __( 'La libreria tiene al massimo %d sezioni.', 'xb' ), self::MAX ) );
		}
		$sections[] = $entry;
		self::store( $sections );
		return $entry['id'];
	}

	/**
	 * One section as it is stored, checked and (for someone without unfiltered_html) filtered; nothing is written.
	 *
	 * @throws \InvalidArgumentException With a message for the user.
	 * @return array{id: string, name: string, origin: string, author: int, created: string, clip: string}
	 */
	private static function entry( string $name, string $json, string $id ): array {
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 80 );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( __( 'Dai un nome alla sezione.', 'xb' ) );
		}
		$clip = self::clip( $json );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$clip = self::restricted( $clip['json'] );
		}
		return array(
			'id'      => 1 === preg_match( '/^[0-9a-f-]{36}$/', $id ) ? $id : wp_generate_uuid4(),
			'name'    => $name,
			'origin'  => $clip['origin'],
			'author'  => get_current_user_id(),
			'created' => gmdate( 'Y-m-d H:i:s' ),
			'clip'    => $clip['json'],
		);
	}

	/**
	 * A section from someone without unfiltered_html is filtered when it is saved, as their documents are: whoever
	 * inserts it later, even an administrator, inserts only what its author could have written (ADR 0005 R1).
	 *
	 * @return array{origin: string, json: string}
	 */
	private static function restricted( string $json ): array {
		$clip            = (array) json_decode( $json, true );
		$clip['markup']  = Plugin::instance()->sanitizer->restricted_content( (string) $clip['markup'] );
		$clip['styles']  = \XB\Versioning\Documents::restricted_styles( array( 'styles' => $clip['styles'] ) )['styles'];
		return self::clip( (string) wp_json_encode( $clip, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** Who saved a section (0 for sections of older versions), or null when there is none with that id. */
	public static function author( string $id ): ?int {
		foreach ( self::stored() as $s ) {
			if ( $s['id'] === $id ) {
				return $s['author'];
			}
		}
		return null;
	}

	/** Whether the current user may remove a section: their own, or any with xb_publish. */
	public static function can_delete( string $id ): bool {
		$author = self::author( $id );
		return null !== $author && ( ( 0 !== $author && get_current_user_id() === $author ) || current_user_can( 'xb_publish' ) );
	}

	public static function delete( string $id ): bool {
		$sections = self::stored();
		$kept     = array_values( array_filter( $sections, static fn ( array $s ): bool => $s['id'] !== $id ) );
		if ( count( $kept ) === count( $sections ) ) {
			return false;
		}
		self::store( $kept );
		Plugin::instance()->versioning->store()->audit( 'library_delete', 'library', array( 'id' => $id ) );
		return true;
	}

	/**
	 * The `.xblibrary` file. It carries the pictures of this site that the sections use (M2), so another site gets
	 * them from the file, never by fetching an address someone wrote into a section.
	 *
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		$sections = array_map( static fn ( array $s ): array => array( 'id' => $s['id'], 'name' => $s['name'], 'clip' => $s['clip'] ), self::stored() );
		$media    = self::media_of( array_column( $sections, 'clip' ) );
		return array(
			'format'     => self::FORMAT,
			'version'    => self::VERSION,
			'site'       => home_url( '/' ),
			'created'    => gmdate( 'c' ),
			'sections'   => $sections,
			'hash'       => hash( 'sha256', (string) wp_json_encode( $sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'media'      => $media,
			'media_hash' => hash( 'sha256', (string) wp_json_encode( $media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	/**
	 * The uploaded pictures the clips point at: each attachment once, with every address of it the clips use.
	 *
	 * @param list<string> $clips
	 * @return list<array{urls: list<string>, name: string, mime: string, alt: string, data: string}>
	 */
	private static function media_of( array $clips ): array {
		$base = (string) wp_upload_dir()['baseurl'];
		$text = str_replace( '\\/', '/', implode( "\n", $clips ) );
		preg_match_all( '#' . preg_quote( $base, '#' ) . '/[^\s"\'()<>\\\\,]+#', $text, $m );
		$by_id = array();
		foreach ( array_unique( $m[0] ) as $url ) {
			$id = \XB\Versioning\SiteTransfer::attachment_for( $url );
			if ( $id > 0 ) {
				$by_id[ $id ][] = $url;
			}
		}
		$out   = array();
		$total = 0;
		foreach ( $by_id as $id => $urls ) {
			$file = get_attached_file( $id );
			$mime = (string) get_post_mime_type( $id );
			$size = is_string( $file ) && is_readable( $file ) ? (int) filesize( $file ) : 0;
			if ( 0 === $size || $size > self::MAX_MEDIA_FILE || $total + $size > self::MAX_MEDIA_TOTAL || ! in_array( $mime, self::MEDIA_TYPES, true ) ) {
				continue;
			}
			// The file is read from disk, past the web server: only a picture this user may read, of uploads, in no
			// folder the server keeps closed (a shop's protected downloads, for instance).
			if ( ! current_user_can( 'read_post', $id ) || ! self::served_upload( (string) $file ) ) {
				continue;
			}
			$total += $size;
			$out[]  = array(
				'urls' => array_values( array_unique( $urls ) ),
				'name' => basename( (string) $file ),
				'mime' => $mime,
				'alt'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'data' => base64_encode( (string) file_get_contents( (string) $file ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}
		return $out;
	}

	/** A file inside the uploads folder, with no `.htaccess` that denies access on the way down to it. */
	private static function served_upload( string $file ): bool {
		$base = realpath( (string) wp_upload_dir()['basedir'] );
		$real = realpath( $file );
		if ( false === $base || false === $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		for ( $dir = dirname( $real ); strlen( $dir ) >= strlen( $base ); $dir = dirname( $dir ) ) {
			$rules = $dir . '/.htaccess';
			if ( is_file( $rules ) && 1 === preg_match( '/\b(?:deny\s+from\s+all|require\s+all\s+denied)\b/i', (string) file_get_contents( $rules ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				return false;
			}
			if ( $dir === $base ) {
				break;
			}
		}
		return true;
	}

	/**
	 * The pictures of a `.xblibrary` file, uploaded here through WordPress's checks of the real file type. A picture
	 * already imported (same bytes) is reused. Returns old address → new address.
	 *
	 * @param array<mixed> $media
	 * @return array<string, string>
	 */
	private static function import_media( array $media ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$map   = array();
		$total = 0;
		foreach ( array_slice( $media, 0, 500 ) as $item ) {
			$bytes = is_array( $item ) && is_string( $item['data'] ?? null ) ? base64_decode( $item['data'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$urls  = is_array( $item ) && is_array( $item['urls'] ?? null ) ? array_values( array_filter( $item['urls'], static fn ( mixed $u ): bool => is_string( $u ) && 1 === preg_match( '#^https?://#', $u ) ) ) : array();
			$name  = is_array( $item ) && is_string( $item['name'] ?? null ) ? sanitize_file_name( $item['name'] ) : '';
			if ( false === $bytes || array() === $urls || '' === $name || strlen( $bytes ) > self::MAX_MEDIA_FILE || $total + strlen( $bytes ) > self::MAX_MEDIA_TOTAL ) {
				continue;
			}
			$total += strlen( $bytes );
			$source = 'library:' . hash( 'sha256', $bytes );
			$found  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_xb_import_source', 'meta_value' => $source, 'numberposts' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$id     = $found ? (int) $found[0] : self::upload( $name, $bytes, $source, is_string( $item['alt'] ?? null ) ? $item['alt'] : '' );
			$url    = $id > 0 ? wp_get_attachment_url( $id ) : false;
			if ( is_string( $url ) ) {
				foreach ( $urls as $old ) {
					$map[ $old ] = $url;
				}
			}
		}
		return $map;
	}

	/** One picture into the uploads; 0 when WordPress or the real file type refuse it. */
	private static function upload( string $name, string $bytes, string $source, string $alt ): int {
		if ( '' === $name ) {
			return 0;
		}
		// Checked before anything reaches the public uploads folder: a picture by its name and by its bytes.
		$named = wp_check_filetype( $name );
		$size  = getimagesizefromstring( $bytes );
		if ( ! in_array( $named['type'], self::MEDIA_TYPES, true ) || false === $size || ! in_array( $size['mime'], self::MEDIA_TYPES, true ) ) {
			return 0;
		}
		$upload = wp_upload_bits( $name, null, $bytes );
		if ( false !== $upload['error'] ) {
			return 0;
		}
		$real = wp_check_filetype_and_ext( $upload['file'], $name );
		$type = is_string( $real['type'] ) ? $real['type'] : '';
		if ( ! in_array( $type, self::MEDIA_TYPES, true ) || ! wp_getimagesize( $upload['file'] ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => $type, 'post_title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ), 'post_status' => 'inherit' ), $upload['file'], 0, true );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_xb_import_source', $source );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return $id;
	}

	/**
	 * A clip with its picture addresses replaced (plain and JSON-escaped forms).
	 *
	 * @param array<string, string> $map
	 */
	private static function rewrite( string $json, array $map ): string {
		if ( array() === $map ) {
			return $json;
		}
		$clip = json_decode( $json, true );
		if ( ! is_array( $clip ) ) {
			return $json;
		}
		$pairs = $map;
		foreach ( $map as $old => $new ) {
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}
		$clip['markup'] = strtr( (string) ( $clip['markup'] ?? '' ), $pairs );
		foreach ( is_array( $clip['styles'] ?? null ) ? $clip['styles'] : array() as $i => $style ) {
			if ( is_array( $style ) && is_string( $style['css'] ?? null ) ) {
				$clip['styles'][ $i ]['css'] = strtr( $style['css'], $pairs );
			}
		}
		return (string) wp_json_encode( $clip, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Adds the sections of a `.xblibrary` file; a section already here (same id) is skipped.
	 *
	 * @throws \InvalidArgumentException With a message for the user; nothing is added then.
	 * @return array{added: int, skipped: int, media: int}
	 */
	public static function import( mixed $data ): array {
		$fail = static fn ( string $why ): \InvalidArgumentException => new \InvalidArgumentException( sprintf( /* translators: %s: reason */ __( 'File della libreria non valido: %s.', 'xb' ), $why ) );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) || self::VERSION !== ( $data['version'] ?? null ) || ! is_array( $data['sections'] ?? null ) || ! array_is_list( $data['sections'] ) ) {
			throw $fail( __( 'formato sconosciuto', 'xb' ) );
		}
		if ( ! is_string( $data['hash'] ?? null ) || ! hash_equals( hash( 'sha256', (string) wp_json_encode( $data['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $data['hash'] ) ) {
			throw $fail( __( 'contenuto alterato o incompleto', 'xb' ) );
		}
		$stored = self::stored();
		$have   = array_column( $stored, 'id' );
		$clean  = array();
		$seen   = array();
		foreach ( $data['sections'] as $i => $section ) {
			// One id once: a section twice in the same file would be added twice.
			if ( ! is_array( $section ) || ! is_string( $section['id'] ?? null ) || 1 !== preg_match( '/^[0-9a-f-]{36}$/D', $section['id'] ) || isset( $seen[ $section['id'] ] ) || ! is_string( $section['name'] ?? null ) || '' === trim( sanitize_text_field( $section['name'] ) ) || ! is_string( $section['clip'] ?? null ) ) {
				/* translators: %d: section number */
				throw $fail( sprintf( __( 'sezione %d', 'xb' ), $i + 1 ) );
			}
			self::clip( $section['clip'] );
			$seen[ $section['id'] ] = true;
			$clean[]                = $section;
		}
		$fresh = array_values( array_filter( $clean, static fn ( array $s ): bool => ! in_array( $s['id'], $have, true ) ) );
		if ( count( $have ) + count( $fresh ) > self::MAX ) {
			/* translators: %d: maximum number of sections */
			throw new \InvalidArgumentException( sprintf( __( 'La libreria tiene al massimo %d sezioni.', 'xb' ), self::MAX ) );
		}
		// The file's pictures, when it carries them untouched and this user may upload (M2).
		$map   = array();
		$media = $data['media'] ?? null;
		if ( array() !== $fresh && is_array( $media ) && array_is_list( $media ) && current_user_can( 'upload_files' ) && is_string( $data['media_hash'] ?? null ) && hash_equals( hash( 'sha256', (string) wp_json_encode( $media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), $data['media_hash'] ) ) {
			$map = self::import_media( $media );
		}
		// Every section checked first, then written at once: a file is added whole or not at all.
		$entries = array();
		foreach ( $fresh as $section ) {
			$entries[] = self::entry( $section['name'], self::rewrite( $section['clip'], $map ), $section['id'] );
		}
		if ( array() !== $entries ) {
			self::store( array_merge( $stored, $entries ) );
		}
		Plugin::instance()->versioning->store()->audit( 'library_import', 'library', array( 'added' => count( $fresh ), 'skipped' => count( $clean ) - count( $fresh ), 'media' => count( array_unique( $map ) ) ) );
		return array(
			'added'   => count( $fresh ),
			'skipped' => count( $clean ) - count( $fresh ),
			'media'   => count( array_unique( $map ) ),
		);
	}
}
