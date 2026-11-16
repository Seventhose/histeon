<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * Fluent Forms (doc 25: the form plugin chosen on 22 October). Each field is made from Fluent Forms' own template for
 * its element (DefaultElements), with the name, label, choices and «required» of Histeon's field: the plugin then
 * validates and keeps the entries. After sending: the page set in `xb_redirect`, or the form's thank-you message.
 * The site administrator gets an email with every field, as from Histeon. Turnstile is Fluent Forms' own setting.
 */
final class FluentForms implements Adapter {

	public function id(): string {
		return 'fluentforms';
	}

	public function name(): string {
		return 'Fluent Forms';
	}

	public function available(): bool {
		return class_exists( \FluentForm\App\Models\Form::class ) && function_exists( 'fluentformLoadFile' );
	}

	public function notes( array $form ): array {
		return $form['turnstile'] ? array( __( 'Fluent Forms: il controllo Turnstile si accende nelle sue impostazioni (Integrazioni → Turnstile), con le chiavi del sito.', 'xb' ) ) : array();
	}

	public function export( array $form ): array {
		$fields = array();
		foreach ( $form['fields'] as $i => $field ) {
			$fields[] = $this->field( $field, $i );
		}
		$fields_json = array(
			'fields'       => $fields,
			'submitButton' => array(
				'uniqElKey'      => 'el_' . substr( md5( $form['name'] . '|submit' ), 0, 13 ),
				'element'        => 'button',
				'attributes'     => array( 'type' => 'submit', 'class' => '' ),
				'settings'       => array(
					'align'           => 'left',
					'button_style'    => 'default',
					'container_class' => '',
					'help_message'    => '',
					'button_size'     => 'md',
					'button_ui'       => array( 'type' => 'default', 'text' => $form['submit'], 'img_url' => '' ),
				),
				'editor_options' => array( 'title' => 'Submit Button' ),
			),
		);
		$confirmation = '' !== $form['redirect']
			? array( 'redirectTo' => 'customUrl', 'customUrl' => home_url( $form['redirect'] ), 'messageToShow' => '', 'customPage' => null, 'samePageFormBehavior' => 'hide_form' )
			: array( 'redirectTo' => 'samePage', 'messageToShow' => __( 'Grazie, il messaggio è arrivato.', 'xb' ), 'customPage' => null, 'samePageFormBehavior' => 'hide_form', 'customUrl' => null );
		return array(
			'title'       => $form['title'],
			'status'      => 'published',
			'type'        => 'form',
			'has_payment' => 0,
			'form_fields' => $fields_json,
			'metas'       => array(
				array(
					'meta_key' => 'formSettings',
					'value'    => array(
						'confirmation' => $confirmation,
						'layout'       => array( 'labelPlacement' => 'top', 'helpMessagePlacement' => 'with_label', 'errorMessagePlacement' => 'inline', 'cssClassName' => '', 'asteriskPlacement' => 'asterisk-right' ),
					),
				),
				array(
					'meta_key' => 'notifications',
					'value'    => array(
						'name'         => __( 'Notifica all\'amministratore', 'xb' ),
						'sendTo'       => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ),
						'fromName'     => '',
						'fromEmail'    => '',
						'replyTo'      => '',
						'bcc'          => '',
						/* translators: %s: form title */
						'subject'      => sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form['title'] ),
						'message'      => '<p>{all_data}</p>',
						'conditionals' => array( 'status' => false, 'type' => 'all', 'conditions' => array() ),
						'enabled'      => true,
						'email_template' => '',
					),
				),
			),
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Fluent Forms non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data = $this->export( $form );
		$id   = (int) \FluentForm\App\Models\Form::insertGetId(
			array(
				'title'       => $data['title'],
				'form_fields' => (string) wp_json_encode( $data['form_fields'] ),
				'status'      => 'published',
				'has_payment' => 0,
				'type'        => 'form',
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
		foreach ( $data['metas'] as $meta ) {
			\FluentForm\App\Models\FormMeta::insert(
				array(
					'form_id'  => $id,
					'meta_key' => $meta['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery
					'value'    => (string) wp_json_encode( $meta['value'] ),
				)
			);
		}
		return $id;
	}

	public function block( int $id ): array {
		$attrs = array( 'formId' => (string) $id );
		return array(
			'blockName'    => 'fluentfom/guten-block',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function messages( int $id, array $messages ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'Fluent Forms non è attivo: i messaggi non si possono portare.', 'xb' ) );
		}
		$serial = (int) \FluentForm\App\Models\Submission::where( 'form_id', $id )->max( 'serial_number' );
		$count  = 0;
		foreach ( $messages as $message ) {
			$entry = (int) \FluentForm\App\Models\Submission::insertGetId(
				array(
					'form_id'       => $id,
					'serial_number' => ++$serial,
					'response'      => (string) wp_json_encode( $message['fields'], JSON_UNESCAPED_UNICODE ),
					'source_url'    => '',
					'user_id'       => 0,
					'status'        => 'read',
					'created_at'    => $message['date'],
					'updated_at'    => $message['date'],
				)
			);
			( new \FluentForm\App\Services\Submission\SubmissionService() )->recordEntryDetails( $entry, $id, $message['fields'] );
			++$count;
		}
		return $count;
	}

	/**
	 * A field as Fluent Forms keeps it: its own template for the element when it is active, filled with Histeon's
	 * field; a minimal element otherwise (shown by `--forms`).
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private function field( array $field, int $index ): array {
		$element = match ( $field['type'] ) {
			'email' => 'input_email',
			'url' => 'input_url',
			'number' => 'input_number',
			'date' => 'input_date',
			'textarea' => 'textarea',
			'select' => 'select',
			'radio' => 'input_radio',
			'checkbox' => 'input_checkbox',
			default => 'input_text',
		};
		$template = $this->template( $element ) ?? array(
			'element'    => $element,
			'attributes' => array(),
			'settings'   => array( 'validation_rules' => array( 'required' => array( 'value' => false, 'message' => '' ) ) ),
		);
		$template['index']                    = $index;
		$template['uniqElKey']                = 'el_' . substr( md5( $field['name'] ), 0, 13 );
		$template['attributes']['name']       = $field['name'];
		$template['attributes']['placeholder'] = $field['placeholder'];
		if ( 'tel' === $field['type'] ) {
			$template['attributes']['type'] = 'tel';
		}
		if ( in_array( $element, array( 'input_checkbox' ), true ) ) {
			$template['attributes']['value'] = array();
		} else {
			$template['attributes']['value'] = '';
		}
		if ( 'select' === $element && ! empty( $field['multiple'] ) ) {
			$template['attributes']['multiple'] = true;
		}
		$template['settings']['label']                              = $field['label'];
		$template['settings']['validation_rules']['required']['value'] = (bool) $field['required'];
		if ( '' === (string) ( $template['settings']['validation_rules']['required']['message'] ?? '' ) ) {
			$template['settings']['validation_rules']['required']['message'] = __( 'Questo campo è obbligatorio', 'xb' );
		}
		if ( in_array( $element, array( 'select', 'input_radio', 'input_checkbox' ), true ) ) {
			$template['settings']['advanced_options'] = array_map(
				static fn ( array $o ): array => array(
					'label'      => $o['label'],
					'value'      => $o['value'],
					'calc_value' => '',
				),
				$field['options']
			);
		}
		return $template;
	}

	/**
	 * Fluent Forms' template for an element, when it is active.
	 *
	 * @return array<string, mixed>|null
	 */
	private function template( string $element ): ?array {
		static $all = null;
		if ( ! $this->available() ) {
			return null;
		}
		$all ??= fluentformLoadFile( 'Services/FormBuilder/DefaultElements.php' );
		foreach ( is_array( $all ) ? $all : array() as $group ) {
			if ( is_array( $group ) && is_array( $group[ $element ] ?? null ) ) {
				return $group[ $element ];
			}
		}
		return null;
	}
}
