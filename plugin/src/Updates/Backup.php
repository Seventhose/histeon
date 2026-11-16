<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Updates;

use XB\Versioning\Schema;
use XB\Versioning\Store;

/**
 * Safety copies of Histeon before an update (doc 23 of Histeon Publish, item 178; docs/spec/updates.md §6).
 *
 * A copy is the plugin's folder as a ZIP (`plugin.zip`) and Histeon's own tables (Schema: commits, refs, releases,
 * release_items, audit, deploys) as their `CREATE TABLE` and their rows (`tables.ndjson.gz`), in
 * uploads/xb-backups/<id>/, closed to the web like the static bundles (Apache: .htaccess; on nginx add
 * `location ~ /xb-backups/ { deny all; }`; the folder names are random anyway). The list, with the checksums of the two
 * files, is the option xb_update_backups. At most KEEP copies: a new one removes the oldest.
 *
 * WordPress makes one by itself before it installs a Histeon package (upgrader_source_selection): an update from the
 * plugins screen, the automatic updates, WP-CLI, the signed updater of Histeon (Updater) and an uploaded ZIP that
 * replaces the installed one. A copy that fails stops the update (XB_UPDATE_BACKUP false in wp-config.php skips it).
 *
 * «Torna alla versione di prima» (restore()) puts back both: the tables first, built aside and swapped in with one
 * RENAME TABLE, then the folder, extracted and checked aside and swapped in with two renames.
 *
 * Not covered: the site's content (posts, pages and their meta, media, menus), Histeon's options and settings, the
 * tables and options of the modules (Lingua Bridge, Static Bridge: their code is in the folder, their data is not),
 * other plugins and the theme. Not on a multisite network: there the folder is every site's and the tables each one's.
 */
final class Backup {

	public const OPTION = 'xb_update_backups';
	public const KEEP   = 2;
	/** Histeon's own tables, by their Schema names. */
	public const TABLES = array( 'commits', 'refs', 'releases', 'release_items', 'audit', 'deploys' );

	private const ID    = '/^\d{8}-\d{6}-[0-9a-f]{12}$/D';
	private const CHUNK = 200;
	/** The order rows are read in: the primary key of each table. */
	private const ORDER = array(
		'commits'       => 'id',
		'refs'          => 'doc, branch',
		'releases'      => 'id',
		'release_items' => 'release_id, doc',
		'audit'         => 'id',
		'deploys'       => 'id',
	);
	/** Folders a development checkout has and an installed plugin does not. */
	private const SKIPPED = array( '.git', 'node_modules' );

	/** Whether the last package options of the upgrader replace an installed plugin (an uploaded ZIP over it). */
	private static bool $overwrite = false;

	public static function register(): void {
		add_filter( 'upgrader_package_options', array( self::class, 'note_options' ) );
		add_filter( 'upgrader_source_selection', array( self::class, 'before_install' ), 5, 4 );
	}

	/** Copies are made and restored only on a single site, by who may manage the site and update plugins. */
	public static function available(): bool {
		return ! is_multisite();
	}

	public static function can_manage(): bool {
		return self::available() && current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' ) && current_user_can( 'xb_build' );
	}

	/** False when wp-config.php says `define( 'XB_UPDATE_BACKUP', false );`. */
	public static function enabled(): bool {
		return self::available() && ! ( defined( 'XB_UPDATE_BACKUP' ) && false === constant( 'XB_UPDATE_BACKUP' ) );
	}

	/**
	 * The folder of the copies, closed to the web.
	 *
	 * @throws \RuntimeException When it cannot be made.
	 */
	public static function root(): string {
		$root = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'xb-backups';
		if ( ! wp_mkdir_p( $root ) ) {
			throw new \RuntimeException( __( 'Impossibile creare la cartella delle copie negli upload.', 'xb' ) );
		}
		foreach ( array( 'index.html' => '', 'index.php' => "<?php\n// Silence is golden.\n", '.htaccess' => "Require all denied\nOptions -Indexes\n" ) as $file => $body ) {
			if ( ! is_file( $root . '/' . $file ) || file_get_contents( $root . '/' . $file ) !== $body ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				file_put_contents( $root . '/' . $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $root;
	}

	/** The plugin's folder (a filter lets the tests use a folder of their own). */
	public static function plugin_dir(): string {
		$dir = apply_filters( 'xb_update_backup_plugin_dir', dirname( XB_FILE ) );
		return untrailingslashit( is_string( $dir ) ? $dir : dirname( XB_FILE ) );
	}

	/**
	 * The copies, newest first.
	 *
	 * @return list<array{id: string, created: int, version: string, to: string, dbVersion: string, reason: string, files: int, tables: array<string, int>, sha256: array<string, string>, bytes: int}>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || 1 !== preg_match( self::ID, $entry['id'] ) ) {
				continue;
			}
			$tables = array();
			foreach ( is_array( $entry['tables'] ?? null ) ? $entry['tables'] : array() as $name => $rows ) {
				if ( in_array( $name, self::TABLES, true ) ) {
					$tables[ (string) $name ] = (int) $rows;
				}
			}
			$sha = is_array( $entry['sha256'] ?? null ) ? $entry['sha256'] : array();
			$out[] = array(
				'id'        => $entry['id'],
				'created'   => (int) ( $entry['created'] ?? 0 ),
				'version'   => (string) ( $entry['version'] ?? '' ),
				'to'        => (string) ( $entry['to'] ?? '' ),
				'dbVersion' => (string) ( $entry['dbVersion'] ?? '' ),
				'reason'    => (string) ( $entry['reason'] ?? '' ),
				'files'     => (int) ( $entry['files'] ?? 0 ),
				'tables'    => $tables,
				'sha256'    => array(
					'plugin.zip'       => (string) ( $sha['plugin.zip'] ?? '' ),
					'tables.ndjson.gz' => (string) ( $sha['tables.ndjson.gz'] ?? '' ),
				),
				'bytes'     => (int) ( $entry['bytes'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Makes a copy of the folder and the tables, keeps the newest KEEP.
	 *
	 * @param string $reason `update`, `upload` or `manual`.
	 * @param string $to     The version about to be installed, when known.
	 * @return array<string, mixed> The new entry.
	 * @throws \RuntimeException With a message for the administrator.
	 */
	public static function create( string $reason, string $to = '' ): array {
		if ( ! self::available() ) {
			throw new \RuntimeException( __( 'Su un multisito Histeon non fa copie di sicurezza: la cartella del plugin è di tutti i siti, le tabelle di ognuno.', 'xb' ) );
		}
		if ( ! class_exists( \ZipArchive::class ) || ! function_exists( 'gzopen' ) ) {
			throw new \RuntimeException( __( 'Mancano le estensioni zip o zlib di PHP.', 'xb' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}
		$id  = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) );
		$dir = self::root() . '/' . $id;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( __( 'Impossibile creare la cartella della copia negli upload.', 'xb' ) );
		}
		try {
			$files  = self::zip_folder( self::plugin_dir(), $dir . '/plugin.zip' );
			$tables = self::dump_tables( $dir . '/tables.ndjson.gz' );
			$sha    = array(
				'plugin.zip'       => (string) hash_file( 'sha256', $dir . '/plugin.zip' ),
				'tables.ndjson.gz' => (string) hash_file( 'sha256', $dir . '/tables.ndjson.gz' ),
			);
		} catch ( \Throwable $e ) {
			self::delete_dir( $dir );
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
		$entry = array(
			'id'        => $id,
			'created'   => time(),
			'version'   => defined( 'XB_VERSION' ) ? (string) XB_VERSION : '',
			'to'        => mb_substr( $to, 0, 32 ),
			'dbVersion' => (string) get_option( Schema::OPTION, '' ),
			'reason'    => in_array( $reason, array( 'update', 'upload', 'manual' ), true ) ? $reason : 'manual',
			'files'     => $files,
			'tables'    => $tables,
			'sha256'    => $sha,
			'bytes'     => (int) filesize( $dir . '/plugin.zip' ) + (int) filesize( $dir . '/tables.ndjson.gz' ),
		);
		$list = array_merge( array( $entry ), self::all() );
		foreach ( array_slice( $list, self::KEEP ) as $old ) {
			self::delete_dir( self::root() . '/' . $old['id'] );
		}
		update_option( self::OPTION, array_slice( $list, 0, self::KEEP ), false );
		self::audit( 'update_backup', $id, array( 'reason' => $entry['reason'], 'version' => $entry['version'], 'to' => $entry['to'] ) );
		return $entry;
	}

	/**
	 * Puts back the folder and the tables of a copy. The tables are built aside and swapped in with one RENAME TABLE;
	 * the folder is extracted and checked aside, then swapped in; a failed swap of the folder swaps the tables back.
	 * On SQLite (restore_on_sqlite) the tables are written again inside one transaction instead.
	 *
	 * @return array{id: string, created: int, version: string, to: string, dbVersion: string, reason: string, files: int, tables: array<string, int>, sha256: array<string, string>, bytes: int}
	 * @throws \RuntimeException With a message for the administrator.
	 */
	public static function restore( string $id ): array {
		global $wpdb;
		$entry = null;
		foreach ( self::all() as $candidate ) {
			if ( $candidate['id'] === $id ) {
				$entry = $candidate;
			}
		}
		if ( null === $entry || ! self::available() ) {
			throw new \RuntimeException( __( 'Copia di sicurezza non trovata.', 'xb' ) );
		}
		$dir = self::root() . '/' . $entry['id'];
		foreach ( $entry['sha256'] as $file => $sha ) {
			if ( ! is_file( $dir . '/' . $file ) || '' === $sha || ! hash_equals( $sha, (string) hash_file( 'sha256', $dir . '/' . $file ) ) ) {
				throw new \RuntimeException( __( 'La copia è incompleta o è cambiata dopo essere stata fatta: non si usa.', 'xb' ) );
			}
		}
		$plugin = self::plugin_dir();
		$parent = dirname( $plugin );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method( array(), $parent ) ) {
			throw new \RuntimeException( __( 'WordPress non può scrivere direttamente nella cartella dei plugin (chiede credenziali FTP): rimetti la copia a mano.', 'xb' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}
		$token = bin2hex( random_bytes( 6 ) );
		$stage = $parent . '/.xb-restore-' . $token;
		$old   = $parent . '/.xb-old-' . $token;
		try {
			self::unzip( $dir . '/plugin.zip', $stage );
			if ( ! is_file( $stage . '/' . basename( XB_FILE ) ) ) {
				throw new \RuntimeException( __( 'Nella copia manca il file principale di Histeon.', 'xb' ) );
			}
			$loaded = self::load_tables( $dir . '/tables.ndjson.gz', '__xbr' );
		} catch ( \Throwable $e ) {
			self::delete_dir( $stage );
			self::drop_tables( '__xbr' );
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
		if ( Store::sqlite() ) {
			self::restore_on_sqlite( $dir . '/tables.ndjson.gz', $plugin, $stage, $old );
			return self::restored( $entry, $plugin, $old );
		}
		// The tables: the current ones go aside (__xbo), the copied ones take their names, in one statement.
		$renames = array();
		$back    = array();
		foreach ( $loaded as $name ) {
			$table = Schema::table( $name );
			$back[] = "`{$table}` TO `{$table}__xbr`";
			if ( self::table_exists( $table ) ) {
				$renames[] = "`{$table}` TO `{$table}__xbo`";
				$back[]    = "`{$table}__xbo` TO `{$table}`";
			}
			$renames[] = "`{$table}__xbr` TO `{$table}`";
		}
		self::drop_tables( '__xbo' );
		if ( array() !== $renames && false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from Schema.
			self::delete_dir( $stage );
			self::drop_tables( '__xbr' );
			throw new \RuntimeException( __( 'Le tabelle di Histeon non si sono potute rimettere: niente è cambiato.', 'xb' ) );
		}
		// The folder: two renames in the plugins folder. If the second fails, everything goes back.
		$moved = rename( $plugin, $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $moved || ! rename( $stage, $plugin ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( $moved ) {
				rename( $old, $plugin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			if ( array() !== $back ) {
				$wpdb->query( 'RENAME TABLE ' . implode( ', ', $back ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from Schema.
			}
			self::drop_tables( '__xbr' );
			self::delete_dir( $stage );
			throw new \RuntimeException( __( 'La cartella del plugin non si è potuta sostituire: niente è cambiato.', 'xb' ) );
		}
		self::drop_tables( '__xbo' );
		return self::restored( $entry, $plugin, $old );
	}

	/**
	 * After the swap: the old folder goes, the schema version of the copy comes back.
	 *
	 * @param array{id: string, created: int, version: string, to: string, dbVersion: string, reason: string, files: int, tables: array<string, int>, sha256: array<string, string>, bytes: int} $entry
	 * @return array{id: string, created: int, version: string, to: string, dbVersion: string, reason: string, files: int, tables: array<string, int>, sha256: array<string, string>, bytes: int}
	 */
	private static function restored( array $entry, string $plugin, string $old ): array {
		self::delete_dir( $old );
		if ( '' !== $entry['dbVersion'] ) {
			update_option( Schema::OPTION, $entry['dbVersion'], false );
		}
		self::invalidate_opcache( $plugin );
		self::audit( 'update_restore', $entry['id'], array( 'version' => $entry['version'] ) );
		return $entry;
	}

	/**
	 * The swap on SQLite. The SQLite Database Integration driver does not run RENAME TABLE, and it takes
	 * ALTER TABLE … RENAME TO without renaming anything; SQLite changes tables inside a transaction, though. So the
	 * copy, already loaded and checked aside (__xbr), is written again over the tables of Histeon in one transaction,
	 * the folder is swapped, and only then the transaction is committed: a failed swap rolls the tables back.
	 *
	 * @throws \RuntimeException With a message for the administrator; nothing has changed.
	 */
	private static function restore_on_sqlite( string $file, string $plugin, string $stage, string $old ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::load_tables( $file, '' );
		} catch ( \Throwable ) {
			$wpdb->query( 'ROLLBACK' );
			self::delete_dir( $stage );
			self::drop_tables( '__xbr' );
			throw new \RuntimeException( __( 'Le tabelle di Histeon non si sono potute rimettere: niente è cambiato.', 'xb' ) );
		}
		$moved   = rename( $plugin, $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		$swapped = $moved && rename( $stage, $plugin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $swapped || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $swapped ) {
				rename( $plugin, $stage ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			if ( $moved ) {
				rename( $old, $plugin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			self::drop_tables( '__xbr' );
			self::delete_dir( $stage );
			throw new \RuntimeException( $swapped ? __( 'Le tabelle di Histeon non si sono potute rimettere: niente è cambiato.', 'xb' ) : __( 'La cartella del plugin non si è potuta sostituire: niente è cambiato.', 'xb' ) );
		}
		self::drop_tables( '__xbr' );
	}

	/**
	 * Before WordPress installs a Histeon package: a copy of what is installed. A failed copy stops the update.
	 *
	 * @param mixed $source        The folder of the unpacked package.
	 * @param mixed $remote_source
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 */
	public static function before_install( mixed $source, mixed $remote_source = null, mixed $upgrader = null, mixed $hook_extra = array() ): mixed {
		if ( ! is_string( $source ) || ! self::enabled() || ! self::is_histeon_package( $source, $hook_extra ) ) {
			return $source;
		}
		$header = get_file_data( trailingslashit( $source ) . basename( XB_FILE ), array( 'version' => 'Version' ) );
		try {
			self::create( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ? 'update' : 'upload', (string) ( $header['version'] ?? '' ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'xb_update_backup',
				/* translators: %s: reason */
				sprintf( __( 'Aggiornamento di Histeon fermato: la copia di sicurezza non è riuscita (%s). Libera spazio negli upload e riprova; per aggiornare senza copia aggiungi define( \'XB_UPDATE_BACKUP\', false ); in wp-config.php.', 'xb' ), $e->getMessage() )
			);
		}
		return $source;
	}

	/**
	 * Remembers whether the upgrader replaces an installed plugin (WordPress's «Sostituisci la versione attuale»).
	 *
	 * @param mixed $options
	 */
	public static function note_options( mixed $options ): mixed {
		self::$overwrite = is_array( $options ) && ! empty( $options['clear_destination'] );
		return $options;
	}

	/**
	 * An update of this plugin, or an uploaded ZIP with this plugin's folder that replaces it.
	 *
	 * @param mixed $hook_extra
	 */
	public static function is_histeon_package( string $source, mixed $hook_extra ): bool {
		$installed = self::plugin_dir();
		$extra     = is_array( $hook_extra ) ? $hook_extra : array();
		$update    = plugin_basename( XB_FILE ) === ( $extra['plugin'] ?? null );
		$upload    = 'plugin' === ( $extra['type'] ?? null ) && 'install' === ( $extra['action'] ?? null ) && self::$overwrite
			&& basename( untrailingslashit( $source ) ) === basename( $installed );
		return ( $update || $upload ) && is_dir( $installed ) && is_file( trailingslashit( $source ) . basename( XB_FILE ) );
	}

	// ---------------------------------------------------------------- files

	/** The folder in a ZIP, paths relative to it; symbolic links and development folders stay out. */
	private static function zip_folder( string $folder, string $file ): int {
		if ( ! is_dir( $folder ) ) {
			throw new \RuntimeException( __( 'La cartella del plugin non c’è.', 'xb' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( __( 'Impossibile scrivere lo ZIP della copia.', 'xb' ) );
		}
		$count = 0;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ),
				static fn ( \SplFileInfo $item ): bool => ! $item->isLink() && ! ( $item->isDir() && in_array( $item->getFilename(), self::SKIPPED, true ) )
			)
		);
		foreach ( $items as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $folder ) + 1 ) );
			if ( ! $zip->addFile( $item->getPathname(), $relative ) ) {
				throw new \RuntimeException( __( 'Impossibile aggiungere un file allo ZIP della copia.', 'xb' ) );
			}
			++$count;
		}
		if ( ! $zip->close() ) {
			throw new \RuntimeException( __( 'Lo ZIP della copia non si è chiuso: forse manca spazio.', 'xb' ) );
		}
		return $count;
	}

	/** Extracts a copy's ZIP into a new folder, refusing any name that would leave it. */
	private static function unzip( string $file, string $into ): void {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( __( 'Lo ZIP della copia non si apre.', 'xb' ) );
		}
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );
			if ( '' === $name || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || 1 === preg_match( '#(^|/)\.\.(/|$)#', $name ) || str_contains( $name, ':' ) ) {
				$zip->close();
				throw new \RuntimeException( __( 'Lo ZIP della copia contiene un percorso non ammesso.', 'xb' ) );
			}
		}
		if ( ! wp_mkdir_p( $into ) || ! $zip->extractTo( $into ) ) {
			$zip->close();
			throw new \RuntimeException( __( 'Lo ZIP della copia non si è potuto estrarre.', 'xb' ) );
		}
		$zip->close();
	}

	/** The PHP files of the folder leave the opcache, so the next request runs the restored code. */
	private static function invalidate_opcache( string $folder ): void {
		if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $folder ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && 'php' === $item->getExtension() ) {
				opcache_invalidate( $item->getPathname(), true );
			}
		}
	}

	/** Removes a folder of ours (a copy, a staging or an old plugin folder) with its content. */
	private static function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item instanceof \SplFileInfo ) {
				$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	// ---------------------------------------------------------------- tables

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Each existing table of Histeon as one line with its CREATE TABLE, then one line per row, in a gzip file.
	 *
	 * @return array<string, int> Rows per table.
	 */
	private static function dump_tables( string $file ): array {
		global $wpdb;
		$out = gzopen( $file, 'wb6' );
		if ( false === $out ) {
			throw new \RuntimeException( __( 'Impossibile scrivere la copia delle tabelle.', 'xb' ) );
		}
		$counts = array();
		try {
			foreach ( self::TABLES as $name ) {
				$table = Schema::table( $name );
				if ( ! self::table_exists( $table ) ) {
					continue;
				}
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", 'ARRAY_N' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- name from Schema.
				if ( ! is_array( $create ) || ! is_string( $create[1] ?? null ) ) {
					throw new \RuntimeException( __( 'La struttura di una tabella di Histeon non si legge.', 'xb' ) );
				}
				self::write_line( $out, array( 'table' => $name, 'create' => $create[1] ) );
				$rows_of_table = 0;
				for ( $offset = 0; ; $offset += self::CHUNK ) {
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY " . self::ORDER[ $name ] . ' LIMIT %d, %d', $offset, self::CHUNK ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names from Schema.
					if ( ! is_array( $rows ) || array() === $rows ) {
						break;
					}
					foreach ( $rows as $row ) {
						self::write_line( $out, array( 'row' => $row ) );
						++$rows_of_table;
					}
					if ( count( $rows ) < self::CHUNK ) {
						break;
					}
				}
				$counts[ $name ] = $rows_of_table;
			}
		} finally {
			gzclose( $out );
		}
		return $counts;
	}

	/**
	 * @param resource             $out
	 * @param array<string, mixed> $value
	 */
	private static function write_line( $out, array $value ): void {
		$line = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $line ) || false === gzwrite( $out, $line . "\n" ) ) {
			throw new \RuntimeException( __( 'Impossibile scrivere la copia delle tabelle: forse manca spazio.', 'xb' ) );
		}
	}

	/**
	 * Builds each table of the copy under its name with the suffix, from its own CREATE TABLE, with its rows.
	 *
	 * @return list<string> The Schema names loaded.
	 */
	private static function load_tables( string $file, string $suffix ): array {
		global $wpdb;
		$in = gzopen( $file, 'rb' );
		if ( false === $in ) {
			throw new \RuntimeException( __( 'La copia delle tabelle non si apre.', 'xb' ) );
		}
		$loaded  = array();
		$current = null;
		try {
			while ( ! gzeof( $in ) ) {
				$line = gzgets( $in );
				if ( false === $line || '' === trim( $line ) ) {
					continue;
				}
				$item = json_decode( $line, true );
				if ( ! is_array( $item ) ) {
					throw new \RuntimeException( __( 'La copia delle tabelle è rovinata.', 'xb' ) );
				}
				if ( isset( $item['table'] ) ) {
					$name   = is_string( $item['table'] ) && in_array( $item['table'], self::TABLES, true ) ? $item['table'] : null;
					$create = is_string( $item['create'] ?? null ) ? $item['create'] : '';
					$table  = null === $name ? '' : Schema::table( $name );
					// Only the CREATE TABLE of that very table, one statement.
					if ( null === $name || ! str_starts_with( $create, 'CREATE TABLE `' . $table . '` (' ) || str_contains( $create, ';' ) ) {
						throw new \RuntimeException( __( 'La copia delle tabelle contiene una struttura non attesa.', 'xb' ) );
					}
					$current = $table . $suffix;
					$wpdb->query( "DROP TABLE IF EXISTS `{$current}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- name from Schema.
					if ( false === $wpdb->query( 'CREATE TABLE `' . $current . '` (' . substr( $create, strlen( 'CREATE TABLE `' . $table . '` (' ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- checked above.
						throw new \RuntimeException( __( 'Una tabella di Histeon non si è potuta ricreare dalla copia.', 'xb' ) );
					}
					$loaded[] = $name;
					continue;
				}
				$row = $item['row'] ?? null;
				if ( null === $current || ! is_array( $row ) ) {
					throw new \RuntimeException( __( 'La copia delle tabelle è rovinata.', 'xb' ) );
				}
				if ( false === $wpdb->insert( $current, $row ) ) {
					throw new \RuntimeException( __( 'Una riga della copia non si è potuta rimettere.', 'xb' ) );
				}
			}
		} finally {
			gzclose( $in );
		}
		return $loaded;
	}

	/** Drops the tables of Histeon with the suffix (__xbr, __xbo) left by a restore. */
	private static function drop_tables( string $suffix ): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . Schema::table( $name ) . $suffix . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- name from Schema.
		}
	}

	/** @param array<string, string> $details */
	private static function audit( string $action, string $object, array $details ): void {
		try {
			\XB\Plugin::instance()->versioning->store()->audit( $action, $object, $details );
		} catch ( \Throwable ) {
			// The register is a convenience: a copy or a restore never fails for it.
		}
	}
}
