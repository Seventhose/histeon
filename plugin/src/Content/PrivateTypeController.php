<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Content;

/**
 * REST controller of a content type without pages of its own (docs/spec/content.md §3): the block editor needs
 * the REST API, but its items are not listed or read there by visitors — only by who can edit them. Pages still
 * show them through loops, with the fields a template chooses.
 */
final class PrivateTypeController extends \WP_REST_Posts_Controller {

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public function get_items_permissions_check( $request ): true|\WP_Error {
		return $this->can_edit() ? parent::get_items_permissions_check( $request ) : $this->refuse();
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public function get_item_permissions_check( $request ): bool|\WP_Error {
		return $this->can_edit() ? parent::get_item_permissions_check( $request ) : $this->refuse();
	}

	private function can_edit(): bool {
		$type = get_post_type_object( $this->post_type );
		return null !== $type && current_user_can( $type->cap->edit_posts );
	}

	private function refuse(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', __( 'Questi contenuti non sono pubblici.', 'xb' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
