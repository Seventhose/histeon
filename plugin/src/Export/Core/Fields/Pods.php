<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/**
 * Pods. The fields are a `pods.json` in the site theme, which Pods reads from the active theme by itself: each content
 * type, taxonomy or users extended with one group of fields. Values: dates `AAAA-MM-GG` and yes/no as Histeon keeps
 * them (a no as `0`), a date and time `AAAA-MM-GG hh:mm:ss` in the site's time; more values (choices, contents,
 * images, plain values with `repeatable`) one meta row each, with the list in `_pods_<campo>` for choices,
 * relationships and files, as Pods saves them.
 *
 * Pods has no repeater with fields of its own: a repeater is left out of the groups, its values stay as Histeon keeps
 * them (Fondamenta still shows them) and the report says so.
 */
final class Pods implements Adapter {

	public function id(): string {
		return 'pods';
	}

	public function name(): string {
		return 'Pods';
	}

	public function notes( array $content ): array {
		$out = array();
		foreach ( $content['fields'] as $fields ) {
			foreach ( $fields as $field ) {
				if ( 'repeater' === $field['type'] ) {
					$out[] = (string) $field['name'];
				}
			}
		}
		/* translators: %s: field names */
		return array() === $out ? array() : array( sprintf( __( 'Pods non ha ripetitori con campi propri: %s restano come sono, Fondamenta li mostra ma non si modificano nell\'editor.', 'xb' ), implode( ', ', array_unique( $out ) ) ) );
	}

	public function theme_files( array $content ): array {
		$pods = array();
		foreach ( $content['fields'] as $target => $fields ) {
			$fields = array_values( array_filter( $fields, static fn ( array $f ): bool => 'repeater' !== $f['type'] ) );
			if ( array() === $fields ) {
				continue;
			}
			$target = (string) $target;
			[ $type, $name ] = 'user' === $target ? array( 'user', 'user' ) : ( str_starts_with( $target, 'term:' ) ? array( 'taxonomy', substr( $target, 5 ) ) : array( 'post_type', $target ) );
			$pods[] = array(
				'name'    => $name,
				'type'    => $type,
				'storage' => 'meta',
				'groups'  => array(
					array(
						'name'   => 'campi',
						/* translators: %s: content type, taxonomy or users */
						'label'  => sprintf( __( 'Campi: %s', 'xb' ), $name ),
						'fields' => array_map( array( self::class, 'field' ), $fields ),
					),
				),
			);
		}
		return array() === $pods ? array() : array( 'pods.json' => (string) wp_json_encode( array( 'pods' => $pods ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	public function functions(): string {
		return '';
	}

	public function values( string $target, array $fields, callable $read, array $keys ): array {
		unset( $target, $keys );
		$set  = array();
		$rows = array();
		foreach ( $fields as $field ) {
			$name = (string) $field['name'];
			$type = (string) $field['type'];
			$raw  = $read( $name );
			if ( 'repeater' === $type ) {
				continue;
			}
			if ( ! empty( $field['multiple'] ) ) {
				$single        = array( 'multiple' => false ) + $field;
				$list          = array_values( array_map( static fn ( mixed $v ): mixed => self::value( $single, $v ), array_filter( is_array( $raw ) ? $raw : array(), 'is_scalar' ) ) );
				$rows[ $name ] = $list;
				if ( in_array( $type, array( 'post', 'image' ), true ) ) {
					$set[ '_pods_' . $name ] = array_map( 'intval', $list );
				} elseif ( 'select' === $type ) {
					$set[ '_pods_' . $name ] = $list;
				}
				continue;
			}
			$set[ $name ] = self::value( $field, $raw );
		}
		return array(
			'set'    => $set,
			'delete' => array(),
			'rows'   => $rows,
		);
	}

	/** @param array<string, mixed> $field */
	private static function value( array $field, mixed $raw ): mixed {
		return match ( (string) $field['type'] ) {
			'toggle' => in_array( $raw, array( true, 1, '1', 'true' ), true ) ? '1' : '0',
			'datetime' => Acf::local_time( is_scalar( $raw ) ? (string) $raw : '' ),
			'image', 'post' => is_numeric( $raw ) ? (string) (int) $raw : '',
			default => is_scalar( $raw ) ? (string) $raw : '',
		};
	}

	/**
	 * A field as Pods declares it.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field( array $field ): array {
		$type     = (string) $field['type'];
		$multiple = ! empty( $field['multiple'] );
		$base     = array(
			'name'        => (string) $field['name'],
			'label'       => (string) ( $field['label'] ?? $field['name'] ),
			'description' => (string) ( $field['help'] ?? '' ),
			'required'    => ! empty( $field['required'] ) ? 1 : 0,
		);
		$own      = match ( $type ) {
			'textarea' => array( 'type' => 'paragraph' ),
			'rich-text' => array( 'type' => 'wysiwyg' ),
			'number', 'decimal' => array( 'type' => 'number', 'number_decimals' => 'decimal' === $type ? 2 : 0 ),
			'integer' => array( 'type' => 'number', 'number_decimals' => 0 ),
			'url' => array( 'type' => 'website' ),
			'email' => array( 'type' => 'email' ),
			'date' => array( 'type' => 'date', 'date_format_type' => 'date', 'date_format' => 'ymd_dash' ),
			'datetime' => array( 'type' => 'datetime', 'datetime_format' => 'ymd_dash', 'datetime_time_format' => 'h_mm_ss' ),
			'toggle' => array( 'type' => 'boolean', 'boolean_format_type' => 'checkbox' ),
			'select' => array(
				'type'             => 'pick',
				'pick_object'      => 'custom-simple',
				'pick_custom'      => implode( "\n", array_map( static fn ( array $o ): string => $o['value'] . '|' . $o['label'], is_array( $field['options'] ?? null ) ? $field['options'] : array() ) ),
				'pick_format_type' => $multiple ? 'multi' : 'single',
			),
			'image' => array(
				'type'             => 'file',
				'file_type'        => 'images',
				'file_format_type' => $multiple ? 'multi' : 'single',
			),
			'post' => array(
				'type'             => 'pick',
				'pick_object'      => 'post_type',
				'pick_val'         => (string) ( $field['postType'] ?? 'post' ),
				'pick_format_type' => $multiple ? 'multi' : 'single',
			),
			default => array( 'type' => 'text' ),
		};
		// More plain values: one field that repeats, one meta row per value.
		if ( $multiple && ! in_array( $type, array( 'select', 'post', 'image' ), true ) ) {
			$own['repeatable'] = 1;
		}
		return $own + $base;
	}
}
