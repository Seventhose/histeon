<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

use XB\Plugin;

/**
 * CSS on demand, like Etch: a page carries only the rules whose selectors can match it — docs/spec/styles.md §3.
 *
 * The front-end page is buffered; when it is complete its HTML is scanned (classes and ids) and:
 * - XB styles (global sheet and selector entries) replace a marker printed in the head;
 * - when the framework loading mode is "on demand", the framework's own <link> is replaced by an inline
 *   <style> with the rules the page needs (FrameworkCss).
 * Classes added later by JavaScript cannot be seen in the HTML: they go in the safelist (Settings → XB).
 */
final class PageStyles {

	public const MODE     = 'xb_framework_loading';
	public const SAFELIST = 'xb_css_safelist';
	/** The global sheets as a cached file instead of inline in each page (C5); off unless chosen. */
	public const GLOBAL_FILE = 'xb_css_global_file';

	public static function global_file(): bool {
		return (bool) get_option( self::GLOBAL_FILE, false );
	}

	/**
	 * Whether this page gets the global sheets as the cached file: chosen, and no static copy is being made. The
	 * exporter copies from uploads only published media, and Static Bridge's own capture is not known to copy it:
	 * the static pages keep the sheets inline, as before.
	 */
	private static function global_file_now(): bool {
		$use = self::global_file() && ! \XB\StaticSite\ExportMode::active() && ! has_filter( 'static_bridge_status' );
		return (bool) apply_filters( 'xb_css_global_file', $use );
	}

	private bool $buffering = false;
	private string $marker  = '';
	private string $buffer  = '';

	public static function boot(): void {
		$self = new self();
		add_action( 'template_redirect', array( $self, 'start' ), PHP_INT_MAX );
		add_action( 'wp_head', array( $self, 'head' ), 99 );
		add_action( 'wp_footer', array( $self, 'footer' ), 5 );
	}

	/** Framework stylesheet: whole file ('full') or only the rules the page uses ('on-demand', default). */
	public static function mode(): string {
		return 'full' === get_option( self::MODE ) ? 'full' : 'on-demand';
	}

	/** @return list<string> */
	public static function safelist(): array {
		$stored  = (string) get_option( self::SAFELIST, '' );
		$entries = preg_split( '/[\s,]+/', $stored ) ?: array();
		$entries = (array) apply_filters( 'xb_css_safelist', array_values( array_filter( $entries, static fn ( string $e ): bool => 1 === preg_match( '/^[.#]?-?[_a-zA-Z0-9\\\\:\/-]+\*?$/', $e ) ) ) );
		return array_values( array_filter( $entries, 'is_string' ) );
	}

	/**
	 * Adds or removes one class or id in the safelist, from the selector manager (lista migliorie C6): the same
	 * option and the same rules as Settings → Histeon (pattern, 100 characters, 500 entries), recorded as a live
	 * change of the settings like a save there.
	 *
	 * @return list<string> The safelist after the change.
	 */
	public static function set_safelisted( string $entry, bool $on ): array {
		if ( 1 !== preg_match( '/^[.#]-?[_a-zA-Z][_a-zA-Z0-9-]{0,99}$/', $entry ) ) {
			throw new \XB\Versioning\VersioningException( __( 'Solo una classe (.nome) o un id (#nome).', 'xb' ), 400 );
		}
		$stored  = preg_split( '/[\s,]+/', (string) get_option( self::SAFELIST, '' ) ) ?: array();
		$entries = array_values( array_filter( $stored, static fn ( string $e ): bool => '' !== $e && $e !== $entry ) );
		if ( $on ) {
			$entries[] = $entry;
		}
		$entries = array_slice( array_values( array_unique( $entries ) ), 0, 500 );
		update_option( self::SAFELIST, implode( ' ', $entries ), false );
		$versioning = \XB\Plugin::instance()->versioning;
		$versioning->record_live( \XB\Versioning\Documents::SETTINGS );
		$versioning->store()->audit( $on ? 'safelist_add' : 'safelist_remove', \XB\Versioning\Documents::SETTINGS, array( 'entry' => $entry ) );
		return $entries;
	}

	private static function wanted(): bool {
		$wanted = ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! wp_is_json_request()
			&& ! is_feed()
			&& ! is_robots()
			&& ! is_trackback()
			&& ! is_embed();
		return (bool) apply_filters( 'xb_css_on_demand', $wanted );
	}

	public function start(): void {
		if ( $this->buffering || ! self::wanted() ) {
			return;
		}
		$this->marker    = '<!--xb-styles-' . bin2hex( random_bytes( 8 ) ) . '-->';
		$this->buffering = ob_start( array( $this, 'handle' ) );
	}

	public function head(): void {
		if ( $this->buffering ) {
			echo $this->marker; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed comment with random hex.
		} else {
			Plugin::instance()->styles->print_pending( 'xb-styles' );
		}
	}

	public function footer(): void {
		if ( ! $this->buffering ) {
			Plugin::instance()->styles->print_pending( 'xb-styles-late' );
		}
	}

	/**
	 * Output handler. Partial flushes are held back (the head must be rewritten with the whole page known);
	 * a clean discards what was collected.
	 */
	public function handle( string $chunk, int $phase ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->buffer = '';
			$chunk        = '';
		}
		$this->buffer .= $chunk;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$html         = $this->buffer;
		$this->buffer = '';
		try {
			return $this->finish( $html );
		} catch ( \Throwable $e ) {
			// Never lose the page: on any failure, fall back to the full stylesheets.
			return $this->fallback( $html );
		}
	}

	public function finish( string $html ): string {
		return self::is_html_response() ? self::apply( $html, $this->marker ) : $html;
	}

	/** Replaces the marker with the page's XB styles and, on demand, the framework <link> with its used rules. */
	public static function apply( string $html, string $marker ): string {
		$at = strpos( $html, $marker );
		if ( false === $at ) {
			return $html;
		}
		$page = Presence::from_html( $html );
		$page->add_safelist( self::safelist() );
		// The global sheets first, as a cached file when chosen (C5): the cascade stays sheets, then selectors.
		$file = self::global_file_now() ? Plugin::instance()->styles->global_file_url() : null;
		$css  = Plugin::instance()->styles->css_for( $page, null === $file );
		$tag  = null === $file ? '' : '<link rel="stylesheet" id="xb-global-css" href="' . esc_url( $file ) . '">' . "\n";
		$tag .= '' === trim( $css ) ? '' : "<style id=\"xb-styles\">\n" . $css . "\n</style>";
		$html = substr_replace( $html, $tag, $at, strlen( $marker ) );
		if ( 'on-demand' === self::mode() ) {
			$html = FrameworkCss::inline( $html, Plugin::instance()->frameworks->active()->stylesheets(), $page );
		}
		return $html;
	}

	private function fallback( string $html ): string {
		$tag = "<style id=\"xb-styles\">\n" . Plugin::instance()->styles->all_css() . "\n</style>";
		return str_replace( $this->marker, $tag, $html );
	}

	private static function is_html_response(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}
		return true;
	}
}
