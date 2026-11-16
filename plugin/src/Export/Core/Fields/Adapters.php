<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/** The field plugins a site can leave Histeon with (doc 25 §10), and how their values are written. */
final class Adapters {

	/** @return array<string, Adapter> By id. */
	public static function all(): array {
		$out = array();
		foreach ( array( new Acf( 'scf' ), new Acf( 'acf' ), new MetaBox(), new Pods(), new JetEngine(), new Acf( 'openfields' ) ) as $adapter ) {
			$out[ $adapter->id() ] = $adapter;
		}
		return $out;
	}

	public static function get( string $id ): ?Adapter {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * What converting one post's values would write: the meta to set and to remove (nothing is written).
	 *
	 * @return array{set: array<string, mixed>, delete: list<string>, rows?: array<string, list<mixed>>}
	 */
	public static function post_values( Adapter $adapter, \WP_Post $post ): array {
		$fields = \XB\Content\ContentTypes::live()['fields'][ $post->post_type ] ?? array();
		$keys   = array_map( 'strval', array_keys( (array) get_post_meta( $post->ID ) ) );
		return $adapter->values( $post->post_type, $fields, static fn ( string $key ): mixed => get_post_meta( $post->ID, $key, true ), $keys );
	}

	/** Writes one post's values in the plugin's form (the conversion, and the tests). */
	public static function write_post( Adapter $adapter, \WP_Post $post ): void {
		$values = self::post_values( $adapter, $post );
		foreach ( $values['delete'] as $key ) {
			delete_post_meta( $post->ID, $key );
		}
		global $wpdb;
		foreach ( $values['set'] as $key => $value ) {
			// Straight to the table: the sanitizers Histeon registers for its fields would put a repeater's rows back
			// in its own form (the number of rows read as a list).
			delete_metadata( 'post', $post->ID, $key );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above; the cache is cleared below.
				$wpdb->postmeta,
				array(
					'post_id'    => $post->ID,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => maybe_serialize( $value ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		}
		foreach ( $values['rows'] ?? array() as $key => $list ) {
			delete_metadata( 'post', $post->ID, $key );
			foreach ( $list as $value ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
					$wpdb->postmeta,
					array(
						'post_id'    => $post->ID,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => maybe_serialize( $value ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
			}
		}
		wp_cache_delete( $post->ID, 'post_meta' );
	}
}
