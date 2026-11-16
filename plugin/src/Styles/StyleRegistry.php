<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * Stored styles (Phase 1: options; Phase 4 moves them under versioning).
 * - `xb_styles`: list of {id, selector, css} — one entry per selector edited in the CSS panel.
 * - `xb_global_styles`: list of {id, css, source?} — global stylesheets, always printed; source is user-declared provenance.
 *
 * @phpstan-type Entry array{id: string, selector: string, css: string}
 */
final class StyleRegistry {

	public const OPTION_STYLES = 'xb_styles';
	public const OPTION_GLOBAL = 'xb_global_styles';
	/** Bumped when an attachment that CSS can point at changes: part of the cache key of the site rules. */
	public const MEDIA_GENERATION = 'xb_css_media_gen';

	/**
	 * Styles to use instead of the live options for this request (dev preview, builder canvas).
	 *
	 * @var array{styles: mixed, globals: mixed}|null
	 */
	private static ?array $override = null;

	/** @param array{styles: mixed, globals: mixed}|null $payload */
	public static function override( ?array $payload ): void {
		self::$override = $payload;
	}

	public static function overridden(): bool {
		return null !== self::$override;
	}

	/** Invalidates the cached site rules when attachments change: their URLs are resolved into the CSS. */
	public static function register(): void {
		add_action( 'clean_post_cache', array( self::class, 'on_clean_post_cache' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_post_meta' ), 10, 3 );
		}
	}

	public static function media_generation(): int {
		return (int) get_option( self::MEDIA_GENERATION, 0 );
	}

	public static function bump_media_generation(): void {
		update_option( self::MEDIA_GENERATION, self::media_generation() + 1, true );
	}

	public static function on_clean_post_cache( mixed $post_id, mixed $post ): void {
		unset( $post_id );
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			self::bump_media_generation();
		}
	}

	/** @param int|array<int>|string $meta_id */
	public static function on_post_meta( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( in_array( $meta_key, array( '_xb_page_profile_id', '_wp_attached_file' ), true ) ) {
			self::bump_media_generation();
		}
	}

	/** @return list<array{id: string, selector: string, css: string, key: ?string, assets?: list<array{reference: string, assetId: string}>}> */
	public function entries( bool $live = false, bool $resolve_assets = false ): array {
		$out    = array();
		$source = ! $live && null !== self::$override ? self::$override['styles'] : get_option( self::OPTION_STYLES, array() );
		foreach ( (array) $source as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['selector'] ?? null ) || ! is_string( $entry['css'] ?? null ) ) {
				continue;
			}
			$assets = self::asset_mappings( $entry['assets'] ?? array() );
			$css    = $entry['css'];
			if ( $resolve_assets && array() !== $assets ) {
				$css = CssAssets::rewrite( $css, $assets, self::attachment_url(...) );
			}
			$compiled = array(
				'id'       => is_string( $entry['id'] ?? null ) ? $entry['id'] : md5( $entry['selector'] ),
				'selector' => $entry['selector'],
				'css'      => $css,
				'key'      => self::key_class( $entry['selector'] ),
			);
			if ( array() !== $assets ) {
				$compiled['assets'] = $assets;
			}
			$out[] = $compiled;
		}
		return $out;
	}

	/** @return list<array{id: string, css: string, source?: array<string, mixed>, assets?: list<array{reference: string, assetId: string}>}> */
	public function globals( bool $live = false, bool $resolve_assets = false ): array {
		$out    = array();
		$source = ! $live && null !== self::$override ? self::$override['globals'] : get_option( self::OPTION_GLOBAL, array() );
		foreach ( (array) $source as $sheet ) {
			if ( is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ) {
				$assets = self::asset_mappings( $sheet['assets'] ?? array() );
				$css    = $sheet['css'];
				if ( $resolve_assets && array() !== $assets ) {
					$css = CssAssets::rewrite( $css, $assets, self::attachment_url(...) );
				}
				$entry = array(
					'id'  => is_string( $sheet['id'] ?? null ) ? $sheet['id'] : 'global',
					'css' => $css,
				);
				if ( is_array( $sheet['source'] ?? null ) ) {
					$entry['source'] = $sheet['source'];
				}
				if ( array() !== $assets ) {
					$entry['assets'] = $assets;
				}
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * The media mappings of a stored rule as the page uses them: a list that is not well formed is no mapping at all.
	 * Documents::restricted_styles() reads them the same way, to judge the CSS as it is printed with its media.
	 *
	 * @return list<array{reference: string, assetId: string}>
	 */
	public static function asset_mappings( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 500 ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['reference'] ?? null ) || '' === trim( $entry['reference'] ) || strlen( $entry['reference'] ) > 2048 || ! is_string( $entry['assetId'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( $entry['assetId'] ) ) ) {
				return array();
			}
			$out[] = array( 'reference' => $entry['reference'], 'assetId' => strtolower( $entry['assetId'] ) );
		}
		return $out;
	}

	private static function attachment_url( string $asset_id ): ?string {
		static $cache = array();
		if ( array_key_exists( $asset_id, $cache ) ) {
			return $cache[ $asset_id ];
		}
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_xb_page_profile_id', // phpcs:ignore WordPress.DB.SlowDBQuery -- one stable Histeon media ID.
				'meta_value'     => $asset_id, // phpcs:ignore WordPress.DB.SlowDBQuery -- exact stable Histeon media ID.
			)
		);
		$url = array() === $attachments ? false : wp_get_attachment_url( (int) $attachments[0] );
		$cache[ $asset_id ] = is_string( $url ) ? $url : null;
		return $cache[ $asset_id ];
	}

	/**
	 * The class that decides whether an entry is needed on a page: the first class in the selector.
	 * Entries without a class (`h1`, `#main`, `[data-x]`) are always printed.
	 */
	public static function key_class( string $selector ): ?string {
		return 1 === preg_match( '/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $selector, $m ) ? $m[1] : null;
	}
}
