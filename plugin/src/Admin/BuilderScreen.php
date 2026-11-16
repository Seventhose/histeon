<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Rest\Controller;

/**
 * The builder: a full-screen page at wp-admin/admin.php?action=xb_builder&post=ID, outside the wp-admin chrome.
 * The app itself is built from editor/ into assets/builder/ (npm run build -w editor).
 */
final class BuilderScreen {

	/** Port of the Histeon Connector on 127.0.0.1 (editor/src/lib/connector.svelte.ts). */
	public const CONNECTOR_PORT = 7341;
	/**
	 * User meta: the random seed of the Histeon Connector's pairing secret (docs/spec/connector.md §3). The secret
	 * itself is never stored: it is derived from the seed and the site's salts, so a copy of the database alone
	 * does not give it away.
	 */
	public const CONNECTOR_SEED = 'xb_connector_seed';
	/** User meta of earlier versions: the secret in clear. Removed on first use and at uninstall. */
	public const CONNECTOR_SECRET = 'xb_connector_secret';

	public const ACTION = 'xb_builder';

	/** Admin pages of the CSS frameworks XB integrates, opened inside the builder (provider id → menu slug). */
	private const FRAMEWORK_PAGES = array(
		'acss'           => 'automatic-css',
		'core-framework' => 'core-framework',
	);

	public static function register(): void {
		add_action( 'admin_action_' . self::ACTION, array( self::class, 'render' ) );
		add_action( 'admin_post_xb_connector_rotate', array( self::class, 'rotate_connector_secret' ) );
		add_action( 'admin_head', array( self::class, 'framed_dashboard' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 80 );
	}

	/** @param bool $embed Opened over Gutenberg (EditorLaunch): the page may then be framed by this site only. */
	/**
	 * The user's pairing secret for the Histeon Connector: it goes into the `serve` command they copy, and the
	 * builder tab and the local connector prove to each other that they know it. An HMAC, with the site's auth
	 * salt, of the user id and a random seed made on first use: rotating replaces the seed.
	 */
	public static function connector_secret( int $user_id ): string {
		// An earlier version kept the secret itself: a new one is derived instead (the user copies the
		// instructions again once), and the old one leaves the database.
		delete_user_meta( $user_id, self::CONNECTOR_SECRET );
		$seed = (string) get_user_meta( $user_id, self::CONNECTOR_SEED, true );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $seed ) ) {
			$seed = bin2hex( random_bytes( 32 ) );
			update_user_meta( $user_id, self::CONNECTOR_SEED, $seed );
		}
		$mac = hash_hmac( 'sha256', 'xb-connector|' . $user_id . '|' . $seed, wp_salt( 'auth' ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
	}

	/** The origin of the admin pages: the only one the connector accepts builder tabs from. */
	public static function admin_origin(): string {
		$parts  = wp_parse_url( admin_url() );
		$origin = strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) ( $parts['host'] ?? '' ) );
		return isset( $parts['port'] ) ? $origin . ':' . (int) $parts['port'] : $origin;
	}

	/** A new secret: a connector started with the old one no longer accepts this user's tabs. */
	public static function rotate_connector_secret(): void {
		check_admin_referer( 'xb_connector_rotate' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'xb' ), '', array( 'response' => 403 ) );
		}
		delete_user_meta( get_current_user_id(), self::CONNECTOR_SEED );
		self::connector_secret( get_current_user_id() );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	public static function url( int $post_id, bool $embed = false ): string {
		$args = array(
			'action' => self::ACTION,
			'post'   => $post_id,
		);
		if ( $embed ) {
			$args['embed'] = '1';
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function can_open( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Controller::DOCUMENT_TYPES, true ) ) {
			return false;
		}
		// A client opens the pages his permissions reach, also those he may only see (voce 186).
		if ( \XB\Capabilities::content_only() ) {
			return \XB\Security\ClientAccess::allows( $post_id, 'view' );
		}
		return current_user_can( 'xb_build' ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, \WP_Post $post ): array {
		if ( self::can_open( $post->ID ) ) {
			$actions['xb_builder'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( $post->ID ) ), esc_html__( 'Modifica con Histeon', 'xb' ) );
		}
		return $actions;
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && self::can_open( $post->ID ) ) {
			$bar->add_node(
				array(
					'id'    => 'xb-builder',
					'title' => esc_html__( 'Modifica con Histeon', 'xb' ),
					'href'  => self::url( $post->ID ),
				)
			);
		}
	}

	/**
	 * The dashboard of the installed CSS framework (the active one first), when this user can open it: the
	 * admin menu, already built for this request, lists only the pages the user has the capability for.
	 *
	 * @phpstan-impure Reads the admin menu globals.
	 * @return array{id: string, label: string, url: string}|null
	 */
	public static function framework_panel(): ?array {
		global $menu, $submenu;
		$registered = array();
		foreach ( is_array( $menu ) ? $menu : array() as $item ) {
			$registered[ (string) ( $item[2] ?? '' ) ] = true;
		}
		foreach ( is_array( $submenu ) ? $submenu : array() as $items ) {
			foreach ( (array) $items as $item ) {
				$registered[ (string) ( $item[2] ?? '' ) ] = true;
			}
		}
		$frameworks = \XB\Plugin::instance()->frameworks;
		$active     = $frameworks->active();
		$providers  = array( $active->id() => $active ) + $frameworks->all();
		foreach ( $providers as $id => $provider ) {
			$slug = self::FRAMEWORK_PAGES[ $id ] ?? null;
			if ( null !== $slug && $provider->available() && isset( $registered[ $slug ] ) ) {
				return array(
					'id'    => (string) $id,
					'label' => $provider->label(),
					// Shown alone in the builder's side panel (framed_dashboard); "open in a new tab" drops xb_frame.
					'url'   => add_query_arg(
						array(
							'page'     => $slug,
							'xb_frame' => 1,
						),
						admin_url( 'admin.php' )
					),
				);
			}
		}
		return null;
	}

	/**
	 * The framework's dashboard opened in the builder's side panel (`xb_frame=1`): only the framework's own app,
	 * without WordPress's menu, toolbar, footer and notices. Presentation only: the page, its permissions and
	 * its nonces are WordPress's and the framework's, unchanged; WordPress allows framing by the same origin only.
	 */
	public static function framed_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display switch.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// Framework dashboards, and Fluent Forms' editor for those who may edit its forms (docs/spec/fluent-forms.md).
		$framed = in_array( $page, self::FRAMEWORK_PAGES, true ) || ( \XB\Integrations\FluentForms::PAGE === $page && \XB\Integrations\FluentForms::allowed() );
		if ( ! isset( $_GET['xb_frame'] ) || ! $framed || ! current_user_can( 'xb_build' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<style id="xb-framed">html.wp-toolbar{padding-top:0!important}#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta,#screen-meta-links,.notice,.update-nag,div.updated,div.error{display:none!important}#wpcontent,#wpfooter{margin-left:0!important}#wpcontent{padding-left:0!important}#wpbody-content{padding-bottom:0!important}html,body{background:#fff}</style>' . "\n";
	}

	/**
	 * Content Security Policy of the builder page. Only the two scripts carrying the nonce run; the canvas iframe
	 * (srcdoc) inherits this policy on top of its own sandbox. Images, fonts and stylesheets of the site being
	 * previewed may come from anywhere (themes and CDNs), so those stay open; nothing else does. Framing is
	 * refused, except by this same origin when the builder is opened over Gutenberg ($embed).
	 */
	public static function policy( string $nonce, bool $embed = false ): string {
		// One worker, the image encoder (lista migliorie M5), allowed by its exact path: it compiles its WebAssembly
		// inside itself, so the page needs neither 'unsafe-eval' nor 'wasm-unsafe-eval'.
		$encoder = self::encoder_url();
		$origins = array( "'self'" );
		foreach ( array( home_url( '/' ), site_url( '/' ), rest_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
				$origins[] = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			}
		}
		$self = implode( ' ', array_unique( $origins ) );
		return implode(
			'; ',
			array(
				"default-src 'self'",
				"script-src 'nonce-" . $nonce . "'",
				"style-src {$self} 'unsafe-inline' https:",
				'img-src * data: blob:',
				'font-src * data:',
				'media-src *',
				// The Histeon Connector on the user's own computer (docs/spec/connector.md): loopback, one port.
				"connect-src {$self} http://127.0.0.1:" . self::CONNECTOR_PORT,
				"frame-src 'self'",
				null === $encoder ? "worker-src 'none'" : 'worker-src ' . $encoder,
				"object-src 'none'",
				"base-uri 'none'",
				"form-action 'self'",
				$embed ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
			)
		);
	}

	/**
	 * The image encoder's worker (lista migliorie M5, editor/src/media/encoder.worker.ts), when it is built and on
	 * this admin's origin: a module worker can only start from the page's own origin. Null otherwise, and the builder
	 * then compresses with the browser's own WebP encoder.
	 */
	public static function encoder_url(): ?string {
		if ( ! is_readable( dirname( XB_FILE ) . '/assets/builder/media/encoder.js' ) ) {
			return null;
		}
		$url   = plugin_dir_url( XB_FILE ) . 'assets/builder/media/encoder.js';
		$own   = wp_parse_url( admin_url() );
		$parts = wp_parse_url( $url );
		$origin = static fn ( $p ): string => is_array( $p ) ? ( $p['scheme'] ?? '' ) . '://' . ( $p['host'] ?? '' ) . ':' . ( $p['port'] ?? '' ) : '';
		if ( '' === $origin( $parts ) || $origin( $parts ) !== $origin( $own ) || 1 === preg_match( '/[\s;,\'"]/', $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * `xb_builder_languages`: a language plugin describes this document's language and its translations. Only
	 * known fields of the expected types are kept, and links must point to this site's admin.
	 *
	 * @return array{current: string, items: list<array{code: string, label: string, state: string, url: string}>, source: list<string>}|null
	 */
	public static function languages( int $post_id ): ?array {
		$raw = apply_filters( 'xb_builder_languages', null, $post_id );
		if ( ! is_array( $raw ) || ! is_array( $raw['items'] ?? null ) ) {
			return null;
		}
		$admin = admin_url();
		$items = array();
		foreach ( array_slice( $raw['items'], 0, 30 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$code  = is_string( $item['code'] ?? null ) ? substr( preg_replace( '/[^A-Za-z0-9-]/', '', $item['code'] ) ?? '', 0, 35 ) : '';
			$state = in_array( $item['state'] ?? '', array( 'current', 'publish', 'draft', 'missing' ), true ) ? (string) $item['state'] : '';
			$url   = is_string( $item['url'] ?? null ) ? esc_url_raw( $item['url'] ) : '';
			if ( '' === $code || '' === $state ) {
				continue;
			}
			$items[] = array(
				'code'  => $code,
				'label' => is_string( $item['label'] ?? null ) ? mb_substr( sanitize_text_field( $item['label'] ), 0, 40 ) : strtoupper( $code ),
				'state' => $state,
				'url'   => str_starts_with( $url, $admin ) ? $url : '',
			);
		}
		$current = is_string( $raw['current'] ?? null ) ? (string) preg_replace( '/[^A-Za-z0-9-]/', '', $raw['current'] ) : '';
		// The original's texts, for the check that points out texts left untranslated (plain text, bounded).
		$source = array();
		foreach ( array_slice( is_array( $raw['source'] ?? null ) ? $raw['source'] : array(), 0, 300 ) as $text ) {
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$source[] = mb_substr( sanitize_text_field( $text ), 0, 300 );
			}
		}
		return array() === $items ? null : array( 'current' => $current, 'items' => $items, 'source' => $source );
	}

	/**
	 * The first answers the builder asks for (V6): the document, the styles and the components, from the same REST
	 * routes and with the same permissions, so they reach the bundle with the page instead of after it. The
	 * framework lists stay a request of their own: revalidated with an ETag, they usually come back as a 304.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot( int $post_id ): array {
		$boot = array();
		foreach ( array( 'document' => 'documents/' . $post_id, 'styles' => 'styles', 'components' => 'components' ) as $key => $route ) {
			$response = rest_do_request( new \WP_REST_Request( 'GET', '/' . Controller::NAMESPACE . '/' . $route ) );
			if ( ! $response->is_error() && 200 === $response->get_status() ) {
				$boot[ $key ] = rest_get_server()->response_to_data( $response, false );
			}
		}
		return $boot;
	}

	public static function render(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen; writes use REST nonces.
		if ( ! self::can_open( $post_id ) ) {
			wp_die( esc_html__( 'Non hai i permessi per modificare questo contenuto con Histeon.', 'xb' ), 403 );
		}
		$post  = get_post( $post_id );
		$embed = isset( $_GET['embed'] ) && '1' === $_GET['embed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only relaxes framing to this same origin.
		$dir   = dirname( XB_FILE ) . '/assets/builder/';
		$base = plugin_dir_url( XB_FILE ) . 'assets/builder/';

		$nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		nocache_headers();
		header( 'X-Frame-Options: ' . ( $embed ? 'SAMEORIGIN' : 'DENY' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Content-Security-Policy: ' . self::policy( $nonce, $embed ) );

		$config = array(
			'restUrl'  => esc_url_raw( rest_url( Controller::NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'postId'   => $post_id,
			'title'    => $post instanceof \WP_Post ? $post->post_title : '',
			'editUrl'  => get_edit_post_link( $post_id, 'raw' ),
			'viewUrl'  => \XB\Templates\Templates::is_template( $post ) ? \XB\Templates\Templates::url( $post ) : get_permalink( $post_id ),
			'template' => \XB\Templates\Templates::is_template( $post ) ? array(
				'part'    => 'wp_template_part' === $post->post_type,
				'slug'    => $post->post_name,
				'samples' => \XB\Templates\Templates::samples( $post ),
			) : null,
			'media'    => array(
				'url'       => esc_url_raw( rest_url( 'wp/v2/media' ) ),
				'canUpload' => current_user_can( 'upload_files' ),
				'maxBytes'  => (int) wp_max_upload_size(),
				'encoder'   => self::encoder_url() ? self::encoder_url() . '?ver=' . filemtime( $dir . 'media/encoder.js' ) : null,
			),
			'trusted'  => current_user_can( 'unfiltered_html' ),
			'canPublish' => current_user_can( 'xb_publish' ) && ! \XB\Capabilities::content_only(),
			// Client mode: texts and pictures only (docs/spec/client-mode.md); the server checks every save.
			'contentOnly' => \XB\Capabilities::content_only(),
			'contentProps' => \XB\Capabilities::content_props(),
			// Voce 186: a client's page seen only (nothing saved), and the groups of its own look he may use.
			'viewOnly'     => \XB\Capabilities::content_only() && 'view' === \XB\Security\ClientAccess::level( $post_id ),
			'lookGroups'   => \XB\Capabilities::content_only() ? \XB\Security\ClientAccess::groups( $post_id ) : array(),
			// Static Bridge serves a static copy and refreshes it after each release (docs/integration/static-bridge.md).
			'staticBridge' => \XB\StaticSite\StaticBridge::keeps_up(),
			'checksBlock'  => ReleasesScreen::checks_block(),
			// «Scoraggia i motori di ricerca» off (Settings → Reading), read only: the page checks warn before a release.
			'blogPublic'   => (bool) get_option( 'blog_public', '1' ),
			// What the canvas may load besides the builder's own origin (S5): the site, its uploads, the origins declared in Settings.
			'canvasOrigins' => self::canvas_origins(),
			// The icon library (E10), a static file read only when the icon dialog opens.
			'icons'      => esc_url_raw( plugins_url( 'assets/icons/lucide.json', XB_FILE ) . '?ver=' . \XB\Plugin::asset_version( 'assets/icons/lucide.json' ) ),
			// The Histeon Connector, downloadable from this site (docs/spec/connector.md).
			'connector'  => esc_url_raw( plugins_url( 'connector/histos-connector.mjs', XB_FILE ) ),
			// Its pairing: this user's secret and the admin origin, for `serve --origin … --pair …` and the tab.
			'connectorPair' => array(
				'origin' => self::admin_origin(),
				'secret' => self::connector_secret( get_current_user_id() ),
				'rotate' => wp_nonce_url( admin_url( 'admin-post.php?action=xb_connector_rotate' ), 'xb_connector_rotate' ),
			),
			// The user's own keyboard shortcuts (docs/spec/shortcuts.md).
			'shortcuts'  => (object) Shortcuts::get( get_current_user_id() ),
			'canManage'  => current_user_can( 'manage_options' ),
			// The XB screens in wp-admin, so the builder links back to them.
			'admin'      => array(
				'overview' => Menu::url( Menu::SLUG ),
				'history'  => Menu::url( HistoryScreen::SLUG, array( 'doc' => \XB\Versioning\Documents::for_post( $post_id ) ) ),
				'releases' => Menu::url( ReleasesScreen::SLUG ),
				// Creating a template happens there (docs/spec/templates.md, «Nuovo template»).
				'templates' => TemplatesScreen::allowed() ? Menu::url( TemplatesScreen::SLUG ) : null,
			),
			'framework'  => self::framework_panel(),
			// Fluent Forms inside the builder: insert a form, edit it in its own editor (docs/spec/fluent-forms.md).
			'fluentForms' => \XB\Integrations\FluentForms::allowed(),
			// The languages of this document, from a language plugin (docs/spec/multilingual.md).
			'languages'  => self::languages( $post_id ),
			'locale'   => get_user_locale(),
			'embed'    => $embed,
			'agentScopes' => \XB\Ai\Scopes::describe(),
			// For tools and agents in the page (window.xb.environment, doc 26 voce 220).
			'environment' => array(
				'type'      => wp_get_environment_type(),
				'histeon'   => XB_VERSION,
				'wordpress' => (string) get_bloginfo( 'version' ),
				'multisite' => is_multisite(),
			),
		);
		$config['boot'] = (object) self::boot( $post_id );
		$built  = is_readable( $dir . 'builder.js' );
		$ver    = $built ? (string) filemtime( $dir . 'builder.js' ) : XB_VERSION;
		// The builder in English for every language but Italian (docs/spec/i18n.md): its catalog is a file of its
		// own, cached by the browser like the bundle, instead of 95 kB in this page that is never cached.
		$strings = 'strings-' . \XB\I18n::FALLBACK . '.js';
		$english = $built && \XB\I18n::english() && is_readable( $dir . $strings );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( 'Histeon — %s', $config['title'] ) ); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( plugins_url( 'assets/brand/histos-mark.svg', XB_FILE ) ); ?>">
		<?php if ( $built ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $base . 'builder.css?ver=' . $ver ); ?>">
		<?php endif; ?>
</head>
<body>
<div id="xb-builder"></div>
		<?php if ( $built ) : ?>
<script nonce="<?php echo esc_attr( $nonce ); ?>">window.xbBuilder = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
(function (c) {
	// The canvas drawing starts now, while the bundle downloads and runs (V6); the builder uses it once.
	var d = c.boot && c.boot.document;
	if (!d || typeof d.content !== 'string') return;
	c.boot.render = fetch(c.restUrl + 'render', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': c.nonce, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ post_id: c.postId, content: d.content, context: 0 }) })
		.then(function (r) { return r.ok ? r.json() : null; }, function () { return null; });
})(window.xbBuilder);</script>
			<?php if ( $english ) : ?>
<script nonce="<?php echo esc_attr( $nonce ); ?>" src="<?php echo esc_url( $base . $strings . '?ver=' . filemtime( $dir . $strings ) ); ?>"></script>
			<?php endif; ?>
<script type="module" nonce="<?php echo esc_attr( $nonce ); ?>" src="<?php echo esc_url( $base . 'builder.js?ver=' . $ver ); ?>"></script>
		<?php else : ?>
<p style="font:16px sans-serif;padding:2rem"><?php esc_html_e( 'L\'interfaccia del builder non è compilata: esegui "npm run build -w editor" nella radice del repository.', 'xb' ); ?></p>
		<?php endif; ?>
</body>
</html>
		<?php
		exit;
	}

	/** Option: further origins (one per line) whose images, fonts and styles the canvas may load (S5). */
	public const CANVAS_ORIGINS = 'xb_canvas_origins';

	/**
	 * The origins the canvas may load media, fonts and styles from: the site's own addresses (home, WordPress,
	 * content, uploads, possibly on a CDN), plus those an administrator declares, plus the `xb_canvas_origins`
	 * filter. Only scheme://host[:port] survive.
	 *
	 * @return list<string>
	 */
	public static function canvas_origins(): array {
		$uploads = wp_upload_dir( null, false );
		$list    = array( home_url( '/' ), site_url( '/' ), content_url( '/' ), $uploads['baseurl'] );
		$list    = array_merge( $list, self::declared_origins( (string) get_option( self::CANVAS_ORIGINS, '' ) ) );
		$list    = (array) apply_filters( 'xb_canvas_origins', $list );
		$out     = array();
		foreach ( $list as $url ) {
			$origin = is_string( $url ) ? self::origin( $url ) : null;
			if ( null !== $origin && ! in_array( $origin, $out, true ) ) {
				$out[] = $origin;
			}
		}
		return $out;
	}

	/**
	 * The origins written in Settings, one per line: anything that is not an http(s) origin is left out.
	 *
	 * @return list<string>
	 */
	public static function declared_origins( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $text ) ?: array() as $line ) {
			$origin = self::origin( $line );
			if ( null !== $origin && ! in_array( $origin, $out, true ) ) {
				$out[] = $origin;
			}
		}
		return array_slice( $out, 0, 50 );
	}

	private static function origin( string $url ): ?string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || ! is_string( $parts['host'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9.-]+$/', $parts['host'] ) ) {
			return null;
		}
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
