<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Templates;

use XB\Plugin;

/**
 * The site's own templates follow it from XB Blank to Fondamenta and back — docs/spec/fondamenta.md §3.
 *
 * WordPress ties every template, part and global style saved in the database to the theme that was active (the
 * `wp_theme` term), and the custom CSS to its stylesheet name: after a switch they stay with the old theme and the
 * site loses its look. Between the themes of the same family (XB Blank, Fondamenta and the themes built on
 * Fondamenta) the switch moves them to the new theme instead, keeping the posts (same IDs, so the same Histeon
 * documents and history). A post the new theme already has with the same name stays where it is: the new theme's
 * wins, and the Registro says which ones were left.
 */
final class ThemeCarry {

	public const LEGACY = 'xb-blank';
	public const PARENT = 'fondamenta';

	/** Post types saved per theme with the `wp_theme` term. */
	private const TYPES = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	public static function register(): void {
		add_action( 'switch_theme', array( self::class, 'on_switch' ), 10, 3 );
	}

	/** Whether a theme belongs to the family: XB Blank, Fondamenta, or a theme built on Fondamenta. */
	public static function in_family( \WP_Theme $theme ): bool {
		return in_array( $theme->get_stylesheet(), array( self::LEGACY, self::PARENT ), true ) || self::PARENT === $theme->get_template();
	}

	/**
	 * @param string    $name      New theme name (unused).
	 * @param \WP_Theme $new_theme
	 * @param \WP_Theme $old_theme
	 */
	public static function on_switch( string $name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
		unset( $name );
		if ( ! self::in_family( $new_theme ) || ! self::in_family( $old_theme ) || $new_theme->get_stylesheet() === $old_theme->get_stylesheet() ) {
			return;
		}
		$done = self::carry( $old_theme->get_stylesheet(), $new_theme->get_stylesheet() );
		if ( array() !== $done['moved'] || array() !== $done['kept'] ) {
			Plugin::instance()->versioning->store()->audit( 'theme_carry', 'theme:' . $new_theme->get_stylesheet(), array( 'from' => $old_theme->get_stylesheet() ) + $done );
		}
	}

	/**
	 * Moves what the database keeps for theme $from to theme $to.
	 *
	 * @return array{moved: list<string>, kept: list<string>} Type and name of each post moved, or left with $from
	 *                                                       because $to already has one with that name.
	 */
	public static function carry( string $from, string $to ): array {
		$done = array(
			'moved' => array(),
			'kept'  => array(),
		);
		// What the new theme already has, by type and name (WP_Query drops tax_query when given a name).
		$taken = array();
		foreach ( self::posts( $to ) as $post ) {
			$taken[ $post->post_type . ':' . $post->post_name ] = $post;
		}
		foreach ( self::posts( $from ) as $post ) {
			$name = 'wp_global_styles' === $post->post_type ? 'wp-global-styles-' . rawurlencode( $to ) : $post->post_name;
			$what = $post->post_type . ':' . $post->post_name;
			$there = $taken[ $post->post_type . ':' . $name ] ?? null;
			if ( $there instanceof \WP_Post && 'wp_global_styles' === $there->post_type && self::empty_styles( $there ) ) {
				// The Site Editor creates the new theme's global styles empty on first look: they trade places.
				self::rename( $there, $from, 'wp-global-styles-' . rawurlencode( $from ) );
				$there = null;
			}
			if ( null !== $there ) {
				$done['kept'][] = $what;
				continue;
			}
			self::rename( $post, $to, $name );
			$done['moved'][] = $what;
		}
		// The custom CSS (Appearance → Customize → Additional CSS) is a post named after the stylesheet.
		$css = wp_get_custom_css_post( $from );
		if ( $css instanceof \WP_Post ) {
			if ( wp_get_custom_css_post( $to ) instanceof \WP_Post ) {
				$done['kept'][] = 'custom_css:' . $from;
			} else {
				wp_update_post(
					array(
						'ID'        => $css->ID,
						'post_name' => $to,
					)
				);
				if ( get_stylesheet() === $to ) {
					set_theme_mod( 'custom_css_post_id', $css->ID );
				}
				$done['moved'][] = 'custom_css:' . $from;
			}
		}
		return $done;
	}

	private static function rename( \WP_Post $post, string $theme, string $name ): void {
		wp_set_post_terms( $post->ID, array( $theme ), 'wp_theme' );
		if ( $name !== $post->post_name ) {
			wp_update_post(
				array(
					'ID'        => $post->ID,
					'post_name' => $name,
				)
			);
		}
	}

	/** Global styles with no settings and no styles of their own. */
	private static function empty_styles( \WP_Post $post ): bool {
		$data = json_decode( $post->post_content, true );
		return ! is_array( $data ) || ( empty( $data['settings'] ) && empty( $data['styles'] ) );
	}

	/**
	 * Posts a theme keeps in the database, every status but trash.
	 *
	 * @return list<\WP_Post>
	 */
	private static function posts( string $theme ): array {
		$args = array(
			'post_type'        => self::TYPES,
			'post_status'      => array( 'publish', 'draft', 'private', 'future', 'pending', 'auto-draft' ),
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'tax_query'        => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- templates of one theme.
		);
		return array_values( get_posts( $args ) );
	}
}
