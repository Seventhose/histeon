<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\ComponentRegistry;

/**
 * A block of the article library used in Gutenberg enters the site as a component (Content\ArticleBlocks). Its key is
 * site-wide and its wp_block belongs to who creates it: only someone who may create components does.
 */
final class ArticleBlocksTest extends IntegrationTestCase {

	private function owned_by(): ?int {
		ComponentRegistry::forget_refs();
		$owner = ComponentRegistry::owner( 'articolo/riquadro' );
		return null === $owner ? null : (int) get_post_field( 'post_author', $owner );
	}

	public function test_an_author_cannot_claim_a_library_component_for_the_whole_site(): void {
		ComponentRegistry::forget_refs();
		if ( null !== ComponentRegistry::owner( 'articolo/riquadro' ) ) {
			$this->markTestSkipped( 'Il sito di sviluppo ha già il componente articolo/riquadro.' );
		}
		$article = '<!-- wp:xb/component {"ref":"articolo/riquadro","uid":"artq0001"} /-->';
		$author  = $this->user( 'author' );
		wp_set_current_user( $author );
		$this->post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $author, 'post_content' => $article ) );
		$this->assertNull( $this->owned_by(), 'no component from an author\'s article' );

		$builder = $this->user( 'editor' );
		( new \WP_User( $builder ) )->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		try {
			$this->post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $builder, 'post_content' => $article ) );
			$this->assertSame( $builder, $this->owned_by(), 'someone who may create components makes it' );
		} finally {
			// The shared dev site keeps no component from this test (a project import refuses a site with one).
			$made = get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => ComponentRegistry::META_KEY, 'meta_value' => 'articolo/riquadro' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $made as $id ) {
				wp_delete_post( (int) $id, true );
			}
			ComponentRegistry::forget_refs();
		}
	}
}
