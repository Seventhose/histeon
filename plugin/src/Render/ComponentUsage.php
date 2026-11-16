<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Versioning\Schema;

/**
 * A component in use is not deleted (lista della spesa 162): before it goes to the trash, or is deleted, from
 * wp-admin, the block editor or the REST API, Histeon looks for the pages, templates and components that use it,
 * online or in development, and refuses with their list. Code that deletes on purpose (WP-CLI, a migration, the
 * tests) is not stopped; the filter `xb_protect_components_in_use` turns the check off.
 */
final class ComponentUsage {

	private const TYPES = array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part' );

	public static function register(): void {
		add_filter( 'pre_trash_post', array( self::class, 'before_trash' ), 9, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'before_delete' ), 9, 2 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'rest' ), 10, 3 );
	}

	/**
	 * Where the component with key $ref is used: published or draft content that holds it, and documents whose
	 * development version holds it. At most $limit entries.
	 *
	 * @return list<array{id: int, title: string, type: string, dev: bool}>
	 */
	public static function usages( string $ref, int $limit = 30 ): array {
		global $wpdb;
		if ( '' === $ref ) {
			return array();
		}
		$needles = array( '"ref":"' . $ref . '"', '"ref":"' . str_replace( '/', '\\/', $ref ) . '"' );
		$types   = implode( ',', array_fill( 0, count( self::TYPES ), '%s' ) );
		$like    = static fn ( string $n ): string => '%' . $wpdb->esc_like( $n ) . '%';
		$found   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_type, post_title LIMIT %d", array_merge( self::TYPES, array( $like( $needles[0] ), $like( $needles[1] ), $limit ) ) ), ARRAY_A );
		foreach ( $rows as $row ) {
			$found[ (int) $row['ID'] ] = array( 'id' => (int) $row['ID'], 'title' => (string) $row['post_title'], 'type' => (string) $row['post_type'], 'dev' => false );
		}
		// Development versions not published yet: the head of each document, read in one query.
		$refs    = Schema::table( 'refs' );
		$commits = Schema::table( 'commits' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$docs = (array) $wpdb->get_col( $wpdb->prepare( "SELECT r.doc FROM {$refs} r INNER JOIN {$commits} c ON c.id = r.commit_id WHERE r.branch = 'dev' AND r.doc LIKE 'post:%%' AND ( c.payload LIKE %s OR c.payload LIKE %s ) LIMIT %d", $like( str_replace( '"', '\\"', $needles[0] ) ), $like( str_replace( array( '"', '/' ), array( '\\"', '\\/' ), $needles[0] ) ), $limit ) );
		foreach ( $docs as $doc ) {
			$id = (int) substr( (string) $doc, 5 );
			if ( $id > 0 && ! isset( $found[ $id ] ) && 'trash' !== get_post_status( $id ) && false !== get_post_status( $id ) ) {
				$found[ $id ] = array( 'id' => $id, 'title' => (string) get_the_title( $id ), 'type' => (string) get_post_type( $id ), 'dev' => true );
			}
		}
		return array_slice( array_values( $found ), 0, $limit );
	}

	/** The key of a Histeon component, or '' for any other post. */
	private static function ref_of( \WP_Post $post ): string {
		return 'wp_block' === $post->post_type ? (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) : '';
	}

	/** Only deletions someone asks for in wp-admin, the block editor or the REST API are checked. */
	private static function guarded(): bool {
		$asked = is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		return $asked && (bool) apply_filters( 'xb_protect_components_in_use', true );
	}

	/** Why the component cannot go, in words, or '' when nothing uses it (or it is no component). */
	public static function refusal( \WP_Post $post ): string {
		$ref = self::ref_of( $post );
		if ( '' === $ref ) {
			return '';
		}
		$uses = array_values( array_filter( self::usages( $ref ), static fn ( array $u ): bool => $u['id'] !== $post->ID ) );
		if ( array() === $uses ) {
			return '';
		}
		$names = array_map( static fn ( array $u ): string => ( '' !== $u['title'] ? $u['title'] : '#' . $u['id'] ) . ( $u['dev'] ? ' ' . __( '(in sviluppo)', 'xb' ) : '' ), $uses );
		/* translators: 1: component title, 2: list of pages and templates */
		return sprintf( __( 'Il componente «%1$s» è in uso e non si può eliminare. Toglilo prima da: %2$s.', 'xb' ), $post->post_title, implode( ', ', $names ) );
	}

	/**
	 * @param mixed $trash
	 * @return mixed
	 */
	public static function before_trash( $trash, \WP_Post $post ) {
		if ( null !== $trash || ! self::guarded() ) {
			return $trash;
		}
		$why = self::refusal( $post );
		if ( '' === $why ) {
			return $trash;
		}
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_doing_ajax() ) {
			wp_die( esc_html( $why ), esc_html__( 'Componente in uso', 'xb' ), array( 'response' => 409, 'back_link' => true ) );
		}
		return false;
	}

	/**
	 * @param mixed $delete
	 * @return mixed
	 */
	public static function before_delete( $delete, \WP_Post $post ) {
		return self::before_trash( $delete, $post );
	}

	/**
	 * The block editor deletes a reusable block through the REST API: there the refusal says why.
	 *
	 * @param mixed $response
	 * @param array<string, mixed> $handler
	 * @return mixed
	 */
	public static function rest( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );
		if ( 'DELETE' !== $request->get_method() || 1 !== preg_match( '#^/wp/v2/blocks/(\d+)$#', $request->get_route(), $m ) || ! (bool) apply_filters( 'xb_protect_components_in_use', true ) ) {
			return $response;
		}
		$post = get_post( (int) $m[1] );
		$why  = $post instanceof \WP_Post ? self::refusal( $post ) : '';
		return '' === $why ? $response : new \WP_Error( 'xb_component_in_use', $why, array( 'status' => 409 ) );
	}
}
