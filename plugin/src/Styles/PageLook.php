<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * The page's own look (voce 186 of doc 23 of Publish; docs/spec/client-mode.md, «Aspetto della pagina»): what the
 * «Aspetto» panel writes when the change must stay on one page and never reach the site's classes.
 *
 * A page or post keeps a short list of entries `{selector, css}` in its document payload (`styles`, versioned with
 * the page: saved in development, online with a release) and, once published, in the protected meta
 * `_xb_page_styles`. The CSS is the mini GUI's own (docs/spec/styles.md §9-§10), read with a closed grammar:
 * - the selector is one class or one id (`.card__title`, `#hero`);
 * - the body holds declarations, a state block (`&:hover`…) and a named width (`@media (--tablet)`), a state
 *   inside a width, nothing else: no other selectors, at-rules, comments, strings or `!important`;
 * - each property is one of the mini GUI's controls (PROPS), each value one of its options or, for the
 *   framework's scales (spaces, text sizes, radii, shadows, colours), a `var(--name)`.
 * Printed, every rule sits under the page: `.xb-page-<id> .card__title { … }`, with the class on <body> of that
 * page only, so the same class elsewhere does not change. Builders write the same grammar: page styles are the
 * small, safe tool; the site's classes stay in the CSS panel.
 */
final class PageLook {

	/** The live entries, as JSON (protected: no custom-fields box writes it). */
	public const META = '_xb_page_styles';
	/** The key in a page's document payload. */
	public const KEY = 'styles';
	/** Post types with a page of their own on the site. */
	public const TYPES = array( 'page', 'post' );
	public const MAX_ENTRIES = 100;
	public const MAX_BYTES   = 20000;

	/** The mini GUI's states (editor/src/lib/cssContext.ts STATES). */
	public const STATES = array( '&:hover', '&:focus-visible', '&:active', '&::before', '&::after' );

	/** The mini GUI's groups (editor/src/lib/miniGui.ts GROUPS), the unit a client is granted. */
	public const GROUPS = array( 'layout', 'space', 'size', 'text', 'look', 'position' );

	/**
	 * Property => [group, values]: values is a list of the literal options, or the name of a framework scale
	 * (any `var(--name)`), optionally with literal extras. Mirrors GROUPS in editor/src/lib/miniGui.ts
	 * (editor/test/pageLook.test.ts keeps them equal).
	 */
	public const PROPS = array(
		'display'               => array( 'layout', array( 'block', 'flex', 'grid', 'inline-flex', 'none' ) ),
		'flex-direction'        => array( 'layout', array( 'row', 'column' ) ),
		'flex-wrap'             => array( 'layout', array( 'wrap', 'nowrap' ) ),
		'grid-template-columns' => array( 'layout', array( 'repeat(2, minmax(0, 1fr))', 'repeat(3, minmax(0, 1fr))', 'repeat(4, minmax(0, 1fr))', 'repeat(auto-fit, minmax(min(16rem, 100%), 1fr))' ) ),
		'justify-content'       => array( 'layout', array( 'start', 'center', 'end', 'space-between' ) ),
		'align-items'           => array( 'layout', array( 'start', 'center', 'end', 'stretch' ) ),
		'gap'                   => array( 'layout', 'space' ),
		'padding'               => array( 'space', 'space' ),
		'padding-block'         => array( 'space', 'space' ),
		'padding-inline'        => array( 'space', 'space' ),
		'margin-block'          => array( 'space', 'space' ),
		'margin-inline'         => array( 'space', array( 'auto', '0' ) ),
		'width'                 => array( 'size', array( '100%', 'auto', 'fit-content' ) ),
		'max-width'             => array( 'size', array( '100%', '60ch', '40rem', '75rem' ) ),
		'min-height'            => array( 'size', array( '50vh', '100vh', '100svh' ) ),
		'aspect-ratio'          => array( 'size', array( '1', '4 / 3', '16 / 9' ) ),
		'object-fit'            => array( 'size', array( 'cover', 'contain' ) ),
		'font-size'             => array( 'text', 'text' ),
		'font-weight'           => array( 'text', array( '400', '500', '600', '700' ) ),
		'line-height'           => array( 'text', array( '1', '1.2', '1.5', '1.7' ) ),
		'text-align'            => array( 'text', array( 'start', 'center', 'end' ) ),
		'text-wrap'             => array( 'text', array( 'balance', 'pretty' ) ),
		'color'                 => array( 'text', 'color' ),
		'background'            => array( 'look', 'color' ),
		'border-radius'         => array( 'look', 'radius', array( '0', '999px' ) ),
		'box-shadow'            => array( 'look', 'shadow', array( 'none' ) ),
		'border'                => array( 'look', array( 'none', '1px solid currentColor' ) ),
		'opacity'               => array( 'look', array( '0.5', '0.8', '1' ) ),
		'position'              => array( 'position', array( 'relative', 'absolute', 'sticky' ) ),
		'inset'                 => array( 'position', array( '0' ) ),
		'z-index'               => array( 'position', array( '1', '10', '100' ) ),
		'overflow'              => array( 'position', array( 'hidden', 'auto', 'clip' ) ),
		'container-type'        => array( 'position', array( 'inline-size' ) ),
	);

	public static function register(): void {
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_head', array( self::class, 'print' ), 100 );
	}

	/** The class that scopes a page's own styles. */
	public static function scope( int $post_id ): string {
		return 'xb-page-' . $post_id;
	}

	public static function supports( int $post_id ): bool {
		return $post_id > 0 && in_array( get_post_type( $post_id ), self::TYPES, true );
	}

	/**
	 * The live entries of a page (what is published), as stored: never trusted, checked again when printed.
	 *
	 * @return list<array{selector: string, css: string}>
	 */
	public static function stored( int $post_id ): array {
		$raw = json_decode( (string) get_post_meta( $post_id, self::META, true ), true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		try {
			return self::validate( $raw );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/**
	 * Writes the live entries (a release, Documents::apply), checked with the same grammar.
	 *
	 * @param mixed $entries
	 * @throws \InvalidArgumentException When they do not follow the grammar.
	 */
	public static function set( int $post_id, mixed $entries ): void {
		$clean = self::validate( $entries );
		if ( array() === $clean ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, wp_slash( (string) wp_json_encode( $clean ) ) );
	}

	/**
	 * The entries a request shows: the live ones, or the development version in a preview (Preview filter).
	 *
	 * @return list<array{selector: string, css: string}>
	 */
	public static function shown( int $post_id ): array {
		$entries = apply_filters( 'xb_document_styles', self::stored( $post_id ), $post_id );
		try {
			return self::validate( $entries );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/** The page being shown, when it is a single page or post: 0 elsewhere (archives, home of posts…). */
	public static function current(): int {
		if ( ! is_singular( self::TYPES ) ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}

	/**
	 * @param list<string> $classes
	 * @return list<string>
	 */
	public static function body_class( array $classes ): array {
		$id = self::current();
		if ( $id > 0 ) {
			$classes[] = self::scope( $id );
		}
		return $classes;
	}

	/** After the site's styles (wp_head 99), on that page only. */
	public static function print(): void {
		$id = self::current();
		if ( 0 === $id ) {
			return;
		}
		$css = self::css( self::shown( $id ), '.' . self::scope( $id ) );
		if ( '' !== $css ) {
			echo "<style id=\"xb-page-styles\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closed grammar (validate), compiled and sanitized by CssCompiler.
		}
	}

	/**
	 * The entries as CSS, every rule under `$scope` (`.xb-page-12` on the site, `:root` in the editors), named
	 * widths resolved.
	 *
	 * @param list<array{selector: string, css: string}> $entries Validated.
	 */
	public static function css( array $entries, string $scope ): string {
		$out = '';
		foreach ( $entries as $entry ) {
			$out .= CssCompiler::compile( $scope . ' ' . $entry['selector'], $entry['css'] );
		}
		return '' === $out ? '' : NamedQueries::resolve( $out );
	}

	/**
	 * The entries if they follow the grammar, normalized (`{selector, css}`, empty ones dropped).
	 *
	 * @return list<array{selector: string, css: string}>
	 * @throws \InvalidArgumentException With what is wrong, in words.
	 */
	public static function validate( mixed $entries ): array {
		if ( ! is_array( $entries ) || ! array_is_list( $entries ) ) {
			throw new \InvalidArgumentException( __( 'Stili della pagina: formato non valido.', 'xb' ) );
		}
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			throw new \InvalidArgumentException( __( 'Stili della pagina: troppe voci.', 'xb' ) );
		}
		$out   = array();
		$seen  = array();
		$bytes = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['selector'] ?? null ) || ! is_string( $entry['css'] ?? null ) || array() !== array_diff( array_keys( $entry ), array( 'selector', 'css' ) ) ) {
				throw new \InvalidArgumentException( __( 'Stili della pagina: formato non valido.', 'xb' ) );
			}
			$selector = $entry['selector'];
			if ( 1 !== preg_match( '/^[.#]-?[_a-zA-Z][_a-zA-Z0-9-]{0,99}$/', $selector ) ) {
				/* translators: %s: CSS selector */
				throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina: «%s» non è una classe o un id.', 'xb' ), mb_substr( $selector, 0, 100 ) ) );
			}
			if ( isset( $seen[ $selector ] ) ) {
				/* translators: %s: CSS selector */
				throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina: «%s» compare due volte.', 'xb' ), $selector ) );
			}
			$seen[ $selector ] = true;
			$bytes            += strlen( $entry['css'] );
			if ( $bytes > self::MAX_BYTES ) {
				throw new \InvalidArgumentException( __( 'Stili della pagina: troppo lunghi.', 'xb' ) );
			}
			$found = array();
			self::body( $entry['css'], array(), $selector, $found );
			if ( '' !== trim( $entry['css'] ) ) {
				$out[] = array(
					'selector' => $selector,
					'css'      => $entry['css'],
				);
			}
		}
		return $out;
	}

	/**
	 * Every declaration of valid entries, flat: `selector␟context␟property` => value (the context is the blocks
	 * it sits in, `@media (--tablet) &:hover`). Two versions compare by it.
	 *
	 * @param list<array{selector: string, css: string}> $entries
	 * @return array<string, string>
	 * @throws \InvalidArgumentException When they do not follow the grammar.
	 */
	public static function declarations( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			self::body( $entry['css'], array(), $entry['selector'], $out );
		}
		return $out;
	}

	/**
	 * The properties whose value differs between two versions (added, changed or removed), anywhere.
	 *
	 * @param list<array{selector: string, css: string}> $before
	 * @param list<array{selector: string, css: string}> $after
	 * @return list<string>
	 */
	public static function changed( array $before, array $after ): array {
		try {
			$old = self::declarations( $before );
		} catch ( \InvalidArgumentException ) {
			$old = array();
		}
		$new   = self::declarations( $after );
		$props = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$props[] = substr( $key, (int) strrpos( $key, "\x1F" ) + 1 );
			}
		}
		return array_values( array_unique( $props ) );
	}

	/** The group of a property, '' when it is not one of the mini GUI's. */
	public static function group( string $prop ): string {
		return self::PROPS[ $prop ][0] ?? '';
	}

	/**
	 * Reads one body of the grammar into `$out`.
	 *
	 * @param list<string>          $path The blocks around it, outermost first.
	 * @param array<string, string> $out
	 */
	private static function body( string $css, array $path, string $selector, array &$out ): void {
		if ( 1 === preg_match( '/[^a-zA-Z0-9\s\-_#%.,:;&()\/{}@]/', $css ) || str_contains( $css, '/*' ) ) {
			/* translators: %s: CSS selector */
			throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %s: caratteri non ammessi.', 'xb' ), $selector ) );
		}
		$length = strlen( $css );
		$at     = 0;
		while ( $at < $length ) {
			$span = strcspn( $css, ';{}', $at );
			$text = trim( substr( $css, $at, $span ) );
			$end  = $at + $span;
			$char = $end < $length ? $css[ $end ] : '';
			if ( '}' === $char ) {
				/* translators: %s: CSS selector */
				throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %s: graffe non bilanciate.', 'xb' ), $selector ) );
			}
			if ( '{' === $char ) {
				$depth = 1;
				$close = $end + 1;
				while ( $close < $length && $depth > 0 ) {
					$depth += '{' === $css[ $close ] ? 1 : ( '}' === $css[ $close ] ? -1 : 0 );
					++$close;
				}
				if ( $depth > 0 ) {
					/* translators: %s: CSS selector */
					throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %s: graffe non bilanciate.', 'xb' ), $selector ) );
				}
				$prelude = self::prelude( $text );
				if ( ! self::allowed( $prelude, $path ) ) {
					/* translators: 1: CSS selector, 2: what was written before a { */
					throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %1$s: «%2$s» non si può usare qui.', 'xb' ), $selector, mb_substr( $text, 0, 60 ) ) );
				}
				self::body( substr( $css, $end + 1, $close - $end - 2 ), array_merge( $path, array( $prelude ) ), $selector, $out );
				$at = $close;
				continue;
			}
			if ( '' !== $text ) {
				[ $prop, $value ] = self::declaration( $text, $selector );
				$out[ $selector . "\x1F" . implode( ' ', $path ) . "\x1F" . $prop ] = $value;
			}
			$at = $end + 1;
		}
	}

	private static function prelude( string $text ): string {
		$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
		return str_replace( array( '( ', ' )' ), array( '(', ')' ), $text );
	}

	/** @param list<string> $path */
	private static function allowed( string $prelude, array $path ): bool {
		$state = in_array( $prelude, self::STATES, true );
		$media = 1 === preg_match( '/^@media \(--[a-z][a-z0-9-]{0,30}\)$/', $prelude );
		if ( array() === $path ) {
			return $state || $media;
		}
		// A state inside a width; nothing inside a state.
		return 1 === count( $path ) && str_starts_with( $path[0], '@media' ) && $state;
	}

	/** @return array{0: string, 1: string} */
	private static function declaration( string $text, string $selector ): array {
		$colon = strpos( $text, ':' );
		$prop  = false === $colon ? '' : strtolower( trim( substr( $text, 0, $colon ) ) );
		$value = false === $colon ? '' : (string) preg_replace( '/\s+/', ' ', trim( substr( $text, $colon + 1 ) ) );
		if ( ! isset( self::PROPS[ $prop ] ) ) {
			/* translators: 1: CSS selector, 2: CSS property */
			throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %1$s: la proprietà «%2$s» non si può usare qui.', 'xb' ), $selector, mb_substr( '' === $prop ? $text : $prop, 0, 60 ) ) );
		}
		if ( ! self::value_allowed( $prop, $value ) ) {
			/* translators: 1: CSS selector, 2: CSS property, 3: value */
			throw new \InvalidArgumentException( sprintf( __( 'Stili della pagina di %1$s: «%3$s» non è un valore ammesso per %2$s.', 'xb' ), $selector, $prop, mb_substr( $value, 0, 80 ) ) );
		}
		return array( $prop, $value );
	}

	private static function value_allowed( string $prop, string $value ): bool {
		$rule = self::PROPS[ $prop ];
		if ( is_array( $rule[1] ) ) {
			return in_array( $value, $rule[1], true );
		}
		$extra = $rule[2] ?? array();
		return in_array( $value, $extra, true ) || 1 === preg_match( '/^var\(--[a-zA-Z0-9_-]{1,80}\)$/', $value );
	}
}
