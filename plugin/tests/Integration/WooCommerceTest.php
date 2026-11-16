<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** WooCommerce products in expressions and loops — docs/spec/ecommerce.md. */
final class WooCommerceTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( \WC_Product_Simple::class ) ) {
			$this->markTestSkipped( 'WooCommerce non attivo nel WordPress di sviluppo.' );
		}
	}

	private function product( string $name, string $regular, string $sale = '', string $visibility = 'visible' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $regular );
		if ( '' !== $sale ) {
			$product->set_sale_price( $sale );
		}
		$product->set_sku( 'xbt-' . wp_generate_password( 6, false ) );
		$product->set_catalog_visibility( $visibility );
		$product->set_short_description( '<p>Breve <b>descrizione</b></p>' );
		$id = $product->save();
		$this->track( $id );
		return $id;
	}

	public function test_product_data_and_catalogue_visibility(): void {
		$visible = $this->product( 'Xbt Tazza', '12', '9.5' );
		$hidden  = $this->product( 'Xbt Nascosto', '5', '', 'hidden' );
		wp_set_current_user( 0 );
		$this->go_to( $visible );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.product.price}|{this.product.regularPrice}|{this.product.onSale}|{this.product.inStock}|{this.product.shortDescription}|[{this.product.priceText}]' ) ) ) ) );
		$this->assertStringContainsString( '9.5|12|true|true|Breve descrizione|[', $html );
		$this->assertStringNotContainsString( '<span class="woocommerce-Price', $html, 'prices print as text, never markup' );

		$this->go_to( $hidden );
		$this->assertStringContainsString( '[]', $this->render( array( $this->el( 'p', array(), array( $this->text( '[{this.product.price}]' ) ) ) ) ) );

		$ids = array_map( static fn ( $n ): int => $n->id(), ( new \XB\Render\LoopPresets() )->run( 'posts', array( 'type' => 'product', 'limit' => 100 ) ) );
		$this->assertContains( $visible, $ids );
		$this->assertNotContains( $hidden, $ids, 'hidden from the catalogue: never in a list' );
	}

	public function test_the_static_export_leaves_the_shop_dynamic_pages_to_wordpress(): void {
		if ( ! defined( 'XB_EXPORT_ORIGIN' ) ) {
			$this->markTestSkipped( 'XB_EXPORT_ORIGIN non definita nel WordPress di sviluppo.' );
		}
		$product  = $this->product( 'Prodotto statico', '12' );
		$exporter = new \XB\StaticSite\Exporter();
		$paths    = $exporter->urls();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) get_option( 'woocommerce_' . $page . '_page_id' );
			if ( $id > 0 ) {
				$this->assertNotContains( (string) wp_make_link_relative( (string) get_permalink( $id ) ), $paths, $page . ' stays dynamic' );
			}
		}
		$this->assertContains( (string) wp_make_link_relative( (string) get_permalink( $product ) ), $paths, 'products are static pages' );
		$build = $exporter->build( 'woo' );
		try {
			$this->assertFalse( \XB\StaticSite\Scanner::blocking( $build['findings'] ), (string) wp_json_encode( $build['findings'] ) );
			// Other tests' post types may answer 404 here (rewrite rules); the shop's pages must not be in the log.
			$shop = array_filter( $build['log'], static fn ( string $line ): bool => 1 === preg_match( '/risposta 30\d|' . preg_quote( (string) wp_make_link_relative( (string) get_permalink( $product ) ), '/' ) . '/', $line ) );
			$this->assertSame( array(), array_values( $shop ), (string) wp_json_encode( $build['log'] ) );
		} finally {
			\XB\StaticSite\Bundle::delete( $build['dir'] );
		}
	}
}
