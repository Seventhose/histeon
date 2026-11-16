<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * Histeon's own forms — the static site's, posting to `/xb-form` (docs/spec/static.md §4) — read from their markup as
 * a definition another form plugin can be made from (docs/spec/export-core.md §16): the fields with their names,
 * types, labels, choices and whether they are required; the form's name (`xb_form`), the page to go to after sending
 * (`xb_redirect`) and the button's text. Nothing is written.
 *
 * @phpstan-type Field array{name: string, type: string, label: string, required: bool, placeholder: string, options: list<array{value: string, label: string}>, multiple: bool}
 * @phpstan-type Definition array{name: string, title: string, redirect: string, submit: string, turnstile: bool, fields: list<Field>, documents: list<int>}
 */
final class Reader {

	/** Field types a form plugin takes, from an input's `type`. */
	private const TYPES = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'checkbox', 'radio' );

	/**
	 * Every Histeon form of the site, by name (`xb_form`; `contatti` when it has none): the same name on more pages is
	 * one form, with the documents that show it.
	 *
	 * @return array<string, Definition>
	 */
	public static function all(): array {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','future') AND post_type NOT IN ('revision') AND ( post_content LIKE '%xb-form%' OR post_content LIKE '%xb\\_form%' ) ORDER BY ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			foreach ( self::in_blocks( array_values( parse_blocks( $post->post_content ) ) ) as $form ) {
				$definition = self::read( $form );
				if ( ! isset( $out[ $definition['name'] ] ) ) {
					$out[ $definition['name'] ] = $definition;
				}
				$out[ $definition['name'] ]['documents'][] = $post->ID;
			}
		}
		foreach ( $out as $name => $definition ) {
			$out[ $name ]['documents'] = array_values( array_unique( $definition['documents'] ) );
		}
		return $out;
	}

	/**
	 * Whether a block is a Histeon form: a `form` posting to `/xb-form`, or holding the `xb_form` field.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function is_form( array $block ): bool {
		if ( 'xb/element' !== ( $block['blockName'] ?? null ) || 'form' !== strtolower( (string) ( $block['attrs']['tag'] ?? '' ) ) ) {
			return false;
		}
		$action = $block['attrs']['attributes']['action'] ?? '';
		return ( is_string( $action ) && str_contains( $action, 'xb-form' ) ) || str_contains( (string) wp_json_encode( $block['innerBlocks'] ?? array() ), '"name":"xb_form"' );
	}

	/**
	 * The Histeon forms among blocks, at any depth.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function in_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( self::is_form( $block ) ) {
				$out[] = $block;
				continue;
			}
			array_push( $out, ...self::in_blocks( is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array() ) );
		}
		return $out;
	}

	/**
	 * A form's definition from its block.
	 *
	 * @param array<string, mixed> $form
	 * @return Definition
	 */
	public static function read( array $form ): array {
		$controls = array();
		$labels   = array();
		$hidden   = array();
		$submit   = '';
		$turnstile = false;
		self::walk( is_array( $form['innerBlocks'] ?? null ) ? array_values( $form['innerBlocks'] ) : array(), null, $controls, $labels, $hidden, $submit, $turnstile );
		$fields = array();
		foreach ( $controls as $control ) {
			$name = (string) preg_replace( '/\[\]$/', '', $control['name'] );
			if ( '' === $name || str_starts_with( $name, 'xb_' ) || str_starts_with( $name, 'cf-turnstile' ) ) {
				continue;
			}
			$label = '' !== $control['label'] ? $control['label'] : ( $labels[ $control['id'] ] ?? '' );
			if ( in_array( $control['type'], array( 'radio', 'checkbox' ), true ) ) {
				$option = array(
					'value' => '' !== $control['value'] ? $control['value'] : ( 'checkbox' === $control['type'] ? 'si' : $label ),
					'label' => '' !== $label ? $label : $control['value'],
				);
				if ( isset( $fields[ $name ] ) ) {
					$fields[ $name ]['options'][] = $option;
					$fields[ $name ]['required'] = $fields[ $name ]['required'] || $control['required'];
					continue;
				}
				$fields[ $name ] = array(
					'name'        => $name,
					'type'        => $control['type'],
					'label'       => $control['group'] ?? '',
					'required'    => $control['required'],
					'placeholder' => '',
					'options'     => array( $option ),
					'multiple'    => 'checkbox' === $control['type'],
				);
				continue;
			}
			$fields[ $name ] = array(
				'name'        => $name,
				'type'        => $control['type'],
				'label'       => '' !== $label ? $label : ucfirst( str_replace( array( '_', '-' ), ' ', $name ) ),
				'required'    => $control['required'],
				'placeholder' => $control['placeholder'],
				'options'     => $control['options'],
				'multiple'    => $control['multiple'],
			);
		}
		// A group of choices with no legend takes the first option's name; one box alone is a yes, labelled by itself.
		foreach ( $fields as $name => $field ) {
			if ( '' === $field['label'] ) {
				$fields[ $name ]['label'] = 1 === count( $field['options'] ) ? $field['options'][0]['label'] : ucfirst( str_replace( array( '_', '-' ), ' ', $name ) );
			}
		}
		$name = sanitize_key( $hidden['xb_form'] ?? '' );
		return array(
			'name'      => '' !== $name ? $name : 'contatti',
			'title'     => ucfirst( str_replace( array( '_', '-' ), ' ', '' !== $name ? $name : 'contatti' ) ),
			'redirect'  => self::local_path( $hidden['xb_redirect'] ?? '' ),
			'submit'    => '' !== $submit ? $submit : __( 'Invia', 'xb' ),
			'turnstile' => $turnstile,
			'fields'    => array_values( $fields ),
			'documents' => array(),
		);
	}

	/**
	 * Collects the controls, the labels by `for`, the hidden values, the button's text.
	 *
	 * @param list<array<string, mixed>>         $blocks
	 * @param array{text: string, group?: string}|null $label The label (or fieldset) the blocks are in.
	 * @param list<array<string, mixed>>         $controls
	 * @param array<string, string>              $labels
	 * @param array<string, string>              $hidden
	 */
	private static function walk( array $blocks, ?array $label, array &$controls, array &$labels, array &$hidden, string &$submit, bool &$turnstile ): void {
		foreach ( $blocks as $block ) {
			if ( 'xb/element' !== ( $block['blockName'] ?? null ) ) {
				$inner = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
				self::walk( $inner, $label, $controls, $labels, $hidden, $submit, $turnstile );
				continue;
			}
			$tag      = strtolower( (string) ( $block['attrs']['tag'] ?? '' ) );
			$attrs    = is_array( $block['attrs']['attributes'] ?? null ) ? $block['attrs']['attributes'] : array();
			$attr     = static fn ( string $n ): string => is_scalar( $attrs[ $n ] ?? null ) ? trim( (string) $attrs[ $n ] ) : '';
			$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			$class    = ' ' . $attr( 'class' ) . ' ';
			if ( str_contains( $class, ' cf-turnstile ' ) ) {
				$turnstile = true;
				continue;
			}
			switch ( $tag ) {
				case 'label':
					$text = self::text( $children );
					if ( '' !== $attr( 'for' ) ) {
						$labels[ $attr( 'for' ) ] = $text;
					}
					self::walk( $children, array( 'text' => $text ) + ( isset( $label['group'] ) ? array( 'group' => $label['group'] ) : array() ), $controls, $labels, $hidden, $submit, $turnstile );
					break;
				case 'fieldset':
					$legend = '';
					foreach ( $children as $child ) {
						if ( 'legend' === strtolower( (string) ( $child['attrs']['tag'] ?? '' ) ) ) {
							$legend = self::text( is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array() );
						}
					}
					self::walk( $children, array( 'text' => '', 'group' => $legend ), $controls, $labels, $hidden, $submit, $turnstile );
					break;
				case 'input':
					$type = strtolower( '' !== $attr( 'type' ) ? $attr( 'type' ) : 'text' );
					if ( 'hidden' === $type ) {
						$hidden[ $attr( 'name' ) ] = $attr( 'value' );
						break;
					}
					if ( in_array( $type, array( 'submit', 'button' ), true ) ) {
						$submit = '' !== $submit ? $submit : $attr( 'value' );
						break;
					}
					$controls[] = self::control( in_array( $type, self::TYPES, true ) ? $type : 'text', $attrs, $label, array(), false );
					break;
				case 'textarea':
					$controls[] = self::control( 'textarea', $attrs, $label, array(), false );
					break;
				case 'select':
					$options = array();
					foreach ( $children as $child ) {
						if ( 'option' !== strtolower( (string) ( $child['attrs']['tag'] ?? '' ) ) ) {
							continue;
						}
						$text  = self::text( is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array() );
						$value = is_scalar( $child['attrs']['attributes']['value'] ?? null ) ? (string) $child['attrs']['attributes']['value'] : $text;
						if ( '' !== $value ) {
							$options[] = array(
								'value' => $value,
								'label' => '' !== $text ? $text : $value,
							);
						}
					}
					$controls[] = self::control( 'select', $attrs, $label, $options, array_key_exists( 'multiple', $attrs ) );
					break;
				case 'button':
					if ( in_array( strtolower( $attr( 'type' ) ), array( '', 'submit' ), true ) && '' === $submit ) {
						$submit = self::text( $children );
					}
					break;
				default:
					self::walk( $children, $label, $controls, $labels, $hidden, $submit, $turnstile );
			}
		}
	}

	/**
	 * @param array<string, mixed>                          $attrs
	 * @param array{text: string, group?: string}|null      $label
	 * @param list<array{value: string, label: string}>      $options
	 * @return array<string, mixed>
	 */
	private static function control( string $type, array $attrs, ?array $label, array $options, bool $multiple ): array {
		$attr = static fn ( string $n ): string => is_scalar( $attrs[ $n ] ?? null ) ? trim( (string) $attrs[ $n ] ) : '';
		return array(
			'name'        => $attr( 'name' ),
			'id'          => $attr( 'id' ),
			'type'        => $type,
			'value'       => $attr( 'value' ),
			'label'       => (string) ( $label['text'] ?? '' ),
			'group'       => $label['group'] ?? null,
			'required'    => array_key_exists( 'required', $attrs ) && false !== $attrs['required'],
			'placeholder' => $attr( 'placeholder' ),
			'options'     => $options,
			'multiple'    => $multiple,
		);
	}

	/**
	 * The text of blocks, controls left out: a label's words.
	 *
	 * @param list<array<string, mixed>> $blocks
	 */
	private static function text( array $blocks ): string {
		$out = '';
		foreach ( $blocks as $block ) {
			if ( 'xb/text' === ( $block['blockName'] ?? null ) ) {
				$out .= ' ' . html_entity_decode( wp_strip_all_tags( (string) ( $block['attrs']['content'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				continue;
			}
			if ( in_array( strtolower( (string) ( $block['attrs']['tag'] ?? '' ) ), array( 'input', 'textarea', 'select', 'button' ), true ) ) {
				continue;
			}
			$out .= ' ' . self::text( is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array() );
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
	}

	/** A page of this site to go to after sending (as the Worker allows: a path), or ''. */
	private static function local_path( string $path ): string {
		return 1 === preg_match( '#^/(?!/)[^\s]*$#', $path ) ? $path : '';
	}
}
