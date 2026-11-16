<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * Forminator. The form is made through Forminator's own API (`Forminator_API::add_form`): one field per row, each with
 * Forminator's id (`text-1`, `email-1`…) and Histeon's label, choices and «required»; Forminator adds its own email to
 * the site administrator. After sending: the page set in `xb_redirect`, or a thank-you message. The messages Histeon
 * received become entries (`add_form_entry`), by Forminator's field ids. The block is Forminator's shortcode in a core
 * Shortcode block (Forminator's own block is in its paid part).
 *
 * Forminator names the fields of the page `text-1`, `email-1`…, not as Histeon did: only a script of the site that
 * read those names would notice.
 */
final class Forminator implements Adapter {

	public function id(): string {
		return 'forminator';
	}

	public function name(): string {
		return 'Forminator';
	}

	public function available(): bool {
		return class_exists( \Forminator_API::class );
	}

	public function notes( array $form ): array {
		return $form['turnstile'] ? array( __( 'Forminator: Turnstile si aggiunge come campo del form, con le chiavi del sito nelle sue impostazioni.', 'xb' ) ) : array();
	}

	public function export( array $form ): array {
		$wrappers = array();
		foreach ( self::ids( $form ) as $name => $element ) {
			$field      = self::field_named( $form, $name );
			$wrappers[] = array(
				'wrapper_id' => 'wrapper-' . substr( md5( $form['name'] . '|' . $name ), 0, 13 ),
				'fields'     => array( self::field( $field, $element ) ),
			);
		}
		$settings = array(
			'formName'             => $form['title'],
			'form-type'            => 'default',
			'enable-ajax'          => 'true',
			'validation-inline'    => true,
			'submitData'           => array( 'custom-submit-text' => $form['submit'] ),
			'submission-behaviour' => '' !== $form['redirect'] ? 'behaviour-redirect' : 'behaviour-thankyou',
			'redirect-url'         => '' !== $form['redirect'] ? home_url( $form['redirect'] ) : '',
			'thankyou-message'     => __( 'Grazie, il messaggio è arrivato.', 'xb' ),
		);
		return array(
			'name'     => $form['title'],
			'wrappers' => $wrappers,
			'settings' => $settings,
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Forminator non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data = $this->export( $form );
		$id   = \Forminator_API::add_form( $data['name'], $data['wrappers'], $data['settings'] );
		if ( $id instanceof \WP_Error ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		update_post_meta( (int) $id, '_xb_form_ids', self::ids( $form ) );
		return (int) $id;
	}

	public function block( int $id ): array {
		$html = "\n" . '[forminator_form id="' . $id . '"]' . "\n";
		return array(
			'blockName'    => 'core/shortcode',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	public function messages( int $id, array $messages ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Forminator non è attivo: i messaggi non si possono portare.', 'xb' ) );
		}
		$ids   = get_post_meta( $id, '_xb_form_ids', true );
		$ids   = is_array( $ids ) ? $ids : array();
		$count = 0;
		foreach ( $messages as $message ) {
			$meta = array();
			foreach ( $message['fields'] as $name => $value ) {
				if ( isset( $ids[ $name ] ) ) {
					$meta[] = array(
						'name'  => (string) $ids[ $name ],
						'value' => $value,
					);
				}
			}
			$entry = \Forminator_API::add_form_entry( $id, $meta );
			if ( ! $entry instanceof \WP_Error ) {
				global $wpdb;
				// The date it was received, not today's.
				$wpdb->update( $wpdb->prefix . 'frmt_form_entry', array( 'date_created' => $message['date'] ), array( 'entry_id' => (int) $entry ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Forminator's id of each field (`text-1`, `email-1`…), numbered by type in the form's order.
	 *
	 * @param array<string, mixed> $form
	 * @return array<string, string>
	 */
	private static function ids( array $form ): array {
		$seen = array();
		$out  = array();
		foreach ( $form['fields'] as $field ) {
			$type                    = self::type( (string) $field['type'] );
			$seen[ $type ]           = ( $seen[ $type ] ?? 0 ) + 1;
			$out[ $field['name'] ] = $type . '-' . $seen[ $type ];
		}
		return $out;
	}

	private static function type( string $type ): string {
		return match ( $type ) {
			'email', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' => $type,
			'tel' => 'phone',
			default => 'text',
		};
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array<string, mixed>
	 */
	private static function field_named( array $form, string $name ): array {
		foreach ( $form['fields'] as $field ) {
			if ( $field['name'] === $name ) {
				return $field;
			}
		}
		return array();
	}

	/**
	 * A field as Forminator keeps it.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field( array $field, string $element ): array {
		$type = self::type( (string) $field['type'] );
		$out  = array(
			'element_id'  => $element,
			'type'        => $type,
			'cols'        => '12',
			'required'    => ! empty( $field['required'] ) ? 'true' : false,
			'field_label' => (string) $field['label'],
			'placeholder' => (string) $field['placeholder'],
		);
		if ( 'text' === $type ) {
			$out['input_type'] = 'line';
		}
		if ( 'textarea' === $type ) {
			$out['input_type'] = 'paragraph';
		}
		if ( 'email' === $type ) {
			$out['validation'] = true;
		}
		if ( 'date' === $type ) {
			$out['field_type']  = 'picker';
			$out['date_format'] = 'yy-mm-dd';
		}
		if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$out['value_type'] = 'select' === $type ? ( ! empty( $field['multiple'] ) ? 'multiselect' : 'single' ) : $type;
			$out['options']    = array_map( static fn ( array $o ): array => array( 'label' => $o['label'], 'value' => $o['value'] ), $field['options'] );
		}
		return $out;
	}
}
