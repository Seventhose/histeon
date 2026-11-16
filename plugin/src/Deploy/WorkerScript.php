<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Deploy;

/** The Worker deployed with the static site (assets/worker/worker.js): serves assets and handles forms. */
final class WorkerScript {

	public static function source(): string {
		$source = file_get_contents( dirname( XB_FILE ) . '/assets/worker/worker.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $source ) {
			throw new DeployException( __( 'Sorgente del Worker mancante.', 'xb' ) );
		}
		return $source;
	}
}
