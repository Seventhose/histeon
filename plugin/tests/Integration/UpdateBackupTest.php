<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Updates\Backup;
use XB\Versioning\Schema;

/**
 * Safety copies before an update of Histeon and the way back (doc 23 of Histeon Publish, item 178). The tests copy
 * and restore a plugin folder of their own (the filter xb_update_backup_plugin_dir), never the plugin that runs them.
 */
final class UpdateBackupTest extends IntegrationTestCase {

	private string $folder = '';
	/** @var mixed */
	private $saved_option;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_option = get_option( Backup::OPTION, null );
		delete_option( Backup::OPTION );
		$this->folder = dirname( XB_FILE, 2 ) . '/xb-backup-test-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $this->folder . '/src' );
		file_put_contents( $this->folder . '/' . basename( XB_FILE ), "<?php\n/**\n * Plugin Name: Histeon (test copy)\n * Version: 0.0.1\n */\n" );
		file_put_contents( $this->folder . '/src/Uno.php', "<?php // uno\n" );
		add_filter( 'xb_update_backup_plugin_dir', array( $this, 'folder' ) );
		add_filter( 'filesystem_method', array( $this, 'direct' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'xb_update_backup_plugin_dir', array( $this, 'folder' ) );
		remove_filter( 'filesystem_method', array( $this, 'direct' ) );
		foreach ( Backup::all() as $copy ) {
			$this->remove( Backup::root() . '/' . $copy['id'] );
		}
		$this->remove( $this->folder );
		if ( null === $this->saved_option ) {
			delete_option( Backup::OPTION );
		} else {
			update_option( Backup::OPTION, $this->saved_option, false );
		}
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function latest(): array {
		return Backup::all()[0] ?? array();
	}

	public function folder(): string {
		return $this->folder;
	}

	public function direct(): string {
		return 'direct';
	}

	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	public function test_a_copy_holds_the_folder_and_the_tables_in_a_closed_folder_and_only_two_are_kept(): void {
		global $wpdb;
		$first = Backup::create( 'manual' );
		$dir   = Backup::root() . '/' . $first['id'];
		$this->assertFileExists( $dir . '/plugin.zip' );
		$this->assertFileExists( $dir . '/tables.ndjson.gz' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( Backup::root() . '/.htaccess' ) );
		$this->assertSame( 2, $first['files'] );
		$this->assertSame( array( 'commits', 'refs', 'releases', 'release_items', 'audit', 'deploys' ), array_keys( $first['tables'] ) );
		$this->assertSame( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'commits' ) ), $first['tables']['commits'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $dir . '/plugin.zip' ) );
		$this->assertNotFalse( $zip->locateName( 'src/Uno.php' ) );
		$zip->close();

		$second = Backup::create( 'manual' );
		$third  = Backup::create( 'update', '9.9.9' );
		$this->assertSame( array( $third['id'], $second['id'] ), array_column( Backup::all(), 'id' ) );
		$this->assertDirectoryDoesNotExist( $dir, 'the oldest copy goes' );
		$this->assertSame( '9.9.9', Backup::all()[0]['to'] );
	}

	public function test_back_to_the_copy_puts_back_the_folder_and_the_tables(): void {
		global $wpdb;
		$commits = Schema::table( 'commits' );
		$before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commits}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$version = get_option( Schema::OPTION );
		$copy    = Backup::create( 'manual' );

		// After the copy: the folder changes, a commit is written, the schema version moves on.
		file_put_contents( $this->folder . '/src/Uno.php', "<?php // nuovo\n" );
		file_put_contents( $this->folder . '/src/Due.php', "<?php // due\n" );
		$wpdb->insert( $commits, array( 'id' => str_repeat( 'f', 32 ), 'doc' => 'xb-backup-test', 'branch' => 'dev', 'payload' => '{}', 'hash' => str_repeat( '0', 64 ), 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
		update_option( Schema::OPTION, '999', false );

		Backup::restore( $copy['id'] );
		$this->assertSame( "<?php // uno\n", file_get_contents( $this->folder . '/src/Uno.php' ) );
		$this->assertFileDoesNotExist( $this->folder . '/src/Due.php' );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commits}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$commits} WHERE doc = %s", 'xb-backup-test' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $version, get_option( Schema::OPTION ) );
		$this->assertSame( array(), array_merge( glob( dirname( $this->folder ) . '/.xb-restore-*' ) ?: array(), glob( dirname( $this->folder ) . '/.xb-old-*' ) ?: array() ), 'nothing left in the plugins folder' );
		$this->assertSame( array(), $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $commits ) . '\_\_xb%' ) ), 'no aside tables left' );
	}

	/**
	 * After the copy: the folder and the tables change, so that a failed way back must leave them like this.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The copy and the commits counted after the change.
	 */
	private function copy_then_change(): array {
		global $wpdb;
		$commits = Schema::table( 'commits' );
		$copy    = Backup::create( 'manual' );
		file_put_contents( $this->folder . '/src/Uno.php', "<?php // nuovo\n" );
		file_put_contents( $this->folder . '/src/Due.php', "<?php // due\n" );
		$wpdb->insert( $commits, array( 'id' => str_repeat( 'e', 32 ), 'doc' => 'xb-backup-test', 'branch' => 'dev', 'payload' => '{}', 'hash' => str_repeat( '0', 64 ), 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
		return array( $copy, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commits}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** A failed way back: the folder and the tables as they were after the copy, nothing left aside. */
	private function assert_nothing_changed( int $count ): void {
		global $wpdb;
		$commits = Schema::table( 'commits' );
		$this->assertSame( "<?php // nuovo\n", file_get_contents( $this->folder . '/src/Uno.php' ), 'the folder as it was' );
		$this->assertFileExists( $this->folder . '/src/Due.php' );
		$this->assertSame( $count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commits}" ), 'the tables as they were' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( str_repeat( 'e', 32 ), $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$commits} WHERE doc = %s", 'xb-backup-test' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array(), $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $commits ) . '\_\_xb%' ) ), 'no aside tables left' );
		foreach ( array( dirname( $this->folder ), $this->folder ) as $dir ) {
			$this->assertSame( array(), array_merge( glob( $dir . '/.xb-restore-*' ) ?: array(), glob( $dir . '/.xb-old-*' ) ?: array() ), 'no stage or old folder left in ' . $dir );
		}
		$wpdb->delete( $commits, array( 'id' => str_repeat( 'e', 32 ) ) );
	}

	/**
	 * The folder cannot be swapped (here: the path of the plugin ends in «/.», which rename() refuses): the tables,
	 * already put back (on SQLite inside the open transaction, on MySQL with RENAME TABLE), go back as they were.
	 */
	public function test_a_folder_that_cannot_be_swapped_leaves_the_folder_and_the_tables_as_they_were(): void {
		list( $copy, $count ) = $this->copy_then_change();
		$dot = fn (): string => $this->folder . '/.';
		add_filter( 'xb_update_backup_plugin_dir', $dot, 20 );
		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			Backup::restore( $copy['id'] );
			$this->fail( 'A folder that cannot be swapped must stop the way back.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'La cartella del plugin non si è potuta sostituire: niente è cambiato.', $e->getMessage() );
		} finally {
			restore_error_handler();
			remove_filter( 'xb_update_backup_plugin_dir', $dot, 20 );
		}
		$this->assertNotSame( array(), preg_grep( '/^rename\(/', $warnings ), 'the rename really failed' );
		$this->assert_nothing_changed( $count );
	}

	/** The tables fail half way while they are written over the real ones (SQLite only): all of them go back. */
	public function test_tables_that_fail_half_way_on_sqlite_leave_the_folder_and_the_tables_as_they_were(): void {
		if ( ! \XB\Versioning\Store::sqlite() ) {
			$this->markTestSkipped( 'Only on SQLite: on MySQL the copy is loaded only aside (__xbr) and swapped in with one RENAME TABLE, never written over the real tables.' );
		}
		list( $copy, $count ) = $this->copy_then_change();
		$audit = 'CREATE TABLE `' . Schema::table( 'audit' ) . '` (';
		$throw = static function ( string $query ) use ( $audit ): string {
			if ( str_starts_with( $query, $audit ) ) {
				throw new \RuntimeException( 'test: the copy of the audit table cannot be written' );
			}
			return $query;
		};
		add_filter( 'query', $throw );
		try {
			Backup::restore( $copy['id'] );
			$this->fail( 'Tables that fail half way must stop the way back.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Le tabelle di Histeon non si sono potute rimettere: niente è cambiato.', $e->getMessage() );
		} finally {
			remove_filter( 'query', $throw );
		}
		$this->assert_nothing_changed( $count );
	}

	public function test_a_copy_changed_after_it_was_made_is_not_used(): void {
		$copy = Backup::create( 'manual' );
		file_put_contents( Backup::root() . '/' . $copy['id'] . '/plugin.zip', 'x', FILE_APPEND );
		file_put_contents( $this->folder . '/src/Uno.php', "<?php // nuovo\n" );
		try {
			Backup::restore( $copy['id'] );
			$this->fail( 'A changed copy must be refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'cambiata', $e->getMessage() );
		}
		$this->assertSame( "<?php // nuovo\n", file_get_contents( $this->folder . '/src/Uno.php' ), 'nothing changed' );
		$this->expectException( \RuntimeException::class );
		Backup::restore( '20260101-000000-000000000000' );
	}

	public function test_wordpress_makes_a_copy_before_it_installs_histeon_and_stops_when_it_cannot(): void {
		$package = get_temp_dir() . 'xb-backup-package-' . bin2hex( random_bytes( 4 ) ) . '/' . basename( $this->folder );
		wp_mkdir_p( $package );
		file_put_contents( $package . '/' . basename( XB_FILE ), "<?php\n/**\n * Plugin Name: Histeon\n * Version: 9.9.9\n */\n" );
		try {
			// Another plugin: nothing.
			$this->assertSame( $package, Backup::before_install( $package, null, null, array( 'plugin' => 'akismet/akismet.php' ) ) );
			$this->assertSame( array(), Backup::all() );
			// An uploaded ZIP that does not replace the installed plugin: nothing either.
			Backup::note_options( array( 'clear_destination' => false ) );
			$this->assertSame( $package, Backup::before_install( $package, null, null, array( 'type' => 'plugin', 'action' => 'install' ) ) );
			$this->assertSame( array(), Backup::all() );
			// An update of Histeon: a copy, with the version about to come.
			$this->assertSame( $package, Backup::before_install( $package, null, null, array( 'plugin' => plugin_basename( XB_FILE ) ) ) );
			$latest = self::latest();
			$this->assertSame( array( 'update', '9.9.9' ), array( $latest['reason'] ?? null, $latest['to'] ?? null ) );
			// An uploaded ZIP that replaces it: a copy too.
			Backup::note_options( array( 'clear_destination' => true ) );
			$this->assertSame( $package, Backup::before_install( $package, null, null, array( 'type' => 'plugin', 'action' => 'install' ) ) );
			$this->assertSame( 'upload', self::latest()['reason'] ?? null );
			// A copy that cannot be made stops the update with the reason.
			$broken = static fn ( array $dirs ): array => array_merge( $dirs, array( 'basedir' => '/proc/xb-no-uploads' ) );
			add_filter( 'upload_dir', $broken );
			try {
				$stopped = Backup::before_install( $package, null, null, array( 'plugin' => plugin_basename( XB_FILE ) ) );
			} finally {
				remove_filter( 'upload_dir', $broken );
			}
			$this->assertInstanceOf( \WP_Error::class, $stopped );
			$this->assertSame( 'xb_update_backup', $stopped->get_error_code() );
			$this->assertStringContainsString( 'XB_UPDATE_BACKUP', $stopped->get_error_message() );
		} finally {
			Backup::note_options( array() );
			$this->remove( dirname( $package ) );
		}
	}
}
