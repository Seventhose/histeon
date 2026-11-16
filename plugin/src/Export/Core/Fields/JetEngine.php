<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/**
 * JetEngine (Crocoblock, installed by the owner with their licence). The meta boxes are a JSON file of the site theme
 * (`campi/jet-engine.json`), added by functions.php to those JetEngine keeps in its option; once saved in JetEngine's
 * screen they are its own. Values as JetEngine saves them: dates `AAAA-MM-GG`, a date and time `AAAA-MM-GGThh:mm` in
 * the site's time, yes/no `true`/`false`, more images a list of IDs separated by commas, a repeater rows `item-0`,
 * `item-1`…; more plain values a repeater of one field, `valore`.
 *
 * JetEngine has no repeater inside a repeater's row: such a field is left out of the row, its values stay in the row
 * as they are (Fondamenta still shows them), and the report says so.
 */
final class JetEngine implements Adapter {

	public function id(): string {
		return 'jetengine';
	}

	public function name(): string {
		return 'JetEngine';
	}

	public function notes( array $content ): array {
		$out = array( __( 'JetEngine non è su WordPress.org: si installa con la licenza del proprietario.', 'xb' ) );
		foreach ( $content['fields'] as $fields ) {
			foreach ( $fields as $field ) {
				foreach ( 'repeater' === $field['type'] ? $field['sub'] : array() as $sub ) {
					if ( 'repeater' === $sub['type'] ) {
						/* translators: 1: repeater, 2: repeater in its rows */
						$out[] = sprintf( __( 'JetEngine non ha ripetitori nelle righe: %1$s.%2$s resta com\'è, Fondamenta lo mostra ma non si modifica nell\'editor.', 'xb' ), $field['name'], $sub['name'] );
					}
				}
			}
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
			$id     = 'campi-' . str_replace( ':', '-', $target );
			$args   = array(
				/* translators: %s: content type, taxonomy or users */
				'name'              => sprintf( __( 'Campi: %s', 'xb' ), $target ),
				'object_type'       => 'post',
				'allowed_post_type' => array(),
				'allowed_tax'       => array(),
			);
			if ( 'user' === $target ) {
				$args['object_type'] = 'user';
			} elseif ( str_starts_with( $target, 'term:' ) ) {
				$args['object_type'] = 'taxonomy';
				$args['allowed_tax'] = array( substr( $target, 5 ) );
			} else {
				$args['allowed_post_type'] = array( $target );
			}
			$boxes[ $id ] = array(
				'args'        => $args,
				'meta_fields' => array_values( array_filter( array_map( array( self::class, 'field' ), $fields ) ) ),
				'id'          => $id,
			);
		}
		return array() === $boxes ? array() : array( 'campi/jet-engine.json' => (string) wp_json_encode( $boxes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	public function functions(): string {
		return "\n// The site's fields, for JetEngine (campi/jet-engine.json): added to its meta boxes until it saves them itself.\n"
			. "\$sito_jet_engine = static function ( mixed \$boxes ): array {\n\t\$boxes = is_array( \$boxes ) ? \$boxes : array();\n\t\$own   = json_decode( (string) file_get_contents( __DIR__ . '/campi/jet-engine.json' ), true );\n\treturn is_array( \$own ) ? \$boxes + \$own : \$boxes;\n};\n"
			. "add_filter( 'option_jet_engine_meta_boxes', \$sito_jet_engine );\nadd_filter( 'default_option_jet_engine_meta_boxes', \$sito_jet_engine );\n";
	}

	public function values( string $target, array $fields, callable $read, array $keys ): array {
		unset( $target, $keys );
		$set = array();
		foreach ( $fields as $field ) {
			$name         = (string) $field['name'];
			$set[ $name ] = self::value( $field, $read( $name ) );
		}
		return array(
			'set'    => $set,
			'delete' => array(),
		);
	}

	/** @param array<string, mixed> $field */
	private static function value( array $field, mixed $raw ): mixed {
		$type = (string) $field['type'];
		if ( 'repeater' === $type || ( ! empty( $field['multiple'] ) && ! in_array( $type, array( 'select', 'post', 'image' ), true ) ) ) {
			$subs = 'repeater' === $type ? $field['sub'] : array( array( 'name' => 'valore', 'multiple' => false ) + $field );
			$rows = array();
			foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $i => $row ) {
				$row  = 'repeater' === $type ? ( is_array( $row ) ? $row : array() ) : array( 'valore' => $row );
				$item = array();
				foreach ( $subs as $sub ) {
					// A repeater in the row stays as it is.
					$item[ (string) $sub['name'] ] = 'repeater' === $sub['type'] ? ( $row[ $sub['name'] ] ?? array() ) : self::value( $sub, $row[ $sub['name'] ] ?? null );
				}
				$rows[ 'item-' . $i ] = $item;
			}
			return $rows;
		}
		if ( ! empty( $field['multiple'] ) ) {
			$list = array_values( array_map( 'strval', array_filter( is_array( $raw ) ? $raw : array(), 'is_scalar' ) ) );
			return 'image' === $type ? implode( ',', $list ) : $list;
		}
		return match ( $type ) {
			'toggle' => in_array( $raw, array( true, 1, '1', 'true' ), true ) ? 'true' : 'false',
			'datetime' => self::local_minutes( is_scalar( $raw ) ? (string) $raw : '' ),
			default => is_scalar( $raw ) ? (string) $raw : '',
		};
	}

	/** ISO 8601 as JetEngine's date and time: `AAAA-MM-GGThh:mm` in the site's time. */
	private static function local_minutes( string $iso ): string {
		$local = Acf::local_time( $iso );
		return '' === $local ? '' : substr( str_replace( ' ', 'T', $local ), 0, 16 );
	}

	/**
	 * A field as JetEngine declares it; null for a repeater inside a row.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>|null
	 */
	private static function field( array $field, bool $in_row = false ): ?array {
		$type     = (string) $field['type'];
		$multiple = ! empty( $field['multiple'] );
		if ( $in_row && 'repeater' === $type ) {
			return null;
		}
		$base = array(
			'title'           => (string) ( $field['label'] ?? $field['name'] ),
			'name'            => (string) $field['name'],
			'object_type'     => 'field',
			'width'           => '100%',
			'description'     => (string) ( $field['help'] ?? '' ),
			'is_required'     => ! empty( $field['required'] ),
			'options'         => array(),
			'repeater-fields' => array(),
			'id'              => abs( crc32( 'xb-campi|' . (string) $field['name'] ) ) % 100000,
		);
		if ( 'repeater' === $type || ( $multiple && ! in_array( $type, array( 'select', 'post', 'image' ), true ) ) ) {
			$subs = 'repeater' === $type ? $field['sub'] : array( array( 'name' => 'valore', 'multiple' => false ) + $field );
			return array(
				'type'            => 'repeater',
				'repeater-fields' => array_values( array_filter( array_map( static fn ( array $sub ): ?array => self::field( $sub, true ), $subs ) ) ),
			) + $base;
		}
		return match ( $type ) {
			'textarea' => array( 'type' => 'textarea' ),
			'rich-text' => array( 'type' => 'wysiwyg' ),
			'number', 'integer', 'decimal' => array( 'type' => 'number', 'step_value' => 'integer' === $type ? '1' : 'any' ),
			'date' => array( 'type' => 'date', 'is_timestamp' => false ),
			'datetime' => array( 'type' => 'datetime-local', 'is_timestamp' => false ),
			'toggle' => array( 'type' => 'switcher' ),
			'select' => array(
				'type'        => 'select',
				'options'     => array_map( static fn ( array $o ): array => array( 'key' => $o['value'], 'value' => $o['label'] ), is_array( $field['options'] ?? null ) ? $field['options'] : array() ),
				'is_multiple' => $multiple,
			),
			'image' => array( 'type' => $multiple ? 'gallery' : 'media', 'value_format' => 'id' ),
			'post' => array(
				'type'             => 'posts',
				'search_post_type' => array( (string) ( $field['postType'] ?? 'post' ) ),
				'is_multiple'      => $multiple,
			),
			default => array( 'type' => 'text' ),
		} + $base;
	}
}
