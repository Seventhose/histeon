<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * Ninja Forms. The form is made with its own models (`Ninja_Forms()->form()`), as its import does, but with every
 * field's settings saved at once (its import leaves them to a background task): fields by Histeon's names as keys,
 * then the actions «Record Submission», an email to the site administrator with every field, and the page set in
 * `xb_redirect` or a thank-you message. The messages Histeon received become its submissions (`nf_sub`), with their
 * dates. The block is `ninja-forms/form` (its attribute is `formID`).
 */
final class NinjaForms implements Adapter {

	public function id(): string {
		return 'ninjaforms';
	}

	public function name(): string {
		return 'Ninja Forms';
	}

	public function available(): bool {
		return function_exists( 'Ninja_Forms' ) && class_exists( \WPN_Helper::class );
	}

	public function notes( array $form ): array {
		return $form['turnstile'] ? array( __( 'Ninja Forms: Turnstile si aggiunge come campo del form, con le chiavi del sito nelle sue impostazioni.', 'xb' ) ) : array();
	}

	public function export( array $form ): array {
		$fields = array();
		foreach ( $form['fields'] as $i => $field ) {
			$fields[] = self::field( $field, $i + 1 );
		}
		$fields[] = array(
			'type'             => 'submit',
			'key'              => 'invia',
			'label'            => $form['submit'],
			'processing_label' => '…',
			'order'            => (string) ( count( $fields ) + 1 ),
		);
		$actions = array(
			array(
				'type'   => 'save',
				'label'  => __( 'Salva la risposta', 'xb' ),
				'active' => '1',
			),
			array(
				'type'          => 'email',
				'label'         => __( 'Notifica all\'amministratore', 'xb' ),
				'active'        => '1',
				'to'            => '{system:admin_email}',
				/* translators: %s: form title */
				'email_subject' => sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form['title'] ),
				'email_message' => '<p>{all_fields_table}</p>',
				'email_format'  => 'html',
			),
			'' !== $form['redirect']
				? array(
					'type'         => 'redirect',
					'label'        => __( 'Pagina dopo l\'invio', 'xb' ),
					'active'       => '1',
					'redirect_url' => home_url( $form['redirect'] ),
				)
				: array(
					'type'    => 'successmessage',
					'label'   => __( 'Messaggio dopo l\'invio', 'xb' ),
					'active'  => '1',
					'message' => __( 'Grazie, il messaggio è arrivato.', 'xb' ),
				),
		);
		return array(
			'settings' => array(
				'title'             => $form['title'],
				'default_label_pos' => 'above',
				'show_title'        => '0',
				'clear_complete'    => '1',
				'hide_complete'     => '1',
				'add_submit'        => '0',
			),
			'fields'   => $fields,
			'actions'  => $actions,
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Ninja Forms non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data  = $this->export( $form );
		$model = Ninja_Forms()->form()->get();
		$model->update_settings( $data['settings'] );
		$model->update_setting( 'created_at', current_time( 'mysql' ) );
		$model->save();
		$id = (int) $model->get_id();
		foreach ( $data['fields'] as $settings ) {
			Ninja_Forms()->form( $id )->field()->get()->update_settings( $settings + array( 'parent_id' => $id, 'label_pos' => 'above', 'created_at' => current_time( 'mysql' ) ) )->save();
		}
		foreach ( $data['actions'] as $settings ) {
			Ninja_Forms()->form( $id )->action()->get()->update_settings( $settings + array( 'created_at' => current_time( 'mysql' ) ) )->save();
		}
		\WPN_Helper::build_nf_cache( $id );
		return $id;
	}

	public function block( int $id ): array {
		return array(
			'blockName'    => 'ninja-forms/form',
			'attrs'        => array( 'formID' => $id ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function messages( int $id, array $messages ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Ninja Forms non è attivo: i messaggi non si possono portare.', 'xb' ) );
		}
		$count = 0;
		foreach ( $messages as $message ) {
			$sub = Ninja_Forms()->form( $id )->sub()->get();
			foreach ( $message['fields'] as $name => $value ) {
				$sub->update_field_value( $name, $value );
			}
			$sub->save();
			$post = (int) $sub->get_id();
			if ( $post > 0 ) {
				wp_update_post(
					array(
						'ID'            => $post,
						'post_date'     => $message['date'],
						'post_date_gmt' => get_gmt_from_date( $message['date'] ),
					)
				);
				++$count;
			}
		}
		return $count;
	}

	/**
	 * A field as Ninja Forms keeps it.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field( array $field, int $order ): array {
		$type = match ( $field['type'] ) {
			'email' => 'email',
			'tel' => 'phone',
			'number' => 'number',
			'date' => 'date',
			'textarea' => 'textarea',
			'select' => ! empty( $field['multiple'] ) ? 'listmultiselect' : 'listselect',
			'radio' => 'listradio',
			'checkbox' => 1 === count( $field['options'] ) ? 'checkbox' : 'listcheckbox',
			default => 'textbox',
		};
		$out  = array(
			'type'        => $type,
			'key'         => (string) $field['name'],
			'label'       => (string) $field['label'],
			'required'    => ! empty( $field['required'] ) ? '1' : '',
			'placeholder' => (string) $field['placeholder'],
			'order'       => (string) $order,
		);
		if ( 'checkbox' === $type ) {
			$out['checked_value']   = (string) ( $field['options'][0]['value'] ?? 'si' );
			$out['unchecked_value'] = '';
		} elseif ( str_starts_with( $type, 'list' ) ) {
			$out['options'] = array();
			foreach ( array_values( $field['options'] ) as $i => $option ) {
				$out['options'][] = array(
					'label'    => $option['label'],
					'value'    => $option['value'],
					'calc'     => '',
					'selected' => 0,
					'order'    => $i,
				);
			}
		}
		return $out;
	}
}
