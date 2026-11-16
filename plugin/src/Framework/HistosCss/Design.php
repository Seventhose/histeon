<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;


/**
 * The few values Histeon CSS is computed from — docs/spec/histos-css.md §1: base colours, the type and space scales, the
 * viewports, the radius, the widths, the font stacks; then the colour options of the panel (histos-publish doc 19
 * §5.3.1): families switched on or off, one lightness for the brand or the states, the hover shade, the colour roles
 * in the light and dark theme, the dark theme itself. Flags are 0 or 1, choices one of a list. They live in the settings document (key `design`: versions,
 * releases, restore) and, once released, in the option `xb_design`. Missing values take the defaults.
 */
final class Design {

	public const OPTION = 'xb_design';

	/** The colour families, in the order the palette shows them. */
	public const FAMILIES = DesignData::FAMILIES;

	public const DEFAULTS = DesignData::DEFAULTS;

	/** Numeric parameters: [minimum, maximum]. */
	private const LIMITS = DesignData::LIMITS;

	/**
	 * Every parameter checked, the missing ones from the defaults.
	 *
	 * @param mixed $raw `{ "primary": "#2271b1", "text_max": 19, … }`.
	 * @return array<string, string|int|float>
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function validate( mixed $raw ): array {
		if ( null === $raw || array() === $raw ) {
			return self::DEFAULTS;
		}
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( __( 'Design: serve un oggetto nome → valore.', 'xb' ) );
		}
		$out = self::DEFAULTS;
		foreach ( $raw as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
				/* translators: %s: parameter name */
				throw new \InvalidArgumentException( sprintf( __( 'Parametro sconosciuto: %s', 'xb' ), $key ) );
			}
			if ( in_array( $key, self::FAMILIES, true ) ) {
				$color = is_string( $value ) ? Color::parse( $value ) : null;
				if ( null === $color ) {
					/* translators: %s: colour family */
					throw new \InvalidArgumentException( sprintf( __( 'Colore non valido per %s: usa #rrggbb, rgb() o oklch().', 'xb' ), $key ) );
				}
				$out[ $key ] = $color->css();
			} elseif ( str_ends_with( $key, '_dark' ) && in_array( substr( $key, 0, -5 ), self::FAMILIES, true ) ) {
				// A family's colour in the dark theme: '' is automatic (its shade 400).
				$color = is_string( $value ) && '' !== trim( $value ) ? Color::parse( $value ) : null;
				if ( null === $color && ! ( is_string( $value ) && '' === trim( $value ) ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( 'Colore non valido per %s: usa #rrggbb, rgb() o oklch().', 'xb' ), $key ) );
				}
				$out[ $key ] = null === $color ? '' : $color->css();
			} elseif ( in_array( $key, DesignData::FLAGS, true ) ) {
				if ( ! in_array( $value, array( true, false, 0, 1, '0', '1' ), true ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( '%s: serve 0 o 1.', 'xb' ), $key ) );
				}
				$out[ $key ] = in_array( $value, array( true, 1, '1' ), true ) ? 1 : 0;
			} elseif ( isset( DesignData::CHOICES[ $key ] ) ) {
				$choice = is_string( $value ) || is_int( $value ) ? (string) $value : '';
				if ( ! in_array( $choice, DesignData::CHOICES[ $key ], true ) ) {
					/* translators: 1: parameter name, 2: the values allowed */
					throw new \InvalidArgumentException( sprintf( __( '%1$s: uno fra %2$s.', 'xb' ), $key, implode( ', ', DesignData::CHOICES[ $key ] ) ) );
				}
				$out[ $key ] = $choice;
			} elseif ( isset( DesignData::PATTERNS[ $key ] ) ) {
				// The two free texts of the panel: their pattern leaves nothing that could end a CSS string.
				$text = is_string( $value ) ? trim( $value ) : '';
				if ( ! is_string( $value ) || 1 !== preg_match( '/' . DesignData::PATTERNS[ $key ] . '/uD', $text ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( '%s: valore non valido.', 'xb' ), $key ) );
				}
				$out[ $key ] = $text;
			} elseif ( str_starts_with( $key, 'role_' ) ) {
				if ( ! is_string( $value ) || ! self::role( $value ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( '%s: auto, white, black, una famiglia o famiglia-sfumatura (primary-700).', 'xb' ), $key ) );
				}
				$out[ $key ] = $value;
			} elseif ( ! isset( self::LIMITS[ $key ] ) ) {
				// The font stacks: the only parameters that are neither colours nor numbers. The free roles may be empty.
				if ( in_array( $key, DesignData::OPTIONAL_FONTS, true ) && is_string( $value ) && '' === trim( $value ) ) {
					$out[ $key ] = '';
					continue;
				}
				if ( ! is_string( $value ) || ! self::font_stack( $value ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( '%s: solo nomi di caratteri separati da virgole.', 'xb' ), $key ) );
				}
				$out[ $key ] = trim( $value );
			} else {
				[ $min, $max ] = self::LIMITS[ $key ];
				if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
					/* translators: %s: parameter name */
					throw new \InvalidArgumentException( sprintf( __( '%s: serve un numero.', 'xb' ), $key ) );
				}
				$number = (float) $value;
				if ( $number < $min || $number > $max ) {
					/* translators: 1: parameter name, 2: minimum, 3: maximum */
					throw new \InvalidArgumentException( sprintf( __( '%1$s: fra %2$s e %3$s.', 'xb' ), $key, (string) $min, (string) $max ) );
				}
				$out[ $key ] = $number;
			}
		}
		if ( (float) $out['viewport_max'] <= (float) $out['viewport_min'] ) {
			throw new \InvalidArgumentException( __( 'La viewport grande deve essere più larga di quella piccola.', 'xb' ) );
		}
		return $out;
	}

	/** A colour role's value: `auto`, `white`, `black`, a family (`primary`) or one of its shades (`primary-700`). */
	public static function role( string $value ): bool {
		if ( in_array( $value, array( 'auto', 'white', 'black' ), true ) || in_array( $value, self::FAMILIES, true ) ) {
			return true;
		}
		$dash = strrpos( $value, '-' );
		return false !== $dash && in_array( substr( $value, 0, $dash ), self::FAMILIES, true ) && array_key_exists( substr( $value, $dash + 1 ), DesignData::SHADES );
	}

	/** The design parameter of a colour role: role_bg, role_text_muted_dark… */
	public static function role_key( string $role, bool $dark = false ): string {
		return 'role_' . str_replace( '-', '_', $role ) . ( $dark ? '_dark' : '' );
	}

	/**
	 * A font stack: family names (letters, digits, spaces, hyphens), quoted or not, separated by commas. Nothing that
	 * could end the declaration or the rule.
	 */
	public static function font_stack( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 300 ) {
			return false;
		}
		foreach ( explode( ',', $value ) as $family ) {
			if ( 1 !== preg_match( '/^\s*(?:"[\p{L}\p{N} \-]+"|\'[\p{L}\p{N} \-]+\'|[\p{L}\p{N}\-]+(?: [\p{L}\p{N}\-]+)*)\s*$/u', $family ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Only what differs from the defaults (what the settings document stores).
	 *
	 * @param array<string, string|int|float> $design
	 * @return array<string, string|int|float>
	 */
	public static function changes( array $design ): array {
		$out = array();
		foreach ( $design as $key => $value ) {
			if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
				continue;
			}
			$default = self::DEFAULTS[ $key ];
			$same    = is_string( $default ) ? (string) $value === $default : abs( (float) $value - (float) $default ) < 1e-9;
			if ( ! $same ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
