<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Fields\Fields;
use XB\Fields\ObjectProvider;
use XB\Fields\Provider;

/**
 * Custom fields of a post, a term or a user — docs/spec/content.md §4. `this.fields.prezzo` reads the first
 * provider (XB, ACF/SCF, JetEngine, Meta Box…) that defines a readable field with that name for the object;
 * `this.acf.prezzo`, `this.jetengine.prezzo` and `this.metabox.prezzo` read only from that plugin. Terms and users
 * are read through providers that implement ObjectProvider. A field nobody made public is null, like an unknown key.
 */
final class FieldsNode implements Node {

	/** @var array<string, mixed> */
	private array $memo = array();

	public function __construct( private readonly \WP_Post|\WP_Term|\WP_User $object, private readonly ?string $only = null ) {}

	public function get( string $key ): mixed {
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}
		$value = null;
		foreach ( Fields::providers() as $provider ) {
			if ( null !== $this->only && $provider->id() !== $this->only ) {
				continue;
			}
			if ( ! Fields::safe( static fn (): bool => $provider->available(), false ) ) {
				continue;
			}
			$fields = Fields::safe( fn (): array => $this->fields( $provider ), array() );
			$field  = $fields[ $key ] ?? null;
			if ( is_array( $field ) && Fields::is_public( $provider, $field ) ) {
				$value = Fields::safe( fn (): mixed => $this->value( $provider, $field ), null );
				break;
			}
		}
		return $this->memo[ $key ] = $value;
	}

	/** @return array<string, array<string, mixed>> */
	private function fields( Provider $provider ): array {
		$object = $this->object;
		if ( $object instanceof \WP_Post ) {
			return $provider->fields_for( $object );
		}
		if ( ! $provider instanceof ObjectProvider ) {
			return array();
		}
		return $object instanceof \WP_Term
			? $provider->object_fields( 'term', $object->term_id, $object->taxonomy )
			: $provider->object_fields( 'user', $object->ID, '' );
	}

	/** @param array<string, mixed> $field */
	private function value( Provider $provider, array $field ): mixed {
		$object = $this->object;
		if ( $object instanceof \WP_Post ) {
			return $provider->value( $object, $field );
		}
		if ( ! $provider instanceof ObjectProvider ) {
			return null;
		}
		return $object instanceof \WP_Term
			? $provider->object_value( 'term', $object->term_id, $field )
			: $provider->object_value( 'user', $object->ID, $field );
	}
}
