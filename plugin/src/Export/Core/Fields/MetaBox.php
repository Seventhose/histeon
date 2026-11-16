<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/**
 * Meta Box. The meta boxes are a JSON file of the site theme (`campi/meta-box.json`), handed to Meta Box by
 * functions.php (filter `rwmb_meta_boxes`). Values stay as Histeon keeps them where Meta Box keeps them the same way:
 * dates `AAAA-MM-GG` (`save_format`), more plain values as one list (`clone`), a repeater as one list of rows (a
 * cloneable `group`); a date and time becomes `AAAA-MM-GG hh:mm:ss` in the site's time, a yes/no `1`/`0`, and more
 * choices, contents or images one meta row each (`multiple`), as Meta Box saves them.
 *
 * A repeater needs the Meta Box Group extension, fields of terms and users MB Term Meta and MB User Meta (paid, or in
 * Meta Box AIO): without them those fields are not shown in the editor, and their values stay as they are.
 */
final class MetaBox implements Adapter {

	public function id(): string {
		return 'metabox';
	}

	public function name(): string {
		return 'Meta Box';
	}

	public function notes( array $content ): array {
		$out    = array();
		$groups = array();
		foreach ( $content['fields'] as $target => $fields ) {
			foreach ( $fields as $field ) {
				if ( 'repeater' === $field['type'] ) {
					$groups[] = (string) $field['name'];
				}
			}
			if ( array() !== $fields && ( 'user' === $target || str_starts_with( (string) $target, 'term:' ) ) ) {
				/* translators: %s: taxonomy or users */
				$out[] = sprintf( __( 'Meta Box: i campi di %s chiedono MB Term Meta o MB User Meta.', 'xb' ), 'user' === $target ? __( 'utenti', 'xb' ) : substr( (string) $target, 5 ) );
			}
		}
		if ( array() !== $groups ) {
			/* translators: %s: field names */
			array_unshift( $out, sprintf( __( 'Meta Box: i ripetitori %s chiedono l\'estensione Meta Box Group.', 'xb' ), implode( ', ', array_unique( $groups ) ) ) );
		}
		return $out;
	}

	public function theme_files( array $content ): array {
		$boxes = array();
		foreach ( $content['fields'] as $target => $fields ) {
			if ( array() === $fields ) {
				continue;
			}
			$target = (string) $target;
			$box    = array(
				'id'     => 'campi-' . str_replace( ':', '-', $target ),
				/* translators: %s: content type, taxonomy or users */
				'title'  => sprintf( __( 'Campi: %s', 'xb' ), $target ),
				'fields' => array_map( array( self::class, 'field' ), $fields ),
			);
			if ( 'user' === $target ) {
				$box['type'] = 'user';
			} elseif ( str_starts_with( $target, 'term:' ) ) {
				$box['taxonomies'] = array( substr( $target, 5 ) );
			} else {
				$box['post_types'] = array( $target );
			}
			$boxes[] = $box;
		}
		return array() === $boxes ? array() : array( 'campi/meta-box.json' => (string) wp_json_encode( $boxes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	public function functions(): string {
		return "\n// The site's fields, for Meta Box (campi/meta-box.json).\nadd_filter(\n\t'rwmb_meta_boxes',\n\tstatic function ( array \$boxes ): array {\n\t\t\$own = json_decode( (string) file_get_contents( __DIR__ . '/campi/meta-box.json' ), true );\n\t\treturn is_array( \$own ) ? array_merge( \$boxes, \$own ) : \$boxes;\n\t}\n);\n";
	}

	public function values( string $target, array $fields, callable $read, array $keys ): array {
		unset( $target, $keys );
		$set  = array();
		$rows = array();
		foreach ( $fields as $field ) {
			$name = (string) $field['name'];
			$raw  = $read( $name );
			if ( ! empty( $field['multiple'] ) && in_array( $field['type'], array( 'select', 'post', 'image' ), true ) ) {
				$rows[ $name ] = array_values( array_map( 'strval', array_filter( is_array( $raw ) ? $raw : array(), 'is_scalar' ) ) );
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

	/**
	 * A value as Meta Box keeps it; the rows of a repeater each the same way.
	 *
	 * @param array<string, mixed> $field
	 */
	private static function value( array $field, mixed $raw ): mixed {
		$type = (string) $field['type'];
		if ( 'repeater' === $type ) {
			$out = array();
			foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $row ) {
				$item = array();
				foreach ( is_array( $field['sub'] ?? null ) ? $field['sub'] : array() as $sub ) {
					$item[ (string) $sub['name'] ] = self::value( $sub, is_array( $row ) ? ( $row[ $sub['name'] ] ?? null ) : null );
				}
				$out[] = $item;
			}
			return $out;
		}
		if ( ! empty( $field['multiple'] ) ) {
			$single = array( 'multiple' => false ) + $field;
			return array_values( array_map( static fn ( mixed $v ): mixed => self::value( $single, $v ), is_array( $raw ) ? $raw : array() ) );
		}
		return match ( $type ) {
			'toggle' => in_array( $raw, array( true, 1, '1', 'true' ), true ) ? '1' : '0',
			'datetime' => Acf::local_time( is_scalar( $raw ) ? (string) $raw : '' ),
			default => is_scalar( $raw ) ? $raw : ( null === $raw ? '' : $raw ),
		};
	}

	/**
	 * A field as Meta Box declares it.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field( array $field ): array {
		$type     = (string) $field['type'];
		$multiple = ! empty( $field['multiple'] );
		$base     = array(
			'id'       => (string) $field['name'],
			'name'     => (string) ( $field['label'] ?? $field['name'] ),
			'desc'     => (string) ( $field['help'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		);
		if ( 'repeater' === $type ) {
			return array(
				'type'        => 'group',
				'clone'       => true,
				'sort_clone'  => true,
				'collapsible' => true,
				'fields'      => array_map( array( self::class, 'field' ), is_array( $field['sub'] ?? null ) ? $field['sub'] : array() ),
			) + $base;
		}
		$own = match ( $type ) {
			'textarea' => array( 'type' => 'textarea' ),
			'rich-text' => array( 'type' => 'wysiwyg' ),
			'number', 'decimal' => array( 'type' => 'number', 'step' => 'any' ),
			'integer' => array( 'type' => 'number', 'step' => 1 ),
			'url', 'email' => array( 'type' => $type ),
			'date' => array( 'type' => 'date', 'save_format' => 'Y-m-d', 'js_options' => array( 'dateFormat' => 'yy-mm-dd' ) ),
			'datetime' => array( 'type' => 'datetime', 'save_format' => 'Y-m-d H:i:s', 'js_options' => array( 'dateFormat' => 'yy-mm-dd', 'timeFormat' => 'HH:mm:ss' ) ),
			'toggle' => array( 'type' => 'switch' ),
			'select' => array(
				'type'     => 'select',
				'options'  => array_column( is_array( $field['options'] ?? null ) ? $field['options'] : array(), 'label', 'value' ),
				'multiple' => $multiple,
			),
			'image' => array( 'type' => $multiple ? 'image_advanced' : 'single_image' ),
			'post' => array(
				'type'       => 'post',
				'post_type'  => array( (string) ( $field['postType'] ?? 'post' ) ),
				'field_type' => 'select_advanced',
				'multiple'   => $multiple,
			),
			default => array( 'type' => 'text' ),
		};
		// More plain values: one field cloned, kept as one list (as Histeon keeps it).
		if ( $multiple && ! in_array( $type, array( 'select', 'post', 'image' ), true ) ) {
			$own += array(
				'clone'      => true,
				'sort_clone' => true,
			);
		}
		return $own + $base;
	}
}
