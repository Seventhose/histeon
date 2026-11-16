<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Integrations;

/**
 * Fluent Forms inside Histeon (docs/spec/fluent-forms.md). Histeon does not make forms: it inserts a Fluent Forms form
 * in a page (Fluent Forms' own block, kept as it is) and opens Fluent Forms' editor inside the builder, in a frame
 * without WordPress's menus (BuilderScreen::framed_dashboard). The forms, their fields, entries and permissions stay
 * Fluent Forms'.
 */
final class FluentForms {

	public const BLOCK     = 'fluentfom/guten-block';
	public const PAGE      = 'fluent_forms';
	private const LIMIT    = 200;

	public static function available(): bool {
		return defined( 'FLUENTFORM' ) && class_exists( '\FluentForm\App\Modules\Acl\Acl' );
	}

	/** Who may see and edit the forms: Fluent Forms' own permission, as in its menu. */
	public static function allowed(): bool {
		if ( ! self::available() || ! current_user_can( 'xb_build' ) ) {
			return false;
		}
		$check = array( '\FluentForm\App\Modules\Acl\Acl', 'hasPermission' );
		return is_callable( $check ) && (bool) call_user_func( $check, 'fluentform_forms_manager' );
	}

	/**
	 * The forms, newest first.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function forms(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'fluentform_forms';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} ORDER BY id DESC LIMIT %d", self::LIMIT ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a table name.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'    => (int) $row['id'],
					'title' => (string) $row['title'],
				);
			}
		}
		return $out;
	}

	/** Fluent Forms' editor of a form, or its «new form» screen, framed for the builder. */
	public static function editor_url( int $form_id = 0 ): string {
		$args = $form_id > 0 ? array( 'page' => self::PAGE, 'route' => 'editor', 'form_id' => $form_id, 'xb_frame' => 1 ) : array( 'page' => self::PAGE, 'xb_frame' => 1 );
		return add_query_arg( $args, admin_url( 'admin.php' ) ) . ( $form_id > 0 ? '' : '#add=1' );
	}

	public static function register(): void {
		register_rest_route(
			\XB\Rest\Controller::NAMESPACE,
			'/integrations/fluentforms',
			array(
				'methods'             => 'GET',
				'callback'            => static function (): \WP_REST_Response {
					$response = new \WP_REST_Response(
						array(
							'forms'   => self::forms(),
							'editUrl' => self::editor_url( 999999999 ),
							'newUrl'  => self::editor_url(),
							'block'   => self::BLOCK,
						)
					);
					$response->header( 'Cache-Control', 'no-store' );
					return $response;
				},
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}
}
