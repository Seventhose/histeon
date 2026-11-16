<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * Histeon CSS in the block editor's pickers — docs/spec/histos-css.md §3: the colour palette and the font sizes of
 * Gutenberg offer its tokens, after the theme's own (which stay: their slugs may be in use). The values are
 * var(--xb-…), so the site's parameters restyle them. Pure: used by Histeon (Gutenberg) and the standalone plugin.
 */
final class ThemeJson {

	/** Shades offered in the palette, besides the colour itself. */
	private const SHADES = array( 100, 300, 700, 900 );

	public static function apply( \WP_Theme_JSON_Data $data ): \WP_Theme_JSON_Data {
		$current  = $data->get_data();
		$palette  = self::presets( $current['settings']['color']['palette'] ?? null );
		$sizes    = self::presets( $current['settings']['typography']['fontSizes'] ?? null );
		$palette  = array_values( array_filter( $palette, static fn ( mixed $c ): bool => is_array( $c ) && ! str_starts_with( (string) ( $c['slug'] ?? '' ), 'xb-' ) ) );
		$sizes    = array_values( array_filter( $sizes, static fn ( mixed $s ): bool => is_array( $s ) && ! str_starts_with( (string) ( $s['slug'] ?? '' ), 'xb-' ) ) );
		$spaces   = array_values( array_filter( self::presets( $current['settings']['spacing']['spacingSizes'] ?? null ), static fn ( mixed $s ): bool => is_array( $s ) && ! str_starts_with( (string) ( $s['slug'] ?? '' ), 'xb-' ) ) );
		$shadows  = array_values( array_filter( self::presets( $current['settings']['shadow']['presets'] ?? null ), static fn ( mixed $s ): bool => is_array( $s ) && ! str_starts_with( (string) ( $s['slug'] ?? '' ), 'xb-' ) ) );
		return $data->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color'      => array( 'palette' => array_merge( $palette, self::palette() ) ),
					'typography' => array( 'fontSizes' => array_merge( $sizes, self::font_sizes() ) ),
					// Lista della spesa 139: Gutenberg's own blocks take Histeon's spaces and shadows too.
					'spacing'    => array( 'spacingSizes' => array_merge( $spaces, self::spacing_sizes() ) ),
					'shadow'     => array( 'presets' => array_merge( $shadows, self::shadows() ) ),
				),
			)
		);
	}

	/**
	 * The theme's own presets: WordPress keeps them by origin (`['theme' => [...]]`) once processed, as a list in a
	 * raw theme.json.
	 *
	 * @return list<mixed>
	 */
	private static function presets( mixed $presets ): array {
		if ( ! is_array( $presets ) ) {
			return array();
		}
		if ( isset( $presets['theme'] ) && is_array( $presets['theme'] ) ) {
			return array_values( $presets['theme'] );
		}
		return array_is_list( $presets ) ? $presets : array();
	}

	/** @return list<array{slug: string, name: string, color: string}> */
	public static function palette(): array {
		$out = array();
		foreach ( Design::FAMILIES as $family ) {
			// A family the defaults switch off (tertiary) is not in the editor's palette.
			if ( 0 === ( Design::DEFAULTS[ $family . '_on' ] ?? 1 ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => 'xb-' . $family,
				'name'  => 'Histeon ' . $family,
				'color' => 'var(--xb-' . $family . ')',
			);
			foreach ( self::SHADES as $shade ) {
				$out[] = array(
					'slug'  => 'xb-' . $family . '-' . $shade,
					'name'  => 'Histeon ' . $family . ' ' . $shade,
					'color' => 'var(--xb-' . $family . '-' . $shade . ')',
				);
			}
		}
		$out[] = array( 'slug' => 'xb-white', 'name' => 'Histeon white', 'color' => 'var(--xb-white)' );
		$out[] = array( 'slug' => 'xb-black', 'name' => 'Histeon black', 'color' => 'var(--xb-black)' );
		return $out;
	}

	/** @return list<array{slug: string, name: string, size: string}> */
	public static function spacing_sizes(): array {
		$out = array();
		foreach ( array_keys( Tokens::SPACE ) as $step ) {
			$out[] = array(
				'slug' => 'xb-' . $step,
				'name' => 'Histeon ' . $step,
				'size' => 'var(--xb-space-' . $step . ')',
			);
		}
		return $out;
	}

	/** @return list<array{slug: string, name: string, shadow: string}> */
	public static function shadows(): array {
		$out = array();
		foreach ( array( 's', 'm', 'l', 'xl', '2xl' ) as $size ) {
			$out[] = array(
				'slug'   => 'xb-' . $size,
				'name'   => 'Histeon ' . $size,
				'shadow' => 'var(--xb-shadow-' . $size . ')',
			);
		}
		return $out;
	}

	/**
	 * Styles of WordPress's own blocks made with the tokens (lista della spesa 139): a client who adds a button, a
	 * group or a picture inside a Histeon page picks the site's look from the block's «Styles», without CSS.
	 *
	 * @return list<array{block: string, name: string, label: string, css: string}>
	 */
	public static function block_styles(): array {
		return array(
			array( 'block' => 'core/button', 'name' => 'xb-solid', 'label' => __( 'Histeon pieno', 'xb' ), 'css' => '.wp-block-button.is-style-xb-solid .wp-block-button__link{background:var(--xb-primary);color:var(--xb-on-primary,var(--xb-white));border-radius:var(--xb-radius-m);padding:var(--xb-space-xs) var(--xb-space-m);font-weight:600}' ),
			array( 'block' => 'core/button', 'name' => 'xb-outline', 'label' => __( 'Histeon contorno', 'xb' ), 'css' => '.wp-block-button.is-style-xb-outline .wp-block-button__link{background:transparent;color:var(--xb-primary);border:2px solid currentColor;border-radius:var(--xb-radius-m);padding:var(--xb-space-xs) var(--xb-space-m);font-weight:600}' ),
			array( 'block' => 'core/group', 'name' => 'xb-section', 'label' => __( 'Histeon sezione', 'xb' ), 'css' => '.wp-block-group.is-style-xb-section{padding:var(--xb-space-xl) var(--xb-space-m)}' ),
			array( 'block' => 'core/group', 'name' => 'xb-card', 'label' => __( 'Histeon scheda', 'xb' ), 'css' => '.wp-block-group.is-style-xb-card{padding:var(--xb-space-m);border-radius:var(--xb-radius-l);box-shadow:var(--xb-shadow-m);background:var(--xb-surface,var(--xb-white))}' ),
			array( 'block' => 'core/image', 'name' => 'xb-rounded', 'label' => __( 'Histeon arrotondata', 'xb' ), 'css' => '.wp-block-image.is-style-xb-rounded img{border-radius:var(--xb-radius-l)}' ),
			array( 'block' => 'core/quote', 'name' => 'xb-quote', 'label' => __( 'Histeon citazione', 'xb' ), 'css' => '.wp-block-quote.is-style-xb-quote{border-inline-start:4px solid var(--xb-primary);padding-inline-start:var(--xb-space-m);font-size:var(--xb-text-l)}' ),
		);
	}

	/** Registers block_styles() with WordPress: in the editor and on the site, their CSS comes with the block. */
	public static function register_block_styles(): void {
		if ( ! function_exists( 'register_block_style' ) ) {
			return;
		}
		foreach ( self::block_styles() as $style ) {
			register_block_style(
				$style['block'],
				array(
					'name'         => $style['name'],
					'label'        => $style['label'],
					'inline_style' => $style['css'],
				)
			);
		}
	}

	/** @return list<array{slug: string, name: string, size: string}> */
	public static function font_sizes(): array {
		$out = array();
		foreach ( array_keys( Tokens::TEXT ) as $step ) {
			$out[] = array(
				'slug' => 'xb-' . $step,
				'name' => 'Histeon ' . $step,
				'size' => 'var(--xb-text-' . $step . ')',
			);
		}
		return $out;
	}
}
