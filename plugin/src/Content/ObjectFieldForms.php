<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Content;

/**
 * The fields an administrator defined for terms and users (docs/spec/content.md §6), in WordPress's own forms:
 * the category/tag edit screens and the user profile. Values are term or user meta, written only with a valid
 * nonce and edit_term / edit_user, and sanitized by type (ContentTypes::sanitize_value, also on any other write
 * through the registered meta).
 *
 * @phpstan-import-type FieldDef from ContentTypes
 */
final class ObjectFieldForms {

	private const NONCE = '_xb_fields_nonce';
	private const INPUT = 'xb_fields';

	public static function register(): void {
		add_action( 'init', array( self::class, 'hook_taxonomies' ), 20 );
		add_action( 'show_user_profile', array( self::class, 'user_form' ) );
		add_action( 'edit_user_profile', array( self::class, 'user_form' ) );
		add_action( 'personal_options_update', array( self::class, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_user' ) );
	}

	/** After the taxonomies exist: one pair of form and save hooks per taxonomy with fields. */
	public static function hook_taxonomies(): void {
		foreach ( array_keys( ContentTypes::live()['fields'] ) as $key ) {
			[ $meta_type, $taxonomy ] = ContentTypes::target( (string) $key );
			if ( 'term' !== $meta_type || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			add_action( "{$taxonomy}_add_form_fields", static fn () => self::term_form( null, $taxonomy ) );
			add_action( "{$taxonomy}_edit_form_fields", static fn ( mixed $term ) => self::term_form( $term instanceof \WP_Term ? $term : null, $taxonomy ) );
			add_action( "created_{$taxonomy}", static fn ( int $term_id ) => self::save_term( $term_id, $taxonomy ) );
			add_action( "edited_{$taxonomy}", static fn ( int $term_id ) => self::save_term( $term_id, $taxonomy ) );
		}
	}

	private static function term_form( ?\WP_Term $term, string $taxonomy ): void {
		$fields = ContentTypes::fields_for_term( $taxonomy );
		if ( array() === $fields ) {
			return;
		}
		$table = null !== $term;
		wp_nonce_field( 'xb_fields_term', self::NONCE );
		foreach ( $fields as $field ) {
			$value = null === $term ? '' : get_term_meta( $term->term_id, $field['name'], true );
			if ( $table ) {
				echo '<tr class="form-field"><th scope="row"><label for="xb-field-' . esc_attr( $field['name'] ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
				self::input( $field, $value );
				echo '</td></tr>';
			} else {
				echo '<div class="form-field"><label for="xb-field-' . esc_attr( $field['name'] ) . '">' . esc_html( $field['label'] ) . '</label>';
				self::input( $field, $value );
				echo '</div>';
			}
		}
		self::media_script( $fields );
	}

	public static function user_form( mixed $user ): void {
		$fields = ContentTypes::fields_for_user();
		if ( array() === $fields || ! $user instanceof \WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'xb_fields_user', self::NONCE );
		echo '<h2>' . esc_html__( 'Campi del profilo', 'xb' ) . '</h2><p class="description">' . esc_html__( 'Si vedono sul sito nelle pagine che mostrano l\'autore.', 'xb' ) . '</p><table class="form-table" role="presentation">';
		foreach ( $fields as $field ) {
			echo '<tr><th scope="row"><label for="xb-field-' . esc_attr( $field['name'] ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
			self::input( $field, get_user_meta( $user->ID, $field['name'], true ) );
			echo '</td></tr>';
		}
		echo '</table>';
		self::media_script( $fields );
	}

	private static function save_term( int $term_id, string $taxonomy ): void {
		if ( ! self::verified( 'xb_fields_term' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		foreach ( ContentTypes::fields_for_term( $taxonomy ) as $field ) {
			$value = self::submitted( $field );
			if ( self::is_empty( $value ) ) {
				delete_term_meta( $term_id, $field['name'] );
			} else {
				update_term_meta( $term_id, $field['name'], wp_slash( $value ) );
			}
		}
	}

	public static function save_user( int $user_id ): void {
		if ( ! self::verified( 'xb_fields_user' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		foreach ( ContentTypes::fields_for_user() as $field ) {
			$value = self::submitted( $field );
			if ( self::is_empty( $value ) ) {
				delete_user_meta( $user_id, $field['name'] );
			} else {
				update_user_meta( $user_id, $field['name'], wp_slash( $value ) );
			}
		}
	}

	private static function verified( string $action ): bool {
		$nonce = isset( $_POST[ self::NONCE ] ) && is_string( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * The value sent for a field, sanitized by its type.
	 *
	 * @param FieldDef $field
	 */
	private static function submitted( array $field ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$all = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : array();
		$raw = array_key_exists( $field['name'], $all ) ? $all[ $field['name'] ] : ( 'toggle' === $field['type'] ? false : '' );
		return ContentTypes::sanitize_value( $field, is_scalar( $raw ) ? $raw : '' );
	}

	private static function is_empty( mixed $value ): bool {
		return '' === $value || 0 === $value || false === $value || null === $value;
	}

	/** @param FieldDef $field */
	private static function input( array $field, mixed $value ): void {
		$name  = self::INPUT . '[' . $field['name'] . ']';
		$id    = 'xb-field-' . $field['name'];
		$value = is_scalar( $value ) ? (string) $value : '';
		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'toggle':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( '1' === $value || 'true' === $value, true, false ) . '> ' . esc_html__( 'Sì', 'xb' ) . '</label>';
				break;
			case 'select':
				if ( ! empty( $field['radio'] ) ) {
					// Radio buttons instead of the menu (doc 26 voce 211); the empty one clears the value.
					echo '<fieldset id="' . esc_attr( $id ) . '"><label><input type="radio" name="' . esc_attr( $name ) . '" value=""' . checked( $value, '', false ) . '> —</label>';
					foreach ( $field['options'] as $option ) {
						echo ' <label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option['value'] ) . '"' . checked( $value, $option['value'], false ) . '> ' . esc_html( $option['label'] ) . '</label>';
					}
					echo '</fieldset>';
					break;
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
				foreach ( $field['options'] as $option ) {
					echo '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $value, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
				}
				echo '</select>';
				break;
			case 'post':
				$posts = get_posts(
					array(
						'post_type'   => $field['postType'],
						'post_status' => 'publish',
						'numberposts' => 200,
						'orderby'     => 'title',
						'order'       => 'ASC',
					)
				);
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="0">—</option>';
				foreach ( $posts as $post ) {
					echo '<option value="' . esc_attr( (string) $post->ID ) . '"' . selected( $value, (string) $post->ID, false ) . '>' . esc_html( get_the_title( $post ) ) . '</option>';
				}
				echo '</select>';
				break;
			case 'file':
				$file = (int) $value > 0 ? wp_get_attachment_url( (int) $value ) : false;
				echo '<span class="xb-field-image" data-xb-image data-xb-any>';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (int) $value > 0 ? $value : '' ) . '">';
				echo '<code data-xb-name>' . esc_html( $file ? wp_basename( $file ) : '' ) . '</code> ';
				echo '<button type="button" class="button" data-xb-pick>' . esc_html__( 'Scegli file', 'xb' ) . '</button> ';
				echo '<button type="button" class="button-link" data-xb-clear>' . esc_html__( 'Rimuovi', 'xb' ) . '</button></span>';
				break;
			case 'image':
				$src = (int) $value > 0 ? wp_get_attachment_image_url( (int) $value, 'thumbnail' ) : false;
				echo '<span class="xb-field-image" data-xb-image>';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (int) $value > 0 ? $value : '' ) . '">';
				echo '<img src="' . esc_url( $src ? $src : '' ) . '" alt="" style="width:64px;height:64px;object-fit:cover;vertical-align:middle;' . ( $src ? '' : 'display:none;' ) . '"> ';
				echo '<button type="button" class="button" data-xb-pick>' . esc_html__( 'Scegli immagine', 'xb' ) . '</button> ';
				echo '<button type="button" class="button-link" data-xb-clear>' . esc_html__( 'Rimuovi', 'xb' ) . '</button></span>';
				break;
			default:
				$type = array(
					'url'    => 'url',
					'email'  => 'email',
					'date'   => 'date',
					'time'   => 'time',
					'number' => 'number',
				)[ $field['type'] ] ?? 'text';
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( 'number' === $type ? ' step="any"' : '' ) . '>';
		}
		if ( '' !== $field['help'] ) {
			echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
		}
	}

	/**
	 * The media library for image fields: a hidden input with the attachment ID and a preview.
	 *
	 * @param list<FieldDef> $fields
	 */
	private static function media_script( array $fields ): void {
		if ( array() === array_intersect( array( 'image', 'file' ), array_column( $fields, 'type' ) ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_media();
		?>
		<script>
		document.addEventListener( 'click', function ( event ) {
			var box = event.target.closest( '[data-xb-image]' );
			if ( ! box || ! window.wp || ! wp.media ) { return; }
			var input = box.querySelector( 'input' ), img = box.querySelector( 'img' ), label = box.querySelector( '[data-xb-name]' );
			var any = box.hasAttribute( 'data-xb-any' );
			if ( event.target.closest( '[data-xb-clear]' ) ) {
				input.value = '';
				if ( img ) { img.style.display = 'none'; }
				if ( label ) { label.textContent = ''; }
				return;
			}
			if ( ! event.target.closest( '[data-xb-pick]' ) ) { return; }
			event.preventDefault();
			var frame = wp.media( any ? { multiple: false } : { library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var item = frame.state().get( 'selection' ).first().toJSON();
				input.value = String( item.id );
				if ( img ) {
					img.src = ( item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url );
					img.style.display = '';
				}
				if ( label ) { label.textContent = item.filename || item.url; }
			} );
			frame.open();
		} );
		</script>
		<?php
	}
}
