<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * Meta Box (metabox.io): the fields of its meta boxes for posts. Definitions come from its registry
 * (rwmb_get_registry), values from rwmb_get_value, the raw value without its HTML output: no shortcodes, no
 * oEmbed requests, no templates. Values are converted by Values, so related posts are read only if the visitor
 * could read them, and media become images or files of the library.
 */
final class MetaBoxFields implements Provider, ObjectProvider {

	/** Field types without a value: they only lay out the editing screen. */
	private const LAYOUT = array( 'heading', 'divider', 'custom_html', 'button', 'tab' );

	/** @var array<string, array<string, array<string, mixed>>> Fields of a post type, by name. */
	private array $memo = array();

	public function id(): string {
		return 'metabox';
	}

	public function label(): string {
		return 'Meta Box';
	}

	public function available(): bool {
		return function_exists( 'rwmb_get_registry' ) && function_exists( 'rwmb_get_value' );
	}

	/**
	 * The meta boxes of one kind of object: posts (by post type), terms (by taxonomy, MB Term Meta) or users
	 * (MB User Meta). Settings pages are never read.
	 *
	 * @param 'post'|'term'|'user' $kind
	 * @return list<array{id: string, title: string, postTypes: list<string>, fields: list<array<string, mixed>>}>
	 */
	private static function boxes( string $kind = 'post' ): array {
		$registry = rwmb_get_registry( 'meta_box' );
		$all      = is_object( $registry ) && method_exists( $registry, 'all' ) ? $registry->all() : array();
		$out      = array();
		foreach ( is_array( $all ) ? $all : array() as $box ) {
			if ( ! is_object( $box ) || ! method_exists( $box, 'get_object_type' ) || $kind !== $box->get_object_type() ) {
				continue;
			}
			$settings = property_exists( $box, 'meta_box' ) && is_array( $box->meta_box ) ? $box->meta_box : array();
			$id       = (string) ( $settings['id'] ?? '' );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,60}$/', $id ) ) {
				continue;
			}
			$targets = match ( $kind ) {
				'term' => array_values( array_filter( (array) ( $settings['taxonomies'] ?? array() ), 'is_string' ) ),
				'user' => array( 'user' ),
				default => array_values( array_filter( (array) ( $settings['post_types'] ?? array() ), 'is_string' ) ),
			};
			$out[] = array(
				'id'        => $id,
				'title'     => (string) ( $settings['title'] ?? $id ),
				'postTypes' => $targets,
				'fields'    => array_values( array_filter( (array) ( $settings['fields'] ?? array() ), 'is_array' ) ),
			);
		}
		return $out;
	}

	public function object_fields( string $kind, int $id, string $subtype ): array {
		if ( ! in_array( $kind, array( 'term', 'user' ), true ) ) {
			return array();
		}
		$target = 'term' === $kind ? $subtype : 'user';
		$key    = "$kind:$target";
		if ( ! isset( $this->memo[ $key ] ) ) {
			$this->memo[ $key ] = array();
			foreach ( self::boxes( $kind ) as $box ) {
				if ( ! in_array( $target, $box['postTypes'], true ) ) {
					continue;
				}
				foreach ( $box['fields'] as $field ) {
					$name = (string) ( $field['id'] ?? '' );
					if ( self::is_value( $field ) && ! isset( $this->memo[ $key ][ $name ] ) ) {
						$meta = get_registered_meta_keys( $kind, 'term' === $kind ? $subtype : '' )[ $name ] ?? get_registered_meta_keys( $kind )[ $name ] ?? null;
						$this->memo[ $key ][ $name ] = array(
							'id'   => "metabox:{$box['id']}:$name",
							'name' => $name,
							'rest' => is_array( $meta ) && ! empty( $meta['show_in_rest'] ),
						) + $field;
					}
				}
			}
		}
		return $this->memo[ $key ];
	}

	public function object_value( string $kind, int $id, array $field ): mixed {
		return self::convert( $field, rwmb_get_value( (string) $field['name'], array( 'object_type' => $kind ), $id ), 0 );
	}

	public function describe(): array {
		$out   = array();
		$boxes = array_merge(
			array_map( static fn ( array $b ): array => $b + array( 'kind' => 'post' ), self::boxes( 'post' ) ),
			array_map( static fn ( array $b ): array => $b + array( 'kind' => 'term' ), self::boxes( 'term' ) ),
			array_map( static fn ( array $b ): array => $b + array( 'kind' => 'user' ), self::boxes( 'user' ) )
		);
		foreach ( $boxes as $box ) {
			$types = match ( $box['kind'] ) {
				'term' => array_map( static fn ( string $t ): string => "term:$t", array_values( array_filter( $box['postTypes'], 'taxonomy_exists' ) ) ),
				'user' => array( 'user' ),
				default => array_values( array_filter( $box['postTypes'], 'post_type_exists' ) ),
			};
			foreach ( $box['fields'] as $field ) {
				if ( ! self::is_value( $field ) ) {
					continue;
				}
				$name  = (string) $field['id'];
				$type  = (string) ( $field['type'] ?? 'text' );
				$out[] = array(
					'id'         => "metabox:{$box['id']}:$name",
					'provider'   => 'metabox',
					'name'       => $name,
					'label'      => (string) ( '' !== (string) ( $field['name'] ?? '' ) ? $field['name'] : $name ),
					// A cloneable group is a list of rows: shown (and read) like a repeater.
					'type'       => 'group' === $type && ! empty( $field['clone'] ) ? 'repeater' : $type,
					'group'      => $box['id'],
					'groupLabel' => $box['title'],
					'postTypes'  => $types,
					'rest'       => array() !== array_filter( $types, static fn ( string $t ): bool => self::rest( $field, $t ) ),
					'supported'  => self::supported( $type ),
					'sub'        => self::sub( $field ),
				);
			}
		}
		return $out;
	}

	public function fields_for( \WP_Post $post ): array {
		$type = $post->post_type;
		if ( ! isset( $this->memo[ $type ] ) ) {
			$this->memo[ $type ] = array();
			foreach ( self::boxes() as $box ) {
				if ( ! in_array( $type, $box['postTypes'], true ) ) {
					continue;
				}
				foreach ( $box['fields'] as $field ) {
					$name = (string) ( $field['id'] ?? '' );
					if ( self::is_value( $field ) && ! isset( $this->memo[ $type ][ $name ] ) ) {
						$this->memo[ $type ][ $name ] = array(
							'id'   => "metabox:{$box['id']}:$name",
							'name' => $name,
							'rest' => self::rest( $field, $type ),
						) + $field;
					}
				}
			}
		}
		return $this->memo[ $type ];
	}

	public function value( \WP_Post $post, array $field ): mixed {
		// `id` is XB's (metabox:box:key) here; `name` is the meta key, Meta Box's own field id.
		return self::convert( $field, rwmb_get_value( (string) $field['name'], array( 'object_type' => 'post' ), $post->ID ), 0 );
	}

	/** @param array<string, mixed> $field */
	private static function is_value( array $field ): bool {
		$name = (string) ( $field['id'] ?? '' );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name ) && ! in_array( $field['type'] ?? 'text', self::LAYOUT, true ) && false !== ( $field['save_field'] ?? true );
	}

	/**
	 * Published in the REST API already: by its MB REST API extension (every field of a post type in REST, unless
	 * `hide_from_rest`), or as a meta key registered with show_in_rest.
	 *
	 * @param array<string, mixed> $field
	 */
	private static function rest( array $field, string $post_type ): bool {
		$key  = (string) ( $field['id'] ?? '' );
		$meta = get_registered_meta_keys( 'post', $post_type )[ $key ] ?? get_registered_meta_keys( 'post' )[ $key ] ?? null;
		if ( is_array( $meta ) && ! empty( $meta['show_in_rest'] ) ) {
			return true;
		}
		return function_exists( 'mb_rest_api_load' ) && empty( $field['hide_from_rest'] ) && ! empty( get_post_type_object( $post_type )->show_in_rest );
	}

	private static function supported( string $type ): bool {
		return null !== self::kind( $type );
	}

	/** How a Meta Box type is read; null: never (passwords, hidden inputs, and anything unknown). */
	private static function kind( string $type ): ?string {
		return match ( $type ) {
			'text', 'textarea', 'email', 'tel', 'color', 'time', 'icon' => 'text',
			'wysiwyg', 'block_editor' => 'plain',
			'number', 'range', 'slider' => 'number',
			'url', 'oembed', 'file_input' => 'url',
			'checkbox', 'switch' => 'boolean',
			'date', 'datetime' => 'date',
			'select', 'select_advanced', 'select_tree', 'radio', 'checkbox_list', 'checkbox_tree', 'button_group', 'image_select', 'autocomplete' => 'choice',
			'single_image' => 'image',
			'image', 'image_advanced', 'image_upload', 'plupload_image', 'thickbox_image' => 'images',
			'file', 'file_advanced', 'file_upload', 'video' => 'files',
			'post' => 'post',
			'taxonomy', 'taxonomy_advanced' => 'terms',
			'user' => 'user',
			'map', 'osm' => 'map',
			'group' => 'group',
			default => null,
		};
	}

	/** @param array<string, mixed> $field */
	private static function convert( array $field, mixed $raw, int $depth ): mixed {
		$multiple = ! empty( $field['multiple'] );
		if ( ! empty( $field['clone'] ) ) {
			// A cloneable field holds a list of values, one per clone.
			if ( $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ) {
				return array();
			}
			$one = array( 'clone' => false ) + $field;
			$out = array();
			foreach ( array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) as $item ) {
				$out[] = self::convert( $one, $item, $depth + 1 );
			}
			return $out;
		}
		switch ( self::kind( (string) ( $field['type'] ?? 'text' ) ) ) {
			case 'text':
				return Values::text( $raw );
			case 'plain':
				return Values::plain( $raw );
			case 'number':
				return Values::number( $raw );
			case 'url':
				return Values::url( $raw );
			case 'boolean':
				return Values::boolean( $raw );
			case 'date':
				return Values::date( $raw, ! empty( $field['timestamp'] ) );
			case 'choice':
				return Values::choice( $raw );
			case 'image':
				return Values::image( $raw );
			case 'images':
				return Values::images( $raw );
			case 'files':
				$files = array();
				foreach ( is_array( $raw ) ? array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) : array() as $item ) {
					$file = Values::file( $item );
					if ( null !== $file ) {
						$files[] = $file;
					}
				}
				return $files;
			case 'post':
				return $multiple ? Values::posts( $raw ) : Values::post( $raw );
			case 'terms':
				// rwmb_get_value gives the terms themselves: read by ID, from public taxonomies only.
				$items = is_array( $raw ) ? $raw : ( empty( $raw ) ? array() : array( $raw ) );
				$terms = Values::terms( array_map( static fn ( mixed $t ): mixed => $t instanceof \WP_Term ? $t->term_id : $t, array_values( $items ) ) );
				return $multiple ? $terms : ( $terms[0] ?? null );
			case 'user':
				if ( $multiple ) {
					return array_values( array_filter( array_map( array( Values::class, 'user' ), is_array( $raw ) ? array_slice( $raw, 0, Values::MAX_ROWS ) : array() ) ) );
				}
				return Values::user( $raw );
			case 'map':
				return is_array( $raw ) ? Values::map(
					array(
						'lat' => $raw['latitude'] ?? null,
						'lng' => $raw['longitude'] ?? null,
					)
				) : null;
			case 'group':
				return $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ? null : self::row( (array) ( $field['fields'] ?? array() ), $raw, $depth + 1 );
			default:
				return null;
		}
	}

	/**
	 * A row of a group: the values of its sub fields, by their id.
	 *
	 * @param array<mixed>        $sub_fields
	 * @param array<mixed, mixed> $raw
	 * @return array<string, mixed>
	 */
	private static function row( array $sub_fields, array $raw, int $depth ): array {
		$out = array();
		foreach ( $sub_fields as $sub ) {
			if ( is_array( $sub ) && self::is_value( $sub ) ) {
				$out[ (string) $sub['id'] ] = self::convert( $sub, $raw[ $sub['id'] ] ?? null, $depth );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<array{name: string, label: string, type: string}>
	 */
	private static function sub( array $field ): array {
		$out = array();
		foreach ( 'group' === ( $field['type'] ?? '' ) ? (array) ( $field['fields'] ?? array() ) : array() as $sub ) {
			if ( is_array( $sub ) && self::is_value( $sub ) ) {
				$out[] = array(
					'name'  => (string) $sub['id'],
					'label' => (string) ( $sub['name'] ?? $sub['id'] ),
					'type'  => (string) ( $sub['type'] ?? 'text' ),
				);
			}
		}
		return $out;
	}
}
