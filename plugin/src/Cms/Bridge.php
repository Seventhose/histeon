<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Cms;

use XB\Plugin;
use XB\Remote\Link as RemoteLink;
use XB\Remote\OperationConflictException;
use XB\Remote\PageProfileAdapter;
use XB\Remote\ProfileConflictException;
use XB\Templates\Templates;
use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * The routes Histeon CMS calls (docs/spec/cms-bridge.md §2): capabilities, export, import (to development), publish,
 * operation status and revoke. Every request is signed with the CMS link and runs as the administrator who created
 * it.
 */
final class Bridge {

	public const CAPABILITIES = '/cms/v1/capabilities';
	public const EXPORT       = '/cms/v1/export';
	public const IMPORT       = '/cms/v1/import';
	public const PUBLISH      = '/cms/v1/publish';
	public const STATUS       = '/cms/v1/status/(?P<operationId>[0-9a-f-]{36})';
	public const REVOKE       = '/cms/v1/revoke';
	/** Which sent content is which language's version of which: a language plugin takes it (docs/spec/multilingual.md). */
	public const LANGUAGES    = '/cms/v1/languages';
	private const MAX_LANGUAGE_LINKS = 5000;
	/**
	 * The site's home and posts page as WordPress reads them (Settings → Reading), by the identities of the project's
	 * pages: GET with the read permission, POST with the publish permission (doc 23 item 201 of Histeon Publish).
	 */
	public const FRONT_PAGE   = '/cms/v1/front-page';
	/**
	 * V1.6 (threat model T32): signed preview links of the pages that change in development, for the checks Histeon
	 * Publish runs in its own hidden window (axe, links to other sites). Read permission; no parameter.
	 */
	public const PREVIEW_LINKS = '/cms/v1/preview-links';
	/** How long each of those links lasts, in seconds: fixed, never chosen by the caller. */
	public const PREVIEW_LINKS_TTL = 600;
	/** At most this many pages in one answer. */
	public const PREVIEW_LINKS_MAX = 20;
	/** Links whose content does not exist yet: records sent without publishing wait for the next release. */
	public const WAITING_LANGUAGE_LINKS = 'xb_cms_waiting_language_links';

	public static function register(): void {
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_archive' ), 10, 4 );
		$routes = array(
			self::CAPABILITIES  => array( 'GET', 'capabilities' ),
			self::EXPORT        => array( 'GET', 'export' ),
			self::IMPORT        => array( 'POST', 'import' ),
			self::PUBLISH       => array( 'POST', 'publish' ),
			self::STATUS        => array( 'GET', 'status' ),
			self::REVOKE        => array( 'POST', 'revoke' ),
			self::LANGUAGES     => array( 'POST', 'languages' ),
			self::FRONT_PAGE    => array( 'GET, POST', 'front_page' ),
			self::PREVIEW_LINKS => array( 'GET', 'preview_links' ),
		);
		foreach ( $routes as $route => [ $method, $callback ] ) {
			$args = array(
				'methods'             => $method,
				'callback'            => array( self::class, $callback ),
				'permission_callback' => array( self::class, 'allowed' ),
			);
			if ( self::STATUS === $route ) {
				$args['args'] = array( 'operationId' => array( 'validate_callback' => static fn ( string $value ): bool => 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ) );
			}
			register_rest_route( \XB\Rest\Controller::NAMESPACE, $route, $args );
		}
		// Doc 23 item 237: the one route without the link's signature, authenticated by its claim and verifier.
		Pairing::register_rest();
	}

	/** The full route as signed by the CMS (`/xb/v1/cms/v1/import`). */
	public static function route( string $route ): string {
		return '/' . \XB\Rest\Controller::NAMESPACE . $route;
	}

	public static function allowed( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! is_ssl() && ! RemoteLink::local() ) {
			return new \WP_Error( 'xb_cms_https', __( 'Serve HTTPS.', 'xb' ), array( 'status' => 403 ) );
		}
		$why = Link::verify(
			$request->get_method(),
			$request->get_route(),
			(string) $request->get_header( 'x-histos-time' ),
			(string) $request->get_header( 'x-histos-nonce' ),
			(string) $request->get_header( 'x-histos-signature' ),
			(string) $request->get_body()
		);
		if ( '' !== $why ) {
			self::refused( $why );
			// "No link" is the one refusal Histeon CMS may read as "already revoked" and forget its secret.
			return 'no link' === $why
				? new \WP_Error( 'xb_cms_inactive', __( 'Nessun collegamento Histeon CMS attivo su questo sito.', 'xb' ), array( 'status' => 401 ) )
				: new \WP_Error( 'xb_cms_refused', __( 'Richiesta non autorizzata.', 'xb' ), array( 'status' => 401 ) );
		}
		$link = Link::get();
		$user = null === $link ? false : get_userdata( $link['user'] );
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'xb_build' ) ) {
			return new \WP_Error( 'xb_cms_owner', __( 'Chi ha creato il collegamento non è più amministratore: crea un nuovo codice.', 'xb' ), array( 'status' => 403 ) );
		}
		wp_set_current_user( $user->ID );
		return true;
	}

	/** A refused request is logged, at most once a minute (someone without the secret cannot fill the log). */
	private static function refused( string $why ): void {
		if ( false !== get_transient( 'xb_cms_refused' ) ) {
			return;
		}
		set_transient( 'xb_cms_refused', 1, MINUTE_IN_SECONDS );
		Plugin::instance()->versioning->store()->audit( 'cms_refused', 'cms', array( 'why' => $why ) );
	}

	private static function scope( string $scope ): ?\WP_Error {
		$link = Link::get();
		if ( null !== $link && $link[ $scope ] ) {
			return null;
		}
		$messages = array(
			'read'    => __( 'Questo collegamento non permette di scaricare il sito.', 'xb' ),
			'write'   => __( 'Questo collegamento non permette di inviare modifiche.', 'xb' ),
			'publish' => __( 'Questo collegamento non permette di pubblicare.', 'xb' ),
		);
		return new \WP_Error( 'xb_cms_scope', $messages[ $scope ], array( 'status' => 403 ) );
	}

	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Any valid link may ask: the answer says what this link may do, and nothing about the site's content. */
	public static function capabilities(): \WP_REST_Response|\WP_Error {
		Link::touch();
		$link = Link::get();
		if ( null === $link ) {
			return new \WP_Error( 'xb_cms_refused', __( 'Richiesta non autorizzata.', 'xb' ), array( 'status' => 401 ) );
		}
		return self::no_store( new \WP_REST_Response( PageProfileAdapter::capabilities( $link ) ) );
	}

	/**
	 * The profile as a ZIP; serve_archive() writes its bytes without the JSON wrapping. `x-histos-export` says what the
	 * reading is for, and all but a download leave the site as it was (docs/spec/cms-bridge.md §4).
	 */
	public static function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = self::scope( 'read' );
		if ( null !== $denied ) {
			return $denied;
		}
		try {
			$archive = PageProfileAdapter::export( in_array( $request->get_header( 'x-histos-export' ), array( 'read', 'linked', 'check' ), true ) ? (string) $request->get_header( 'x-histos-export' ) : 'adopt' );
		} catch ( \DomainException $e ) {
			return new \WP_Error( 'xb_cms_unavailable', $e->getMessage(), array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'xb_cms_export_failed', __( 'Non è stato possibile preparare il profilo del sito.', 'xb' ), array( 'status' => 500 ) );
		}
		Link::touch();
		Plugin::instance()->versioning->store()->audit( 'cms_export', 'cms', array( 'bytes' => strlen( $archive ) ) );
		$response = new \WP_REST_Response( $archive );
		$response->header( 'Content-Type', 'application/zip' );
		$response->header( 'Content-Disposition', 'attachment; filename="histos-cms-profile.xbsite"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return self::no_store( $response );
	}

	public static function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::apply( $request, false );
	}

	public static function publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::apply( $request, true );
	}

	private static function apply( \WP_REST_Request $request, bool $publish ): \WP_REST_Response|\WP_Error {
		$denied = self::scope( 'write' ) ?? ( $publish ? self::scope( 'publish' ) : null );
		if ( null !== $denied ) {
			return $denied;
		}
		if ( $publish && ! current_user_can( 'xb_publish' ) ) {
			return new \WP_Error( 'xb_cms_scope', __( 'Chi ha creato il collegamento non ha più il permesso di pubblicare.', 'xb' ), array( 'status' => 403 ) );
		}
		if ( 'application/zip' !== strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ), 2 )[0] ) ) ) {
			return new \WP_Error( 'xb_cms_type', __( 'Serve un archivio .xbsite versione 2.', 'xb' ), array( 'status' => 415 ) );
		}
		try {
			$report = PageProfileAdapter::import( $request->get_body(), $publish );
		} catch ( OperationConflictException $e ) {
			return new \WP_Error( 'xb_cms_operation_conflict', $e->getMessage(), array( 'status' => 409, 'complete' => false ) );
		} catch ( ProfileConflictException $e ) {
			return new \WP_Error( 'xb_cms_conflict', $e->getMessage(), array( 'status' => 409, 'conflicts' => $e->conflicts, 'complete' => false ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_cms_invalid', $e->getMessage(), array( 'status' => 422, 'complete' => false ) );
		} catch ( \DomainException $e ) {
			return new \WP_Error( 'xb_cms_preflight', $e->getMessage(), array( 'status' => 422, 'complete' => false ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'xb_cms_import_failed', __( 'Invio incompleto: controlla lo stato dell\'operazione prima di riprovare.', 'xb' ), array( 'status' => 500, 'complete' => false ) );
		}
		Link::touch();
		Plugin::instance()->versioning->store()->audit(
			$publish ? 'cms_publish' : 'cms_import',
			'cms',
			array(
				'project'   => $report['project']['id'],
				'operation' => $report['operationId'],
				'changed'   => count( $report['changed'] ),
				'media'     => $report['media'],
				'published' => $report['published'],
			)
		);
		return self::no_store( new \WP_REST_Response( $report, 200 ) );
	}

	/**
	 * The language links of a send. Each link whose two contents exist here goes to `xb_remote_language_link`; a link
	 * to a content that waits for the next release waits with it, and every send replaces the list. A site without a
	 * language plugin answers that it took none.
	 */
	public static function languages( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = self::scope( 'write' );
		if ( null !== $denied ) {
			return $denied;
		}
		$links = json_decode( (string) $request->get_body(), true )['links'] ?? null;
		if ( ! is_array( $links ) || count( $links ) > self::MAX_LANGUAGE_LINKS ) {
			return new \WP_Error( 'xb_cms_invalid', __( 'Legami fra le lingue non validi.', 'xb' ), array( 'status' => 422 ) );
		}
		$taken   = 0;
		$waiting = array();
		foreach ( $links as $link ) {
			$link = self::language_link( $link );
			if ( null === $link ) {
				continue;
			}
			if ( self::give_language_link( $link ) ) {
				++$taken;
			} else {
				$waiting[] = $link;
			}
		}
		update_option( self::WAITING_LANGUAGE_LINKS, $waiting, false );
		Link::touch();
		return self::no_store( new \WP_REST_Response( array( 'taken' => has_action( 'xb_remote_language_link' ) ? $taken + count( $waiting ) : 0 ) ) );
	}

	/** After a release, the links whose contents it has just created. */
	public static function apply_waiting_language_links(): void {
		$waiting = get_option( self::WAITING_LANGUAGE_LINKS, array() );
		if ( ! is_array( $waiting ) || array() === $waiting ) {
			return;
		}
		$left = array_filter(
			array_map( array( self::class, 'language_link' ), $waiting ),
			static fn ( ?array $link ): bool => null !== $link && ! self::give_language_link( $link )
		);
		update_option( self::WAITING_LANGUAGE_LINKS, array_values( $left ), false );
	}

	/** @return array{translationId: string, sourceId: string, language: string, mode: string}|null */
	private static function language_link( mixed $link ): ?array {
		$link        = is_array( $link ) ? $link : array();
		$translation = $link['translationId'] ?? null;
		$source      = $link['sourceId'] ?? null;
		$language    = $link['language'] ?? null;
		$mode        = $link['mode'] ?? null;
		if ( ! PageProfileAdapter::valid_profile_identity( $translation ) || ! PageProfileAdapter::valid_profile_identity( $source ) || ! is_string( $language ) || 1 !== preg_match( '/^[A-Za-z0-9-]{1,35}$/', $language ) || ! in_array( $mode, array( 'follow', 'independent' ), true ) ) {
			return null;
		}
		return array( 'translationId' => $translation, 'sourceId' => $source, 'language' => $language, 'mode' => $mode );
	}

	/**
	 * Hands a link to the language plugin, once both contents exist.
	 *
	 * @param array{translationId: string, sourceId: string, language: string, mode: string} $link
	 */
	private static function give_language_link( array $link ): bool {
		$translation = PageProfileAdapter::post_of_entity( $link['translationId'] );
		$source      = PageProfileAdapter::post_of_entity( $link['sourceId'] );
		if ( 0 === $translation || 0 === $source ) {
			return false;
		}
		/**
		 * A content sent by Histeon Publish is the version in $language of another one.
		 *
		 * @param int    $translation The translation's post.
		 * @param int    $source      The original's post.
		 * @param string $language    BCP 47 code, as the project writes it.
		 * @param string $mode        `follow` or `independent`.
		 */
		do_action( 'xb_remote_language_link', $translation, $source, $link['language'], $link['mode'] );
		return true;
	}

	public static function status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link   = Link::get();
		$status = null === $link ? null : PageProfileAdapter::status( (string) $request['operationId'], $link['user'] );
		if ( null === $status ) {
			return new \WP_Error( 'xb_cms_operation_missing', __( 'Operazione non trovata per questo collegamento.', 'xb' ), array( 'status' => 404 ) );
		}
		return self::no_store( new \WP_REST_Response( $status ) );
	}

	/** A signed disconnect: the link stops working; the project's content and history stay. */
	public static function revoke(): \WP_REST_Response {
		Plugin::instance()->versioning->store()->audit( 'cms_revoke', 'cms', array( 'by' => 'cms' ) );
		Link::revoke();
		return self::no_store( new \WP_REST_Response( array( 'revoked' => true ) ) );
	}

	/**
	 * GET: which project pages are the site's home and posts page. POST `{"frontPage": id, "postsPage": id|null}`: makes
	 * them so, after the user said yes in Histeon Publish. Only published pages of the project, by their identity.
	 */
	public static function front_page( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( 'POST' !== $request->get_method() ) {
			$denied = self::scope( 'read' );
			if ( null !== $denied ) {
				return $denied;
			}
			Link::touch();
			return self::no_store( new \WP_REST_Response( FrontPage::state() ) );
		}
		$denied = self::scope( 'write' ) ?? self::scope( 'publish' );
		if ( null !== $denied ) {
			return $denied;
		}
		// Settings → Reading is an administrator's choice, and it changes the live site at once.
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_publish' ) ) {
			return new \WP_Error( 'xb_cms_scope', __( 'Chi ha creato il collegamento non ha più il permesso di cambiare la pagina iniziale.', 'xb' ), array( 'status' => 403 ) );
		}
		$body = json_decode( (string) $request->get_body(), true );
		try {
			$changed = FrontPage::apply( $body );
		} catch ( \DomainException $e ) {
			return new \WP_Error( 'xb_cms_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}
		Link::touch();
		Plugin::instance()->versioning->store()->audit( 'cms_front_page', 'cms', $changed );
		return self::no_store( new \WP_REST_Response( FrontPage::state() ) );
	}

	/**
	 * The pages that change in development, each with a preview link of PREVIEW_LINKS_TTL seconds seen as a visitor
	 * without an account (`guest`), signed by the link's owner. Only documents of Versioning::changes() with a page to
	 * see (a template opens on a page that uses it); components, styles and settings show in those pages. Nothing is
	 * chosen by the caller: no post, role or duration. The tokens are never written to the log.
	 */
	public static function preview_links(): \WP_REST_Response|\WP_Error {
		$denied = self::scope( 'read' );
		if ( null !== $denied ) {
			return $denied;
		}
		$pages   = array();
		$seen    = array();
		$skipped = 0;
		foreach ( Plugin::instance()->versioning->changes() as $change ) {
			$id   = Documents::post_id( $change['doc'] );
			$post = null === $id ? null : get_post( $id );
			if ( ! $post instanceof \WP_Post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$template = Templates::is_template( $post );
			if ( ! $template && ! is_post_type_viewable( $post->post_type ) ) {
				continue;
			}
			if ( count( $pages ) >= self::PREVIEW_LINKS_MAX ) {
				++$skipped;
				continue;
			}
			$url  = Preview::url( $post->ID, $template ? Templates::url( $post ) : null, 'guest', self::PREVIEW_LINKS_TTL );
			$page = remove_query_arg( Preview::PARAM, $url );
			if ( isset( $seen[ $page ] ) ) {
				continue;
			}
			$seen[ $page ] = true;
			$pages[]       = array(
				'doc'   => $change['doc'],
				'title' => mb_substr( $change['label'], 0, 160 ),
				'page'  => $page,
				'url'   => $url,
			);
		}
		Link::touch();
		Plugin::instance()->versioning->store()->audit( 'cms_preview_links', 'cms', array( 'links' => count( $pages ) ) );
		return self::no_store(
			new \WP_REST_Response(
				array(
					'pages'   => $pages,
					'skipped' => $skipped,
					'expires' => time() + self::PREVIEW_LINKS_TTL,
				)
			)
		);
	}

	/** @param mixed $served @param mixed $result @param mixed $request @param mixed $server */
	public static function serve_archive( mixed $served, mixed $result, mixed $request, mixed $server ): bool {
		if ( $served || ! $request instanceof \WP_REST_Request || ! $result instanceof \WP_REST_Response || self::route( self::EXPORT ) !== $request->get_route() ) {
			return (bool) $served;
		}
		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return false;
		}
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the ZIP bytes built by PageProfile are the response body.
		return true;
	}
}
