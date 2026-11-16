<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/**
 * The fingerprint of a commit (`hash`), which tells a commit read back intact from one changed in the database.
 *
 * A move to another address (All-in-One WP Migration, Duplicator, `wp search-replace`) rewrites the site's URL in
 * every table, commits included: with a plain SHA-256 of the payload every commit that named the site's address
 * stopped reading after the move («Commit … corrotto»), and the builder showed empty pages. So the fingerprint is
 * taken with the address part of its URLs written as a placeholder: the same commit gives the same fingerprint
 * before and after a move. Commits written before (a plain SHA-256) still read; after a move they are repaired with the old
 * address (`repair`), which proves that the address is all that changed.
 *
 * The fingerprint guards against corruption, not against someone who can write the database: they could rewrite it.
 */
final class Integrity {

	/** Set when a commit does not read, so the admin offers the repair. */
	public const UNREADABLE = 'xb_versions_unreadable';

	/** Where the background upgrade of old fingerprints has got to (an id), or `done`; also its cron hook. */
	public const UPGRADE = 'xb_versions_fingerprint';

	private const HOME = '{xb:home}';

	/** @var array<string, list<string>> */
	private static array $hosts = array();

	public static function digest( string $json ): string {
		return hash( 'sha256', self::normalize( $json ) );
	}

	/** Whether the stored fingerprint fits the payload: this site's fingerprint or a plain SHA-256 (before 0.3.8). */
	public static function matches( string $hash, string $json ): bool {
		return hash_equals( $hash, hash( 'sha256', $json ) ) || hash_equals( $hash, self::digest( $json ) );
	}

	/**
	 * The payload with the address part of every URL (scheme and host, escaped slashes, URL-encoded) as a placeholder.
	 * Every host, not only this site's: a migration tool may rewrite one form of the address and miss another (a
	 * slash escaped twice inside a block's attributes), and some sites move twice.
	 */
	public static function normalize( string $json ): string {
		$host = '[a-z0-9-]+(?:\.[a-z0-9-]+)*';
		$json = (string) preg_replace( '~(?:https?:)?(?:\\\\*/){2}' . $host . '(?::\d+)?(?![\w-]|\.[\w-])~i', self::HOME, $json );
		return (string) preg_replace( '~(?:https?%3A)?(?:%2F){2}' . $host . '(?:%3A\d+)?(?![\w-]|\.[\w-])~i', self::HOME, $json );
	}

	/**
	 * The payloads a commit may have had before a move from `$from`: its URLs of this site back to the old address,
	 * with the old scheme or keeping each one's own. One of them must give the stored plain SHA-256.
	 *
	 * @return list<string>
	 */
	public static function before_move( string $json, string $from ): array {
		$old = self::origin( $from );
		if ( null === $old ) {
			return array();
		}
		$out = array();
		foreach ( array( true, false ) as $old_scheme ) {
			$text = $json;
			foreach ( self::hosts() as $name ) {
				$quoted = preg_quote( $name, '~' );
				$text   = (string) preg_replace_callback(
					'~(https?)(:(?:\\\\*/){2})' . $quoted . '(?![\w-]|\.[\w-])~i',
					static fn ( array $m ): string => ( $old_scheme ? $old['scheme'] : $m[1] ) . $m[2] . $old['host'],
					$text
				);
				$text = (string) preg_replace_callback(
					'~(https?)(%3A(?:%2F){2})' . $quoted . '(?![\w-]|\.[\w-])~i',
					static fn ( array $m ): string => ( $old_scheme ? $old['scheme'] : $m[1] ) . $m[2] . $old['host'],
					$text
				);
				// Protocol-relative URLs (//host/…) have no scheme to choose.
				$text = (string) preg_replace( '~(?<![\w:%])((?:\\\\*/){2})' . $quoted . '(?![\w-]|\.[\w-])~i', '${1}' . $old['host'], $text );
			}
			$out[] = $text;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Checks commits by id, from after `$after`, at most `$limit`: each one keeps its fingerprint if it fits, gets
	 * the new one if it has a plain SHA-256 that fits, or, with the old address, if the address is all that changed.
	 * With `$accept` an administrator takes the others as they are now, when their payload is still a document: a
	 * move that turned http and https into one scheme cannot be undone to prove it. Only `hash` is written; payloads
	 * never change.
	 *
	 * @return array{checked: int, upgraded: int, repaired: int, accepted: list<string>, unreadable: list<string>, next: string|null}
	 */
	public static function repair( ?string $from, string $after = '', int $limit = 200, bool $accept = false ): array {
		global $wpdb;
		$table  = Schema::table( 'commits' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload, hash FROM {$table} WHERE id > %s ORDER BY id LIMIT %d", $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$result = array( 'checked' => 0, 'upgraded' => 0, 'repaired' => 0, 'accepted' => array(), 'unreadable' => array(), 'next' => null );
		foreach ( (array) $rows as $row ) {
			$id   = (string) $row['id'];
			$json = (string) $row['payload'];
			$hash = (string) $row['hash'];
			++$result['checked'];
			$result['next'] = $id;
			$digest         = self::digest( $json );
			if ( hash_equals( $hash, $digest ) ) {
				continue;
			}
			$state = null;
			if ( hash_equals( $hash, hash( 'sha256', $json ) ) ) {
				$state = 'upgraded';
			} elseif ( null !== $from ) {
				foreach ( self::before_move( $json, $from ) as $before ) {
					if ( hash_equals( $hash, hash( 'sha256', $before ) ) ) {
						$state = 'repaired';
						break;
					}
				}
			}
			if ( null === $state && $accept && is_array( json_decode( $json, true ) ) ) {
				$state = 'accepted';
			}
			if ( null === $state ) {
				$result['unreadable'][] = $id;
				continue;
			}
			$wpdb->update( $table, array( 'hash' => $digest ), array( 'id' => $id, 'hash' => $hash ), array( '%s' ), array( '%s', '%s' ) );
			if ( 'accepted' === $state ) {
				$result['accepted'][] = $id;
			} else {
				++$result[ $state ];
			}
		}
		if ( count( (array) $rows ) < $limit ) {
			$result['next'] = null;
		}
		return $result;
	}

	/**
	 * The whole table, batch by batch. When nothing is left unreadable the admin stops offering the repair.
	 *
	 * @return array{checked: int, upgraded: int, repaired: int, accepted: list<string>, unreadable: list<string>}
	 */
	public static function repair_all( ?string $from, bool $accept = false ): array {
		$total = array( 'checked' => 0, 'upgraded' => 0, 'repaired' => 0, 'accepted' => array(), 'unreadable' => array() );
		$after = '';
		do {
			$batch = self::repair( $from, $after, 200, $accept );
			foreach ( array( 'checked', 'upgraded', 'repaired' ) as $key ) {
				$total[ $key ] += $batch[ $key ];
			}
			$total['accepted']   = array_merge( $total['accepted'], $batch['accepted'] );
			$total['unreadable'] = array_merge( $total['unreadable'], $batch['unreadable'] );
			$after               = (string) $batch['next'];
		} while ( null !== $batch['next'] );
		if ( array() === $total['unreadable'] ) {
			delete_option( self::UNREADABLE );
		}
		return $total;
	}

	/**
	 * After an update, commits written with a plain SHA-256 get the new fingerprint, 200 at a time in the
	 * background, so a later move does not stop them reading. Nothing to give: the address has not changed.
	 */
	public static function upgrade_step(): void {
		$cursor = get_option( self::UPGRADE );
		if ( 'done' === $cursor ) {
			return;
		}
		$batch = self::repair( null, is_string( $cursor ) ? $cursor : '' );
		update_option( self::UPGRADE, null === $batch['next'] ? 'done' : $batch['next'], false );
		if ( null !== $batch['next'] && ! wp_next_scheduled( self::UPGRADE ) ) {
			wp_schedule_single_event( time() + 30, self::UPGRADE );
		}
	}

	/** Starts the background upgrade once (on init: a cheap option read). */
	public static function maybe_schedule_upgrade(): void {
		if ( 'done' !== get_option( self::UPGRADE ) && ! wp_next_scheduled( self::UPGRADE ) ) {
			wp_schedule_single_event( time() + 60, self::UPGRADE );
		}
	}

	/** Remembers that a commit did not read (cheap: one option, not autoloaded). */
	public static function flag(): void {
		if ( ! get_option( self::UNREADABLE ) ) {
			update_option( self::UNREADABLE, gmdate( 'Y-m-d H:i:s' ), false );
		}
	}

	/**
	 * Scheme and host (with its port) of an old address, or null if it is not one.
	 *
	 * @return array{scheme: string, host: string}|null
	 */
	public static function origin( string $url ): ?array {
		$parts = wp_parse_url( trim( $url ) );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return null;
		}
		return array( 'scheme' => (string) $parts['scheme'], 'host' => $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) );
	}

	/** @return list<string> This site's hosts (home and site address, with a port when they have one). */
	public static function hosts(): array {
		$key = home_url() . '|' . site_url();
		if ( ! isset( self::$hosts[ $key ] ) ) {
			$names = array();
			foreach ( array( home_url(), site_url() ) as $url ) {
				$origin = self::origin( $url );
				if ( null !== $origin ) {
					$names[] = $origin['host'];
				}
			}
			self::$hosts[ $key ] = array_values( array_unique( $names ) );
		}
		return self::$hosts[ $key ];
	}
}
