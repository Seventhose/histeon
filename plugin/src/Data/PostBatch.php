<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * Posts a loop lists together. When one of them reads its image or author, those of every post in the list are
 * loaded at once (two queries for the whole list instead of two for each item), and only then: a loop that
 * never shows images costs nothing more.
 */
final class PostBatch {

	private bool $images  = false;
	private bool $authors = false;

	/** @param list<\WP_Post> $posts */
	public function __construct( private readonly array $posts ) {}

	public function prime_images(): void {
		if ( $this->images ) {
			return;
		}
		$this->images = true;
		$query        = new \WP_Query();
		$query->posts = $this->posts;
		update_post_thumbnail_cache( $query );
	}

	public function prime_authors(): void {
		if ( $this->authors ) {
			return;
		}
		$this->authors = true;
		cache_users( array_values( array_unique( array_map( static fn ( \WP_Post $post ): int => (int) $post->post_author, $this->posts ) ) ) );
	}
}
