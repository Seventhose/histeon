<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/** An image attachment: `image.url`, `image.alt`, `image.srcset`, or a registered size name (`image.medium`). */
final class ImageNode implements Node, \Stringable {

	private function __construct( private readonly int $id ) {}

	public static function for_attachment( int $id ): ?self {
		return wp_attachment_is_image( $id ) ? new self( $id ) : null;
	}

	public function get( string $key ): mixed {
		$full = wp_get_attachment_image_src( $this->id, 'full' );
		switch ( $key ) {
			case 'id':
				return $this->id;
			case 'url':
				return $full ? $full[0] : null;
			case 'width':
				return $full ? (int) $full[1] : null;
			case 'height':
				return $full ? (int) $full[2] : null;
			case 'alt':
				return (string) get_post_meta( $this->id, '_wp_attachment_image_alt', true );
			case 'caption':
				return wp_get_attachment_caption( $this->id ) ?: '';
			case 'title':
				return get_the_title( $this->id );
			case 'srcset':
				return wp_get_attachment_image_srcset( $this->id, 'full' ) ?: null;
			case 'sizes':
				// Text: the sizes attribute; `.sizes.medium_large.url`: that size (docs/spec/data.md).
				return new ImageSizesNode( $this->id );
			default:
				if ( in_array( $key, get_intermediate_image_sizes(), true ) ) {
					$sized = wp_get_attachment_image_src( $this->id, $key );
					return $sized ? $sized[0] : null;
				}
				return null;
		}
	}

	public function __toString(): string {
		return (string) $this->get( 'url' );
	}
}
