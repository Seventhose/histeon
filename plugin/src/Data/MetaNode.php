<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Content\ContentTypes;

/**
 * Post meta reachable from expressions (`this.meta.price`). Threat model T3:
 * protected keys (`_*`) are never exposed; other keys only when registered with `show_in_rest`
 * for the post type, or allowed through the `xb_public_meta_keys` filter.
 */
final class MetaNode implements Node {

	public function __construct( private readonly \WP_Post $post ) {}

	public function get( string $key ): mixed {
		if ( ! self::is_public( $key, $this->post->post_type ) ) {
			return null;
		}
		$value = get_post_meta( $this->post->ID, $key, true );
		return '' === $value ? null : $value;
	}

	public static function is_public( string $key, string $post_type ): bool {
		if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
			return false;
		}
		// Histeon registers private CMS fields in REST for editor writes too. That permission does not make a
		// field public in templates; the content model remains authoritative for expression access.
		foreach ( ContentTypes::fields_for( $post_type ) as $field ) {
			if ( $field['name'] === $key ) {
				return $field['public'];
			}
		}
		$registered = get_registered_meta_keys( 'post', $post_type ) + get_registered_meta_keys( 'post' );
		if ( isset( $registered[ $key ] ) && ! empty( $registered[ $key ]['show_in_rest'] ) ) {
			return true;
		}
		/** @var list<string> $allowed */
		$allowed = (array) apply_filters( 'xb_public_meta_keys', array(), $post_type );
		return in_array( $key, $allowed, true );
	}
}
