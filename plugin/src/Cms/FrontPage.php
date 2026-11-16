<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Cms;

use XB\Remote\PageProfileAdapter;

/**
 * The site's home and posts page (Settings → Reading: `show_on_front`, `page_on_front`, `page_for_posts`) as Histeon
 * Publish reads and sets them (docs/spec/cms-bridge.md §18). On a new WordPress the home of the project stays a page at
 * /home/ and the posts list stays the front page; Publish proposes, after the first send with publishing, to make
 * them as in the project, and sets them only when the user says yes (doc 23 item 201 of Histeon Publish).
 *
 * Only the project's own published pages, by their profile identity: never a post id, a title or a page of someone
 * else, read or written.
 */
final class FrontPage {

	/** Said in the capabilities: the site has the route FRONT_PAGE of the bridge. */
	public const CAPABILITY = 'histos-front-page-v1';

	/**
	 * What the site shows on its home: `posts` or `page`, and the identities of the project pages that are its home and
	 * its posts page. A page that is not of the project counts only as `frontPageOther` / `postsPageOther`.
	 *
	 * @return array{showOnFront: string, frontPage: string|null, postsPage: string|null, frontPageOther: bool, postsPageOther: bool}
	 */
	public static function state(): array {
		$static = 'page' === get_option( 'show_on_front' );
		$front  = $static ? (int) get_option( 'page_on_front' ) : 0;
		$posts  = $static ? (int) get_option( 'page_for_posts' ) : 0;
		$front_id = self::project_page( $front );
		$posts_id = self::project_page( $posts );
		return array(
			'showOnFront'    => $static ? 'page' : 'posts',
			'frontPage'      => $front_id,
			'postsPage'      => $posts_id,
			'frontPageOther' => $front > 0 && null === $front_id,
			'postsPageOther' => $posts > 0 && null === $posts_id,
		);
	}

	/**
	 * Sets the home (and the posts page, when given) to pages of the project. `postsPage` null keeps the current posts
	 * page, unless it is the new home, which WordPress cannot show twice: then the site has none.
	 *
	 * @return array{before: array{show: string, front: int, posts: int}, after: array{show: string, front: int, posts: int}}
	 * @throws \DomainException When the request is not exactly two identities of published pages of the project.
	 */
	public static function apply( mixed $body ): array {
		if ( ! is_array( $body ) || array( 'frontPage', 'postsPage' ) !== self::sorted_keys( $body ) ) {
			throw new \DomainException( __( 'Richiesta della pagina iniziale non valida.', 'xb' ) );
		}
		$front = self::published_page( $body['frontPage'] );
		$posts = null === $body['postsPage'] ? null : self::published_page( $body['postsPage'] );
		if ( null === $front || ( null !== $body['postsPage'] && null === $posts ) ) {
			throw new \DomainException( __( 'La pagina scelta non è una pagina pubblicata del progetto su questo sito.', 'xb' ) );
		}
		if ( $front === $posts ) {
			throw new \DomainException( __( 'La pagina iniziale e la pagina degli articoli devono essere due pagine diverse.', 'xb' ) );
		}
		$before = self::options();
		if ( null === $posts ) {
			$posts = $before['posts'] === $front ? 0 : $before['posts'];
		}
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $posts );
		update_option( 'show_on_front', 'page' );
		return array( 'before' => $before, 'after' => self::options() );
	}

	/** The redirect of the addresses posts had before they moved to /blog/ (doc 23 item 235). */
	public static function boot(): void {
		add_action( 'template_redirect', array( self::class, 'redirect_old_post_address' ), 9 );
	}

	/**
	 * With the posts at /blog/<slug>/, a request for /<slug>/ that WordPress cannot find, and that is the slug of a
	 * published post, goes there for good (301). WordPress's own guess does not try it: such an address reads as a
	 * page. Only one segment, only posts, only with this permalink structure: nothing else is touched.
	 */
	public static function redirect_old_post_address(): void {
		if ( ! is_404() || self::POSTS_PERMALINK !== get_option( 'permalink_structure' ) ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a slug pattern below.
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		if ( 1 !== preg_match( '#^/([a-z0-9%_-]{1,200})/?$#', $path, $match ) ) {
			return;
		}
		$post = get_page_by_path( sanitize_title( urldecode( $match[1] ) ), 'OBJECT', 'post' );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password ) {
			wp_safe_redirect( (string) get_permalink( $post ), 301 );
			exit;
		}
	}

	/** Where Publish writes posts (translations.ts, postAddress): /blog/<slug>/. */
	public const POSTS_PERMALINK = '/blog/%postname%/';

	/**
	 * What a file import can still do for the site to look as in Publish (doc 23 items 234-235), from the plan's
	 * `site`: make the project's home the front page (and its «blog» page the posts page), give the posts a «Blog»
	 * page when the project has none, and write the posts at /blog/<slug>/. Each entry is offered only when it
	 * would change something, and is on unless it would change what the site already had of its own.
	 *
	 * @param array<string, mixed> $site The plan's `site` (PageProfileAdapter::check_plan).
	 * @return array{front?: array{home: string, blog: string|null, on: bool}, blog?: array{on: bool}, permalinks?: array{current: string, on: bool}}
	 */
	public static function offers( array $site ): array {
		$out   = array();
		$state = self::state();
		$home  = is_array( $site['homePage'] ?? null ) ? $site['homePage'] : null;
		$blog  = is_array( $site['blogPage'] ?? null ) ? $site['blogPage'] : null;
		$posts = (int) ( $site['posts'] ?? 0 );
		if ( null !== $home && ( 'page' !== $state['showOnFront'] || $state['frontPage'] !== $home['id'] || ( null !== $blog && $state['postsPage'] !== $blog['id'] ) ) ) {
			$out['front'] = array(
				'home' => (string) $home['title'],
				'blog' => null === $blog ? null : (string) $blog['title'],
				'on'   => ! $state['frontPageOther'],
			);
		}
		if ( null === $blog && $posts > 0 && ( 'page' !== $state['showOnFront'] || 0 === (int) get_option( 'page_for_posts' ) ) ) {
			$out['blog'] = array( 'on' => ! $state['postsPageOther'] );
		}
		$current = (string) get_option( 'permalink_structure' );
		if ( $posts > 0 && self::POSTS_PERMALINK !== $current ) {
			$out['permalinks'] = array(
				'current' => $current,
				'on'      => true,
			);
		}
		return $out;
	}

	/**
	 * Does what the person ticked of `offers()` after a published import, and says what changed. Each part that
	 * fails is said and the others still run: the import itself is already done.
	 *
	 * @param array<string, mixed> $site   The plan's `site`.
	 * @param array<string, bool>  $chosen `front`, `blog`, `permalinks`.
	 * @return list<string> What changed, or why a part did not.
	 */
	public static function after_import( array $site, array $chosen ): array {
		$said   = array();
		$offers = self::offers( $site );
		if ( isset( $offers['front'] ) && ! empty( $chosen['front'] ) ) {
			try {
				self::apply( array( 'frontPage' => $site['homePage']['id'], 'postsPage' => $site['blogPage']['id'] ?? null ) );
				$said[] = __( 'La home del progetto è la pagina iniziale del sito.', 'xb' );
			} catch ( \DomainException $error ) {
				$said[] = $error->getMessage();
			}
		}
		if ( isset( $offers['blog'] ) && ! empty( $chosen['blog'] ) ) {
			$page = get_page_by_path( 'blog', 'OBJECT', 'page' );
			$id   = $page instanceof \WP_Post ? (int) $page->ID : (int) wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => __( 'Blog', 'xb' ),
					'post_name'   => 'blog',
				),
				false
			);
			if ( $id > 0 && 'publish' === get_post_status( $id ) && (int) get_option( 'page_on_front' ) !== $id ) {
				update_option( 'page_for_posts', $id );
				if ( 'page' !== get_option( 'show_on_front' ) ) {
					update_option( 'show_on_front', 'page' );
				}
				$said[] = __( 'Gli articoli sono elencati nella pagina «Blog», a /blog/.', 'xb' );
			} else {
				$said[] = __( 'La pagina «Blog» non si è potuta preparare: scegli la pagina degli articoli in Impostazioni → Lettura.', 'xb' );
			}
		}
		if ( isset( $offers['permalinks'] ) && ! empty( $chosen['permalinks'] ) ) {
			global $wp_rewrite;
			// Categories and tags at /category/… and /tag/…, as in Publish: an explicit base keeps them out of /blog/.
			if ( '' === (string) get_option( 'category_base' ) ) {
				update_option( 'category_base', 'category' );
			}
			if ( '' === (string) get_option( 'tag_base' ) ) {
				update_option( 'tag_base', 'tag' );
			}
			$wp_rewrite->set_permalink_structure( self::POSTS_PERMALINK );
			flush_rewrite_rules( false );
			$said[] = __( 'Gli articoli sono a /blog/nome-articolo/, come in Publish; i vecchi indirizzi portano ai nuovi.', 'xb' );
		}
		return $said;
	}

	/** @return array{show: string, front: int, posts: int} */
	private static function options(): array {
		return array(
			'show'  => 'page' === get_option( 'show_on_front' ) ? 'page' : 'posts',
			'front' => (int) get_option( 'page_on_front' ),
			'posts' => (int) get_option( 'page_for_posts' ),
		);
	}

	/**
	 * @param array<mixed> $body
	 * @return list<int|string>
	 */
	private static function sorted_keys( array $body ): array {
		$keys = array_keys( $body );
		sort( $keys );
		return $keys;
	}

	/** The identity of a project page, when the post is one. */
	private static function project_page( int $post_id ): ?string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		return $post instanceof \WP_Post && 'page' === $post->post_type ? PageProfileAdapter::entity_of_post( $post_id ) : null;
	}

	/** The post of a published page of the project with this identity, or null. */
	private static function published_page( mixed $id ): ?int {
		if ( ! PageProfileAdapter::valid_profile_identity( $id ) ) {
			return null;
		}
		$post_id = PageProfileAdapter::post_of_entity( $id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		return $post instanceof \WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password ? $post_id : null;
	}
}
