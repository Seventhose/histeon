<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Library;

use XB\Rest\Controller;

/** REST of the section library (docs/spec/library.md): builders only (`xb_build`), never cached. */
final class Rest {

	public static function register(): void {
		$allowed = static fn (): bool => current_user_can( 'xb_build' );
		$id      = '/sections/(?P<id>[0-9a-f-]{36})';
		register_rest_route(
			Controller::NAMESPACE,
			'/sections',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => self::reply( array( 'sections' => Sections::index() ) ),
					'permission_callback' => $allowed,
				),
				array(
					'methods'             => 'POST',
					'callback'            => static fn ( \WP_REST_Request $r ) => self::guard( static fn (): array => array( 'id' => Sections::save( (string) $r['name'], (string) $r['clip'] ) ) ),
					'permission_callback' => $allowed,
					'args'                => array(
						'name' => array( 'type' => 'string', 'required' => true ),
						'clip' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/sections/export',
			array(
				'methods'             => 'GET',
				'callback'            => static fn (): \WP_REST_Response => self::reply( Sections::export() ),
				'permission_callback' => $allowed,
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/sections/import',
			array(
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $r ) => self::guard( static fn (): array => Sections::import( $r['data'] ) ),
				'permission_callback' => $allowed,
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			$id,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static function ( \WP_REST_Request $r ) {
						$clip = Sections::get( (string) $r['id'] );
						return null === $clip ? new \WP_Error( 'xb_not_found', __( 'Sezione inesistente.', 'xb' ), array( 'status' => 404 ) ) : self::reply( array( 'clip' => $clip ) );
					},
					'permission_callback' => $allowed,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static function ( \WP_REST_Request $r ) {
						$id = (string) $r['id'];
						if ( null === Sections::author( $id ) ) {
							return new \WP_Error( 'xb_not_found', __( 'Sezione inesistente.', 'xb' ), array( 'status' => 404 ) );
						}
						// The library is shared: a builder removes only what they saved (docs/spec/library.md).
						if ( ! Sections::can_delete( $id ) ) {
							return new \WP_Error( 'xb_forbidden', __( 'Puoi togliere dalla libreria solo le sezioni che hai salvato tu.', 'xb' ), array( 'status' => 403 ) );
						}
						return Sections::delete( $id ) ? self::reply( array( 'deleted' => true ) ) : new \WP_Error( 'xb_not_found', __( 'Sezione inesistente.', 'xb' ), array( 'status' => 404 ) );
					},
					'permission_callback' => $allowed,
				),
			)
		);
	}

	/** @param array<string, mixed> $data */
	private static function reply( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function guard( callable $fn ): \WP_REST_Response|\WP_Error {
		try {
			return self::reply( $fn() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_library', $e->getMessage(), array( 'status' => 400 ) );
		}
	}
}
