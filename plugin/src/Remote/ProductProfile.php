<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

/**
 * Products in the Histeon CMS bridge (docs/spec/ecommerce.md §Prodotti dal CMS, docs/spec/cms-bridge.md §15): the
 * content type `product` of a Histeon Publish project is WooCommerce's catalogue. Its fields are fixed, the same as
 * Publish's (`packages/histos-contract/src/commerce.ts`); the categories and tags are WooCommerce's `product_cat` and
 * `product_tag`. Catalogue only: never orders, customers, carts, stock quantities or stock movements. Only simple
 * products travel.
 *
 * Staging is the records': a new product is a draft, an existing draft changes in place, a published one waits in the
 * development queue for the next Histeon release (ContentProfileAdapter).
 */
final class ProductProfile {

	public const TYPE = 'product';
	public const TAXONOMIES = array( 'product_cat', 'product_tag' );
	public const CAPABILITY = 'commerce:products:v1';
	public const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
	public const MAX_PRICE = 999999999;
	public const PRICE_DECIMALS = 4;
	public const MAX_GALLERY = 20;
	private const SKU = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/D';
	/** What a send wrote, and the hash of the WooCommerce data it gave: a WordPress edit after it wins (cms-bridge §5). */
	private const DATA_META = '_xb_page_profile_product';
	/** The labels the project gives the product type and its taxonomies: project metadata, never shown by WooCommerce. */
	private const LABELS = 'xb_page_profile_product_labels';
	private const DEFAULT_LABELS = array( 'product' => 'Prodotti', 'product_cat' => 'Categorie prodotto', 'product_tag' => 'Tag prodotto' );

	/**
	 * The product type's fields, in Publish's order and shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function fields(): array {
		$field = static fn ( string $key, string $label, string $type, bool $multiple = false ): array => array( 'key' => $key, 'label' => $label, 'type' => $type, 'required' => false, 'public' => true, 'multiple' => $multiple );
		return array(
			$field( 'regular_price', 'Prezzo', 'decimal' ),
			$field( 'sale_price', 'Prezzo scontato', 'decimal' ),
			$field( 'sku', 'SKU', 'text' ),
			$field( 'stock_status', 'Disponibilità', 'enum' ) + array( 'options' => self::STOCK_STATUSES ),
			$field( 'image', 'Immagine', 'asset' ),
			$field( 'gallery', 'Galleria', 'asset', true ),
		);
	}

	/** WooCommerce is active: the site declares CAPABILITY and takes products. */
	public static function available(): bool {
		return class_exists( '\WooCommerce' ) && function_exists( 'wc_get_product' ) && post_type_exists( self::TYPE ) && taxonomy_exists( 'product_cat' ) && taxonomy_exists( 'product_tag' );
	}

	/**
	 * Whether a transfer has products: the product type or WooCommerce's product taxonomies.
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	public static function uses_products( array $entities ): bool {
		foreach ( $entities as $entity ) {
			$key = $entity['payload']['key'] ?? null;
			if ( ( 'content-type' === ( $entity['kind'] ?? null ) && self::TYPE === $key ) || ( 'taxonomy' === ( $entity['kind'] ?? null ) && in_array( $key, self::TAXONOMIES, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_price( mixed $value ): bool {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}
		$number = (float) $value;
		return is_finite( $number ) && $number >= 0 && $number <= self::MAX_PRICE && round( $number, self::PRICE_DECIMALS ) === $number;
	}

	public static function is_plain_text( mixed $value ): bool {
		return is_string( $value ) && 1 !== preg_match( '/[<>]/', $value );
	}

	private static function present( mixed $value ): bool {
		return null !== $value && '' !== $value && array() !== $value;
	}

	/**
	 * The problems of a product's data beyond its field types, as Publish's productValueIssues() says them.
	 *
	 * @param array<string, mixed> $values
	 * @return list<string>
	 */
	public static function value_issues( mixed $title, mixed $excerpt, array $values ): array {
		$issues  = array();
		$regular = $values['regular_price'] ?? null;
		$sale    = $values['sale_price'] ?? null;
		foreach ( array( 'regular_price' => $regular, 'sale_price' => $sale ) as $key => $value ) {
			if ( self::present( $value ) && ! self::is_price( $value ) ) {
				$issues[] = $key . ': un prezzo è un numero da 0 a ' . self::MAX_PRICE . ', con al massimo ' . self::PRICE_DECIMALS . ' decimali.';
			}
		}
		if ( self::present( $sale ) && ! self::present( $regular ) ) {
			$issues[] = 'sale_price: un prezzo scontato vuole anche regular_price, il prezzo pieno.';
		} elseif ( self::is_price( $sale ) && self::is_price( $regular ) && (float) $sale >= (float) $regular ) {
			$issues[] = 'sale_price: il prezzo scontato è minore del prezzo pieno (regular_price).';
		}
		$sku = $values['sku'] ?? null;
		if ( self::present( $sku ) && ( ! is_string( $sku ) || 1 !== preg_match( self::SKU, $sku ) ) ) {
			$issues[] = 'sku: lettere, cifre, «.», «_», «-» e «/», al massimo 64 caratteri.';
		}
		$status = $values['stock_status'] ?? null;
		if ( self::present( $status ) && ! in_array( $status, self::STOCK_STATUSES, true ) ) {
			$issues[] = 'stock_status: uno fra ' . implode( ', ', self::STOCK_STATUSES ) . '.';
		}
		$gallery = $values['gallery'] ?? null;
		if ( is_array( $gallery ) ) {
			if ( count( $gallery ) > self::MAX_GALLERY ) {
				$issues[] = 'gallery: al massimo ' . self::MAX_GALLERY . ' immagini.';
			}
			if ( count( array_unique( array_map( 'strval', array_filter( $gallery, 'is_scalar' ) ) ) ) !== count( $gallery ) ) {
				$issues[] = 'gallery: un\'immagine è ripetuta.';
			}
		}
		if ( ! self::is_plain_text( $title ) ) {
			$issues[] = 'title: il nome di un prodotto è testo, senza markup (< e >).';
		}
		if ( ! self::is_plain_text( $excerpt ) ) {
			$issues[] = 'excerpt: la descrizione breve di un prodotto è testo, senza markup (< e >).';
		}
		return $issues;
	}

	/**
	 * The transfer's rules for products (PageProfile::validate): the fixed fields, the taxonomies of products only,
	 * each product's data and one SKU per product.
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	public static function validate( array $entities ): void {
		$fields = PageProfile::canonical( self::fields() );
		$skus   = array();
		foreach ( $entities as $entity ) {
			$payload = is_array( $entity['payload'] ?? null ) ? $entity['payload'] : array();
			switch ( $entity['kind'] ?? null ) {
				case 'content-type':
					if ( in_array( $payload['key'] ?? null, self::TAXONOMIES, true ) ) {
						throw new \InvalidArgumentException( 'product_cat and product_tag are the product taxonomies, not content types.' );
					}
					if ( self::TYPE === ( $payload['key'] ?? null ) && PageProfile::canonical( $payload['fields'] ?? null ) !== $fields ) {
						throw new \InvalidArgumentException( 'The product type has exactly the product fields.' );
					}
					break;
				case 'taxonomy':
					if ( in_array( $payload['key'] ?? null, self::TAXONOMIES, true ) && array( self::TYPE ) !== ( $payload['types'] ?? null ) ) {
						throw new \InvalidArgumentException( 'Product categories and tags belong to products only.' );
					}
					break;
				case 'content-record':
					if ( self::TYPE !== ( $payload['typeKey'] ?? null ) ) {
						break;
					}
					$values = is_array( $payload['values'] ?? null ) ? $payload['values'] : array();
					$issues = self::value_issues( $payload['title'] ?? null, $payload['excerpt'] ?? null, $values );
					if ( array() !== $issues ) {
						throw new \InvalidArgumentException( 'Invalid product: ' . implode( ' ', $issues ) );
					}
					$sku = $values['sku'] ?? null;
					if ( is_string( $sku ) && '' !== $sku ) {
						if ( isset( $skus[ $sku ] ) ) {
							throw new \InvalidArgumentException( 'Two products share a SKU.' );
						}
						$skus[ $sku ] = true;
					}
					break;
			}
		}
	}

	/**
	 * Before any write: WooCommerce is active, the link's user may edit products and their terms, each SKU is free on
	 * the site, an existing product is a simple one. Throws a DomainException in Italian.
	 *
	 * @param array<string, array<string, mixed>> $records    Product records by portable id.
	 * @param array<string, array<string, mixed>> $types      Product type entities by portable id.
	 * @param array<string, array<string, mixed>> $taxonomies Product taxonomy entities by portable id.
	 * @param array<string, array<string, mixed>> $terms      Term entities of the product taxonomies by portable id.
	 * @param callable(string): ?\WP_Post         $linked     The product post of a portable id.
	 */
	public static function preflight( array $records, array $types, array $taxonomies, array $terms, callable $linked ): void {
		if ( array() === $records && array() === $types && array() === $taxonomies && array() === $terms ) {
			return;
		}
		if ( ! self::available() ) {
			throw new \DomainException( 'Il progetto ha dei prodotti, ma WooCommerce non è attivo su questo sito.' );
		}
		if ( ( array() !== $records || array() !== $types ) && ! current_user_can( 'edit_products' ) ) {
			throw new \DomainException( 'L\'utente del collegamento non può modificare i prodotti di WooCommerce.' );
		}
		if ( array() !== $terms && ! current_user_can( 'manage_product_terms' ) ) {
			throw new \DomainException( 'L\'utente del collegamento non può modificare le categorie e i tag dei prodotti.' );
		}
		foreach ( $records as $id => $entity ) {
			$payload = $entity['payload'];
			$post    = $linked( (string) $id );
			if ( $post instanceof \WP_Post ) {
				$product = wc_get_product( $post->ID );
				if ( $product instanceof \WC_Product && 'simple' !== $product->get_type() ) {
					throw new \DomainException( 'Il prodotto «' . $payload['title'] . '» in WooCommerce non è più un prodotto semplice: Histeon porta solo prodotti semplici.' );
				}
			}
			$sku = $payload['values']['sku'] ?? null;
			if ( is_string( $sku ) && '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$owner = (int) wc_get_product_id_by_sku( $sku );
				if ( $owner > 0 && ( ! $post instanceof \WP_Post || $owner !== $post->ID ) ) {
					throw new \DomainException( 'Lo SKU «' . $sku . '» del prodotto «' . $payload['title'] . '» è già di un altro prodotto WooCommerce.' );
				}
			}
		}
	}

	/** The labels of the product type and its taxonomies, written with the send: project metadata only. */
	/**
	 * @param array<string, array<string, mixed>> $types
	 * @param array<string, array<string, mixed>> $taxonomies
	 */
	public static function remember_labels( array $types, array $taxonomies ): void {
		$labels = self::labels();
		foreach ( array_merge( array_values( $types ), array_values( $taxonomies ) ) as $entity ) {
			$key   = $entity['payload']['key'] ?? null;
			$label = $entity['payload']['label'] ?? null;
			if ( is_string( $key ) && isset( self::DEFAULT_LABELS[ $key ] ) && is_string( $label ) && '' !== $label ) {
				$labels[ $key ] = mb_substr( $label, 0, 120 );
			}
		}
		update_option( self::LABELS, $labels, false );
	}

	/** @return array<string, string> */
	public static function labels(): array {
		$stored = get_option( self::LABELS, array() );
		$labels = self::DEFAULT_LABELS;
		foreach ( is_array( $stored ) ? $stored : array() as $key => $label ) {
			if ( isset( $labels[ $key ] ) && is_string( $label ) && '' !== $label ) {
				$labels[ $key ] = $label;
			}
		}
		return $labels;
	}

	/**
	 * Writes a product's catalogue data after its post is saved (ContentProfileAdapter::apply_contents): prices, SKU,
	 * stock status (never a quantity), image, gallery, categories and tags, through WooCommerce's own API.
	 *
	 * @param array<string, mixed> $payload   The record's payload.
	 * @param array<string, int>   $media_ids Portable asset id → attachment id.
	 * @param array<string, int>   $term_ids  Portable term id → term id.
	 */
	public static function write( int $post_id, array $payload, array $media_ids, array $term_ids ): void {
		$values  = is_array( $payload['values'] ?? null ) ? $payload['values'] : array();
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \WC_Product_Simple ) {
			$product = new \WC_Product_Simple( $post_id );
		}
		$attachment = static function ( mixed $asset ) use ( $media_ids, $payload ): int {
			$id = is_string( $asset ) ? (int) ( $media_ids[ $asset ] ?? 0 ) : 0;
			if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
				throw new \RuntimeException( sprintf( /* translators: %s: product name */ __( 'Un\'immagine del prodotto «%s» non è un\'immagine della libreria.', 'xb' ), (string) $payload['title'] ) );
			}
			return $id;
		};
		$terms = is_array( $payload['terms'] ?? null ) ? $payload['terms'] : array();
		try {
			$product->set_regular_price( self::price_text( $values['regular_price'] ?? null ) );
			$product->set_sale_price( self::price_text( $values['sale_price'] ?? null ) );
			$product->set_sku( is_string( $values['sku'] ?? null ) ? $values['sku'] : '' );
			$product->set_stock_status( is_string( $values['stock_status'] ?? null ) && '' !== $values['stock_status'] ? $values['stock_status'] : 'instock' );
			$product->set_image_id( self::present( $values['image'] ?? null ) ? $attachment( $values['image'] ) : '' );
			$product->set_gallery_image_ids( is_array( $values['gallery'] ?? null ) ? array_map( $attachment, array_values( array_filter( $values['gallery'], array( self::class, 'present' ) ) ) ) : array() );
			$map = static function ( string $taxonomy ) use ( $terms, $term_ids, $payload ): array {
				$ids = array();
				foreach ( (array) ( $terms[ $taxonomy ] ?? array() ) as $id ) {
					$term_id = is_string( $id ) ? (int) ( $term_ids[ $id ] ?? 0 ) : 0;
					if ( $term_id < 1 ) {
						throw new \RuntimeException( sprintf( /* translators: %s: product name */ __( 'WordPress non ha trovato tutti i termini del prodotto «%s».', 'xb' ), (string) $payload['title'] ) );
					}
					$ids[] = $term_id;
				}
				return $ids;
			};
			$product->set_category_ids( $map( 'product_cat' ) );
			$product->set_tag_ids( $map( 'product_tag' ) );
			$product->save();
		} catch ( \WC_Data_Exception $error ) {
			throw new \RuntimeException( sprintf( /* translators: 1: product name, 2: error message */ __( 'Prodotto «%1$s» non salvato: %2$s', 'xb' ), (string) $payload['title'], $error->getMessage() ), 0, $error );
		}
		$profile_terms = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( isset( $terms[ $taxonomy ] ) ) {
				$profile_terms[ $taxonomy ] = $terms[ $taxonomy ];
			}
		}
		update_post_meta( $post_id, self::DATA_META, wp_slash( array( 'values' => $values, 'terms' => $profile_terms, 'native' => self::native_hash( $post_id ) ) ) );
	}

	/**
	 * A product as the profile carries it, for the export: values and terms, with the assets it shows appended to
	 * `$entities`. What a send wrote comes back as it was sent while WooCommerce still has the data the send gave it;
	 * otherwise it is read from WooCommerce. Null for a product the profile cannot carry (not simple, or data
	 * WooCommerce allows and the profile does not): left out of the export, unless it is the project's, then an error.
	 *
	 * @param array<string, string>      $files
	 * @param list<array<string, mixed>> $entities
	 * @param callable(\WP_Term): string $term_id  The portable id of a term ('' without one).
	 * @return array{values: array<string, mixed>, terms: array<string, list<string>>}|null
	 */
	public static function export( \WP_Post $post, bool $owned, array &$files, bool $ensure, array &$entities, callable $term_id ): ?array {
		$product = wc_get_product( $post->ID );
		$refuse  = static function ( string $why ) use ( $owned, $post ): null {
			if ( $owned ) {
				throw new \DomainException( 'Il prodotto «' . $post->post_title . '» in WooCommerce ' . $why . ': correggilo in WordPress prima di scaricare il progetto.' );
			}
			return null;
		};
		if ( ! $product instanceof \WC_Product || 'simple' !== $product->get_type() ) {
			return $refuse( 'non è un prodotto semplice' );
		}
		$asset = static function ( int $id ) use ( &$files, $ensure, &$entities ): string {
			$attachment = get_post( $id );
			if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
				throw new \DomainException( 'Un\'immagine di un prodotto punta a un allegato assente.' );
			}
			$entity = PageProfileAdapter::transfer_asset_entity( $attachment, $files, $ensure );
			foreach ( $entities as $existing ) {
				if ( ( $existing['id'] ?? null ) === $entity['id'] ) {
					return (string) $entity['id'];
				}
			}
			$entities[] = $entity;
			return (string) $entity['id'];
		};
		$image   = (int) $product->get_image_id( 'edit' );
		$gallery = array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) );
		$side    = get_post_meta( $post->ID, self::DATA_META, true );
		if ( is_array( $side ) && is_array( $side['values'] ?? null ) && is_array( $side['terms'] ?? null ) && is_string( $side['native'] ?? null ) && hash_equals( $side['native'], self::native_hash( $post->ID ) ) ) {
			foreach ( array_filter( array_merge( array( $image ), $gallery ) ) as $id ) {
				$asset( $id );
			}
			/** @var array{values: array<string, mixed>, terms: array<string, list<string>>} */
			return array( 'values' => $side['values'], 'terms' => $side['terms'] );
		}
		$values = array();
		foreach ( array( 'regular_price' => $product->get_regular_price( 'edit' ), 'sale_price' => $product->get_sale_price( 'edit' ) ) as $key => $text ) {
			if ( '' === $text ) {
				continue;
			}
			$number = is_numeric( $text ) ? $text + 0 : null;
			if ( ! self::is_price( $number ) ) {
				return $refuse( 'ha un prezzo che Histeon non porta' );
			}
			$values[ $key ] = $number;
		}
		$sku = $product->get_sku( 'edit' );
		if ( '' !== $sku ) {
			$values['sku'] = $sku;
		}
		$values['stock_status'] = $product->get_stock_status( 'edit' );
		if ( $image > 0 ) {
			$values['image'] = $asset( $image );
		}
		if ( array() !== $gallery ) {
			$values['gallery'] = array_map( $asset, $gallery );
		}
		if ( array() !== self::value_issues( $post->post_title, $post->post_excerpt, $values ) ) {
			return $refuse( 'ha dati che Histeon non porta (prezzi, SKU, disponibilità, galleria o markup nel nome o nella descrizione breve)' );
		}
		$terms = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$assigned = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $assigned ) ) {
				throw new \DomainException( 'Impossibile leggere i termini del prodotto WordPress.' );
			}
			$ids = array();
			foreach ( $assigned as $term ) {
				// WooCommerce files a product without categories under its default one: not a choice of the project.
				if ( 'product_cat' === $taxonomy && (int) $term->term_id === (int) get_option( 'default_product_cat', 0 ) && 1 === count( $assigned ) ) {
					continue;
				}
				$id = $term_id( $term );
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}
			if ( array() !== $ids ) {
				$terms[ $taxonomy ] = array_values( array_unique( $ids ) );
			}
		}
		return array( 'values' => $values, 'terms' => $terms );
	}

	/** The WooCommerce data a send gives a product, as a hash: changed in WordPress after the send when it differs. */
	public static function native_hash( int $post_id ): string {
		clean_post_cache( $post_id );
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		$terms = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			$terms[ $taxonomy ] = $ids;
		}
		return hash(
			'sha256',
			PageProfile::canonical(
				array(
					'type'    => $product->get_type(),
					'regular' => $product->get_regular_price( 'edit' ),
					'sale'    => $product->get_sale_price( 'edit' ),
					'sku'     => $product->get_sku( 'edit' ),
					'stock'   => $product->get_stock_status( 'edit' ),
					'image'   => (int) $product->get_image_id( 'edit' ),
					'gallery' => array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ),
					'terms'   => $terms,
				)
			)
		);
	}

	/** A price as WooCommerce stores it: decimal text, '' for none. */
	private static function price_text( mixed $value ): string {
		if ( ! self::present( $value ) ) {
			return '';
		}
		if ( ! self::is_price( $value ) ) {
			throw new \RuntimeException( __( 'Un prezzo del prodotto non è valido.', 'xb' ) );
		}
		return is_int( $value ) ? (string) $value : rtrim( rtrim( number_format( (float) $value, self::PRICE_DECIMALS, '.', '' ), '0' ), '.' );
	}
}
