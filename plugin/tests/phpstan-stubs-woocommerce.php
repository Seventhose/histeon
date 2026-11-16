<?php
/**
 * WooCommerce classes and functions XB reads at runtime (static analysis only).
 *
 * @package XB
 */

class WC_Product {
	public function __construct( int $product = 0 ) {}
	public function get_id(): int { return 0; }
	public function get_price( string $context = 'view' ): string { return ''; }
	public function get_regular_price( string $context = 'view' ): string { return ''; }
	public function get_sale_price( string $context = 'view' ): string { return ''; }
	public function is_on_sale(): bool { return false; }
	public function get_price_html(): string { return ''; }
	public function get_sku( string $context = 'view' ): string { return ''; }
	public function is_in_stock(): bool { return true; }
	public function get_stock_status( string $context = 'view' ): string { return ''; }
	public function get_type(): string { return ''; }
	public function get_short_description( string $context = 'view' ): string { return ''; }
	public function get_name( string $context = 'view' ): string { return ''; }
	public function get_status( string $context = 'view' ): string { return ''; }
	public function get_manage_stock( string $context = 'view' ): bool { return false; }
	public function add_to_cart_url(): string { return ''; }
	public function add_to_cart_text(): string { return ''; }
	public function get_average_rating(): string { return ''; }
	public function get_review_count(): int { return 0; }
	public function get_image_id( string $context = 'view' ): string { return ''; }
	/** @return list<int> */
	public function get_gallery_image_ids( string $context = 'view' ): array { return array(); }
	public function get_catalog_visibility(): string { return ''; }
	public function set_name( string $name ): void {}
	public function set_status( string $status ): void {}
	public function set_regular_price( string $price ): void {}
	public function set_sale_price( string $price ): void {}
	public function set_sku( string $sku ): void {}
	public function set_catalog_visibility( string $visibility ): void {}
	public function set_short_description( string $text ): void {}
	public function set_stock_status( string $status ): void {}
	public function set_image_id( int|string $id ): void {}
	/** @param list<int> $ids */
	public function set_gallery_image_ids( array $ids ): void {}
	/** @param list<int> $ids */
	public function set_category_ids( array $ids ): void {}
	/** @param list<int> $ids */
	public function set_tag_ids( array $ids ): void {}
	public function save(): int { return 0; }
}

class WooCommerce {}
class WC_Data_Exception extends Exception {}
function wc_get_product_id_by_sku( string $sku ): int { return 0; }

/** @return WC_Product|null|false */
function wc_get_product( mixed $product = false ) { return null; }
function get_woocommerce_currency(): string { return ''; }
function get_woocommerce_currency_symbol( string $currency = '' ): string { return ''; }

class WC_Product_Simple extends WC_Product {}
