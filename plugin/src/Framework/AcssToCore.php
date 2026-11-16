<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;


/**
 * Automatic.css → Core Framework, completely — docs/spec/framework.md §6. Core Framework is free and open
 * source (MIT): moving a site's design system there leaves no license behind.
 *
 * Reads what ACSS generated for this site (its own files in uploads, and its recipes through its public API)
 * and writes a new Core Framework project, a copy of the current one with:
 *   - the brand and status colours of ACSS, converted to the hsla Core Framework works with;
 *   - a stylesheet with every ACSS variable and its value, so any `var(--…)` of ACSS keeps working.
 * The ACSS rules the site uses (base styles of elements, rules of every class its pages render, read from its
 * public pages as visitors get them) go to a compatibility stylesheet XB loads where ACSS loaded them: after
 * the site's own styles, so every rule keeps winning or losing exactly as before.
 * The ACSS recipes become completions of XB's CSS editor (`@name`). Nothing of either plugin is modified:
 * Core Framework builds the project's CSS when it is opened and saved in its dashboard.
 */
final class AcssToCore {

	public const RECIPES     = 'xb_css_recipes';
	/** The compatibility stylesheet: ACSS rules the site uses, loaded after the site's own styles. */
	public const COMPAT      = 'xb_acss_compat';
	private const MAX_RECIPE = 4096;

	/** ACSS colour families → Core Framework colours (group "Brand" or "Status"). */
	private const COLORS = array(
		'primary'   => array( 'Brand', 'primary' ),
		'secondary' => array( 'Brand', 'secondary' ),
		'tertiary'  => array( 'Brand', 'tertiary' ),
		'accent'    => array( 'Brand', 'accent' ),
		'success'   => array( 'Status', 'success' ),
		'danger'    => array( 'Status', 'error' ),
		'warning'   => array( 'Status', 'warning' ),
		'info'      => array( 'Status', 'info' ),
	);

	public static function available(): bool {
		global $wpdb;
		return ( new AcssProvider() )->available()
			&& ( new CoreFrameworkProvider() )->available()
			&& null !== self::acss_file( 'automatic-variables.css' )
			&& $wpdb->prefix . 'core_framework_presets' === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'core_framework_presets' ) );
	}

	private static function acss_file( string $name ): ?string {
		$path = wp_upload_dir()['basedir'] . '/automatic-css/' . $name;
		return is_readable( $path ) && filesize( $path ) > 0 ? $path : null;
	}

	/**
	 * Every custom property ACSS defines at the root, with its value.
	 *
	 * @return array<string, string>
	 */
	public static function variables(): array {
		$file = self::acss_file( 'automatic-variables.css' );
		$css  = null === $file ? '' : (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$out  = array();
		preg_match_all( '/:root\s*\{([^}]*)\}/', self::strip_comments( $css ), $roots );
		foreach ( $roots[1] as $body ) {
			preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $m, PREG_SET_ORDER );
			foreach ( $m as $decl ) {
				$out[ $decl[1] ] = trim( $decl[2] );
			}
		}
		return $out;
	}

	private static function strip_comments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * ACSS recipes (its "expansions"): name → CSS, through ACSS's public API.
	 *
	 * @return array<string, string>
	 */
	public static function recipes(): array {
		if ( ! class_exists( '\\Automatic_CSS\\API' ) ) {
			return array();
		}
		try {
			$all = \Automatic_CSS\API::get_all_recipes();
		} catch ( \Throwable ) {
			return array();
		}
		return self::clean_recipes( is_array( $all ) ? $all : array() );
	}

	/**
	 * Recipes XB keeps: a simple name, CSS without markup or blocks that could close a rule early.
	 *
	 * @param array<mixed> $recipes
	 * @return array<string, string>
	 */
	public static function clean_recipes( array $recipes ): array {
		$out = array();
		foreach ( $recipes as $name => $css ) {
			$name = (string) $name;
			if ( ! is_string( $css ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,60}$/', $name ) || strlen( $css ) > self::MAX_RECIPE || str_contains( $css, '<' ) || substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
				continue;
			}
			$out[ $name ] = trim( $css );
			if ( count( $out ) >= 500 ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * What the import would do, without writing anything.
	 *
	 * @return array{variables: int, colors: array<string, string>, classes: list<string>, recipes: int, preset: string|null}
	 */
	public static function plan(): array {
		$vars   = self::variables();
		$colors = array();
		foreach ( array_keys( self::COLORS ) as $family ) {
			$hsla = isset( $vars[ '--' . $family ] ) ? self::to_hsla( $vars[ '--' . $family ] ) : null;
			if ( null !== $hsla ) {
				$colors[ $family ] = $hsla;
			}
		}
		$current = self::current_preset();
		return array(
			'variables' => count( $vars ),
			'colors'    => $colors,
			'classes'   => self::missing_classes(),
			'recipes'   => count( self::recipes() ),
			'preset'    => null === $current ? null : (string) ( $current['name'] ?? '' ),
		);
	}

	/**
	 * Writes the new Core Framework project (selected) and XB's recipes. Returns the new project's id.
	 *
	 * @throws \RuntimeException With a message for the administrator.
	 */
	public static function import(): string {
		global $wpdb;
		$base = self::current_preset();
		if ( null === $base ) {
			throw new \RuntimeException( __( 'Nessun progetto di Core Framework da cui partire: apri Core Framework una volta per crearlo.', 'xb' ) );
		}
		$vars   = self::variables();
		$preset = $base;
		$id     = self::new_id();
		$preset['id']          = $id;
		$preset['name']        = __( 'Automatic.css (importato)', 'xb' );
		$preset['description'] = sprintf( /* translators: %s: date */ __( 'Importato da Automatic.css con Histeon il %s.', 'xb' ), wp_date( 'Y-m-d H:i' ) );
		$preset['date']        = gmdate( 'c' );

		// Colours: the ACSS palette in Core Framework's own colour system (its shades and utilities follow).
		$groups = (array) ( $preset['modulesData']['COLOR_SYSTEM']['groups'] ?? array() );
		foreach ( self::COLORS as $family => [ $group_name, $color_name ] ) {
			$hsla = isset( $vars[ '--' . $family ] ) ? self::to_hsla( $vars[ '--' . $family ] ) : null;
			if ( null === $hsla ) {
				continue;
			}
			$groups = self::set_color( $groups, $group_name, $color_name, $hsla );
		}
		$preset['modulesData']['COLOR_SYSTEM']['groups'] = $groups;

		// Stylesheets: every ACSS variable, then the ACSS rules of the classes the site still needs.
		$root = ":root {\n";
		foreach ( $vars as $name => $value ) {
			$root .= "  $name: $value;\n";
		}
		$root  .= "}\n";
		$sheets = array_values(
			array_filter(
				(array) ( $preset['modulesData']['STYLESHEETS']['groups'] ?? array() ),
				static fn ( mixed $g ): bool => is_array( $g ) && ! str_starts_with( (string) ( $g['name'] ?? '' ), 'Automatic.css' )
			)
		);
		$sheets[] = array(
			'id'       => self::new_id(),
			'name'     => __( 'Automatic.css — variabili', 'xb' ),
			'css'      => self::safe_css( $root ),
			'isActive' => true,
		);
		Carry::write_compat( 'acss', Carry::rules( new AcssProvider() ) );
		$preset['modulesData']['STYLESHEETS']['groups']     = $sheets;
		$preset['modulesData']['STYLESHEETS']['isDisabled'] = false;

		$table = $wpdb->prefix . 'core_framework_presets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Core Framework keeps its projects in this table; same row shape as its own REST endpoint writes.
		$ok = $wpdb->insert(
			$table,
			array(
				'id'   => $id,
				'time' => current_time( 'mysql' ),
				'data' => (string) wp_json_encode( $preset ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( __( 'Impossibile scrivere il progetto di Core Framework.', 'xb' ) );
		}
		$main = get_option( 'core_framework_main', array() );
		$main = is_array( $main ) ? $main : array();
		$main['selected_id'] = $id;
		update_option( 'core_framework_main', $main, false );

		update_option( self::RECIPES, self::recipes(), false );
		return $id;
	}

	/** URL of the compatibility stylesheet (Carry), or null. */
	public static function compat_url(): ?string {
		return Carry::compat_url();
	}

	public static function register(): void {
		Carry::register();
	}

	/**
	 * The project Core Framework has selected now.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function current_preset(): ?array {
		global $wpdb;
		$main = get_option( 'core_framework_main', array() );
		$id   = is_array( $main ) ? (string) ( $main['selected_id'] ?? '' ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = '' === $id ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . $wpdb->prefix . 'core_framework_presets WHERE id = %s', $id ) );
		$data = is_string( $row ) ? json_decode( $row, true ) : null;
		return is_array( $data ) && isset( $data['modulesData'] ) ? $data : null;
	}

	/**
	 * @param array<mixed> $groups
	 * @return array<mixed>
	 */
	private static function set_color( array $groups, string $group_name, string $color_name, string $value ): array {
		foreach ( $groups as $gi => $group ) {
			if ( ! is_array( $group ) || ( $group['name'] ?? '' ) !== $group_name ) {
				continue;
			}
			foreach ( (array) ( $group['colors'] ?? array() ) as $ci => $color ) {
				if ( is_array( $color ) && ( $color['name'] ?? '' ) === $color_name ) {
					$groups[ $gi ]['colors'][ $ci ]['value'] = $value;
					return $groups;
				}
			}
			// A colour Core Framework's project does not have yet: added like its own, shades and tints included.
			$template                     = is_array( $group['colors'][0] ?? null ) ? $group['colors'][0] : array();
			$template['id']               = self::new_id();
			$template['name']             = $color_name;
			$template['value']            = $value;
			$template['shades']           = array();
			$template['tints']            = array();
			$groups[ $gi ]['colors'][]    = $template;
			return $groups;
		}
		return $groups;
	}

	/**
	 * ACSS classes used in the site's XB content and global styles that Core Framework has no class for.
	 *
	 * @return list<string>
	 */
	public static function missing_classes(): array {
		$acss = array();
		foreach ( ( new AcssProvider() )->classes() as $names ) {
			foreach ( $names as $name ) {
				$acss[ $name ] = true;
			}
		}
		$core = array();
		foreach ( ( new CoreFrameworkProvider() )->classes() as $names ) {
			foreach ( $names as $name ) {
				$core[ $name ] = true;
			}
		}
		$map  = ( new Migration( \XB\Plugin::instance()->frameworks, \XB\Plugin::instance()->versioning ) )->map( new AcssProvider(), new CoreFrameworkProvider() );
		$used = array();
		foreach ( Carry::site_classes() as $class ) {
			if ( isset( $acss[ $class ] ) && ! isset( $core[ $class ] ) && ! isset( $map['classes'][ $class ] ) ) {
				$used[] = $class;
			}
		}
		sort( $used );
		return $used;
	}


	/** CSS for a stylesheet of Core Framework: nothing its own checks refuse, nothing that closes a style element. */
	private static function safe_css( string $css ): string {
		return str_ireplace( array( '</', '<script', 'expression(', 'javascript:' ), array( '\3C /', '', '', '' ), $css );
	}

	/** A Crockford base32 id, 26 characters, like Core Framework's own. */
	private static function new_id(): string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$id       = '';
		for ( $i = 0; $i < 26; $i++ ) {
			$id .= $alphabet[ random_int( 0, 31 ) ];
		}
		return $id;
	}

	/**
	 * A colour as `hsla(h, s%, l%, a)`: oklch() (ACSS 4), hex, rgb(a) and hsl(a) are understood; null otherwise.
	 */
	public static function to_hsla( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		$rgb   = null;
		$alpha = 1.0;
		if ( 1 === preg_match( '/^oklch\(\s*([\d.]+%?)\s+([\d.]+)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+%?))?\s*\)$/', $value, $m ) ) {
			$l     = str_ends_with( $m[1], '%' ) ? (float) $m[1] / 100 : (float) $m[1];
			$rgb   = self::oklch_to_rgb( $l, (float) $m[2], (float) $m[3] );
			$alpha = isset( $m[4] ) ? ( str_ends_with( $m[4], '%' ) ? (float) $m[4] / 100 : (float) $m[4] ) : 1.0;
		} elseif ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m ) ) {
			$hex = 3 === strlen( $m[1] ) ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
			$rgb = array( hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
		} elseif ( 1 === preg_match( '/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s\/]+([\d.]+))?\s*\)$/', $value, $m ) ) {
			$rgb   = array( (float) $m[1] / 255, (float) $m[2] / 255, (float) $m[3] / 255 );
			$alpha = isset( $m[4] ) ? (float) $m[4] : 1.0;
		} elseif ( 1 === preg_match( '/^hsla?\(\s*([\d.]+)(?:deg)?[,\s]+([\d.]+)%[,\s]+([\d.]+)%(?:[,\s\/]+([\d.]+))?\s*\)$/', $value, $m ) ) {
			return sprintf( 'hsla(%d, %d%%, %d%%, %s)', (int) round( (float) $m[1] ), (int) round( (float) $m[2] ), (int) round( (float) $m[3] ), self::alpha( isset( $m[4] ) ? (float) $m[4] : 1.0 ) );
		}
		if ( null === $rgb ) {
			return null;
		}
		[ $h, $s, $l ] = self::rgb_to_hsl( $rgb );
		return sprintf( 'hsla(%d, %d%%, %d%%, %s)', (int) round( $h ), (int) round( $s * 100 ), (int) round( $l * 100 ), self::alpha( $alpha ) );
	}

	private static function alpha( float $a ): string {
		return rtrim( rtrim( number_format( max( 0, min( 1, $a ) ), 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * OKLCH → sRGB (0–1), clipped to the gamut.
	 *
	 * @return array{0: float, 1: float, 2: float}
	 */
	private static function oklch_to_rgb( float $l, float $c, float $h ): array {
		$a  = $c * cos( deg2rad( $h ) );
		$b  = $c * sin( deg2rad( $h ) );
		$l_ = ( $l + 0.3963377774 * $a + 0.2158037573 * $b ) ** 3;
		$m_ = ( $l - 0.1055613458 * $a - 0.0638541728 * $b ) ** 3;
		$s_ = ( $l - 0.0894841775 * $a - 1.2914855480 * $b ) ** 3;
		$lin = array(
			4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
			-1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
			-0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
		);
		$out = array();
		foreach ( $lin as $v ) {
			$v     = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ( max( 0, $v ) ** ( 1 / 2.4 ) ) - 0.055;
			$out[] = max( 0.0, min( 1.0, $v ) );
		}
		return array( $out[0], $out[1], $out[2] );
	}

	/**
	 * @param array{0: float, 1: float, 2: float}|array<int, float> $rgb
	 * @return array{0: float, 1: float, 2: float}
	 */
	private static function rgb_to_hsl( array $rgb ): array {
		[ $r, $g, $b ] = array( (float) $rgb[0], (float) $rgb[1], (float) $rgb[2] );
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return array( 0.0, 0.0, $l );
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		$h = match ( $max ) {
			$r => ( $g - $b ) / $d + ( $g < $b ? 6 : 0 ),
			$g => ( $b - $r ) / $d + 2,
			default => ( $r - $g ) / $d + 4,
		};
		return array( $h * 60, $s, $l );
	}
}
