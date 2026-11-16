<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Loops load the images and authors of all their items together, and only when the loop shows them. */
final class LoopBatchTest extends IntegrationTestCase {

	/** @var list<int> */
	private array $terms = array();

	protected function tearDown(): void {
		parent::tearDown();
		foreach ( $this->terms as $term ) {
			wp_delete_term( $term, 'category' );
		}
	}

	private function category( string $name ): int {
		$term = wp_insert_term( $name . '-' . wp_generate_password( 6, false, false ), 'category' );
		$this->assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	/** Posts of a category of their own, each with a featured image and an author of its own. */
	private function posts( int $count, int $category ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$author = $this->user( 'author' );
			$image  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'loopbatch', 'post_status' => 'inherit' ), 'loopbatch-' . $i . '.png' );
			$this->assertGreaterThan( 0, $image );
			$this->track( $image );
			wp_update_attachment_metadata( $image, array( 'width' => 80, 'height' => 60, 'file' => 'loopbatch-' . $i . '.png', 'sizes' => array() ) );
			update_post_meta( $image, '_wp_attachment_image_alt', 'alt ' . $i );
			$post = $this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $author, 'post_category' => array( $category ) ) );
			set_post_thumbnail( $post, $image );
		}
	}

	/** Queries made to render a loop over the category, with an empty object cache (a fresh request). */
	private function queries( int $category, string $text ): int {
		global $wpdb;
		$blocks = array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'category' => (string) $category, 'limit' => '20' ) ), 'as' => 'p' ), array( $this->text( $text ) ) ) );
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$html   = $this->render( $blocks );
		$this->assertStringContainsString( '[', $html );
		return $wpdb->num_queries - $before;
	}

	public function test_images_and_authors_do_not_cost_queries_per_item(): void {
		$small = $this->category( 'loopbatch-small' );
		$large = $this->category( 'loopbatch-large' );
		$this->posts( 2, $small );
		$this->posts( 8, $large );
		$text = '[{p.title}|{p.image.url}|{p.image.alt}|{p.author.name}]';

		$html = $this->render( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'category' => (string) $large ) ), 'as' => 'p' ), array( $this->text( $text ) ) ) ) );
		$this->assertSame( 8, substr_count( $html, 'loopbatch-' ), 'every item shows its image' );
		$this->assertSame( 8, substr_count( $html, '|alt ' ) );

		$this->assertSame( $this->queries( $small, $text ), $this->queries( $large, $text ), 'four times the items, the same queries' );
		$this->assertLessThan( $this->queries( $large, $text ), $this->queries( $large, '[{p.title}]' ), 'a loop without images loads none' );
	}
}
