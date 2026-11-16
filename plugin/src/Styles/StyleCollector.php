<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * XB styles on the front end: only the rules whose selectors can match something on the page — docs/spec/styles.md §3.
 *
 * Normal path (PageStyles): the whole page is buffered and its final HTML scanned, so classes and ids from XB
 * elements, `{#raw}` HTML, core blocks and theme parts all count; the CSS replaces a marker in the head.
 * Fallback (no buffer): the classes the renderer emitted are used, printed in the head and, for anything
 * rendered later, at the start of the footer.
 */
final class StyleCollector {

	/** File prefix of the cached site rules in uploads/xb-cache/. */
	public const CACHE = 'site-css';
	/** The same without the global sheets, when they are served as a file of their own (C5). */
	public const CACHE_ENTRIES = 'entry-css';
	/** File prefix of the global sheets served as a cached stylesheet (C5). */
	public const GLOBAL_FILE = 'global-css';

	private Presence $rendered;
	/** @var array<string, true> Hashes of rules already printed (fallback path). */
	private array $printed = array();
	/** @var array<mixed>|null */
	private ?array $rules = null;
	private string $rules_key = '';

	public function __construct( private readonly StyleRegistry $registry ) {
		$this->rendered = new Presence();
	}

	/**
	 * Runs a render whose classes must not count for the current page (SEO descriptions, sitemaps): what it
	 * records is discarded, so the page's on-demand CSS stays exactly its own.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function isolated( callable $fn ): mixed {
		$saved = clone $this->rendered;
		try {
			return $fn();
		} finally {
			$this->rendered = $saved;
		}
	}

	public function add_class_attribute( string $value ): void {
		$this->rendered->add_classes( $value );
	}

	public function uses( string $class ): bool {
		return $this->rendered->has( 'c:' . $class );
	}

	/**
	 * Global sheets, then the compiled entries, parsed into rules: each sheet and each entry on its own, so what one
	 * of them leaves open or closes once too many ends with it and never changes how the CSS of another is read, and
	 * only statements that CssRules reads closed (threat model T31, V2 of the fifth review). Parsing a large imported
	 * sheet costs tens of milliseconds, so the live styles are also cached in uploads/xb-cache/ between requests:
	 * the key is the stored styles as written (before their media are resolved), the named queries, the runtime CSS,
	 * the parser and plugin versions and the media generation, so a release, a new parser or a moved attachment
	 * makes a new key. Overridden styles (dev preview, builder canvas) are only kept for the request.
	 *
	 * @return array<mixed>
	 */
	public function rules( bool $with_globals = true ): array {
		$queries = NamedQueries::all();
		/** CSS of the site that XB does not store (Etch's styles while Etch is off: Import\Etch\Runtime), on demand too. */
		$extra = (string) apply_filters( 'xb_runtime_css', '' );
		$key   = md5(
			(string) wp_json_encode(
				array(
					$this->registry->globals(),
					$this->registry->entries(),
					$queries,
					md5( $extra ),
					CssRules::VERSION,
					XB_VERSION,
					StyleRegistry::media_generation(),
					wp_upload_dir()['baseurl'],
					$with_globals,
				)
			)
		);
		if ( null !== $this->rules && $key === $this->rules_key ) {
			return $this->rules;
		}
		// A preview (or the builder) may render development content even when the styles are not overridden: what it
		// compiles is kept for the request only, never in the cache that published pages read.
		$shared = ! StyleRegistry::overridden() && ! \XB\Versioning\Preview::active();
		$prefix = $with_globals ? self::CACHE : self::CACHE_ENTRIES;
		$cached = $shared ? RulesCache::get( $prefix, $key ) : null;
		if ( null === $cached ) {
			$sheets = array();
			foreach ( $with_globals ? $this->registry->globals( false, true ) : array() as $sheet ) {
				$sheets[] = CssCompiler::sanitize( $sheet['css'] );
			}
			foreach ( $this->registry->entries( false, true ) as $entry ) {
				$sheets[] = CssCompiler::compile( $entry['selector'], $entry['css'] );
			}
			$sheets[] = CssCompiler::sanitize( $extra );
			$cached   = array();
			foreach ( $sheets as $sheet ) {
				foreach ( CssRules::parse( NamedQueries::resolve( $sheet, $queries ) ) as $node ) {
					$cached[] = $node;
				}
			}
			if ( $shared ) {
				RulesCache::put( $prefix, $key, $cached );
			}
		}
		$this->rules     = $cached;
		$this->rules_key = $key;
		return $this->rules;
	}

	/** CSS for a page whose content is known (buffered path); without the global sheets when they are a file. */
	public function css_for( Presence $page, bool $with_globals = true ): string {
		return CssCompiler::sanitize( CssRules::select( $this->rules( $with_globals ), $page ) );
	}

	/**
	 * The global sheets as one cached stylesheet (C5): the browser keeps it from page to page, while each page's own
	 * CSS stays inline and on demand. Null (inline, as before) when there is nothing to serve, for a preview of
	 * unpublished styles, when the folder cannot be written, or when a sheet has a relative url(): from a file in
	 * uploads/xb-cache/ it would point somewhere else.
	 */
	public function global_file_url(): ?string {
		if ( StyleRegistry::overridden() || \XB\Versioning\Preview::active() ) {
			return null;
		}
		$css = '';
		foreach ( $this->registry->globals( false, true ) as $sheet ) {
			$css .= CssCompiler::sanitize( $sheet['css'] ) . "\n";
		}
		$css = CssCompiler::sanitize( NamedQueries::resolve( $css, NamedQueries::all() ) );
		// ASCII letters alone, whatever the PHP locale: the `i` flag, `\b`, `\w` and `\s` of PCRE follow it (T31, P1).
		if ( '' === trim( $css ) || 1 === preg_match( '/url\([ \t\n\x0B\f\r]*([\'"]?)(?![\'"]?(?:data:|https?:|\/))/', strtolower( $css ) ) ) {
			return null;
		}
		return RulesCache::css_file( self::GLOBAL_FILE, md5( $css ), $css );
	}

	/** Fallback path: CSS not printed yet for what the renderer has emitted so far. */
	public function pending_css(): string {
		$page = clone $this->rendered;
		$page->add_safelist( PageStyles::safelist() );
		$css = '';
		foreach ( $this->rules() as $node ) {
			$rule = CssRules::select( array( $node ), $page );
			$hash = md5( $rule );
			if ( '' !== $rule && ! isset( $this->printed[ $hash ] ) ) {
				$this->printed[ $hash ] = true;
				$css                   .= $rule . "\n";
			}
		}
		return CssCompiler::sanitize( $css );
	}

	/** Every stored style, regardless of use: for editor canvases, where the page is not rendered by XB. */
	public function all_css(): string {
		$css = '';
		foreach ( $this->registry->globals( false, true ) as $sheet ) {
			$css .= CssCompiler::sanitize( $sheet['css'] ) . "\n";
		}
		foreach ( $this->registry->entries( false, true ) as $entry ) {
			$css .= CssCompiler::compile( $entry['selector'], $entry['css'] ) . "\n";
		}
		// Queries are substituted after the per-sheet sanitize: the result is printed raw in <style> (editor canvas,
		// fallback page CSS), so it is sanitized again as a whole.
		return CssCompiler::sanitize( NamedQueries::resolve( $css ) );
	}

	public function print_pending( string $id ): void {
		$css = $this->pending_css();
		if ( '' !== trim( $css ) ) {
			printf( "<style id=\"%s\">\n%s</style>\n", esc_attr( $id ), $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS sanitized by CssCompiler.
		}
	}

	public function reset(): void {
		$this->rendered = new Presence();
		$this->printed  = array();
		$this->rules    = null;
	}
}
