<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/**
 * ACF and Secure Custom Fields: one format for both. The groups are local JSON in the site theme (`acf-json/`, which
 * both plugins read from the active theme by themselves); values are post meta with a reference `_campo` to the
 * field's key, dates as `Ymd`, yes/no as `1`/`0`, repeaters as rows (`campo` the number of rows, `campo_0_sotto` each
 * value). Fields with more plain values become a repeater of one field, `valore`, since ACF has no list of plain
 * values; more images a gallery, more contents a post object with more choices.
 *
 * ACF without Pro has no repeater and no gallery; Secure Custom Fields has both.
 *
 * Codeideal Open Fields (`openfields`) reads values the same way (rows `campo_0_sotto`, found by their keys) but keeps
 * dates as `AAAA-MM-GG` and a date and time as `AAAA-MM-GGThh:mm`, with no references. Its field groups live in its
 * own tables: the theme carries one file per group to import in its screen (version 0.4 imports neither where a group
 * shows nor the fields inside a repeater: those are set there by hand).
 */
final class Acf implements Adapter {

	public function __construct( private readonly string $id ) {
	}

	public function id(): string {
		return $this->id;
	}

	public function name(): string {
		return match ( $this->id ) {
			'scf' => 'Secure Custom Fields',
			'openfields' => 'Codeideal Open Fields',
			default => 'Advanced Custom Fields',
		};
	}

	public function notes( array $content ): array {
		if ( 'openfields' === $this->id ) {
			return array( __( 'Open Fields: importa i file di campi/openfields/ nel suo pannello, poi indica dove si mostra ogni gruppo e aggiungi i campi dei ripetitori (la sua importazione non li porta). I valori sono già al loro posto.', 'xb' ) );
		}
		if ( 'acf' !== $this->id ) {
			return array();
		}
		$pro = array();
		foreach ( $content['fields'] as $fields ) {
			foreach ( $fields as $field ) {
				if ( 'repeater' === $field['type'] || ( ! empty( $field['multiple'] ) && ! in_array( $field['type'], array( 'select', 'post' ), true ) ) ) {
					$pro[] = (string) $field['name'];
				}
			}
		}
		/* translators: %s: field names */
		return array() === $pro ? array() : array( sprintf( __( 'ACF senza Pro non ha ripetitori né gallerie: i campi %s chiedono ACF Pro, oppure Secure Custom Fields.', 'xb' ), implode( ', ', array_unique( $pro ) ) ) );
	}

	public function theme_files( array $content ): array {
		if ( 'openfields' === $this->id ) {
			return $this->open_fields_files( $content );
		}
		$out = array();
		foreach ( $content['fields'] as $target => $fields ) {
			if ( array() === $fields ) {
				continue;
			}
			$target = (string) $target;
			$group  = array(
				'key'                   => self::key( 'group', $target ),
				/* translators: %s: content type, taxonomy or users */
				'title'                 => sprintf( __( 'Campi: %s', 'xb' ), self::label( $target, $content ) ),
				'fields'                => array_map( fn ( array $field ): array => $this->field( $field, $target ), $fields ),
				'location'              => array( array( self::location( $target ) ) ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
				'active'                => true,
				'description'           => '',
				'show_in_rest'          => 0,
				'modified'              => 0,
			);
			$out[ 'acf-json/' . $group['key'] . '.json' ] = (string) wp_json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		return $out;
	}

	public function functions(): string {
		return '';
	}

	public function values( string $target, array $fields, callable $read, array $keys ): array {
		$set = array();
		foreach ( $fields as $field ) {
			$name = (string) $field['name'];
			$this->value( $field, $name, $read( $name ), $set, $target );
		}
		// Rows of an earlier conversion past the rows written now.
		$delete = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $set ) && 1 === preg_match( '/^_?([a-z][a-z0-9_]*?)_\d+_/', $key, $m ) && self::rows_of( $m[1], $fields ) ) {
				$delete[] = $key;
			}
		}
		return array(
			'set'    => $set,
			'delete' => $delete,
		);
	}

	/**
	 * One value in ACF's form, with its reference; a repeater's rows each with theirs.
	 *
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $set
	 */
	private function value( array $field, string $key, mixed $raw, array &$set, string $path ): void {
		$own = $path . '/' . (string) $field['name'];
		if ( 'openfields' !== $this->id ) {
			$set[ '_' . $key ] = self::key( 'field', $own );
		}
		$type              = (string) $field['type'];
		$rows              = self::as_rows( $field );
		if ( null !== $rows ) {
			$list        = is_array( $raw ) ? array_values( $raw ) : array();
			// No rows: an empty value, as ACF saves it.
			$set[ $key ] = array() === $list ? '' : (string) count( $list );
			foreach ( $list as $i => $row ) {
				$row = 'repeater' === $type ? ( is_array( $row ) ? $row : array() ) : array( 'valore' => $row );
				foreach ( $rows as $sub ) {
					$this->value( $sub, $key . '_' . $i . '_' . $sub['name'], $row[ $sub['name'] ] ?? null, $set, $own );
				}
			}
			return;
		}
		$set[ $key ] = match ( true ) {
			is_array( $raw ) => array_values( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) ),
			'date' === $type && 'openfields' === $this->id => is_scalar( $raw ) ? (string) $raw : '',
			'date' === $type => 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $raw, $m ) ? $m[1] . $m[2] . $m[3] : '',
			'datetime' === $type && 'openfields' === $this->id => substr( str_replace( ' ', 'T', self::local_time( (string) ( is_scalar( $raw ) ? $raw : '' ) ) ), 0, 16 ),
			'datetime' === $type => self::local_time( (string) ( is_scalar( $raw ) ? $raw : '' ) ),
			'toggle' === $type => in_array( $raw, array( true, 1, '1', 'true' ), true ) ? '1' : '0',
			default => is_scalar( $raw ) ? (string) $raw : '',
		};
	}

	/**
	 * The fields of a row when a field is stored as rows: a repeater's, or `valore` for more plain values.
	 *
	 * @param array<string, mixed> $field
	 * @return list<array<string, mixed>>|null
	 */
	private static function as_rows( array $field ): ?array {
		if ( 'repeater' === $field['type'] ) {
			return array_values( array_filter( is_array( $field['sub'] ?? null ) ? $field['sub'] : array(), 'is_array' ) );
		}
		if ( ! empty( $field['multiple'] ) && ! in_array( $field['type'], array( 'image', 'post', 'select' ), true ) ) {
			return array( array( 'name' => 'valore', 'type' => $field['type'], 'multiple' => false ) + $field );
		}
		return null;
	}

	/** @param list<array<string, mixed>> $fields */
	private static function rows_of( string $name, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( str_starts_with( $name, (string) $field['name'] ) && null !== self::as_rows( $field ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Open Fields: one file per group, in the form its screen imports (`{"fieldset": …}`), with where it shows as its
	 * screen keeps it; the fields of a repeater are not in the file (its import cannot link them).
	 *
	 * @param array{types: array<string, mixed>, taxonomies: array<string, mixed>, fields: array<string, mixed>} $content
	 * @return array<string, string>
	 */
	private function open_fields_files( array $content ): array {
		$out = array();
		foreach ( $content['fields'] as $target => $fields ) {
			if ( ! is_array( $fields ) || array() === $fields ) {
				continue;
			}
			$target   = (string) $target;
			$location = self::location( $target );
			$rows     = array();
			foreach ( $fields as $i => $field ) {
				$acf    = $this->field( $field, $target );
				$type   = match ( $acf['type'] ) {
					'true_false' => 'switch',
					'date_picker' => 'date',
					'date_time_picker' => 'datetime',
					default => $acf['type'],
				};
				$rows[] = array(
					'label'        => $acf['label'],
					'name'         => $acf['name'],
					'type'         => $type,
					'instructions' => $acf['instructions'],
					'required'     => $acf['required'],
					'menu_order'   => $i,
					'field_config' => array_diff_key( $acf, array_flip( array( 'key', 'label', 'name', 'type', 'instructions', 'required', 'sub_fields' ) ) ),
				);
			}
			$out[ 'campi/openfields/' . str_replace( ':', '-', $target ) . '.json' ] = (string) wp_json_encode(
				array(
					'fieldset' => array(
						/* translators: %s: content type, taxonomy or users */
						'title'           => sprintf( __( 'Campi: %s', 'xb' ), self::label( $target, $content ) ),
						'status'          => 'active',
						'fields'          => $rows,
						'location_groups' => array(
							array(
								'id'    => '1',
								'rules' => array(
									array(
										'type'     => $location['param'],
										'operator' => '==',
										'value'    => $location['value'],
									),
								),
							),
						),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) . "\n";
		}
		return $out;
	}

	/** An ISO 8601 date and time as ACF keeps it: the site's local time, `AAAA-MM-GG hh:mm:ss`. */
	public static function local_time( string $iso ): string {
		try {
			return '' === $iso ? '' : ( new \DateTimeImmutable( $iso ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * A field as ACF declares it.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private function field( array $field, string $path ): array {
		$own  = $path . '/' . (string) $field['name'];
		$type = (string) $field['type'];
		$base = array(
			'key'          => self::key( 'field', $own ),
			'label'        => (string) ( $field['label'] ?? $field['name'] ),
			'name'         => (string) $field['name'],
			'type'         => 'text',
			'instructions' => (string) ( $field['help'] ?? '' ),
			'required'     => ! empty( $field['required'] ) ? 1 : 0,
		);
		$rows = self::as_rows( $field );
		if ( null !== $rows ) {
			return array(
				'type'         => 'repeater',
				'layout'       => 'block',
				'button_label' => __( 'Aggiungi riga', 'xb' ),
				'sub_fields'   => array_map( fn ( array $sub ): array => $this->field( $sub, $own ), $rows ),
			) + $base;
		}
		$multiple = ! empty( $field['multiple'] );
		return match ( $type ) {
			'textarea' => array( 'type' => 'textarea', 'new_lines' => '' ) + $base,
			'rich-text' => array( 'type' => 'wysiwyg', 'tabs' => 'all', 'toolbar' => 'full', 'media_upload' => 1 ) + $base,
			'number', 'decimal' => array( 'type' => 'number' ) + $base,
			'integer' => array( 'type' => 'number', 'step' => 1 ) + $base,
			'url', 'email' => array( 'type' => $type ) + $base,
			'date' => array( 'type' => 'date_picker', 'display_format' => 'd/m/Y', 'return_format' => 'Y-m-d', 'first_day' => 1 ) + $base,
			'datetime' => array( 'type' => 'date_time_picker', 'display_format' => 'd/m/Y H:i', 'return_format' => 'Y-m-d H:i:s', 'first_day' => 1 ) + $base,
			'toggle' => array( 'type' => 'true_false', 'ui' => 1 ) + $base,
			'time' => array( 'type' => 'time_picker', 'display_format' => 'H:i', 'return_format' => 'H:i' ) + $base,
			'file' => array( 'type' => 'file', 'return_format' => 'id', 'library' => 'all' ) + $base,
			'select' => ! empty( $field['radio'] ) ? array(
				'type'          => 'radio',
				'choices'       => array_column( is_array( $field['options'] ?? null ) ? $field['options'] : array(), 'label', 'value' ),
				'allow_null'    => 1,
				'return_format' => 'value',
			) + $base : array(
				'type'          => 'select',
				'choices'       => array_column( is_array( $field['options'] ?? null ) ? $field['options'] : array(), 'label', 'value' ),
				'multiple'      => $multiple ? 1 : 0,
				'allow_null'    => 1,
				'return_format' => 'value',
			) + $base,
			'image' => array(
				'type'          => $multiple ? 'gallery' : 'image',
				'return_format' => 'id',
				'library'       => 'all',
				'preview_size'  => 'medium',
			) + $base,
			'post' => array(
				'type'          => 'post_object',
				'post_type'     => array( (string) ( $field['postType'] ?? 'post' ) ),
				'multiple'      => $multiple ? 1 : 0,
				'allow_null'    => 1,
				'return_format' => 'id',
			) + $base,
			default => $base,
		};
	}

	/** A key ACF accepts (`field_` or `group_` and 13 characters), the same at every export. */
	private static function key( string $kind, string $path ): string {
		return $kind . '_' . substr( md5( 'xb-campi|' . $path ), 0, 13 );
	}

	/**
	 * Where a group shows: a content type, the terms of a taxonomy, or users.
	 *
	 * @return array{param: string, operator: string, value: string}
	 */
	private static function location( string $target ): array {
		if ( 'user' === $target ) {
			return array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' );
		}
		if ( str_starts_with( $target, 'term:' ) ) {
			return array( 'param' => 'taxonomy', 'operator' => '==', 'value' => substr( $target, 5 ) );
		}
		return array( 'param' => 'post_type', 'operator' => '==', 'value' => $target );
	}

	/** @param array{types: array<string, mixed>, taxonomies: array<string, mixed>, fields: array<string, mixed>} $content */
	private static function label( string $target, array $content ): string {
		if ( 'user' === $target ) {
			return __( 'utenti', 'xb' );
		}
		if ( str_starts_with( $target, 'term:' ) ) {
			$tax = substr( $target, 5 );
			return (string) ( $content['taxonomies'][ $tax ]['plural'] ?? get_taxonomy( $tax )->label ?? $tax );
		}
		$type = get_post_type_object( $target );
		return (string) ( $content['types'][ $target ]['plural'] ?? ( null !== $type ? $type->label : $target ) );
	}
}
