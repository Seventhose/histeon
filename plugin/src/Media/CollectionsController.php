<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Media;

/**
 * REST controller of the media collections: the core terms controller lets anyone list the terms of a taxonomy that
 * is shown in REST, private or not. Collection names are the site's own filing, read only by who can upload files
 * (the same capability that manages and assigns them).
 */
final class CollectionsController extends \WP_REST_Terms_Controller {

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public function get_items_permissions_check( $request ): bool|\WP_Error {
		return current_user_can( 'upload_files' ) ? parent::get_items_permissions_check( $request ) : self::refuse();
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public function get_item_permissions_check( $request ): bool|\WP_Error {
		return current_user_can( 'upload_files' ) ? parent::get_item_permissions_check( $request ) : self::refuse();
	}

	private static function refuse(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', __( 'Questi contenuti non sono pubblici.', 'xb' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
