<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Plugin;
use XB\Versioning\SiteTransfer;
use XB\Versioning\VersioningException;

/**
 * Production side (docs/spec/remote.md): two signed routes, a dry run and the import, for the staging site the
 * pairing code was given to. No cookie, no nonce: the signature is the credential. The import runs as the
 * administrator who created the link, with that person's permissions, and publishes only if the link allows it.
 */
final class Receiver {

	public const PLAN   = '/remote/plan';
	public const IMPORT = '/remote/import';
	/** An import that also trashes what staging removed (P6): its own route, so the choice is part of the signature. */
	public const IMPORT_DELETE = '/remote/import-delete';
	/** The staging site confirms the pairing and names its address, which the link keeps (doc 23, voce 173). */
	public const PAIR = '/remote/pair';

	public static function register(): void {
		foreach ( array( self::PLAN => false, self::IMPORT => true, self::IMPORT_DELETE => true ) as $route => $write ) {
			$delete = self::IMPORT_DELETE === $route;
			register_rest_route(
				\XB\Rest\Controller::NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => static fn ( \WP_REST_Request $request ) => self::handle( $request, $write, $delete ),
					'permission_callback' => array( self::class, 'allowed' ),
				)
			);
		}
		register_rest_route(
			\XB\Rest\Controller::NAMESPACE,
			self::PAIR,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'pair' ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}

	/** The signed confirmation of the pairing: `{"site": "<staging address>"}`. */
	public static function pair( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = json_decode( $request->get_body(), true );
		$site = is_array( $data ) && is_string( $data['site'] ?? null ) ? $data['site'] : '';
		try {
			$origin = Link::pair_inbound( $site );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_remote_origin', $e->getMessage(), array( 'status' => 409 ) );
		}
		$response = new \WP_REST_Response( array( 'paired' => true, 'site' => $origin ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** The full route as signed by the sender (`/xb/v1/remote/import`). */
	public static function route( string $route ): string {
		return '/' . \XB\Rest\Controller::NAMESPACE . $route;
	}

	public static function allowed( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! is_ssl() && ! Link::local() ) {
			return new \WP_Error( 'xb_remote_https', __( 'Serve HTTPS.', 'xb' ), array( 'status' => 403 ) );
		}
		$why = Link::verify(
			$request->get_method(),
			$request->get_route(),
			(string) $request->get_header( 'x-histos-time' ),
			(string) $request->get_header( 'x-histos-nonce' ),
			(string) $request->get_header( 'x-histos-signature' ),
			$request->get_body()
		);
		if ( '' !== $why ) {
			self::refused( $why );
			return new \WP_Error( 'xb_remote_refused', __( 'Richiesta non autorizzata.', 'xb' ), array( 'status' => 401 ) );
		}
		$link = Link::inbound();
		$user = null === $link ? false : get_userdata( $link['user'] );
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'xb_build' ) ) {
			return new \WP_Error( 'xb_remote_owner', __( 'Chi ha creato il collegamento non è più amministratore: crea un nuovo codice.', 'xb' ), array( 'status' => 403 ) );
		}
		wp_set_current_user( $user->ID );
		return true;
	}

	/** A refused request is logged, at most once a minute (someone without the secret cannot fill the log). */
	private static function refused( string $why ): void {
		if ( false !== get_transient( 'xb_remote_refused' ) ) {
			return;
		}
		set_transient( 'xb_remote_refused', 1, MINUTE_IN_SECONDS );
		Plugin::instance()->versioning->store()->audit( 'remote_refused', 'remote', array( 'why' => $why ) );
	}

	private static function handle( \WP_REST_Request $request, bool $write, bool $delete = false ): \WP_REST_Response|\WP_Error {
		$link = Link::inbound();
		if ( null === $link ) {
			return new \WP_Error( 'xb_remote_refused', __( 'Richiesta non autorizzata.', 'xb' ), array( 'status' => 401 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = wp_tempnam( 'xb-remote' );
		$site = $file . '.' . SiteTransfer::EXTENSION;
		rename( $file, $site ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- our own temp file.
		file_put_contents( $site, $request->get_body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			$publish = $write && $link['publish'] && current_user_can( 'xb_publish' );
			// The staging link authenticates every request with its secret: the file's signature is not asked for.
			// The package must come from the address bound to the link, never from one it names itself (voce 173).
			$options = array( 'verified' => true ) + ( '' !== $link['origin'] ? array( 'origin' => $link['origin'] ) : array() );
			$report  = SiteTransfer::instance()->import( $site, $write, $publish, $delete, $options );
			if ( '' === $link['origin'] ) {
				// A link paired before the address was kept, or whose confirmation never arrived: its first package binds it.
				try {
					Link::pair_inbound( $report['site'] );
				} catch ( \InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- not bound: the next request tries again, what was done stands.
				}
			}
		} catch ( \InvalidArgumentException | VersioningException $e ) {
			return new \WP_Error( 'xb_remote_file', $e->getMessage(), array( 'status' => 400 ) );
		} finally {
			wp_delete_file( $site );
		}
		if ( $write ) {
			Link::touch_inbound();
			Plugin::instance()->versioning->store()->audit( 'remote_import', 'remote', array( 'from' => $report['site'], 'contents' => count( $report['contents'] ), 'media' => $report['media'], 'published' => $report['published'], 'trashed' => $report['deleted'] ) );
		}
		$report['canPublish'] = $link['publish'];
		$response             = new \WP_REST_Response( $report );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
