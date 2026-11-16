<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * SureForms. A form is a post of its type made of its field blocks (`srfm/input`, `srfm/email`…), with its settings
 * in post meta: the button's text, after sending the page set in `xb_redirect` (`custom url`) or a thank-you message,
 * an email to the site administrator with every field (`{all_data}`). The messages Histeon received become entries of
 * its table, by the names SureForms gives the fields (`srfm-<tipo>-<blocco>-lbl-<etichetta>-<nome>`). The block is
 * `srfm/form`.
 *
 * SureForms keeps only the labels of choices: the value a visitor's choice sends is its label.
 */
final class SureForms implements Adapter {

	public function id(): string {
		return 'sureforms';
	}

	public function name(): string {
		return 'SureForms';
	}

	public function available(): bool {
		return post_type_exists( 'sureforms_form' ) && class_exists( \SRFM\Inc\Database\Tables\Entries::class );
	}

	public function notes( array $form ): array {
		$out = array();
		foreach ( $form['fields'] as $field ) {
			foreach ( $field['options'] as $option ) {
				if ( $option['value'] !== $option['label'] ) {
					/* translators: %s: field name */
					$out[] = sprintf( __( 'SureForms tiene solo le etichette delle scelte: il campo %s invierà l\'etichetta, non il valore.', 'xb' ), $field['name'] );
					break;
				}
			}
		}
		if ( $form['turnstile'] ) {
			$out[] = __( 'SureForms: Turnstile si accende nelle sue impostazioni (Sicurezza), con le chiavi del sito.', 'xb' );
		}
		return $out;
	}

	public function export( array $form ): array {
		$blocks = array();
		foreach ( $form['fields'] as $field ) {
			[ $name, $attrs ] = self::field( $field, $form['name'] );
			$blocks[]          = array(
				'blockName'    => 'srfm/' . $name,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		$confirmation = array(
			'id'                => 1,
			'confirmation_type' => '' !== $form['redirect'] ? 'custom url' : 'same page',
			'page_url'          => '',
			'custom_url'        => '' !== $form['redirect'] ? home_url( $form['redirect'] ) : '',
			'message'           => __( 'Grazie, il messaggio è arrivato.', 'xb' ),
			'submission_action' => 'hide form',
		);
		$email = array(
			'id'             => 1,
			'status'         => true,
			'is_raw_format'  => false,
			'name'           => __( 'Notifica all\'amministratore', 'xb' ),
			'email_to'       => '{admin_email}',
			'email_reply_to' => '',
			'from_name'      => '',
			'from_email'     => '',
			'email_cc'       => '',
			'email_bcc'      => '',
			/* translators: %s: form title */
			'subject'        => sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form['title'] ),
			'email_body'     => '{all_data}',
		);
		return array(
			'title'   => $form['title'],
			'content' => \XB\Blocks::serialize( $blocks ),
			'meta'    => array(
				'_srfm_submit_button_text'  => $form['submit'],
				'_srfm_form_confirmation'   => array( $confirmation ),
				'_srfm_email_notification'  => array( $email ),
				'_srfm_submit_type'         => 'message',
				'_srfm_form_recaptcha'      => 'none',
				'_srfm_single_page_form_title' => 0,
			),
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'SureForms non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data = $this->export( $form );
		$id   = wp_insert_post(
			array(
				'post_type'    => 'sureforms_form',
				'post_status'  => 'publish',
				'post_title'   => (string) $data['title'],
				'post_content' => (string) wp_slash( (string) $data['content'] ),
			),
			true
		);
		if ( $id instanceof \WP_Error ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		$defaults = class_exists( \SRFM\Inc\Create_New_Form::class ) ? \SRFM\Inc\Create_New_Form::get_default_meta_keys() : array();
		foreach ( (array) $data['meta'] + $defaults as $key => $value ) {
			update_post_meta( $id, (string) $key, $value );
		}
		// The names SureForms gives the fields, for the messages carried over.
		$keys = array();
		foreach ( $form['fields'] as $field ) {
			[ $type, $attrs ]          = self::field( $field, $form['name'] );
			$keys[ $field['name'] ] = 'srfm-' . $type . '-' . $attrs['block_id'] . '-lbl-' . rtrim( base64_encode( wp_strip_all_tags( (string) $field['label'] ) ), '=' ) . '-' . $field['name']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- as SureForms names its fields.
		}
		update_post_meta( $id, '_xb_form_keys', $keys );
		return $id;
	}

	public function block( int $id ): array {
		return array(
			'blockName'    => 'srfm/form',
			'attrs'        => array( 'id' => $id ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function messages( int $id, array $messages ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'SureForms non è attivo: i messaggi non si possono portare.', 'xb' ) );
		}
		$keys  = get_post_meta( $id, '_xb_form_keys', true );
		$keys  = is_array( $keys ) ? $keys : array();
		$count = 0;
		foreach ( $messages as $message ) {
			$data = array();
			foreach ( $message['fields'] as $name => $value ) {
				$data[ $keys[ $name ] ?? $name ] = $value;
			}
			$entry = \SRFM\Inc\Database\Tables\Entries::add(
				array(
					'form_id'    => $id,
					'form_data'  => $data,
					'status'     => 'read',
					'created_at' => $message['date'],
				)
			);
			if ( false !== $entry ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * A field as one of SureForms' blocks: its type and attributes.
	 *
	 * @param array<string, mixed> $field
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private static function field( array $field, string $form ): array {
		$type  = match ( $field['type'] ) {
			'email' => 'email',
			'url' => 'url',
			'tel' => 'phone',
			'number' => 'number',
			'textarea' => 'textarea',
			'select' => 'dropdown',
			'radio' => 'multi-choice',
			'checkbox' => 1 === count( $field['options'] ) ? 'checkbox' : 'multi-choice',
			default => 'input',
		};
		$attrs = array(
			'block_id'   => substr( md5( $form . '|' . $field['name'] ), 0, 8 ),
			'label'      => (string) $field['label'],
			'required'   => ! empty( $field['required'] ),
			'slug'       => (string) $field['name'],
			'fieldWidth' => 100,
		);
		if ( '' !== (string) $field['placeholder'] ) {
			$attrs['placeholder'] = (string) $field['placeholder'];
		}
		if ( 'dropdown' === $type ) {
			$attrs['options']     = array_map( static fn ( array $o ): array => array( 'label' => $o['label'], 'icon' => '' ), $field['options'] );
			$attrs['multiSelect'] = ! empty( $field['multiple'] );
		}
		if ( 'multi-choice' === $type ) {
			$attrs['options']         = array_map( static fn ( array $o ): array => array( 'optionTitle' => $o['label'], 'icon' => '', 'image' => '' ), $field['options'] );
			$attrs['singleSelection'] = 'radio' === $field['type'];
		}
		return array( $type, $attrs );
	}
}
