<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

use XB\Deploy\Deployer;
use XB\StaticSite\Exporter;
use XB\StaticSite\Scanner;

/**
 * WP-CLI: `wp xb static-build`, `wp xb deploy`, `wp xb deploys`, `wp xb deploy-rollback <id>`, `wp xb import-etch`,
 * `wp xb export-etch` (Export\Etch\Command), `wp xb export-core` (Export\Core\Command), `wp xb versions-repair`.
 * For large sites, where a web request would time out. Run it with --user=<an administrator>.
 */
final class Cli {

	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'xb static-build', array( self::class, 'build' ) );
		\WP_CLI::add_command( 'xb deploy', array( self::class, 'deploy' ) );
		\WP_CLI::add_command( 'xb deploys', array( self::class, 'deploys' ) );
		\WP_CLI::add_command( 'xb deploy-rollback', array( self::class, 'rollback' ) );
		\WP_CLI::add_command( 'xb import-etch', array( self::class, 'import_etch' ) );
		\WP_CLI::add_command( 'xb export-etch', array( \XB\Export\Etch\Command::class, 'run' ) );
		\WP_CLI::add_command( 'xb export-core', array( \XB\Export\Core\Command::class, 'run' ) );
		\WP_CLI::add_command( 'xb versions-repair', array( self::class, 'versions_repair' ) );
	}

	/**
	 * Repairs the fingerprints of commits after a move to another address (Versioning\Integrity): only commits whose
	 * content differs by the old address alone. Without --from it only gives old fingerprints the new form.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<url>]
	 * : The address the site had before the move, e.g. http://sito.local.
	 *
	 * [--accept]
	 * : Takes the commits still unreadable as they are now (their payload must still be a document). For a move
	 * that turned http and https into one scheme; check the pages first.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public static function versions_repair( array $args, array $assoc ): void {
		$from = isset( $assoc['from'] ) ? (string) $assoc['from'] : null;
		if ( null !== $from && null === \XB\Versioning\Integrity::origin( $from ) ) {
			\WP_CLI::error( 'Indirizzo di prima non valido: scrivilo intero, per esempio http://sito.local.' );
		}
		$result = \XB\Versioning\Integrity::repair_all( $from, isset( $assoc['accept'] ) );
		Plugin::instance()->versioning->store()->audit( 'versions_repair', 'versions', array( 'from' => (string) $from, 'repaired' => $result['repaired'], 'upgraded' => $result['upgraded'], 'accepted' => array_slice( $result['accepted'], 0, 200 ), 'unreadable' => array_slice( $result['unreadable'], 0, 20 ) ) );
		$message = \XB\Admin\VersionsRepair::summary( $result );
		if ( array() !== $result['unreadable'] ) {
			\WP_CLI::warning( $message );
			return;
		}
		\WP_CLI::success( $message );
	}

	/**
	 * Imports an Etch site (docs/spec/import-etch.md). Without --prepare it only reports.
	 * Run it as a user who may build and edit everything: `--user=<admin>`.
	 *
	 * ## OPTIONS
	 *
	 * [--prepare]
	 * : Writes the import as dev commits (publish it from the builder, roll it back like any release).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public static function import_etch( array $args, array $assoc ): void {
		unset( $args );
		if ( ! current_user_can( 'xb_build' ) ) {
			\WP_CLI::error( __( 'Serve un utente con il permesso xb_build: aggiungi --user=<amministratore>.', 'xb' ) );
		}
		try {
			$migration = \XB\Import\Etch\Migration::instance();
			$report    = isset( $assoc['prepare'] ) ? $migration->prepare() : $migration->plan();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $report['documents'] as $doc ) {
			$rows[] = array(
				'id'                     => $doc['id'],
				__( 'tipo', 'xb' )       => $doc['type'],
				__( 'titolo', 'xb' )     => mb_substr( (string) $doc['title'], 0, 40 ),
				__( 'fiducia', 'xb' )    => $doc['trusted'] ? 'trusted' : 'restricted',
				__( 'convertiti', 'xb' ) => $doc['counts']['converted'],
				'passthrough'            => $doc['counts']['passthrough'],
				__( 'non conv.', 'xb' )  => $doc['counts']['kept'],
				__( 'script', 'xb' )     => $doc['scripts'],
				__( 'problemi', 'xb' )   => count( $doc['issues'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ?? array( 'id' => 0 ) ) );
		\WP_CLI::log( sprintf( /* translators: %s: list of components */ __( 'Componenti: %s', 'xb' ), implode( ', ', array_map( static fn ( array $c ): string => $c['key'], $report['components'] ) ) ) );
		\WP_CLI::log( sprintf( /* translators: %s: list of loop presets */ __( 'Preset dei loop: %s', 'xb' ), implode( ', ', $report['presets'] ) ) );
		\WP_CLI::log( sprintf( /* translators: 1: global stylesheets, 2: selectors, 3: bytes */ __( 'Stili: %1$d fogli globali, %2$d selettori, %3$d byte', 'xb' ), $report['styles']['globals'], $report['styles']['selectors'], $report['styles']['bytes'] ) );
		\WP_CLI::log( sprintf( /* translators: %d: number of classes */ __( 'Classi usate dagli script (sempre incluse nel CSS su richiesta): %d', 'xb' ), count( $report['safelist'] ) ) );
		if ( null !== $report['framework'] ) {
			\WP_CLI::log( sprintf( /* translators: %s: CSS framework */ __( 'Framework CSS scelto per Histeon: %s', 'xb' ), $report['framework'] ) );
		}
		foreach ( $report['documents'] as $doc ) {
			foreach ( $doc['issues'] as $issue ) {
				\WP_CLI::warning( sprintf( '#%d %s: %s', $doc['id'], mb_substr( (string) $doc['title'], 0, 30 ), $issue ) );
			}
		}
		foreach ( $report['issues'] as $issue ) {
			\WP_CLI::warning( $issue );
		}
		$report['written'] ? \WP_CLI::success( __( 'Importazione preparata in sviluppo: controllala in anteprima e pubblicala dal builder.', 'xb' ) ) : \WP_CLI::success( __( 'Anteprima: nulla è stato modificato. Usa --prepare per scrivere in sviluppo.', 'xb' ) );
	}

	/** Builds the static bundle and prints the scan. */
	public static function build(): void {
		$build = ( new Exporter() )->build( 'cli' );
		\WP_CLI::log( sprintf( /* translators: 1: folder, 2: number of pages, 3: number of assets */ __( 'Bundle: %1$s (%2$d pagine, %3$d asset)', 'xb' ), $build['dir'], $build['pages'], $build['assets'] ) );
		foreach ( $build['log'] as $line ) {
			\WP_CLI::warning( $line );
		}
		foreach ( $build['findings'] as $finding ) {
			\WP_CLI::log( sprintf( '[%s] %s: %s', $finding['severity'], $finding['file'], $finding['rule'] ) );
		}
		Scanner::blocking( $build['findings'] ) ? \WP_CLI::error( __( 'Scansione: ci sono problemi bloccanti.', 'xb' ) ) : \WP_CLI::success( __( 'Bundle pronto.', 'xb' ) );
	}

	/** Builds and deploys to Cloudflare. */
	public static function deploy(): void {
		try {
			$result = ( new Deployer() )->deploy();
			foreach ( $result['warnings'] as $warning ) {
				\WP_CLI::warning( $warning );
			}
			\WP_CLI::success( sprintf( /* translators: 1: version ID, 2: number of files, 3: number uploaded */ __( 'Versione %1$s online (%2$d file, %3$d caricati).', 'xb' ), $result['version'], $result['files'], $result['uploaded'] ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	public static function deploys(): void {
		\WP_CLI\Utils\format_items( 'table', ( new Deployer() )->history(), array( 'id', 'created', 'release_id', 'version_id', 'status', 'files', 'message' ) );
	}

	/** @param list<string> $args */
	public static function rollback( array $args ): void {
		try {
			$result = ( new Deployer() )->rollback( (int) ( $args[0] ?? 0 ) );
			foreach ( $result['warnings'] as $warning ) {
				\WP_CLI::warning( $warning );
			}
			\WP_CLI::success( sprintf( /* translators: %s: version ID */ __( 'Online la versione %s', 'xb' ), $result['version'] ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}
}
