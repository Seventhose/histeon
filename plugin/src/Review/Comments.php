<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Review;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * Comments and approval from the signed preview (docs/spec/review.md). Whoever holds a valid preview link
 * — a client, a colleague — can leave a comment on an element of that page, or approve the version they see;
 * nobody else can. Comments are a private post type only builders read; text is plain text, short, and the
 * public endpoints are rate limited per link and per address.
 */
final class Comments {

	public const POST_TYPE = 'xb_comment';
	public const APPROVAL  = '_xb_approval';
	private const MAX_TEXT = 2000;
	private const MAX_NAME = 80;
	/** Per link and address, per hour. */
	private const PER_HOUR = 30;
	/** For the whole site, per hour: a leaked link cannot flood the list. */
	private const SITE_PER_HOUR = 300;

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( 'Commenti dall\'anteprima', 'xb' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'editor' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
	}

	public static function routes(): void {
		$text = array( 'type' => 'string', 'required' => true );
		register_rest_route(
			'xb/v1',
			'/review/comments',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'add' ),
					// The signed preview token in the body is the credential (checked in the callback).
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => $text,
						'uid'   => $text,
						'text'  => $text,
						'name'  => array( 'type' => 'string' ),
						'email' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'list' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => array( 'post' => array( 'type' => 'integer', 'required' => true ) ),
				),
			)
		);
		register_rest_route(
			'xb/v1',
			'/review/comments/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'set_status' ),
					'permission_callback' => array( self::class, 'can_edit_comment' ),
					'args'                => array( 'status' => array( 'type' => 'string', 'enum' => array( 'open', 'resolved' ), 'required' => true ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'remove' ),
					'permission_callback' => array( self::class, 'can_edit_comment' ),
				),
			)
		);
		register_rest_route(
			'xb/v1',
			'/review/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'approve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => $text,
					'name'  => $text,
					'head'  => $text,
					'email' => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function can_edit( \WP_REST_Request $request ): bool {
		$post = (int) $request['post'];
		return current_user_can( 'xb_build' ) && $post > 0 && current_user_can( 'edit_post', $post );
	}

	public static function can_edit_comment( \WP_REST_Request $request ): bool {
		$comment = get_post( (int) $request['id'] );
		return $comment instanceof \WP_Post && self::POST_TYPE === $comment->post_type && current_user_can( 'xb_build' ) && current_user_can( 'edit_post', (int) $comment->post_parent );
	}

	/** The document a valid token opens, or an error that says nothing more than "not allowed". */
	private static function document( \WP_REST_Request $request ): int|\WP_Error {
		$post = Preview::validate( (string) $request['token'] );
		// A honeypot field people never see: a bot filling it in gets the same answer as a bad token.
		if ( null === $post || '' !== trim( (string) $request['email'] ) ) {
			return new \WP_Error( 'xb_review_denied', __( 'Link di anteprima non valido o scaduto.', 'xb' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	private static function throttled( string $token ): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$keys  = array( 'xb_rv_' . substr( hash( 'sha256', $token . '|' . $ip ), 0, 32 ) => self::PER_HOUR, 'xb_rv_site' => self::SITE_PER_HOUR );
		foreach ( $keys as $key => $limit ) {
			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				return true;
			}
		}
		foreach ( array_keys( $keys ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
		}
		return false;
	}

	private static function clean( string $text, int $max ): string {
		return mb_substr( trim( sanitize_textarea_field( $text ) ), 0, $max );
	}

	/** The dev head of a document, so an approval says which version was seen. */
	private static function head( int $post ): string {
		try {
			return Plugin::instance()->versioning->working_copy( Documents::for_post( $post ) )['head'];
		} catch ( \Throwable ) {
			return '';
		}
	}

	public static function add( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = self::document( $request );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		$uid  = (string) $request['uid'];
		$text = self::clean( (string) $request['text'], self::MAX_TEXT );
		$name = self::clean( (string) $request['name'], self::MAX_NAME );
		if ( 1 !== preg_match( '/^[a-z0-9]{1,32}$/', $uid ) || '' === $text ) {
			return new \WP_Error( 'xb_review_invalid', __( 'Commento vuoto o elemento non valido.', 'xb' ), array( 'status' => 400 ) );
		}
		if ( self::throttled( (string) $request['token'] ) ) {
			return new \WP_Error( 'xb_review_busy', __( 'Troppi commenti in poco tempo: riprova più tardi.', 'xb' ), array( 'status' => 429 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_parent'  => $post,
				'post_content' => $text,
				'post_title'   => '' === $name ? __( 'Anonimo', 'xb' ) : $name,
				'meta_input'   => array(
					'_xb_uid'    => $uid,
					'_xb_status' => 'open',
					'_xb_head'   => self::head( $post ),
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'xb_review_failed', __( 'Commento non salvato.', 'xb' ), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	public static function approve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = self::document( $request );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		$name = self::clean( (string) $request['name'], self::MAX_NAME );
		if ( '' === $name ) {
			return new \WP_Error( 'xb_review_invalid', __( 'Serve un nome per approvare.', 'xb' ), array( 'status' => 400 ) );
		}
		if ( self::throttled( (string) $request['token'] ) ) {
			return new \WP_Error( 'xb_review_busy', __( 'Troppe richieste in poco tempo: riprova più tardi.', 'xb' ), array( 'status' => 429 ) );
		}
		// The version the reviewer saw, not the one saved since: an approval is only for what was on the screen.
		$head = self::head( $post );
		if ( '' === $head || ! hash_equals( $head, (string) $request['head'] ) ) {
			return new \WP_Error( 'xb_review_stale', __( 'La pagina è cambiata dopo che l\'hai aperta: ricaricala e approva di nuovo.', 'xb' ), array( 'status' => 409 ) );
		}
		update_post_meta(
			$post,
			self::APPROVAL,
			array(
				'name' => $name,
				'at'   => time(),
				'head' => $head,
			)
		);
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/** @return array{name: string, at: int, head: string}|null */
	public static function approval( int $post ): ?array {
		$a = get_post_meta( $post, self::APPROVAL, true );
		return is_array( $a ) && isset( $a['name'], $a['at'] ) ? array( 'name' => (string) $a['name'], 'at' => (int) $a['at'], 'head' => (string) ( $a['head'] ?? '' ) ) : null;
	}

	public static function list( \WP_REST_Request $request ): \WP_REST_Response {
		$post  = (int) $request['post'];
		$items = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $post,
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$approval = self::approval( $post );
		return new \WP_REST_Response(
			array(
				'comments' => array_map(
					static fn ( \WP_Post $c ): array => array(
						'id'     => $c->ID,
						'author' => $c->post_title,
						'text'   => $c->post_content,
						'uid'    => (string) get_post_meta( $c->ID, '_xb_uid', true ),
						'status' => 'resolved' === get_post_meta( $c->ID, '_xb_status', true ) ? 'resolved' : 'open',
						'at'     => (int) get_post_time( 'U', true, $c ),
					),
					$items
				),
				'approval' => null === $approval ? null : array_merge( $approval, array( 'current' => '' !== $approval['head'] && $approval['head'] === self::head( $post ) ) ),
			)
		);
	}

	public static function set_status( \WP_REST_Request $request ): \WP_REST_Response {
		update_post_meta( (int) $request['id'], '_xb_status', 'resolved' === $request['status'] ? 'resolved' : 'open' );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	public static function remove( \WP_REST_Request $request ): \WP_REST_Response {
		wp_delete_post( (int) $request['id'], true );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * On a page opened with a valid preview link: the small review bar. Not where the page's scripts are
	 * blocked (unreviewed content in dev, Preview::detect): then there are no comments either.
	 */
	public static function enqueue(): void {
		if ( ! Preview::active() || Preview::scripts_blocked() ) {
			return;
		}
		$token = isset( $_GET[ Preview::PARAM ] ) && is_string( $_GET[ Preview::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ Preview::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC token is the credential.
		$post  = Preview::validate( $token );
		$base  = plugin_dir_url( XB_FILE ) . 'assets/front/';
		wp_enqueue_script( 'xb-review', $base . 'review.js', array(), Plugin::asset_version( 'assets/front/review.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_style( 'xb-review', $base . 'review.css', array(), Plugin::asset_version( 'assets/front/review.css' ) );
		wp_add_inline_script(
			'xb-review',
			'window.xbReview=' . (string) wp_json_encode(
				array(
					'comments' => esc_url_raw( rest_url( 'xb/v1/review/comments' ) ),
					'approve'  => esc_url_raw( rest_url( 'xb/v1/review/approve' ) ),
					'token'    => $token,
					// The version on the screen, which an approval names (note 113 of the audit of 3 November 2026).
					'head'     => null === $post ? '' : self::head( $post ),
					'i18n'     => self::texts(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The texts of the review bar (assets/front/review.js), in the reviewer's language: the script runs on the
	 * public page, without wp.i18n.
	 *
	 * @return array<string, string>
	 */
	private static function texts(): array {
		return array(
			'Non inviato' => __( 'Non inviato', 'xb' ),
			'Revisione' => __( 'Revisione', 'xb' ),
			'Commenta' => __( 'Commenta', 'xb' ),
			'Approva' => __( 'Approva', 'xb' ),
			'Scegli un elemento… (Esc)' => __( 'Scegli un elemento… (Esc)', 'xb' ),
			'Commento' => __( 'Commento', 'xb' ),
			'Cosa cambieresti qui?' => __( 'Cosa cambieresti qui?', 'xb' ),
			'Il tuo nome' => __( 'Il tuo nome', 'xb' ),
			'Invia' => __( 'Invia', 'xb' ),
			'Annulla' => __( 'Annulla', 'xb' ),
			'Commento inviato. Grazie!' => __( 'Commento inviato. Grazie!', 'xb' ),
			'Approvi questa versione della pagina? Scrivi il tuo nome:' => __( 'Approvi questa versione della pagina? Scrivi il tuo nome:', 'xb' ),
			/* translators: %s: the reviewer's name */
			'Approvata da %s.' => __( 'Approvata da %s.', 'xb' ),
		);
	}
}
