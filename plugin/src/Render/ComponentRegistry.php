<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Spec;

/**
 * Component definitions: `wp_block` posts with meta `xb_component_key` (the stable ref) and
 * `xb_component_schema` (JSON: {"props": {"name": {"type": "string", "default": …}}}) — docs/spec/blocks.md §3.
 */
final class ComponentRegistry {

	public const META_KEY    = 'xb_component_key';
	public const META_SCHEMA = 'xb_component_schema';


	/** @var array<string, array{id: int, blocks: list<array<string, mixed>>, props: array<string, array<string, mixed>>}|null> */
	private array $cache = array();

	public static function register_meta(): void {
		// Written only by XB itself (REST routes and releases enforce uniqueness): no generic meta API
		// (core REST, XML-RPC custom fields) may set a key and hijack a component site-wide.
		$can_build = static fn (): bool => false;
		foreach ( array( 'wp_block', 'wp_template_part' ) as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => $can_build,
					'sanitize_callback' => static fn ( mixed $value ): string => is_string( $value ) && 1 === preg_match( Spec::COMPONENT_REF_PATTERN, $value ) ? $value : '',
				)
			);
			register_post_meta(
				$post_type,
				self::META_SCHEMA,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => $can_build,
					'sanitize_callback' => static fn ( mixed $value ): string => is_string( $value ) && is_array( json_decode( $value, true ) ) ? $value : '{}',
				)
			);
		}
	}

	/** @return array{id: int, blocks: list<array<string, mixed>>, props: array<string, array<string, mixed>>}|null */
	public function find( string $ref ): ?array {
		if ( ! array_key_exists( $ref, $this->cache ) ) {
			$this->cache[ $ref ] = $this->load( $ref );
		}
		return $this->cache[ $ref ];
	}

	/** Option: every component key → the post that owns it, with the theme it was built for (V14). */
	public const REFS_OPTION = 'xb_component_refs';
	/** Post states in which a component or template part still owns its key. */
	private const OWNING = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/** @var array<string, int>|null */
	private static ?array $refs = null;

	/**
	 * The component or active-theme template part that owns a key. A reusable block wins before a template
	 * part if a corrupt site contains both, and the lowest id among equals, so a later duplicate can never take
	 * over deterministically. Read from one map of every key (two queries to build it), kept until a key, the
	 * state of a component or template part, or the theme changes (watch()).
	 */
	public static function owner( string $ref ): ?int {
		return self::refs()[ $ref ] ?? null;
	}

	/** @return array<string, int> */
	private static function refs(): array {
		if ( null !== self::$refs ) {
			return self::$refs;
		}
		$stored = get_option( self::REFS_OPTION );
		if ( is_array( $stored ) && get_stylesheet() === ( $stored['theme'] ?? null ) && is_array( $stored['map'] ?? null ) ) {
			return self::$refs = array_map( 'intval', $stored['map'] );
		}
		global $wpdb;
		$states = implode( ',', array_fill( 0, count( self::OWNING ), '%s' ) );
		$map    = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one row per component; placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, m.meta_value AS ref FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'wp_block' AND p.post_status IN ($states) ORDER BY p.ID ASC", self::META_KEY, ...self::OWNING ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$map[ (string) $row['ref'] ] ??= (int) $row['id'];
		}
		$parts = get_posts(
			array(
				'post_type'        => 'wp_template_part',
				'post_status'      => self::OWNING,
				'numberposts'      => 500,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery -- one row per template part.
				'suppress_filters' => true,
				'tax_query'        => array( array( 'taxonomy' => 'wp_theme', 'field' => 'slug', 'terms' => array( get_stylesheet() ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- active-theme template parts only.
			)
		);
		foreach ( $parts as $part ) {
			$map[ (string) get_post_meta( $part->ID, self::META_KEY, true ) ] ??= $part->ID;
		}
		unset( $map[''] );
		update_option(
			self::REFS_OPTION,
			array(
				'theme' => get_stylesheet(),
				'map'   => $map,
			),
			true
		);
		return self::$refs = $map;
	}

	/** Forgets the copy of the map kept for this request; the stored one stays. */
	public static function reset_memory(): void {
		self::$refs = null;
	}

	/** Forgets the map of keys: the next owner() builds it again. */
	public static function forget_refs(): void {
		self::$refs = null;
		delete_option( self::REFS_OPTION );
	}

	/** What changes who owns a key: the key itself, the state or existence of a component or part, the theme. */
	public static function watch(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action(
				$hook,
				static function ( mixed $meta_ids, mixed $object_id, mixed $meta_key ): void {
					unset( $meta_ids, $object_id );
					if ( self::META_KEY === $meta_key ) {
						self::forget_refs();
					}
				},
				10,
				3
			);
		}
		add_action(
			'transition_post_status',
			static function ( mixed $new, mixed $old, mixed $post ): void {
				if ( $new !== $old && $post instanceof \WP_Post && in_array( $post->post_type, array( 'wp_block', 'wp_template_part' ), true ) ) {
					self::forget_refs();
				}
			},
			10,
			3
		);
		add_action(
			'deleted_post',
			static function ( mixed $id, mixed $post ): void {
				unset( $id );
				if ( $post instanceof \WP_Post && in_array( $post->post_type, array( 'wp_block', 'wp_template_part' ), true ) ) {
					self::forget_refs();
				}
			},
			10,
			2
		);
		add_action( 'switch_theme', array( self::class, 'forget_refs' ) );
	}

	/**
	 * Refs of the component instances in a block tree.
	 *
	 * @param array<mixed> $blocks
	 * @return list<string>
	 */
	public static function refs_in( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'xb/component' === ( $block['blockName'] ?? null ) && is_string( $block['attrs']['ref'] ?? null ) ) {
				$out[] = $block['attrs']['ref'];
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$out = array_merge( $out, self::refs_in( $block['innerBlocks'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * What a server rendering depends on beyond its own markup (lista della spesa 149): the published components and
	 * the current release (query presets, settings). The block editor keeps its renderings by this value and draws
	 * them again when it changes. One indexed query on the posts table.
	 */
	public static function render_version(): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", 'wp_block' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return substr( md5( (string) wp_json_encode( array( $row, get_option( 'xb_current_release' ) ) ) ), 0, 12 );
	}

	public function forget(): void {
		$this->cache = array();
	}

	/**
	 * Values for drawing a component by itself in the builder (E5): each prop's sample, else its default, else a
	 * value made from its type (the prop's name in words for a text, an image placeholder, the first option, true
	 * for a switch, so what it guards shows). Never used on a page: there an instance gives its props.
	 *
	 * @param array<string, array<string, mixed>> $props The schema's props.
	 * @return array<string, mixed>
	 */
	public static function sample_props( array $props ): array {
		$out = array();
		foreach ( $props as $name => $definition ) {
			$value = $definition['sample'] ?? $definition['default'] ?? null;
			if ( null === $value ) {
				$options = is_array( $definition['options'] ?? null ) ? array_values( $definition['options'] ) : array();
				$label   = is_string( $definition['label'] ?? null ) ? trim( $definition['label'] ) : '';
				$value   = match ( $definition['type'] ?? 'string' ) {
					'number' => 3,
					'boolean' => true,
					'url' => '#',
					'image' => plugins_url( 'assets/samples/image.svg', XB_FILE ),
					'enum' => is_string( $options[0] ?? null ) ? $options[0] : '',
					'array', 'object' => array(),
					default => '' !== $label ? $label : self::words( (string) $name ),
				};
			}
			$out[ (string) $name ] = $value;
		}
		return $out;
	}

	/** An identifier in words: mainTitle and main_title read «Main title». */
	private static function words( string $name ): string {
		return ucfirst( trim( strtolower( (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|[_-]+/', ' ', $name ) ) ) );
	}

	/** @return array{id: int, blocks: list<array<string, mixed>>, props: array<string, array<string, mixed>>}|null */
	private function load( string $ref ): ?array {
		if ( 1 !== preg_match( Spec::COMPONENT_REF_PATTERN, $ref ) ) {
			return null;
		}
		/**
		 * A definition built at render time (an Etch component not imported yet, while Etch is off:
		 * Import\Etch\Runtime). Null: the stored component.
		 */
		$runtime = apply_filters( 'xb_component_definition', null, $ref );
		if ( is_array( $runtime ) && is_int( $runtime['id'] ?? null ) && is_array( $runtime['blocks'] ?? null ) ) {
			$props = array();
			foreach ( is_array( $runtime['props'] ?? null ) ? $runtime['props'] : array() as $name => $prop ) {
				if ( is_string( $name ) && is_array( $prop ) ) {
					$props[ $name ] = $prop;
				}
			}
			return array(
				'id'     => $runtime['id'],
				'blocks' => array_values( array_filter( $runtime['blocks'], 'is_array' ) ),
				'props'  => $props,
			);
		}
		// In a development preview, a component not published yet is found by the key of its development version.
		$owner = self::owner( $ref ) ?? \XB\Versioning\Preview::dev_component( $ref );
		/** A language plugin may answer with a translation of this definition; it must keep the same post type. */
		$post   = null === $owner ? null : get_post( $owner );
		$chosen = null === $owner ? null : apply_filters( 'xb_component_post', $owner, $ref );
		if ( is_int( $chosen ) && $chosen > 0 && $chosen !== $owner ) {
			$variant = get_post( $chosen );
			if ( $post instanceof \WP_Post && $variant instanceof \WP_Post && in_array( $variant->post_type, array( 'wp_block', 'wp_template_part' ), true ) && $variant->post_type === $post->post_type && 'publish' === $variant->post_status ) {
				$post = $variant;
			}
		}
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}
		$schema = json_decode( (string) get_post_meta( $post->ID, self::META_SCHEMA, true ), true );
		$props  = is_array( $schema ) && is_array( $schema['props'] ?? null ) ? $schema['props'] : array();
		/** This filter lets the dev preview substitute unpublished definitions. */
		$content = (string) apply_filters( 'xb_document_content', $post->post_content, $post->ID );
		/** @var list<array<string, mixed>> $blocks */
		$blocks = parse_blocks( $content );
		return array(
			'id'     => $post->ID,
			'blocks' => $blocks,
			'props'  => $props,
		);
	}
}
