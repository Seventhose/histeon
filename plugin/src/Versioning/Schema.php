<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/**
 * Versioning tables — docs/spec/versioning.md.
 *
 * xb_commits        immutable snapshots of a document (payload JSON + sha256 for integrity)
 * xb_refs           branch heads: (doc, dev|prod) → commit
 * xb_releases       published versions of the whole design
 * xb_release_items  (release, doc) → commit: a full snapshot, so a rollback restores everything
 * xb_audit          who published, rolled back or imported what, and when
 */
final class Schema {

	public const VERSION = '4';
	public const OPTION  = 'xb_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'xb_' . $name;
	}

	public static function maybe_install(): void {
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$commits = self::table( 'commits' );
		$refs    = self::table( 'refs' );
		$rels    = self::table( 'releases' );
		$items   = self::table( 'release_items' );
		$audit   = self::table( 'audit' );
		$deploys = self::table( 'deploys' );

		dbDelta(
			"CREATE TABLE {$commits} (
				id char(32) NOT NULL,
				doc varchar(191) NOT NULL,
				branch varchar(16) NOT NULL,
				parent char(32) DEFAULT NULL,
				base char(32) DEFAULT NULL,
				payload longtext NOT NULL,
				hash char(64) NOT NULL,
				author bigint(20) unsigned NOT NULL DEFAULT 0,
				trusted tinyint(1) NOT NULL DEFAULT 0,
				message varchar(255) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY doc_created (doc, created)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$refs} (
				doc varchar(191) NOT NULL,
				branch varchar(16) NOT NULL,
				commit_id char(32) NOT NULL,
				updated datetime NOT NULL,
				PRIMARY KEY  (doc, branch),
				KEY branch_doc (branch, doc),
				KEY commit_id (commit_id)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$rels} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version varchar(32) NOT NULL,
				notes text NOT NULL,
				author bigint(20) unsigned NOT NULL DEFAULT 0,
				created datetime NOT NULL,
				restored_from bigint(20) unsigned DEFAULT NULL,
				hash char(64) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY version (version)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$items} (
				release_id bigint(20) unsigned NOT NULL,
				doc varchar(191) NOT NULL,
				commit_id char(32) NOT NULL,
				PRIMARY KEY  (release_id, doc),
				KEY commit_id (commit_id)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(32) NOT NULL,
				object varchar(191) NOT NULL DEFAULT '',
				details longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY at (at)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$deploys} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				author bigint(20) unsigned NOT NULL DEFAULT 0,
				release_id bigint(20) unsigned DEFAULT NULL,
				target varchar(32) NOT NULL,
				version_id varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL,
				files int(10) unsigned NOT NULL DEFAULT 0,
				uploaded int(10) unsigned NOT NULL DEFAULT 0,
				rollback_of bigint(20) unsigned DEFAULT NULL,
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created)
			) ENGINE=InnoDB {$charset};"
		);
		update_option( self::OPTION, self::VERSION, false );
	}
}
