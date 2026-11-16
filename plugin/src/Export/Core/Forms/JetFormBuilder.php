<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * JetFormBuilder. A form is a post of its type made of its field blocks (`jet-forms/text-field`…, choices in
 * `field_options`), with the actions after sending in `_jf_actions`: an email to the site administrator with every
 * field, the entry saved («Save Form Record»), and the page set in `xb_redirect`. The messages Histeon received
 * become records (its `jet_fb_records` tables), with their dates. The block is `jet-forms/form-block`.
 */
final class JetFormBuilder implements Adapter {

	public function id(): string {
		return 'jetformbuilder';
	}

	public function name(): string {
		return 'JetFormBuilder';
	}

	public function available(): bool {
		return function_exists( 'jet_form_builder' ) && post_type_exists( 'jet-form-builder' );
	}

	public function notes( array $form ): array {
		return $form['turnstile'] ? array( __( 'JetFormBuilder: Turnstile si accende nelle sue impostazioni (Captcha), con le chiavi del sito.', 'xb' ) ) : array();
	}

	public function export( array $form ): array {
		$blocks = array();
		foreach ( $form['fields'] as $field ) {
			$blocks[] = self::field( $field );
		}
		$blocks[] = array(
			'blockName'    => 'jet-forms/submit-field',
			'attrs'        => array( 'label' => $form['submit'] ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$content = '';
		foreach ( $form['fields'] as $field ) {
			$content .= $field['label'] . ': %' . $field['name'] . "%\n";
		}
		$actions = array(
			array(
				'id'       => 1,
				'type'     => 'send_email',
				'settings' => array(
					'mail_to' => 'admin',
					/* translators: %s: form title */
					'subject' => sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form['title'] ),
					'content' => $content,
				),
			),
			array(
				'id'       => 2,
				'type'     => 'save_record',
				'settings' => array(),
			),
		);
		if ( '' !== $form['redirect'] ) {
			$actions[] = array(
				'id'       => 3,
				'type'     => 'redirect_to_page',
				'settings' => array(
					'redirect_type' => 'custom_url',
					'redirect_url'  => home_url( $form['redirect'] ),
				),
			);
		}
		return array(
			'title'   => $form['title'],
			'content' => \XB\Blocks::serialize( $blocks ),
			'actions' => $actions,
		);
	}

	public function create( array $form ): int {
		if ( ! $this->available() ) {
			throw new \RuntimeException( __( 'JetFormBuilder non è attivo: il form non si può creare.', 'xb' ) );
		}
		$data = $this->export( $form );
		$id   = wp_insert_post(
			array(
				'post_type'    => 'jet-form-builder',
				'post_status'  => 'publish',
				'post_title'   => (string) $data['title'],
				'post_content' => (string) wp_slash( (string) $data['content'] ),
			),
			true
		);
		if ( $id instanceof \WP_Error ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		update_post_meta( $id, '_jf_actions', wp_slash( (string) wp_json_encode( $data['actions'] ) ) );
		return $id;
	}

	public function block( int $id ): array {
		return array(
			'blockName'    => 'jet-forms/form-block',
			'attrs'        => array( 'form_id' => $id ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function messages( int $id, array $messages ): int {
		if ( ! $this->available() || ! class_exists( \JFB_Modules\Form_Record\Models\Record_Model::class ) ) {
			throw new \RuntimeException( __( 'JetFormBuilder non è attivo: i messaggi non si possono portare.', 'xb' ) );
		}
		$count = 0;
		foreach ( $messages as $message ) {
			$record = (int) ( new \JFB_Modules\Form_Record\Models\Record_Model() )->insert(
				array(
					'form_id'           => $id,
					'user_id'           => 0,
					'from_content_id'   => 0,
					'from_content_type' => '',
					'referrer'          => '',
					'submit_type'       => 'reload',
					'status'            => 'success',
					'is_viewed'         => 1,
					'created_at'        => $message['date'],
					'updated_at'        => $message['date'],
				)
			);
			foreach ( $message['fields'] as $name => $value ) {
				( new \JFB_Modules\Form_Record\Models\Record_Field_Model() )->insert(
					array(
						'record_id'   => $record,
						'field_name'  => $name,
						'field_value' => $value,
						'field_type'  => 'text-field',
						'field_attrs' => '{}',
					)
				);
			}
			++$count;
		}
		return $count;
	}

	/**
	 * A field as one of JetFormBuilder's blocks.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field( array $field ): array {
		$name  = match ( $field['type'] ) {
			'textarea' => 'jet-forms/textarea-field',
			'number' => 'jet-forms/number-field',
			'date' => 'jet-forms/date-field',
			'select' => 'jet-forms/select-field',
			'radio' => 'jet-forms/radio-field',
			'checkbox' => 'jet-forms/checkbox-field',
			default => 'jet-forms/text-field',
		};
		$attrs = array(
			'name'     => (string) $field['name'],
			'label'    => (string) $field['label'],
			'required' => ! empty( $field['required'] ),
		);
		if ( '' !== (string) $field['placeholder'] ) {
			$attrs['placeholder'] = (string) $field['placeholder'];
		}
		if ( 'jet-forms/text-field' === $name && in_array( $field['type'], array( 'email', 'tel', 'url' ), true ) ) {
			$attrs['field_type'] = $field['type'];
		}
		if ( in_array( $field['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
			$attrs['field_options_from'] = 'manual_input';
			$attrs['field_options']      = array_map( static fn ( array $o ): array => array( 'label' => $o['label'], 'value' => $o['value'] ), $field['options'] );
			if ( 'select' === $field['type'] && ! empty( $field['multiple'] ) ) {
				$attrs['multiple'] = true;
			}
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}
}
