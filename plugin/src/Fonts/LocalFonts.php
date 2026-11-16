<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fonts;

/**
 * Fonts served by the site itself — docs/spec/fonts.md. No request ever goes to a font provider when a page is
 * viewed (in Italy and the EU, loading Google Fonts from Google sends the visitor's IP abroad).
 *
 * Files come from an upload (WOFF2 or WOFF, checked by their signature) or are fetched once, server side, from
 * Google Fonts: only fonts.googleapis.com for the CSS and fonts.gstatic.com for the files. They live in
 * uploads/xb-fonts/ under names XB chooses; `@font-face` rules are printed in the page head, in the block editor
 * and in the builder canvas.
 *
 * @phpstan-type FontFace array{family: string, weight: string, style: string, file: string, format: string, range: string}
 */
final class LocalFonts {

	public const OPTION     = 'xb_local_fonts';
	public const MAX_FACES  = 120;
	public const MAX_BYTES  = 2 * 1024 * 1024;
	private const FAMILY    = '/^[A-Za-z0-9][A-Za-z0-9 \-]{0,59}$/';
	private const GOOGLE_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print_head' ), 2 );
	}

	public static function print_head(): void {
		foreach ( self::preloads() as $url ) {
			printf( "<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"font/woff2\" crossorigin>\n", esc_url( $url ) );
		}
		$css = self::css();
		if ( '' !== $css ) {
			echo "<style id=\"xb-fonts\">\n" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from validated values only.
		}
	}

	/**
	 * The files worth preloading (lista della spesa 146): the text font at weight 400 and the heading font at the
	 * heading weight, from the site's design, upright, woff2, latin when split by range. At most two: a preload the
	 * page does not use costs more than it saves. Nothing when the design names no local font.
	 *
	 * @return list<string>
	 */
	public static function preloads(): array {
		if ( ! class_exists( \XB\Framework\HistosCss\SiteDesign::class ) ) {
			return array();
		}
		$design = \XB\Framework\HistosCss\SiteDesign::current();
		$first  = static fn ( mixed $stack ): string => trim( (string) strtok( is_string( $stack ) ? $stack : '', ',' ), " \"'" );
		$wanted = array(
			array( $first( $design['font_body'] ?? '' ), '400' ),
			array( $first( $design['font_heading'] ?? '' ), (string) ( $design['heading_weight'] ?? '700' ) ),
		);
		$out = array();
		foreach ( $wanted as [ $family, $weight ] ) {
			if ( '' === $family ) {
				continue;
			}
			$best = null;
			foreach ( self::faces() as $face ) {
				if ( 0 !== strcasecmp( $face['family'], $family ) || 'normal' !== $face['style'] || 'woff2' !== $face['format'] ) {
					continue;
				}
				// A variable face ("100 900") covers the weight; a static one must be it.
				$range = explode( ' ', $face['weight'] );
				$fits  = 2 === count( $range ) ? ( (int) $range[0] <= (int) $weight && (int) $weight <= (int) $range[1] ) : $face['weight'] === $weight;
				if ( ! $fits ) {
					continue;
				}
				// Split by range (Google's subsets): the latin subset, the one every Italian or English page needs.
				if ( null === $best || ( '' !== $face['range'] && str_contains( $face['range'], 'U+0000-00FF' ) ) ) {
					$best = $face;
				}
			}
			if ( null !== $best ) {
				$out[] = self::url( $best['file'] );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 2 );
	}

	/** @return list<FontFace> */
	public static function faces(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $face ) {
			if ( is_array( $face ) && null !== ( $clean = self::clean_face( $face ) ) ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * A stored face, checked again before it is printed.
	 *
	 * @param array<mixed> $face
	 * @return FontFace|null
	 */
	private static function clean_face( array $face ): ?array {
		$family = (string) ( $face['family'] ?? '' );
		$weight = (string) ( $face['weight'] ?? '400' );
		$style  = (string) ( $face['style'] ?? 'normal' );
		$file   = (string) ( $face['file'] ?? '' );
		$format = (string) ( $face['format'] ?? 'woff2' );
		$range  = (string) ( $face['range'] ?? '' );
		if ( 1 !== preg_match( self::FAMILY, $family )
			|| 1 !== preg_match( '/^[1-9]00( [1-9]00)?$/', $weight )
			|| ! in_array( $style, array( 'normal', 'italic' ), true )
			|| 1 !== preg_match( '/^[a-z0-9-]{1,60}\/[a-z0-9-]{1,120}\.(woff2|woff)$/', $file )
			|| ! in_array( $format, array( 'woff2', 'woff' ), true )
			|| ( '' !== $range && 1 !== preg_match( '/^U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(, ?U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/', $range ) ) ) {
			return null;
		}
		return compact( 'family', 'weight', 'style', 'file', 'format', 'range' );
	}

	public static function dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'xb-fonts';
	}

	private static function url( string $file ): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'xb-fonts/' . $file;
	}

	/** `@font-face` rules for every face, `font-display: swap`. */
	public static function css(): string {
		$css = '';
		foreach ( self::faces() as $face ) {
			$css .= sprintf(
				"@font-face{font-family:\"%s\";src:url(\"%s\") format(\"%s\");font-weight:%s;font-style:%s;font-display:swap;%s}\n",
				$face['family'],
				esc_url_raw( self::url( $face['file'] ) ),
				$face['format'],
				$face['weight'],
				$face['style'],
				'' === $face['range'] ? '' : 'unicode-range:' . $face['range'] . ';'
			);
		}
		return $css;
	}

	/** @return list<string> Family names, for the builder's completions and the settings screen. */
	public static function families(): array {
		return array_values( array_unique( array_column( self::faces(), 'family' ) ) );
	}

	/**
	 * Stores one font file after checking what it is (its signature, not its name or the declared type).
	 *
	 * @throws \InvalidArgumentException With a message for the administrator.
	 */
	public static function add( string $family, string $weight, string $style, string $bytes, string $range = '' ): void {
		$family = trim( preg_replace( '/\s+/', ' ', $family ) ?? '' );
		if ( 1 !== preg_match( self::FAMILY, $family ) ) {
			throw new \InvalidArgumentException( __( 'Nome della famiglia non valido: lettere, cifre, spazi e trattini.', 'xb' ) );
		}
		if ( 1 !== preg_match( '/^[1-9]00$/', $weight ) || ! in_array( $style, array( 'normal', 'italic' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Peso o stile non valido.', 'xb' ) );
		}
		$format = self::format( $bytes );
		if ( null === $format ) {
			throw new \InvalidArgumentException( __( 'Il file non è un font WOFF2 o WOFF.', 'xb' ) );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( __( 'Font troppo grande (al massimo 2 MB).', 'xb' ) );
		}
		$faces = self::faces();
		if ( count( $faces ) >= self::MAX_FACES ) {
			throw new \InvalidArgumentException( __( 'Troppi font: rimuovine qualcuno.', 'xb' ) );
		}
		$slug = sanitize_title( $family );
		$name = $slug . '-' . $weight . ( 'italic' === $style ? '-italic' : '' ) . '-' . substr( hash( 'sha256', $bytes ), 0, 12 ) . '.' . $format;
		$dir  = self::dir() . '/' . $slug;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \InvalidArgumentException( __( 'Impossibile creare la cartella dei font.', 'xb' ) );
		}
		if ( ! is_file( self::dir() . '/index.html' ) ) {
			file_put_contents( self::dir() . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$existed = is_file( $dir . '/' . $name );
		file_put_contents( $dir . '/' . $name, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$face = self::clean_face(
			array(
				'family' => $family,
				'weight' => $weight,
				'style'  => $style,
				'file'   => $slug . '/' . $name,
				'format' => $format,
				'range'  => $range,
			)
		);
		if ( null === $face ) {
			// No file left behind that no face names (one already there may be another face's: the same bytes).
			if ( ! $existed ) {
				wp_delete_file( $dir . '/' . $name );
			}
			throw new \InvalidArgumentException( __( 'Font non valido.', 'xb' ) );
		}
		$faces = array_values( array_filter( $faces, static fn ( array $f ): bool => ! ( $f['family'] === $family && $f['weight'] === $weight && $f['style'] === $style && $f['range'] === $face['range'] ) ) );
		$faces[] = $face;
		update_option( self::OPTION, $faces, true );
	}

	/** Removes a family and its files. */
	public static function remove( string $family ): void {
		$keep = array();
		foreach ( self::faces() as $face ) {
			if ( $face['family'] === $family ) {
				wp_delete_file( self::dir() . '/' . $face['file'] );
			} else {
				$keep[] = $face;
			}
		}
		update_option( self::OPTION, $keep, true );
	}

	/** WOFF2 or WOFF by signature. */
	public static function format( string $bytes ): ?string {
		return match ( substr( $bytes, 0, 4 ) ) {
			'wOF2' => 'woff2',
			'wOFF' => 'woff',
			default => null,
		};
	}

	/**
	 * Fetches a family from Google Fonts, once, on the server: the CSS from fonts.googleapis.com, every file from
	 * fonts.gstatic.com, nothing else. Returns how many faces were stored.
	 *
	 * @param list<int> $weights
	 * @throws \InvalidArgumentException With a message for the administrator.
	 */
	public static function import_google( string $family, array $weights, bool $italic ): int {
		$family = trim( preg_replace( '/\s+/', ' ', $family ) ?? '' );
		if ( 1 !== preg_match( self::FAMILY, $family ) ) {
			throw new \InvalidArgumentException( __( 'Nome della famiglia non valido.', 'xb' ) );
		}
		$weights = array_values( array_unique( array_filter( $weights, static fn ( int $w ): bool => $w >= 100 && $w <= 900 && 0 === $w % 100 ) ) );
		sort( $weights );
		if ( array() === $weights ) {
			$weights = array( 400 );
		}
		$axes = array();
		foreach ( $italic ? array( 0, 1 ) : array( 0 ) as $ital ) {
			foreach ( $weights as $w ) {
				$axes[] = $ital . ',' . $w;
			}
		}
		// Google refuses the whole request when one weight or the italic does not exist for the family: then each
		// one is asked alone, and what exists is kept.
		$css = self::google_css( $family, $axes );
		if ( null === $css && count( $axes ) > 1 ) {
			$css = implode( "\n", array_filter( array_map( static fn ( string $axis ): ?string => self::google_css( $family, array( $axis ) ), $axes ) ) );
		}
		if ( null === $css || '' === $css ) {
			throw new \InvalidArgumentException( __( 'Google Fonts non risponde o la famiglia non esiste (controlla il nome, per esempio "Inter").', 'xb' ) );
		}
		$count = 0;
		foreach ( self::parse_google_css( $css ) as $face ) {
			$file = wp_safe_remote_get(
				$face['src'],
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES + 1,
				)
			);
			if ( is_wp_error( $file ) || 200 !== wp_remote_retrieve_response_code( $file ) ) {
				continue;
			}
			self::add( $family, $face['weight'], $face['style'], (string) wp_remote_retrieve_body( $file ), $face['range'] );
			++$count;
			if ( $count >= 60 ) {
				break;
			}
		}
		if ( 0 === $count ) {
			throw new \InvalidArgumentException( __( 'Nessun file di font scaricato.', 'xb' ) );
		}
		return $count;
	}

	/**
	 * The stylesheet Google Fonts gives for these axes ("0,400", "1,700"…), or null when it refuses or does not answer.
	 *
	 * @param list<string> $axes
	 */
	private static function google_css( string $family, array $axes ): ?string {
		$url      = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':ital,wght@' . implode( ';', $axes ) . '&display=swap';
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 512 * 1024,
				'user-agent'          => self::GOOGLE_UA,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Google Fonts families to choose from in the builder (E2): a list bundled with the plugin
	 * (assets/fonts/google-fonts.json), so the browser never asks Google anything. Weights and italics are what the
	 * family is known to have; the import keeps whatever Google actually serves.
	 *
	 * @return list<array{family: string, category: string, weights: list<int>, italic: bool}>
	 */
	public static function catalog(): array {
		$raw  = file_get_contents( dirname( XB_FILE ) . '/assets/fonts/google-fonts.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of the plugin.
		$list = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['family'] ?? null ) || 1 !== preg_match( self::FAMILY, $entry['family'] ) ) {
				continue;
			}
			$weights = array_values( array_filter( is_array( $entry['weights'] ?? null ) ? $entry['weights'] : array(), static fn ( mixed $w ): bool => is_int( $w ) && $w >= 100 && $w <= 900 && 0 === $w % 100 ) );
			$out[]   = array(
				'family'   => $entry['family'],
				'category' => is_string( $entry['category'] ?? null ) ? $entry['category'] : 'sans-serif',
				'weights'  => array() === $weights ? array( 400 ) : $weights,
				'italic'   => true === ( $entry['italic'] ?? false ),
			);
		}
		return $out;
	}

	/**
	 * The site's families with their faces, for the builder.
	 *
	 * @return list<array{family: string, faces: list<array{weight: string, style: string}>}>
	 */
	public static function summary(): array {
		$by = array();
		foreach ( self::faces() as $face ) {
			$by[ $face['family'] ][ $face['weight'] . ' ' . $face['style'] ] = array(
				'weight' => $face['weight'],
				'style'  => $face['style'],
			);
		}
		$out = array();
		foreach ( $by as $family => $faces ) {
			ksort( $faces );
			$out[] = array(
				'family' => (string) $family,
				'faces'  => array_values( $faces ),
			);
		}
		return $out;
	}

	/**
	 * The faces of a Google Fonts stylesheet; sources outside fonts.gstatic.com are ignored.
	 *
	 * @return list<array{src: string, weight: string, style: string, range: string}>
	 */
	public static function parse_google_css( string $css ): array {
		$out = array();
		if ( false === preg_match_all( '/@font-face\s*\{([^}]*)\}/', $css, $blocks ) ) {
			return $out;
		}
		foreach ( $blocks[1] as $body ) {
			$src    = preg_match( '/src:\s*url\((https:\/\/fonts\.gstatic\.com\/[A-Za-z0-9._\/-]+\.woff2)\)/', $body, $m ) ? $m[1] : '';
			$weight = preg_match( '/font-weight:\s*([1-9]00)\s*;/', $body, $w ) ? $w[1] : '';
			$style  = preg_match( '/font-style:\s*(normal|italic)\s*;/', $body, $s ) ? $s[1] : '';
			$range  = preg_match( '/unicode-range:\s*([^;]+);/', $body, $r ) ? trim( $r[1] ) : '';
			if ( '' !== $src && '' !== $weight && '' !== $style ) {
				$out[] = array(
					'src'    => $src,
					'weight' => $weight,
					'style'  => $style,
					'range'  => $range,
				);
			}
		}
		return $out;
	}
}
