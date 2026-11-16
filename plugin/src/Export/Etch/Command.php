<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

/**
 * `wp xb export-etch` — docs/spec/export-etch.md §6. Run it as an administrator: `--user=<admin>`.
 */
final class Command {

	/**
	 * Exports the Histeon project to Etch's data on this site. Without --apply it only shows what would change.
	 *
	 * ## OPTIONS
	 *
	 * [--tokens=<mode>]
	 * : histos-css (default: the export carries Histeon CSS) or acss (only with Automatic.css installed by the owner).
	 *
	 * [--file=<path>]
	 * : Also saves the export as JSON, to read or keep. Etch does not import this file.
	 *
	 * [--apply]
	 * : Writes into this site, after a backup of everything it replaces. Needs --confirm.
	 *
	 * [--confirm=<fingerprint>]
	 * : The fingerprint the preview printed: the write happens only if nothing changed since.
	 *
	 * [--restore]
	 * : Puts back what the last --apply replaced. Needs --yes.
	 *
	 * [--yes]
	 * : Confirms --restore.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		unset( $args );
		if ( ! Exporter::can_export() ) {
			\WP_CLI::error( __( 'Serve un amministratore con il permesso xb_build: aggiungi --user=<amministratore>.', 'xb' ) );
		}
		try {
			if ( isset( $assoc['restore'] ) ) {
				if ( ! isset( $assoc['yes'] ) ) {
					\WP_CLI::error( __( 'Il ripristino riscrive contenuti e opzioni: aggiungi --yes per confermare.', 'xb' ) );
				}
				$done = Exporter::restore();
				\WP_CLI::success( sprintf( /* translators: 1: date, 2: posts, 3: options */ __( 'Ripristinata la copia del %1$s: %2$d contenuti, %3$d opzioni.', 'xb' ), $done['at'], $done['posts'], $done['options'] ) );
				return;
			}
			$exporter = new Exporter( (string) ( $assoc['tokens'] ?? Tokens::HISTOS ) );
			$report   = isset( $assoc['apply'] ) ? $exporter->apply( (string) ( $assoc['confirm'] ?? '' ) ) : $exporter->plan();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( isset( $assoc['file'] ) && '' !== $assoc['file'] ) {
			if ( false === file_put_contents( $assoc['file'], Exporter::file( $report ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a path the CLI user chose.
				\WP_CLI::error( __( 'Impossibile scrivere il file.', 'xb' ) );
			}
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( array_diff_key( $report, array( 'posts' => true, 'options' => true ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$changes = array_column( $report['diff']['posts'], null, 'id' );
		$rows    = array();
		foreach ( $report['report']['documents'] as $doc ) {
			$rows[] = array(
				'id'                     => $doc['id'],
				__( 'tipo', 'xb' )       => $doc['type'],
				__( 'titolo', 'xb' )     => mb_substr( (string) $doc['title'], 0, 40 ),
				__( 'fiducia', 'xb' )    => $doc['trusted'] ? 'trusted' : 'restricted',
				__( 'blocchi', 'xb' )    => $doc['blocks'],
				__( 'omessi', 'xb' )     => $doc['omitted'],
				__( 'script', 'xb' )     => $doc['scripts'],
				__( 'già Etch', 'xb' )   => ! empty( $changes[ $doc['id'] ]['etch'] ) ? 'sì' : '',
				__( 'problemi', 'xb' )   => count( $doc['issues'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ?? array( 'id' => 0 ) ) );
		$entries = array();
		foreach ( $report['diff']['options'] as $entry ) {
			$entries[] = array(
				__( 'opzione', 'xb' ) => $entry['option'],
				__( 'chiave', 'xb' )  => $entry['key'],
				__( 'azione', 'xb' )  => $entry['action'],
			);
		}
		if ( array() !== $entries ) {
			\WP_CLI\Utils\format_items( 'table', $entries, array_keys( $entries[0] ) );
		}
		foreach ( $report['report']['documents'] as $doc ) {
			foreach ( (array) ( $doc['changes'] ?? array() ) as $change ) {
				\WP_CLI::log( sprintf( '#%d %s: %s', $doc['id'], mb_substr( (string) $doc['title'], 0, 30 ), $change ) );
			}
			foreach ( $doc['issues'] as $issue ) {
				\WP_CLI::warning( sprintf( '#%d %s: %s', $doc['id'], mb_substr( (string) $doc['title'], 0, 30 ), $issue ) );
			}
		}
		foreach ( array_merge( $report['report']['issues'], $report['report']['skipped'] ) as $issue ) {
			\WP_CLI::warning( $issue );
		}
		if ( $report['written'] ) {
			\WP_CLI::success( __( 'Esportazione scritta nei dati di Etch di questo sito. Copia di sicurezza fatta: per tornare indietro usa --restore --yes.', 'xb' ) );
			return;
		}
		\WP_CLI::log( sprintf( /* translators: %s: fingerprint */ __( 'Impronta: %s', 'xb' ), $report['fingerprint'] ) );
		\WP_CLI::success( __( 'Anteprima: nulla è stato modificato. Per scrivere: --apply --confirm=<impronta>.', 'xb' ) );
	}
}
