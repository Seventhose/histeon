<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * Custom loop queries (presets) and the post types allowed in loops — docs/spec/queries.md.
 *
 * Both live in the versioned `settings` document: edited in dev, previewed, published with a release. Live
 * pages read the options; previews and the builder canvas read the dev version.
 */
final class Queries {

	public const MAX_PRESETS = 200;
	private const MAX_DEPTH  = 4;
	private const MAX_BYTES  = 20000;
	private const BUILTIN    = array( 'posts', 'terms', 'main', 'users' );
	/** WP_Query arguments a preset may not set: XB fixes them so a loop never shows what a visitor cannot read. */
	public const FORBIDDEN_ARGS = array( 'post_status', 'perm', 'has_password', 'post_password', 'suppress_filters', 'fields', 'cache_results', 'lazy_load_term_meta' );

	/** @return array<string, array{type: string, args: array<mixed>}> */
	public static function presets(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'presets', $dev ) ? $dev['presets'] : get_option( LoopPresets::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		/** Presets known at render time only (Etch queries while Etch is off: Import\Etch\Runtime); stored ones win. */
		$extra = apply_filters( 'xb_loop_presets', array() );
		return is_array( $extra ) ? $stored + $extra : $stored;
	}

	/** @return list<string> */
	public static function types(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'loopTypes', $dev ) ? $dev['loopTypes'] : get_option( LoopPresets::TYPES, array() );
		return array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_string' ) );
	}

	/** @return list<string> */
	public static function taxonomies(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'loopTaxonomies', $dev ) ? $dev['loopTaxonomies'] : get_option( LoopPresets::TAXONOMIES, array() );
		return array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_string' ) );
	}

	/**
	 * Checks presets written by a builder; returns them normalized.
	 *
	 * @param mixed $presets
	 * @return array<string, array{type: string, args: array<mixed>}>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $presets ): array {
		if ( ! is_array( $presets ) || count( $presets ) > self::MAX_PRESETS ) {
			throw new \InvalidArgumentException( __( 'Elenco delle query non valido.', 'xb' ) );
		}
		$out = array();
		foreach ( $presets as $id => $preset ) {
			$id = (string) $id;
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $id ) || in_array( $id, self::BUILTIN, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Nome di query non valido o riservato: "%s".', 'xb' ), mb_substr( $id, 0, 40 ) ) );
			}
			$type = is_array( $preset ) ? ( $preset['type'] ?? 'posts' ) : null;
			if ( ! in_array( $type, self::BUILTIN, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Tipo della query "%s" non valido (posts, terms, main o users).', 'xb' ), $id ) );
			}
			$args = is_array( $preset['args'] ?? null ) ? $preset['args'] : array();
			self::check_args( $args, $id, 0 );
			if ( ! current_user_can( 'manage_options' ) ) {
				self::check_readable_predicates( (string) $type, $args, $id );
			}
			if ( 'users' === $type && array() !== array_diff( array_keys( $args ), LoopPresets::USER_ARGS ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'La query di utenti "%1$s" accetta solo: %2$s.', 'xb' ), $id, implode( ', ', LoopPresets::USER_ARGS ) ) );
			}
			if ( strlen( (string) wp_json_encode( $args ) ) > self::MAX_BYTES ) {
				throw new \InvalidArgumentException( sprintf( __( 'Parametri della query "%s" troppo grandi.', 'xb' ), $id ) );
			}
			$out[ $id ] = array(
				'type' => (string) $type,
				'args' => $args,
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Filters and orderings read the fields they name: a builder who is not an administrator may use only the fields
	 * visitors may read (as for facets and expressions), and only public taxonomies. Otherwise a test query would
	 * tell, title by title, what a protected or private field contains.
	 *
	 * @param array<mixed> $args
	 */
	private static function check_readable_predicates( string $type, array $args, string $id ): void {
		$refuse = static fn ( string $what ): \InvalidArgumentException => new \InvalidArgumentException(
			/* translators: 1: field or taxonomy, 2: query name */
			sprintf( __( 'La query "%2$s" usa %1$s, che chi visita non può leggere: solo un amministratore può usarlo nei filtri.', 'xb' ), $what, $id )
		);
		if ( 'terms' === $type && ( isset( $args['meta_query'] ) || isset( $args['meta_key'] ) || isset( $args['meta_value'] ) ) ) {
			throw $refuse( 'meta_query' );
		}
		// A value without a key is compared with every field of the post, private ones included.
		if ( ! isset( $args['meta_key'] ) && ( isset( $args['meta_value'] ) || isset( $args['meta_value_num'] ) || isset( $args['meta_compare'] ) ) ) {
			throw $refuse( 'meta_value' );
		}
		$types = array_values( array_filter( (array) ( $args['post_type'] ?? 'post' ), 'is_string' ) );
		if ( in_array( 'any', $types, true ) || array() === $types ) {
			$types = get_post_types( array( 'public' => true ) );
		}
		$keys = array();
		if ( isset( $args['meta_key'] ) ) {
			$keys[] = $args['meta_key'];
		}
		$keyless = false;
		$walk    = static function ( mixed $clause ) use ( &$walk, &$keys, &$keyless ): void {
			if ( ! is_array( $clause ) ) {
				return;
			}
			if ( array_key_exists( 'key', $clause ) ) {
				foreach ( (array) $clause['key'] as $key ) {
					$keys[] = $key;
				}
				// A key pattern (LIKE, REGEXP…) reaches fields it does not name.
				if ( isset( $clause['compare_key'] ) && ! in_array( strtoupper( (string) $clause['compare_key'] ), array( '=', 'IN', 'EXISTS', 'NOT EXISTS' ), true ) ) {
					$keyless = true;
				}
			} elseif ( array_key_exists( 'value', $clause ) || array_key_exists( 'compare', $clause ) || array_key_exists( 'compare_key', $clause ) ) {
				$keyless = true;
			}
			foreach ( $clause as $name => $nested ) {
				if ( 'key' !== $name && is_array( $nested ) ) {
					$walk( $nested );
				}
			}
		};
		$walk( $args['meta_query'] ?? null );
		if ( $keyless ) {
			throw $refuse( 'meta_query senza chiave' );
		}
		foreach ( $keys as $key ) {
			$key = is_scalar( $key ) ? (string) $key : '';
			foreach ( $types as $post_type ) {
				if ( ! \XB\Data\MetaNode::is_public( $key, (string) $post_type ) ) {
					throw $refuse( '"' . $key . '"' );
				}
			}
		}
		$taxonomies = array();
		$walk_tax = static function ( mixed $clause ) use ( &$walk_tax, &$taxonomies ): void {
			if ( ! is_array( $clause ) ) {
				return;
			}
			if ( isset( $clause['taxonomy'] ) ) {
				$taxonomies[] = $clause['taxonomy'];
			}
			foreach ( $clause as $nested ) {
				if ( is_array( $nested ) ) {
					$walk_tax( $nested );
				}
			}
		};
		$walk_tax( $args['tax_query'] ?? null );
		if ( 'terms' === $type ) {
			$taxonomies = array_merge( $taxonomies, (array) ( $args['taxonomy'] ?? array() ) );
		}
		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = is_scalar( $taxonomy ) ? (string) $taxonomy : '';
			if ( ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
				throw $refuse( '"' . $taxonomy . '"' );
			}
		}
	}

	/** @param array<mixed> $args */
	private static function check_args( array $args, string $id, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \InvalidArgumentException( sprintf( __( 'Parametri della query "%s" troppo annidati.', 'xb' ), $id ) );
		}
		foreach ( $args as $key => $value ) {
			if ( 0 === $depth && ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z_][a-z0-9_]{0,63}$/', $key ) ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Parametro non valido nella query "%s".', 'xb' ), $id ) );
			}
			if ( 0 === $depth && in_array( $key, self::FORBIDDEN_ARGS, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il parametro "%1$s" non si può usare (query "%2$s"): Histeon mostra solo contenuti pubblicati e leggibili da chi visita.', 'xb' ), $key, $id ) );
			}
			if ( is_array( $value ) ) {
				self::check_args( $value, $id, $depth + 1 );
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				throw new \InvalidArgumentException( sprintf( __( 'Valore non valido nella query "%s".', 'xb' ), $id ) );
			}
		}
	}

	/**
	 * Post types an administrator allowed in loops: only existing types, never internals.
	 *
	 * @param mixed $types
	 * @return list<string>
	 */
	public static function validate_types( mixed $types ): array {
		$out = array();
		foreach ( is_array( $types ) ? $types : array() as $type ) {
			if ( is_string( $type ) && post_type_exists( $type ) && ! in_array( $type, LoopPresets::INTERNAL_TYPES, true ) ) {
				$out[] = $type;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Taxonomies an administrator allows in term loops: existing ones, never WordPress internals.
	 *
	 * @param mixed $taxonomies
	 * @return list<string>
	 */
	public static function validate_taxonomies( mixed $taxonomies ): array {
		$out = array();
		foreach ( is_array( $taxonomies ) ? $taxonomies : array() as $taxonomy ) {
			if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, LoopPresets::INTERNAL_TAXONOMIES, true ) ) {
				$out[] = $taxonomy;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * What a preset returns, as a visitor would see it: the first records with their fields, for the builder's
	 * data preview. With a `groupBy` {field, how?} (doc 23 of Publish, item 205) also the groups a grouped loop
	 * repeats, each key with how many records it holds: the same groupBy modifier the Renderer uses, on the whole
	 * result of the one query.
	 *
	 * @param array{type: string, args: array<mixed>} $preset
	 * @param array<string, mixed>                    $params   Loop arguments.
	 * @param array{field: string, how?: string}|null $group_by Valid (Spec::GROUP_BY_*_PATTERN), or null.
	 * @return array{items: list<array{title: string, url: string, fields: list<array{path: string, kind: string, value: string}>}>, groups?: list<array{key: string, count: int}>}
	 */
	public static function test( string $id, array $preset, array $params, ?array $group_by = null ): array {
		$items = ( new LoopPresets() )->run_preset( $id, $preset, $params );
		$out   = array();
		foreach ( array_slice( $items, 0, self::PREVIEW ) as $item ) {
			if ( $item instanceof \XB\Data\Node ) {
				$out[] = array(
					'title'  => self::plain( $item instanceof \XB\Data\PostNode ? $item->get( 'title' ) : $item->get( 'name' ) ),
					'url'    => (string) ( $item->get( 'url' ) ?? '' ),
					'fields' => self::fields( $item ),
				);
			}
		}
		if ( null === $group_by ) {
			return array( 'items' => $out );
		}
		try {
			$groups = \XB\Plugin::instance()->modifiers->apply( 'groupBy', $items, array_values( $group_by ) );
		} catch ( \XB\Expr\EvalError ) {
			$groups = array();
		}
		$list   = array();
		foreach ( is_array( $groups ) ? array_slice( $groups, 0, self::PREVIEW_GROUPS ) : array() as $group ) {
			if ( is_array( $group ) && is_array( $group['items'] ?? null ) ) {
				$list[] = array(
					'key'   => \XB\Expr\Value::to_string( $group['key'] ?? '' ),
					'count' => count( $group['items'] ),
				);
			}
		}
		return array(
			'items'  => $out,
			'groups' => $list,
		);
	}

	/** Groups shown by the preview of a grouped loop. */
	private const PREVIEW_GROUPS = 100;

	/**
	 * A loop's `groupBy` as the builder sends it: {field, how?} with the patterns of the DSL, or null when it is not.
	 *
	 * @return array{field: string, how?: string}|null
	 */
	public static function group_by( mixed $value ): ?array {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'field', 'how' ) ) || ! is_string( $value['field'] ?? null ) || 1 !== preg_match( \XB\Spec::GROUP_BY_FIELD_PATTERN, $value['field'] ) ) {
			return null;
		}
		if ( ! array_key_exists( 'how', $value ) ) {
			return array( 'field' => $value['field'] );
		}
		if ( ! is_string( $value['how'] ) || 1 !== preg_match( \XB\Spec::GROUP_BY_HOW_PATTERN, $value['how'] ) ) {
			return null;
		}
		return array(
			'field' => $value['field'],
			'how'   => $value['how'],
		);
	}

	/** Records shown by the preview (the Data panel of the builder). */
	private const PREVIEW = 20;

	/** What a loop item offers, key by key, for the preview: the expression path, a kind and a short value. */
	private const KEYS = array(
		'post' => array( 'id', 'title', 'url', 'slug', 'type', 'date', 'modified', 'excerpt', 'author.name', 'author.url', 'image.url', 'image.alt', 'image.width', 'image.height', 'categories', 'tags', 'parent.title', 'commentCount', 'readingTime' ),
		'term' => array( 'id', 'name', 'slug', 'url', 'count', 'description', 'taxonomy' ),
		'user' => array( 'id', 'name', 'url', 'avatar', 'bio', 'postCount' ),
	);

	/**
	 * A loop item as rows `{path, kind, value}`: `path` is what goes after `item.` in an expression.
	 *
	 * @return list<array{path: string, kind: string, value: string}>
	 */
	public static function fields( \XB\Data\Node $node ): array {
		$rows = array();
		$keys = self::KEYS[ $node instanceof \XB\Data\TermNode ? 'term' : ( $node instanceof \XB\Data\UserNode ? 'user' : 'post' ) ];
		if ( $node instanceof \XB\Data\PostNode ) {
			// Fields added by plugins (xb_post_data), after the built-in ones.
			$id   = (int) $node->get( 'id' );
			$keys = array_merge( $keys, array_values( array_filter( array_map( 'strval', array_keys( (array) apply_filters( 'xb_post_data', array(), $id ) ) ), static fn ( string $k ): bool => 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $k ) ) ) );
			if ( 'fluent-products' === $node->get( 'type' ) && null !== $node->get( 'product' ) ) {
				$keys = array_merge( $keys, array( 'product.priceText', 'product.price', 'product.regularPrice', 'product.onSale', 'product.inStock', 'product.sku', 'product.buyNowUrl', 'product.variations', 'product.subscription', 'product.brands' ) );
			}
			if ( 'product' === $node->get( 'type' ) && null !== $node->get( 'product' ) ) {
				$keys = array_merge( $keys, array( 'product.priceText', 'product.price', 'product.regularPrice', 'product.onSale', 'product.inStock', 'product.sku', 'product.addToCartUrl', 'product.rating' ) );
			}
			// Custom fields pages can read (XB, ACF/SCF, JetEngine…): docs/spec/content.md §4.
			$keys = array_merge( $keys, array_map( static fn ( string $name ): string => 'fields.' . $name, self::field_names( get_post( $id ) instanceof \WP_Post ? get_post( $id ) : null ) ) );
		} elseif ( $node instanceof \XB\Data\TermNode ) {
			$term = get_term( (int) $node->get( 'id' ), (string) $node->get( 'taxonomy' ) );
			$keys = array_merge( $keys, array_map( static fn ( string $name ): string => 'fields.' . $name, self::field_names( $term instanceof \WP_Term ? $term : null ) ) );
		} elseif ( $node instanceof \XB\Data\UserNode && null !== $node->get( 'id' ) ) {
			$user = get_userdata( (int) $node->get( 'id' ) );
			$keys = array_merge( $keys, array_map( static fn ( string $name ): string => 'fields.' . $name, self::field_names( $user instanceof \WP_User ? $user : null ) ) );
		}
		foreach ( $keys as $path ) {
			$value = $node;
			foreach ( explode( '.', $path ) as $part ) {
				$value = \XB\Expr\Value::member( $value, $part );
			}
			$kind = null;
			if ( $value instanceof \XB\Data\ImageNode ) {
				$path .= '.url';
				$value = $value->get( 'url' );
				$kind  = 'image';
			} elseif ( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) || is_array( $value[0] ?? null ) || ( $value[0] ?? null ) instanceof \XB\Data\ImageNode ) ) {
				// A row or rows of fields (repeater, group, link): shown as a count, read with a loop or by key.
				$value = array() === $value ? null : sprintf( _n( '%d elemento', '%d elementi', count( $value ), 'xb' ), count( $value ) );
				$kind  = 'list';
			}
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$rows[] = array(
				'path'  => $path,
				'kind'  => $kind ?? self::kind( $path, $value ),
				'value' => mb_substr( self::plain( is_array( $value ) ? implode( ', ', array_map( static fn ( mixed $v ): string => \XB\Expr\Value::to_string( $v ), $value ) ) : $value ), 0, 300 ),
			);
		}
		return $rows;
	}

	/**
	 * Names of the custom fields of a post, term or user that pages can read.
	 *
	 * @return list<string>
	 */
	private static function field_names( \WP_Post|\WP_Term|\WP_User|null $object ): array {
		if ( null === $object ) {
			return array();
		}
		$names = array();
		foreach ( \XB\Fields\Fields::providers() as $provider ) {
			if ( ! \XB\Fields\Fields::safe( static fn (): bool => $provider->available(), false ) ) {
				continue;
			}
			$fields = \XB\Fields\Fields::safe(
				static function () use ( $provider, $object ): array {
					if ( $object instanceof \WP_Post ) {
						return $provider->fields_for( $object );
					}
					if ( ! $provider instanceof \XB\Fields\ObjectProvider ) {
						return array();
					}
					return $object instanceof \WP_Term ? $provider->object_fields( 'term', $object->term_id, $object->taxonomy ) : $provider->object_fields( 'user', $object->ID, '' );
				},
				array()
			);
			foreach ( $fields as $name => $field ) {
				if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name ) && \XB\Fields\Fields::is_public( $provider, $field ) ) {
					$names[] = (string) $name;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private static function kind( string $path, mixed $value ): string {
		return match ( true ) {
			is_array( $value ) => 'list',
			is_int( $value ) || is_float( $value ) => 'number',
			str_ends_with( $path, 'url' ) || 'avatar' === $path => in_array( $path, array( 'image.url', 'avatar' ), true ) ? 'image' : 'url',
			in_array( $path, array( 'date', 'modified' ), true ) => 'date',
			default => 'text',
		};
	}

	private static function plain( mixed $value ): string {
		return html_entity_decode( wp_strip_all_tags( \XB\Expr\Value::to_string( $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function count_terms( string $taxonomy ): int {
		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Terms of a public taxonomy by name, for the term filter of a loop when a taxonomy has more than the 200 the
	 * options list (doc 26 voce 222): 50 at most, or the ones asked by id (to name those already chosen).
	 *
	 * @param list<int> $include
	 * @return list<array{id: int, name: string, slug: string, count: int}>
	 */
	public static function search_terms( string $taxonomy, string $search, array $include = array() ): array {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object instanceof \WP_Taxonomy || ! $object->public || ! $object->show_ui ) {
			return array();
		}
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 50,
			'orderby'    => 'name',
		);
		if ( array() !== $include ) {
			$args['include'] = array_slice( array_map( 'intval', $include ), 0, 100 );
			$args['number']  = 100;
		} else {
			$args['search'] = mb_substr( $search, 0, 100 );
		}
		$terms = get_terms( $args );
		return is_array( $terms ) ? array_values(
			array_map(
				static fn ( \WP_Term $t ): array => array(
					'id'    => (int) $t->term_id,
					'name'  => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'slug'  => $t->slug,
					'count' => (int) $t->count,
				),
				$terms
			)
		) : array();
	}

	/**
	 * Choices for the visual query builder: the taxonomies of a content type with their terms, and the authors
	 * with published content of that type. Only public taxonomies, and authors already public on the site.
	 *
	 * @return array{taxonomies: list<array{name: string, label: string, terms: list<array{id: int, name: string, slug: string, count: int}>}>, authors: list<array{id: int, name: string}>, roles: list<array{name: string, label: string}>}
	 */
	public static function options( string $type ): array {
		$taxonomies = array();
		$names      = '' === $type ? get_taxonomies( array( 'public' => true ) ) : get_object_taxonomies( $type );
		foreach ( $names as $name ) {
			$taxonomy = get_taxonomy( (string) $name );
			if ( ! $taxonomy instanceof \WP_Taxonomy || ! $taxonomy->public || ! $taxonomy->show_ui ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'number'     => 200,
					'orderby'    => 'name',
				)
			);
			$taxonomies[] = array(
				'name'  => $taxonomy->name,
				'label' => (string) $taxonomy->labels->name,
				'terms' => is_array( $terms ) ? array_values(
					array_map(
						static fn ( \WP_Term $t ): array => array(
							'id'    => (int) $t->term_id,
							'name'  => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
							'slug'  => $t->slug,
							'count' => (int) $t->count,
						),
						$terms
					)
				) : array(),
				// More than the 200 listed (doc 26 voce 223): the builder searches the others (Queries::search_terms).
				'more'  => is_array( $terms ) && count( $terms ) >= 200 && self::count_terms( $taxonomy->name ) > 200,
			);
		}
		$authors = array();
		if ( '' !== $type && LoopPresets::listable( $type ) ) {
			foreach ( get_users( array( 'has_published_posts' => array( $type ), 'number' => 100, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
				$authors[] = array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				);
			}
		}
		$roles = array();
		foreach ( wp_roles()->get_names() as $role => $label ) {
			$roles[] = array(
				'name'  => (string) $role,
				'label' => translate_user_role( (string) $label ),
			);
		}
		return array(
			'taxonomies' => $taxonomies,
			'authors'    => $authors,
			'roles'      => $roles,
		);
	}
}
