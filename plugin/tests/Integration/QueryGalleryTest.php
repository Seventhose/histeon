<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\Queries;

/** Lista della spesa 165: the gallery's queries (editor/src/lib/queries.ts, GALLERY) pass the server's checks as written. */
final class QueryGalleryTest extends IntegrationTestCase {

	public function test_the_gallery_queries_are_valid(): void {
		$gallery = array(
			'ultimiArticoli'     => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? 3', 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true ),
			'articoliCorrelati'  => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? 3', 'category__in' => array( '$categoria' ), 'post__not_in' => array( '$escludi' ), 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true ),
			'prodottiInEvidenza' => array( 'post_type' => 'product', 'posts_per_page' => '$count ?? 4', 'tax_query' => array( array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'featured' ) ) ), 'orderby' => 'date', 'order' => 'DESC' ),
			'eventiFuturi'       => array( 'post_type' => 'evento', 'posts_per_page' => '$count ?? 6', 'meta_key' => 'data', 'orderby' => 'meta_value', 'order' => 'ASC', 'meta_query' => array( array( 'key' => 'data', 'value' => '$today', 'compare' => '>=', 'type' => 'DATE' ) ) ),
		);
		$presets = array_map( static fn ( array $args ): array => array( 'type' => 'posts', 'args' => $args ), $gallery );
		$names = array_keys( $gallery );
		$valid = array_keys( Queries::validate( $presets ) );
		sort( $names );
		sort( $valid );
		$this->assertSame( $names, $valid );
	}
}
