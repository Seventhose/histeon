<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/**
 * Copies the files a page references into the bundle — straight from disk, never through PHP.
 *
 * Only root-relative URLs under /wp-content/ or /wp-includes/ with an allowed extension are copied, and
 * the resolved real path must stay inside those directories (no traversal, no symlink escapes).
 * CSS files are scanned for url() and @import, recursively.
 */
final class Assets {

	private const EXTENSIONS = array( 'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'ogg', 'pdf' );
	private const MAX_FILE   = 25 * 1024 * 1024; // Cloudflare's per-file limit.

	/** @var array<string, true> */
	private array $copied = array();
	/** @var list<string> */
	private array $roots;
	/** @var list<array{0: string, 1: int|null}>|null Real uploads directories and the site each belongs to (null: by path). */
	private ?array $upload_roots = null;

	/**
	 * @param callable(string): bool|null $reachable Whether a path is served to an anonymous visitor
	 *                                                (the web server's access rules apply). Null in unit use.
	 */
	public function __construct( private readonly string $dir, private readonly mixed $reachable = null ) {
		$this->roots = array_values(
			array_filter(
				array( realpath( WP_CONTENT_DIR ), realpath( ABSPATH . WPINC ) ),
				'is_string'
			)
		);
	}

	public function count(): int {
		return count( $this->copied );
	}

	public function collect_and_rewrite( string $html ): string {
		// src, href, poster, data-src… and srcset lists.
		preg_match_all( '#(?:src|href|poster|data-src)\s*=\s*["\']([^"\']+)["\']#i', $html, $single );
		preg_match_all( '#srcset\s*=\s*["\']([^"\']+)["\']#i', $html, $sets );
		$urls = $single[1];
		foreach ( $sets[1] as $set ) {
			foreach ( explode( ',', $set ) as $candidate ) {
				$urls[] = (string) preg_replace( '/\s+\S+$/', '', trim( $candidate ) );
			}
		}
		preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i', $html, $inline );
		foreach ( array_merge( $urls, $inline[1] ) as $url ) {
			$this->copy( $url, '/' );
		}
		return $html;
	}

	/** Copies one URL if it is an allowed local asset; returns the bundle path or null. */
	public function copy( string $url, string $base ): ?string {
		$url  = html_entity_decode( trim( $url ), ENT_QUOTES );
		$path = $this->resolve( $url, $base );
		if ( null === $path || isset( $this->copied[ $path ] ) ) {
			return $path;
		}
		$source = $this->source( $path );
		if ( null === $source ) {
			return null;
		}
		// A file on disk is not necessarily public (protected downloads, deny rules): ask the web server.
		if ( is_callable( $this->reachable ) && ! ( $this->reachable )( $path ) ) {
			return null;
		}
		// The web server answers the exporter from its own address, which rules by client IP (Require local,
		// allow 127.0.0.1) let through. In uploads only media WordPress itself publishes go into the bundle.
		if ( ! $this->published_media_or_code( $path, $source ) ) {
			return null;
		}
		$this->copied[ $path ] = true;
		$target                = $this->dir . $path;
		wp_mkdir_p( dirname( $target ) );
		copy( $source, $target );
		if ( str_ends_with( strtolower( $path ), '.css' ) ) {
			$css = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)|@import\s+["\']([^"\']+)["\']#i', $css, $m );
			foreach ( array_merge( $m[1], $m[2] ) as $ref ) {
				if ( '' !== $ref ) {
					$this->copy( $ref, $path );
				}
			}
		}
		return $path;
	}

	/**
	 * Theme, plugin and core assets are code shipped with the site; a file in uploads is public only as a media item
	 * of the library (attachment) that is not attached to a private or unpublished post, or as one of its sizes.
	 *
	 * «In uploads» is decided by where the file really is on disk (doc 23, voce 170), as well as by its address: a
	 * symlink under plugins or themes that points into uploads, a custom upload_url_path, or on multisite a subsite
	 * page naming a file of the main site or of another subsite all reach the media check. A file of another site
	 * of the network is checked against that site's library.
	 */
	private function published_media_or_code( string $path, string $source ): bool {
		$uploads = (string) wp_parse_url( (string) wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		$uploads = '/' . trim( $uploads, '/' ) . '/';
		$owner   = $this->upload_owner( $source );
		if ( null === $owner ) {
			if ( ! str_starts_with( $path, $uploads ) ) {
				return true;
			}
			// Named under the uploads address but stored elsewhere: still checked as a media item of this site.
			$owner = array( get_current_blog_id(), substr( $path, strlen( $uploads ) ) );
		}
		[ $blog, $relative ] = $owner;
		if ( $blog === get_current_blog_id() ) {
			return self::published_media( $relative );
		}
		if ( ! function_exists( 'switch_to_blog' ) || ! get_site( $blog ) instanceof \WP_Site ) {
			return false;
		}
		switch_to_blog( $blog );
		try {
			// The file must be in that site's own uploads, as that site names it.
			$base = realpath( (string) wp_get_upload_dir()['basedir'] );
			if ( false === $base || ! str_starts_with( $source, $base . DIRECTORY_SEPARATOR ) ) {
				return false;
			}
			return self::published_media( str_replace( DIRECTORY_SEPARATOR, '/', substr( $source, strlen( $base ) + 1 ) ) );
		} finally {
			restore_current_blog();
		}
	}

	/** A file of the current site's uploads, by its path relative to the uploads directory. */
	private static function published_media( string $relative ): bool {
		$url = wp_get_upload_dir()['baseurl'] . '/' . ltrim( $relative, '/' );
		$id  = attachment_url_to_postid( $url );
		if ( 0 === $id ) {
			// A generated size (photo-300x200.jpg) or a scaled copy belongs to the original's attachment.
			$original = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated)(?=\.[a-z0-9]+$)/i', '', $url );
			$id       = $original !== $url ? attachment_url_to_postid( $original ) : 0;
			// Only a file WordPress made for it: any other file named like a size is not that media item's.
			if ( $id > 0 && ! self::is_file_of( $id, basename( $relative ) ) ) {
				$id = 0;
			}
		}
		$attachment = $id > 0 ? get_post( $id ) : null;
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		return 0 === $attachment->post_parent || is_post_publicly_viewable( $attachment->post_parent );
	}

	/**
	 * The site whose uploads hold a real file, and the file's path inside them; null when the file is in no uploads
	 * directory. Checked in order: this site's uploads, then on multisite the network's (uploads/sites/<id>/ and the
	 * old blogs.dir/<id>/files/ belong to subsite <id>, the rest of uploads to the main site), and the default
	 * wp-content/uploads whatever upload_path says.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private function upload_owner( string $source ): ?array {
		foreach ( $this->upload_roots() as [ $root, $blog ] ) {
			if ( ! str_starts_with( $source, $root . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $source, strlen( $root ) + 1 ) );
			if ( null === $blog ) {
				// blogs.dir/<id>/files/…
				return 1 === preg_match( '#^(\d+)/files/(.+)$#', $relative, $m ) ? array( (int) $m[1], $m[2] ) : array( 0, $relative );
			}
			if ( is_multisite() && get_main_site_id() === $blog && 1 === preg_match( '#^sites/(\d+)/(.+)$#', $relative, $m ) ) {
				return array( (int) $m[1], $m[2] );
			}
			return array( $blog, $relative );
		}
		return null;
	}

	/** @return list<array{0: string, 1: int|null}> */
	private function upload_roots(): array {
		if ( null !== $this->upload_roots ) {
			return $this->upload_roots;
		}
		$roots = array( array( realpath( (string) wp_get_upload_dir()['basedir'] ), get_current_blog_id() ) );
		$main  = is_multisite() ? get_main_site_id() : get_current_blog_id();
		if ( is_multisite() && $main !== get_current_blog_id() ) {
			switch_to_blog( $main );
			$roots[] = array( realpath( (string) wp_get_upload_dir()['basedir'] ), $main );
			restore_current_blog();
		}
		if ( is_multisite() ) {
			$roots[] = array( realpath( WP_CONTENT_DIR . '/blogs.dir' ), null );
		}
		$roots[] = array( realpath( WP_CONTENT_DIR . '/uploads' ), $main );
		$this->upload_roots = array();
		foreach ( $roots as [ $root, $blog ] ) {
			if ( is_string( $root ) ) {
				$this->upload_roots[] = array( $root, $blog );
			}
		}
		return $this->upload_roots;
	}

	/** Whether a file name is one of the sizes, or the original, that WordPress recorded for an attachment. */
	private static function is_file_of( int $id, string $name ): bool {
		/** @var array<string, mixed> $meta */
		$meta  = (array) wp_get_attachment_metadata( $id );
		$names = array_column( is_array( $meta['sizes'] ?? null ) ? array_filter( $meta['sizes'], 'is_array' ) : array(), 'file' );
		foreach ( array( $meta['original_image'] ?? null, $meta['file'] ?? null ) as $file ) {
			if ( is_string( $file ) ) {
				$names[] = basename( $file );
			}
		}
		return in_array( $name, $names, true );
	}

	/** URL as written in a page or a CSS file → normalized root-relative path, or null. */
	private function resolve( string $url, string $base ): ?string {
		if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) || str_starts_with( $url, '//' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return null; // External, inline or protocol-relative: not ours to copy.
		}
		$path = (string) strtok( $url, '?#' );
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = rtrim( dirname( $base ), '/' ) . '/' . $path;
		}
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = rawurldecode( $segment );
		}
		$path = '/' . implode( '/', $segments );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::EXTENSIONS, true ) || ! Bundle::safe_path( $path ) ) {
			return null;
		}
		return str_starts_with( $path, '/wp-content/' ) || str_starts_with( $path, '/wp-includes/' ) ? $path : null;
	}

	/** Real file for a bundle path, only inside wp-content or wp-includes. */
	private function source( string $path ): ?string {
		$candidate = str_starts_with( $path, '/wp-content/' )
			? WP_CONTENT_DIR . substr( $path, strlen( '/wp-content' ) )
			: ABSPATH . ltrim( $path, '/' );
		$real      = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) || filesize( $real ) > self::MAX_FILE ) {
			return null;
		}
		foreach ( $this->roots as $root ) {
			if ( str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}
		return null;
	}
}
