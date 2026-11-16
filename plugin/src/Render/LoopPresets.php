<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Data\PostNode;
use XB\Data\UserNode;
use XB\Data\TermNode;

/**
 * Loop sources `{#loop name(arg: value) as item}` — docs/spec/blocks.md §3 (xb/loop).
 *
 * Built-in presets `posts`, `terms` and `main` (the main query: archives, search, blog index) accept a fixed set of arguments. Custom presets are stored by builders
 * (`xb_build`) in the option `xb_loop_presets` as {"id": {"type": "posts"|"terms", "args": {…}}}; string values
 * "$name" are replaced by the loop argument `name`, and keys left unresolved are dropped.
 * Whatever the preset, queries only return content the visitor can read (threat model T2).
 */
final class LoopPresets {

	public const OPTION    = 'xb_loop_presets';
	/** Post types allowed in loops although WordPress does not make them viewable (set by administrators). */
	public const TYPES     = 'xb_loop_types';
	/** Never listable, whatever the settings: WordPress internals and XB's own private data. */
	public const INTERNAL_TYPES = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'xb_submission' );
	/** Taxonomies allowed in term loops although WordPress does not make them public (set by administrators). */
	public const TAXONOMIES = 'xb_loop_taxonomies';
	/** Never listable in term loops, whatever the settings: WordPress internals. */
	public const INTERNAL_TAXONOMIES = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );
	public const MAX_ITEMS = 100;
	/** The only arguments a users query takes: no search, no meta, no fields (nothing that probes private data). */
	public const USER_ARGS   = array( 'number', 'offset', 'orderby', 'order', 'include', 'exclude', 'role__in', 'role__not_in', 'post_type' );
	private const USER_ORDERS = array( 'display_name', 'post_count', 'registered', 'ID', 'include' );

	/**
	 * @param array<string, mixed> $args Evaluated loop arguments.
	 * @return list<mixed>
	 */
	public function run( string $id, array $args ): array {
		if ( 'posts' === $id ) {
			return $this->posts( $this->post_args( $args ) );
		}
		if ( 'terms' === $id ) {
			return $this->terms( $this->term_args( $args ) );
		}
		if ( 'main' === $id ) {
			return $this->main_query();
		}
		if ( 'users' === $id ) {
			return $this->users(
				array_filter(
					array(
						'number'   => $args['limit'] ?? null,
						'offset'   => $args['offset'] ?? null,
						'orderby'  => $args['orderby'] ?? null,
						'order'    => $args['order'] ?? null,
						'role__in' => $args['role'] ?? null,
					),
					static fn ( mixed $v ): bool => null !== $v
				)
			);
		}
		$preset = Queries::presets()[ $id ] ?? null;
		return is_array( $preset ) ? $this->run_preset( $id, $preset, $args ) : array();
	}

	/**
	 * The WP_Query arguments of a query of contents, as run() would use them, with `$today` and `$now` left as written
	 * (fondamenta/loop resolves them at each request): what the conversion to Fondamenta stores. `principale` is the
	 * main query with the arguments it may change. Null for any other query (terms, users, a preset that is gone).
	 *
	 * @param array<string, mixed> $args Evaluated loop arguments.
	 * @return array{fonte: string, query: array<string, mixed>}|null
	 */
	public function query_args( string $id, array $args ): ?array {
		if ( 'posts' === $id ) {
			$query = $this->visible( $this->post_args( $args ) );
			return null === $query ? null : array( 'fonte' => 'query', 'query' => $query );
		}
		if ( 'main' === $id ) {
			return array( 'fonte' => 'principale', 'query' => array() );
		}
		$preset = Queries::presets()[ $id ] ?? null;
		if ( ! is_array( $preset ) ) {
			return null;
		}
		$resolved = array_diff_key( $this->substitute( $preset['args'], $args + array( 'today' => '$today', 'now' => '$now' ) ), array_flip( Queries::FORBIDDEN_ARGS ) );
		if ( 'main' === $preset['type'] ) {
			return array( 'fonte' => 'principale', 'query' => array_intersect_key( $resolved, array_flip( array( 'posts_per_page', 'offset', 'order', 'orderby' ) ) ) );
		}
		if ( 'posts' !== $preset['type'] ) {
			return null;
		}
		$query = $this->visible( self::loop_paging( $preset['args'], $resolved, $args ) );
		return null === $query ? null : array( 'fonte' => 'query', 'query' => $query );
	}

	/**
	 * A custom preset (stored, or being tested in the builder).
	 *
	 * @param array<mixed>         $preset {type, args}
	 * @param array<string, mixed> $args   Evaluated loop arguments.
	 * @return list<mixed>
	 */
	public function run_preset( string $id, array $preset, array $args ): array {
		unset( $id );
		if ( ! is_array( $preset['args'] ?? null ) ) {
			return array();
		}
		// Whatever is stored, visibility is not a preset's to decide (Queries::FORBIDDEN_ARGS).
		$resolved = array_diff_key( $this->substitute( $preset['args'], $args ), array_flip( Queries::FORBIDDEN_ARGS ) );
		return match ( $preset['type'] ?? 'posts' ) {
			'terms' => $this->terms( $resolved ),
			'main'  => $this->main_query( $resolved ),
			'users' => $this->users( array_intersect_key( $resolved, array_flip( self::USER_ARGS ) ) ),
			default => $this->posts( self::loop_paging( $preset['args'], $resolved, $args ) ),
		};
	}

	/**
	 * People with published content of a listable type, as public profiles (name, page, avatar, bio). Author
	 * pages already show exactly these; email, login and roles are never exposed.
	 *
	 * @param array<string, mixed> $args Only USER_ARGS.
	 * @return list<UserNode>
	 */
	private function users( array $args ): array {
		$requested = isset( $args['post_type'] ) ? (array) $args['post_type'] : array_values( get_post_types( array( 'public' => true ) ) );
		$types     = array_values( array_filter( $requested, array( self::class, 'listable' ) ) );
		if ( array() === $types ) {
			return array();
		}
		$number = isset( $args['number'] ) && is_numeric( $args['number'] ) ? (int) $args['number'] : 10;
		$query  = array(
			'has_published_posts' => $types,
			'number'              => $number <= 0 ? self::MAX_ITEMS : min( self::MAX_ITEMS, $number ),
			'offset'              => isset( $args['offset'] ) && is_numeric( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0,
			'orderby'             => in_array( $args['orderby'] ?? null, self::USER_ORDERS, true ) ? $args['orderby'] : 'display_name',
			'order'               => is_string( $args['order'] ?? null ) && 'desc' === strtolower( $args['order'] ) ? 'DESC' : 'ASC',
			'fields'              => 'ID',
			'count_total'         => false,
		);
		foreach ( array( 'include', 'exclude' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$query[ $key ] = $this->ids( $args[ $key ] );
			}
		}
		foreach ( array( 'role__in', 'role__not_in' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$query[ $key ] = array_values( array_filter( array_map( 'strval', (array) $args[ $key ] ), static fn ( string $r ): bool => 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $r ) ) );
			}
		}
		$out = array();
		foreach ( ( new \WP_User_Query( $query ) )->get_results() as $id ) {
			$node = UserNode::public_profile( (int) $id );
			if ( null !== $node ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function post_args( array $args ): array {
		$query = array( 'posts_per_page' => 10 );
		if ( isset( $args['type'] ) ) {
			$query['post_type'] = $args['type'];
		}
		if ( isset( $args['limit'] ) && is_numeric( $args['limit'] ) ) {
			$query['posts_per_page'] = (int) $args['limit'];
		}
		if ( isset( $args['offset'] ) && is_numeric( $args['offset'] ) ) {
			$query['offset'] = max( 0, (int) $args['offset'] );
		}
		if ( isset( $args['order'] ) && is_string( $args['order'] ) ) {
			$query['order'] = 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		}
		if ( isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'date', 'title', 'modified', 'menu_order', 'rand', 'comment_count' ), true ) ) {
			$query['orderby'] = $args['orderby'];
		}
		if ( isset( $args['category'] ) ) {
			$query[ is_numeric( $args['category'] ) ? 'cat' : 'category_name' ] = $args['category'];
		}
		if ( isset( $args['tag'] ) && is_string( $args['tag'] ) ) {
			$query['tag'] = $args['tag'];
		}
		if ( isset( $args['author'] ) && is_numeric( $args['author'] ) ) {
			$query['author'] = (int) $args['author'];
		}
		if ( isset( $args['exclude'] ) ) {
			$query['post__not_in'] = $this->ids( $args['exclude'] );
		}
		if ( isset( $args['include'] ) ) {
			$query['post__in'] = $this->ids( $args['include'] );
		}
		if ( isset( $args['taxonomy'], $args['term'] ) && is_string( $args['taxonomy'] ) ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => $args['taxonomy'],
					'field'    => is_numeric( $args['term'] ) ? 'term_id' : 'slug',
					'terms'    => $args['term'],
				),
			);
		}
		return $query;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function term_args( array $args ): array {
		$query = array(
			'taxonomy' => is_string( $args['taxonomy'] ?? null ) ? $args['taxonomy'] : 'category',
			'number'   => isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (int) $args['limit'] : 20,
		);
		if ( isset( $args['hideEmpty'] ) ) {
			$query['hide_empty'] = (bool) $args['hideEmpty'];
		}
		if ( isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'name', 'count', 'slug', 'term_order' ), true ) ) {
			$query['orderby'] = $args['orderby'];
		}
		if ( isset( $args['parent'] ) && is_numeric( $args['parent'] ) ) {
			$query['parent'] = (int) $args['parent'];
		}
		return $query;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return list<PostNode>
	 */
	private function posts( array $query ): array {
		return $this->posts_page( $query, false )['items'];
	}

	/**
	 * WP_Query arguments with the visibility rules every list follows; null when no type may be listed.
	 *
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>|null
	 */
	private function visible( array $query, int $max = self::MAX_ITEMS ): ?array {
		$types = (array) ( $query['post_type'] ?? 'post' );
		$types = array_values( array_filter( $types, array( self::class, 'listable' ) ) );
		if ( array() === $types ) {
			return null;
		}
		$per_page = (int) ( $query['posts_per_page'] ?? 10 );
		$query    = array_merge(
			array( 'ignore_sticky_posts' => true ),
			// posts_per_archive_page replaces posts_per_page when the query is an archive (a tax_query is one), and
			// nopaging drops the limit: neither may take a list past its cap.
			array_diff_key( $query, array_flip( Queries::FORBIDDEN_ARGS ), array( 'posts_per_archive_page' => true ) ),
			array(
				// One type as a string, like WordPress itself: plugins filtering pre_get_posts compare it that way.
				'post_type'      => 1 === count( $types ) ? $types[0] : $types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'perm'           => 'readable',
				'no_found_rows'  => true,
				// -1 means "all" in WP_Query: here it means as many as a loop may show.
				'posts_per_page' => $per_page <= 0 ? $max : min( $max, $per_page ),
				'nopaging'       => false,
			)
		);
		if ( in_array( 'product', $types, true ) && taxonomy_exists( 'product_visibility' ) ) {
			// WooCommerce: products hidden from the catalogue never appear in a list (docs/spec/ecommerce.md).
			$query['tax_query']   = is_array( $query['tax_query'] ?? null ) ? $query['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$query['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => array( 'exclude-from-catalog' ),
				'operator' => 'NOT IN',
			);
		}
		return $query;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array{items: list<PostNode>, total: int}
	 */
	private function posts_page( array $query, bool $count, int $max = self::MAX_ITEMS ): array {
		$query = $this->visible( $query, $max );
		if ( null === $query ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$query['no_found_rows'] = ! $count;
		$wp_query               = new \WP_Query( self::stable_order( $query ) );
		$items                  = PostNode::for_posts( $wp_query->posts );
		return array(
			'items' => $items,
			'total' => $count ? (int) $wp_query->found_posts : count( $items ),
		);
	}

	/** Orders whose ties the ID breaks: a value many posts may share. */
	private const TIED_ORDERS = array( 'date', 'modified', 'title', 'menu_order', 'comment_count' );

	/**
	 * The same order, with the ID breaking ties in the same direction: posts with the same date (products imported
	 * together) would otherwise come out in whatever order the database plan gives, which changes when the query
	 * changes (another page of the list, a filter). Orders given as a list, random and the others stay as they are.
	 *
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public static function stable_order( array $query ): array {
		$orderby = $query['orderby'] ?? 'date';
		if ( ! is_string( $orderby ) || ! in_array( strtolower( trim( $orderby ) ), self::TIED_ORDERS, true ) ) {
			return $query;
		}
		$order            = is_string( $query['order'] ?? null ) && 'ASC' === strtoupper( $query['order'] ) ? 'ASC' : 'DESC';
		$query['orderby'] = array(
			strtolower( trim( $orderby ) ) => $order,
			'ID'                           => $order,
		);
		unset( $query['order'] );
		return $query;
	}

	/**
	 * The posts query a facet filters (docs/spec/facets.md): the built-in `posts` of one type, or a custom posts
	 * preset with its defaults. Null when the preset does not exist or is not a posts query.
	 *
	 * @return array<string, mixed>|null
	 */
	public function facet_base( string $query, string $type ): ?array {
		if ( 'posts' === $query ) {
			return $this->post_args( array( 'type' => '' === $type ? 'post' : $type ) );
		}
		$preset = Queries::presets()[ $query ] ?? null;
		if ( null === $preset || 'posts' !== $preset['type'] ) {
			return null;
		}
		return array_diff_key( $this->substitute( $preset['args'], array() ), array_flip( Queries::FORBIDDEN_ARGS ) );
	}

	/**
	 * One page of a facet's results, with the total.
	 *
	 * @param array<string, mixed> $query
	 * @return array{items: list<PostNode>, total: int}
	 */
	public function facet_page( array $query, int $max = self::MAX_ITEMS ): array {
		// A static page carries every result (Facets::STATIC_MAX), not only as many as a loop shows.
		return $this->posts_page( $query, true, max( 1, min( Facets::STATIC_MAX, $max ) ) );
	}

	/**
	 * Ids of the first $max posts a facet query matches, to count the options of a filter.
	 *
	 * @param array<string, mixed> $query
	 * @return list<int>
	 */
	public function facet_ids( array $query, int $max ): array {
		$query = $this->visible( $query );
		if ( null === $query ) {
			return array();
		}
		unset( $query['paged'], $query['offset'], $query['orderby'], $query['order'] );
		$query['posts_per_page'] = $max;
		$query['fields']         = 'ids';
		$ids                     = array();
		foreach ( ( new \WP_Query( $query ) )->posts as $id ) {
			$ids[] = (int) ( $id instanceof \WP_Post ? $id->ID : $id );
		}
		return $ids;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return list<TermNode>
	 */
	private function terms( array $query ): array {
		$taxonomy = get_taxonomy( is_string( $query['taxonomy'] ?? null ) ? $query['taxonomy'] : '' );
		if ( ! $taxonomy || ! self::listable_taxonomy( $taxonomy->name ) ) {
			return array();
		}
		$query['number'] = min( self::MAX_ITEMS, max( 1, (int) ( $query['number'] ?? 20 ) ) );
		$terms           = get_terms( $query );
		$items           = array();
		// No 'fields' argument is ever passed, so get_terms() returns WP_Term objects or a WP_Error.
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$items[] = new TermNode( $term );
		}
		return $items;
	}

	/**
	 * The posts of the main query (archives, search, blog index), already limited by WordPress.
	 * A custom `main` preset may change how many, in which order and from which offset: the same query is
	 * then run again with those values (and the same visibility rules).
	 *
	 * @param array<string, mixed> $override
	 * @return list<PostNode>
	 */
	private function main_query( array $override = array() ): array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return array();
		}
		$posts = $wp_query->posts;
		$query = array();
		if ( isset( $override['posts_per_page'] ) && is_numeric( $override['posts_per_page'] ) ) {
			$query['posts_per_page'] = (int) $override['posts_per_page'] <= 0 ? self::MAX_ITEMS : min( self::MAX_ITEMS, (int) $override['posts_per_page'] );
		}
		if ( isset( $override['offset'] ) && is_numeric( $override['offset'] ) && (int) $override['offset'] > 0 ) {
			$query['offset'] = (int) $override['offset'];
		}
		if ( isset( $override['order'] ) && is_string( $override['order'] ) ) {
			$query['order'] = 'asc' === strtolower( $override['order'] ) ? 'ASC' : 'DESC';
		}
		if ( isset( $override['orderby'] ) && in_array( $override['orderby'], array( 'date', 'title', 'modified', 'menu_order', 'rand', 'comment_count', 'relevance' ), true ) ) {
			$query['orderby'] = $override['orderby'];
		}
		if ( array() !== $query && ! is_404() ) {
			$posts = ( new \WP_Query( array_merge( $wp_query->query_vars, $query, array( 'no_found_rows' => true, 'perm' => 'readable' ) ) ) )->posts;
		}
		return PostNode::for_posts( $posts );
	}

	/** @return list<int> */
	private function ids( mixed $value ): array {
		$values = is_array( $value ) ? $value : array( $value );
		$ids    = array();
		foreach ( $values as $v ) {
			if ( $v instanceof PostNode ) {
				$ids[] = $v->id();
			} elseif ( is_numeric( $v ) ) {
				$ids[] = (int) $v;
			}
		}
		return $ids;
	}

	/** Viewable types, plus the ones an administrator allowed explicitly; never WordPress internals. */
	public static function listable( mixed $type ): bool {
		if ( ! is_string( $type ) || ! post_type_exists( $type ) || in_array( $type, self::INTERNAL_TYPES, true ) ) {
			return false;
		}
		if ( is_post_type_viewable( $type ) ) {
			return true;
		}
		$allowed = (array) apply_filters( 'xb_loop_post_types', Queries::types() );
		return in_array( $type, $allowed, true );
	}

	/**
	 * Public taxonomies, plus the ones an administrator allowed explicitly (an Etch site counted titles with
	 * WooCommerce's private `product_type`); never WordPress internals.
	 */
	public static function listable_taxonomy( mixed $taxonomy ): bool {
		$object = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
		if ( ! $object || in_array( $object->name, self::INTERNAL_TAXONOMIES, true ) ) {
			return false;
		}
		if ( $object->public ) {
			return true;
		}
		$allowed = (array) apply_filters( 'xb_loop_taxonomies', Queries::taxonomies() );
		return in_array( $object->name, $allowed, true );
	}

	/** A default written in a preset: number, quoted string, true/false/null; anything else stays text. */
	private static function literal( string $value ): mixed {
		if ( is_numeric( $value ) ) {
			return $value + 0;
		}
		if ( 1 === preg_match( '/^([\'"])(.*)\1$/s', $value, $m ) ) {
			return $m[2];
		}
		return match ( strtolower( $value ) ) {
			'true' => true,
			'false' => false,
			'null' => null,
			default => $value,
		};
	}

	/**
	 * `limit` and `offset` passed by the loop reach any posts preset, as in Histeon Publish (CmsContentModel's
	 * resolveQueryPreset, ProfileValidator's base arguments): presets imported before ContentProfileAdapter wrote the
	 * placeholders store fixed numbers. A parameter of the preset's own ("$quanti ?? 6") wins over `limit` when the
	 * loop passes it; `offset` always wins, as there.
	 *
	 * @param array<mixed>         $template The stored preset arguments.
	 * @param array<string, mixed> $resolved The arguments after substitution.
	 * @param array<string, mixed> $args     Evaluated loop arguments.
	 * @return array<string, mixed>
	 */
	private static function loop_paging( array $template, array $resolved, array $args ): array {
		$own = $template['posts_per_page'] ?? null;
		$own = is_string( $own ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]*)/', trim( $own ), $m ) ? $m[1] : null;
		if ( isset( $args['limit'] ) && is_numeric( $args['limit'] ) && ( null === $own || ! array_key_exists( $own, $args ) ) ) {
			$resolved['posts_per_page'] = max( 1, (int) $args['limit'] );
		}
		if ( isset( $args['offset'] ) && is_numeric( $args['offset'] ) ) {
			$resolved['offset'] = max( 0, (int) $args['offset'] );
		}
		return $resolved;
	}

	/**
	 * @param array<mixed>         $template
	 * @param array<string, mixed> $args
	 * @return array<mixed>
	 */
	private function substitute( array $template, array $args ): array {
		// "$today" and "$now", unless the loop passes a parameter with that name: the date (Y-m-d) and the time
		// (Y-m-d H:i:s) of the site's time zone, as date fields store them. "Prossimi eventi" reads
		// {"key": "data", "value": "$today", "compare": ">=", "type": "DATE"}.
		$args += array(
			'today' => wp_date( 'Y-m-d' ),
			'now'   => wp_date( 'Y-m-d H:i:s' ),
		);
		$out = array();
		foreach ( $template as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = $this->substitute( $value, $args );
			} elseif ( is_string( $value ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]*)(?:\s*\?\?\s*(.+))?$/s', $value, $m ) ) {
				if ( array_key_exists( $m[1], $args ) ) {
					$out[ $key ] = $args[ $m[1] ] instanceof PostNode ? $args[ $m[1] ]->id() : $args[ $m[1] ];
				} elseif ( isset( $m[2] ) ) {
					$out[ $key ] = self::literal( trim( $m[2] ) ); // "$limit ?? -1": the default when the loop does not pass it.
				}
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
