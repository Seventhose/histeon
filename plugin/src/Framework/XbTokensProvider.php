<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * Histeon CSS, Histeon's own framework (docs/spec/histos-css.md, ADR 0014): the --xb-* tokens (assets/tokens/xb-tokens.css,
 * computed from the design parameters) and its classes (assets/framework/histos.css). The id stays `xb-tokens`:
 * sites that chose Histeon Tokens have Histeon CSS now, with the same token names.
 */
final class XbTokensProvider implements Provider {

	public function id(): string {
		return 'xb-tokens';
	}

	public function label(): string {
		return HistosCss\Module::active() ? 'Histeon CSS' : __( 'Token Histeon', 'xb' );
	}

	public function available(): bool {
		return true;
	}

	public function note(): string {
		return '';
	}

	public function variables(): array {
		$css = (string) file_get_contents( self::file() );
		preg_match_all( '/(?<![\w-])--([a-z0-9-]+)\s*:/', $css, $m );
		return array( 'xb' => array_values( array_unique( $m[1] ) ) );
	}

	public function classes(): array {
		if ( ! HistosCss\Module::active() ) {
			return array();
		}
		$design = HistosCss\SiteDesign::current();
		$out    = array();
		foreach ( HistosCss\Utilities::classes() as $group => $names ) {
			$out[ $group ] = array_values( array_filter( $names, static fn ( string $name ): bool => ! HistosCss\Utilities::class_off( $name, $design ) ) );
		}
		return $out;
	}

	public function stylesheets(): array {
		return HistosCss\Module::active() ? array( self::url(), self::classes_url() ) : array( self::url() );
	}

	/**
	 * The sheets of the builder's canvas: from the design in development, so a group switched off and saved shows
	 * there before the release.
	 *
	 * @return list<string>
	 */
	public static function canvas_stylesheets(): array {
		return HistosCss\Module::active() ? array( self::url( true ), self::classes_url( true ) ) : array( self::url( true ) );
	}

	public function aliases(): array {
		return array();
	}

	/**
	 * The tokens sheet: the shipped defaults (assets/tokens/xb-tokens.css) or, on a site that switches groups of tokens
	 * off in the Histeon CSS panel (histos-publish doc 19 §5.3.12), the site's own sheet without them.
	 */
	public static function file(): string {
		return self::own_sheet()['file'] ?? self::shipped_file();
	}

	public static function url( bool $dev = false ): string {
		return self::own_sheet( $dev )['url'] ?? plugin_dir_url( XB_FILE ) . 'assets/tokens/xb-tokens.css?ver=' . (string) filemtime( self::shipped_file() );
	}

	/** The defaults as shipped, the same for every site. */
	public static function shipped_file(): string {
		return dirname( XB_FILE ) . '/assets/tokens/xb-tokens.css';
	}

	/**
	 * The site's own tokens sheet, when groups are switched off: written once to the uploads under a name that is the
	 * hash of its content (built from validated parameters only), so a browser never keeps an old one.
	 *
	 * @return array{file: string, url: string}|null
	 */
	private static function own_sheet( bool $dev = false ): ?array {
		if ( ! HistosCss\Module::active() ) {
			return null;
		}
		$design = HistosCss\SiteDesign::current( $dev );
		if ( ! HistosCss\Tokens::groups_off( $design ) ) {
			return null;
		}
		static $done = array();
		$key = (string) wp_json_encode( $design );
		if ( isset( $done[ $key ] ) ) {
			return $done[ $key ];
		}
		$css          = "/* Histeon CSS tokens of this site, without the groups switched off in the panel (GPL-3.0-or-later). */\n" . HistosCss\Tokens::sheet( $design ) . "\n";
		$done[ $key ] = self::write( 'xb-tokens-', $css );
		return $done[ $key ];
	}

	/**
	 * A sheet of this site, written once to the uploads under a name made of the hash of its content.
	 *
	 * @return array{file: string, url: string}|null
	 */
	private static function write( string $prefix, string $css ): ?array {
		$name = $prefix . substr( hash( 'sha256', $css ), 0, 20 ) . '.css';
		$up   = wp_upload_dir( null, false );
		$dir  = trailingslashit( (string) $up['basedir'] ) . 'xb-histos-css';
		$file = $dir . '/' . $name;
		if ( ! is_file( $file ) && ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, $css, LOCK_EX ) ) ) {
			return null;
		}
		return array(
			'file' => $file,
			'url'  => trailingslashit( (string) $up['baseurl'] ) . 'xb-histos-css/' . $name,
		);
	}

	/** Histeon CSS's classes: the shipped sheet, or the site's own without the groups of classes switched off. */
	public static function classes_file(): string {
		return self::own_classes()['file'] ?? dirname( XB_FILE ) . '/assets/framework/histos.css';
	}

	public static function classes_url( bool $dev = false ): string {
		return self::own_classes( $dev )['url'] ?? plugin_dir_url( XB_FILE ) . 'assets/framework/histos.css?ver=' . (string) filemtime( dirname( XB_FILE ) . '/assets/framework/histos.css' );
	}

	/** @return array{file: string, url: string}|null */
	private static function own_classes( bool $dev = false ): ?array {
		if ( ! HistosCss\Module::active() ) {
			return null;
		}
		$design  = HistosCss\SiteDesign::current( $dev );
		$shipped = (string) file_get_contents( dirname( XB_FILE ) . '/assets/framework/histos.css' );
		$css     = HistosCss\Utilities::filtered( $shipped, $design );
		return $css === $shipped ? null : self::write( 'histos-css-', $css );
	}
}
