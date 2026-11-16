<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Content\ContentTypes;
use XB\Remote\ContentProfileAdapter;
use XB\Versioning\Documents;

final class ContentProfileAdapterTest extends IntegrationTestCase {
	/** @var list<int> */
	private array $term_ids = array();
	/** @var array<string, bool> */
	private array $registered_types_before = array();
	private bool $registered_topic_before = false;

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'hp_article', 'hp_author', 'hp_organization', 'hp_region', 'hp_layout', 'hp_note' ) as $type ) {
			$this->registered_types_before[ $type ] = post_type_exists( $type );
		}
		$this->registered_topic_before = taxonomy_exists( 'hp_topic' );
	}

	public function test_record_deletion_detects_incoming_relations_but_ignores_records_deleted_in_the_same_transfer(): void {
		$target_id = '11111111-1111-4111-8111-111111111111';
		$owner_id = '22222222-2222-4222-8222-222222222222';
		$entities = array(
			array( 'id' => '33333333-3333-4333-8333-333333333333', 'kind' => 'content-type', 'payload' => array( 'key' => 'person', 'fields' => array() ) ),
			array( 'id' => '44444444-4444-4444-8444-444444444444', 'kind' => 'content-type', 'payload' => array( 'key' => 'article', 'fields' => array( array( 'key' => 'author', 'type' => 'relation' ) ) ) ),
			array( 'id' => $target_id, 'kind' => 'content-record', 'payload' => array( 'typeKey' => 'person', 'values' => array() ) ),
			array( 'id' => $owner_id, 'kind' => 'content-record', 'payload' => array( 'typeKey' => 'article', 'values' => array( 'author' => $target_id ) ) ),
		);
		$this->assertTrue( ContentProfileAdapter::has_incoming_record_reference( $target_id, $entities ) );
		$this->assertFalse( ContentProfileAdapter::has_incoming_record_reference( $target_id, $entities, array( $owner_id ) ) );
	}

	public function test_assigned_templates_are_used_by_wordpress_single_and_archive_hierarchies(): void {
		foreach ( array( ContentTypes::OPTION, 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_query_ids', 'xb_page_profile_source_ids', 'xb_page_profile_relational_bases' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		ContentTypes::forget();
		$type = array( 'id' => '11111111-1111-4111-8111-111111111111', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'a', 64 ), 'payload' => array( 'key' => 'hp_layout', 'label' => 'Layout', 'singleTemplateRef' => 'content/layout-single', 'archiveTemplateRef' => 'content/layout-archive', 'fields' => array() ) );
		$this->publish_entities( array( $type ) );

		$single_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_name' => 'histos-layout-single', 'post_title' => 'Histeon single layout', 'post_content' => '<!-- wp:paragraph --><p>Histeon single layout</p><!-- /wp:paragraph -->', 'post_status' => 'publish' ), true );
		$archive_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_name' => 'histos-layout-archive', 'post_title' => 'Histeon archive layout', 'post_content' => '<!-- wp:paragraph --><p>Histeon archive layout</p><!-- /wp:paragraph -->', 'post_status' => 'publish' ), true );
		$this->assertIsInt( $single_id );
		$this->assertIsInt( $archive_id );
		$this->track( $single_id );
		$this->track( $archive_id );
		foreach ( array( $single_id => 'content/layout-single', $archive_id => 'content/layout-archive' ) as $post_id => $ref ) {
			wp_set_post_terms( $post_id, array( get_stylesheet() ), 'wp_theme' );
			update_post_meta( $post_id, '_xb_page_profile_ref', $ref );
		}
		$theme_fallback_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_name' => 'single-hp_layout', 'post_title' => 'Theme fallback', 'post_content' => '<!-- wp:paragraph --><p>Theme fallback</p><!-- /wp:paragraph -->', 'post_status' => 'publish' ), true );
		$this->assertIsInt( $theme_fallback_id );
		$this->track( $theme_fallback_id );
		wp_set_post_terms( $theme_fallback_id, array( get_stylesheet() ), 'wp_theme' );

		$single = resolve_block_template( 'wp_template', array( 'single-hp_layout-layout-item', 'single-hp_layout', 'single' ), '' );
		$this->assertInstanceOf( \WP_Block_Template::class, $single );
		$this->assertSame( 'single-hp_layout', $single->slug );
		$this->assertSame( $single_id, (int) $single->wp_id );
		$this->assertStringContainsString( 'Histeon single layout', $single->content );
		$this->assertStringNotContainsString( 'Theme fallback', $single->content );

		$archive = resolve_block_template( 'wp_template', array( 'archive-hp_layout', 'archive' ), '' );
		$this->assertInstanceOf( \WP_Block_Template::class, $archive );
		$this->assertSame( 'archive-hp_layout', $archive->slug );
		$this->assertSame( $archive_id, (int) $archive->wp_id );
		$this->assertStringContainsString( 'Histeon archive layout', $archive->content );
	}

	public function test_public_query_filters_apply_to_wordpress_loops_and_fail_closed(): void {
		$this->set_option( ContentTypes::OPTION, get_option( ContentTypes::OPTION, array() ) );
		foreach ( array( 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_query_ids', 'xb_page_profile_source_ids', 'xb_page_profile_relational_bases', 'xb_page_profile_default_locale', 'xb_page_profile_relational_routes', \XB\Render\LoopPresets::OPTION, \XB\Render\Sources::OPTION ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		ContentTypes::forget();
		$entities = array(
			array( 'id' => '11111111-1111-4111-8111-111111111111', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'a', 64 ), 'payload' => array( 'key' => 'hp_article', 'label' => 'Articolo', 'singleTemplateRef' => 'content/article-single', 'archiveTemplateRef' => 'content/article-archive', 'fields' => array( array( 'key' => 'score', 'label' => 'Punteggio', 'type' => 'integer', 'required' => false, 'public' => true, 'multiple' => false ), array( 'key' => 'author', 'label' => 'Autore', 'type' => 'relation', 'targetType' => 'hp_author', 'required' => false, 'public' => true, 'multiple' => false ) ) ) ),
			array( 'id' => '88888888-8888-4888-8888-888888888888', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '0', 64 ), 'payload' => array( 'key' => 'hp_author', 'label' => 'Autore', 'fields' => array( array( 'key' => 'name', 'label' => 'Nome', 'type' => 'text', 'required' => false, 'public' => true, 'multiple' => false ), array( 'key' => 'organizations', 'label' => 'Organizzazioni', 'type' => 'relation', 'targetType' => 'hp_organization', 'required' => false, 'public' => true, 'multiple' => true ) ) ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777771', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '4', 64 ), 'payload' => array( 'key' => 'hp_organization', 'label' => 'Organizzazione', 'fields' => array( array( 'key' => 'regions', 'label' => 'Regioni', 'type' => 'relation', 'targetType' => 'hp_region', 'required' => false, 'public' => true, 'multiple' => true ) ) ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777772', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '5', 64 ), 'payload' => array( 'key' => 'hp_region', 'label' => 'Regione', 'public' => false, 'fields' => array( array( 'key' => 'code', 'label' => 'Codice', 'type' => 'text', 'required' => false, 'public' => true, 'multiple' => false ) ) ) ),
			array( 'id' => '44444444-4444-4444-8444-444444444444', 'kind' => 'taxonomy', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'd', 64 ), 'payload' => array( 'key' => 'hp_topic', 'label' => 'Argomento', 'types' => array( 'hp_article' ) ) ),
			array( 'id' => '55555555-5555-4555-8555-555555555555', 'kind' => 'term', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'e', 64 ), 'payload' => array( 'taxonomyKey' => 'hp_topic', 'slug' => 'technology', 'label' => 'Tecnologia', 'parentId' => null ) ),
			array( 'id' => '22222222-2222-4222-8222-222222222222', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'b', 64 ), 'payload' => array( 'typeKey' => 'hp_article', 'slug' => 'alto', 'locale' => 'it-IT', 'title' => 'Punteggio alto', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'score' => 10, 'author' => '99999999-9999-4999-8999-999999999999' ), 'terms' => array( 'hp_topic' => array( '55555555-5555-4555-8555-555555555555' ) ) ) ),
			array( 'id' => '33333333-3333-4333-8333-333333333333', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'c', 64 ), 'payload' => array( 'typeKey' => 'hp_article', 'slug' => 'basso', 'locale' => 'it-IT', 'title' => 'Punteggio basso', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'score' => 3 ), 'terms' => array() ) ),
			array( 'id' => '66666666-6666-4666-8666-666666666666', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'f', 64 ), 'payload' => array( 'typeKey' => 'hp_article', 'slug' => 'alto', 'locale' => 'en-US', 'title' => 'High score', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'score' => 12 ), 'terms' => array() ) ),
			array( 'id' => '99999999-9999-4999-8999-999999999999', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '1', 64 ), 'payload' => array( 'typeKey' => 'hp_author', 'slug' => 'ada', 'locale' => 'it-IT', 'title' => 'Ada Lovelace', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'name' => 'Ada', 'organizations' => array( '77777777-7777-4777-8777-777777777773', '77777777-7777-4777-8777-777777777776' ) ), 'terms' => array() ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777773', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '6', 64 ), 'payload' => array( 'typeKey' => 'hp_organization', 'slug' => 'studio', 'locale' => 'it-IT', 'title' => 'Studio', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'regions' => array( '77777777-7777-4777-8777-777777777774', '77777777-7777-4777-8777-777777777775' ) ), 'terms' => array() ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777776', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '9', 64 ), 'payload' => array( 'typeKey' => 'hp_organization', 'slug' => 'secondo-studio', 'locale' => 'it-IT', 'title' => 'Secondo studio', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'regions' => array( '77777777-7777-4777-8777-777777777775' ) ), 'terms' => array() ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777774', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '7', 64 ), 'payload' => array( 'typeKey' => 'hp_region', 'slug' => 'italia', 'locale' => 'it-IT', 'title' => 'Italia', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'code' => 'IT' ), 'terms' => array() ) ),
			array( 'id' => '77777777-7777-4777-8777-777777777775', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '8', 64 ), 'payload' => array( 'typeKey' => 'hp_region', 'slug' => 'usa', 'locale' => 'en-US', 'title' => 'Stati Uniti', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'code' => 'US' ), 'terms' => array() ) ),
			array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'kind' => 'content-record', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '3', 64 ), 'payload' => array( 'typeKey' => 'hp_article', 'slug' => 'senza', 'locale' => 'it-IT', 'title' => 'Senza punteggio', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array(), 'terms' => array() ) ),
			array( 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'kind' => 'query-preset', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '4', 64 ), 'payload' => array( 'key' => 'articles_by_score', 'label' => 'Articoli per punteggio', 'typeKey' => 'hp_article', 'limit' => 10, 'orderBy' => 'score', 'order' => 'asc' ) ),
			array( 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'kind' => 'query-preset', 'schemaVersion' => 1, 'revisionHash' => str_repeat( '2', 64 ), 'payload' => array( 'key' => 'articles_by_author', 'label' => 'Articoli per autore', 'typeKey' => 'hp_article', 'limit' => 10, 'orderBy' => 'title', 'order' => 'asc', 'filter' => array( 'field' => 'author.organizations.regions.code', 'op' => 'eq', 'value' => 'IT' ) ) ),
		);
		$invalid_entities = $entities;
		foreach ( $invalid_entities as &$invalid_entity ) {
			if ( 'content-record' === $invalid_entity['kind'] && 'alto' === ( $invalid_entity['payload']['slug'] ?? null ) ) {
				$invalid_entity['payload']['values']['score'] = '10 punti';
				break;
			}
		}
		unset( $invalid_entity );
		try {
			ContentProfileAdapter::preflight( $invalid_entities );
			$this->fail( 'Preflight must reject a field value that WordPress would change.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'WordPress', $error->getMessage() );
		}
		$this->publish_entities( $entities );
		$high = get_posts( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => '22222222-2222-4222-8222-222222222222' ) );
		$low = get_posts( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => '33333333-3333-4333-8333-333333333333' ) );
		$this->assertCount( 1, $high );
		$this->assertCount( 1, $low );
		$this->track( $high[0]->ID );
		$this->track( $low[0]->ID );
		$translated = get_posts( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => '66666666-6666-4666-8666-666666666666' ) );
		$this->assertCount( 1, $translated );
		$this->track( $translated[0]->ID );
		foreach ( array( '99999999-9999-4999-8999-999999999999', '77777777-7777-4777-8777-777777777773', '77777777-7777-4777-8777-777777777774', '77777777-7777-4777-8777-777777777775', '77777777-7777-4777-8777-777777777776' ) as $record_id ) {
			$related = get_posts( array( 'post_type' => array( 'hp_author', 'hp_organization', 'hp_region' ), 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) );
			$this->assertCount( 1, $related );
			$this->track( $related[0]->ID );
		}

		$query = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'score', 'op' => 'gte', 'value' => 8 ) ) );
		$this->assertEqualsCanonicalizing( array( $high[0]->ID, $translated[0]->ID ), wp_list_pluck( $query->posts, 'ID' ) );
		$author_query = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'author', 'op' => 'eq', 'value' => '99999999-9999-4999-8999-999999999999' ) ) );
		$this->assertSame( array( $high[0]->ID ), wp_list_pluck( $author_query->posts, 'ID' ) );
		$nested_query = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'author.organizations.regions.code', 'op' => 'eq', 'value' => 'IT' ) ) );
		$this->assertSame( array( $high[0]->ID ), wp_list_pluck( $nested_query->posts, 'ID' ) );
		$multiple_relation_query = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'author.organizations.regions.code', 'op' => 'eq', 'value' => 'US' ) ) );
		$this->assertSame( array( $high[0]->ID ), wp_list_pluck( $multiple_relation_query->posts, 'ID' ) );
		$multiple_relation_exists_query = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'author.organizations.regions', 'op' => 'exists' ) ) );
		$this->assertSame( array( $high[0]->ID ), wp_list_pluck( $multiple_relation_exists_query->posts, 'ID' ) );

		$private_filter = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'field' => 'missing_private_field', 'op' => 'eq', 'value' => 'x' ) ) );
		$this->assertSame( array(), $private_filter->posts );
		$taxonomy_filter = new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_filter' => array( 'taxonomyKey' => 'hp_topic', 'termIds' => array( '55555555-5555-4555-8555-555555555555' ), 'operator' => 'IN' ) ) );
		$this->assertSame( array( $high[0]->ID ), wp_list_pluck( $taxonomy_filter->posts, 'ID' ) );

		// Doc 23 item 232: ordered by a field as Publish orders it: numbers as numbers (3, 10, 12, never "10" before
		// "3"), a record without the value first going up and last going down, and still in the list.
		$none = get_posts( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );
		$this->assertCount( 1, $none );
		$this->track( $none[0]->ID );
		$by_score = static fn ( array $order ): array => wp_list_pluck( ( new \WP_Query( array_merge( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_order_field' => 'score' ), $order ) ) )->posts, 'ID' );
		$ascending = array( $none[0]->ID, $low[0]->ID, $high[0]->ID, $translated[0]->ID );
		$this->assertSame( $ascending, $by_score( array( 'orderby' => 'menu_order', 'order' => 'ASC' ) ) );
		$this->assertSame( array_reverse( $ascending ), $by_score( array( 'orderby' => 'menu_order', 'order' => 'DESC' ) ) );
		// The shape LoopPresets::stable_order gives a preset's query: the direction is in the orderby list.
		$this->assertSame( $ascending, $by_score( \XB\Render\LoopPresets::stable_order( array( 'orderby' => 'menu_order', 'order' => 'ASC' ) ) ) );
		// A field that cannot order (a relation) leaves WordPress's own order, with every record.
		$this->assertCount( 4, ( new \WP_Query( array( 'post_type' => 'hp_article', 'post_status' => 'publish', 'posts_per_page' => 10, 'xb_type_key' => 'hp_article', 'xb_order_field' => 'author' ) ) )->posts );
		$presets = \XB\Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload']['presets'] ?? array();
		$this->assertSame( 'score', $presets['articles_by_score']['args']['xb_order_field'] ?? null );
		$this->assertSame( 'menu_order', $presets['articles_by_score']['args']['orderby'] ?? null );

		$files = array();
		$snapshot = ContentProfileAdapter::snapshot( false, $files );
		$portable_type = current( array_filter( $snapshot, static fn ( array $entity ): bool => 'content-type' === $entity['kind'] && 'hp_article' === $entity['payload']['key'] ) );
		if ( ! is_array( $portable_type ) ) {
			throw new \RuntimeException( 'Il tipo relazionale non è stato incluso nello snapshot.' );
		}
		$this->assertSame( 'content/article-single', $portable_type['payload']['singleTemplateRef'] );
		$this->assertSame( 'content/article-archive', $portable_type['payload']['archiveTemplateRef'] );
		// Doc 23 item 122: a type without pages of its own arrives as such and goes back as such.
		$this->assertFalse( ContentTypes::live()['types']['hp_region']['public'] );
		$this->assertTrue( ContentTypes::live()['types']['hp_article']['public'] );
		$portable_region = current( array_filter( $snapshot, static fn ( array $entity ): bool => 'content-type' === $entity['kind'] && 'hp_region' === $entity['payload']['key'] ) );
		$this->assertIsArray( $portable_region );
		$this->assertFalse( $portable_region['payload']['public'] );
		$this->assertArrayNotHasKey( 'public', $portable_type['payload'] );
		$portable_term = current( array_filter( $snapshot, static fn ( array $entity ): bool => 'term' === $entity['kind'] && '55555555-5555-4555-8555-555555555555' === $entity['id'] ) );
		if ( ! is_array( $portable_term ) ) {
			throw new \RuntimeException( 'Il termine della tassonomia non è stato incluso nello snapshot.' );
		}
		$this->assertSame( 'technology', $portable_term['payload']['slug'] );
		$portable_query = current( array_filter( $snapshot, static fn ( array $entity ): bool => 'query-preset' === $entity['kind'] && 'articles_by_score' === $entity['payload']['key'] ) );
		$this->assertIsArray( $portable_query );
		$this->assertSame( 'score', $portable_query['payload']['orderBy'], 'a query ordered by a field goes back to Publish as such' );

		// No custom rewrite rules: the default language keeps the portable slug, another language gets a suffix.
		$this->assertSame( 'alto', get_post_field( 'post_name', $high[0]->ID ) );
		$this->assertSame( 'alto-en-us', get_post_field( 'post_name', $translated[0]->ID ) );

		$terms = get_terms( array( 'taxonomy' => 'hp_topic', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			throw new \RuntimeException( 'Impossibile elencare i termini creati dal test: ' . $terms->get_error_message() );
		}
		$this->term_ids = array_values( array_map( static fn ( \WP_Term $term ): int => (int) $term->term_id, $terms ) );
	}

	protected function tearDown(): void {
		foreach ( $this->term_ids as $id ) { wp_delete_term( $id, 'hp_topic' ); }
		parent::tearDown();
		if ( ! $this->registered_topic_before && taxonomy_exists( 'hp_topic' ) ) { unregister_taxonomy( 'hp_topic' ); }
		foreach ( $this->registered_types_before as $post_type => $was_registered ) {
			if ( ! $was_registered && post_type_exists( $post_type ) ) { unregister_post_type( $post_type ); }
		}
		ContentTypes::forget();
		ContentTypes::register();
	}

	/**
	 * What a CMS publish does with these entities: settings into development, a release, types registered, then
	 * contents with their own statuses.
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	private function publish_entities( array $entities, string $locale = 'it-IT' ): void {
		$plan = ContentProfileAdapter::preflight( $entities );
		$this->stage( $plan );
		\XB\Plugin::instance()->versioning->publish( null, 'Histeon CMS (test)', array( Documents::SETTINGS ) );
		ContentTypes::forget();
		ContentTypes::register();
		ContentProfileAdapter::publish_layouts( $plan );
		ContentProfileAdapter::apply_contents( $plan, array(), true, $locale );
		ContentTypes::forget();
	}

	/** @param array<string, mixed> $plan */
	private function stage( array $plan ): void {
		$versioning = \XB\Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::SETTINGS );
		$versioning->save_dev( Documents::SETTINGS, array_merge( $copy['payload'], ContentProfileAdapter::staged_settings( $plan ) ), $copy['head'], $copy['base'], 'test' );
		ContentProfileAdapter::stage_identities( $plan );
	}

	public function test_sending_without_publishing_leaves_the_live_site_alone(): void {
		foreach ( array( ContentTypes::OPTION, 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_default_locale', ContentProfileAdapter::QUEUE, \XB\Render\LoopPresets::OPTION ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		ContentTypes::forget();
		$type   = array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb1', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'a', 64 ), 'payload' => array( 'key' => 'hp_note', 'label' => 'Nota', 'fields' => array( array( 'key' => 'rating', 'label' => 'Voto', 'type' => 'integer', 'required' => false, 'public' => true, 'multiple' => false ) ) ) );
		$record = static function ( string $title, int $rating ): array {
			$entity = array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2', 'kind' => 'content-record', 'schemaVersion' => 1, 'payload' => array( 'typeKey' => 'hp_note', 'slug' => 'uno', 'locale' => 'it-IT', 'title' => $title, 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array( 'rating' => $rating ), 'terms' => array() ) );
			return $entity + array( 'revisionHash' => \XB\Remote\PageProfile::revision_hash( $entity ) );
		};
		$find = static fn (): array => get_posts( array( 'post_type' => 'hp_note', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2' ) );

		// A new type and its record, sent without publishing: the type is only in development, the record waits.
		$plan = ContentProfileAdapter::preflight( array( $type, $record( 'Primo', 1 ) ) );
		$this->stage( $plan );
		$this->assertSame( array( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2' ), ContentProfileAdapter::queue_contents( $plan, array(), 'it-IT' ) );
		ContentTypes::forget();
		$this->assertArrayNotHasKey( 'hp_note', ContentTypes::live()['types'], 'not registered before a release' );
		$files    = array();
		$exported = array_column( ContentProfileAdapter::snapshot( false, $files ), null, 'id' );
		$this->assertSame( 'Primo', $exported['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2']['payload']['title'] ?? null, 'the export shows development' );

		// The next Histeon release registers the type and applies the queue.
		\XB\Plugin::instance()->versioning->publish( null, 'test', array( Documents::SETTINGS ) );
		$posts = $find();
		$this->assertCount( 1, $posts );
		$this->track( $posts[0]->ID );
		$this->assertSame( 'publish', $posts[0]->post_status );
		$this->assertSame( array(), ContentProfileAdapter::queue()['entities'] );

		// A change sent without publishing: the published record does not change until the next release.
		$plan = ContentProfileAdapter::preflight( array( $record( 'Modificato', 2 ) ) );
		ContentProfileAdapter::queue_contents( $plan, array(), 'it-IT' );
		$this->assertSame( 'Primo', get_post_field( 'post_title', $posts[0]->ID ) );
		$this->assertSame( '1', (string) get_post_meta( $posts[0]->ID, 'rating', true ) );
		ContentProfileAdapter::apply_queue( 0 );
		$this->assertSame( 'Modificato', get_post_field( 'post_title', $posts[0]->ID ) );
		$this->assertSame( '2', (string) get_post_meta( $posts[0]->ID, 'rating', true ) );
	}

	/**
	 * Doc 23 item 202 of Histeon Publish: a record with two terms out of their name order came back from WordPress in
	 * name order, with another revision hash, and Publish refused the first send with publishing (heads-invalid).
	 */
	public function test_a_record_keeps_the_order_of_its_terms_as_sent(): void {
		foreach ( array( ContentTypes::OPTION, 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_default_locale', ContentProfileAdapter::QUEUE, \XB\Render\LoopPresets::OPTION ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		ContentTypes::forget();
		$hash   = static fn ( array $entity ): array => $entity + array( 'revisionHash' => \XB\Remote\PageProfile::revision_hash( $entity ) );
		$zeta   = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc4';
		$alfa   = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc5';
		$record = $hash( array( 'id' => 'cccccccc-cccc-4ccc-8ccc-ccccccccccc2', 'kind' => 'content-record', 'schemaVersion' => 1, 'payload' => array( 'typeKey' => 'hp_tile', 'slug' => 'uno', 'locale' => 'it-IT', 'title' => 'Uno', 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array(), 'terms' => array( 'hp_mood' => array( $zeta, $alfa ) ) ) ) );
		$entities = array(
			$hash( array( 'id' => 'cccccccc-cccc-4ccc-8ccc-ccccccccccc1', 'kind' => 'content-type', 'schemaVersion' => 1, 'payload' => array( 'key' => 'hp_tile', 'label' => 'Tessera', 'fields' => array() ) ) ),
			$hash( array( 'id' => 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3', 'kind' => 'taxonomy', 'schemaVersion' => 1, 'payload' => array( 'key' => 'hp_mood', 'label' => 'Umore', 'types' => array( 'hp_tile' ) ) ) ),
			$hash( array( 'id' => $zeta, 'kind' => 'term', 'schemaVersion' => 1, 'payload' => array( 'taxonomyKey' => 'hp_mood', 'slug' => 'zeta', 'label' => 'Zeta', 'parentId' => null ) ) ),
			$hash( array( 'id' => $alfa, 'kind' => 'term', 'schemaVersion' => 1, 'payload' => array( 'taxonomyKey' => 'hp_mood', 'slug' => 'alfa', 'label' => 'Alfa', 'parentId' => null ) ) ),
			$record,
		);
		$this->publish_entities( $entities );
		ContentTypes::register();
		$posts = get_posts( array( 'post_type' => 'hp_tile', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record['id'] ) );
		$this->assertCount( 1, $posts );
		$this->track( $posts[0]->ID );
		$files    = array();
		$exported = array_column( ContentProfileAdapter::snapshot( false, $files ), null, 'id' );
		$this->assertSame( array( $zeta, $alfa ), $exported[ $record['id'] ]['payload']['terms']['hp_mood'] ?? null, 'the order sent, not WordPress\'s order by name' );
		$this->assertSame( $record['revisionHash'], $exported[ $record['id'] ]['revisionHash'], 'the same revision Publish sent' );

		// Changed in WordPress: other terms, read as WordPress orders them.
		wp_set_object_terms( $posts[0]->ID, array( 'alfa' ), 'hp_mood', false );
		$exported = array_column( ContentProfileAdapter::snapshot( false, $files ), null, 'id' );
		$this->assertSame( array( $alfa ), $exported[ $record['id'] ]['payload']['terms']['hp_mood'] ?? null );
		$terms = get_terms( array( 'taxonomy' => 'hp_mood', 'hide_empty' => false ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			wp_delete_term( $term->term_id, 'hp_mood' );
		}
		// Registered in this process by the release: later tests (the static export) must not see the type.
		unregister_taxonomy( 'hp_mood' );
		unregister_post_type( 'hp_tile' );
		ContentTypes::forget();
	}

	/** Note 89 of the 3 November audit: a record title is text; markup WordPress would strip stops the send in the preflight. */
	public function test_a_record_title_with_markup_is_refused_in_the_preflight(): void {
		$type   = array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbc1', 'kind' => 'content-type', 'schemaVersion' => 1, 'revisionHash' => str_repeat( 'a', 64 ), 'payload' => array( 'key' => 'hp_titolo', 'label' => 'Titolo', 'fields' => array() ) );
		$record = static function ( string $title ): array {
			$entity = array( 'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbc2', 'kind' => 'content-record', 'schemaVersion' => 1, 'payload' => array( 'typeKey' => 'hp_titolo', 'slug' => 'uno', 'locale' => 'it-IT', 'title' => $title, 'status' => 'published', 'publishedAt' => '2026-10-23T10:00:00Z', 'excerpt' => '', 'order' => 0, 'values' => array(), 'terms' => array() ) );
			return $entity + array( 'revisionHash' => \XB\Remote\PageProfile::revision_hash( $entity ) );
		};
		$this->assertArrayHasKey( 'entities', ContentProfileAdapter::preflight( array( $type, $record( 'Rossi & figli' ) ) ), 'a plain & is no markup' );
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbc2' );
		ContentProfileAdapter::preflight( array( $type, $record( 'Nota<script>alert(1)</script>' ) ) );
	}
}
