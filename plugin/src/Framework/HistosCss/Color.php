<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * Colours for Histeon CSS — docs/spec/histos-css.md §2. Reads a colour as the site owner writes it (hex, rgb(), oklch()),
 * works in OKLCH (perceptual: the same lightness looks equally light whatever the hue) and writes `oklch(…)`.
 * Conversions follow the published OKLab definition (Björn Ottosson, 2020); sRGB transfer per IEC 61966-2-1.
 */
final class Color {

	/** Lightness of each shade, from the lightest (50) to the darkest (950). */
	public const SHADES = DesignData::SHADES;

	/** Share of the colour's chroma each shade keeps: pale and deep shades are less saturated. */
	private const CHROMA = DesignData::CHROMA;

	/**
	 * @param float $l Lightness 0–1.
	 * @param float $c Chroma ≥ 0.
	 * @param float $h Hue in degrees 0–360.
	 */
	private function __construct(
		public readonly float $l,
		public readonly float $c,
		public readonly float $h,
	) {}

	public static function oklch( float $l, float $c, float $h ): self {
		$h = fmod( $h, 360.0 );
		return new self( max( 0.0, min( 1.0, $l ) ), max( 0.0, min( 0.5, $c ) ), $h < 0 ? $h + 360.0 : $h );
	}

	/** A colour as written: `#rgb`, `#rrggbb`, `rgb(r g b)` / `rgb(r, g, b)`, `oklch(L C H)` with L in % or 0–1. Null if unreadable. */
	public static function parse( string $value ): ?self {
		$value = strtolower( trim( $value ) );
		if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m ) ) {
			$hex = 3 === strlen( $m[1] ) ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
			return self::from_srgb( hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
		}
		if ( 1 === preg_match( '/^rgba?\(\s*(\d{1,3})\s*[ ,]\s*(\d{1,3})\s*[ ,]\s*(\d{1,3})\s*(?:[,\/]\s*[\d.]+%?\s*)?\)$/', $value, $m ) ) {
			$rgb = array_map( static fn ( string $n ): float => min( 255, (int) $n ) / 255, array( $m[1], $m[2], $m[3] ) );
			return self::from_srgb( $rgb[0], $rgb[1], $rgb[2] );
		}
		if ( 1 === preg_match( '/^oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)(?:deg)?\s*(?:\/\s*[\d.]+%?\s*)?\)$/', $value, $m ) ) {
			$l = (float) $m[1];
			return self::oklch( '%' === $m[2] || $l > 1.0 ? $l / 100 : $l, (float) $m[3], (float) $m[4] );
		}
		return null;
	}

	/** sRGB components 0–1 → OKLCH. */
	public static function from_srgb( float $r, float $g, float $b ): self {
		$lin = static fn ( float $v ): float => $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		$r   = $lin( $r );
		$g   = $lin( $g );
		$b   = $lin( $b );
		$l   = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
		$m   = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
		$s   = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
		$l   = self::cbrt( $l );
		$m   = self::cbrt( $m );
		$s   = self::cbrt( $s );
		$ok_l = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
		$ok_a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
		$ok_b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
		$c    = sqrt( $ok_a * $ok_a + $ok_b * $ok_b );
		$h    = $c < 1e-6 ? 0.0 : rad2deg( atan2( $ok_b, $ok_a ) );
		return self::oklch( $ok_l, $c, $h );
	}

	/** @return array{0: float, 1: float, 2: float} sRGB 0–1, clipped to the gamut. */
	public function to_srgb(): array {
		$a  = $this->c * cos( deg2rad( $this->h ) );
		$b  = $this->c * sin( deg2rad( $this->h ) );
		$l_ = ( $this->l + 0.3963377774 * $a + 0.2158037573 * $b ) ** 3;
		$m_ = ( $this->l - 0.1055613458 * $a - 0.0638541728 * $b ) ** 3;
		$s_ = ( $this->l - 0.0894841775 * $a - 1.2914855480 * $b ) ** 3;
		$rgb = array(
			4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
			-1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
			-0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
		);
		$gamma = static fn ( float $v ): float => $v <= 0.0031308 ? 12.92 * $v : 1.055 * ( $v ** ( 1 / 2.4 ) ) - 0.055;
		$out   = array_map( static fn ( float $v ): float => max( 0.0, min( 1.0, $gamma( max( 0.0, $v ) ) ) ), $rgb );
		return array( $out[0], $out[1], $out[2] );
	}

	/** WCAG 2 relative luminance (0 black – 1 white). */
	public function luminance(): float {
		$lin = static fn ( float $v ): float => $v <= 0.04045 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		[ $r, $g, $b ] = $this->to_srgb();
		return 0.2126 * $lin( $r ) + 0.7152 * $lin( $g ) + 0.0722 * $lin( $b );
	}

	/** WCAG 2 contrast ratio between two colours (1–21). */
	public static function contrast( self $one, self $two ): float {
		$a = $one->luminance();
		$b = $two->luminance();
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/** The shade of this colour at a step of the scale (50 … 950): its hue, the step's lightness, a share of its chroma. */
	public function shade( int $step ): self {
		return self::oklch( self::SHADES[ $step ] ?? $this->l, $this->c * ( self::CHROMA[ $step ] ?? 1.0 ), $this->h );
	}

	/** Text that reads on this colour: near-white or near-black, whichever contrasts more. */
	public function on(): self {
		$light = self::oklch( 0.99, 0.0, 0.0 );
		$dark  = self::oklch( 0.20, min( 0.02, $this->c ), $this->h );
		return self::contrast( $this, $light ) >= self::contrast( $this, $dark ) ? $light : $dark;
	}

	/** `oklch(62% 0.18 265)`: rounded, always the same text for the same colour. */
	public function css(): string {
		$l = rtrim( rtrim( sprintf( '%.1F', $this->l * 100 ), '0' ), '.' );
		$c = $this->c < 0.0005 ? '0' : rtrim( rtrim( sprintf( '%.3F', $this->c ), '0' ), '.' );
		$h = $this->c < 0.0005 ? '0' : rtrim( rtrim( sprintf( '%.1F', $this->h ), '0' ), '.' );
		return 'oklch(' . $l . '% ' . $c . ' ' . $h . ')';
	}

	private static function cbrt( float $v ): float {
		return $v < 0 ? -( ( -$v ) ** ( 1 / 3 ) ) : $v ** ( 1 / 3 );
	}
}
