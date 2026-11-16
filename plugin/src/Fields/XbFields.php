<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

use XB\Content\ContentTypes;

/** Fields defined in XB's Content Hub (ContentTypes): content for the site, always readable. */
final class XbFields implements Provider, ObjectProvider {

	public function id(): string {
		return 'xb';
	}

	public function label(): string {
		return 'Histeon';
	}

	public function available(): bool {
		return true;
	}

	public function describe(): array {
		$out = array();
		foreach ( ContentTypes::live()['fields'] as $post_type => $fields ) {
			foreach ( $fields as $field ) {
				if ( empty( $field['public'] ) ) {
					continue;
				}
				$out[] = array(
					'id'         => "xb:$post_type:{$field['name']}",
					'provider'   => 'xb',
					'name'       => $field['name'],
					'label'      => $field['label'],
					'type'       => $field['type'],
					'required'   => $field['required'],
					'multiple'   => $field['multiple'],
					'group'      => (string) $post_type,
					'groupLabel' => (string) $post_type,
					'postTypes'  => array( (string) $post_type ),
					'rest'       => true,
					'supported'  => true,
					'sub'        => array_map( static fn ( array $sub ): array => array( 'name' => $sub['name'], 'label' => $sub['label'], 'type' => $sub['type'] ), $field['sub'] ),
				);
			}
		}
		return $out;
	}

	public function fields_for( \WP_Post $post ): array {
		$out = array();
		foreach ( ContentTypes::fields_for( $post->post_type ) as $field ) {
			if ( empty( $field['public'] ) ) {
				continue;
			}
			$out[ $field['name'] ] = $field + array(
				'id'   => "xb:{$post->post_type}:{$field['name']}",
				'rest' => true,
			);
		}
		return $out;
	}

	public function object_fields( string $kind, int $id, string $subtype ): array {
		$list = 'term' === $kind ? ContentTypes::fields_for_term( $subtype ) : ( 'user' === $kind ? ContentTypes::fields_for_user() : array() );
		$out  = array();
		foreach ( $list as $field ) {
			if ( empty( $field['public'] ) ) {
				continue;
			}
			$out[ $field['name'] ] = $field + array(
				'id'   => 'xb:' . ( 'term' === $kind ? "term:$subtype" : 'user' ) . ":{$field['name']}",
				'rest' => true,
			);
		}
		return $out;
	}

	public function object_value( string $kind, int $id, array $field ): mixed {
		$raw = 'term' === $kind ? get_term_meta( $id, (string) $field['name'], true ) : get_user_meta( $id, (string) $field['name'], true );
		return self::field_value( $field, $raw );
	}

	public function value( \WP_Post $post, array $field ): mixed {
		$raw = get_post_meta( $post->ID, (string) $field['name'], true );
		if ( 'repeater' === $field['type'] ) {
			// A list of rows by sub field name, read with a loop: `{#loop this.fields.orari as r}{r.giorno}{/loop}`.
			return self::rows( is_array( $field['sub'] ?? null ) ? $field['sub'] : array(), $raw );
		}
		return self::field_value( $field, $raw );
	}

	/**
	 * Rows by sub field name; a repeater in a row (D6) is its own list of rows: `{#loop r.orari as o}`.
	 *
	 * @param array<mixed> $subs
	 * @return list<array<string, mixed>>
	 */
	private static function rows( array $subs, mixed $raw ): array {
		$rows = array();
		foreach ( is_array( $raw ) ? array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) : array() as $row ) {
			$item = array();
			foreach ( $subs as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$value                         = is_array( $row ) ? ( $row[ $sub['name'] ] ?? null ) : null;
				$item[ (string) $sub['name'] ] = 'repeater' === ( $sub['type'] ?? '' ) ? self::rows( is_array( $sub['sub'] ?? null ) ? $sub['sub'] : array(), $value ) : self::convert( (string) $sub['type'], $value );
			}
			$rows[] = $item;
		}
		return $rows;
	}

	/** @param array<string, mixed> $field */
	private static function field_value( array $field, mixed $raw ): mixed {
		if ( ! empty( $field['multiple'] ) ) {
			$items = is_array( $raw ) ? array_slice( array_values( $raw ), 0, 1000 ) : array();
			if ( 'image' === $field['type'] ) {
				return Values::images( $items );
			}
			if ( 'post' === $field['type'] ) {
				return Values::posts( $items );
			}
			if ( 'file' === $field['type'] ) {
				return array_values( array_filter( array_map( array( Values::class, 'file' ), $items ) ) );
			}
			return array_map( static fn ( mixed $value ): mixed => self::convert( (string) $field['type'], $value ), $items );
		}
		return self::convert( (string) $field['type'], $raw );
	}

	private static function convert( string $type, mixed $raw ): mixed {
		return match ( $type ) {
			'number' => Values::number( $raw ),
			'toggle' => Values::boolean( $raw ),
			// Histeon's own date and time keep their zone, as Histeon Publish shows them; other forms as plugins store them.
			'date', 'datetime', 'time' => Values::zoned( $raw ) ?? Values::date( $raw ),
			'file' => Values::file( $raw ),
			'url' => Values::url( $raw ),
			'image' => Values::image( $raw ),
			'post' => Values::post( $raw ),
			'rich-text' => Values::plain( $raw ),
			default => Values::text( $raw ),
		};
	}
}
