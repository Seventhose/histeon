<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use FluentCart\App\Models\ProductDetail as Detail;
use FluentCart\App\Models\ProductVariation as Variation;

/**
 * A FluentCart product as seen by expressions: `this.product.price`, `item.product.variations` —
 * docs/spec/ecommerce.md. The same keys as a WooCommerce product where they mean the same thing, plus what
 * FluentCart has of its own (variations, subscriptions, brands, buy-now links). Read only, catalogue data only:
 * never orders, customers, carts or stock movements. Inactive variations are never shown.
 */
final class FluentCartProductNode implements Node {

	public const POST_TYPE = 'fluent-products';

	/** @var array<string, mixed> */
	private array $memo = array();

	/** @param list<Variation> $variations Active variations, in their order. */
	private function __construct( private readonly \WP_Post $post, private readonly ?Detail $detail, private readonly array $variations ) {}

	public static function available(): bool {
		return class_exists( '\FluentCart\App\Models\Product' ) && class_exists( '\FluentCart\App\Helpers\Helper' );
	}

	public static function for_post( \WP_Post $post ): ?self {
		if ( self::POST_TYPE !== $post->post_type || ! self::available() ) {
			return null;
		}
		try {
			$product = \FluentCart\App\Models\Product::with( array( 'detail', 'variants' ) )->find( $post->ID );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_object( $product ) ) {
			return null;
		}
		$variations = array();
		foreach ( $product->variants as $variation ) {
			if ( 'active' === $variation->item_status ) {
				$variations[] = $variation;
			}
		}
		usort( $variations, static fn ( Variation $a, Variation $b ): int => (int) $a->serial_index <=> (int) $b->serial_index );
		return new self( $post, $product->detail, $variations );
	}

	public function get( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			try {
				$this->memo[ $key ] = $this->resolve( $key );
			} catch ( \Throwable ) {
				$this->memo[ $key ] = null; // A FluentCart change never breaks the page.
			}
		}
		return $this->memo[ $key ];
	}

	/** The variation shown by default: the product's default, otherwise the first active one. */
	private function main(): ?Variation {
		$default = null === $this->detail ? 0 : (int) $this->detail->default_variation_id;
		foreach ( $this->variations as $variation ) {
			if ( (int) $variation->id === $default ) {
				return $variation;
			}
		}
		return $this->variations[0] ?? null;
	}

	private function resolve( string $key ): mixed {
		$main = $this->main();
		switch ( $key ) {
			case 'price':
				return null === $main ? null : self::money( $main->item_price );
			case 'regularPrice':
				return null === $main ? null : self::money( self::on_sale( $main ) ? $main->compare_price : $main->item_price );
			case 'salePrice':
				return null !== $main && self::on_sale( $main ) ? self::money( $main->item_price ) : null;
			case 'onSale':
				return null !== $main && self::on_sale( $main );
			case 'minPrice':
			case 'maxPrice':
				$prices = array_map( static fn ( Variation $v ): float => (float) $v->item_price, $this->variations );
				if ( array() === $prices ) {
					return null;
				}
				return self::money( 'minPrice' === $key ? min( $prices ) : max( $prices ) );
			case 'priceText':
				$prices = array_map( static fn ( Variation $v ): float => (float) $v->item_price, $this->variations );
				if ( array() === $prices ) {
					return null;
				}
				$low  = self::money_text( min( $prices ) );
				$high = self::money_text( max( $prices ) );
				return $low === $high ? $low : $low . ' – ' . $high;
			case 'currency':
				$currency = \FluentCart\Api\CurrencySettings::get( 'currency' );
				return is_string( $currency ) ? $currency : null;
			case 'currencySymbol':
				$sign = \FluentCart\Api\CurrencySettings::get( 'currency_sign' );
				return is_string( $sign ) ? html_entity_decode( $sign, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
			case 'sku':
				return null === $main || '' === (string) $main->sku ? null : (string) $main->sku;
			case 'inStock':
				return array() !== array_filter( $this->variations, array( self::class, 'in_stock' ) );
			case 'stockStatus':
				return $this->detail?->stock_availability;
			case 'type':
				return $this->detail?->variation_type;
			case 'fulfillment':
				return $this->detail?->fulfillment_type;
			case 'shortDescription':
				return trim( wp_strip_all_tags( $this->post->post_excerpt ) );
			case 'addToCartUrl':
			case 'buyNowUrl':
				// FluentCart adds to the cart only with JavaScript: the link is its "buy now" (straight to checkout).
				return null === $main ? null : self::buy_url( $main );
			case 'addToCartText':
				return __( 'Acquista', 'xb' );
			case 'variantId':
				return null === $main ? null : (int) $main->id;
			case 'variations':
				return array_map( fn ( Variation $v ): array => $this->variation( $v ), $this->variations );
			case 'subscription':
				return null === $main ? null : self::subscription( $main );
			case 'gallery':
				$items = get_post_meta( $this->post->ID, 'fluent-products-gallery-image', true );
				$ids   = array();
				foreach ( is_array( $items ) ? $items : array() as $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
						$ids[] = (int) $item['id'];
					}
				}
				return array_values( array_filter( array_map( array( ImageNode::class, 'for_attachment' ), $ids ) ) );
			case 'categories':
				return TermNode::list_for_post( $this->post, 'product-categories' );
			case 'brands':
				return TermNode::list_for_post( $this->post, 'product-brands' );
			case 'tags':
				return array();
			case 'cartUrl':
			case 'checkoutUrl':
				$settings = new \FluentCart\Api\StoreSettings();
				$url      = 'cartUrl' === $key ? $settings->getCartPage() : $settings->getCheckoutPage();
				return '' !== $url ? esc_url_raw( $url ) : null;
			default:
				return null;
		}
	}

	/** @return array<string, mixed> */
	private function variation( Variation $v ): array {
		$sale = self::on_sale( $v );
		return array(
			'id'           => (int) $v->id,
			'title'        => sanitize_text_field( (string) $v->variation_title ),
			'sku'          => '' === (string) $v->sku ? null : (string) $v->sku,
			'price'        => self::money( $v->item_price ),
			'regularPrice' => self::money( $sale ? $v->compare_price : $v->item_price ),
			'onSale'       => $sale,
			'priceText'    => self::money_text( (float) $v->item_price ),
			'inStock'      => self::in_stock( $v ),
			'buyNowUrl'    => self::buy_url( $v ),
			'subscription' => self::subscription( $v ),
		);
	}

	private static function on_sale( Variation $v ): bool {
		return (float) $v->compare_price > (float) $v->item_price;
	}

	private static function in_stock( Variation $v ): bool {
		return (bool) $v->isStock();
	}

	/** FluentCart's "buy now": its instant checkout for one item of this variation. */
	private static function buy_url( Variation $v ): string {
		return esc_url_raw( (string) $v->getPurchaseUrl() );
	}

	/** @return array<string, mixed>|null */
	private static function subscription( Variation $v ): ?array {
		if ( 'subscription' !== $v->payment_type ) {
			return null;
		}
		try {
			$info = is_array( $v->other_info ) ? $v->other_info : array();
		} catch ( \Throwable ) {
			$info = array(); // Malformed terms in FluentCart: the variation still shows, without them.
		}
		return array(
			'interval'  => is_string( $info['repeat_interval'] ?? null ) ? $info['repeat_interval'] : null,
			'times'     => isset( $info['times'] ) && is_numeric( $info['times'] ) ? (int) $info['times'] : 0,
			'trialDays' => isset( $info['trial_days'] ) && is_numeric( $info['trial_days'] ) ? (int) $info['trial_days'] : 0,
			'signupFee' => 'yes' === ( $info['manage_setup_fee'] ?? 'no' ) && isset( $info['signup_fee'] ) ? self::money( $info['signup_fee'] ) : null,
			'text'      => self::terms_text( $v ),
		);
	}

	/** FluentCart's own sentence for the terms ("€25 al mese…"); null when it cannot build one. */
	private static function terms_text( Variation $v ): ?string {
		try {
			$text = trim( wp_strip_all_tags( (string) $v->getSubscriptionTermsText() ) );
			return '' === $text ? null : $text;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** FluentCart stores cents. */
	private static function money( mixed $cents ): ?float {
		return is_numeric( $cents ) ? (float) \FluentCart\App\Helpers\Helper::toDecimalWithoutComma( $cents ) : null;
	}

	/** The shop's own formatting (currency, separators), as text: expressions never print markup. */
	private static function money_text( float $cents ): string {
		return trim( html_entity_decode( wp_strip_all_tags( (string) \FluentCart\App\Helpers\Helper::toDecimal( $cents ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
