<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * Contact Form 7. The form is written as Contact Form 7 writes it, text with tags (`<label> Nome [text* nome] </label>`),
 * with choices as «label|value» (its pipes: the page shows the label, the email gets the value); the email goes to the
 * site administrator with every field. The block is the one Contact Form 7's editor saves: its shortcode, by the
 * form's hash.
 *
 * Contact Form 7 keeps no messages (Flamingo, a separate plugin, does) and stays on the page after sending: the
 * report says so; the messages Histeon received are not carried.
 */
final class ContactForm7 implements Adapter {

	public function id(): string {
		return 'cf7';
	}

	public function name(): string {
		return 'Contact Form 7';
	}

	public function available(): bool {
		return class_exists( \WPCF7_ContactForm::class );
	}

	public function notes( array $form ): array {
		$out = array( __( 'Contact Form 7 non archivia i messaggi: servono Flamingo o un\'aggiunta simile. I messaggi già ricevuti da Histeon non si portano: esportali prima da «Messaggi dai form».', 'xb' ) );
		if ( '' !== $form['redirect'] ) {
			/* translators: %s: page */
			$out[] = sprintf( __( 'Contact Form 7 resta sulla pagina dopo l\'invio e mostra il suo messaggio: la pagina %s non si apre.', 'xb' ), $form['redirect'] );
		}
		if ( $form['turnstile'] ) {
			$out[] = __( 'Contact Form 7: Turnstile si accende in Contatti → Integrazione, con le chiavi del sito.', 'xb' );
		}
		return $out;
	}

	public function export( array $form ): array {
		$lines = array();
		foreach ( $form['fields'] as $field ) {
			$lines[] = '<label> ' . self::quote_free( $field['label'] ) . "\n    " . self::tag( $field ) . ' </label>';
		}
		$lines[] = '[submit "' . self::quote_free( $form['submit'] ) . '"]';
		$body    = '';
		foreach ( $form['fields'] as $field ) {
			$body .= $field['label'] . ': [' . $field['name'] . "]\n";
		}
		return array(
			'title' => $form['title'],
			'form'  => implode( "\n\n", $lines ),
			'mail'  => array(
				'recipient' => '[_site_admin_email]',
				/* translators: %s: form title */
				'subject'   => sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form['title'] ),
				'body'      => $body,
			),
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Contact Form 7 non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data    = $this->export( $form );
		$contact = \WPCF7_ContactForm::get_template( array( 'title' => $data['title'] ) );
		$mail    = $contact->prop( 'mail' );
		$contact->set_properties(
			array(
				'form' => $data['form'],
				'mail' => array_merge( is_array( $mail ) ? $mail : array(), $data['mail'] ),
			)
		);
		return (int) $contact->save();
	}

	public function block( int $id ): array {
		$contact = $this->available() ? \WPCF7_ContactForm::get_instance( $id ) : null;
		$hash    = null !== $contact ? (string) $contact->hash() : (string) $id;
		$title   = null !== $contact ? (string) $contact->title() : '';
		$html    = '<div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="' . esc_attr( $hash ) . '" title="' . esc_attr( $title ) . '"]</div>';
		return array(
			'blockName'    => 'contact-form-7/contact-form-selector',
			'attrs'        => array(
				'id'    => $id,
				'hash'  => $hash,
				'title' => $title,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	public function messages( int $id, array $messages ): int {
		unset( $id, $messages );
		return 0;
	}

	/**
	 * A field as a Contact Form 7 tag.
	 *
	 * @param array<string, mixed> $field
	 */
	private static function tag( array $field ): string {
		$type     = match ( $field['type'] ) {
			'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' => $field['type'],
			default => 'text',
		};
		$required = ! empty( $field['required'] ) && 'radio' !== $type ? '*' : '';
		$parts    = array( $type . $required, (string) $field['name'] );
		if ( 'select' === $type && ! empty( $field['multiple'] ) ) {
			$parts[] = 'multiple';
		}
		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			$parts[] = 'use_label_element';
		}
		if ( 'radio' === $type ) {
			$parts[] = 'default:1';
		}
		if ( '' !== (string) $field['placeholder'] && ! in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$parts[] = 'placeholder "' . self::quote_free( (string) $field['placeholder'] ) . '"';
		}
		foreach ( $field['options'] as $option ) {
			$parts[] = '"' . self::quote_free( $option['label'] ) . ( $option['label'] !== $option['value'] ? '|' . self::quote_free( $option['value'] ) : '' ) . '"';
		}
		return '[' . implode( ' ', $parts ) . ']';
	}

	/** Text for inside a tag: no double quotes, brackets or pipes, which the tag syntax uses. */
	private static function quote_free( string $text ): string {
		return str_replace( array( '"', '[', ']', '|' ), array( '”', '(', ')', '/' ), $text );
	}
}
