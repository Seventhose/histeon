<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Templates\Templates;

/** «Nuovo template» (docs/spec/templates.md). */
final class TemplatesCreateTest extends IntegrationTestCase {

	/** @var list<int> */
	private array $created = array();

	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
		parent::tearDown();
	}

	private function create( string $type, string $slug, string $title = '', string $area = 'uncategorized', string $from = '' ): int {
		$id              = Templates::create( $type, $slug, $title, $area, $from );
		$this->created[] = $id;
		return $id;
	}

	private function raw( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		return $post->post_content;
	}

	public function test_a_custom_template_is_offered_to_pages_and_a_hierarchy_one_applies_by_itself(): void {
		$custom = $this->create( 'wp_template', 'xb-test-landing', 'Landing di prova' );
		$this->assertSame( 'wp_template', get_post_type( $custom ) );
		$template = get_block_template( get_stylesheet() . '//xb-test-landing', 'wp_template' );
		$this->assertInstanceOf( \WP_Block_Template::class, $template );
		$this->assertTrue( $template->is_custom, 'a free name is a custom template' );
		$this->assertSame( (int) $custom, (int) $template->wp_id );
		$this->assertContains( 'xb-test-landing', array_column( get_block_templates( array( 'post_type' => 'page' ) ), 'slug' ), 'offered in the page editor' );

		$hierarchy = $this->create( 'wp_template', 'search', 'Ricerca' );
		$template  = get_block_template( get_stylesheet() . '//search', 'wp_template' );
		$this->assertInstanceOf( \WP_Block_Template::class, $template );
		$this->assertFalse( $template->is_custom, 'a hierarchy name applies by itself' );
		$this->assertSame( (int) $hierarchy, (int) $template->wp_id );
		$this->assertContains( 'create_template', array_column( \XB\Plugin::instance()->versioning->store()->audit_log( 5 ), 'action' ) );
	}

	public function test_a_part_gets_its_area_and_a_copy_starts_from_the_source(): void {
		$part = $this->create( 'wp_template_part', 'xb-test-banner', 'Banner', 'header' );
		$this->assertSame( array( 'header' ), wp_get_object_terms( $part, 'wp_template_part_area', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( get_stylesheet() ), wp_get_object_terms( $part, 'wp_theme', array( 'fields' => 'names' ) ) );

		$index = get_block_template( get_stylesheet() . '//index', 'wp_template' );
		$this->assertInstanceOf( \WP_Block_Template::class, $index );
		$copy = $this->create( 'wp_template', 'xb-test-copia', 'Copia', 'uncategorized', get_stylesheet() . '//index' );
		$this->assertSame( (string) $index->content, $this->raw( $copy ) );
		$this->assertSame( '', $this->raw( $this->create( 'wp_template', 'xb-test-vuoto' ) ), 'empty by default' );
	}

	public function test_bad_names_duplicates_and_foreign_sources_are_refused(): void {
		$this->create( 'wp_template', 'xb-test-unico' );
		foreach (
			array(
				array( 'wp_template', 'xb-test-unico', '' ),
				array( 'wp_template', '../x', '' ),
				array( 'wp_template', 'Con Spazi', '' ),
				array( 'wp_template', '', '' ),
				array( 'post', 'xb-test-tipo', '' ),
				array( 'wp_template', 'xb-test-fonte', 'altro-tema//index' ),
			) as [ $type, $slug, $from ]
		) {
			try {
				$this->created[] = Templates::create( $type, $slug, '', 'uncategorized', $from );
				$this->fail( "accepted: $type $slug $from" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_suggestions_come_from_the_hierarchy_and_skip_what_exists(): void {
		$suggestions = Templates::suggestions();
		$this->assertContains( '404', array_merge( $suggestions, array_column( Templates::all(), 'slug' ) ) );
		$this->assertNotContains( 'index', $suggestions, 'the theme has index already' );
		foreach ( $suggestions as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9_-]*$/', $slug );
		}
	}
}
