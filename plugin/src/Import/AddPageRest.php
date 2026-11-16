<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import;

use XB\Admin\CmsScreen;
use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;
use XB\Rest\Controller;

/**
 * V5.2, «Importa una pagina di questo sito»: the two readings «Importa HTML» needs to add one page of this site to its
 * Histeon project. Both are read-only, for who may convert a site (CmsScreen::can_import(): `manage_options` and
 * `xb_build`, with the REST nonce of the cookie), never cached.
 *
 * - `GET /xb/v1/import/pagina/elenco`: the published pages the user may edit, with their address, to choose from. The
 *   pages already of Histeon are left out (counted): importing them again makes no sense.
 * - `GET /xb/v1/import/pagina/base`: the site's base for the converter (`AddPageBase` of Histeon Publish, see
 *   PageProfileAdapter::add_page_base()): project, the styles entity as it is, every page slug, every entity id, the
 *   media entities, and in base64 the bytes of the media the styles name. Payloads are passed untouched (as their
 *   canonical JSON, so an empty map stays `{}`): the converter recomputes every revision hash and refuses a changed one.
 *
 * The browser downloads the page itself, as a visitor; this server fetches nothing.
 */
final class AddPageRest {

	public const LIST = '/import/pagina/elenco';
	public const BASE = '/import/pagina/base';

	/** At most this many pages in the list; past it the list says so. */
	public const MAX_PAGES = 5000;
	/** The limits of the converter's base (ADD_PAGE_LIMITS of add-page.ts); past them the site cannot add a page here. */
	public const MAX_SLUGS       = 100000;
	public const MAX_ENTITY_IDS  = 100000;
	public const MAX_ASSETS      = 10000;
	public const MAX_STYLES      = 20000;
	public const MAX_GLOBALS     = 1000;
	public const MAX_STYLES_JSON = 33554432;
	/** The bytes of the media the styles use, sent in base64 in one answer: lower than the converter's 200 MB. */
	public const MAX_FILES_BYTES = 33554432;

	public static function register(): void {
		foreach ( array( self::LIST => 'pages', self::BASE => 'base' ) as $route => $callback ) {
			register_rest_route(
				Controller::NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, $callback ),
					'permission_callback' => static fn (): bool => CmsScreen::can_import(),
				)
			);
		}
	}

	private static function fresh( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** The published pages the user may edit, Histeon pages and password-protected ones left out and counted. */
	public static function pages(): \WP_REST_Response {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_PAGES + 1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$more      = count( $ids ) > self::MAX_PAGES;
		$pages     = array();
		$histeon   = 0;
		$protected = 0;
		foreach ( array_slice( $ids, 0, self::MAX_PAGES ) as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			if ( PageProfileAdapter::is_histeon_page( $post ) ) {
				++$histeon;
				continue;
			}
			// A visitor sees only the password form: there is nothing to import.
			if ( '' !== $post->post_password ) {
				++$protected;
				continue;
			}
			$url = (string) get_permalink( $post );
			if ( '' === $url ) {
				continue;
			}
			$pages[] = array(
				'id'    => $post->ID,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'   => $url,
				'path'  => (string) wp_parse_url( $url, PHP_URL_PATH ),
				'slug'  => $post->post_name,
			);
		}
		return self::fresh(
			new \WP_REST_Response(
				array(
					'pages'     => $pages,
					'histeon'   => $histeon,
					'protected' => $protected,
					'more'      => $more,
				)
			)
		);
	}

	/** The site's base (AddPageBase), or a 409 that says why a page cannot be added to it now. */
	public static function base(): \WP_REST_Response|\WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the site's media are read once.
		}
		try {
			$base = PageProfileAdapter::add_page_base();
		} catch ( \DomainException | \RuntimeException $e ) {
			return new \WP_Error( 'xb_import_base_unavailable', $e->getMessage(), array( 'status' => 409 ) );
		}
		$styles = PageProfile::canonical( $base['styles'] );
		$files  = 0;
		foreach ( $base['files'] as $bytes ) {
			$files += strlen( $bytes );
		}
		$too_big = match ( true ) {
			count( $base['slugs'] ) > self::MAX_SLUGS => sprintf( /* translators: %d: limit */ __( 'più di %d pagine', 'xb' ), self::MAX_SLUGS ),
			count( $base['entityIds'] ) > self::MAX_ENTITY_IDS => sprintf( /* translators: %d: limit */ __( 'più di %d elementi di progetto', 'xb' ), self::MAX_ENTITY_IDS ),
			count( $base['assets'] ) > self::MAX_ASSETS => sprintf( /* translators: %d: limit */ __( 'più di %d media di Histeon', 'xb' ), self::MAX_ASSETS ),
			count( $base['styles']['payload']['styles'] ) > self::MAX_STYLES => sprintf( /* translators: %d: limit */ __( 'più di %d stili', 'xb' ), self::MAX_STYLES ),
			count( $base['styles']['payload']['globals'] ) > self::MAX_GLOBALS => sprintf( /* translators: %d: limit */ __( 'più di %d fogli globali', 'xb' ), self::MAX_GLOBALS ),
			strlen( $styles ) > self::MAX_STYLES_JSON => __( 'stili oltre 32 MB', 'xb' ),
			$files > self::MAX_FILES_BYTES => __( 'immagini e caratteri usati dagli stili oltre 32 MB', 'xb' ),
			default => null,
		};
		if ( null !== $too_big ) {
			return new \WP_Error(
				'xb_import_base_too_big',
				/* translators: %s: what is past the limit */
				sprintf( __( 'Il sito è troppo grande per aggiungere una pagina da qui (%s). Converti la pagina con Histeon Publish.', 'xb' ), $too_big ),
				array( 'status' => 409 )
			);
		}
		$encoded = array();
		foreach ( $base['files'] as $path => $bytes ) {
			$encoded[ $path ] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- media bytes for the page.
		}
		return self::fresh(
			new \WP_REST_Response(
				array(
					'projectId' => $base['projectId'],
					// As the archive writes them: the converter hashes what it reads, and an empty map must stay `{}`.
					'styles'    => json_decode( $styles, false, 512, JSON_THROW_ON_ERROR ),
					'slugs'     => $base['slugs'],
					'entityIds' => $base['entityIds'],
					'assets'    => array_map( static fn ( array $asset ): mixed => json_decode( PageProfile::canonical( $asset ), false, 512, JSON_THROW_ON_ERROR ), $base['assets'] ),
					'files'     => (object) $encoded,
				)
			)
		);
	}
}
