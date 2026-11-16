<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Versioning\Documents;
use XB\Content\ContentTypes;
use XB\Render\LoopPresets;
use XB\Render\Queries;
use XB\Render\Sources;

/**
 * Maps Histeon CMS entities onto native WordPress content types, fields, terms and posts.
 * It deliberately does not delete unpaired WordPress content.
 *
 * @phpstan-import-type FieldDef from ContentTypes
 */
final class ContentProfileAdapter {

	private const ID_META = '_xb_page_profile_id';
	private const BASE_META = '_xb_page_profile_base_hash';
	private const VALUES_META = '_xb_page_profile_values';
	/**
	 * The record's terms in the order the profile sent them, taxonomy by taxonomy. WordPress reads terms back by name,
	 * so without it a record with two terms out of that order came back with another revision hash, and Histeon
	 * Publish refused the send (`cms-remote-heads-invalid`, found by the version test of doc 23 item 202).
	 */
	private const TERMS_META = '_xb_page_profile_terms';
	private const LOCALE_META = '_xb_page_profile_locale';
	private const STATUS_META = '_xb_page_profile_status';
	private const DATE_META = '_xb_page_profile_published_at';
	private const SLUG_META = '_xb_page_profile_slug';
	private const TERM_SLUG_META = '_xb_page_profile_term_slug';
	private const TEMPLATE_REF_META = '_xb_page_profile_ref';
	private const TYPE_IDS = 'xb_page_profile_content_type_ids';
	private const TYPE_LAYOUTS = 'xb_page_profile_content_type_templates';
	private const TAXONOMY_IDS = 'xb_page_profile_taxonomy_ids';
	/** The project's name for WordPress's categories and tags: WordPress keeps its own, and it comes back as sent. */
	private const NATIVE_LABELS = 'xb_page_profile_native_taxonomy_labels';
	private const QUERY_IDS = 'xb_page_profile_query_ids';
	private const SOURCE_IDS = 'xb_page_profile_source_ids';
	private const BASES = 'xb_page_profile_relational_bases';
	private const DEFAULT_LOCALE = 'xb_page_profile_default_locale';
	/** Records and terms sent without publishing: they wait here for the next Histeon release (§5). */
	public const QUEUE = 'xb_cms_pending_contents';
	/** WordPress's own taxonomies for posts (§12), with fixed labels: the hash never follows the site language. */
	private const NATIVE_TAXONOMY_LABELS = array( 'category' => 'Categorie', 'post_tag' => 'Tag' );

	/** Register the safe public query filter and project routes after Histeon post types exist. */
	/**
	 * Query filters and type templates. No rewrite rules: records use their type's WordPress permalinks
	 * (docs/spec/cms-bridge.md §7), so existing addresses never change.
	 */
	public static function boot(): void {
		add_filter( 'posts_where', array( self::class, 'filter_posts_where' ), 10, 2 );
		add_filter( 'posts_orderby', array( self::class, 'filter_posts_orderby' ), 10, 2 );
		add_filter( 'get_block_templates', array( self::class, 'apply_type_templates' ), 10, 3 );
	}

	/**
	 * The name a project gave to categories and tags, in the administration too.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function native_taxonomy_labels( array $args, string $taxonomy ): array {
		$named = get_option( self::NATIVE_LABELS, array() );
		if ( isset( self::NATIVE_TAXONOMY_LABELS[ $taxonomy ] ) && is_array( $named ) && is_string( $named[ $taxonomy ] ?? null ) && '' !== $named[ $taxonomy ] ) {
			$labels         = is_array( $args['labels'] ?? null ) ? $args['labels'] : array();
			$args['labels'] = array( 'name' => $named[ $taxonomy ], 'menu_name' => $named[ $taxonomy ] ) + $labels;
		}
		return $args;
	}

	/** Use the Histeon template assigned to a content type when WordPress resolves its single or archive hierarchy. */
	/**
	 * @param array<array-key, mixed> $templates
	 * @param array<string, mixed> $query
	 * @return array<array-key, mixed>
	 */
	public static function apply_type_templates( array $templates, array $query, string $template_type ): array {
		if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) || ! is_array( $query['slug__in'] ) ) {
			return $templates;
		}
		$requested = array_fill_keys( array_filter( $query['slug__in'], 'is_string' ), true );
		$definitions = ContentTypes::live();
		$layouts = (array) get_option( self::TYPE_LAYOUTS, array() );
		$aliases = array();
		foreach ( $definitions['types'] as $type_key => $type ) {
			if ( empty( $type['public'] ) || ! is_array( $layouts[ $type_key ] ?? null ) ) {
				continue;
			}
			foreach ( array( 'singleTemplateRef' => 'single-', 'archiveTemplateRef' => 'archive-' ) as $layout_key => $prefix ) {
				$ref = $layouts[ $type_key ][ $layout_key ] ?? null;
				if ( ! is_string( $ref ) || ( 'archiveTemplateRef' === $layout_key && empty( $type['archive'] ) ) ) {
					continue;
				}
				$slug = $prefix . $type_key;
				if ( isset( $requested[ $slug ] ) ) {
					$aliases[ $slug ] = $ref;
				}
			}
		}
		// The shop's products (queue C6): WooCommerce's product and shop templates take the project's.
		if ( is_array( $layouts[ ProductProfile::TYPE ] ?? null ) && ProductProfile::available() ) {
			foreach ( array( 'singleTemplateRef' => 'single-', 'archiveTemplateRef' => 'archive-' ) as $layout_key => $prefix ) {
				$ref  = $layouts[ ProductProfile::TYPE ][ $layout_key ] ?? null;
				$slug = $prefix . ProductProfile::TYPE;
				if ( is_string( $ref ) && isset( $requested[ $slug ] ) ) {
					$aliases[ $slug ] = $ref;
				}
			}
		}
		foreach ( $aliases as $slug => $ref ) {
			$template = self::published_template_for_ref( $ref );
			if ( ! $template instanceof \WP_Block_Template ) {
				continue;
			}
			$template->slug = $slug;
			$template->id = get_stylesheet() . '//' . $slug;
			// An explicit Histeon assignment takes precedence over a theme template with the same hierarchy slug.
			$templates = array_values( array_filter( $templates, static fn ( mixed $candidate ): bool => ! ( $candidate instanceof \WP_Block_Template && $candidate->slug === $slug ) ) );
			$templates[] = $template;
		}
		return $templates;
	}

	private static function published_template_for_ref( string $ref ): ?\WP_Block_Template {
		$ids = get_posts( array(
			'post_type' => 'wp_template',
			'post_status' => 'publish',
			'numberposts' => 2,
			'fields' => 'ids',
			'meta_key' => self::TEMPLATE_REF_META,
			'meta_value' => $ref,
			'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => get_stylesheet() ) ),
			'suppress_filters' => true,
		) );
		if ( 1 !== count( $ids ) ) {
			return null;
		}
		$post_id = (int) $ids[0];
		$slug = get_post_field( 'post_name', $post_id );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}
		$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
		return $template instanceof \WP_Block_Template && (int) $template->wp_id === $post_id ? $template : null;
	}





	/**
	 * Doc 23 item 232: a Histeon query ordered by a field of its type, as Publish orders it (content-model.ts): by
	 * the field's value, numbers as numbers, records without a value first going up and last going down, ties by
	 * ID. Not WP_Query's meta_key order, which would drop the records without a value. Only for a public field with
	 * one value of a live Histeon type: anything else keeps the order WordPress already has.
	 */
	public static function filter_posts_orderby( string $orderby, \WP_Query $query ): string {
		global $wpdb;
		$field_key = $query->get( 'xb_order_field' );
		$type      = $query->get( 'xb_type_key' );
		if ( ! is_string( $field_key ) || ! is_string( $type ) || ! isset( ContentTypes::live()['types'][ $type ] ) ) { return $orderby; }
		$field = self::sortable_field( $type, $field_key );
		if ( null === $field ) { return $orderby; }
		// LoopPresets::stable_order moves the direction into an orderby list; a plain query keeps it in `order`.
		$given     = $query->get( 'orderby' );
		$direction = is_array( $given ) && array() !== $given ? (string) reset( $given ) : (string) $query->get( 'order' );
		$direction = 'ASC' === strtoupper( $direction ) ? 'ASC' : 'DESC';
		$value     = $wpdb->prepare( "SELECT xb_cms_order.meta_value FROM {$wpdb->postmeta} xb_cms_order WHERE xb_cms_order.post_id = {$wpdb->posts}.ID AND xb_cms_order.meta_key = %s ORDER BY xb_cms_order.meta_id LIMIT 1", $field_key );
		$numeric   = in_array( $field['type'], array( 'number', 'integer', 'decimal' ), true );
		return '(' . ( $numeric ? 'CAST((' . $value . ') AS DECIMAL(65,10))' : $value ) . ') ' . $direction . ", {$wpdb->posts}.ID ASC";
	}

	/**
	 * A field of a live type a query may order by: public, with one value, not rich text, media or a link to a post.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function sortable_field( string $type, string $field_key ): ?array {
		foreach ( ContentTypes::fields_for( $type ) as $field ) {
			if ( $field['name'] === $field_key ) {
				$sortable = $field['public'] && ! $field['multiple'] && ! in_array( $field['type'], array( 'rich-text', 'textarea', 'image', 'file', 'post', 'repeater' ), true );
				return $sortable ? $field : null;
			}
		}
		return null;
	}

	/** Apply the validated CMS query filter only to Histeon content queries. */
	public static function filter_posts_where( string $where, \WP_Query $query ): string {
		$filter = $query->get( 'xb_filter' );
		$type = $query->get( 'xb_type_key' );
		if ( ! is_array( $filter ) || ! is_string( $type ) || ! isset( ContentTypes::live()['types'][ $type ] ) ) { return $where; }
		$condition = self::compile_filter_sql( $filter, $type, 0 );
		return null === $condition ? $where . ' AND 1=0' : $where . ' AND (' . $condition . ')';
	}

	/** @param array<mixed> $filter */
	private static function compile_filter_sql( array $filter, string $type, int $depth ): ?string {
		global $wpdb;
		if ( $depth > 4 || ( 0 === $depth && PageProfile::filter_leaves( $filter ) > PageProfile::MAX_FILTER_LEAVES ) ) { return null; }
		if ( 1 === count( $filter ) && ( isset( $filter['and'] ) || isset( $filter['or'] ) ) ) {
			$key = isset( $filter['and'] ) ? 'and' : 'or';
			$children = $filter[ $key ];
			if ( ! is_array( $children ) || ! array_is_list( $children ) || array() === $children || count( $children ) > 32 ) { return null; }
			$parts = array();
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || null === ( $part = self::compile_filter_sql( $child, $type, $depth + 1 ) ) ) { return null; }
				$parts[] = '(' . $part . ')';
			}
			return implode( 'and' === $key ? ' AND ' : ' OR ', $parts );
		}
		if ( isset( $filter['taxonomyKey'] ) ) {
			$taxonomy = (string) $filter['taxonomyKey'];
			$profile_terms = $filter['termIds'] ?? null;
			$operator = $filter['operator'] ?? null;
			if ( ! is_array( $profile_terms ) || ! array_is_list( $profile_terms ) || array() === $profile_terms || count( $profile_terms ) > 100 || ! in_array( $operator, array( 'IN', 'NOT IN', 'AND' ), true ) || ! in_array( $type, ContentTypes::live()['taxonomies'][ $taxonomy ]['types'] ?? array(), true ) ) { return null; }
			$term_ids = array();
			foreach ( $profile_terms as $profile_id ) {
				if ( ! is_string( $profile_id ) ) { return null; }
				$term = self::term_by_profile_id( $profile_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) { return null; }
				$term_ids[] = (int) $term->term_id;
			}
			$term_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$taxonomy_sql = $wpdb->prepare( 'xb_cms_tt.taxonomy = %s', $taxonomy );
			$ids_sql = $wpdb->prepare( $term_placeholders, ...$term_ids );
			$from = "{$wpdb->term_relationships} xb_cms_rel JOIN {$wpdb->term_taxonomy} xb_cms_tt ON xb_cms_tt.term_taxonomy_id = xb_cms_rel.term_taxonomy_id";
			$where_sql = "xb_cms_rel.object_id = {$wpdb->posts}.ID AND $taxonomy_sql AND xb_cms_tt.term_id IN ($ids_sql)";
			if ( 'NOT IN' === $operator ) { return "NOT EXISTS (SELECT 1 FROM $from WHERE $where_sql)"; }
			if ( 'AND' === $operator ) { return "(SELECT COUNT(DISTINCT xb_cms_tt.term_id) FROM $from WHERE $where_sql) = " . count( $term_ids ); }
			return "EXISTS (SELECT 1 FROM $from WHERE $where_sql)";
		}
		return self::compile_field_filter_sql( $filter, $type, 0 );
	}

	/** Compile a public scalar or relation field path, with no more than three relation joins. */
	/** @param array<string, mixed> $filter */
	private static function compile_field_filter_sql( array $filter, string $type, int $relation_depth ): ?string {
		global $wpdb;
		$field_path = is_string( $filter['field'] ?? null ) ? explode( '.', $filter['field'] ) : array();
		$operator = $filter['op'] ?? null;
		if ( array() === $field_path || count( $field_path ) > 4 || count( array_filter( $field_path, static fn ( string $key ): bool => 1 !== preg_match( '/^[a-z][a-z0-9_]{0,62}$/', $key ) ) ) > 0 || ! in_array( $operator, array( 'eq', 'ne', 'in', 'lt', 'lte', 'gt', 'gte', 'contains', 'exists' ), true ) ) { return null; }
		$field_key = array_shift( $field_path );
		$field = null;
		foreach ( ContentTypes::fields_for( $type ) as $candidate ) { if ( $candidate['name'] === $field_key ) { $field = $candidate; break; } }
		if ( null === $field || empty( $field['public'] ) ) { return null; }
		if ( array() !== $field_path ) {
			$target_type = $field['postType'];
			if ( 'post' !== $field['type'] || ! isset( ContentTypes::live()['types'][ $target_type ] ) || $relation_depth >= 3 ) { return null; }
			$nested = self::compile_field_filter_sql( array_merge( $filter, array( 'field' => implode( '.', $field_path ) ) ), $target_type, $relation_depth + 1 );
			if ( null === $nested ) { return null; }
			$meta_alias = 'xb_cms_relation_meta_' . $relation_depth;
			$post_alias = 'xb_cms_relation_post_' . $relation_depth;
			$parent_alias = 0 === $relation_depth ? $wpdb->posts : 'xb_cms_relation_post_' . ( $relation_depth - 1 );
			$relation_key_sql = $wpdb->prepare( '%s', $field_key );
			$target_type_sql = $wpdb->prepare( '%s', $target_type );
			$target_join = ! empty( $field['multiple'] )
				? "($meta_alias.meta_value = CAST($post_alias.ID AS CHAR) OR $meta_alias.meta_value LIKE CONCAT('%i:', $post_alias.ID, ';%'))"
				: "$post_alias.ID = CAST($meta_alias.meta_value AS UNSIGNED)";
			return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} $meta_alias INNER JOIN {$wpdb->posts} $post_alias ON $target_join WHERE $meta_alias.post_id = $parent_alias.ID AND $meta_alias.meta_key = $relation_key_sql AND $post_alias.post_type = $target_type_sql AND $post_alias.post_status = 'publish' AND ($nested))";
		}
		if ( in_array( $field['type'], array( 'repeater', 'image', 'rich-text' ), true ) || ! empty( $field['multiple'] ) && 'post' !== $field['type'] ) { return null; }
		if ( 'post' === $field['type'] ) {
			$target_type = $field['postType'];
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/', $target_type ) || ! isset( ContentTypes::live()['types'][ $target_type ] ) ) { return null; }
			$meta_alias = 'xb_cms_relation_meta_' . $relation_depth;
			$post_alias = 'xb_cms_relation_post_' . $relation_depth;
			$parent_alias = 0 === $relation_depth ? $wpdb->posts : 'xb_cms_relation_post_' . ( $relation_depth - 1 );
			$relation_key_sql = $wpdb->prepare( '%s', $field_key );
			$target_type_sql = $wpdb->prepare( '%s', $target_type );
			$target_join = ! empty( $field['multiple'] )
				? "($meta_alias.meta_value = CAST($post_alias.ID AS CHAR) OR $meta_alias.meta_value LIKE CONCAT('%i:', $post_alias.ID, ';%'))"
				: "$post_alias.ID = CAST($meta_alias.meta_value AS UNSIGNED)";
			$target_condition = '';
			if ( 'exists' !== $operator ) {
				if ( 'eq' !== $operator || ! empty( $field['multiple'] ) || ! array_key_exists( 'value', $filter ) || ! is_string( $filter['value'] ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $filter['value'] ) ) { return null; }
				$target_id = $wpdb->prepare( "SELECT target.ID FROM {$wpdb->posts} target INNER JOIN {$wpdb->postmeta} xb_cms_target_identity ON xb_cms_target_identity.post_id = target.ID WHERE target.post_type = %s AND target.post_status = %s AND xb_cms_target_identity.meta_key = %s AND xb_cms_target_identity.meta_value = %s LIMIT 1", $target_type, 'publish', self::ID_META, $filter['value'] );
				$target_condition = " AND $post_alias.ID = ($target_id)";
			} elseif ( array_key_exists( 'value', $filter ) ) { return null; }
			return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} $meta_alias INNER JOIN {$wpdb->posts} $post_alias ON $target_join WHERE $meta_alias.post_id = $parent_alias.ID AND $meta_alias.meta_key = $relation_key_sql AND $post_alias.post_type = $target_type_sql AND $post_alias.post_status = 'publish'$target_condition)";
		}
		if ( 'exists' === $operator ) { return 'EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' xb_cms_meta_' . $relation_depth . ' WHERE xb_cms_meta_' . $relation_depth . '.post_id = ' . ( 0 === $relation_depth ? $wpdb->posts : 'xb_cms_relation_post_' . ( $relation_depth - 1 ) ) . '.ID AND xb_cms_meta_' . $relation_depth . '.meta_key = ' . $wpdb->prepare( '%s', $field_key ) . ')'; }
		if ( ! array_key_exists( 'value', $filter ) ) { return null; }
		$meta_alias = 'xb_cms_meta_' . $relation_depth;
		$parent_alias = 0 === $relation_depth ? $wpdb->posts : 'xb_cms_relation_post_' . ( $relation_depth - 1 );
		$base = "SELECT 1 FROM {$wpdb->postmeta} $meta_alias WHERE $meta_alias.post_id = $parent_alias.ID AND $meta_alias.meta_key = " . $wpdb->prepare( '%s', $field_key );
		$value_sql = "$meta_alias.meta_value";
		if ( in_array( $field['type'], array( 'integer', 'decimal', 'number' ), true ) ) { $value_sql = "CAST($meta_alias.meta_value AS DECIMAL(30,10))"; }
		if ( 'in' === $operator ) {
			$values = $filter['value'];
			if ( ! is_array( $values ) || ! array_is_list( $values ) || array() === $values || count( $values ) > 100 ) { return null; }
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$values_sql = $wpdb->prepare( $placeholders, ...array_map( static fn ( mixed $value ): string => is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value, $values ) );
			return 'EXISTS (' . $base . " AND $value_sql IN ($values_sql))";
		}
		if ( 'contains' === $operator ) {
			if ( ! is_string( $filter['value'] ) || strlen( $filter['value'] ) > 4096 ) { return null; }
			$like = $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $filter['value'] ) . '%' );
			return 'EXISTS (' . $base . " AND $value_sql LIKE $like)";
		}
		$comparisons = array( 'eq' => '=', 'ne' => '<>', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>=' );
		$value = $filter['value'];
		if ( ! is_scalar( $value ) ) { return null; }
		$encoded = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
		$encoded_sql = $wpdb->prepare( '%s', $encoded );
		return 'EXISTS (' . $base . ' AND ' . $value_sql . ' ' . $comparisons[ $operator ] . " $encoded_sql)";
	}

	public static function canonical_locale( string $locale ): string {
		$locale = trim( str_replace( '_', '-', $locale ) );
		if ( 1 !== preg_match( '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale ) ) { return ''; }
		$parts = explode( '-', $locale );
		foreach ( $parts as $index => &$part ) { $part = 0 === $index ? strtolower( $part ) : ( 4 === strlen( $part ) && ctype_alpha( $part ) ? ucfirst( strtolower( $part ) ) : ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ? strtoupper( $part ) : strtolower( $part ) ) ); }
		unset( $part );
		return implode( '-', $parts );
	}

	/** Validate every collision and WordPress-specific limitation before the caller writes any profile entity. */
	/**
	 * @param list<array<string, mixed>> $entities
	 * @return array<string, mixed>
	 */
	public static function preflight( array $entities ): array {
		$incoming = array_values( array_filter( $entities, static fn ( array $entity ): bool => in_array( $entity['kind'] ?? null, array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ), true ) ) );
		if ( array() === $incoming ) {
			return array( 'definitions' => self::dev_definitions(), 'entities' => array() );
		}
		$by_kind = array( 'content-type' => array(), 'content-record' => array(), 'taxonomy' => array(), 'term' => array(), 'query-preset' => array(), 'query-source' => array(), 'native-taxonomy' => array(), 'product-type' => array() );
		foreach ( $incoming as $entity ) {
			// WordPress's categories and tags exist on every site, WooCommerce's product categories and tags with
			// WooCommerce: only their identity is the project's.
			$kind = 'taxonomy' === $entity['kind'] && in_array( $entity['payload']['key'] ?? null, array_merge( PageProfile::NATIVE_TAXONOMIES, ProductProfile::TAXONOMIES ), true ) ? 'native-taxonomy' : $entity['kind'];
			// The product type is WooCommerce's `product` (queue C6): never a type Histeon registers.
			$kind = 'content-type' === $kind && ProductProfile::TYPE === ( $entity['payload']['key'] ?? null ) ? 'product-type' : $kind;
			$by_kind[ $kind ][ $entity['id'] ] = $entity;
		}
		ProductProfile::preflight(
			array_filter( $by_kind['content-record'], static fn ( array $entity ): bool => ProductProfile::TYPE === ( $entity['payload']['typeKey'] ?? null ) ),
			$by_kind['product-type'],
			array_filter( $by_kind['native-taxonomy'], static fn ( array $entity ): bool => in_array( $entity['payload']['key'] ?? null, ProductProfile::TAXONOMIES, true ) ),
			array_filter( $by_kind['term'], static fn ( array $entity ): bool => in_array( $entity['payload']['taxonomyKey'] ?? null, ProductProfile::TAXONOMIES, true ) ),
			static fn ( string $id ): ?\WP_Post => self::post_by_profile_id( $id, ProductProfile::TYPE )
		);
		$type_ids = (array) get_option( self::TYPE_IDS, array() );
		$taxonomy_ids = (array) get_option( self::TAXONOMY_IDS, array() );
		$definitions = self::dev_definitions();
		$types_by_key = array();
		foreach ( $by_kind['content-type'] as $entity ) {
			$payload = $entity['payload'];
			$key = $payload['key'];
			if ( strlen( $key ) > 20 ) {
				throw new \DomainException( 'Il tipo "' . $key . '" supera il limite di 20 caratteri di WordPress e non può essere importato senza rinominarlo.' );
			}
			$mapped = isset( $type_ids[ $key ] ) && is_string( $type_ids[ $key ] ) ? $type_ids[ $key ] : '';
			if ( isset( $definitions['types'][ $key ] ) && $mapped !== $entity['id'] ) {
				throw new \DomainException( 'Il tipo WordPress "' . $key . '" esiste già ma non appartiene a questo progetto Histeon.' );
			}
			if ( post_type_exists( $key ) && ! isset( $definitions['types'][ $key ] ) && $mapped !== $entity['id'] ) {
				throw new \DomainException( 'Il nome "' . $key . '" è già usato da un altro tipo di contenuto WordPress.' );
			}
			$types_by_key[ $key ] = $entity;
		}
		foreach ( $by_kind['native-taxonomy'] as $entity ) {
			$mapped = $taxonomy_ids[ $entity['payload']['key'] ] ?? '';
			if ( is_string( $mapped ) && '' !== $mapped && $mapped !== $entity['id'] ) {
				throw new \DomainException( 'Le categorie o i tag di WordPress appartengono già a un altro progetto Histeon.' );
			}
		}
		$taxonomies_by_key = array();
		foreach ( $by_kind['taxonomy'] as $entity ) {
			$payload = $entity['payload'];
			$key = $payload['key'];
			if ( strlen( $key ) > 32 ) {
				throw new \DomainException( 'La tassonomia "' . $key . '" supera il limite di 32 caratteri di WordPress.' );
			}
			$mapped = isset( $taxonomy_ids[ $key ] ) && is_string( $taxonomy_ids[ $key ] ) ? $taxonomy_ids[ $key ] : '';
			if ( isset( $definitions['taxonomies'][ $key ] ) && $mapped !== $entity['id'] ) {
				throw new \DomainException( 'La tassonomia WordPress "' . $key . '" esiste già ma non appartiene a questo progetto Histeon.' );
			}
			if ( taxonomy_exists( $key ) && ! isset( $definitions['taxonomies'][ $key ] ) && $mapped !== $entity['id'] ) {
				throw new \DomainException( 'Il nome "' . $key . '" è già usato da un’altra tassonomia WordPress.' );
			}
			$taxonomies_by_key[ $key ] = $entity;
		}
		$next = $definitions;
		$type_layouts = self::dev_layouts();
		foreach ( $by_kind['product-type'] as $entity ) {
			$layouts = array();
			foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $layout_key ) {
				$ref = $entity['payload'][ $layout_key ] ?? null;
				if ( null !== $ref && ( ! is_string( $ref ) || strlen( $ref ) > 128 || 1 !== preg_match( '#^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:/[a-z][a-z0-9]*(?:-[a-z0-9]*)*)?$#', $ref ) ) ) {
					throw new \DomainException( 'Il tipo "product" contiene un riferimento template non valido.' );
				}
				if ( is_string( $ref ) ) { $layouts[ $layout_key ] = $ref; }
			}
			$type_layouts[ ProductProfile::TYPE ] = $layouts;
		}
		foreach ( $types_by_key as $key => $entity ) {
			$payload = $entity['payload'];
			$layouts = array();
			foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $layout_key ) {
				$ref = $payload[ $layout_key ] ?? null;
				if ( null !== $ref && ( ! is_string( $ref ) || strlen( $ref ) > 128 || 1 !== preg_match( '#^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:/[a-z][a-z0-9]*(?:-[a-z0-9]*)*)?$#', $ref ) ) ) {
					throw new \DomainException( 'Il tipo "' . $key . '" contiene un riferimento template non valido.' );
				}
				if ( is_string( $ref ) ) { $layouts[ $layout_key ] = $ref; }
			}
			$type_layouts[ $key ] = $layouts;
			$fields = array();
			foreach ( $payload['fields'] as $field ) {
				$fields[] = self::field_to_wordpress( $field );
			}
			$next['types'][ $key ] = $definitions['types'][ $key ] ?? array(
				'singular' => $payload['label'], 'plural' => $payload['label'], 'public' => true, 'archive' => true,
				'slug' => $key, 'hierarchical' => false, 'icon' => 'dashicons-admin-post',
				'supports' => array( 'title', 'editor', 'excerpt', 'page-attributes' ), 'capability' => 'post',
			);
			$next['types'][ $key ]['singular'] = $payload['label'];
			$next['types'][ $key ]['plural'] = $payload['label'];
			// Doc 23 item 122: Histeon CMS says whether the type has pages of its own (WordPress's `public`).
			$next['types'][ $key ]['public'] = false !== ( $payload['public'] ?? true );
			$next['fields'][ $key ] = $fields;
		}
		foreach ( $taxonomies_by_key as $key => $entity ) {
			$payload = $entity['payload'];
			$term_ids = array_fill_keys( array_keys( $by_kind['term'] ), true );
			$hierarchical = false;
			foreach ( $by_kind['term'] as $term ) {
				if ( $term['payload']['taxonomyKey'] === $key && is_string( $term['payload']['parentId'] ) ) {
					$hierarchical = true;
				}
			}
			$next['taxonomies'][ $key ] = $definitions['taxonomies'][ $key ] ?? array(
				'singular' => $payload['label'], 'plural' => $payload['label'], 'public' => true,
				'hierarchical' => $hierarchical, 'slug' => $key, 'types' => array(),
			);
			$next['taxonomies'][ $key ]['singular'] = $payload['label'];
			$next['taxonomies'][ $key ]['plural'] = $payload['label'];
			$next['taxonomies'][ $key ]['types'] = $payload['types'];
			$next['taxonomies'][ $key ]['hierarchical'] = $hierarchical;
			unset( $term_ids );
		}
		try {
			$next = ContentTypes::validate( $next, false );
		} catch ( \InvalidArgumentException $error ) {
			throw new \DomainException( 'La definizione CMS non è compatibile con il modello WordPress: ' . $error->getMessage(), 0, $error );
		}
		foreach ( $by_kind['term'] as $entity ) {
			$taxonomy = $entity['payload']['taxonomyKey'];
			if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
			$existing = get_term_by( 'slug', $entity['payload']['slug'], $taxonomy );
			if ( $existing instanceof \WP_Term && (string) get_term_meta( $existing->term_id, self::ID_META, true ) !== $entity['id'] && null === self::adoptable_term( $entity ) ) {
				throw new \DomainException( 'Il termine WordPress "' . $entity['payload']['slug'] . '" esiste già nella tassonomia ' . $taxonomy . ' e non appartiene al progetto Histeon.' );
			}
		}
		foreach ( $by_kind['query-preset'] as $entity ) {
			if ( 'post' !== $entity['payload']['typeKey'] && ! isset( $types_by_key[ $entity['payload']['typeKey'] ] ) && ! isset( $definitions['types'][ $entity['payload']['typeKey'] ] ) && ! ( ProductProfile::TYPE === $entity['payload']['typeKey'] && ProductProfile::available() ) ) {
				throw new \DomainException( 'La query "' . $entity['payload']['key'] . '" usa un tipo non presente in WordPress.' );
			}
			self::query_to_wordpress( $entity['payload'] );
		}
		$dev_settings = PageProfileAdapter::dev_payload( Documents::SETTINGS );
		$current_presets = is_array( $dev_settings['presets'] ?? null ) ? $dev_settings['presets'] : array();
		$current_sources = is_array( $dev_settings['sources'] ?? null ) ? $dev_settings['sources'] : array();
	$query_ids = (array) get_option( self::QUERY_IDS, array() );
	$source_ids = (array) get_option( self::SOURCE_IDS, array() );
		$incoming_names = array();
		foreach ( $by_kind['query-preset'] as $entity ) {
			$key = $entity['payload']['key'];
			if ( isset( $incoming_names[ $key ] ) ) { throw new \DomainException( 'Il nome query/sorgente "' . $key . '" è duplicato nel progetto.' ); }
			$incoming_names[ $key ] = 'query';
			if ( isset( $current_sources[ $key ] ) && ( $query_ids[ $key ] ?? null ) !== $entity['id'] ) {
				throw new \DomainException( 'Il nome "' . $key . '" è già usato da una sorgente dati WordPress.' );
			}
		}
		foreach ( $by_kind['query-source'] as $entity ) {
			$name = $entity['payload']['name'];
			if ( isset( $incoming_names[ $name ] ) ) { throw new \DomainException( 'Il nome query/sorgente "' . $name . '" è duplicato nel progetto.' ); }
			$incoming_names[ $name ] = 'source';
			if ( isset( $current_presets[ $name ] ) && ( $source_ids[ $name ] ?? null ) !== $entity['id'] ) {
				throw new \DomainException( 'Il nome "' . $name . '" è già usato da una query WordPress.' );
			}
		}
		$next_presets = $current_presets;
		foreach ( $by_kind['query-preset'] as $entity ) { $next_presets[ $entity['payload']['key'] ] = self::query_to_wordpress( $entity['payload'] ); }
		try { Queries::validate( $next_presets ); }
		catch ( \InvalidArgumentException $error ) { throw new \DomainException( 'Una query CMS non è compatibile con WordPress: ' . $error->getMessage(), 0, $error ); }
		$next_sources = $current_sources;
		foreach ( $by_kind['query-source'] as $entity ) { $next_sources[ $entity['payload']['name'] ] = array( 'type' => 'json', 'data' => $entity['payload']['data'] ); }
		try { Sources::validate( $next_sources, array_keys( $next_presets ) ); }
		catch ( \InvalidArgumentException $error ) { throw new \DomainException( 'Una sorgente CMS non è compatibile con WordPress: ' . $error->getMessage(), 0, $error ); }
		foreach ( $by_kind['content-record'] as $entity ) {
			$payload = $entity['payload'];
			if ( is_string( $payload['title'] ?? null ) && PageProfileAdapter::markup_filtered( $payload['title'] ) ) {
				throw new \DomainException( 'Il titolo del record ' . $entity['id'] . ' contiene markup che WordPress rimuoverebbe. Correggilo prima del trasferimento.' );
			}
			$collisions = get_posts( array( 'post_type' => $payload['typeKey'], 'post_status' => 'any', 'posts_per_page' => 100, 'name' => $payload['slug'], 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $collisions as $collision ) {
				$collision_id = (string) get_post_meta( $collision->ID, self::ID_META, true );
				if ( $collision_id === $entity['id'] ) { continue; }
				$collision_slug = (string) get_post_meta( $collision->ID, self::SLUG_META, true );
				$collision_locale = (string) get_post_meta( $collision->ID, self::LOCALE_META, true );
				if ( PageProfileAdapter::valid_profile_identity( $collision_id ) && $collision_slug === $payload['slug'] && strtolower( $collision_locale ) !== strtolower( $payload['locale'] ) ) { continue; }
				throw new \DomainException( 'Il percorso "' . $payload['typeKey'] . '/' . $payload['slug'] . '" è già occupato da contenuto WordPress. Scegli uno slug libero prima del trasferimento.' );
			}
			foreach ( $payload['values'] as $key => $value ) {
				$field = null;
				foreach ( $types_by_key[ $payload['typeKey'] ]['payload']['fields'] ?? array() as $candidate ) {
					if ( $candidate['key'] === $key ) { $field = $candidate; break; }
				}
				if ( null === $field ) {
					foreach ( $definitions['fields'][ $payload['typeKey'] ] ?? array() as $candidate ) {
						if ( $candidate['name'] === $key ) { $field = self::field_from_wordpress( $candidate ); break; }
					}
				}
				if ( null !== $field ) {
					// Reject lossy conversions in preflight, before types, posts or terms are written.
					// WordPress validates asset and record IDs after their portable IDs are mapped.
					if ( in_array( $field['type'], array( 'asset', 'relation' ), true ) ) { continue; }
					$wp_field = self::field_to_wordpress( $field );
					$clean = ContentTypes::sanitize_value( $wp_field, $value );
					if ( ! self::same_json( $value, $clean ) ) {
						$message = 'rich-text' === $field['type'] ? ' contiene markup o testo che WordPress rimuoverebbe' : ' non è rappresentabile senza perdita nel campo WordPress';
						throw new \DomainException( 'Il campo "' . $key . '" del record "' . $payload['title'] . '"' . $message . '. Correggilo prima del trasferimento.' );
					}
				}
			}
		}
		return array( 'definitions' => $next, 'entities' => $by_kind, 'types' => $types_by_key, 'taxonomies' => $taxonomies_by_key, 'layouts' => $type_layouts, 'presets' => $next_presets, 'sources' => $next_sources );
	}

	/**
	 * The settings the plan brings, for the development copy of `settings` (docs/spec/cms-bridge.md §5): content
	 * types and fields, queries, data sources. Validated again by PageProfileAdapter::validated_settings().
	 *
	 * @param array<string, mixed> $plan
	 * @return array<string, mixed>
	 */
	public static function staged_settings( array $plan ): array {
		$by_kind = $plan['entities'];
		if ( array() === $by_kind ) {
			return array();
		}
		$settings = array();
		if ( array() !== $by_kind['content-type'] || array() !== $by_kind['taxonomy'] ) {
			$settings['content'] = $plan['definitions'];
		}
		if ( array() !== $by_kind['query-preset'] ) {
			$settings['presets'] = $plan['presets'];
		}
		if ( array() !== $by_kind['query-source'] ) {
			$settings['sources'] = $plan['sources'];
		}
		return $settings;
	}

	/**
	 * Remembers which portable ids own which WordPress names (types, taxonomies, queries, sources): metadata only,
	 * so an interrupted import can tell its own names from collisions. Returns the ids staged.
	 *
	 * @param array<string, mixed> $plan
	 * @return list<string>
	 */
	public static function stage_identities( array $plan ): array {
		$by_kind = $plan['entities'];
		if ( array() === $by_kind ) {
			return array();
		}
		$maps = array(
			'content-type' => array( self::TYPE_IDS, 'key' ),
			'taxonomy'     => array( self::TAXONOMY_IDS, 'key' ),
			'native-taxonomy' => array( self::TAXONOMY_IDS, 'key' ),
			'product-type' => array( self::TYPE_IDS, 'key' ),
			'query-preset' => array( self::QUERY_IDS, 'key' ),
			'query-source' => array( self::SOURCE_IDS, 'name' ),
		);
		$ids = array();
		foreach ( $maps as $kind => [ $option, $name ] ) {
			$map = (array) get_option( $option, array() );
			foreach ( $by_kind[ $kind ] as $entity ) {
				$map[ $entity['payload'][ $name ] ] = $entity['id'];
				$ids[]                              = $entity['id'];
			}
			update_option( $option, $map, false );
		}
		$named = get_option( self::NATIVE_LABELS, array() );
		$named = is_array( $named ) ? $named : array();
		foreach ( $by_kind['native-taxonomy'] ?? array() as $entity ) {
			if ( isset( self::NATIVE_TAXONOMY_LABELS[ $entity['payload']['key'] ] ) ) {
				$named[ $entity['payload']['key'] ] = $entity['payload']['label'];
			}
		}
		update_option( self::NATIVE_LABELS, $named, false );
		ProductProfile::remember_labels( $by_kind['product-type'] ?? array(), array_filter( $by_kind['native-taxonomy'] ?? array(), static fn ( array $entity ): bool => in_array( $entity['payload']['key'] ?? null, ProductProfile::TAXONOMIES, true ) ) );
		return $ids;
	}

	/**
	 * After publishing: the Histeon templates assigned to each type (they change how live archives look).
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function publish_layouts( array $plan ): void {
		if ( array() !== ( $plan['entities']['content-type'] ?? array() ) || array() !== ( $plan['entities']['product-type'] ?? array() ) ) {
			update_option( self::TYPE_LAYOUTS, $plan['layouts'] ?? self::dev_layouts(), false );
			$queue = self::queue();
			if ( null !== $queue['layouts'] ) {
				$queue['layouts'] = null;
				self::save_queue( $queue );
			}
		}
	}

	/**
	 * Without publishing, the templates assigned to each type wait in the development queue with the contents
	 * (they change how live archives look).
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function queue_layouts( array $plan ): void {
		if ( array() !== ( $plan['entities']['content-type'] ?? array() ) || array() !== ( $plan['entities']['product-type'] ?? array() ) ) {
			$queue            = self::queue();
			$queue['layouts'] = $plan['layouts'] ?? self::dev_layouts();
			self::save_queue( $queue );
		}
	}

	/** @return array<string, mixed> The type templates as they are in development (queued, otherwise live). */
	private static function dev_layouts(): array {
		return self::queue()['layouts'] ?? (array) get_option( self::TYPE_LAYOUTS, array() );
	}

	/**
	 * Terms and records (docs/spec/cms-bridge.md §5–6). A new one is a draft (published only when publishing and
	 * asked); an existing draft is updated; an existing published one changes only when publishing, otherwise it
	 * waits. A type or taxonomy WordPress does not register yet (its settings are not published) waits too.
	 *
	 * @param array<string, mixed> $plan
	 * @param array<string, int>   $media_ids
	 * @return array{changed: list<string>, pending: list<array{id: string, reason: string}>, records: int, terms: int}
	 */
	public static function apply_contents( array $plan, array $media_ids, bool $publish, string $default_locale = '' ): array {
		$by_kind = $plan['entities'];
		$changed = array();
		$pending = array();
		if ( array() === $by_kind ) {
			return array( 'changed' => array(), 'pending' => array(), 'records' => 0, 'terms' => 0 );
		}
		$default_locale = self::canonical_locale( '' !== $default_locale ? $default_locale : (string) get_option( self::DEFAULT_LOCALE, get_locale() ) );
		if ( '' !== $default_locale ) {
			update_option( self::DEFAULT_LOCALE, $default_locale, false );
		}

		$term_ids = array();
		$waiting  = $by_kind['term'];
		for ( $pass = 0; $waiting && $pass <= count( $by_kind['term'] ); ++$pass ) {
			$remaining = array();
			foreach ( $waiting as $entity ) {
				$payload = $entity['payload'];
				if ( ! taxonomy_exists( $payload['taxonomyKey'] ) ) {
					$pending[] = array( 'id' => $entity['id'], 'reason' => 'taxonomy-not-published' );
					continue;
				}
				$parent = null;
				if ( is_string( $payload['parentId'] ) ) {
					$parent = self::term_by_profile_id( $payload['parentId'], $payload['taxonomyKey'] );
					if ( ! $parent instanceof \WP_Term ) {
						$remaining[] = $entity;
						continue;
					}
				}
				$existing = self::term_by_profile_id( $entity['id'], $payload['taxonomyKey'] ) ?? self::adoptable_term( $entity );
				$args     = array( 'slug' => $payload['slug'], 'parent' => $parent instanceof \WP_Term ? $parent->term_id : 0 );
				if ( $existing instanceof \WP_Term && ! $publish ) {
					// A term has no draft: changing it changes live archives and labels, so it waits for a publish.
					if ( wp_specialchars_decode( $existing->name, ENT_QUOTES ) !== $payload['label'] || $existing->slug !== $payload['slug'] || $existing->parent !== $args['parent'] ) {
						$pending[] = array( 'id' => $entity['id'], 'reason' => 'published-content' );
					}
					$term_ids[ $entity['id'] ] = $existing->term_id;
					continue;
				}
				$result = $existing instanceof \WP_Term ? wp_update_term( $existing->term_id, $payload['taxonomyKey'], array_merge( $args, array( 'name' => $payload['label'] ) ) ) : wp_insert_term( $payload['label'], $payload['taxonomyKey'], $args );
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( sprintf( /* translators: 1: term, 2: error message */ __( 'Termine «%1$s» non salvato: %2$s', 'xb' ), $payload['label'], $result->get_error_message() ) );
				}
				$term_id = (int) ( $result['term_id'] ?? 0 );
				if ( $term_id < 1 ) {
					throw new \RuntimeException( __( 'WordPress non ha restituito l\'identificativo del termine.', 'xb' ) );
				}
				update_term_meta( $term_id, self::ID_META, $entity['id'] );
				update_term_meta( $term_id, self::BASE_META, $entity['revisionHash'] );
				update_term_meta( $term_id, self::TERM_SLUG_META, $payload['slug'] );
				$term_ids[ $entity['id'] ] = $term_id;
				$changed[]                 = $entity['id'];
			}
			$waiting = $remaining;
		}
		if ( $waiting ) {
			throw new \RuntimeException( __( 'La gerarchia dei termini non si può applicare in WordPress.', 'xb' ) );
		}

		// Posts first (relations point to them), then their fields and terms.
		$record_posts = array();
		$write        = array();
		foreach ( $by_kind['content-record'] as $entity ) {
			$payload  = $entity['payload'];
			$existing = self::post_by_profile_id( $entity['id'], $payload['typeKey'] );
			if ( ! post_type_exists( $payload['typeKey'] ) ) {
				$pending[] = array( 'id' => $entity['id'], 'reason' => 'type-not-published' );
				continue;
			}
			if ( $existing instanceof \WP_Post && 'publish' === $existing->post_status && ! $publish ) {
				$pending[]                     = array( 'id' => $entity['id'], 'reason' => 'published-content' );
				$record_posts[ $entity['id'] ] = $existing->ID;
				continue;
			}
			$status  = 'archived' === $payload['status'] ? 'private' : ( $publish && 'published' === $payload['status'] ? 'publish' : 'draft' );
			$postarr = array(
				'post_type'    => $payload['typeKey'],
				'post_name'    => $existing instanceof \WP_Post && ! $publish ? $existing->post_name : self::record_slug( $payload, $entity['id'], $default_locale ),
				'post_title'   => $payload['title'],
				'post_excerpt' => $payload['excerpt'],
				'menu_order'   => $payload['order'],
				'post_status'  => $status,
			);
			if ( is_string( $payload['publishedAt'] ) && 'publish' === $status ) {
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( $payload['publishedAt'] ) );
				$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
			}
			if ( $existing instanceof \WP_Post ) {
				$postarr['ID'] = $existing->ID;
			}
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( sprintf( /* translators: 1: record title, 2: error message */ __( 'Record «%1$s» non salvato: %2$s', 'xb' ), $payload['title'], $post_id->get_error_message() ) );
			}
			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::ID_META, $entity['id'] );
			update_post_meta( $post_id, self::BASE_META, $entity['revisionHash'] );
			update_post_meta( $post_id, self::LOCALE_META, $payload['locale'] );
			update_post_meta( $post_id, self::STATUS_META, 'archived' === $payload['status'] ? 'archived' : '' );
			update_post_meta( $post_id, self::DATE_META, self::stored_date( is_string( $payload['publishedAt'] ) ? $payload['publishedAt'] : '' ) );
			update_post_meta( $post_id, self::SLUG_META, $payload['slug'] );
			$record_posts[ $entity['id'] ] = $post_id;
			$write[]                       = $entity;
		}
		foreach ( $write as $entity ) {
			$payload = $entity['payload'];
			$post_id = $record_posts[ $entity['id'] ];
			$fields  = array_column( ContentTypes::fields_for( $payload['typeKey'] ), null, 'name' );
			$sidecar = array();
			foreach ( $fields as $key => $field ) {
				if ( ! array_key_exists( $key, $payload['values'] ) ) {
					delete_post_meta( $post_id, $key );
					continue;
				}
				$profile_value = $payload['values'][ $key ];
				$native_value  = self::value_to_wordpress( $field, $profile_value, $media_ids, $record_posts );
				$clean         = ContentTypes::sanitize_value( $field, $native_value );
				if ( ! self::same_json( $native_value, $clean ) ) {
					throw new \RuntimeException( sprintf( /* translators: 1: field, 2: record title */ __( 'WordPress modificherebbe il campo «%1$s» del record «%2$s».', 'xb' ), $key, $payload['title'] ) );
				}
				update_post_meta( $post_id, $key, wp_slash( $clean ) );
				$sidecar[ $key ] = array( 'profileValue' => $profile_value, 'nativeHash' => hash( 'sha256', PageProfile::canonical( get_post_meta( $post_id, $key, true ) ) ) );
			}
			update_post_meta( $post_id, self::VALUES_META, wp_slash( $sidecar ) );
			$sent_terms = array();
			foreach ( ContentTypes::live()['taxonomies'] as $taxonomy_key => $taxonomy ) {
				if ( ! in_array( $payload['typeKey'], $taxonomy['types'], true ) ) {
					continue;
				}
				if ( array() !== ( $payload['terms'][ $taxonomy_key ] ?? array() ) ) {
					$sent_terms[ $taxonomy_key ] = array_values( $payload['terms'][ $taxonomy_key ] );
				}
				$mapped = array_map( static fn ( string $id ): int => $term_ids[ $id ] ?? 0, $payload['terms'][ $taxonomy_key ] ?? array() );
				if ( in_array( 0, $mapped, true ) ) {
					throw new \RuntimeException( sprintf( /* translators: %s: record title */ __( 'WordPress non ha trovato tutti i termini del record «%s».', 'xb' ), $payload['title'] ) );
				}
				$result = wp_set_object_terms( $post_id, $mapped, $taxonomy_key, false );
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( sprintf( /* translators: 1: taxonomy, 2: error message */ __( 'Tassonomia «%1$s» non assegnata: %2$s', 'xb' ), $taxonomy_key, $result->get_error_message() ) );
				}
			}
			if ( array() === $sent_terms ) {
				delete_post_meta( $post_id, self::TERMS_META );
			} else {
				update_post_meta( $post_id, self::TERMS_META, $sent_terms );
			}
			if ( ProductProfile::TYPE === $payload['typeKey'] ) {
				// Catalogue data through WooCommerce (queue C6): never orders, customers or stock movements.
				ProductProfile::write( $post_id, $payload, $media_ids, $term_ids );
			}
			$changed[] = $entity['id'];
		}
		return array( 'changed' => $changed, 'pending' => $pending, 'records' => count( $write ), 'terms' => count( $term_ids ) );
	}

	/**
	 * Without a publish, records and terms are part of development: they wait in a queue, the export shows them as
	 * development shows them, and the next Histeon release applies them (docs/spec/cms-bridge.md §5).
	 *
	 * @param array<string, mixed> $plan
	 * @param array<string, int>   $media_ids
	 * @return list<string> The ids queued.
	 */
	public static function queue_contents( array $plan, array $media_ids, string $default_locale ): array {
		$by_kind = $plan['entities'];
		if ( array() === $by_kind ) {
			return array();
		}
		$queue = self::queue();
		$live  = self::live_hashes();
		$ids   = array();
		foreach ( array_merge( $by_kind['term'], $by_kind['content-record'] ) as $entity ) {
			$queue['entities'][ $entity['id'] ] = $entity;
			// What it replaces on the live site, to notice a WordPress edit made before the release.
			if ( isset( $live[ $entity['id'] ] ) ) {
				$queue['live'][ $entity['id'] ] = $live[ $entity['id'] ];
			} else {
				unset( $queue['live'][ $entity['id'] ] );
			}
			$ids[] = $entity['id'];
		}
		$queue['media']  = array_merge( $queue['media'], $media_ids );
		$queue['locale'] = '' !== $default_locale ? $default_locale : $queue['locale'];
		self::save_queue( $queue );
		return $ids;
	}

	/** @return array{entities: array<string, array<string, mixed>>, live: array<string, string>, media: array<string, int>, locale: string, layouts: array<string, mixed>|null} */
	public static function queue(): array {
		$queue = get_option( self::QUEUE, array() );
		return array(
			'entities' => is_array( $queue['entities'] ?? null ) ? $queue['entities'] : array(),
			'live'     => is_array( $queue['live'] ?? null ) ? $queue['live'] : array(),
			'media'    => is_array( $queue['media'] ?? null ) ? array_map( 'intval', $queue['media'] ) : array(),
			'locale'   => is_string( $queue['locale'] ?? null ) ? $queue['locale'] : '',
			'layouts'  => is_array( $queue['layouts'] ?? null ) ? $queue['layouts'] : null,
		);
	}

	/** Records, terms and products queued for the next release (filter `xb_release_waiting_contents`). */
	public static function queued_count(): int {
		return count( self::queue()['entities'] );
	}

	/** @param array{entities: array<string, array<string, mixed>>, live: array<string, string>, media: array<string, int>, locale: string, layouts: array<string, mixed>|null} $queue */
	private static function save_queue( array $queue ): void {
		if ( array() === $queue['entities'] && null === $queue['layouts'] ) {
			delete_option( self::QUEUE );
			return;
		}
		update_option( self::QUEUE, $queue, false );
	}

	/**
	 * Takes entities out of the queue (applied by a publish, or replaced by a newer send).
	 *
	 * @param list<string> $ids
	 */
	public static function dequeue( array $ids ): void {
		$queue = self::queue();
		foreach ( $ids as $id ) {
			unset( $queue['entities'][ $id ], $queue['live'][ $id ] );
		}
		self::save_queue( $queue );
	}

	/**
	 * A Histeon release went online (xb_release_published): the queued records and terms follow it. What still
	 * cannot be applied (its type is not registered yet) stays queued; an error is logged and keeps the queue,
	 * never fails the release.
	 *
	 * @param list<string> $docs
	 */
	public static function apply_queue( int $release_id, array $docs = array() ): void {
		$queue = self::queue();
		if ( null !== $queue['layouts'] ) {
			update_option( self::TYPE_LAYOUTS, $queue['layouts'], false );
			$queue['layouts'] = null;
			self::save_queue( $queue );
		}
		if ( array() === $queue['entities'] ) {
			return;
		}
		ContentTypes::forget();
		ContentTypes::register();
		try {
			// Changed in WordPress after the send: that edit wins, the CMS sees it at its next download.
			$live  = self::live_hashes();
			$stale = array_keys( array_filter( $queue['entities'], static fn ( array $entity ): bool => ( $queue['live'][ $entity['id'] ] ?? null ) !== ( $live[ $entity['id'] ] ?? null ) ) );
			if ( array() !== $stale ) {
				self::dequeue( $stale );
				\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_stale', 'release:' . $release_id, array( 'dropped' => count( $stale ) ) );
				$queue = self::queue();
				if ( array() === $queue['entities'] ) {
					return;
				}
			}
			$plan   = self::preflight( array_values( $queue['entities'] ) );
			$result = self::apply_contents( $plan, $queue['media'], true, $queue['locale'] );
			self::dequeue( $result['changed'] );
			\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_applied', 'release:' . $release_id, array( 'applied' => count( $result['changed'] ), 'waiting' => count( $result['pending'] ) ) );
		} catch ( \Throwable $e ) {
			\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_failed', 'release:' . $release_id, array( 'error' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
	}

	/**
	 * The WordPress slug of a record: its portable slug in the project's default language, with the language as a
	 * suffix otherwise (`nome-en-us`), so two languages never fight for one address.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function record_slug( array $payload, string $id, string $default_locale ): string {
		$slug = (string) $payload['slug'];
		if ( '' !== $default_locale && strtolower( self::canonical_locale( (string) $payload['locale'] ) ) !== strtolower( $default_locale ) ) {
			$slug .= '-' . strtolower( self::canonical_locale( (string) $payload['locale'] ) );
		}
		return self::available_slug( (string) $payload['typeKey'], sanitize_title( $slug ), $id );
	}

	/**
	 * Content types, taxonomies and fields as in the development copy of `settings`.
	 *
	 * @return array{types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>, fields: array<string, list<array<string, mixed>>>}
	 */
	private static function dev_definitions(): array {
		$content = PageProfileAdapter::dev_payload( Documents::SETTINGS )['content'] ?? null;
		return ContentTypes::validate( is_array( $content ) ? $content : array(), false );
	}

	/** Build relational CMS entities and any record-only media for the WordPress snapshot. */
	/**
	 * @param array<string, string> $files
	 * @return list<array<string, mixed>>
	 */
	public static function snapshot( bool $ensure, array &$files, bool $with_queue = true, bool $with_posts = false ): array {
		/** @var list<array<string, mixed>> $entities */
		$entities = array();
		$definitions = self::dev_definitions();
		$type_ids = (array) get_option( self::TYPE_IDS, array() );
		$taxonomy_ids = (array) get_option( self::TAXONOMY_IDS, array() );
		$type_layouts = self::dev_layouts();
		foreach ( $definitions['types'] as $key => $type ) {
			$id = self::mapped_id( $type_ids, 'type:' . $key, $key, $ensure );
			if ( '' === $id ) { continue; }
			$fields = array_map( static fn ( array $field ): array => self::field_from_wordpress( $field ), $definitions['fields'][ $key ] ?? array() );
			$payload = array( 'key' => $key, 'label' => $type['singular'], 'fields' => $fields );
			$layouts = is_array( $type_layouts[ $key ] ?? null ) ? $type_layouts[ $key ] : array();
			if ( empty( $type['public'] ) ) {
				// Doc 23 item 122: a type without pages of its own travels as such, and without templates.
				$payload['public'] = false;
				$layouts           = array();
			}
			foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $layout_key ) {
				if ( is_string( $layouts[ $layout_key ] ?? null ) ) { $payload[ $layout_key ] = $layouts[ $layout_key ]; }
			}
			$entities[] = self::entity( $id, 'content-type', $payload, self::base_for( $id ) );
		}
		// The shop's products (queue C6): once the project sent its product type, with WooCommerce active.
		$product_type_id = ProductProfile::available() && PageProfileAdapter::valid_profile_identity( $type_ids[ ProductProfile::TYPE ] ?? null ) ? (string) $type_ids[ ProductProfile::TYPE ] : '';
		$product_labels  = ProductProfile::labels();
		if ( '' !== $product_type_id ) {
			$payload = array( 'key' => ProductProfile::TYPE, 'label' => $product_labels[ ProductProfile::TYPE ], 'fields' => ProductProfile::fields() );
			$layouts = is_array( $type_layouts[ ProductProfile::TYPE ] ?? null ) ? $type_layouts[ ProductProfile::TYPE ] : array();
			foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $layout_key ) {
				if ( is_string( $layouts[ $layout_key ] ?? null ) ) { $payload[ $layout_key ] = $layouts[ $layout_key ]; }
			}
			$entities[] = self::entity( $product_type_id, 'content-type', $payload, self::base_for( $product_type_id ) );
		}
		$taxonomy_rows = array();
		foreach ( $definitions['taxonomies'] as $key => $taxonomy ) {
			$taxonomy_rows[ $key ] = array( 'label' => $taxonomy['singular'], 'types' => $taxonomy['types'] );
		}
		// With products, WooCommerce's product categories and tags travel too, with all their terms, like posts' (§12).
		if ( '' !== $product_type_id ) {
			foreach ( ProductProfile::TAXONOMIES as $key ) {
				$taxonomy_rows[ $key ] = array( 'label' => $product_labels[ $key ], 'types' => array( ProductProfile::TYPE ) );
			}
		}
		// With posts in the profile, WordPress's categories and tags travel too, with all their terms (§12).
		if ( $with_posts ) {
			$named = get_option( self::NATIVE_LABELS, array() );
			foreach ( self::NATIVE_TAXONOMY_LABELS as $key => $label ) {
				$taxonomy_rows[ $key ] = array( 'label' => is_array( $named ) && is_string( $named[ $key ] ?? null ) ? $named[ $key ] : $label, 'types' => array( 'post' ) );
			}
		}
		foreach ( $taxonomy_rows as $key => $taxonomy ) {
			$id = self::mapped_id( $taxonomy_ids, 'taxonomy:' . $key, $key, $ensure );
			if ( '' === $id ) { continue; }
			$entities[] = self::entity( $id, 'taxonomy', array( 'key' => $key, 'label' => $taxonomy['label'], 'types' => $taxonomy['types'] ), self::base_for( $id ) );
			// A taxonomy only in development is not registered yet: it has no terms on the site.
			$terms = taxonomy_exists( $key ) ? get_terms( array( 'taxonomy' => $key, 'hide_empty' => false, 'number' => 10000 ) + ( in_array( $key, ProductProfile::TAXONOMIES, true ) ? array( 'orderby' => 'term_id' ) : array() ) ) : array();
			if ( is_wp_error( $terms ) ) { throw new \DomainException( 'Impossibile leggere i termini della tassonomia ' . $key . ': ' . $terms->get_error_message() ); }
			foreach ( $terms as $term ) {
				// WooCommerce's default product category exists on every shop: it is the project's only once sent.
				if ( 'product_cat' === $key && (int) $term->term_id === (int) get_option( 'default_product_cat', 0 ) && ! PageProfileAdapter::valid_profile_identity( get_term_meta( $term->term_id, self::ID_META, true ) ) ) { continue; }
				$term_id = self::term_id( $term, $ensure );
				if ( '' === $term_id ) { continue; }
				$parent = $term->parent ? get_term( $term->parent, $key ) : null;
				$parent_id = $parent instanceof \WP_Term ? self::term_id( $parent, $ensure ) : null;
				$portable_slug = (string) get_term_meta( $term->term_id, self::TERM_SLUG_META, true );
				if ( '' === $portable_slug || $term->slug !== $portable_slug ) { $portable_slug = $term->slug; }
				// WordPress keeps a name with its & as &amp;: the same name.
				$payload = array( 'taxonomyKey' => $key, 'slug' => $portable_slug, 'label' => wp_specialchars_decode( $term->name, ENT_QUOTES ), 'parentId' => $parent_id );
				$entities[] = self::entity( $term_id, 'term', $payload, (string) get_term_meta( $term->term_id, self::BASE_META, true ) );
			}
		}
		$records_by_id = array();
		foreach ( $definitions['types'] as $key => $_type ) {
			$posts = get_posts( array( 'post_type' => $key, 'post_status' => array( 'draft', 'publish', 'private' ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $posts as $post ) {
				$id = self::post_id( $post, $ensure );
				if ( '' !== $id ) { $records_by_id[ $id ] = $post; }
			}
		}
		$products = array();
		if ( '' !== $product_type_id ) {
			$posts = get_posts( array( 'post_type' => ProductProfile::TYPE, 'post_status' => array( 'draft', 'publish', 'private' ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $posts as $post ) {
				$owned = PageProfileAdapter::valid_profile_identity( get_post_meta( $post->ID, self::ID_META, true ) );
				if ( ! $owned && ! $ensure ) { continue; }
				// Only what the profile can carry: a simple product with data the transfer keeps (ProductProfile::export).
				$data = ProductProfile::export( $post, $owned, $files, $ensure, $entities, static fn ( \WP_Term $term ): string => self::term_id( $term, $ensure ) );
				if ( null === $data ) { continue; }
				$id = self::post_id( $post, $ensure );
				if ( '' !== $id ) { $products[ $id ] = array( $post, $data ); }
			}
		}
		if ( $ensure ) {
			PageProfileAdapter::later(
				static function () use ( $type_ids, $taxonomy_ids ): void {
					update_option( self::TYPE_IDS, $type_ids, false );
					update_option( self::TAXONOMY_IDS, $taxonomy_ids, false );
				}
			);
		}
		foreach ( $records_by_id as $id => $post ) {
			$fields = $definitions['fields'][ $post->post_type ] ?? array();
			$sidecar = (array) get_post_meta( $post->ID, self::VALUES_META, true );
			$values = array();
			foreach ( $fields as $field ) {
				$key = $field['name'];
				$native = get_post_meta( $post->ID, $key, true );
				if ( isset( $sidecar[ $key ]['profileValue'], $sidecar[ $key ]['nativeHash'] ) && hash_equals( (string) $sidecar[ $key ]['nativeHash'], hash( 'sha256', PageProfile::canonical( $native ) ) ) ) {
					$values[ $key ] = $sidecar[ $key ]['profileValue'];
					self::include_value_assets( $field, $native, $files, $ensure, $entities );
					continue;
				}
				if ( metadata_exists( 'post', $post->ID, $key ) ) {
					$values[ $key ] = self::value_from_wordpress( $field, $native, $files, $ensure, $entities );
				}
			}
			$terms = array();
			$sent_terms = get_post_meta( $post->ID, self::TERMS_META, true );
			$sent_terms = is_array( $sent_terms ) ? $sent_terms : array();
			foreach ( $definitions['taxonomies'] as $tax_key => $taxonomy ) {
				if ( ! in_array( $post->post_type, $taxonomy['types'], true ) ) { continue; }
				$assigned = wp_get_object_terms( $post->ID, $tax_key );
				if ( is_wp_error( $assigned ) ) { throw new \DomainException( 'Impossibile leggere i termini del record WordPress.' ); }
				$ids = array();
				foreach ( $assigned as $term ) {
					$term_id = self::term_id( $term, $ensure );
					if ( '' !== $term_id ) { $ids[] = $term_id; }
				}
				if ( $ids ) { $terms[ $tax_key ] = self::sent_order( array_values( array_unique( $ids ) ), $sent_terms[ $tax_key ] ?? null ); }
			}
			$entities[] = self::entity( $id, 'content-record', self::record_payload( $post, $id, $values, $terms ), (string) get_post_meta( $post->ID, self::BASE_META, true ) );
		}
		foreach ( $products as $id => [ $post, $data ] ) {
			$entities[] = self::entity( (string) $id, 'content-record', self::record_payload( $post, (string) $id, $data['values'], $data['terms'] ), (string) get_post_meta( $post->ID, self::BASE_META, true ) );
		}
		$dev_settings = PageProfileAdapter::dev_payload( Documents::SETTINGS );
		$query_ids    = (array) get_option( self::QUERY_IDS, array() );
		$source_ids   = (array) get_option( self::SOURCE_IDS, array() );
		$presets_out = self::presets_left_out();
		foreach ( (array) ( $dev_settings['presets'] ?? array() ) as $key => $preset ) {
			if ( ! is_array( $preset ) || 'posts' !== ( $preset['type'] ?? null ) || ! is_array( $preset['args'] ?? null ) ) { continue; }
			$args = $preset['args'];
			if ( ! is_string( $args['xb_type_key'] ?? $args['post_type'] ?? null ) || isset( $presets_out[ $key ] ) ) { continue; }
			$payload = self::query_from_wordpress( (string) $key, $args );
			$id = self::mapped_id( $query_ids, 'query:' . $key, (string) $key, $ensure );
			if ( '' === $id ) { continue; } // Not linked: the site's own, none of a send's business.
			$entities[] = self::entity( $id, 'query-preset', $payload, self::base_for( $id ) );
		}
		foreach ( (array) ( $dev_settings['sources'] ?? array() ) as $name => $source ) {
			if ( ! is_array( $source ) || 'json' !== ( $source['type'] ?? 'json' ) || ! array_key_exists( 'data', $source ) ) { continue; }
			$id = self::mapped_id( $source_ids, 'source:' . $name, (string) $name, $ensure );
			if ( '' === $id ) { continue; }
			$entities[] = self::entity( $id, 'query-source', array( 'name' => (string) $name, 'data' => $source['data'] ), self::base_for( $id ) );
		}
		if ( $ensure ) {
			PageProfileAdapter::later(
				static function () use ( $query_ids, $source_ids ): void {
					update_option( self::QUERY_IDS, $query_ids, false );
					update_option( self::SOURCE_IDS, $source_ids, false );
				}
			);
		}
		// Queued records and terms are part of development: they replace the live ones (or join them), unless
		// WordPress changed the live one after the send. Then the live one shows, and the CMS sees a conflict.
		$queue = $with_queue ? self::queue() : null;
		if ( null !== $queue && array() !== $queue['entities'] ) {
			$live  = array_column( $entities, 'revisionHash', 'id' );
			$fresh = array_filter(
				$queue['entities'],
				static fn ( array $entity ): bool => ( $queue['live'][ $entity['id'] ] ?? null ) === ( $live[ $entity['id'] ] ?? null )
					// Its type or taxonomy must still exist in development.
					&& ( 'term' === $entity['kind'] ? isset( $taxonomy_rows[ $entity['payload']['taxonomyKey'] ?? '' ] ) : isset( $definitions['types'][ $entity['payload']['typeKey'] ?? '' ] ) || ( ProductProfile::TYPE === ( $entity['payload']['typeKey'] ?? '' ) && '' !== $product_type_id ) )
			);
			$entities = array_values( array_filter( $entities, static fn ( array $entity ): bool => ! isset( $fresh[ $entity['id'] ] ) ) );
			foreach ( $fresh as $entity ) {
				$entities[] = self::entity( (string) $entity['id'], (string) $entity['kind'], (array) $entity['payload'], (string) $entity['revisionHash'] );
			}
		}
		return $entities;
	}

	/**
	 * The date Publish sent, as stored in the record's meta. On SQLite the SQLite Database Integration driver rewrites
	 * every string literal shaped like «2026-10-23T10:00:00Z» into «2026-10-23 10:00:00» (its translate_datetime_literal),
	 * so the record would come back with another publishedAt and another revision hash: there the date is kept as a JSON
	 * string, which the driver leaves alone. On MySQL the meta stays the plain date, as before.
	 */
	private static function stored_date( string $date ): string {
		return '' !== $date && \XB\Versioning\Store::sqlite() ? (string) wp_json_encode( $date ) : $date;
	}

	/** The date as Publish sent it, from the record's meta (plain, or a JSON string on SQLite: stored_date). */
	private static function sent_date( string $stored ): string {
		if ( str_starts_with( $stored, '"' ) ) {
			$date = json_decode( $stored, true );
			return is_string( $date ) ? $date : '';
		}
		return $stored;
	}

	/**
	 * A record's payload from its post, with its values and terms.
	 *
	 * @param array<string, mixed>        $values
	 * @param array<string, list<string>> $terms
	 * @return array<string, mixed>
	 */
	private static function record_payload( \WP_Post $post, string $id, array $values, array $terms ): array {
		$status_meta = (string) get_post_meta( $post->ID, self::STATUS_META, true );
		$status = 'publish' === $post->post_status ? 'published' : ( 'private' === $post->post_status && 'archived' === $status_meta ? 'archived' : 'draft' );
		$published_at = null;
		if ( 'published' === $status ) {
			$stored_date = self::sent_date( (string) get_post_meta( $post->ID, self::DATE_META, true ) );
			$stored_stamp = '' !== $stored_date ? strtotime( $stored_date ) : false;
			$post_stamp = strtotime( (string) $post->post_date_gmt . ' UTC' );
			$published_at = false !== $stored_stamp && $stored_stamp === $post_stamp ? $stored_date : gmdate( 'Y-m-d\TH:i:s\Z', $post_stamp ?: time() );
		}
		$slug_meta = (string) get_post_meta( $post->ID, self::SLUG_META, true );
		$slug = '' !== $slug_meta && self::native_slug_for_profile( $post, $slug_meta, $id, (string) get_post_meta( $post->ID, self::LOCALE_META, true ) ) ? $slug_meta : $post->post_name;
		$payload = array(
			'typeKey' => $post->post_type, 'slug' => $slug,
			'locale' => self::canonical_locale( (string) get_post_meta( $post->ID, self::LOCALE_META, true ) ) ?: self::canonical_locale( (string) get_option( self::DEFAULT_LOCALE, get_locale() ) ),
			'title' => $post->post_title, 'status' => $status, 'publishedAt' => $published_at,
			'excerpt' => $post->post_excerpt, 'order' => (int) $post->menu_order, 'values' => $values, 'terms' => $terms,
		);
		return $payload;
	}

	/** @return array<string, string> Portable id → revision hash of the live records and terms (no queue). */
	private static function live_hashes(): array {
		$files    = array();
		$entities = self::snapshot( false, $files, false, true );
		$hashes   = array();
		foreach ( $entities as $entity ) {
			if ( in_array( $entity['kind'], array( 'content-record', 'term' ), true ) ) {
				$hashes[ (string) $entity['id'] ] = (string) $entity['revisionHash'];
			}
		}
		return $hashes;
	}

	/** Return true when any surviving Histeon record still relates to the given portable record ID. */
	/**
	 * @param list<array<string, mixed>> $entities
	 * @param list<string>               $deleted_owner_ids
	 */
	public static function has_incoming_record_reference( string $record_id, array $entities, array $deleted_owner_ids = array() ): bool {
		$fields_by_type = array();
		foreach ( $entities as $entity ) {
			if ( 'content-type' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['key'] ?? null ) && is_array( $entity['payload']['fields'] ?? null ) ) {
				$fields_by_type[ $entity['payload']['key'] ] = $entity['payload']['fields'];
			}
		}
		foreach ( $entities as $entity ) {
			if ( 'content-record' !== ( $entity['kind'] ?? null ) || ( $entity['id'] ?? null ) === $record_id || in_array( $entity['id'] ?? null, $deleted_owner_ids, true ) ) { continue; }
			$type = $entity['payload']['typeKey'] ?? null;
			$values = $entity['payload']['values'] ?? array();
			if ( ! is_string( $type ) || ! is_array( $values ) ) { continue; }
			foreach ( $fields_by_type[ $type ] ?? array() as $field ) {
				if ( 'relation' !== ( $field['type'] ?? null ) || ! is_string( $field['key'] ?? null ) || ! array_key_exists( $field['key'], $values ) ) { continue; }
				$value = $values[ $field['key'] ];
				$targets = is_array( $value ) ? $value : array( $value );
				if ( in_array( $record_id, $targets, true ) ) { return true; }
			}
		}
		return false;
	}

	/** Find a linked WordPress post for a known Histeon relational record. */
	public static function linked_record_by_id( string $id, string $type ): ?\WP_Post {
		if ( ! isset( ContentTypes::live()['types'][ $type ] ) && ! ( ProductProfile::TYPE === $type && ProductProfile::available() ) ) { return null; }
		return self::post_by_profile_id( $id, $type );
	}

	/** Persist the common sync base after a confirmed import/export. */
	/** @param array<string, mixed> $entity */
	public static function mark_base( array $entity ): void {
		$id = (string) ( $entity['id'] ?? '' );
		$hash = (string) ( $entity['revisionHash'] ?? '' );
		if ( '' === $id || 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) { return; }
		if ( in_array( $entity['kind'] ?? null, array( 'content-record' ), true ) ) {
			$post = self::post_by_profile_id( $id, (string) ( $entity['payload']['typeKey'] ?? '' ) );
			if ( $post instanceof \WP_Post ) { update_post_meta( $post->ID, self::BASE_META, $hash ); }
		} elseif ( 'term' === ( $entity['kind'] ?? null ) ) {
			$term = self::term_by_profile_id( $id, (string) ( $entity['payload']['taxonomyKey'] ?? '' ) );
			if ( $term instanceof \WP_Term ) { update_term_meta( $term->term_id, self::BASE_META, $hash ); }
		} else {
			$bases = (array) get_option( self::BASES, array() );
			$bases[ $id ] = $hash;
			update_option( self::BASES, $bases, false );
		}
	}

	/** Keep the first downloaded snapshot as the common base; subsequent downloads must not erase divergence. */
	/** @param array<string, mixed> $entity */
	public static function initialize_export_base( array $entity ): void {
		$id = (string) ( $entity['id'] ?? '' );
		if ( '' === $id ) { return; }
		if ( 'content-record' === ( $entity['kind'] ?? null ) ) {
			$post = self::post_by_profile_id( $id, (string) ( $entity['payload']['typeKey'] ?? '' ) );
			if ( $post instanceof \WP_Post && ! self::valid_hash( get_post_meta( $post->ID, self::BASE_META, true ) ) ) { update_post_meta( $post->ID, self::BASE_META, $entity['revisionHash'] ); }
		} elseif ( 'term' === ( $entity['kind'] ?? null ) ) {
			$term = self::term_by_profile_id( $id, (string) ( $entity['payload']['taxonomyKey'] ?? '' ) );
			if ( $term instanceof \WP_Term && ! self::valid_hash( get_term_meta( $term->term_id, self::BASE_META, true ) ) ) { update_term_meta( $term->term_id, self::BASE_META, $entity['revisionHash'] ); }
		} else {
			$bases = (array) get_option( self::BASES, array() );
			if ( ! self::valid_hash( $bases[ $id ] ?? null ) ) { $bases[ $id ] = $entity['revisionHash']; update_option( self::BASES, $bases, false ); }
		}
	}

	/**
	 * @param array{key: string, label: string, type: string, required: bool, public: bool, multiple: bool, options?: list<string>, targetType?: string} $field
	 * @return FieldDef
	 */
	private static function field_to_wordpress( array $field ): array {
		$types = array( 'text' => 'text', 'rich-text' => 'rich-text', 'integer' => 'integer', 'decimal' => 'decimal', 'boolean' => 'toggle', 'date' => 'date', 'datetime' => 'datetime', 'enum' => 'select', 'url' => 'url', 'asset' => 'image', 'relation' => 'post' );
		$type = $types[ $field['type'] ] ?? null;
		if ( ! is_string( $type ) ) { throw new \DomainException( 'Il tipo di campo CMS non è supportato da WordPress.' ); }
		$out = array(
			'name' => $field['key'],
			'label' => $field['label'],
			'type' => $type,
			'help' => '',
			'options' => array(),
			'postType' => '',
			'sub' => array(),
			'required' => $field['required'],
			'public' => $field['public'],
			'multiple' => $field['multiple'],
		);
		if ( 'enum' === $field['type'] ) { $out['options'] = array_map( static fn ( string $value ): array => array( 'value' => $value, 'label' => $value ), $field['options'] ?? array() ); }
		if ( 'relation' === $field['type'] ) { $out['postType'] = $field['targetType'] ?? ''; }
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field_from_wordpress( array $field ): array {
		$types = array( 'text' => 'text', 'textarea' => 'rich-text', 'rich-text' => 'rich-text', 'number' => 'decimal', 'integer' => 'integer', 'decimal' => 'decimal', 'toggle' => 'boolean', 'date' => 'date', 'datetime' => 'datetime', 'select' => 'enum', 'url' => 'url', 'image' => 'asset', 'post' => 'relation' );
		$type = $types[ $field['type'] ] ?? null;
		if ( null === $type ) {
			throw new \DomainException( 'Il campo WordPress "' . (string) ( $field['name'] ?? '' ) . '" usa il tipo non portabile "' . (string) ( $field['type'] ?? '' ) . '".' );
		}
		$out = array( 'key' => $field['name'], 'label' => $field['label'], 'type' => $type, 'required' => (bool) ( $field['required'] ?? false ), 'public' => (bool) ( $field['public'] ?? true ), 'multiple' => (bool) ( $field['multiple'] ?? false ) );
		if ( 'enum' === $type ) { $out['options'] = array_column( $field['options'], 'value' ); }
		if ( 'relation' === $type ) { $out['targetType'] = $field['postType']; }
		return $out;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function query_to_wordpress( array $payload ): array {
		$order_by = array( 'order' => 'menu_order', 'publishedAt' => 'date', 'title' => 'title', 'slug' => 'name', 'createdAt' => 'date', 'updatedAt' => 'modified' )[ $payload['orderBy'] ] ?? 'menu_order';
		// A Publish loop may pass `limit` and `offset` to any query (base arguments, ProfileValidator): the preset reads them.
		$limit = '$' . ( $payload['limitParam'] ?? 'limit' ) . ' ?? ' . $payload['limit'];
		$args = array( 'post_type' => $payload['typeKey'], 'xb_type_key' => $payload['typeKey'], 'posts_per_page' => $limit, 'xb_limit' => $limit, 'orderby' => $order_by, 'order' => strtoupper( $payload['order'] ), 'xb_label' => $payload['label'] );
		$args['offset'] = '$' . ( $payload['offsetParam'] ?? 'offset' ) . ' ?? ' . ( $payload['offset'] ?? 0 );
		// Doc 23 item 232: a field orders through filter_posts_orderby; menu_order stays as WordPress's fallback.
		if ( ! in_array( $payload['orderBy'], PageProfile::QUERY_ORDERS, true ) ) { $args['xb_order_field'] = $payload['orderBy']; }
		if ( isset( $payload['filter'] ) ) { $args['xb_filter'] = $payload['filter']; }
		return array( 'type' => 'posts', 'args' => $args );
	}

	/**
	 * The site's queries on a type the project does not carry (a post type of a theme, say): the project could not
	 * resolve them, so they stay out of a reading, named by it.
	 *
	 * @return array<string, string> Query key => its type.
	 */
	public static function presets_left_out(): array {
		$settings = PageProfileAdapter::dev_payload( Documents::SETTINGS );
		$types    = self::dev_definitions()['types'];
		$out      = array();
		foreach ( (array) ( $settings['presets'] ?? array() ) as $key => $preset ) {
			$args = is_array( $preset ) && 'posts' === ( $preset['type'] ?? null ) && is_array( $preset['args'] ?? null ) ? $preset['args'] : array();
			$type = $args['xb_type_key'] ?? $args['post_type'] ?? null;
			if ( is_string( $type ) && 'post' !== $type && ProductProfile::TYPE !== $type && ! isset( $types[ $type ] ) ) {
				$out[ (string) $key ] = $type;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function query_from_wordpress( string $key, array $args ): array {
		$type = (string) ( $args['xb_type_key'] ?? $args['post_type'] );
		$raw_limit = $args['xb_limit'] ?? $args['posts_per_page'] ?? 10;
		$limit = 10;
		$param = null;
		if ( is_int( $raw_limit ) || is_float( $raw_limit ) ) { $limit = (int) $raw_limit; }
		elseif ( is_string( $raw_limit ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]{0,63})(?:\s*\?\?\s*(\d{1,3}))?$/', trim( $raw_limit ), $match ) ) { $param = $match[1]; $limit = isset( $match[2] ) ? (int) $match[2] : 10; }
		elseif ( is_numeric( $raw_limit ) ) { $limit = (int) $raw_limit; }
		$orderby = array( 'menu_order' => 'order', 'date' => 'publishedAt', 'title' => 'title', 'name' => 'slug', 'modified' => 'updatedAt' )[ (string) ( $args['orderby'] ?? 'menu_order' ) ] ?? 'order';
		if ( is_string( $args['xb_order_field'] ?? null ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,62}$/D', $args['xb_order_field'] ) && ! in_array( $args['xb_order_field'], PageProfile::QUERY_ORDERS, true ) ) { $orderby = $args['xb_order_field']; }
		$payload = array( 'key' => $key, 'label' => (string) ( $args['xb_label'] ?? $key ), 'typeKey' => $type, 'limit' => max( 1, min( 100, $limit ) ), 'orderBy' => $orderby, 'order' => strtolower( (string) ( $args['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc' );
		if ( null !== $param && 'limit' !== $param ) { $payload['limitParam'] = $param; }
		$raw_offset = $args['offset'] ?? 0;
		$offset = 0;
		$offset_param = null;
		if ( is_int( $raw_offset ) || is_float( $raw_offset ) ) { $offset = (int) $raw_offset; }
		elseif ( is_string( $raw_offset ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]{0,63})(?:\s*\?\?\s*(\d{1,7}))?$/', trim( $raw_offset ), $offset_match ) ) { $offset_param = $offset_match[1]; $offset = isset( $offset_match[2] ) ? (int) $offset_match[2] : 0; }
		elseif ( is_numeric( $raw_offset ) ) { $offset = (int) $raw_offset; }
		if ( $offset >= 0 && $offset <= 1000000 ) { $payload['offset'] = $offset; }
		if ( null !== $offset_param && 'offset' !== $offset_param ) { $payload['offsetParam'] = $offset_param; }
		if ( isset( $args['xb_filter'] ) && is_array( $args['xb_filter'] ) ) { $payload['filter'] = $args['xb_filter']; }
		return $payload;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, int> $media_ids
	 * @param array<string, int> $record_posts
	 */
	private static function value_to_wordpress( array $field, mixed $value, array $media_ids, array $record_posts ): mixed {
		$map = static function ( mixed $item ) use ( $field, $media_ids, $record_posts ): mixed {
			if ( null === $item || '' === $item ) { return $item; }
			if ( 'image' === $field['type'] ) { return is_string( $item ) ? ( $media_ids[ $item ] ?? 0 ) : 0; }
			if ( 'post' === $field['type'] ) { return is_string( $item ) ? ( $record_posts[ $item ] ?? 0 ) : 0; }
			return $item;
		};
		return ! empty( $field['multiple'] ) ? ( is_array( $value ) ? array_map( $map, $value ) : array() ) : $map( $value );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, string> $files
	 * @param list<array<string, mixed>> $entities
	 */
	private static function value_from_wordpress( array $field, mixed $value, array &$files, bool $ensure, array &$entities ): mixed {
		$map = static function ( mixed $item ) use ( $field, &$files, $ensure, &$entities ): mixed {
			if ( null === $item || '' === $item ) { return $item; }
			if ( 'image' === $field['type'] ) {
				$post = get_post( (int) $item );
				if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) { throw new \DomainException( 'Un campo immagine CMS punta a un allegato assente.' ); }
				$entity = PageProfileAdapter::transfer_asset_entity( $post, $files, $ensure );
				self::append_asset_entity( $entities, $entity );
				return $entity['id'];
			}
			if ( 'post' === $field['type'] ) {
				$post = get_post( (int) $item );
				if ( ! $post instanceof \WP_Post ) { throw new \DomainException( 'Un campo relazione CMS punta a un record WordPress assente.' ); }
				$id = (string) get_post_meta( $post->ID, self::ID_META, true );
				if ( ! PageProfileAdapter::valid_profile_identity( $id ) ) { throw new \DomainException( 'Un campo relazione CMS punta a un record non collegato al progetto Histeon.' ); }
				return $id;
			}
			return $item;
		};
		return ! empty( $field['multiple'] ) ? ( is_array( $value ) ? array_map( $map, $value ) : array() ) : $map( $value );
	}

	/**
	 * Include image-field files in the archive even when a page does not reference them.
	 * @param array<string, mixed> $field
	 * @param array<string, string> $files
	 * @param list<array<string, mixed>> $entities
	 */
	private static function include_value_assets( array $field, mixed $value, array &$files, bool $ensure, array &$entities ): void {
		if ( 'image' !== $field['type'] ) { return; }
		$values = ! empty( $field['multiple'] ) && is_array( $value ) ? $value : array( $value );
		foreach ( $values as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) { self::append_asset_entity( $entities, PageProfileAdapter::transfer_asset_entity( $post, $files, $ensure ) ); }
		}
	}

	/**
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, mixed> $asset
	 * @param-out list<array<string, mixed>> $entities
	 */
	private static function append_asset_entity( array &$entities, array $asset ): void {
		foreach ( $entities as $entity ) { if ( ( $entity['id'] ?? null ) === ( $asset['id'] ?? null ) ) { return; } }
		$entities[] = $asset;
	}

	private static function available_slug( string $type, string $slug, string $id ): string {
		$found = get_page_by_path( $slug, 'OBJECT', $type );
		// The record itself, published again, keeps its address.
		return $found instanceof \WP_Post && (string) get_post_meta( $found->ID, self::ID_META, true ) !== $id ? substr( $slug, 0, 40 ) . '-histos-' . substr( str_replace( '-', '', $id ), 0, 12 ) : $slug;
	}

	/** Whether the post's WordPress slug is the one record_slug() gives the portable slug (in any language). */
	private static function native_slug_for_profile( \WP_Post $post, string $profile_slug, string $profile_id, string $locale = '' ): bool {
		$candidates = array( $profile_slug );
		if ( '' !== $locale ) {
			$candidates[] = sanitize_title( $profile_slug . '-' . strtolower( self::canonical_locale( $locale ) ) );
		}
		foreach ( $candidates as $candidate ) {
			if ( $post->post_name === $candidate || $post->post_name === substr( $candidate, 0, 40 ) . '-histos-' . substr( str_replace( '-', '', $profile_id ), 0, 12 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A category or tag the site already has with the incoming slug and no Histeon owner: the project adopts it
	 * instead of creating a twin (§12). Never for the project's own taxonomies, never a term another id owns.
	 *
	 * @param array<string, mixed> $entity
	 */
	private static function adoptable_term( array $entity ): ?\WP_Term {
		$taxonomy = (string) ( $entity['payload']['taxonomyKey'] ?? '' );
		// WordPress's categories and tags, WooCommerce's product categories and tags: an unowned one is adopted.
		if ( ! in_array( $taxonomy, array_merge( PageProfile::NATIVE_TAXONOMIES, ProductProfile::TAXONOMIES ), true ) || self::term_by_profile_id( (string) $entity['id'], $taxonomy ) instanceof \WP_Term ) {
			return null;
		}
		$term = get_term_by( 'slug', (string) $entity['payload']['slug'], $taxonomy );
		return $term instanceof \WP_Term && '' === (string) get_term_meta( $term->term_id, self::ID_META, true ) ? $term : null;
	}

	/** @return list<string> The taxonomies a post carries in the profile: WordPress's own, then the Histeon ones enabled for posts. */
	public static function post_taxonomy_keys(): array {
		$keys = PageProfile::NATIVE_TAXONOMIES;
		foreach ( self::dev_definitions()['taxonomies'] as $key => $taxonomy ) {
			if ( in_array( 'post', (array) ( $taxonomy['types'] ?? array() ), true ) && taxonomy_exists( (string) $key ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	/**
	 * The terms of a record in the order the profile last sent them, while they are still the same terms; otherwise as
	 * WordPress reads them (by name), as before TERMS_META.
	 *
	 * @param list<string> $ids
	 * @return list<string>
	 */
	private static function sent_order( array $ids, mixed $sent ): array {
		if ( ! is_array( $sent ) || count( $sent ) !== count( $ids ) || array_diff( $ids, $sent ) || array_diff( $sent, $ids ) ) {
			return $ids;
		}
		return array_values( array_map( 'strval', $sent ) );
	}

	/**
	 * The terms of a post as portable ids, taxonomy by taxonomy, in a stable order (empty taxonomies left out).
	 *
	 * @param list<string>|null $taxonomies The post taxonomies, when the caller already has them.
	 * @return array<string, list<string>>
	 */
	public static function post_terms( \WP_Post $post, bool $ensure, ?array $taxonomies = null ): array {
		$terms = array();
		foreach ( $taxonomies ?? self::post_taxonomy_keys() as $taxonomy ) {
			$assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'orderby' => 'term_id', 'order' => 'ASC' ) );
			if ( is_wp_error( $assigned ) ) { throw new \DomainException( 'Impossibile leggere i termini dell\'articolo ' . $post->post_name . '.' ); }
			$ids = array();
			foreach ( $assigned as $term ) {
				$id = self::term_id( $term, $ensure );
				if ( '' !== $id ) { $ids[] = $id; }
			}
			// In the order of their identities, as Publish sends them: WordPress reads them back by its own ids.
			if ( array() !== $ids ) {
				$ids = array_values( array_unique( $ids ) );
				sort( $ids, SORT_STRING );
				$terms[ $taxonomy ] = $ids;
			}
		}
		return $terms;
	}

	/**
	 * The WordPress term ids of a post, taxonomy by taxonomy (empty taxonomies left out).
	 *
	 * @return array<string, list<int>>
	 */
	public static function post_term_ids( \WP_Post $post ): array {
		$ids = array();
		foreach ( self::post_taxonomy_keys() as $taxonomy ) {
			$assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $assigned ) ) { throw new \DomainException( 'Impossibile leggere i termini dell\'articolo ' . $post->post_name . '.' ); }
			if ( array() !== $assigned ) { $ids[ $taxonomy ] = array_values( array_map( 'intval', $assigned ) ); }
		}
		return $ids;
	}

	/**
	 * WordPress term ids for the portable terms of a post; null while any of them does not exist yet.
	 *
	 * @param array<string, list<string>> $terms
	 * @return array<string, list<int>>|null
	 */
	public static function wordpress_term_ids( array $terms ): ?array {
		$ids = array();
		foreach ( $terms as $taxonomy => $profile_ids ) {
			if ( ! taxonomy_exists( (string) $taxonomy ) ) { return null; }
			$ids[ $taxonomy ] = array();
			foreach ( $profile_ids as $profile_id ) {
				$term = self::term_by_profile_id( (string) $profile_id, (string) $taxonomy );
				if ( ! $term instanceof \WP_Term ) { return null; }
				$ids[ $taxonomy ][] = $term->term_id;
			}
		}
		return $ids;
	}

	private static function post_by_profile_id( string $id, string $type ): ?\WP_Post {
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => self::ID_META, 'meta_value' => $id, 'orderby' => 'ID', 'order' => 'ASC' ) );
		if ( count( $posts ) > 1 ) { throw new \DomainException( 'Due record WordPress condividono la stessa identità Histeon.' ); }
		return $posts[0] ?? null;
	}

	private static function term_by_profile_id( string $id, string $taxonomy ): ?\WP_Term {
		// An explicit order: WooCommerce sorts product categories by their own meta when none is given, and that
		// replaces the meta_key of the query (wc_change_pre_get_terms).
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 2, 'meta_key' => self::ID_META, 'meta_value' => $id, 'orderby' => 'term_id' ) );
		if ( is_wp_error( $terms ) ) { return null; }
		if ( count( $terms ) > 1 ) { throw new \DomainException( 'Due termini WordPress condividono la stessa identità Histeon.' ); }
		return $terms[0] ?? null;
	}

	private static function post_id( \WP_Post $post, bool $ensure ): string {
		$id = (string) get_post_meta( $post->ID, self::ID_META, true );
		if ( PageProfileAdapter::valid_profile_identity( $id ) ) { return $id; }
		if ( ! $ensure ) { return ''; }
		$id = PageProfileAdapter::derived_id( 'record:' . $post->ID );
		PageProfileAdapter::later( static fn () => update_post_meta( $post->ID, self::ID_META, $id ) );
		return $id;
	}

	private static function term_id( \WP_Term $term, bool $ensure ): string {
		$id = (string) get_term_meta( $term->term_id, self::ID_META, true );
		if ( PageProfileAdapter::valid_profile_identity( $id ) ) { return $id; }
		if ( ! $ensure ) { return ''; }
		$id = PageProfileAdapter::derived_id( 'term:' . $term->term_id );
		PageProfileAdapter::later( static fn () => update_term_meta( $term->term_id, self::ID_META, $id ) );
		return $id;
	}

	/** @param array<string, string> $map */
	private static function mapped_id( array &$map, string $scope, string $key, bool $ensure ): string {
		$id = $map[ $key ] ?? '';
		if ( PageProfileAdapter::valid_profile_identity( $id ) ) { return $id; }
		if ( ! $ensure ) { return ''; }
		$map[ $key ] = PageProfileAdapter::derived_id( $scope );
		return $map[ $key ];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function entity( string $id, string $kind, array $payload, ?string $base ): array {
		$entity = array( 'id' => $id, 'kind' => $kind, 'schemaVersion' => 1, 'payload' => $payload );
		$entity['revisionHash'] = PageProfile::revision_hash( $entity );
		if ( is_string( $base ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $base ) ) { $entity['baseHash'] = $base; }
		return $entity;
	}

	private static function base_for( string $id ): ?string {
		$base = ( (array) get_option( self::BASES, array() ) )[ $id ] ?? null;
		return is_string( $base ) ? $base : null;
	}

	private static function valid_hash( mixed $hash ): bool {
		return is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $hash );
	}

	private static function same_json( mixed $left, mixed $right ): bool {
		try { return PageProfile::canonical( $left ) === PageProfile::canonical( $right ); }
		catch ( \Throwable ) { return false; }
	}
}
