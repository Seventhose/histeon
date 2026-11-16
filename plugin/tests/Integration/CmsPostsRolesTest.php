<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;

/**
 * Posts, categories and tags, and template roles in the `.xbsite` v2 profile (docs/spec/cms-bridge.md §12).
 */
final class CmsPostsRolesTest extends IntegrationTestCase {
	private const POST_ID     = '550e8400-e29b-41d4-a716-446655440301';
	private const TEMPLATE_ID = '550e8400-e29b-41d4-a716-446655440302';
	private const CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440303';
	private const TAG_ID      = '550e8400-e29b-41d4-a716-446655440304';
	private const TERM_ID     = '550e8400-e29b-41d4-a716-446655440305';

	/** @var list<array{id: int, taxonomy: string}> */
	private array $created_terms = array();

	protected function tearDown(): void {
		foreach ( $this->created_terms as $term ) {
			wp_delete_term( $term['id'], $term['taxonomy'] );
		}
		$this->created_terms = array();
		// An export gives every category and tag a project identity: the next test starts from an unpaired site.
		$this->forget_term_identities();
		parent::tearDown();
	}

	private function forget_term_identities(): void {
		// Terms a release created after the send (it applies the queue) are this test's too.
		$released = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::TERM_ID ) );
		foreach ( is_array( $released ) ? $released : array() as $term_id ) {
			wp_delete_term( (int) $term_id, 'category' );
		}
		$terms = get_terms( array( 'taxonomy' => array( 'category', 'post_tag' ), 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			foreach ( array( '_xb_page_profile_id', '_xb_page_profile_base_hash', '_xb_page_profile_term_slug' ) as $key ) {
				delete_term_meta( (int) $term_id, $key );
			}
		}
	}

	/**
	 * @param array<string, mixed> $entity
	 * @return array<string, mixed>
	 */
	private function hashed( array $entity ): array {
		$entity['schemaVersion'] = 1;
		$entity['revisionHash']  = PageProfile::revision_hash( $entity );
		return $entity;
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function post_entity( array $changes = array() ): array {
		return $this->hashed(
			array(
				'id'      => self::POST_ID,
				'kind'    => 'document',
				'payload' => array_merge(
					array(
						'documentType' => 'post',
						'title'        => 'Prima notizia',
						'slug'         => 'prima-notizia',
						'status'       => 'published',
						'content'      => '<!-- wp:xb/element {"uid":"postroot001","tag":"article"} --><!-- wp:xb/text {"uid":"posttext001","content":"Ciao dal blog"} /--><!-- /wp:xb/element -->',
						'script'       => '',
						'publishedAt'  => '2026-10-20T08:30:00Z',
						'excerpt'      => 'Una notizia breve.',
						'terms'        => array( 'category' => array( self::TERM_ID ) ),
						'templateRef'  => 'site/single',
					),
					$changes
				),
			)
		);
	}

	/**
	 * The entity with the base Histeon CMS keeps for it: the revision WordPress exports now.
	 *
	 * @param array<string, mixed> $entity
	 * @return array<string, mixed>
	 */
	private function rebased( array $entity ): array {
		$current = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$entity['baseHash'] = $current[ $entity['id'] ]['revisionHash'];
		return $entity;
	}

	/** @return array<string, mixed> */
	private function template_entity( ?string $role = 'single' ): array {
		$payload = array(
			'title'   => 'Articolo',
			'ref'     => 'site/single',
			'schema'  => array( 'props' => array( 'page' => array( 'type' => 'object', 'default' => array( 'title' => '', 'slug' => '', 'url' => '/' ) ) ) ),
			'content' => '<!-- wp:xb/element {"uid":"singleroot1","tag":"main"} --><!-- wp:xb/text {"uid":"singletext1","content":"{props.page.title}"} /--><!-- wp:xb/slot {"uid":"singleslot1","name":"default"} /--><!-- /wp:xb/element -->',
			'script'  => '',
		);
		if ( null !== $role ) {
			$payload['role'] = $role;
		}
		return $this->hashed( array( 'id' => self::TEMPLATE_ID, 'kind' => 'template', 'payload' => $payload ) );
	}

	/** @return list<array<string, mixed>> */
	private function taxonomy_entities( string $term_slug = 'notizie' ): array {
		return array(
			$this->hashed( array( 'id' => self::CATEGORY_ID, 'kind' => 'taxonomy', 'payload' => array( 'key' => 'category', 'label' => 'Categorie', 'types' => array( 'post' ) ) ) ),
			$this->hashed( array( 'id' => self::TAG_ID, 'kind' => 'taxonomy', 'payload' => array( 'key' => 'post_tag', 'label' => 'Tag', 'types' => array( 'post' ) ) ) ),
			$this->hashed( array( 'id' => self::TERM_ID, 'kind' => 'term', 'payload' => array( 'taxonomyKey' => 'category', 'slug' => $term_slug, 'label' => 'Notizie', 'parentId' => null ) ) ),
		);
	}

	/**
	 * Sends the page fixture plus the given entities, as Histeon CMS would.
	 *
	 * @param list<array<string, mixed>> $extra
	 * @return array<string, mixed>
	 */
	private function send( array $extra, bool $publish, bool $check_only = false ): array {
		global $wpdb;
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		/** @var list<array<string, mixed>> $entities */
		$entities   = array_values( array_merge( $fixture['profile']['entities'], $extra ) );
		$references = $fixture['profile']['references'];
		$has_posts  = (bool) array_filter( $entities, static fn ( array $entity ): bool => 'document' === $entity['kind'] && 'post' === $entity['payload']['documentType'] );
		$has_roles  = (bool) array_filter( $entities, static fn ( array $entity ): bool => 'template' === $entity['kind'] && isset( $entity['payload']['role'] ) );
		$has_templates = (bool) array_filter( $entities, static fn ( array $entity ): bool => 'template' === $entity['kind'] );
		$has_terms  = (bool) array_filter( $entities, static fn ( array $entity ): bool => in_array( $entity['kind'], array( 'taxonomy', 'term' ), true ) );
		$manifest['profile'] = PageProfile::expected_profile( $has_posts || $has_roles, $has_terms, $has_templates, false );
		$manifest['requiredCapabilities'] = array_merge(
			array( 'documents:page:v1', 'styles:global:v1', 'media:asset:v1' ),
			$has_templates ? array( 'templates:definition:v1' ) : array(),
			$has_terms ? array( 'content:taxonomies:v1' ) : array(),
			$has_posts ? array( 'documents:post:v1' ) : array(),
			$has_roles ? array( 'templates:roles:v1' ) : array()
		);
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$path  = $file['path'];
			$bytes = 'entities.ndjson' === $path
				? implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n"
				: ( 'references.ndjson' === $path ? implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n" : (string) base64_decode( $fixture['files'][ $path ], true ) );
			$files[ $path ]  = $bytes;
			$file['bytes']   = strlen( $bytes );
			$file['sha256']  = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_relational_bases', 'xb_page_profile_default_locale', 'xb_cms_pending_contents', 'xb_remote_operation_' . $manifest['selection']['snapshot'], 'xb_remote_project_lock_' . $manifest['project']['id'] ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$posts_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ), true );
		$terms_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" ) ), true );
		try {
			return PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $files ), $publish, $check_only );
		} finally {
			foreach ( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ) as $post_id ) {
				if ( ! isset( $posts_before[ $post_id ] ) ) {
					$this->track( $post_id );
				}
			}
			foreach ( $wpdb->get_results( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}", ARRAY_A ) as $row ) {
				if ( ! isset( $terms_before[ (int) $row['term_id'] ] ) ) {
					$this->created_terms[] = array( 'id' => (int) $row['term_id'], 'taxonomy' => (string) $row['taxonomy'] );
				}
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $extra
	 * @return array<string, mixed>
	 */
	private function send_check( array $extra ): array {
		return $this->send( $extra, false, true );
	}

	private function sent_post(): \WP_Post {
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::POST_ID ) );
		$this->assertCount( 1, $posts );
		return $posts[0];
	}

	public function test_a_post_with_its_category_and_a_single_template_round_trips(): void {
		$sent = array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() );
		$report = $this->send( $sent, true );
		$this->assertTrue( $report['complete'] );

		$template = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::TEMPLATE_ID ) );
		$this->assertCount( 1, $template );
		$this->assertSame( 'single', $template[0]->post_name, 'A template with a role takes the role\'s slug in the active theme.' );

		$post = $this->sent_post();
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'prima-notizia', $post->post_name );
		$this->assertSame( 'Una notizia breve.', $post->post_excerpt );
		$this->assertSame( '2026-10-20 08:30:00', $post->post_date_gmt );
		$this->assertSame( 'single', get_post_meta( $post->ID, '_wp_page_template', true ) );
		$categories = wp_get_object_terms( $post->ID, 'category' );
		$this->assertIsArray( $categories );
		$this->assertSame( array( 'notizie' ), array_map( static fn ( \WP_Term $term ): string => $term->slug, $categories ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertSame( PageProfile::POSTS_PROJECT_PROFILE, $exported['manifest']['profile'] );
		$this->assertContains( 'documents:post:v1', $exported['manifest']['requiredCapabilities'] );
		$this->assertContains( 'templates:roles:v1', $exported['manifest']['requiredCapabilities'] );
		$by_id = array_column( $exported['entities'], null, 'id' );
		$this->assertSame( $this->post_entity()['revisionHash'], $by_id[ self::POST_ID ]['revisionHash'], 'The post comes back exactly as it was sent.' );
		$this->assertSame( 'single', $by_id[ self::TEMPLATE_ID ]['payload']['role'] );
		$this->assertSame( array( 'post' ), $by_id[ self::CATEGORY_ID ]['payload']['types'] );
		$this->assertSame( 'notizie', $by_id[ self::TERM_ID ]['payload']['slug'] );
	}

	/**
	 * Doc 23 item 119: the featured image travels as `image`, becomes the post's thumbnail and comes back the same; a
	 * post without one keeps its fingerprint, and a send without the key takes the thumbnail away.
	 */
	public function test_a_post_featured_image_round_trips_as_its_thumbnail(): void {
		$image = '42345678-1234-4234-8234-123456789abc';
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity( array( 'image' => $image ) ) ), $this->taxonomy_entities() ), true );
		$post       = $this->sent_post();
		$attachment = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $image ) );
		$this->assertCount( 1, $attachment );
		$this->assertSame( $attachment[0]->ID, (int) get_post_thumbnail_id( $post ) );
		$by_id = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$this->assertSame( $this->post_entity( array( 'image' => $image ) )['revisionHash'], $by_id[ self::POST_ID ]['revisionHash'], 'The post comes back with its image.' );
		$this->assertSame( 'asset', $by_id[ $image ]['kind'] );

		// Without the key the post has no image: the thumbnail goes, and the post is as it was before item 119.
		$this->send( array_merge( array( $this->template_entity(), $this->rebased( $this->post_entity() ) ), $this->taxonomy_entities() ), true );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->sent_post() ) );
		$by_id = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$this->assertArrayNotHasKey( 'image', $by_id[ self::POST_ID ]['payload'] );
		$this->assertSame( $this->post_entity()['revisionHash'], $by_id[ self::POST_ID ]['revisionHash'] );

		// An image chosen in WordPress travels to the CMS too.
		set_post_thumbnail( $this->sent_post()->ID, $attachment[0]->ID );
		$by_id = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$this->assertSame( $image, $by_id[ self::POST_ID ]['payload']['image'] );
	}

	/** A new featured image of a published post sent without publishing waits for the release, like its excerpt. */
	public function test_a_post_image_sent_without_publishing_waits_for_the_release(): void {
		$image = '42345678-1234-4234-8234-123456789abc';
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
		$this->send( array_merge( array( $this->template_entity(), $this->rebased( $this->post_entity( array( 'image' => $image ) ) ) ), $this->taxonomy_entities() ), false );
		$post = $this->sent_post();
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post ), 'The live post keeps no image until the release.' );
		$by_id = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$this->assertSame( $image, $by_id[ self::POST_ID ]['payload']['image'], 'Development shows the image waiting.' );
		$this->assertSame( 'asset', $by_id[ $image ]['kind'] );
		\XB\Plugin::instance()->versioning->publish( null, 'Immagine' );
		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $post ) );
	}

	public function test_a_post_sent_without_publishing_waits_for_the_release(): void {
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), false );
		$post = $this->sent_post();
		$this->assertSame( 'draft', $post->post_status, 'A new post is a draft until the release.' );
		$draft_categories = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		$this->assertIsArray( $draft_categories );
		$this->assertNotContains( 'notizie', $draft_categories, 'No category yet: the term waits in the queue.' );
		$exported = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$this->assertSame( PageProfile::canonical( $this->post_entity()['payload'] ), PageProfile::canonical( $exported[ self::POST_ID ]['payload'] ), 'Development shows the post as the CMS sent it.' );

		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		$post = get_post( $post->ID );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2026-10-20 08:30:00', $post->post_date_gmt );
		$this->assertSame( array( 'notizie' ), wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) ), 'The release creates the term before assigning it.' );
		$this->assertSame( '', get_post_meta( $post->ID, '_xb_page_profile_pending_fields', true ) );
	}

	public function test_a_draft_without_categories_loses_the_default_one_at_the_release(): void {
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity( array( 'status' => 'draft', 'publishedAt' => null, 'terms' => array() ) ) ), $this->taxonomy_entities() ), false );
		$post = $this->sent_post();
		$this->assertNotSame( array(), wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'ids' ) ), 'WordPress gives a new post its default category' );
		\XB\Plugin::instance()->versioning->publish( null, 'Bozza' );
		$this->assertSame( array(), wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( 'draft', get_post_status( $post->ID ) );
	}

	public function test_an_existing_category_is_adopted_and_one_of_another_project_stops_the_send(): void {
		$existing = wp_insert_term( 'Notizie del sito', 'category', array( 'slug' => 'notizie' ) );
		$this->assertIsArray( $existing );
		$this->created_terms[] = array( 'id' => (int) $existing['term_id'], 'taxonomy' => 'category' );
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
		$this->assertSame( self::TERM_ID, get_term_meta( (int) $existing['term_id'], '_xb_page_profile_id', true ), 'The unowned category with the same slug is adopted, not duplicated.' );
		$this->assertSame( array( (int) $existing['term_id'] ), wp_get_object_terms( $this->sent_post()->ID, 'category', array( 'fields' => 'ids' ) ) );

		$foreign = wp_insert_term( 'Eventi', 'category', array( 'slug' => 'eventi' ) );
		$this->assertIsArray( $foreign );
		$this->created_terms[] = array( 'id' => (int) $foreign['term_id'], 'taxonomy' => 'category' );
		update_term_meta( (int) $foreign['term_id'], '_xb_page_profile_id', '550e8400-e29b-41d4-a716-446655440399' );
		$taxonomies = $this->taxonomy_entities( 'eventi' );
		try {
			$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $taxonomies ), true );
			$this->fail( 'A category another project owns must stop the send.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'eventi', $error->getMessage() );
		}
	}

	public function test_a_site_editor_template_with_the_role_slug_stops_the_send_before_any_write(): void {
		$customized = wp_insert_post( array( 'post_type' => 'wp_template', 'post_name' => 'single', 'post_title' => 'Single del Site Editor', 'post_status' => 'publish', 'post_content' => '<!-- wp:post-content /-->' ), true );
		$this->assertIsInt( $customized );
		$this->track( $customized );
		wp_set_post_terms( $customized, array( get_stylesheet() ), 'wp_theme' );
		$posts_before = (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'post' )->publish;
		try {
			$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
			$this->fail( 'Histeon must not overwrite a Site Editor template.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'single', $error->getMessage() );
		}
		$this->assertSame( $posts_before, (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'post' )->publish, 'Nothing was written.' );
		$this->assertSame( 'single', get_post_field( 'post_name', $customized ) );
	}

	public function test_a_role_change_renames_the_template_at_publish_and_its_posts_follow(): void {
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
		$exported = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$template = $this->template_entity( 'index' );
		$template['baseHash'] = $exported[ self::TEMPLATE_ID ]['revisionHash'];
		$post = $this->post_entity();
		$post['baseHash'] = $exported[ self::POST_ID ]['revisionHash'];
		$taxonomies = array_map(
			static function ( array $entity ) use ( $exported ): array {
				$entity['baseHash'] = $exported[ $entity['id'] ]['revisionHash'];
				return $entity;
			},
			$this->taxonomy_entities()
		);
		$this->send( array_merge( array( $template, $post ), $taxonomies ), true );
		$templates = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::TEMPLATE_ID ) );
		$this->assertSame( 'index', $templates[0]->post_name );
		$this->assertSame( 'index', get_post_meta( $this->sent_post()->ID, '_wp_page_template', true ), 'Documents assigned to the template follow its new slug.' );
	}

	public function test_the_profile_refuses_malformed_posts_and_duplicate_roles(): void {
		$cases = array(
			'local date'      => array( $this->post_entity( array( 'publishedAt' => '2026-10-20 08:30' ) ) ),
			'draft with date' => array( $this->post_entity( array( 'status' => 'draft' ) ) ),
			'slug'            => array( $this->post_entity( array( 'slug' => 'Prima Notizia' ) ) ),
			'unknown term'    => array( $this->post_entity( array( 'terms' => array( 'category' => array( '550e8400-e29b-41d4-a716-446655440398' ) ) ) ) ),
			'image'           => array( $this->post_entity( array( 'image' => 'copertina' ) ) ),
			'missing image'   => array( $this->post_entity( array( 'image' => '550e8400-e29b-41d4-a716-446655440396' ) ) ),
		);
		foreach ( $cases as $name => $post ) {
			try {
				$this->send( array_merge( array( $this->template_entity() ), $post, $this->taxonomy_entities() ), true );
				$this->fail( 'Accepted a post with a bad ' . $name . '.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertStringNotContainsString( 'Unsupported transfer format', $error->getMessage(), $name );
			}
		}
		$twin = $this->template_entity();
		$twin['id'] = '550e8400-e29b-41d4-a716-446655440397';
		$twin['payload']['ref'] = 'site/single-two';
		$twin = $this->hashed( array( 'id' => $twin['id'], 'kind' => 'template', 'payload' => $twin['payload'] ) );
		try {
			$this->send( array( $this->template_entity(), $twin ), true );
			$this->fail( 'Accepted two templates with one role.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'template role', $error->getMessage() );
		}
		$this->assertSame( array(), get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::POST_ID ) ) );
	}

	public function test_a_check_writes_nothing_and_lists_what_the_import_would_do(): void {
		global $wpdb;
		$posts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$plan = $this->send_check( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ) );
		$this->assertTrue( $plan['check'] );
		$this->assertSame( PageProfile::POSTS_PROJECT_PROFILE, $plan['profile'] );
		$rows = array_column( $plan['entities'], null, 'label' );
		$this->assertSame( 'crea', $rows['Prima notizia']['action'] );
		$this->assertSame( 'Articolo', $rows['Prima notizia']['kind'] );
		$this->assertSame( 'single', $rows['Articolo']['role'] );
		$this->assertSame( $posts_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'A check writes nothing.' );
		$this->assertFalse( get_option( 'xb_page_profile_project_id', false ), 'A check does not pair the site.' );
	}

	public function test_the_cms_screen_offers_the_package_as_a_file_to_administrators_only(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		ob_start();
		\XB\Admin\CmsScreen::export_form();
		\XB\Admin\CmsScreen::render_html_import();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'xb_admin_cms_file_export', $html );
		$this->assertStringContainsString( 'name="package"', $html, 'the HTML conversion still hands its package to the check' );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		wp_set_current_user( $this->user( 'editor' ) );
		ob_start();
		\XB\Admin\CmsScreen::render();
		\XB\Admin\CmsScreen::export_form();
		\XB\Admin\CmsScreen::render_html_import();
		\XB\Admin\CmsScreen::render_plan();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_a_histos_404_template_made_in_wordpress_travels_with_its_role(): void {
		$this->send( array( $this->template_entity() ), true );
		$template = wp_insert_post( array( 'post_type' => 'wp_template', 'post_name' => '404', 'post_title' => 'Non trovato', 'post_status' => 'publish', 'post_content' => '<!-- wp:xb/element {"uid":"nfroot0001","tag":"main"} --><!-- wp:xb/text {"uid":"nftext0001","content":"Pagina non trovata"} /--><!-- wp:xb/post-content {"uid":"nfslot0001"} /--><!-- /wp:xb/element -->' ), true );
		$this->assertIsInt( $template );
		$this->track( $template );
		wp_set_post_terms( $template, array( get_stylesheet() ), 'wp_theme' );
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$found = array_values( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'template' === $entity['kind'] && '404' === ( $entity['payload']['role'] ?? null ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'theme-template/role-404', $found[0]['payload']['ref'] );
	}

	/** @return list<array<string, mixed>> The project after the role moved from site/single to site/single-new. */
	private function moved_role_send(): array {
		$exported = array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
		$old = $this->template_entity( null );
		$old['baseHash'] = $exported[ self::TEMPLATE_ID ]['revisionHash'];
		$new = $this->template_entity( 'single' );
		$new = $this->hashed( array( 'id' => '550e8400-e29b-41d4-a716-446655440306', 'kind' => 'template', 'payload' => array_merge( $new['payload'], array( 'ref' => 'site/single-new', 'title' => 'Articolo nuovo' ) ) ) );
		$post = $this->post_entity( array( 'templateRef' => 'site/single-new' ) );
		$post['baseHash'] = $exported[ self::POST_ID ]['revisionHash'];
		$taxonomies = array_map(
			static function ( array $entity ) use ( $exported ): array {
				$entity['baseHash'] = $exported[ $entity['id'] ]['revisionHash'];
				return $entity;
			},
			$this->taxonomy_entities()
		);
		return array_merge( array( $old, $new, $post ), $taxonomies );
	}

	private function template_slug( string $id ): string {
		$posts = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $id ) );
		$this->assertCount( 1, $posts );
		return $posts[0]->post_name;
	}

	public function test_a_role_moves_to_another_template_in_one_published_send(): void {
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
		$this->send( $this->moved_role_send(), true );
		$this->assertSame( 'single', $this->template_slug( '550e8400-e29b-41d4-a716-446655440306' ) );
		$this->assertStringStartsWith( 'histos-', $this->template_slug( self::TEMPLATE_ID ) );
		$this->assertSame( 'single', get_post_meta( $this->sent_post()->ID, '_wp_page_template', true ) );
		$roles = array();
		foreach ( PageProfile::decode( PageProfileAdapter::export() )['entities'] as $entity ) {
			if ( 'template' === $entity['kind'] ) { $roles[ $entity['id'] ] = $entity['payload']['role'] ?? null; }
		}
		$this->assertSame( array( self::TEMPLATE_ID => null, '550e8400-e29b-41d4-a716-446655440306' => 'single' ), array_intersect_key( $roles, array( self::TEMPLATE_ID => 1, '550e8400-e29b-41d4-a716-446655440306' => 1 ) ) );
	}

	public function test_a_role_moved_without_publishing_changes_hands_at_the_release(): void {
		$this->send( array_merge( array( $this->template_entity(), $this->post_entity() ), $this->taxonomy_entities() ), true );
		$this->send( $this->moved_role_send(), false );
		$this->assertSame( 'single', $this->template_slug( self::TEMPLATE_ID ), 'Until the release the old template keeps the role.' );
		$this->assertStringStartsWith( 'histos-', $this->template_slug( '550e8400-e29b-41d4-a716-446655440306' ) );
		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		$this->assertSame( 'single', $this->template_slug( '550e8400-e29b-41d4-a716-446655440306' ) );
		$this->assertStringStartsWith( 'histos-', $this->template_slug( self::TEMPLATE_ID ) );
		$this->assertSame( 'single', get_post_meta( $this->sent_post()->ID, '_wp_page_template', true ) );
	}
}
