<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;
use XB\Styles\StyleRegistry;

/**
 * Viewing the dev branch — threat model T9.
 *
 * A preview link carries `xb_preview=<expiry>.<hmac>`: it works without logging in (to share with a client),
 * expires (48 hours by default, or the duration chosen in the builder; never more than 7 days), and every link is revoked at once by rotating the
 * secret. Preview responses are never cached or indexed. The builder canvas uses the same dev data.
 */
final class Preview {

	public const PARAM  = 'xb_preview';
	public const SECRET = 'xb_preview_secret';
	private const TTL   = 2 * DAY_IN_SECONDS;
	private const MAX   = 7 * DAY_IN_SECONDS;
	/** The durations the builder offers for a link (V3): 1 hour, 1 day, 2 days (the default), 7 days. */
	public const LINK_TTLS = array( HOUR_IN_SECONDS, DAY_IN_SECONDS, 2 * DAY_IN_SECONDS, 7 * DAY_IN_SECONDS );
	/** A proposal's link lasts the longest a link may: the client looks at it over some days (lista della spesa 160). */
	public const PROPOSAL_TTL = self::MAX;
	/** Two proposals side by side: `?xb_compare=<token>~<token>`. */
	public const COMPARE = 'xb_compare';

	private static bool $dev = false;
	/** This preview runs with scripts blocked (unreviewed dev content): no review bar either. */
	private static bool $blocked = false;
	/** The one post a page token opens (0: a template token, or the builder, which may show any post). */
	private static int $scope = 0;
	/** Builder or agent rendering: dev content only of the documents the current user may edit. */
	private static bool $for_editor = false;
	/** @var array<string, array<string, mixed>|null> */
	private static array $payloads = array();
	/** @var array<string, string>|null The documents of the proposal shown (document → commit), or null. */
	private static ?array $proposal = null;
	/** The role the preview is seen as (lista della spesa 161), '' for the real visitor. */
	private static string $as = '';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'detect' ), 1 );
		add_filter( 'the_posts', array( self::class, 'swap_posts' ) );
		add_filter( 'xb_document_content', array( self::class, 'content' ), 10, 2 );
		add_filter( 'xb_document_script', array( self::class, 'script' ), 10, 2 );
		add_filter( 'xb_document_styles', array( self::class, 'page_styles' ), 10, 2 );
	}

	/**
	 * Token for one post, issued by one user: `<expiry>.<post>.<user>.<hmac>`. It stops working when it
	 * expires, when the secret is rotated, or when the issuer can no longer edit the post.
	 */
	public static function token( int $post_id, int $ttl = self::TTL, string $proposal = '', string $as = '' ): string {
		$expiry = time() + min( max( 60, $ttl ), self::MAX );
		$user   = get_current_user_id();
		// A proposal, and the role the page is seen as, go inside the signature: `<expiry>.<post>.<user>.<extra>.<hmac>`
		// with `<extra>` = `<proposal>`, `as-<role>` or `<proposal>-as-<role>`.
		$extra = $proposal . ( '' === $as ? '' : ( '' === $proposal ? '' : '-' ) . 'as-' . $as );
		return $expiry . '.' . $post_id . '.' . $user . ( '' === $extra ? '' : '.' . $extra ) . '.' . self::mac( $expiry, $post_id, $user, $extra );
	}

	/** @return int|null The post the token was issued for, when valid. */
	public static function validate( string $token ): ?int {
		return self::read( $token )['post'] ?? null;
	}

	/** @return array{post: int, proposal: string, as: string}|null */
	private static function read( string $token ): ?array {
		$parts = explode( '.', $token );
		$extra = 5 === count( $parts ) ? (string) $parts[3] : '';
		$match = array();
		if ( ( 4 !== count( $parts ) && 5 !== count( $parts ) ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) || ( 5 === count( $parts ) && 1 !== preg_match( '/^([0-9a-f]{12})?(?:-?as-([a-z0-9_]{1,40}))?$/', $extra, $match ) ) || ( 5 === count( $parts ) && '' === $extra ) ) {
			return null;
		}
		[ $expiry, $post, $user ] = array( (int) $parts[0], (int) $parts[1], (int) $parts[2] );
		if ( $expiry < time() || $expiry > time() + self::MAX || ! hash_equals( self::mac( $expiry, $post, $user, $extra ), (string) end( $parts ) ) ) {
			return null;
		}
		$as = (string) ( $match[2] ?? '' );
		if ( '' !== $as && ! isset( self::roles()[ $as ] ) ) {
			return null;
		}
		return user_can( $user, 'xb_build' ) && user_can( $user, 'edit_post', $post ) ? array( 'post' => $post, 'proposal' => (string) ( $match[1] ?? '' ), 'as' => $as ) : null;
	}

	/**
	 * The roles a preview can be seen as (lista della spesa 161): a visitor without an account, and every role of the
	 * site that cannot write (subscriber, the shop's customer…). Only Histeon's `user` changes: WordPress's own
	 * permissions in that request stay the anonymous visitor's, and no account is ever used.
	 *
	 * @return array<string, string> role → label
	 */
	public static function roles(): array {
		$out = array( 'guest' => __( 'Chi visita, senza accesso', 'xb' ) );
		foreach ( wp_roles()->roles as $role => $info ) {
			if ( 1 === preg_match( '/^[a-z0-9_]{1,40}$/', (string) $role ) && empty( $info['capabilities']['edit_posts'] ) ) {
				$out[ (string) $role ] = translate_user_role( (string) ( $info['name'] ?? $role ) );
			}
		}
		return $out;
	}

	/** The role this preview is seen as ('' when none: the real visitor). */
	public static function seen_as(): string {
		return self::$as;
	}

	public static function valid( string $token ): bool {
		return null !== self::validate( $token );
	}

	private static function mac( int $expiry, int $post, int $user, string $proposal = '' ): string {
		return hash_hmac( 'sha256', 'xb-preview|' . $expiry . '|' . $post . '|' . $user . ( '' === $proposal ? '' : '|proposal|' . $proposal ), self::secret() );
	}

	/**
	 * @param string|null $page Where to open it: for a template, a page that uses it (default: the post itself).
	 * @param int         $ttl  How long it lasts, in seconds: `token()` keeps it between a minute and 7 days.
	 */
	public static function url( int $post_id, ?string $page = null, string $as = '', int $ttl = self::TTL ): string {
		$link = $page ?? get_permalink( $post_id );
		return add_query_arg( self::PARAM, self::token( $post_id, $ttl, '', isset( self::roles()[ $as ] ) ? $as : '' ), is_string( $link ) ? $link : home_url( '/' ) );
	}

	/** When a preview link stops working (Unix time, from its token), or null when the URL carries none. */
	public static function expires( string $url ): ?int {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$token = $query[ self::PARAM ] ?? '';
		$first = is_string( $token ) ? explode( '.', $token )[0] : '';
		return ctype_digit( $first ) ? (int) $first : null;
	}

	/** Whether this request goes to the REST API: checked at init, before WordPress parses it. */
	private static function rest_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only its presence is read.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) ) {
			return true;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = trailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$prefix = trailingslashit( (string) wp_parse_url( home_url( '/' . rest_get_url_prefix() ), PHP_URL_PATH ) );
		return str_starts_with( $path, $prefix );
	}

	public static function revoke(): void {
		update_option( self::SECRET, bin2hex( random_bytes( 32 ) ), false );
	}

	/**
	 * Frontend request with a valid token for the requested post: render dev, never cache, never index.
	 * If any unpublished dev content was written by an author without unfiltered_html, the page runs with
	 * scripts disabled (CSP): a preview link can never be a way to run someone's script in a reviewer's session.
	 */
	public static function detect(): void {
		self::reset();
		if ( isset( $_GET[ self::COMPARE ] ) && is_string( $_GET[ self::COMPARE ] ) && ! \XB\StaticSite\ExportMode::active() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC tokens are the credential.
			self::compare( sanitize_text_field( wp_unslash( $_GET[ self::COMPARE ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$token = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC token is the credential.
		if ( '' === $token || \XB\StaticSite\ExportMode::active() ) {
			return;
		}
		$read = self::read( $token );
		$post = $read['post'] ?? null;
		if ( null !== $read && '' !== $read['as'] ) {
			// Seen as a role: the request must not be someone's own session, or `user` would mix two people.
			wp_set_current_user( 0 );
			self::$as = $read['as'];
		}
		if ( null !== $read && '' !== $read['proposal'] ) {
			$proposal = Proposals::get( $read['proposal'] );
			if ( null === $proposal ) {
				return; // Deleted: the link shows the live site.
			}
			self::$proposal = $proposal['items'];
		}
		// A page's token opens that page; a template applies to many URLs, so its token opens any page, but never the
		// REST API, which would answer with development content of every post the issuer may edit.
		$template = null !== $post && \XB\Templates\Templates::is_template( get_post( $post ) );
		if ( null === $post || ( ! $template && $post !== self::requested_post() ) || ( $template && self::rest_request() ) ) {
			return;
		}
		self::use_dev( false );
		self::$scope = $template ? 0 : $post;
		if ( ! $template ) {
			// The main query is the final word on which post this request shows: if it is not the token's page
			// (a route the checks above could not foresee), nothing of the preview is shown.
			add_action(
				'wp',
				static function () use ( $post ): void {
					if ( ! is_singular() || (int) get_queried_object_id() !== $post ) {
						self::reset();
						wp_safe_redirect( remove_query_arg( self::PARAM ) );
						exit;
					}
				},
				0
			);
		}
		// The page as visitors see it: no admin bar, also for the logged-in author looking from the builder.
		add_filter( 'show_admin_bar', '__return_false' );
		$untrusted     = null !== self::$proposal ? self::has_untrusted_proposal( self::$proposal ) : ( $template ? self::has_untrusted_dev_content( 0 ) : self::has_untrusted_dev_content( $post ) );
		self::$blocked = $untrusted;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		add_action(
			'send_headers',
			static function () use ( $untrusted ): void {
				nocache_headers();
				header( 'X-Robots-Tag: noindex, nofollow' );
				header( 'Referrer-Policy: no-referrer' );
				if ( $untrusted ) {
					header( "Content-Security-Policy: script-src 'none'; object-src 'none'; base-uri 'none'" );
				}
			}
		);
		if ( $untrusted ) {
			// The CSP above stops every script; the ones WP and plugins print (the Navigation block's modules, their
			// inline data) are left out of the page, so the browser has nothing to refuse and nothing to report.
			add_action( 'template_redirect', static fn () => ob_start( array( self::class, 'without_scripts' ) ), PHP_INT_MAX );
		}
		// Marked as the preview's own: the page checks of the Publish window (V1.4) look for a `noindex` of the page.
		add_action( 'wp_head', static fn () => print( "<meta name=\"robots\" content=\"noindex, nofollow\" data-xb-preview>\n" ), 1 );
		add_action(
			'wp_footer',
			static fn () => printf(
				'<div data-xb-preview-badge style="position:fixed;inset:auto 12px 12px auto;z-index:99999;padding:6px 12px;border-radius:7px;background:#171a33;color:#f3ece1;font:13px/1.4 sans-serif;box-shadow:0 6px 20px rgb(23 26 51 / .25)">%s</div>',
				esc_html__( 'Anteprima di sviluppo Histeon: non è il sito pubblicato', 'xb' )
			)
		);
	}

	/**
	 * The post the current request is for (front page included), before the main query runs. Query variables that
	 * pick a post win over the path in WordPress, so they decide here too: a request whose variables name another
	 * post, or more than one, is for no post a page token may open.
	 */
	private static function requested_post(): int {
		$selected = array();
		foreach ( array( 'p', 'page_id', 'attachment_id', 'preview_id' ) as $var ) {
			if ( isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, as WordPress does.
				$selected[] = absint( $_GET[ $var ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		// Any other query variable of WordPress may pick another post (a taxonomy, an author, a date, a search):
		// only the ones that page through the same post leave the request on the path's post. A page's own
		// parameters (the filters of its loops) are not WordPress's and stay. Query variables registered later
		// (a content type's, a taxonomy's) are caught after the main query: see detect().
		global $wp;
		$public = $wp instanceof \WP ? $wp->public_query_vars : array();
		foreach ( array_keys( $_GET ) as $var ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$var = (string) $var;
			if ( in_array( $var, array( 'pagename', 'name', 'attachment', 'post_type', 'static', 'pageid', 'feed', 'embed', 's' ), true ) || ( in_array( $var, $public, true ) && ! in_array( $var, array( 'p', 'page_id', 'attachment_id', 'preview_id', 'preview', 'page', 'paged', 'cpage' ), true ) ) ) {
				return 0;
			}
		}
		if ( array() !== $selected ) {
			return 1 === count( array_unique( $selected ) ) ? $selected[0] : 0;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( rtrim( $path, '/' ) === rtrim( $home, '/' ) && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return url_to_postid( home_url( $path ) );
	}

	/**
	 * Unpublished dev content written without unfiltered_html, in the post, the styles, the settings or any component;
	 * with $post_id 0, anywhere (a template preview can show any page).
	 */
	private static function has_untrusted_dev_content( int $post_id ): bool {
		global $wpdb;
		if ( 0 === $post_id ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'refs' ) . ' r JOIN ' . Schema::table( 'commits' ) . " c ON c.id = r.commit_id WHERE r.branch = 'dev' AND c.trusted = 0" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( 'refs' ) . ' r JOIN ' . Schema::table( 'commits' ) . " c ON c.id = r.commit_id LEFT JOIN {$wpdb->posts} p ON r.doc = CONCAT('post:', p.ID) WHERE r.branch = 'dev' AND c.trusted = 0 AND ( r.doc IN (%s, %s, %s) OR p.post_type IN ('wp_block', 'wp_template', 'wp_template_part') )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Documents::for_post( $post_id ),
				Documents::STYLES,
				Documents::SETTINGS
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Whether any version in the proposal was written without unfiltered_html: its preview runs without scripts.
	 *
	 * @param array<string, string> $items
	 */
	private static function has_untrusted_proposal( array $items ): bool {
		$store = Plugin::instance()->versioning->store();
		foreach ( $items as $commit ) {
			$row = $store->get( $commit );
			if ( null === $row || ! $row['trusted'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Two proposals side by side (lista della spesa 160): a page with the two signed links in frames, nothing else.
	 * Each frame is an ordinary proposal preview and checks its own token; this page only shows names.
	 */
	private static function compare( string $value ): void {
		$tokens = explode( '~', $value );
		if ( 2 !== count( $tokens ) ) {
			return;
		}
		$sides = array();
		foreach ( $tokens as $token ) {
			$read     = self::read( $token );
			$proposal = null !== $read && '' !== $read['proposal'] ? Proposals::get( $read['proposal'] ) : null;
			if ( null === $proposal ) {
				return;
			}
			$link    = get_permalink( $read['post'] );
			$sides[] = array( 'name' => $proposal['name'], 'url' => add_query_arg( self::PARAM, $token, is_string( $link ) ? $link : home_url( '/' ) ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; frame-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html__( 'Confronto delle proposte', 'xb' ) . '</title>';
		echo '<style>body{margin:0;font:14px/1.4 system-ui,sans-serif;background:#f3ece1;color:#171a33}main{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px;height:calc(100vh - 24px);box-sizing:border-box}section{display:grid;grid-template-rows:auto 1fr;gap:6px;min-height:0}h2{margin:0;font-size:15px}iframe{width:100%;height:100%;border:1px solid #d9cfc0;border-radius:8px;background:#fff}@media (max-width:800px){main{grid-template-columns:1fr;height:auto}iframe{height:80vh}}</style></head><body><main>';
		foreach ( $sides as $side ) {
			printf( '<section><h2>%1$s</h2><iframe src="%2$s" title="%1$s" loading="lazy"></iframe></section>', esc_html( $side['name'] ), esc_url( $side['url'] ) );
		}
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Use dev data for this request (preview link or builder canvas). In the builder and for an agent
	 * ($for_editor) only the documents the current user may edit show their development version: a page in a loop
	 * or a list that someone else is still writing stays as published.
	 */
	public static function use_dev( bool $for_editor = true ): void {
		self::$dev        = true;
		self::$for_editor = $for_editor;
		$styles    = self::payload( Documents::STYLES );
		if ( null !== $styles ) {
			StyleRegistry::override(
				array(
					'styles'  => $styles['styles'] ?? array(),
					'globals' => $styles['globals'] ?? array(),
				)
			);
		}
	}

	/** A page of a preview with scripts blocked, without its scripts and module preloads. */
	public static function without_scripts( string $html ): string {
		// Every letter in its two cases, and no `\b` or `\s`: the `i` flag of PCRE follows the PHP locale, and in a Turkish
		// one `<SCRIPT>` and `<LINK>` stayed (T31, P1).
		$html = (string) preg_replace( '#<[sS][cC][rR][iI][pP][tT](?![A-Za-z0-9_])[^>]*>.*?</[sS][cC][rR][iI][pP][tT][ \t\n\x0B\f\r]*>#s', '', $html );
		return (string) preg_replace( '#<[lL][iI][nN][kK](?![A-Za-z0-9_])[^>]*(?<![A-Za-z0-9_])[rR][eE][lL]=(["\']?)[mM][oO][dD][uU][lL][eE][pP][rR][eE][lL][oO][aA][dD]\1[^>]*>#', '', $html );
	}

	public static function scripts_blocked(): bool {
		return self::$blocked;
	}

	public static function active(): bool {
		return self::$dev;
	}

	/**
	 * @param array<mixed> $posts
	 * @return array<mixed>
	 */
	public static function swap_posts( array $posts ): array {
		if ( ! self::$dev ) {
			return $posts;
		}
		foreach ( $posts as $i => $post ) {
			// A page token shows that page in development, with the components and templates it is drawn with;
			// other posts (in loops, menus, related lists) stay as published.
			if ( $post instanceof \WP_Post && ( 0 === self::$scope || $post->ID === self::$scope || in_array( $post->post_type, array( 'wp_block', 'wp_template', 'wp_template_part' ), true ) ) ) {
				$content = self::content( $post->post_content, $post->ID );
				if ( $content !== $post->post_content ) {
					$copy               = clone $post;
					$copy->post_content = $content;
					$posts[ $i ]        = $copy;
				}
			}
		}
		return $posts;
	}

	public static function content( string $content, int $post_id ): string {
		$payload = self::shows_dev( $post_id ) ? self::payload( Documents::for_post( $post_id ) ) : null;
		return is_string( $payload['content'] ?? null ) ? $payload['content'] : $content;
	}

	/** Whether this post shows its development version in this request. */
	private static function shows_dev( int $post_id ): bool {
		if ( ! self::$dev ) {
			return false;
		}
		if ( ! self::$for_editor ) {
			return true;
		}
		// Components and templates draw every page: the builder shows them as they will be published.
		$type = get_post_type( $post_id );
		return in_array( $type, array( 'wp_block', 'wp_template', 'wp_template_part' ), true ) || current_user_can( 'edit_post', $post_id );
	}

	public static function script( string $script, int $post_id ): string {
		$payload = self::shows_dev( $post_id ) ? self::payload( Documents::for_post( $post_id ) ) : null;
		return is_string( $payload['script'] ?? null ) ? $payload['script'] : $script;
	}

	/**
	 * The page's own look (voce 186) as the preview shows it: the development version when it shows dev.
	 *
	 * @param mixed $styles
	 * @return mixed
	 */
	public static function page_styles( mixed $styles, int $post_id ): mixed {
		$payload = self::shows_dev( $post_id ) ? self::payload( Documents::for_post( $post_id ) ) : null;
		return null === $payload ? $styles : ( $payload[ \XB\Styles\PageLook::KEY ] ?? array() );
	}

	/**
	 * The dev version of a document, for code that reads options directly (queries, allowed types).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function dev_payload( string $doc ): ?array {
		return self::payload( $doc );
	}

	/** @var array<string, int>|null Component keys of the development versions → their post, for this request. */
	private static ?array $dev_refs = null;

	/**
	 * The component that owns a key in its development version, for a key no published component owns yet: the key
	 * of a component is written on its post when a release publishes it, so a component imported or created since
	 * the last release is found only here. Only while development data is shown; the lowest post ID wins, as in
	 * ComponentRegistry::owner().
	 */
	public static function dev_component( string $ref ): ?int {
		if ( ! self::$dev ) {
			return null;
		}
		if ( null === self::$dev_refs ) {
			global $wpdb;
			$docs = $wpdb->get_col( 'SELECT r.doc FROM ' . Schema::table( 'refs' ) . " r JOIN {$wpdb->posts} p ON r.doc = CONCAT('post:', p.ID) WHERE r.branch = 'dev' AND p.post_type = 'wp_block' ORDER BY p.ID ASC LIMIT 2000" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$map  = array();
			foreach ( is_array( $docs ) ? $docs : array() as $doc ) {
				$id      = (int) substr( (string) $doc, 5 );
				$payload = self::shows_dev( $id ) ? self::payload( (string) $doc ) : null;
				$key     = $payload['ref'] ?? null;
				if ( is_string( $key ) && 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $key ) ) {
					$map[ $key ] ??= $id;
				}
			}
			self::$dev_refs = $map;
		}
		return self::$dev_refs[ $ref ] ?? null;
	}

	/** @return array<string, mixed>|null */
	private static function payload( string $doc ): ?array {
		if ( ! array_key_exists( $doc, self::$payloads ) ) {
			$store                  = Plugin::instance()->versioning->store();
			// A proposal shows its own versions, and the live site for everything else.
			$head                   = null !== self::$proposal ? ( self::$proposal[ $doc ] ?? $store->head( $doc, Store::PROD ) ) : $store->head( $doc, Store::DEV );
			self::$payloads[ $doc ] = null === $head ? null : ( $store->get( $head )['payload'] ?? null );
		}
		return self::$payloads[ $doc ];
	}

	/** Test helper: forget per-request state. */
	public static function reset(): void {
		self::$blocked    = false;
		self::$dev        = false;
		self::$for_editor = false;
		self::$scope    = 0;
		self::$payloads = array();
		self::$dev_refs = null;
		self::$proposal = null;
		self::$as       = '';
		StyleRegistry::override( null );
	}

	private static function secret(): string {
		$secret = get_option( self::SECRET );
		if ( ! is_string( $secret ) || strlen( $secret ) < 64 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET, $secret, false );
		}
		return $secret . wp_salt( 'auth' );
	}
}
