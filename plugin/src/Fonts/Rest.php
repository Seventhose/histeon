<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fonts;

use XB\Admin\SettingsScreen;
use XB\Rest\Controller;

/**
 * Local fonts from the builder's design system (E2, docs/spec/fonts.md): builders see them, administrators add
 * families from Google Fonts (downloaded once, on the server) and remove them, as in Histeon → Impostazioni.
 */
final class Rest {

	public static function register(): void {
		$manage = static fn (): bool => SettingsScreen::can_manage();
		register_rest_route(
			Controller::NAMESPACE,
			'/fonts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => self::reply( true ),
					'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
						LocalFonts::remove( (string) $request['family'] );
						return self::reply( false );
					},
					'permission_callback' => $manage,
					'args'                => array(
						'family' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/fonts/google',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'import' ),
				'permission_callback' => $manage,
				'args'                => array(
					'family'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'weights' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array( 400 ),
					),
					'italic'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public static function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$weights = array_map( 'intval', is_array( $request['weights'] ) ? array_values( $request['weights'] ) : array( 400 ) );
		try {
			LocalFonts::import_google( (string) $request['family'], array_slice( $weights, 0, 9 ), (bool) $request['italic'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_font', $e->getMessage(), array( 'status' => 400 ) );
		}
		return self::reply( false );
	}

	/** The site's families and their CSS; the catalog only when asked (it does not change). */
	private static function reply( bool $with_catalog ): \WP_REST_Response {
		$data = array(
			'families'  => LocalFonts::summary(),
			'css'       => LocalFonts::css(),
			'canManage' => SettingsScreen::can_manage(),
		);
		if ( $with_catalog ) {
			$data['catalog'] = LocalFonts::catalog();
		}
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
