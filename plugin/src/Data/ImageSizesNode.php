<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * `image.sizes`: as text, the `sizes` attribute WordPress suggests (what Histeon always gave); followed by a size
 * name, that size of the image — `image.sizes.medium_large.url`, `.width`, `.height` — the shape of the data
 * in Etch, so imported pages keep their images.
 */
final class ImageSizesNode implements Node, \Stringable {

	public function __construct( private readonly int $id ) {}

	public function get( string $key ): mixed {
		if ( ! in_array( $key, get_intermediate_image_sizes(), true ) && 'full' !== $key ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $this->id, $key );
		if ( ! is_array( $src ) ) {
			return null;
		}
		return new class( (string) $src[0], (int) $src[1], (int) $src[2] ) implements Node, \Stringable {
			public function __construct( private readonly string $url, private readonly int $width, private readonly int $height ) {}

			public function get( string $key ): mixed {
				return match ( $key ) {
					'url' => $this->url,
					'width' => $this->width,
					'height' => $this->height,
					default => null,
				};
			}

			public function __toString(): string {
				return $this->url;
			}
		};
	}

	public function __toString(): string {
		return (string) wp_get_attachment_image_sizes( $this->id, 'full' );
	}
}
