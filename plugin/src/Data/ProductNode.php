<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * A WooCommerce product as seen by expressions: `this.product.price`, `item.product.addToCartUrl` —
 * docs/spec/ecommerce.md. Read only, catalogue data only (never orders, customers or stock movements); a
 * product hidden from the catalogue reads as null, like a post the visitor cannot see.
 */
final class ProductNode implements Node {

	/** @var array<string, mixed> */
	private array $memo = array();

	private function __construct( private readonly \WC_Product $product, private readonly \WP_Post $post ) {}

	public static function for_post( \WP_Post $post ): ?self {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof \WC_Product || 'hidden' === $product->get_catalog_visibility() ) {
			return null;
		}
		return new self( $product, $post );
	}

	public function get( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $this->resolve( $key );
		}
		return $this->memo[ $key ];
	}

	private function resolve( string $key ): mixed {
		$p = $this->product;
		switch ( $key ) {
			case 'price':
				return self::number( $p->get_price() );
			case 'regularPrice':
				return self::number( $p->get_regular_price() );
			case 'salePrice':
				return self::number( $p->get_sale_price() );
			case 'onSale':
				return $p->is_on_sale();
			case 'priceText':
				// The shop's own formatting (currency, decimals), as text: expressions never print markup.
				return trim( html_entity_decode( wp_strip_all_tags( (string) $p->get_price_html() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			case 'currency':
				return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null;
			case 'currencySymbol':
				return function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
			case 'sku':
				return '' === $p->get_sku() ? null : $p->get_sku();
			case 'inStock':
				return $p->is_in_stock();
			case 'stockStatus':
				return $p->get_stock_status();
			case 'type':
				return $p->get_type();
			case 'shortDescription':
				return trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
			case 'addToCartUrl':
				return esc_url_raw( (string) $p->add_to_cart_url() );
			case 'addToCartText':
				return wp_strip_all_tags( (string) $p->add_to_cart_text() );
			case 'rating':
				return (float) $p->get_average_rating();
			case 'reviewCount':
				return (int) $p->get_review_count();
			case 'gallery':
				return array_values( array_filter( array_map( array( ImageNode::class, 'for_attachment' ), array_map( 'intval', $p->get_gallery_image_ids() ) ) ) );
			case 'categories':
				return TermNode::list_for_post( $this->post, 'product_cat' );
			case 'tags':
				return TermNode::list_for_post( $this->post, 'product_tag' );
			default:
				return null;
		}
	}

	private static function number( mixed $value ): int|float|null {
		return is_numeric( $value ) ? $value + 0 : null;
	}
}
