<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;
use XB\Remote\ProductProfile;

/**
 * Products from Histeon CMS (queue C6, docs/spec/ecommerce.md, docs/spec/cms-bridge.md §15): the product type of a
 * project becomes WooCommerce's catalogue, with the records' staging, and its templates become WooCommerce's product
 * and shop templates. Skipped without WooCommerce.
 */
final class CmsProductsTest extends IntegrationTestCase {
	private const TYPE_ID      = '550e8400-e29b-41d4-a716-446655440601';
	private const CAT_TAX_ID   = '550e8400-e29b-41d4-a716-446655440602';
	private const TAG_TAX_ID   = '550e8400-e29b-41d4-a716-446655440603';
	private const CAT_ID       = '550e8400-e29b-41d4-a716-446655440604';
	private const TAG_ID       = '550e8400-e29b-41d4-a716-446655440605';
	private const PRODUCT_ID   = '550e8400-e29b-41d4-a716-446655440606';
	private const SINGLE_ID    = '550e8400-e29b-41d4-a716-446655440607';
	private const ARCHIVE_ID   = '550e8400-e29b-41d4-a716-446655440608';
	private const ASSET_ID     = '42345678-1234-4234-8234-123456789abc';

	/** @var list<array{id: int, taxonomy: string}> */
	private array $created_terms = array();
	/** One SKU per test: WooCommerce remembers a SKU in its lookup table. */
	private string $sku = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( \WC_Product_Simple::class ) ) {
			$this->markTestSkipped( 'WooCommerce non attivo nel WordPress di sviluppo.' );
		}
		$this->sku = 'XBT-' . strtoupper( wp_generate_password( 8, false ) );
	}

	protected function tearDown(): void {
		// What a release created after the send (it applies the queue) is this test's too.
		foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::PRODUCT_ID ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( ProductProfile::TAXONOMIES as $taxonomy ) {
			foreach ( array( 'tazze-xbt', 'ceramica-xbt' ) as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}
		}
		foreach ( $this->created_terms as $term ) {
			wp_delete_term( $term['id'], $term['taxonomy'] );
		}
		$this->created_terms = array();
		$terms = get_terms( array( 'taxonomy' => ProductProfile::TAXONOMIES, 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			foreach ( array( '_xb_page_profile_id', '_xb_page_profile_base_hash', '_xb_page_profile_term_slug' ) as $key ) {
				delete_term_meta( (int) $term_id, $key );
			}
		}
		parent::tearDown();
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

	/** @return array<string, mixed> */
	private function template( string $id, string $ref, string $text ): array {
		return $this->hashed(
			array(
				'id'      => $id,
				'kind'    => 'template',
				'payload' => array(
					'title'   => $ref,
					'ref'     => $ref,
					'schema'  => array( 'props' => array() ),
					'content' => '<!-- wp:xb/element {"uid":"' . substr( md5( $ref ), 0, 10 ) . '","tag":"main"} --><!-- wp:xb/text {"uid":"' . substr( md5( $ref . 't' ), 0, 10 ) . '","content":"' . $text . '"} /--><!-- wp:xb/slot {"uid":"' . substr( md5( $ref . 's' ), 0, 10 ) . '","name":"default"} /--><!-- /wp:xb/element -->',
					'script'  => '',
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function product( array $values = array(), array $changes = array() ): array {
		return $this->hashed(
			array(
				'id'      => self::PRODUCT_ID,
				'kind'    => 'content-record',
				'payload' => array_merge(
					array(
						'typeKey'     => 'product',
						'slug'        => 'tazza-blu-xbt',
						'locale'      => 'it-IT',
						'title'       => 'Tazza blu',
						'status'      => 'published',
						'publishedAt' => '2026-10-01T09:00:00Z',
						'excerpt'     => 'Ceramica smaltata a mano & cotta due volte.',
						'order'       => 0,
						'values'      => array_merge( array( 'regular_price' => 24, 'sale_price' => 19.5, 'sku' => $this->sku, 'stock_status' => 'instock', 'image' => self::ASSET_ID, 'gallery' => array( self::ASSET_ID ) ), $values ),
						'terms'       => array( 'product_cat' => array( self::CAT_ID ), 'product_tag' => array( self::TAG_ID ) ),
					),
					$changes
				),
			)
		);
	}

	/**
	 * The shop: the product type with its templates, the product taxonomies with a term each, and the product.
	 *
	 * @param array<string, mixed> $product
	 * @return list<array<string, mixed>>
	 */
	private function shop( array $product, string $single = '{this.product.priceText} {this.product.sku}' ): array {
		return array(
			$this->template( self::SINGLE_ID, 'shop/prodotto', $single ),
			$this->template( self::ARCHIVE_ID, 'shop/negozio', 'Negozio' ),
			$this->hashed( array( 'id' => self::TYPE_ID, 'kind' => 'content-type', 'payload' => array( 'key' => 'product', 'label' => 'Prodotti', 'fields' => ProductProfile::fields(), 'singleTemplateRef' => 'shop/prodotto', 'archiveTemplateRef' => 'shop/negozio' ) ) ),
			$this->hashed( array( 'id' => self::CAT_TAX_ID, 'kind' => 'taxonomy', 'payload' => array( 'key' => 'product_cat', 'label' => 'Categorie prodotto', 'types' => array( 'product' ) ) ) ),
			$this->hashed( array( 'id' => self::TAG_TAX_ID, 'kind' => 'taxonomy', 'payload' => array( 'key' => 'product_tag', 'label' => 'Tag prodotto', 'types' => array( 'product' ) ) ) ),
			$this->hashed( array( 'id' => self::CAT_ID, 'kind' => 'term', 'payload' => array( 'taxonomyKey' => 'product_cat', 'slug' => 'tazze-xbt', 'label' => 'Tazze', 'parentId' => null ) ) ),
			$this->hashed( array( 'id' => self::TAG_ID, 'kind' => 'term', 'payload' => array( 'taxonomyKey' => 'product_tag', 'slug' => 'ceramica-xbt', 'label' => 'Ceramica', 'parentId' => null ) ) ),
			$product,
		);
	}

	/**
	 * Sends the page fixture plus the given entities, as Histeon CMS would.
	 *
	 * @param list<array<string, mixed>> $extra
	 * @return array<string, mixed>
	 */
	private function send( array $extra, bool $publish ): array {
		global $wpdb;
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		/** @var list<array<string, mixed>> $entities */
		$entities   = array_values( array_merge( $fixture['profile']['entities'], $extra ) );
		$references = $fixture['profile']['references'];
		$manifest['profile'] = PageProfile::expected_profile( false, true, true, false );
		$manifest['requiredCapabilities'] = array( 'documents:page:v1', 'styles:global:v1', 'media:asset:v1', 'templates:definition:v1', 'content:types:v1', 'content:records:v1', 'content:taxonomies:v1', ProductProfile::CAPABILITY );
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
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_product_labels', 'xb_page_profile_relational_bases', 'xb_page_profile_default_locale', 'xb_remote_operation_' . $manifest['selection']['snapshot'], 'xb_remote_project_lock_' . $manifest['project']['id'] ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$posts_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ), true );
		$terms_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" ) ), true );
		try {
			return PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $files ), $publish, false );
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

	private function sent_product(): \WC_Product {
		$posts = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::PRODUCT_ID ) );
		$this->assertCount( 1, $posts );
		clean_post_cache( $posts[0]->ID );
		$product = wc_get_product( $posts[0]->ID );
		$this->assertInstanceOf( \WC_Product_Simple::class, $product );
		return $product;
	}

	/** @return array<string, array<string, mixed>> */
	private function exported(): array {
		return array_column( PageProfile::decode( PageProfileAdapter::export() )['entities'], null, 'id' );
	}

	private function template_slug_for( string $slug ): ?int {
		$templates = get_block_templates( array( 'slug__in' => array( $slug ) ), 'wp_template' );
		foreach ( $templates as $template ) {
			if ( $slug === $template->slug && null !== $template->wp_id ) {
				$ref = get_post_meta( (int) $template->wp_id, '_xb_page_profile_ref', true );
				return is_string( $ref ) && '' !== $ref ? (int) $template->wp_id : null;
			}
		}
		return null;
	}

	public function test_the_site_declares_products_only_with_woocommerce(): void {
		$this->assertTrue( ProductProfile::available() );
		$capabilities = PageProfileAdapter::capabilities( array( 'secret' => '', 'user' => 1, 'publish' => true, 'read' => true, 'write' => true, 'label' => '', 'created' => 0, 'last' => 0 ) );
		$this->assertSame( array( ProductProfile::CAPABILITY ), $capabilities['capabilities']['commerce'] );
	}

	public function test_a_published_send_makes_a_woocommerce_product_with_its_templates_and_round_trips(): void {
		$report = $this->send( $this->shop( $this->product() ), true );
		$this->assertTrue( $report['complete'] );
		$product = $this->sent_product();
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( 'simple', $product->get_type() );
		$this->assertSame( 'Tazza blu', $product->get_name() );
		$this->assertSame( '24', $product->get_regular_price( 'edit' ) );
		$this->assertSame( '19.5', $product->get_sale_price( 'edit' ) );
		$this->assertTrue( $product->is_on_sale() );
		$this->assertSame( $this->sku, $product->get_sku( 'edit' ) );
		$this->assertSame( 'instock', $product->get_stock_status( 'edit' ) );
		$this->assertFalse( $product->get_manage_stock(), 'Histeon never manages stock quantities.' );
		$this->assertSame( 'Ceramica smaltata a mano & cotta due volte.', $product->get_short_description( 'edit' ) );
		$attachment = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::ASSET_ID ) );
		$this->assertSame( (int) $attachment[0], (int) $product->get_image_id( 'edit' ) );
		$this->assertSame( array( (int) $attachment[0] ), array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) );
		$this->assertSame( array( 'tazze-xbt' ), wp_get_object_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'ceramica-xbt' ), wp_get_object_terms( $product->get_id(), 'product_tag', array( 'fields' => 'slugs' ) ) );
		$default = get_term( (int) get_option( 'default_product_cat' ), 'product_cat' );
		$this->assertInstanceOf( \WP_Term::class, $default );
		$this->assertSame( '', get_term_meta( $default->term_id, '_xb_page_profile_id', true ), 'WooCommerce\'s default category is never taken for the project\'s.' );
		$this->assertNotSame( 'Tazze', $default->name );

		// WooCommerce's product and shop templates are the project's, in the active theme.
		$single = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::SINGLE_ID ) );
		$archive = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::ARCHIVE_ID ) );
		$this->assertSame( (int) $single[0], $this->template_slug_for( 'single-product' ) );
		$this->assertSame( (int) $archive[0], $this->template_slug_for( 'archive-product' ) );

		// The template reads the product as WordPress gives it.
		wp_set_current_user( 0 );
		$this->go_to( $product->get_id() );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '[{this.product.price}|{this.product.sku}|{this.product.categories.first().slug}|{this.image.id}]' ) ) ) ) );
		$this->assertStringContainsString( '[19.5|' . $this->sku . '|tazze-xbt|' . (int) $attachment[0] . ']', $html );
		wp_set_current_user( 1 );

		$exported = $this->exported();
		$this->assertContains( ProductProfile::CAPABILITY, PageProfile::decode( PageProfileAdapter::export() )['manifest']['requiredCapabilities'] );
		foreach ( $this->shop( $this->product() ) as $entity ) {
			if ( in_array( $entity['kind'], array( 'content-type', 'content-record', 'taxonomy', 'term' ), true ) ) {
				$this->assertSame( $entity['revisionHash'], $exported[ $entity['id'] ]['revisionHash'] ?? null, $entity['kind'] . ' ' . ( $entity['payload']['key'] ?? $entity['payload']['slug'] ?? '' ) . ' comes back exactly as it was sent.' );
			}
		}

		$this->assertSame( array(), array_values( array_filter( $exported, static fn ( array $entity ): bool => 'term' === $entity['kind'] && 'uncategorized' === ( $entity['payload']['slug'] ?? null ) ) ), 'WooCommerce\'s default category is not the project\'s.' );

		// A WordPress edit after the send wins: the download shows WooCommerce's data.
		$product->set_regular_price( '30' );
		$product->save();
		$this->assertSame( 30, $this->exported()[ self::PRODUCT_ID ]['payload']['values']['regular_price'] );
	}

	public function test_a_send_without_publishing_never_changes_the_live_product_until_the_release(): void {
		$this->send( $this->shop( $this->product() ), true );
		$live = $this->sent_product();

		$changed = $this->product( array( 'regular_price' => 30, 'sale_price' => null, 'stock_status' => 'outofstock' ), array( 'title' => 'Tazza blu grande' ) );
		// The CMS sends what it changed from the download it had (cms-bridge §5).
		$changed['baseHash'] = $this->product()['revisionHash'];
		// The product template changes too: a release then has a document to publish.
		$shop = $this->shop( $changed, '{this.product.priceText}' );
		$shop[0]['baseHash'] = $this->shop( $this->product() )[0]['revisionHash'];
		$report  = $this->send( $shop, false );
		$this->assertContains( array( 'id' => self::PRODUCT_ID, 'reason' => 'next-release' ), array_map( static fn ( array $item ): array => array( 'id' => $item['id'], 'reason' => $item['reason'] ), $report['pending'] ) );
		$product = $this->sent_product();
		$this->assertSame( '24', $product->get_regular_price( 'edit' ), 'The live product does not change in development.' );
		$this->assertSame( 'Tazza blu', $product->get_name() );
		$this->assertSame( 'instock', $product->get_stock_status( 'edit' ) );
		$this->assertSame( $changed['revisionHash'], $this->exported()[ self::PRODUCT_ID ]['revisionHash'], 'The download shows the product as development has it.' );

		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		$product = $this->sent_product();
		$this->assertSame( $live->get_id(), $product->get_id() );
		$this->assertSame( '30', $product->get_regular_price( 'edit' ) );
		$this->assertSame( '', $product->get_sale_price( 'edit' ) );
		$this->assertSame( 'outofstock', $product->get_stock_status( 'edit' ) );
		$this->assertSame( 'Tazza blu grande', $product->get_name() );
		$this->assertSame( 'tazza-blu-xbt', get_post_field( 'post_name', $product->get_id() ), 'A product published again keeps its address.' );
	}

	public function test_a_product_change_alone_makes_a_release_of_its_own(): void {
		$this->send( $this->shop( $this->product() ), true );
		$versioning = \XB\Plugin::instance()->versioning;
		$before     = array_column( $versioning->changes(), 'doc' );

		$changed             = $this->product( array( 'regular_price' => 27 ) );
		$changed['baseHash'] = $this->product()['revisionHash'];
		$shop                = $this->shop( $changed );
		$shop[0]['baseHash'] = $this->shop( $this->product() )[0]['revisionHash'];
		$this->send( $shop, false );
		$this->assertSame( '24', $this->sent_product()->get_regular_price( 'edit' ) );

		$labels = array_column( $versioning->changes(), 'label', 'doc' );
		$this->assertSame( array( \XB\Versioning\Documents::CONTENTS ), array_values( array_diff( array_keys( $labels ), $before ) ), 'Only the price changed: no document, one waiting content.' );
		$this->assertSame( sprintf( '%d contenuti dal CMS in attesa (vanno online con qualsiasi release)', count( \XB\Remote\ContentProfileAdapter::queue()['entities'] ) ), $labels[ \XB\Versioning\Documents::CONTENTS ], 'The product and the terms sent with it.' );

		$release = $versioning->publish( null, 'Solo il prezzo', array( \XB\Versioning\Documents::CONTENTS ) );
		$this->assertSame( array(), array_values( array_diff( $release['docs'], $before ) ), 'No document went online with it.' );
		$this->assertSame( '27', $this->sent_product()->get_regular_price( 'edit' ) );
		$this->assertNotContains( \XB\Versioning\Documents::CONTENTS, array_column( $versioning->changes(), 'doc' ), 'Nothing waits after the release.' );
	}

	public function test_a_new_product_sent_without_publishing_waits_with_its_templates_for_the_release(): void {
		$this->send( $this->shop( $this->product() ), false );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::PRODUCT_ID ) ), 'Nothing in the shop before the release.' );
		$this->assertSame( $this->product()['revisionHash'], $this->exported()[ self::PRODUCT_ID ]['revisionHash'], 'The download shows the product as development has it.' );
		$this->assertNull( $this->template_slug_for( 'single-product' ), 'WooCommerce keeps its own product template until the release.' );
		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		$this->assertSame( 'publish', $this->sent_product()->get_status() );
		$this->assertNotNull( $this->template_slug_for( 'single-product' ) );
	}

	public function test_a_sku_of_another_product_or_invalid_data_stops_the_send_before_any_write(): void {
		$other = new \WC_Product_Simple();
		$other->set_name( 'Altro' );
		$other->set_sku( $this->sku );
		$this->track( $other->save() );
		try {
			$this->send( $this->shop( $this->product() ), true );
			$this->fail( 'A SKU of another product must stop the send.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( $this->sku, $error->getMessage() );
		}
		$this->assertSame( array(), get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::PRODUCT_ID ) ) );

		foreach ( array( array( 'sale_price' => 30 ), array( 'regular_price' => '24' ), array( 'regular_price' => 24.123456 ), array( 'sku' => '<b>x</b>' ) ) as $values ) {
			try {
				$this->send( $this->shop( $this->product( array_merge( $values, array( 'sku' => $values['sku'] ?? 'XBT-OK' ) ) ) ), true );
				$this->fail( 'Invalid product data must stop the send: ' . wp_json_encode( $values ) );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		$this->send( $this->shop( $this->product( array( 'sku' => 'XBT-OK' ), array( 'excerpt' => '<p>HTML</p>' ) ) ), true );
	}
}
