<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

use XB\Capabilities;
use XB\Styles\PageLook;

/**
 * What one client may change, and where (voce 186 of doc 23 of Publish; docs/spec/client-mode.md, «Permessi del
 * cliente»). Who builds (`xb_build`) or administers the site sets it for each client from Histeon → Permessi dei
 * clienti; the client never does, for himself or for others.
 *
 * Stored per user and per site (user option `xb_client_access`), checked with closed lists:
 * - `props`: the literal props of components (as `xb_edit_props`, which a role can still give);
 * - `look`, `groups`: the page's own look (PageLook), in the mini GUI's groups chosen;
 * - `pages`: `all` (every page he may edit, as before) or `list`, where each page has a level:
 *   `none` (not even opened), `view` (seen in the builder, nothing saved), `content` (texts and pictures),
 *   `look` (also the page's look, when `look` is on).
 * With nothing stored a client keeps exactly what client mode gave before: every page he may edit, texts and
 * pictures, props only from the role. Nobody gains anything from the update.
 *
 * The levels hold everywhere: in the builder and its REST routes (Rest\Controller, Admin\BuilderScreen) and in
 * WordPress itself, where a page below `content` is not editable (`edit_post` mapped to `do_not_allow`): no block
 * editor, classic editor, quick edit or REST write for it.
 */
final class ClientAccess {

	public const OPTION = 'xb_client_access';
	public const LEVELS = array( 'none', 'view', 'content', 'look' );
	public const MAX_PAGES = 500;

	/** While true, edit_post is not narrowed: what the client may edit in WordPress before this class. */
	private static bool $raw = false;

	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 20, 4 );
	}

	/** @return array{props: bool, look: bool, groups: list<string>, pages: string, levels: array<int, string>} */
	public static function defaults(): array {
		return array(
			'props'  => false,
			'look'   => false,
			'groups' => array(),
			'pages'  => 'all',
			'levels' => array(),
		);
	}

	/** A client: client mode and nothing more (`xb_edit_content` without `xb_build`). */
	public static function is_client( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'xb_edit_content' ) && ! user_can( $user_id, 'xb_build' ) && ! is_super_admin( $user_id );
	}

	/**
	 * What is stored for a user, normalized; the defaults when nothing valid is.
	 *
	 * @return array{props: bool, look: bool, groups: list<string>, pages: string, levels: array<int, string>}
	 */
	public static function settings( int $user_id ): array {
		$raw = $user_id > 0 ? get_user_option( self::OPTION, $user_id ) : false;
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}
		try {
			return self::validate( $raw );
		} catch ( \InvalidArgumentException ) {
			return self::defaults();
		}
	}

	/**
	 * Settings from a form or a request, checked with closed lists.
	 *
	 * @return array{props: bool, look: bool, groups: list<string>, pages: string, levels: array<int, string>}
	 * @throws \InvalidArgumentException With what is wrong.
	 */
	public static function validate( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( __( 'Permessi del cliente: formato non valido.', 'xb' ) );
		}
		$out = self::defaults();
		foreach ( array( 'props', 'look' ) as $flag ) {
			if ( array_key_exists( $flag, $raw ) && ! is_bool( $raw[ $flag ] ) ) {
				throw new \InvalidArgumentException( __( 'Permessi del cliente: formato non valido.', 'xb' ) );
			}
			$out[ $flag ] = true === ( $raw[ $flag ] ?? false );
		}
		$groups = $raw['groups'] ?? array();
		if ( ! is_array( $groups ) ) {
			throw new \InvalidArgumentException( __( 'Permessi del cliente: formato non valido.', 'xb' ) );
		}
		foreach ( $groups as $group ) {
			if ( ! is_string( $group ) || ! in_array( $group, PageLook::GROUPS, true ) ) {
				/* translators: %s: name of a group of the Aspetto panel */
				throw new \InvalidArgumentException( sprintf( __( 'Permessi del cliente: gruppo sconosciuto «%s».', 'xb' ), is_string( $group ) ? mb_substr( $group, 0, 40 ) : '?' ) );
			}
		}
		// In the mini GUI's order, once each.
		$out['groups'] = array_values( array_intersect( PageLook::GROUPS, $groups ) );
		$pages         = $raw['pages'] ?? 'all';
		if ( 'all' !== $pages && 'list' !== $pages ) {
			throw new \InvalidArgumentException( __( 'Permessi del cliente: scelta delle pagine non valida.', 'xb' ) );
		}
		$out['pages'] = $pages;
		$levels       = $raw['levels'] ?? array();
		if ( ! is_array( $levels ) || count( $levels ) > self::MAX_PAGES ) {
			throw new \InvalidArgumentException( __( 'Permessi del cliente: troppe pagine nell\'elenco.', 'xb' ) );
		}
		foreach ( $levels as $id => $level ) {
			if ( ( is_string( $id ) && ! ctype_digit( $id ) ) || (int) $id <= 0 || ! is_string( $level ) || ! in_array( $level, self::LEVELS, true ) ) {
				throw new \InvalidArgumentException( __( 'Permessi del cliente: livello di accesso non valido.', 'xb' ) );
			}
			// «Nessuno» is what an unlisted page has: not stored.
			if ( 'none' !== $level ) {
				$out['levels'][ (int) $id ] = $level;
			}
		}
		ksort( $out['levels'] );
		return $out;
	}

	/** Who may set a client's permissions: who builds, or an administrator; never the client himself. */
	public static function can_manage( int $target ): bool {
		$me = get_current_user_id();
		return $me > 0 && $target !== $me && ( current_user_can( 'xb_build' ) || current_user_can( 'manage_options' ) ) && self::is_client( $target );
	}

	/**
	 * Stores a client's permissions (the screen's save): checked, recorded in the audit log.
	 *
	 * @return array{props: bool, look: bool, groups: list<string>, pages: string, levels: array<int, string>}
	 * @throws \InvalidArgumentException When the values are not valid.
	 * @throws \RuntimeException When the current user may not change them.
	 */
	public static function update( int $target, mixed $raw ): array {
		if ( ! self::can_manage( $target ) ) {
			throw new \RuntimeException( __( 'Non puoi cambiare i permessi di questo utente.', 'xb' ) );
		}
		$settings = self::validate( $raw );
		foreach ( array_keys( $settings['levels'] ) as $id ) {
			if ( ! PageLook::supports( $id ) ) {
				throw new \InvalidArgumentException( __( 'Permessi del cliente: nell\'elenco vanno solo pagine e articoli.', 'xb' ) );
			}
		}
		update_user_option( $target, self::OPTION, $settings );
		\XB\Plugin::instance()->versioning->store()->audit(
			'client_access',
			'user:' . $target,
			array(
				'props'  => $settings['props'],
				'look'   => $settings['look'],
				'groups' => $settings['groups'],
				'pages'  => $settings['pages'],
				'count'  => count( $settings['levels'] ),
			)
		);
		return $settings;
	}

	/** Whether the user may edit the post in WordPress, as before the levels narrow it. */
	public static function raw_can_edit( int $user_id, int $post_id ): bool {
		$was       = self::$raw;
		self::$raw = true;
		try {
			return user_can( $user_id, 'edit_post', $post_id );
		} finally {
			self::$raw = $was;
		}
	}

	/** The level of a user on a post: `look` for who builds, the client's own level, `none` for anyone else. */
	public static function level( int $post_id, ?int $user_id = null ): string {
		$user_id ??= get_current_user_id();
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return 'none';
		}
		if ( ! self::is_client( $user_id ) ) {
			return user_can( $user_id, 'xb_build' ) && self::raw_can_edit( $user_id, $post_id ) ? 'look' : 'none';
		}
		if ( ! self::raw_can_edit( $user_id, $post_id ) ) {
			return 'none';
		}
		$settings = self::settings( $user_id );
		if ( 'all' === $settings['pages'] ) {
			$level = $settings['look'] && array() !== $settings['groups'] && PageLook::supports( $post_id ) ? 'look' : 'content';
		} else {
			$level = $settings['levels'][ $post_id ] ?? 'none';
		}
		// The page's look needs the switch and at least one group, and a page of its own.
		if ( 'look' === $level && ( ! $settings['look'] || array() === $settings['groups'] || ! PageLook::supports( $post_id ) ) ) {
			$level = 'content';
		}
		return $level;
	}

	/** Whether the current user reaches `$need` (a LEVELS value) on the post. */
	public static function allows( int $post_id, string $need ): bool {
		$rank = array_flip( self::LEVELS );
		return isset( $rank[ $need ] ) && $rank[ self::level( $post_id ) ] >= $rank[ $need ];
	}

	/**
	 * The mini GUI groups the current user may use on this post's own look ([] when none).
	 *
	 * @return list<string>
	 */
	public static function groups( int $post_id ): array {
		if ( ! PageLook::supports( $post_id ) || ! self::allows( $post_id, 'look' ) ) {
			return array();
		}
		return self::is_client( get_current_user_id() ) ? self::settings( get_current_user_id() )['groups'] : PageLook::GROUPS;
	}

	/** Client mode may change component props: the role's `xb_edit_props`, or the client's own setting. */
	public static function props( int $user_id ): bool {
		return user_can( $user_id, Capabilities::EDIT_PROPS ) || self::settings( $user_id )['props'];
	}

	/**
	 * A post below `content` is not editable in WordPress for that client (the builder still opens a `view` one,
	 * through level()).
	 *
	 * @param list<string> $caps
	 * @param array<int, mixed> $args
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( self::$raw || ! isset( $args[0] ) || ! is_numeric( $args[0] ) || ! str_starts_with( $cap, 'edit_' ) ) {
			return $caps;
		}
		$post_id = (int) $args[0];
		$type    = get_post_type( $post_id );
		if ( ! is_string( $type ) || ! in_array( $type, \XB\Versioning\Documents::POST_TYPES, true ) ) {
			return $caps;
		}
		// `edit_post`, or the type's own name for it (`edit_page` for pages: what the REST API checks).
		$object = get_post_type_object( $type );
		if ( 'edit_post' !== $cap && ( null === $object || $cap !== $object->cap->edit_post ) ) {
			return $caps;
		}
		// Cheap first: only a stored list of pages narrows anything.
		if ( 'list' !== self::settings( $user_id )['pages'] ) {
			return $caps;
		}
		self::$raw = true;
		try {
			$client = self::is_client( $user_id );
		} finally {
			self::$raw = false;
		}
		if ( $client && in_array( self::level( $post_id, $user_id ), array( 'none', 'view' ), true ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}
}
