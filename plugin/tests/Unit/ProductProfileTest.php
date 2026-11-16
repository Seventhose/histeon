<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Remote\PageProfile;
use XB\Remote\ProductProfile;

/** The product rules of the bridge (queue C6), the same as Histeon Publish's packages/histos-contract/src/commerce.ts. */
final class ProductProfileTest extends TestCase {

	/** The product fields as Publish writes them (its commerce.test.ts checks the same canonical JSON). */
	private const FIELDS = '[{"key":"regular_price","label":"Prezzo","multiple":false,"public":true,"required":false,"type":"decimal"},{"key":"sale_price","label":"Prezzo scontato","multiple":false,"public":true,"required":false,"type":"decimal"},{"key":"sku","label":"SKU","multiple":false,"public":true,"required":false,"type":"text"},{"key":"stock_status","label":"Disponibilità","multiple":false,"options":["instock","outofstock","onbackorder"],"public":true,"required":false,"type":"enum"},{"key":"image","label":"Immagine","multiple":false,"public":true,"required":false,"type":"asset"},{"key":"gallery","label":"Galleria","multiple":true,"public":true,"required":false,"type":"asset"}]';

	public function test_the_fields_are_publish_s(): void {
		$this->assertSame( self::FIELDS, PageProfile::canonical( ProductProfile::fields() ) );
	}

	public function test_value_issues_keep_what_woocommerce_keeps(): void {
		$this->assertSame( array(), ProductProfile::value_issues( 'Tazza', 'Breve & semplice', array( 'regular_price' => 24, 'sale_price' => 19.5, 'sku' => 'TZ-BLU/1', 'stock_status' => 'onbackorder', 'gallery' => array( 'a', 'b' ) ) ) );
		$this->assertSame( array(), ProductProfile::value_issues( 'Tazza', '', array( 'regular_price' => null, 'sku' => '' ) ) );
		$issues = static fn ( array $values, string $title = 'Tazza', string $excerpt = '' ): string => implode( ' ', ProductProfile::value_issues( $title, $excerpt, $values ) );
		$this->assertStringContainsString( 'regular_price: un prezzo', $issues( array( 'regular_price' => '24' ) ), 'a price is a number, never text' );
		$this->assertStringContainsString( 'regular_price: un prezzo', $issues( array( 'regular_price' => -1 ) ) );
		$this->assertStringContainsString( 'regular_price: un prezzo', $issues( array( 'regular_price' => 1.00001 ) ) );
		$this->assertStringContainsString( 'regular_price: un prezzo', $issues( array( 'regular_price' => 1e10 ) ) );
		$this->assertStringContainsString( 'vuole anche regular_price', $issues( array( 'sale_price' => 5 ) ) );
		$this->assertStringContainsString( 'minore del prezzo pieno', $issues( array( 'regular_price' => 5, 'sale_price' => 5 ) ) );
		$this->assertStringContainsString( 'sku:', $issues( array( 'sku' => '<b>x</b>' ) ) );
		$this->assertStringContainsString( 'sku:', $issues( array( 'sku' => str_repeat( 'A', 65 ) ) ) );
		$this->assertStringContainsString( 'stock_status:', $issues( array( 'stock_status' => 'available' ) ) );
		$this->assertStringContainsString( 'ripetuta', $issues( array( 'gallery' => array( 'a', 'a' ) ) ) );
		$this->assertStringContainsString( 'al massimo 20', $issues( array( 'gallery' => range( 1, 21 ) ) ) );
		$this->assertStringContainsString( 'title:', $issues( array(), '<script>' ) );
		$this->assertStringContainsString( 'excerpt:', $issues( array(), 'Tazza', '<p>Breve</p>' ) );
	}

	public function test_validate_refuses_other_fields_foreign_taxonomies_and_a_shared_sku(): void {
		$type    = array( 'id' => 't', 'kind' => 'content-type', 'payload' => array( 'key' => 'product', 'label' => 'Prodotti', 'fields' => ProductProfile::fields() ) );
		$product = static fn ( string $sku ): array => array( 'id' => $sku, 'kind' => 'content-record', 'payload' => array( 'typeKey' => 'product', 'title' => 'P', 'excerpt' => '', 'values' => array( 'sku' => $sku ) ) );
		ProductProfile::validate( array( $type, $product( 'A' ), $product( 'B' ) ) );
		$this->assertTrue( ProductProfile::uses_products( array( $type ) ) );
		$this->assertFalse( ProductProfile::uses_products( array( array( 'kind' => 'content-type', 'payload' => array( 'key' => 'opera' ) ) ) ) );
		$refused = array(
			'other fields'   => array( array( 'kind' => 'content-type', 'payload' => array( 'key' => 'product', 'fields' => array() ) ) ),
			'taxonomy type'  => array( array( 'kind' => 'content-type', 'payload' => array( 'key' => 'product_cat', 'fields' => array() ) ) ),
			'foreign types'  => array( array( 'kind' => 'taxonomy', 'payload' => array( 'key' => 'product_tag', 'types' => array( 'post' ) ) ) ),
			'shared sku'     => array( $type, $product( 'A' ), $product( 'A' ) ),
			'invalid values' => array( $type, array( 'kind' => 'content-record', 'payload' => array( 'typeKey' => 'product', 'title' => 'P', 'excerpt' => '', 'values' => array( 'regular_price' => 5, 'sale_price' => 6 ) ) ) ),
		);
		foreach ( $refused as $case => $entities ) {
			try {
				ProductProfile::validate( $entities );
				$this->fail( 'Refused: ' . $case );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
