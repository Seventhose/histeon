<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/** `site`: public site information, and the public fields of the plugins' options pages (`site.options`, D5). */
final class SiteNode implements Node {

	public function get( string $key ): mixed {
		return match ( $key ) {
			'name' => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url' => home_url( '/' ),
			'language' => get_bloginfo( 'language' ),
			'year' => (int) wp_date( 'Y' ),
			'logo' => ( static function (): ?ImageNode {
				$logo = (int) get_theme_mod( 'custom_logo' );
				return $logo ? ImageNode::for_attachment( $logo ) : null;
			} )(),
			'options' => new OptionsNode(),
			default => null,
		};
	}
}
