<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Data\FluentCartProductNode;
use XB\Data\PostNode;

/** FluentCart products as data — docs/spec/ecommerce.md. Skipped when FluentCart is not active. */
final class FluentCartTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! FluentCartProductNode::available() ) {
			$this->markTestSkipped( 'FluentCart non attivo nel WordPress di sviluppo.' );
		}
	}

	/**
	 * @param list<array<string, mixed>> $variations
	 */
	private function product( string $title, array $variations, string $status = 'publish' ): int {
		$id = $this->post(
			array(
				'post_type'    => FluentCartProductNode::POST_TYPE,
				'post_title'   => $title,
				'post_status'  => $status,
				'post_excerpt' => '<p>Breve <b>descrizione</b></p>',
			)
		);
		$first = null;
		foreach ( $variations as $i => $v ) {
			$row   = \FluentCart\App\Models\ProductVariation::create(
				$v + array(
					'post_id'          => $id,
					'serial_index'     => $i,
					'stock_status'     => 'in-stock',
					'item_status'      => 'active',
					'payment_type'     => 'onetime',
					'fulfillment_type' => 'digital',
					'manage_stock'     => 0,
				)
			);
			$first ??= $row->id;
		}
		\FluentCart\App\Models\ProductDetail::create(
			array(
				'post_id'              => $id,
				'fulfillment_type'     => 'digital',
				'variation_type'       => count( $variations ) > 1 ? 'simple_variations' : 'simple',
				'default_variation_id' => $first,
				'manage_stock'         => 0,
				'stock_availability'   => 'in-stock',
			)
		);
		return $id;
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( FluentCartProductNode::available() ) {
			$wpdb->query( "DELETE v FROM {$wpdb->prefix}fct_product_variations v LEFT JOIN {$wpdb->posts} p ON p.ID = v.post_id WHERE p.ID IS NULL OR p.post_title LIKE 'Xbt %'" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE d FROM {$wpdb->prefix}fct_product_details d LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE p.ID IS NULL OR p.post_title LIKE 'Xbt %'" ); // phpcs:ignore WordPress.DB
		}
		parent::tearDown();
	}

	public function test_product_data_variations_and_subscriptions(): void {
		$id = $this->product(
			'Xbt Corso',
			array(
				array( 'variation_title' => 'Base', 'sku' => 'xbt-base-' . wp_generate_password( 4, false ), 'item_price' => 1000, 'compare_price' => 1500 ),
				array(
					'variation_title' => 'Abbonamento',
					'sku'             => 'xbt-abb-' . wp_generate_password( 4, false ),
					'item_price'      => 2500,
					'compare_price'   => 0,
					'payment_type'    => 'subscription',
					'other_info'      => array( 'repeat_interval' => 'monthly', 'times' => 0, 'trial_days' => 7 ),
				),
				array( 'variation_title' => '<b>Vecchia</b>', 'item_price' => 1, 'item_status' => 'inactive' ),
			)
		);
		wp_set_current_user( 0 );
		$this->go_to( $id );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.product.price}|{this.product.regularPrice}|{this.product.onSale}|{this.product.inStock}|{this.product.shortDescription}|{this.product.minPrice}|{this.product.maxPrice}|{this.product.variations.length()}' ) ) ) ) );
		$this->assertStringContainsString( '<p>10|15|true|true|Breve descrizione|10|25|2</p>', $html, 'inactive variations never show' );

		$product = PostNode::for_post( get_post( $id ) )?->get( 'product' );
		$this->assertInstanceOf( FluentCartProductNode::class, $product );
		$this->assertMatchesRegularExpression( '/10.*–.*25/', (string) $product->get( 'priceText' ) );
		$this->assertStringContainsString( 'fluent-cart=instant_checkout', (string) $product->get( 'buyNowUrl' ) );
		$this->assertSame( $product->get( 'buyNowUrl' ), $product->get( 'addToCartUrl' ) );
		$variations = $product->get( 'variations' );
		$this->assertSame( array( 'Base', 'Abbonamento' ), array_column( $variations, 'title' ) );
		$this->assertNull( $variations[0]['subscription'] );
		$this->assertSame( 'monthly', $variations[1]['subscription']['interval'] );
		$this->assertSame( 7, $variations[1]['subscription']['trialDays'] );
		$this->assertIsString( $product->get( 'currency' ) );
		$this->assertSame( 'simple_variations', $product->get( 'type' ) );
	}

	public function test_drafts_stay_hidden_and_other_types_are_not_products(): void {
		$draft = $this->product( 'Xbt Bozza', array( array( 'variation_title' => 'A', 'item_price' => 500 ) ), 'draft' );
		wp_set_current_user( 0 );
		$this->assertNull( PostNode::for_post( get_post( $draft ) ), 'a visitor never reads a draft product' );
		$page = $this->post( array( 'post_type' => 'page' ) );
		$post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertNull( FluentCartProductNode::for_post( $post ) );
	}

	public function test_loops_list_fluentcart_products(): void {
		$this->product( 'Xbt Elenco', array( array( 'variation_title' => 'A', 'item_price' => 990 ) ) );
		wp_set_current_user( 0 );
		$html = $this->render(
			array(
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'type' => '"fluent-products"', 'limit' => '50' ) ), 'as' => 'item' ), array( $this->text( '[{item.title}={item.product.price}]' ) ) ),
			)
		);
		$this->assertStringContainsString( '[Xbt Elenco=9.9]', $html );
	}
}
