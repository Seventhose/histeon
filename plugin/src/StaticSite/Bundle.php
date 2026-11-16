<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/** A static bundle on disk: uploads/xb-static/<label>-<time>/… */
final class Bundle {

	public static function root(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'xb-static';
	}

	public static function create( string $label ): string {
		$root = self::root();
		wp_mkdir_p( $root );
		// Work area, not a public folder: deny everything (Apache; on nginx add `location ~ /xb-static/ { deny all; }`).
		foreach ( array( 'index.html' => '', '.htaccess' => "Require all denied\nOptions -Indexes\n" ) as $file => $body ) {
			// Rewritten whenever it differs: a weaker file left by an older version must not survive.
			if ( ! is_file( $root . '/' . $file ) || file_get_contents( $root . '/' . $file ) !== $body ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				file_put_contents( $root . '/' . $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		$dir = $root . '/' . sanitize_file_name( $label ) . '-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 16 ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( __( 'Impossibile creare la cartella del bundle.', 'xb' ) );
		}
		return $dir;
	}

	/** Root-relative URL path → file inside the bundle (`/a/b/` → `a/b/index.html`). */
	public static function page_file( string $path ): string {
		$path = trim( $path, '/' );
		return '' === $path ? 'index.html' : $path . '/index.html';
	}

	/** A path we are willing to turn into files: no traversal, no query or fragment, no control characters, no PHP. */
	public static function safe_path( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_contains( $path, '..' )
			&& ! str_contains( $path, '\\' )
			&& ! str_contains( $path, '?' )
			&& ! str_contains( $path, '#' )
			&& 1 !== preg_match( '/[\x00-\x1f\x7f]/', $path )
			&& 1 !== preg_match( '/\.php(\/|$)/i', $path )
			&& strlen( $path ) < 1024;
	}

	/** Deletes a bundle (after deploy, or when blocked): bundles are never kept around. */
	public static function delete( string $dir ): void {
		$root = realpath( self::root() );
		$real = realpath( $dir );
		if ( false === $root || false === $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return; // Only ever delete inside our own work area.
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo ) {
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	public static function write( string $dir, string $relative, string $content ): void {
		if ( ! self::safe_path( '/' . $relative ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: file path */ __( 'Percorso non ammesso nel bundle: %s', 'xb' ), $relative ) );
		}
		$file = $dir . '/' . $relative;
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** @param array<string, mixed> $meta */
	public static function write_meta( string $dir, array $meta ): void {
		file_put_contents( $dir . '/.xb-bundle.json', (string) wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** @return array<string, mixed> */
	public static function meta( string $dir ): array {
		$data = json_decode( (string) @file_get_contents( $dir . '/.xb-bundle.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Every deployable file: path relative to the bundle → absolute path. Dotfiles are excluded.
	 *
	 * @return array<string, string>
	 */
	public static function files( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $dir ) + 1 );
			if ( str_starts_with( basename( $relative ), '.' ) ) {
				continue;
			}
			$out[ '/' . str_replace( '\\', '/', $relative ) ] = $file->getPathname();
		}
		ksort( $out );
		return $out;
	}
}
