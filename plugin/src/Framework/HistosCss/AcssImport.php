<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * From Automatic.css to Histeon CSS — docs/spec/histos-css.md §4, ADR 0014 (T6–T8). Reads only what Automatic.css generated
 * on the user's site (the custom properties of its stylesheets, through Carry) and maps names that mean the same
 * thing. The values stay the ones the site had: Histeon CSS tokens point to them (Carry's sheet keeps every Automatic.css
 * variable), so no page changes; the names used in the site become Histeon CSS's; the rest keeps working through the
 * compatibility sheet. Automatic.css names appear here only as the names of what is imported.
 */
final class AcssImport {

	/** Colour families: Automatic.css family => Histeon CSS family. */
	private const FAMILIES = array(
		'primary'   => 'primary',
		'secondary' => 'secondary',
		'tertiary'  => 'tertiary',
		'accent'    => 'accent',
		'neutral'   => 'neutral',
	);

	/** Shades: Automatic.css suffix => Histeon CSS step ('' = the colour itself). */
	private const SHADES = array(
		''            => '',
		'ultra-light' => '50',
		'light'       => '200',
		'semi-light'  => '300',
		'hover'       => '600',
		'semi-dark'   => '700',
		'dark'        => '800',
		'ultra-dark'  => '950',
	);

	/** Sizes: Automatic.css step => Histeon CSS step. */
	private const SIZES = array(
		'xs'  => 'xs',
		's'   => 's',
		'm'   => 'm',
		'l'   => 'l',
		'xl'  => 'xl',
		'xxl' => '2xl',
	);

	/**
	 * Histeon CSS token => the Automatic.css variable holding the same value (both without `--`).
	 *
	 * @return array<string, string>
	 */
	public static function tokens(): array {
		$out = array();
		foreach ( self::FAMILIES as $from => $to ) {
			foreach ( self::SHADES as $suffix => $step ) {
				// The hover colour has its own token (the panel's hover shade, 600 by default).
				$token                                                         = 'hover' === $suffix ? 'hover' : $step;
				$out[ 'xb-' . $to . ( '' === $token ? '' : '-' . $token ) ] = $from . ( '' === $suffix ? '' : '-' . $suffix );
			}
		}
		foreach ( self::SIZES as $from => $to ) {
			$out[ 'xb-text-' . $to ]  = 'text-' . $from;
			$out[ 'xb-space-' . $to ] = 'space-' . $from;
		}
		foreach ( array( 's', 'm', 'l', 'xl' ) as $size ) {
			$out[ 'xb-section-' . $size ] = 'section-space-' . $size;
			$out[ 'xb-radius-' . $size ]  = 'radius-' . $size;
		}
		for ( $n = 1; $n <= 6; $n++ ) {
			$out[ 'xb-h' . $n ] = 'h' . $n;
		}
		return $out + array(
			'xb-shadow-s'      => 'box-shadow-1',
			'xb-shadow-m'      => 'box-shadow-2',
			'xb-shadow-l'      => 'box-shadow-3',
			'xb-gutter'        => 'gutter',
			'xb-width-content' => 'content-width',
			'xb-bg'            => 'body-bg-color',
			'xb-surface'       => 'bg-ultra-light',
			'xb-surface-2'     => 'bg-light',
			'xb-text'          => 'text-color',
			'xb-text-muted'    => 'text-dark-muted',
			'xb-heading'       => 'heading-color',
			'xb-link'          => 'link-color',
			'xb-link-hover'    => 'link-color-hover',
			'xb-focus'         => 'focus-color',
			'xb-white'         => 'white',
			'xb-black'         => 'black',
		);
	}

	/**
	 * Automatic.css variable => Histeon CSS token, to rewrite `var(--…)` in the site's styles and content.
	 *
	 * @return array<string, string>
	 */
	public static function variables(): array {
		$out = array();
		foreach ( self::tokens() as $token => $variable ) {
			$out[ $variable ] ??= $token;
		}
		return $out;
	}

	/**
	 * Automatic.css class => Histeon CSS class, only where the Histeon CSS class sets the same property from the same value
	 * (a font size, a text colour, hiding for sighted users). Everything else keeps its name and its rule, served
	 * by the compatibility sheet: renaming it would change how it looks.
	 *
	 * @return array<string, string>
	 */
	public static function classes(): array {
		$out = array( 'hidden-accessible' => 'xb-visually-hidden' );
		foreach ( self::SIZES as $from => $to ) {
			$out[ 'text--' . $from ] = 'xb-text-' . $to;
		}
		foreach ( self::FAMILIES as $from => $to ) {
			foreach ( self::SHADES as $suffix => $step ) {
				$out[ 'text--' . $from . ( '' === $suffix ? '' : '-' . $suffix ) ] = 'xb-color-' . $to . ( '' === $step ? '' : '-' . $step );
			}
		}
		return $out;
	}

	/**
	 * The design parameters Automatic.css's values suggest: its base colours, so the shades Histeon CSS computes (and the
	 * families it does not map) belong to the same palette.
	 *
	 * @param array<string, string> $vars `--name` => value, as Carry::variables() reads them.
	 * @return array<string, string|int>
	 */
	public static function design( array $vars ): array {
		$out = array();
		foreach ( self::FAMILIES as $from => $to ) {
			$value = self::resolve( '--' . $from, $vars );
			$color = null === $value ? null : Color::parse( $value );
			if ( null !== $color ) {
				$out[ $to ] = $color->css();
				if ( array_key_exists( $to . '_on', Design::DEFAULTS ) ) {
					$out[ $to . '_on' ] = 1;
				}
			}
		}
		return $out;
	}

	/**
	 * A variable's value with its var() references followed (at most 10 steps); null if it ends nowhere.
	 *
	 * @param array<string, string> $vars `--name` => value.
	 */
	public static function resolve( string $name, array $vars, int $depth = 0 ): ?string {
		$value = $vars[ $name ] ?? null;
		if ( null === $value || $depth > 10 ) {
			return null;
		}
		if ( 1 === preg_match( '/^var\(\s*(--[a-zA-Z0-9_-]+)\s*\)$/', trim( $value ), $m ) ) {
			return self::resolve( $m[1], $vars, $depth + 1 );
		}
		return trim( $value );
	}
}
