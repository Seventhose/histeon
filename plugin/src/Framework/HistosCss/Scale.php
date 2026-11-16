<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * Fluid scales for Histeon CSS — docs/spec/histos-css.md §2. A step of a modular scale has one size on a small screen and
 * another on a large one; between the two viewports it grows in a straight line, outside it stays put:
 * `clamp(min, intercept + slope·vw, max)`. Sizes in rem (16 px), so the visitor's font setting still counts.
 */
final class Scale {

	private const ROOT_PX = DesignData::ROOT_PX;

	/**
	 * The step of a scale: base size × ratio^step, on the small and on the large viewport.
	 *
	 * @return array{0: float, 1: float} Pixels at the small and at the large viewport.
	 */
	public static function step( int $step, float $base_min, float $base_max, float $ratio_min, float $ratio_max ): array {
		return array( $base_min * ( $ratio_min ** $step ), $base_max * ( $ratio_max ** $step ) );
	}

	/** `clamp(…)` from a size at the small viewport to one at the large viewport (pixels in, rem out). */
	public static function fluid( float $min_px, float $max_px, float $viewport_min, float $viewport_max ): string {
		if ( abs( $max_px - $min_px ) < 0.01 || $viewport_max <= $viewport_min ) {
			return self::rem( $min_px );
		}
		$slope     = ( $max_px - $min_px ) / ( $viewport_max - $viewport_min );
		$intercept = $min_px - $slope * $viewport_min;
		$vw        = $slope * 100;
		$preferred = self::rem( $intercept ) . ( $vw < 0 ? ' - ' : ' + ' ) . self::number( abs( $vw ), 4 ) . 'vw';
		return 'clamp(' . self::rem( min( $min_px, $max_px ) ) . ', ' . $preferred . ', ' . self::rem( max( $min_px, $max_px ) ) . ')';
	}

	public static function rem( float $px ): string {
		return self::number( $px / self::ROOT_PX, 4 ) . 'rem';
	}

	/** A number with at most $decimals decimals, no trailing zeros, never "-0". */
	public static function number( float $value, int $decimals = 3 ): string {
		$out = rtrim( rtrim( sprintf( '%.' . $decimals . 'F', $value ), '0' ), '.' );
		return '-0' === $out || '' === $out ? '0' : $out;
	}
}
