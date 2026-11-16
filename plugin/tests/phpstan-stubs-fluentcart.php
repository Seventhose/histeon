<?php
/**
 * PHPStan stubs: the parts of FluentCart XB reads (docs/spec/ecommerce.md). Not loaded at runtime.
 */

namespace FluentCart\App\Models {
	class ProductDetail {
		/** @param array<string, mixed> $attributes */
		public static function create( array $attributes ): self {
			return new self();
		}
		public int $default_variation_id = 0;
		public ?string $stock_availability = null;
		public ?string $variation_type = null;
		public ?string $fulfillment_type = null;
	}
	class ProductVariation {
		/** @param array<string, mixed> $attributes */
		public static function create( array $attributes ): self {
			return new self();
		}
		public int $id = 0;
		public int $serial_index = 0;
		public float $item_price = 0.0;
		public float $compare_price = 0.0;
		public ?string $sku = null;
		public ?string $variation_title = null;
		public string $item_status = 'active';
		public ?string $payment_type = null;
		/** @var array<string, mixed>|null */
		public ?array $other_info = null;
		public function isStock(): bool {
			return true;
		}
		public function getPurchaseUrl(): string {
			return '';
		}
		public function getSubscriptionTermsText(): string {
			return '';
		}
	}
	class Product {
		public ?ProductDetail $detail = null;
		/** @var iterable<ProductVariation> */
		public iterable $variants = array();
		/** @param array<int|string, mixed> $relations */
		public static function with( array $relations ): self {
			return new self();
		}
		public function find( int $id ): ?self {
			return null;
		}
	}
}

namespace FluentCart\App\Helpers {
	class Helper {
		public static function toDecimalWithoutComma( mixed $amount ): float {
			return 0.0;
		}
		public static function toDecimal( mixed $amount ): string {
			return '';
		}
	}
}

namespace FluentCart\Api {
	class CurrencySettings {
		public static function get( string $key ): mixed {
			return null;
		}
	}
	class StoreSettings {
		public function getCartPage(): string {
			return '';
		}
		public function getCheckoutPage(): string {
			return '';
		}
	}
}
