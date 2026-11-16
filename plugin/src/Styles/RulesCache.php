<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * Parsed CSS rules cached as JSON in uploads/xb-cache/ (data, never code), one file per stylesheet: writing a
 * new version removes the older ones with the same prefix. Unwritable folders just mean no cache.
 */
final class RulesCache {

	private static function dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'xb-cache';
	}

	private static function path( string $prefix, string $key ): string {
		return self::dir() . '/' . $prefix . '-' . $key . '.json';
	}

	/**
	 * @param bool $list Rules are a list (reindexed); false keeps the keys of a cached map.
	 * @return array<mixed>|null
	 */
	public static function get( string $prefix, string $key, bool $list = true ): ?array {
		$file = self::path( $prefix, $key );
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $list ? array_values( $data ) : $data;
	}

	/** @param array<mixed> $rules */
	public static function put( string $prefix, string $key, array $rules ): void {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		self::forget( $prefix );
		$json = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( is_string( $json ) ) {
			$file = self::path( $prefix, $key );
			$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			if ( false !== file_put_contents( $tmp, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		}
	}

	/**
	 * A stylesheet served as a file from uploads/xb-cache/ (C5): written once per content (the key is its hash),
	 * replacing the older versions with the same prefix. Its URL, or null when the folder cannot be written.
	 */
	public static function css_file( string $prefix, string $key, string $css ): ?string {
		$file = self::dir() . '/' . $prefix . '-' . $key . '.css';
		if ( ! is_readable( $file ) ) {
			$dir = self::dir();
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			foreach ( glob( $dir . '/' . $prefix . '-*.css' ) ?: array() as $old ) {
				unlink( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- older versions of the same stylesheet.
			}
			$tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			if ( false === file_put_contents( $tmp, $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return null;
			}
			rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'xb-cache/' . $prefix . '-' . $key . '.css';
	}

	/** Removes every cached version with this prefix. */
	public static function forget( string $prefix ): void {
		foreach ( glob( self::dir() . '/' . $prefix . '-*.json' ) ?: array() as $old ) {
			unlink( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- older versions of the same stylesheet.
		}
	}
}
