<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\VersioningException;

/**
 * Restore point before an AI agent (lista della spesa 158). An agent's writes by the same user, each within
 * GAP of the previous one, make a session; at its first write on a document the session keeps the dev head the
 * document had before it. That head is the restore point: nothing is copied. At the end the person who works with the
 * agent keeps everything, or undoes it all: each document goes back to its restore point as a new dev commit
 * (Versioning::revert, so trust and permissions are those of any restore). Nothing goes live either way.
 *
 * Contents and their fields (Contents, doc 26 voce 216) have no versions: the session keeps the values they had
 * before the agent's first change of each one (title, excerpt, each field), and the undo writes them back.
 *
 * @phpstan-type Snapshot array{title?: string, excerpt?: string, meta: array<string, mixed>}
 * @phpstan-type Session array{started: int, last: int, writes: int, docs: array<string, string>, contents: array<int, Snapshot>}
 */
final class AgentSession {

	public const OPTION = 'xb_agent_sessions';
	/** A pause longer than this starts a new session. */
	public const GAP = 1800;

	/** Called by Versioning::save_dev() after an agent's commit; $before is the dev head the commit replaced. */
	public static function record( string $doc, string $before ): void {
		$user = get_current_user_id();
		if ( $user <= 0 || '' === $before ) {
			return;
		}
		$all     = self::all();
		$session = $all[ $user ] ?? null;
		$now     = time();
		$session = self::current( $all[ $user ] ?? null );
		if ( ! isset( $session['docs'][ $doc ] ) ) {
			$session['docs'][ $doc ] = $before;
		}
		++$session['writes'];
		$all[ $user ] = $session;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Called by Contents::update() before an agent changes a content: what it held, for each key not yet kept in
	 * this session (the first value is the one to go back to).
	 *
	 * @param Snapshot $snapshot
	 */
	public static function record_content( int $post_id, array $snapshot ): void {
		$user = get_current_user_id();
		if ( $user <= 0 || $post_id <= 0 ) {
			return;
		}
		$all     = self::all();
		$session = self::current( $all[ $user ] ?? null );
		$kept    = $session['contents'][ $post_id ] ?? array( 'meta' => array() );
		foreach ( array( 'title', 'excerpt' ) as $key ) {
			if ( isset( $snapshot[ $key ] ) && ! isset( $kept[ $key ] ) ) {
				$kept[ $key ] = $snapshot[ $key ];
			}
		}
		$kept['meta']                   += $snapshot['meta'];
		$session['contents'][ $post_id ] = $kept;
		++$session['writes'];
		$all[ $user ] = $session;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * The user's session to write into: the one in course, or a new one after a pause longer than GAP.
	 *
	 * @param Session|null $session
	 * @return Session
	 */
	private static function current( ?array $session ): array {
		$now = time();
		if ( null === $session || $now - $session['last'] > self::GAP ) {
			$session = array( 'started' => $now, 'last' => $now, 'writes' => 0, 'docs' => array(), 'contents' => array() );
		}
		$session['last'] = $now;
		return $session;
	}

	/** @return array<int, Session> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $user => $session ) {
			if ( is_array( $session ) && is_array( $session['docs'] ?? null ) ) {
				$out[ (int) $user ] = array(
					'started'  => (int) ( $session['started'] ?? 0 ),
					'last'     => (int) ( $session['last'] ?? 0 ),
					'writes'   => (int) ( $session['writes'] ?? 0 ),
					'docs'     => array_filter( array_map( 'strval', $session['docs'] ), static fn ( string $id ): bool => 1 === preg_match( '/^[0-9a-f]{32}$/', $id ) ),
					'contents' => self::snapshots( $session['contents'] ?? array() ),
				);
			}
		}
		return $out;
	}

	/**
	 * @return array<int, Snapshot>
	 */
	private static function snapshots( mixed $stored ): array {
		$out = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $snapshot ) {
			if ( (int) $id <= 0 || ! is_array( $snapshot ) ) {
				continue;
			}
			$clean = array( 'meta' => is_array( $snapshot['meta'] ?? null ) ? $snapshot['meta'] : array() );
			foreach ( array( 'title', 'excerpt' ) as $key ) {
				if ( is_string( $snapshot[ $key ] ?? null ) ) {
					$clean[ $key ] = $snapshot[ $key ];
				}
			}
			$out[ (int) $id ] = $clean;
		}
		return $out;
	}

	/**
	 * The sessions the current user may see: their own, and everyone's for an administrator. Each with its documents
	 * in words, and whether the agent is still working (a write within the last two minutes).
	 *
	 * @return list<array{user: int, userName: string, mine: bool, started: int, last: int, writes: int, active: bool, docs: list<array{doc: string, label: string}>}>
	 */
	public static function visible(): array {
		$me  = get_current_user_id();
		$out = array();
		foreach ( self::all() as $user => $session ) {
			if ( $user !== $me && ! current_user_can( 'manage_options' ) ) {
				continue;
			}
			$author = get_userdata( $user );
			$out[]  = array(
				'user'     => $user,
				'userName' => $author instanceof \WP_User ? $author->display_name : '#' . $user,
				'mine'     => $user === $me,
				'started'  => $session['started'],
				'last'     => $session['last'],
				'writes'   => $session['writes'],
				'active'   => time() - $session['last'] < 120,
				'docs'     => array_merge(
					array_map( static fn ( string $doc ): array => array( 'doc' => $doc, 'label' => Documents::label( $doc ) ), array_keys( $session['docs'] ) ),
					array_map(
						static fn ( int $id ): array => array(
							'doc'   => 'post:' . $id,
							/* translators: %s: content title */
							'label' => sprintf( __( 'Contenuto «%s»', 'xb' ), html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ),
						),
						array_keys( $session['contents'] )
					)
				),
			);
		}
		return $out;
	}

	/** Keeps what the agent did: the session ends, the restore points are forgotten (the history still has them). */
	public static function keep( int $user ): void {
		self::guard( $user );
		$all = self::all();
		unset( $all[ $user ] );
		update_option( self::OPTION, $all, false );
		Plugin::instance()->versioning->store()->audit( 'agent-keep', 'user:' . $user );
	}

	/**
	 * Undoes everything the session changed: every document back to its restore point, as a new dev commit.
	 * A document that cannot go back (deleted, or not editable by this user) is named in `failed`; the rest goes.
	 *
	 * @return array{restored: list<string>, failed: array<string, string>}
	 */
	public static function undo( int $user ): array {
		self::guard( $user );
		$all     = self::all();
		$session = $all[ $user ] ?? null;
		if ( null === $session ) {
			throw new VersioningException( __( 'Nessuna sessione dell’agente da annullare.', 'xb' ), 404 );
		}
		$versioning = Plugin::instance()->versioning;
		$restored   = array();
		$failed     = array();
		foreach ( $session['docs'] as $doc => $before ) {
			$id = Documents::post_id( $doc );
			if ( null !== $id && ! current_user_can( 'edit_post', $id ) ) {
				$failed[ $doc ] = __( 'non puoi modificarlo', 'xb' );
				continue;
			}
			if ( $versioning->store()->head( $doc, \XB\Versioning\Store::DEV ) === $before ) {
				$restored[] = $doc; // Already as it was.
				continue;
			}
			try {
				$versioning->revert( $doc, $before );
				$restored[] = $doc;
			} catch ( VersioningException $e ) {
				$failed[ $doc ] = $e->getMessage();
			}
		}
		foreach ( $session['contents'] as $post_id => $snapshot ) {
			$error = Contents::restore( $post_id, $snapshot );
			if ( null === $error ) {
				$restored[] = 'post:' . $post_id;
			} else {
				$failed[ 'post:' . $post_id ] = $error;
			}
		}
		unset( $all[ $user ] );
		update_option( self::OPTION, $all, false );
		$versioning->store()->audit( 'agent-undo', 'user:' . $user, array( 'restored' => $restored, 'failed' => array_keys( $failed ) ) );
		return array( 'restored' => $restored, 'failed' => $failed );
	}

	/** One's own session, or anyone's for an administrator; and only for who builds. */
	private static function guard( int $user ): void {
		if ( ! current_user_can( 'xb_build' ) || ( get_current_user_id() !== $user && ! current_user_can( 'manage_options' ) ) ) {
			throw new VersioningException( __( 'Non puoi gestire questa sessione dell’agente.', 'xb' ), 403 );
		}
	}
}
