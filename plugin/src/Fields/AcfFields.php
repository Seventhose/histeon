<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * Advanced Custom Fields and Secure Custom Fields (its fork on WordPress.org): same API, one adapter.
 * Values are read raw (acf_get_value) and converted by Values, so ACF's formatting never runs: no shortcodes,
 * no oEmbed requests, and relations are filtered by what the visitor may read.
 */
final class AcfFields implements Provider, ObjectProvider, OptionsProvider {

	/** Field types without a value: they only lay out the editing screen. */
	private const LAYOUT = array( 'tab', 'message', 'accordion' );

	/** @var array<string, array<string, array<string, mixed>>> Fields of a post, term or user (`post:12`, `term:3`). */
	private array $memo = array();

	public function id(): string {
		return 'acf';
	}

	public function label(): string {
		return defined( 'ACF_BASENAME' ) && str_starts_with( (string) constant( 'ACF_BASENAME' ), 'secure-custom-fields' ) ? 'Secure Custom Fields' : 'Advanced Custom Fields';
	}

	public function available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'acf_get_value' );
	}

	public function describe(): array {
		$out = array();
		foreach ( acf_get_field_groups() as $group ) {
			if ( ! is_array( $group ) || empty( $group['active'] ) ) {
				continue;
			}
			$types = self::post_types( $group );
			foreach ( acf_get_fields( $group ) as $field ) {
				if ( ! is_array( $field ) || in_array( $field['type'] ?? '', self::LAYOUT, true ) || '' === (string) ( $field['name'] ?? '' ) ) {
					continue;
				}
				$out[] = array(
					'id'         => 'acf:' . (string) $field['key'],
					'provider'   => 'acf',
					'name'       => (string) $field['name'],
					'label'      => (string) ( $field['label'] ?? $field['name'] ),
					'type'       => (string) $field['type'],
					'group'      => (string) $group['key'],
					'groupLabel' => (string) ( $group['title'] ?? $group['key'] ),
					'postTypes'  => $types,
					'optionsPage' => self::options_page( $group ),
					// Options are never published by the REST flag: only an administrator's choice makes them public.
					'rest'       => ! empty( $group['show_in_rest'] ) && null === self::options_page( $group ),
					'supported'  => self::supported( (string) $field['type'] ),
					'sub'        => self::sub( $field ),
				);
			}
		}
		return $out;
	}

	public function fields_for( \WP_Post $post ): array {
		$key = 'post:' . $post->ID;
		return $this->memo[ $key ] ??= self::collect(
			acf_get_field_groups(
				array(
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
				)
			)
		);
	}

	public function object_fields( string $kind, int $id, string $subtype ): array {
		$filter = match ( $kind ) {
			'term' => array( 'taxonomy' => $subtype ),
			'user' => array(
				'user_id'   => $id,
				'user_form' => 'edit',
			),
			default => null,
		};
		return null === $filter ? array() : ( $this->memo[ "$kind:$id" ] ??= self::collect( acf_get_field_groups( $filter ) ) );
	}

	public function options_pages(): array {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_options_pages() as $slug => $page ) {
			if ( is_array( $page ) ) {
				$out[ (string) $slug ] = (string) ( $page['post_id'] ?? 'options' );
			}
		}
		return $out;
	}

	public function options_fields( string $page ): array {
		return isset( $this->options_pages()[ $page ] ) ? ( $this->memo[ "options:$page" ] ??= self::collect( acf_get_field_groups( array( 'options_page' => $page ) ) ) ) : array();
	}

	public function options_value( string $page, array $field ): mixed {
		$store = $this->options_pages()[ $page ] ?? null;
		return null === $store ? null : self::convert( $field, acf_get_value( $store, $field ), 0 );
	}

	/**
	 * The options page a group belongs to, if its location says so.
	 *
	 * @param array<string, mixed> $group
	 */
	private static function options_page( array $group ): ?string {
		foreach ( (array) ( $group['location'] ?? array() ) as $rules ) {
			foreach ( (array) $rules as $rule ) {
				if ( is_array( $rule ) && 'options_page' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
					return (string) $rule['value'];
				}
			}
		}
		return null;
	}

	public function object_value( string $kind, int $id, array $field ): mixed {
		return self::convert( $field, acf_get_value( ( 'term' === $kind ? 'term_' : 'user_' ) . $id, $field ), 0 );
	}

	/**
	 * The fields of the groups that apply, by name (the first group wins a name).
	 *
	 * @param array<int, mixed> $groups
	 * @return array<string, array<string, mixed>>
	 */
	private static function collect( array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( acf_get_fields( $group ) as $field ) {
				$name = is_array( $field ) ? (string) ( $field['name'] ?? '' ) : '';
				if ( '' === $name || isset( $out[ $name ] ) || in_array( $field['type'] ?? '', self::LAYOUT, true ) ) {
					continue;
				}
				/** @var array<string, mixed> $field */
				$out[ $name ] = array(
					'id'   => 'acf:' . (string) $field['key'],
					'rest' => ! empty( $group['show_in_rest'] ),
				) + $field;
			}
		}
		return $out;
	}

	public function value( \WP_Post $post, array $field ): mixed {
		return self::convert( $field, acf_get_value( $post->ID, $field ), 0 );
	}

	/** @param array<string, mixed> $field */
	private static function convert( array $field, mixed $raw, int $depth ): mixed {
		$multiple = ! empty( $field['multiple'] );
		switch ( $field['type'] ?? '' ) {
			case 'text':
			case 'textarea':
			case 'email':
			case 'color_picker':
			case 'time_picker':
				return Values::text( $raw );
			case 'wysiwyg':
				return Values::plain( $raw );
			case 'number':
			case 'range':
				return Values::number( $raw );
			case 'url':
			case 'oembed':
				return Values::url( $raw );
			case 'true_false':
				return Values::boolean( $raw );
			case 'date_picker':
			case 'date_time_picker':
				return Values::date( $raw );
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				return Values::choice( $raw );
			case 'image':
				return Values::image( $raw );
			case 'file':
				return Values::file( $raw );
			case 'gallery':
				return Values::images( $raw );
			case 'link':
				return Values::link( $raw );
			case 'relationship':
				return Values::posts( $raw );
			case 'post_object':
			case 'page_link':
				return $multiple ? Values::posts( $raw ) : Values::post( $raw );
			case 'taxonomy':
				$terms = Values::terms( $raw, (string) ( $field['taxonomy'] ?? '' ) );
				return in_array( $field['field_type'] ?? '', array( 'select', 'radio' ), true ) ? ( $terms[0] ?? null ) : $terms;
			case 'user':
				if ( $multiple ) {
					return array_values( array_filter( array_map( array( Values::class, 'user' ), is_array( $raw ) ? array_slice( $raw, 0, Values::MAX_ROWS ) : array() ) ) );
				}
				return Values::user( $raw );
			case 'google_map':
				return Values::map( $raw );
			case 'group':
				return $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ? null : self::row( (array) ( $field['sub_fields'] ?? array() ), $raw, $depth + 1 );
			case 'repeater':
				if ( $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ) {
					return array();
				}
				$rows = array();
				foreach ( array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) as $row ) {
					$rows[] = self::row( (array) ( $field['sub_fields'] ?? array() ), is_array( $row ) ? $row : array(), $depth + 1 );
				}
				return $rows;
			case 'flexible_content':
				if ( $depth >= Values::MAX_DEPTH || ! is_array( $raw ) ) {
					return array();
				}
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					if ( is_array( $layout ) && isset( $layout['name'] ) ) {
						$layouts[ (string) $layout['name'] ] = (array) ( $layout['sub_fields'] ?? array() );
					}
				}
				$rows = array();
				foreach ( array_slice( array_values( $raw ), 0, Values::MAX_ROWS ) as $row ) {
					$name = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';
					if ( isset( $layouts[ $name ] ) ) {
						$rows[] = array( 'layout' => $name ) + self::row( $layouts[ $name ], $row, $depth + 1 );
					}
				}
				return $rows;
			default:
				return null; // password, clone and anything unknown: never read.
		}
	}

	/**
	 * A row of sub fields (repeater, group, flexible layout): their values by name.
	 *
	 * @param array<mixed>        $sub_fields
	 * @param array<mixed, mixed> $raw Values by field key (or by name).
	 * @return array<string, mixed>
	 */
	private static function row( array $sub_fields, array $raw, int $depth ): array {
		$out = array();
		foreach ( $sub_fields as $sub ) {
			$name = is_array( $sub ) ? (string) ( $sub['name'] ?? '' ) : '';
			if ( '' === $name || str_starts_with( $name, '_' ) || in_array( $sub['type'] ?? '', self::LAYOUT, true ) ) {
				continue;
			}
			$out[ $name ] = self::convert( $sub, $raw[ $sub['key'] ?? '' ] ?? $raw[ $name ] ?? null, $depth );
		}
		return $out;
	}

	private static function supported( string $type ): bool {
		return ! in_array( $type, array( 'password', 'clone' ), true );
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<array{name: string, label: string, type: string}>
	 */
	private static function sub( array $field ): array {
		$subs = (array) ( $field['sub_fields'] ?? array() );
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$subs = array_merge( $subs, is_array( $layout ) ? (array) ( $layout['sub_fields'] ?? array() ) : array() );
		}
		$out = array();
		foreach ( $subs as $sub ) {
			if ( is_array( $sub ) && '' !== (string) ( $sub['name'] ?? '' ) && ! in_array( $sub['type'] ?? '', self::LAYOUT, true ) ) {
				$out[] = array(
					'name'  => (string) $sub['name'],
					'label' => (string) ( $sub['label'] ?? $sub['name'] ),
					'type'  => (string) ( $sub['type'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * The post types a group's location rules name (`post_type == evento`), for the builder's list.
	 *
	 * @param array<string, mixed> $group
	 * @return list<string>
	 */
	private static function post_types( array $group ): array {
		$out = array();
		foreach ( (array) ( $group['location'] ?? array() ) as $rules ) {
			foreach ( (array) $rules as $rule ) {
				if ( is_array( $rule ) && 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
					$out[] = (string) $rule['value'];
				} elseif ( is_array( $rule ) && in_array( $rule['param'] ?? '', array( 'page_template', 'page_type', 'page', 'page_parent' ), true ) ) {
					$out[] = 'page';
				} elseif ( is_array( $rule ) && 'taxonomy' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
					$out[] = 'term:' . (string) $rule['value'];
				} elseif ( is_array( $rule ) && in_array( $rule['param'] ?? '', array( 'user_form', 'user_role', 'current_user', 'current_user_role' ), true ) ) {
					$out[] = 'user';
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
