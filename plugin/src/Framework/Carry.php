<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

use XB\Plugin;
use XB\Styles\CssRules;
use XB\Styles\Presence;
use XB\Versioning\Documents;

/**
 * Carrying the previous CSS framework over when the site switches — docs/spec/framework.md. Renaming names
 * (Migration) is not enough: a site written with Automatic.css uses its variables and its classes everywhere, and
 * the new framework has neither the same values nor the same rules. Carry takes from the framework the site is
 * leaving:
 *   - every custom property it defines at the root, with its value;
 *   - the rules of the classes and elements the site really renders (its public pages, XB content and styles).
 * Into Histeon Tokens (or any framework but Core Framework from ACSS, which has its own project import,
 * AcssToCore) the variables go into a Histeon global sheet, in dev, published with the release that switches
 * framework; the --xb-* tokens take the old framework's values. The rules go into the compatibility sheet,
 * printed after the site's styles, where the old framework's stylesheet used to be — and only while another
 * framework is active (going back, the real one has them).
 */
final class Carry {

	public const COMPAT        = 'xb_framework_compat';
	public const SHEET_PREFIX  = 'carry-';
	private const MAX_PAGES    = 60;

	/**
	 * Local files of a provider's stylesheets (its URLs, under uploads or this plugin).
	 *
	 * @return list<string>
	 */
	public static function files( Provider $provider ): array {
		$uploads = wp_upload_dir();
		$roots   = array(
			(string) $uploads['baseurl'] => (string) $uploads['basedir'],
			plugins_url( '', XB_FILE )  => dirname( XB_FILE ),
		);
		$out = array();
		foreach ( $provider->stylesheets() as $url ) {
			$url = (string) strtok( $url, '?' );
			foreach ( $roots as $base => $dir ) {
				if ( str_starts_with( $url, $base . '/' ) ) {
					$path = $dir . substr( $url, strlen( $base ) );
					$real = realpath( $path );
					if ( false !== $real && str_starts_with( $real, (string) realpath( $dir ) ) && is_readable( $real ) && str_ends_with( $real, '.css' ) ) {
						$out[] = $real;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every custom property the framework defines at the root, with its value (the last one wins, as in CSS).
	 *
	 * @return array<string, string> `--name` => value.
	 */
	public static function variables( Provider $provider ): array {
		$out = array();
		foreach ( self::files( $provider ) as $file ) {
			$css = self::strip_comments( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( self::root_blocks( $css ) as $body ) {
				preg_match_all( '/(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+);?/', $body, $m, PREG_SET_ORDER );
				foreach ( $m as $decl ) {
					$value = trim( $decl[2] );
					if ( '' !== $value && ! str_contains( $value, '</' ) ) {
						$out[ $decl[1] ] = $value;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Bodies of the top-level `:root { … }` rules (not those inside `@media` or `@supports`, which hold values for
	 * some widths or browsers only).
	 *
	 * @return list<string>
	 */
	private static function root_blocks( string $css ): array {
		$out = array();
		preg_match_all( '/(?<![\w-]):root\s*\{/', $css, $m, PREG_OFFSET_CAPTURE );
		foreach ( $m[0] as [ $match, $at ] ) {
			$before = substr( $css, 0, (int) $at );
			if ( substr_count( $before, '{' ) !== substr_count( $before, '}' ) ) {
				continue; // Nested in an at-rule.
			}
			$start = (int) $at + strlen( $match );
			$end   = strpos( $css, '}', $start );
			if ( false !== $end ) {
				$out[] = substr( $css, $start, $end - $start );
			}
		}
		return $out;
	}

	/**
	 * The framework's rules the site needs: element rules, at-rules and the rules of every class the site
	 * renders — on its public pages as visitors get them, and in XB's content and styles.
	 */
	public static function rules( Provider $provider ): string {
		$files = self::files( $provider );
		if ( array() === $files ) {
			return '';
		}
		$page     = Presence::from_html( '<div class="' . esc_attr( implode( ' ', array_merge( self::site_classes(), array( 'skip-link', 'screen-reader-text' ) ) ) ) . '"></div>' );
		$exporter = new \XB\StaticSite\Exporter();
		foreach ( array_slice( $exporter->urls(), 0, self::MAX_PAGES ) as $path ) {
			$html = $exporter->fetch( $path, 200 );
			if ( null !== $html ) {
				$page->add_html( $html );
			}
		}
		$css = '';
		foreach ( $files as $file ) {
			$css .= self::strip_comments( (string) file_get_contents( $file ) ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return CssRules::select( CssRules::parse( $css ), $page );
	}

	/**
	 * What carrying would do, without doing it.
	 *
	 * @return array{from: string, to: string, route: string, variables: int, tokens: int}
	 */
	public static function plan( Provider $from, Provider $to ): array {
		return array(
			'from'      => $from->id(),
			'to'        => $to->id(),
			'route'     => self::route( $from, $to ),
			'variables' => count( self::variables( $from ) ),
			'tokens'    => 'xb-tokens' === $to->id() ? count( self::tokens( $from ) ) : 0,
		);
	}

	/** Core Framework from Automatic.css: a Core Framework project (AcssToCore). Anything else: a Histeon sheet. */
	public static function route( Provider $from, Provider $to ): string {
		return 'acss' === $from->id() && 'core-framework' === $to->id() && AcssToCore::available() ? 'core-framework' : 'sheet';
	}

	/**
	 * Carries the framework over. The variables sheet is a dev commit of the styles document, published with the
	 * framework switch; the compatibility sheet is written now and stays off until another framework is active.
	 *
	 * @return array{from: string, to: string, route: string, variables: int, tokens: int}
	 */
	public static function carry( Provider $from, Provider $to ): array {
		$plan = self::plan( $from, $to );
		if ( 'core-framework' === $plan['route'] ) {
			AcssToCore::import();
			return $plan;
		}
		$vars = self::variables( $from );
		$css  = '';
		if ( array() !== $vars ) {
			$css .= sprintf( "/* %s: le variabili del framework precedente, portate da Histeon. */\n:root {\n", $from->label() );
			foreach ( $vars as $name => $value ) {
				$css .= "  $name: $value;\n";
			}
			$css .= "}\n";
		}
		if ( 'xb-tokens' === $to->id() ) {
			$tokens = self::tokens( $from );
			if ( array() !== $tokens ) {
				$css .= sprintf( "/* I token Histeon con i valori di %s. */\n:root {\n", $from->label() );
				foreach ( $tokens as $token => $variable ) {
					$css .= "  --$token: var(--$variable);\n";
				}
				$css .= "}\n";
			}
		}
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::STYLES );
		$payload    = $copy['payload'];
		$id         = self::SHEET_PREFIX . $from->id();
		$globals    = array_values( array_filter( is_array( $payload['globals'] ?? null ) ? $payload['globals'] : array(), static fn ( mixed $g ): bool => ! is_array( $g ) || ( $g['id'] ?? '' ) !== $id ) );
		if ( '' !== $css ) {
			// First: the old framework's values sit under the site's own sheets, as its stylesheet did.
			array_unshift(
				$globals,
				array(
					'id'  => $id,
					'css' => self::safe_css( $css ),
				)
			);
		}
		$payload['globals'] = $globals;
		$versioning->save_dev( Documents::STYLES, $payload, (string) $copy['head'], (string) $copy['base'], sprintf( /* translators: %s: CSS framework name */ __( 'Framework precedente: %s', 'xb' ), $from->label() ), true );
		self::write_compat( $from->id(), self::rules( $from ) );
		return $plan;
	}

	/**
	 * Histeon tokens the framework can back: token => its variable.
	 *
	 * @return array<string, string>
	 */
	private static function tokens( Provider $from ): array {
		$vars = self::variables( $from );
		$out  = array();
		// From Automatic.css into Histeon CSS: every token with a counterpart (shades, scales, sections…), not only the aliases.
		foreach ( 'acss' === $from->id() ? HistosCss\AcssImport::tokens() : $from->aliases() as $token => $variable ) {
			if ( isset( $vars[ '--' . $variable ] ) ) {
				$out[ $token ] = $variable;
			}
		}
		return $out;
	}

	/** The compatibility sheet, written under uploads with a name that changes with its content. */
	public static function write_compat( string $source, string $css ): void {
		$old = self::compat();
		if ( '' === trim( $css ) ) {
			delete_option( self::COMPAT );
			delete_option( AcssToCore::COMPAT );
			return;
		}
		$dir = wp_upload_dir()['basedir'] . '/xb-css';
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( __( 'Impossibile creare la cartella del foglio di compatibilità.', 'xb' ) );
		}
		$name = 'compat-' . sanitize_key( $source ) . '-' . substr( hash( 'sha256', $css ), 0, 12 ) . '.css';
		file_put_contents( $dir . '/' . $name, '/* ' . sanitize_key( $source ) . ": regole usate dal sito, portate da Histeon */\n" . self::safe_css( $css ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( null !== $old && $old['file'] !== $name ) {
			wp_delete_file( $dir . '/' . $old['file'] );
		}
		update_option(
			self::COMPAT,
			array(
				'source' => sanitize_key( $source ),
				'file'   => $name,
			),
			true
		);
		delete_option( AcssToCore::COMPAT );
	}

	/**
	 * The compatibility sheet in use: this option, or the one the first ACSS → Core Framework import wrote.
	 *
	 * @return array{source: string, file: string}|null
	 */
	public static function compat(): ?array {
		$opt = get_option( self::COMPAT );
		if ( is_array( $opt ) && is_string( $opt['source'] ?? null ) && is_string( $opt['file'] ?? null ) ) {
			$entry = array(
				'source' => $opt['source'],
				'file'   => $opt['file'],
			);
		} else {
			$legacy = get_option( AcssToCore::COMPAT );
			$entry  = is_string( $legacy ) ? array(
				'source' => 'acss',
				'file'   => $legacy,
			) : null;
		}
		if ( null === $entry || 1 !== preg_match( '/^(acss-)?compat(-[a-z0-9_-]+)?-[0-9a-f]{12}\.css$|^acss-compat-[0-9a-f]{12}\.css$/', $entry['file'] ) ) {
			return null;
		}
		return $entry;
	}

	/** URL of the compatibility sheet, or null (none, or the framework it comes from is active again). */
	public static function compat_url(): ?string {
		$entry = self::compat();
		if ( null === $entry || $entry['source'] === Plugin::instance()->frameworks->active()->id() || ! is_readable( wp_upload_dir()['basedir'] . '/xb-css/' . $entry['file'] ) ) {
			return null;
		}
		return wp_upload_dir()['baseurl'] . '/xb-css/' . $entry['file'];
	}

	public static function register(): void {
		// After Histeon's own styles (PageStyles prints at wp_head 99), where the old framework's stylesheet loaded.
		add_action(
			'wp_head',
			static function (): void {
				$url = self::compat_url();
				if ( null !== $url ) {
					printf( "<link rel=\"stylesheet\" id=\"xb-acss-compat-css\" href=\"%s\">\n", esc_url( $url ) );
				}
			},
			100
		);
		add_filter(
			'xb_canvas_stylesheets',
			static function ( mixed $urls ): array {
				$url = self::compat_url();
				return null === $url ? (array) $urls : array_merge( (array) $urls, array( $url ) );
			}
		);
		add_action(
			'enqueue_block_assets',
			static function (): void {
				$url = self::compat_url();
				if ( null !== $url && is_admin() ) {
					wp_enqueue_style( 'xb-acss-compat', $url, array( 'xb-styles-editor' ), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the name changes with the content.
				}
			},
			20
		);
	}

	/** @return list<string> Classes written in Histeon content (posts, templates, components) and in the style entries. */
	public static function site_classes(): array {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a read of every Histeon document's markup.
		$contents = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE '%wp:xb/%' LIMIT 5000" );
		foreach ( $contents as $content ) {
			preg_match_all( '/"class":"([^"]*)"/', (string) $content, $m );
			foreach ( $m[1] as $value ) {
				foreach ( preg_split( '/\s+/', stripcslashes( $value ) ) ?: array() as $class ) {
					if ( 1 === preg_match( '/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/', $class ) ) {
						$out[ $class ] = true;
					}
				}
			}
		}
		$registry = new \XB\Styles\StyleRegistry();
		foreach ( array_merge( $registry->entries(), $registry->globals() ) as $entry ) {
			preg_match_all( '/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', (string) $entry['css'], $m );
			foreach ( $m[1] as $class ) {
				$out[ $class ] = true;
			}
		}
		return array_keys( $out );
	}

	public static function strip_comments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/** Nothing that could close a `<style>`, load a script or run an expression. */
	public static function safe_css( string $css ): string {
		// The removals first, until nothing is left to remove (they could rebuild one another: «<<script/style>»,
		// «expreexpression(ssion(»), then «</», so that no removal can make one again.
		do {
			$before = $css;
			$css    = str_ireplace( array( '<script', 'expression(', 'javascript:' ), '', $css );
		} while ( $css !== $before );
		return str_ireplace( '</', '\3C /', $css );
	}
}
