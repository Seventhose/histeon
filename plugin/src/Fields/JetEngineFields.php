<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * JetEngine meta fields (meta boxes and fields of its content types). JetEngine stores each field as plain post
 * meta under its name; its definitions come from its meta box manager. Values are converted by Values: media
 * and galleries become images, related posts are read only if the visitor could read them.
 */
final class JetEngineFields implements Provider, ObjectProvider {

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $memo = array();

	/** @param array<string, list<array<string, mixed>>>|null $registry Fields per post type (tests). */
	public function __construct( private readonly ?array $registry = null ) {}

	public function id(): string {
		return 'jetengine';
	}

	public function label(): string {
		return 'JetEngine';
	}

	public function available(): bool {
		return null !== $this->registry || null !== self::manager();
	}

	private static function manager(): ?object {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}
		$engine = jet_engine();
		$boxes  = is_object( $engine ) && isset( $engine->meta_boxes ) ? $engine->meta_boxes : null;
		return is_object( $boxes ) ? $boxes : null;
	}

	/** @return array<string, list<array<string, mixed>>> */
	private function registry(): array {
		if ( null !== $this->registry ) {
			return $this->registry;
		}
		$manager = self::manager();
		$fields  = null;
		if ( null !== $manager && method_exists( $manager, 'get_registered_fields' ) ) {
			$fields = $manager->get_registered_fields();
		} elseif ( null !== $manager && isset( $manager->meta_fields ) ) {
			$fields = $manager->meta_fields;
		}
		$out = array();
		foreach ( is_array( $fields ) ? $fields : array() as $object => $list ) {
			if ( is_string( $object ) && is_array( $list ) ) {
				$out[ $object ] = array_values( array_filter( $list, 'is_array' ) );
			}
		}
		return $out;
	}

	public function describe(): array {
		$out = array();
		foreach ( $this->registry() as $object => $fields ) {
			// Meta boxes of a post type, of a taxonomy's terms, or of users ('user').
			$target = post_type_exists( $object ) ? $object : ( taxonomy_exists( $object ) ? "term:$object" : ( 'user' === $object ? 'user' : null ) );
			if ( null === $target ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! self::is_value( $field ) ) {
					continue;
				}
				$name  = (string) $field['name'];
				$out[] = array(
					'id'         => "jetengine:$object:$name",
					'provider'   => 'jetengine',
					'name'       => $name,
					'label'      => (string) ( $field['title'] ?? $name ),
					'type'       => (string) ( $field['type'] ?? 'text' ),
					'group'      => $object,
					'groupLabel' => (string) ( get_post_type_object( $object )?->labels->singular_name ?? get_taxonomy( $object )->labels->singular_name ?? $object ),
					'postTypes'  => array( $target ),
					'rest'       => ! empty( $field['show_in_rest'] ),
					'supported'  => 'html' !== ( $field['type'] ?? '' ),
					'sub'        => array_values(
						array_map(
							static fn ( array $sub ): array => array(
								'name'  => (string) ( $sub['name'] ?? '' ),
								'label' => (string) ( $sub['title'] ?? $sub['name'] ?? '' ),
								'type'  => (string) ( $sub['type'] ?? 'text' ),
							),
							array_filter( (array) ( $field['repeater-fields'] ?? array() ), 'is_array' )
						)
					),
				);
			}
		}
		return $out;
	}

	public function fields_for( \WP_Post $post ): array {
		$type = $post->post_type;
		if ( ! isset( $this->memo[ $type ] ) ) {
			$this->memo[ $type ] = array();
			foreach ( $this->registry()[ $type ] ?? array() as $field ) {
				if ( self::is_value( $field ) && ! isset( $this->memo[ $type ][ (string) $field['name'] ] ) ) {
					$this->memo[ $type ][ (string) $field['name'] ] = array(
						'id'   => "jetengine:$type:{$field['name']}",
						'rest' => ! empty( $field['show_in_rest'] ),
					) + $field;
				}
			}
		}
		return $this->memo[ $type ];
	}

	public function value( \WP_Post $post, array $field ): mixed {
		return self::convert( $field, get_post_meta( $post->ID, (string) $field['name'], true ), 0 );
	}

	public function object_fields( string $kind, int $id, string $subtype ): array {
		$object = 'term' === $kind ? $subtype : ( 'user' === $kind ? 'user' : '' );
		if ( '' === $object || ( 'term' === $kind && ! taxonomy_exists( $subtype ) ) ) {
			return array();
		}
		$key = "$kind:$object";
		if ( ! isset( $this->memo[ $key ] ) ) {
			$this->memo[ $key ] = array();
			foreach ( $this->registry()[ $object ] ?? array() as $field ) {
				if ( self::is_value( $field ) && ! isset( $this->memo[ $key ][ (string) $field['name'] ] ) ) {
					$this->memo[ $key ][ (string) $field['name'] ] = array(
						'id'   => "jetengine:$object:{$field['name']}",
						'rest' => ! empty( $field['show_in_rest'] ),
					) + $field;
				}
			}
		}
		return $this->memo[ $key ];
	}

	public function object_value( string $kind, int $id, array $field ): mixed {
		$raw = 'term' === $kind ? get_term_meta( $id, (string) $field['name'], true ) : get_user_meta( $id, (string) $field['name'], true );
		return self::convert( $field, $raw, 0 );
	}

	/** @param array<string, mixed> $field */
	private static function is_value( array $field ): bool {
		$name = (string) ( $field['name'] ?? '' );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name ) && in_array( $field['object_type'] ?? 'field', array( 'field', '' ), true );
	}

	/** @param array<string, mixed> $field */
	private static function convert( array $field, mixed $raw, int $depth ): mixed {
		switch ( $field['type'] ?? 'text' ) {
			case 'text':
			case 'textarea':
			case 'colorpicker':
			case 'iconpicker':
			case 'time':
				return Values::text( $raw );
			case 'wysiwyg':
				return Values::plain( $raw );
			case 'number':
				return Values::number( $raw );
			case 'date':
			case 'datetime-local':
				return Values::date( $raw, ! empty( $field['is_timestamp'] ) );
			case 'switcher':
				return Values::boolean( $raw );
			case 'checkbox':
			case 'radio':
			case 'select':
				return Values::choice( $raw );
			case 'media':
				return Values::image( $raw ) ?? Values::file( $raw );
			case 'gallery':
				return Values::images( $raw );
			case 'posts':
				return ! empty( $field['is_multiple'] ) ? Values::posts( $raw ) : Values::post( $raw );
			case 'repeater':
				if ( $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ) {
					return array();
				}
				$subs = array_filter( (array) ( $field['repeater-fields'] ?? array() ), 'is_array' );
				$rows = array();
				foreach ( array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) as $row ) {
					$item = array();
					foreach ( $subs as $sub ) {
						$name = (string) ( $sub['name'] ?? '' );
						if ( '' !== $name && ! str_starts_with( $name, '_' ) ) {
							$item[ $name ] = self::convert( $sub, is_array( $row ) ? ( $row[ $name ] ?? null ) : null, $depth + 1 );
						}
					}
					$rows[] = $item;
				}
				return $rows;
			default:
				return null; // html and anything unknown.
		}
	}
}
