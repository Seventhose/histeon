<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * The design parameters of this Histeon site: dev in a signed preview (and the builder canvas), live otherwise.
 * Kept apart from Design, which is pure and ships in the standalone Histeon CSS plugin too (ADR 0015, M5).
 */
final class SiteDesign {

	/** @return array<string, string|int|float> */
	public static function current( bool $dev = false ): array {
		$payload = $dev || Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored  = is_array( $payload ) && array_key_exists( 'design', $payload ) ? $payload['design'] : get_option( Design::OPTION, array() );
		try {
			return Design::validate( $stored );
		} catch ( \InvalidArgumentException ) {
			return Design::DEFAULTS;
		}
	}
}
