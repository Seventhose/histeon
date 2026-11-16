<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/**
 * Data access for the versioning tables. Every query is prepared; commits are never updated or deleted.
 *
 * @phpstan-type Commit array{id: string, doc: string, branch: string, parent: ?string, base: ?string, payload: array<string, mixed>, hash: string, author: int, trusted: bool, message: string, created: string}
 */
final class Store {

	public const DEV  = 'dev';
	public const PROD = 'prod';

	private int $depth = 0;

	/**
	 * Commits read in this request, by id. Commits are never updated or deleted, so a read stays true; a
	 * saving request reads the same few (dev head, prod head, base) several times, each a SELECT of the whole
	 * payload, a JSON decode and a SHA-256 of it. Emptied when a transaction rolls back.
	 *
	 * @var array<string, Commit>
	 */
	private array $read = array();
	/** Enough for the heads and bases of a release of many documents; the oldest go first. */
	private const READ_CACHE = 64;
	/** How every version is written: encode() and commit() must give the same bytes for the same payload. */
	private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * $trusted records whether the payload can be applied as-is: prod commits mirror the live site (already
	 * filtered); dev commits are trusted only when their author has unfiltered_html.
	 *
	 * Text that is not valid UTF-8 never stops a save. In a version that is not trusted each wrong sequence becomes
	 * U+FFFD and the commit returned holds the payload as it was written; a trusted version is repaired by
	 * wp_json_encode(), as it always was, and returns the payload as it was sent.
	 *
	 * @param array<string, mixed> $payload
	 * @return Commit
	 */
	public function commit( string $doc, string $branch, array $payload, ?string $parent, ?string $base, string $message, ?bool $trusted = null ): array {
		global $wpdb;
		$trusted = $trusted ?? ( self::PROD === $branch || current_user_can( 'unfiltered_html' ) );
		if ( $trusted ) {
			$json = self::encode( $payload );
		} else {
			// A version that is not trusted is never repaired by wp_json_encode(), which is what encode() calls: when
			// json_encode() refuses one string that is not UTF-8, anywhere in the document, WP converts every string
			// with the encodings and the substitute character that mbstring is set to. A byte dropped that way puts
			// together, after the filters, what they took out (`@imp<FF>ort`, `u<FF>rl(`, `<scr<FF>ipt>`), and valid
			// text can be read as another encoding (UTF-7 first in mb_detect_order(): `+AEA-import` becomes `@import`).
			// So json_encode() alone, and where it refuses the text, again with JSON_INVALID_UTF8_SUBSTITUTE: each wrong
			// sequence (a lead byte with the bytes after it that cannot start a character, or one such byte on its own:
			// all of them 0x80 or more) becomes one U+FFFD: no wrong sequence disappears, a U+FFFD stays in its place;
			// valid text stays byte for byte, and no setting of mbstring or of the locale is read (threat model T31,
			// P2; mbstring measured on PHP 8.3 and 8.5, the locale on 8.5).
			$json = json_encode( $payload, self::JSON ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() repairs with mbstring, which is what must not happen here.
			if ( false === $json ) {
				$json     = json_encode( $payload, self::JSON | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- as above.
				$repaired = false === $json ? null : json_decode( $json, true );
				if ( false === $json || ! is_array( $repaired ) ) {
					// Not a matter of UTF-8 (nesting deeper than 512, INF or NAN): written through wp_json_encode() it
					// would be an empty payload, a version that no one can open.
					throw new VersioningException( sprintf( /* translators: %s: reason given by PHP */ __( 'La versione non si può scrivere in JSON, quindi non è stata tenuta (%s).', 'xb' ), json_last_error_msg() ), 400 );
				}
				// The commit this returns holds what was written, as get() reads it back, not what was sent.
				$payload = $repaired;
			}
		}
		$commit = array(
			'id'      => bin2hex( random_bytes( 16 ) ),
			'doc'     => $doc,
			'branch'  => $branch,
			'parent'  => $parent,
			'base'    => $base,
			'payload' => $payload,
			'hash'    => Integrity::digest( $json ),
			'author'  => get_current_user_id(),
			'trusted' => $trusted,
			'message' => mb_substr( $message, 0, 255 ),
			'created' => gmdate( 'Y-m-d H:i:s' ),
		);
		$ok      = $wpdb->insert(
			Schema::table( 'commits' ),
			array(
				'id'      => $commit['id'],
				'doc'     => $doc,
				'branch'  => $branch,
				'parent'  => $parent,
				'base'    => $base,
				'payload' => $json,
				'hash'    => $commit['hash'],
				'author'  => $commit['author'],
				'trusted' => $trusted ? 1 : 0,
				'message' => $commit['message'],
				'created' => $commit['created'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Commit non salvato: %s', 'xb' ), $wpdb->last_error ) );
		}
		// Read back what the database kept (doc 23 item 124): a commit written by another PHP or through a connection
		// with another charset showed up as «corrupt» only later, when someone opened it. Seen at once, it is not kept.
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT payload, hash FROM ' . Schema::table( 'commits' ) . ' WHERE id = %s', $commit['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $stored ) || (string) $stored['payload'] !== $json || ! Integrity::matches( (string) $stored['hash'], (string) $stored['payload'] ) ) {
			$wpdb->delete( Schema::table( 'commits' ), array( 'id' => $commit['id'] ) );
			$detail = self::mismatch( $commit['id'], $json, is_array( $stored ) ? (string) $stored['payload'] : '', is_array( $stored ) ? (string) $stored['hash'] : '' );
			throw new VersioningException( sprintf( /* translators: %s: details */ __( 'Il database non ha salvato la versione così come è stata inviata, quindi non è stata tenuta (%s).', 'xb' ), $detail ) );
		}
		return $commit;
	}

	/**
	 * What a failed fingerprint check says, for the log and the message: lengths, where the texts start to differ,
	 * the PHP and the database charset that wrote and read them (doc 23 item 124). Never the content itself.
	 */
	public static function mismatch( string $id, string $expected, string $stored, string $hash ): string {
		global $wpdb;
		$at = 0;
		$max = min( strlen( $expected ), strlen( $stored ) );
		while ( $at < $max && $expected[ $at ] === $stored[ $at ] ) {
			++$at;
		}
		$detail = sprintf(
			'commit %1$s: %2$d byte attesi, %3$d salvati, primo byte diverso %4$s; impronta %5$s; PHP %6$s, charset %7$s',
			$id,
			strlen( $expected ),
			strlen( $stored ),
			$at < $max || strlen( $expected ) !== strlen( $stored ) ? (string) $at : 'nessuno',
			'' === $expected ? 'non controllata' : ( Integrity::matches( $hash, $expected ) ? 'del contenuto inviato' : 'diversa' ),
			PHP_VERSION,
			(string) ( $wpdb->charset ?? '' )
		);
		error_log( 'Histeon: ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed integrity check must reach the server log.
		return $detail;
	}

	/** @return Commit|null */
	public function get( ?string $id ): ?array {
		global $wpdb;
		if ( null === $id || 1 !== preg_match( '/^[0-9a-f]{32}$/', $id ) ) {
			return null;
		}
		if ( isset( $this->read[ $id ] ) ) {
			return $this->read[ $id ];
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'commits' ) . ' WHERE id = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( count( $this->read ) >= self::READ_CACHE ) {
			unset( $this->read[ array_key_first( $this->read ) ] );
		}
		return $this->read[ $id ] = self::hydrate( $row );
	}

	public function head( string $doc, string $branch ): ?string {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT commit_id FROM ' . Schema::table( 'refs' ) . ' WHERE doc = %s AND branch = %s', $doc, $branch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_string( $id ) ? $id : null;
	}

	public function move( string $doc, string $branch, string $commit_id ): void {
		global $wpdb;
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'refs' ) . ' (doc, branch, commit_id, updated) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE commit_id = VALUES(commit_id), updated = VALUES(updated)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$doc,
				$branch,
				$commit_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $ok ) {
			throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Riferimento non aggiornato: %s', 'xb' ), $wpdb->last_error ) );
		}
	}

	/**
	 * Compare-and-swap: moves the ref only if it still points at $expected (null = must not exist yet).
	 * The race-free way to implement optimistic locking.
	 */
	public function move_if( string $doc, string $branch, ?string $expected, string $commit_id ): void {
		global $wpdb;
		if ( null === $expected ) {
			$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Schema::table( 'refs' ) . ' (doc, branch, commit_id, updated) VALUES (%s, %s, %s, %s)', $doc, $branch, $commit_id, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'refs' ) . ' SET commit_id = %s, updated = %s WHERE doc = %s AND branch = %s AND commit_id = %s', $commit_id, gmdate( 'Y-m-d H:i:s' ), $doc, $branch, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( 1 !== $ok ) {
			throw new VersioningException( __( 'Il documento è stato salvato altrove nel frattempo. Ricarica prima di salvare.', 'xb' ), 409 );
		}
	}

	/**
	 * Named lock so publish, restore and deploy never run concurrently: MySQL's GET_LOCK, or on SQLite (where the
	 * SQLite Database Integration plugin answers GET_LOCK with a dummy «yes») a lock row (row_locked).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function locked( string $name, callable $fn ): mixed {
		global $wpdb;
		$lock = $wpdb->prefix . 'xb_' . $name;
		if ( self::sqlite() ) {
			return $this->row_locked( $lock, $fn );
		}
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 15 ) ) ) {
			throw new VersioningException( __( 'Un\'altra pubblicazione è in corso: riprova tra poco.', 'xb' ), 409 );
		}
		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** WordPress runs on SQLite (SQLite Database Integration: docs/spec/versioning.md §10). */
	public static function sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
	}

	/** A lock row older than this is from a request that died: it is taken over. */
	private const LOCK_STALE = 600;

	/** @var array<string, int> Lock rows this request holds, with how many nested calls (re-entrant, like GET_LOCK). */
	private array $held = array();

	/**
	 * A named lock as a row of the options table: whoever inserts it holds it (INSERT IGNORE is atomic on every
	 * engine), others wait up to $wait seconds. The row carries its expiry and a random owner token: only its owner
	 * removes it, and a row past its expiry (a request that died) is taken over with a compare-and-delete.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function row_locked( string $lock, callable $fn, int $wait = 15 ): mixed {
		global $wpdb;
		if ( isset( $this->held[ $lock ] ) ) {
			++$this->held[ $lock ];
			try {
				return $fn();
			} finally {
				--$this->held[ $lock ];
			}
		}
		$name  = self::lock_row( $lock );
		$token = ( time() + self::LOCK_STALE ) . ':' . bin2hex( random_bytes( 12 ) );
		$until = microtime( true ) + $wait;
		for ( ;; ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === (int) $wpdb->rows_affected ) {
				break;
			}
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( is_string( $current ) && (int) strtok( $current, ':' ) < time() ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			if ( microtime( true ) >= $until ) {
				throw new VersioningException( __( 'Un\'altra pubblicazione è in corso: riprova tra poco.', 'xb' ), 409 );
			}
			usleep( 200_000 );
		}
		$this->held[ $lock ] = 1;
		try {
			return $fn();
		} finally {
			unset( $this->held[ $lock ] );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** The options row of a named lock. */
	public static function lock_row( string $lock ): string {
		return '_xb_lock_' . md5( $lock );
	}

	/** Releases are atomic only if every table involved is transactional. */
	public function assert_transactional(): void {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, Schema::table( 'commits' ), Schema::table( 'refs' ), Schema::table( 'releases' ), Schema::table( 'release_items' ) );
		$list   = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($list)", $tables ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $engine ) {
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				throw new VersioningException( __( 'Le tabelle del sito non sono tutte InnoDB: una pubblicazione non potrebbe essere annullata in caso di errore. Convertile prima di pubblicare.', 'xb' ), 500 );
			}
		}
	}

	/**
	 * A document's versions, newest first, without their payloads (a list, not a read of each).
	 *
	 * @return list<array{id: string, branch: string, parent: ?string, author: int, trusted: bool, message: string, created: string}>
	 */
	public function history( string $doc, int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, branch, parent, author, trusted, message, created FROM ' . Schema::table( 'commits' ) . ' WHERE doc = %s ORDER BY created DESC, id DESC LIMIT %d', $doc, max( 1, min( 1000, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'      => (string) $row['id'],
					'branch'  => (string) $row['branch'],
					'parent'  => null === $row['parent'] ? null : (string) $row['parent'],
					'author'  => (int) $row['author'],
					'trusted' => 1 === (int) $row['trusted'],
					'message' => (string) $row['message'],
					'created' => (string) $row['created'],
				);
			}
		}
		// Dates have one-second precision: within the same second, a commit is newer than its ancestors.
		$parents = array_column( $out, 'parent', 'id' );
		$depth   = static function ( string $id ) use ( $parents ): int {
			$n    = 0;
			$seen = array();
			while ( isset( $parents[ $id ] ) && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$id          = (string) $parents[ $id ];
				++$n;
			}
			return $n;
		};
		usort( $out, static fn ( array $a, array $b ): int => strcmp( $b['created'], $a['created'] ) ?: $depth( $b['id'] ) <=> $depth( $a['id'] ) );
		return $out;
	}

	/** @return int Number of versions of a document. */
	public function count_versions( string $doc ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'commits' ) . ' WHERE doc = %s', $doc ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The releases each of a document's versions went online with.
	 *
	 * @return array<string, list<string>> commit id → release versions
	 */
	public function releases_of( string $doc ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT i.commit_id, r.version FROM ' . Schema::table( 'release_items' ) . ' i JOIN ' . Schema::table( 'releases' ) . ' r ON r.id = i.release_id WHERE i.doc = %s ORDER BY r.id', $doc ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $row['commit_id'] ][] = (string) $row['version'];
			}
		}
		return $out;
	}

	/**
	 * Every document with a dev head, its prod head and the fingerprints of both, and the dev commit's message: one
	 * query for what changes() needed four or five per document (lista della spesa 151: 15.102 queries and 1,5 s on
	 * a site with 3000 pages, now one). No payload is read into PHP: where two different heads have the same
	 * fingerprint, `same_bytes` says whether their payloads are byte for byte the same, compared by the database
	 * (doc 23, voce 179); null where the fingerprints differ, the heads are one commit, or a commit is missing.
	 *
	 * @return list<array{doc: string, dev: string, prod: ?string, dev_hash: ?string, prod_hash: ?string, message: string, same_bytes: ?bool}>
	 */
	public function heads(): array {
		global $wpdb;
		$refs    = Schema::table( 'refs' );
		$commits = Schema::table( 'commits' );
		// As binary strings: the column's collation would call «é» and «e», or a trailing space, the same. SQLite
		// (through its translator) compares the two payloads one pair at a time instead (same_bytes()).
		$same    = self::sqlite() ? '' : ', CASE WHEN cd.hash = cp.hash AND d.commit_id <> p.commit_id THEN CAST(cd.payload AS BINARY) = CAST(cp.payload AS BINARY) END AS same_bytes';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT d.doc, d.commit_id AS dev, p.commit_id AS prod, cd.hash AS dev_hash, cp.hash AS prod_hash, cd.message{$same} FROM {$refs} d LEFT JOIN {$refs} p ON p.doc = d.doc AND p.branch = %s LEFT JOIN {$commits} cd ON cd.id = d.commit_id LEFT JOIN {$commits} cp ON cp.id = p.commit_id WHERE d.branch = %s ORDER BY d.doc", self::PROD, self::DEV ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$out     = array();
		foreach ( (array) $rows as $row ) {
			$head = array(
				'doc'        => (string) $row['doc'],
				'dev'        => (string) $row['dev'],
				'prod'       => null === $row['prod'] ? null : (string) $row['prod'],
				'dev_hash'   => null === $row['dev_hash'] ? null : (string) $row['dev_hash'],
				'prod_hash'  => null === $row['prod_hash'] ? null : (string) $row['prod_hash'],
				'message'    => (string) ( $row['message'] ?? '' ),
				'same_bytes' => null === ( $row['same_bytes'] ?? null ) ? null : 1 === (int) $row['same_bytes'],
			);
			if ( '' === $same && null !== $head['prod'] && null !== $head['dev_hash'] && $head['dev_hash'] === $head['prod_hash'] && $head['dev'] !== $head['prod'] ) {
				$head['same_bytes'] = $this->same_bytes( $head['dev'], $head['prod'] );
			}
			$out[] = $head;
		}
		return $out;
	}

	/**
	 * Whether two commits' payloads are byte for byte the same, read as stored, one at a time and not kept (no
	 * decoding, no read cache); null when one is missing.
	 */
	public function same_bytes( string $a, string $b ): ?bool {
		global $wpdb;
		$table = Schema::table( 'commits' );
		$first = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %s", $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		if ( ! is_string( $first ) ) {
			return null;
		}
		$second = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %s", $b ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return is_string( $second ) ? $first === $second : null;
	}

	/**
	 * The dev head of each of $docs whose dev and prod heads differ: what is being changed and not published yet.
	 * One query on the refs' primary key.
	 *
	 * @param list<string> $docs
	 * @return array<string, string> doc → dev commit id
	 */
	public function unpublished( array $docs ): array {
		global $wpdb;
		$docs = array_values( array_unique( array_filter( $docs, static fn ( string $d ): bool => Documents::valid( $d ) ) ) );
		if ( array() === $docs ) {
			return array();
		}
		$refs = Schema::table( 'refs' );
		$in   = implode( ',', array_fill( 0, count( $docs ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.doc, d.commit_id FROM {$refs} d LEFT JOIN {$refs} p ON p.doc = d.doc AND p.branch = %s WHERE d.branch = %s AND d.doc IN ($in) AND ( p.commit_id IS NULL OR p.commit_id <> d.commit_id )", array_merge( array( self::PROD, self::DEV ), $docs ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['doc'] ] = (string) $row['commit_id'];
		}
		return $out;
	}

	/**
	 * Size of the version history, for the overview (lista della spesa 153): number of commits and the oldest, in one
	 * indexed count, and the table's space as MySQL reports it (no scan of the payloads).
	 *
	 * @return array{commits: int, oldest: ?string, bytes: int}
	 */
	public function history_size(): array {
		global $wpdb;
		$table = Schema::table( 'commits' );
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS n, MIN(created) AS oldest FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		return array(
			'commits' => (int) ( $row['n'] ?? 0 ),
			'oldest'  => isset( $row['oldest'] ) ? (string) $row['oldest'] : null,
			'bytes'   => (int) $bytes,
		);
	}

	/**
	 * Deletes the versions saved before $before (GMT) that nothing needs (lista della spesa 153): kept are every
	 * document's dev and prod heads, the base each head was made from (three-way merges start from it), and every
	 * commit of a release, so each release can still be restored. Nothing walks the parent chain, so a kept commit
	 * whose parent goes is still whole. Works in batches; returns how many it deleted.
	 */
	public function prune( string $before, int $batch = 1000 ): int {
		global $wpdb;
		$commits = Schema::table( 'commits' );
		$refs    = Schema::table( 'refs' );
		$items   = Schema::table( 'release_items' );
		// The bases of the heads: a few thousand ids at most, read once.
		$bases   = array_fill_keys( array_map( 'strval', (array) $wpdb->get_col( "SELECT h.base FROM {$refs} r INNER JOIN {$commits} h ON h.id = r.commit_id WHERE h.base IS NOT NULL" ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = 0;
		$after   = array( '0000-00-00 00:00:00', '' );
		do {
			// Heads and release commits are left out by the indexes on commit_id; pages go by (created, id).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.created FROM {$commits} c WHERE c.created < %s AND ( c.created > %s OR ( c.created = %s AND c.id > %s ) ) AND NOT EXISTS (SELECT 1 FROM {$refs} r WHERE r.commit_id = c.id) AND NOT EXISTS (SELECT 1 FROM {$items} i WHERE i.commit_id = c.id) ORDER BY c.created ASC, c.id ASC LIMIT %d", $before, $after[0], $after[0], $after[1], max( 1, $batch ) ), ARRAY_A );
			if ( array() === $rows ) {
				break;
			}
			$last  = end( $rows );
			$after = array( (string) $last['created'], (string) $last['id'] );
			$ids   = array();
			foreach ( $rows as $row ) {
				$id = (string) $row['id'];
				if ( ! isset( $bases[ $id ] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $id ) ) {
					$ids[] = $id;
				}
			}
			if ( array() !== $ids ) {
				$in       = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$commits} WHERE id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$this->read = array();
		} while ( count( $rows ) === $batch );
		return $deleted;
	}

	/** @return list<string> Documents with a ref on the branch. */
	public function docs( string $branch ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT doc FROM ' . Schema::table( 'refs' ) . ' WHERE branch = %s ORDER BY doc', $branch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_map( 'strval', $rows ) );
	}

	/**
	 * @param array<string, string> $items doc → commit id
	 * @return int Release id.
	 */
	public function release( string $version, string $notes, array $items, ?int $restored_from ): int {
		global $wpdb;
		ksort( $items );
		$ok = $wpdb->insert(
			Schema::table( 'releases' ),
			array(
				'version'       => $version,
				'notes'         => $notes,
				'author'        => get_current_user_id(),
				'created'       => gmdate( 'Y-m-d H:i:s' ),
				'restored_from' => $restored_from,
				'hash'          => hash( 'sha256', self::encode( $items ) ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		if ( false === $ok ) {
			throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Release non salvata: %s', 'xb' ), $wpdb->last_error ) );
		}
		$id = (int) $wpdb->insert_id;
		foreach ( $items as $doc => $commit ) {
			$inserted = $wpdb->insert(
				Schema::table( 'release_items' ),
				array(
					'release_id' => $id,
					'doc'        => $doc,
					'commit_id'  => $commit,
				),
				array( '%d', '%s', '%s' )
			);
			if ( false === $inserted ) {
				throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Voce di release non salvata: %s', 'xb' ), $wpdb->last_error ) );
			}
		}
		return $id;
	}

	/** @return array{id: int, version: string, notes: string, author: int, created: string, restored_from: ?int, hash: string, items: array<string, string>}|null */
	public function get_release( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'releases' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		$items = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT doc, commit_id FROM ' . Schema::table( 'release_items' ) . ' WHERE release_id = %d', $id ), ARRAY_A ) as $item ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $item ) ) {
				$items[ (string) $item['doc'] ] = (string) $item['commit_id'];
			}
		}
		ksort( $items );
		return array(
			'id'            => (int) $row['id'],
			'version'       => (string) $row['version'],
			'notes'         => (string) $row['notes'],
			'author'        => (int) $row['author'],
			'created'       => (string) $row['created'],
			'restored_from' => null === $row['restored_from'] ? null : (int) $row['restored_from'],
			'hash'          => (string) $row['hash'],
			'items'         => $items,
		);
	}

	/** @return list<array{id: int, version: string, notes: string, author: int, created: string, restored_from: ?int}> */
	public function releases( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, version, notes, author, created, restored_from FROM ' . Schema::table( 'releases' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'            => (int) $row['id'],
					'version'       => (string) $row['version'],
					'notes'         => (string) $row['notes'],
					'author'        => (int) $row['author'],
					'created'       => (string) $row['created'],
					'restored_from' => null === $row['restored_from'] ? null : (int) $row['restored_from'],
				);
			}
		}
		return $out;
	}

	public function version_exists( string $version ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'releases' ) . ' WHERE version = %s', $version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function latest_version(): ?string {
		global $wpdb;
		$version = $wpdb->get_var( 'SELECT version FROM ' . Schema::table( 'releases' ) . ' ORDER BY id DESC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_string( $version ) ? $version : null;
	}

	/** @param array<string, mixed> $details */
	public function audit( string $action, string $object, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'audit' ),
			array(
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'user_id' => get_current_user_id(),
				'action'  => $action,
				'object'  => $object,
				'details' => self::encode( $details ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** @return list<array{at: string, user_id: int, action: string, object: string, details: string}> */
	public function audit_log( int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT at, user_id, action, object, details FROM ' . Schema::table( 'audit' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'at'      => (string) $row['at'],
					'user_id' => (int) $row['user_id'],
					'action'  => (string) $row['action'],
					'object'  => (string) $row['object'],
					'details' => (string) $row['details'],
				);
			}
		}
		return $out;
	}

	/**
	 * The details of the newest $action entry of each of $objects that has one, by object (ReleaseChecks::of_releases).
	 *
	 * @param list<string> $objects
	 * @return array<string, string>
	 */
	public function audit_of( string $action, array $objects ): array {
		global $wpdb;
		if ( array() === $objects ) {
			return array();
		}
		$table = Schema::table( 'audit' );
		$marks = implode( ',', array_fill( 0, count( $objects ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT object, details FROM {$table} WHERE action = %s AND object IN ({$marks}) ORDER BY id", array_merge( array( $action ), $objects ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$out   = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $row['object'] ] = (string) $row['details'];
			}
		}
		return $out;
	}

	/**
	 * A page of the audit log, newest first (Histeon → Registro). Dates are the site's days; `at` is stored in UTC.
	 *
	 * @param array{action?: string, user?: int, from?: string, to?: string} $filters
	 * @return array{rows: list<array{id: int, at: string, user_id: int, action: string, object: string, details: string}>, total: int}
	 */
	public function audit_page( array $filters, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( '' !== ( $filters['action'] ?? '' ) ) {
			$where[] = 'action = %s';
			$args[]  = $filters['action'];
		}
		if ( ( $filters['user'] ?? 0 ) > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $filters['user'];
		}
		foreach ( array( 'from' => array( '>=', ' 00:00:00' ), 'to' => array( '<=', ' 23:59:59' ) ) as $key => [ $op, $time ] ) {
			$day = (string) ( $filters[ $key ] ?? '' );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				$where[] = "at {$op} %s";
				$args[]  = get_gmt_from_date( $day . $time );
			}
		}
		$table = Schema::table( 'audit' );
		$sql   = '' === implode( '', $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
		$count = "SELECT COUNT(*) FROM {$table}{$sql}";
		$total = (int) $wpdb->get_var( array() === $args ? $count : $wpdb->prepare( $count, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, at, user_id, action, object, details FROM {$table}{$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$out      = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'id'      => (int) $row['id'],
					'at'      => (string) $row['at'],
					'user_id' => (int) $row['user_id'],
					'action'  => (string) $row['action'],
					'object'  => (string) $row['object'],
					'details' => (string) $row['details'],
				);
			}
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * The operations and the people the audit log knows, for its filters.
	 *
	 * @return array{actions: list<string>, users: list<int>}
	 */
	public function audit_facets(): array {
		global $wpdb;
		$table = Schema::table( 'audit' );
		return array(
			'actions' => array_values( array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a table name.
			'users'   => array_values( array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} ORDER BY user_id" ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a table name.
		);
	}

	/**
	 * Runs $fn in a database transaction; any exception rolls everything back. Nested calls use savepoints
	 * (a nested START TRANSACTION would silently commit the outer one).
	 */
	public function transaction( callable $fn ): mixed {
		global $wpdb;
		$savepoint = 'xb_sp_' . $this->depth;
		if ( false === $wpdb->query( 0 === $this->depth ? 'START TRANSACTION' : 'SAVEPOINT ' . $savepoint ) ) {
			throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Transazione non avviata: %s', 'xb' ), $wpdb->last_error ) );
		}
		++$this->depth;
		try {
			$result = $fn();
			--$this->depth;
			if ( 0 === $this->depth && false === $wpdb->query( 'COMMIT' ) ) {
				throw new VersioningException( sprintf( /* translators: %s: database error */ __( 'Commit della transazione non riuscito: %s', 'xb' ), $wpdb->last_error ) );
			}
			return $result;
		} catch ( \Throwable $e ) {
			if ( $this->depth > 0 && $this->depth_of( $savepoint ) ) {
				--$this->depth;
			}
			$wpdb->query( 0 === $this->depth ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint );
			// A commit read inside the transaction may be gone with it, and so may a component key.
			$this->read = array();
			// The option rolls back in the database but not in WordPress's option cache: dropped, rebuilt on use.
			\XB\Render\ComponentRegistry::forget_refs();
			throw $e;
		}
	}

	private function depth_of( string $savepoint ): bool {
		return 'xb_sp_' . ( $this->depth - 1 ) === $savepoint;
	}

	/** @param array<mixed> $value */
	public static function encode( array $value ): string {
		return (string) wp_json_encode( $value, self::JSON );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return Commit
	 */
	private static function hydrate( array $row ): array {
		$json    = (string) $row['payload'];
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || ! Integrity::matches( (string) $row['hash'], $json ) ) {
			// Lengths, PHP and charset in the server log: enough to tell a migration from a different PHP (item 124).
			self::mismatch( (string) $row['id'], '', $json, (string) $row['hash'] );
			Integrity::flag();
			throw new VersioningException( sprintf( /* translators: %s: commit id */ __( 'La versione %s non si legge: il contenuto non corrisponde alla sua impronta. Se il sito è stato spostato a un altro indirizzo, ripara le versioni dall\'avviso di Histeon nell\'amministrazione.', 'xb' ), (string) $row['id'] ) );
		}
		return array(
			'id'      => (string) $row['id'],
			'doc'     => (string) $row['doc'],
			'branch'  => (string) $row['branch'],
			'parent'  => null === $row['parent'] ? null : (string) $row['parent'],
			'base'    => null === $row['base'] ? null : (string) $row['base'],
			'payload' => $payload,
			'hash'    => (string) $row['hash'],
			'author'  => (int) $row['author'],
			'trusted' => 1 === (int) ( $row['trusted'] ?? 0 ),
			'message' => (string) $row['message'],
			'created' => (string) $row['created'],
		);
	}
}
