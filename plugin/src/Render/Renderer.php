<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Expr\EvalError;
use XB\Expr\Evaluator;
use XB\Expr\Parser;
use XB\Expr\SyntaxError;
use XB\Expr\Template;
use XB\Expr\Value;
use XB\Scripts\Scripts;
use XB\Remote\PageProfileAdapter;
use XB\Remote\TransferMarkup;
use XB\Security\MarkupPolicy;
use XB\Spec;
use XB\Styles\StyleCollector;

/**
 * Renders an XB block subtree (arrays as produced by parse_blocks()) — docs/spec/blocks.md §3.
 *
 * WordPress calls render_block() only for the outermost XB block of a subtree (all XB blocks declare
 * skip_inner_blocks), so loop variables, props and slots flow through this class rather than through
 * block context. Every value coming from an expression is escaped for its output context here; static
 * markup has already passed MarkupPolicy when it was saved.
 */
final class Renderer {

	private const MAX_COMPONENT_DEPTH = 16;
	private const MAX_LOOP_ITEMS      = 1000;
	private const MAX_LOOP_DEPTH      = 6;
	/** Nodes rendered per request: nested loops cannot multiply into a denial of service. */
	private const MAX_NODES = 100000;

	private int $nodes      = 0;
	private int $loop_depth = 0;

	/** @var array<string, bool> Component refs managed by a Histeon CMS transfer profile, per request. */
	private array $transfer_managed = array();

	/** @var array<int, true> Posts whose content is being rendered (xb/post-content recursion guard). */
	private array $rendering_posts = array();

	public function __construct(
		private readonly Evaluator $evaluator,
		private readonly ComponentRegistry $components,
		private readonly LoopPresets $presets,
		private readonly StyleCollector $styles,
		private readonly bool $debug = false,
		private readonly ?Scripts $scripts = null,
	) {}

	/** Starts a new render budget (each PHP request starts with one; tests and long-running workers call this). */
	public function reset_budget(): void {
		$this->nodes            = 0;
		$this->loop_depth       = 0;
		$this->transfer_managed = array();
	}

	/** @param array<array<string, mixed>> $blocks */
	public function render_blocks( array $blocks, Context $context ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= $this->render_block( $block, $context );
		}
		return $html;
	}

	/** @param array<string, mixed> $block */
	public function render_block( array $block, Context $context ): string {
		if ( ++$this->nodes > self::MAX_NODES ) {
			return self::MAX_NODES + 1 === $this->nodes ? $this->problem( __( 'limite di elementi per pagina superato: rendering interrotto', 'xb' ) ) : '';
		}
		$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
		$name     = $block['blockName'] ?? null;

		switch ( $name ) {
			case 'xb/element':
				return $this->element( $attrs, $children, $context );
			case 'xb/text':
				// Verbatim text is printed raw only by its <script>/<style>/<textarea> parent (element()); anywhere else it is just text.
				return ( $attrs['verbatim'] ?? false ) === true
					? esc_html( (string) ( $attrs['content'] ?? '' ) )
					: $this->text( (string) ( $attrs['content'] ?? '' ), $context );
			case 'xb/if':
				return $this->condition( $children, $context );
			case 'xb/loop':
				return $this->loop( $attrs, $children, $context );
			case 'xb/component':
				return $this->component( $attrs, $children, $context );
			case 'xb/slot':
				$fill = $context->slots[ (string) ( $attrs['name'] ?? Spec::DEFAULT_SLOT ) ] ?? null;
				return null === $fill ? '' : $this->render_blocks( $fill['blocks'], $fill['context'] );
			case 'xb/raw':
				return (string) ( $attrs['content'] ?? '' );
			case 'xb/passthrough':
				return $this->passthrough( $children, $context );
			case 'xb/post-content':
				return $this->post_content( $context );
			case null:
				return (string) ( $block['innerHTML'] ?? '' );
			default:
				// xb/branch and xb/slot-fill only render through their parents; foreign blocks through WordPress.
				return is_string( $name ) && ! str_starts_with( $name, 'xb/' ) ? $this->foreign( $block, $context ) : '';
		}
	}

	// ------------------------------------------------------------------ blocks

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 */
	private function element( array $attrs, array $children, Context $context ): string {
		$tag = (string) ( $attrs['tag'] ?? '' );
		if ( 1 !== preg_match( MarkupPolicy::TAG_PATTERN, $tag ) || in_array( strtolower( $tag ), MarkupPolicy::FORBIDDEN_TAGS, true ) ) {
			return $this->problem( sprintf( /* translators: %s: HTML tag name */ __( 'tag non valido "%s"', 'xb' ), $tag ) );
		}
		$html = '<' . $tag;
		if ( is_array( $attrs['attributes'] ?? null ) && Behaviors::uses( $attrs['attributes'] ) ) {
			Behaviors::enqueue();
		}
		foreach ( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() as $name => $value ) {
			$html .= $this->attribute( (string) $name, $value, $context );
		}
		if ( 'img' === strtolower( $tag ) ) {
			$html .= $this->image_extras( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array(), $context );
		}
		if ( $context->annotate ) {
			$html .= ' data-xb-uid="' . esc_attr( $context->owner ?? (string) ( $attrs['uid'] ?? '' ) ) . '"';
		}
		$html .= '>';
		// HTML tag names are case-insensitive: <sCRIPT> is a script, and its text must not close it either.
		$lower = strtolower( $tag );
		if ( in_array( $lower, Spec::VOID_ELEMENTS, true ) ) {
			return $html;
		}
		if ( in_array( $lower, Spec::RAW_TEXT_ELEMENTS, true ) ) {
			foreach ( $children as $child ) {
				$child_attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
				if ( 'xb/text' !== ( $child['blockName'] ?? null ) || ( $child_attrs['verbatim'] ?? false ) !== true ) {
					continue; // Only verbatim text belongs inside raw-text elements.
				}
				$content = (string) ( $child_attrs['content'] ?? '' );
				// The content can never close its own element (trusted code included).
				// The name with every letter in its two cases: the `i` flag of PCRE follows the PHP locale (T31, P1).
				$name  = (string) preg_replace_callback( '/[a-z]/', static fn ( array $m ): string => '[' . $m[0] . strtoupper( $m[0] ) . ']', $lower );
				$html .= 'textarea' === $lower ? esc_textarea( $content ) : (string) preg_replace( '#</(' . $name . ')#', '<\\/$1', $content );
			}
			return $html . '</' . $tag . '>';
		}
		return $html . $this->render_blocks( $children, $context ) . '</' . $tag . '>';
	}

	private function attribute( string $name, mixed $value, Context $context ): string {
		if ( 1 !== preg_match( MarkupPolicy::ATTRIBUTE_PATTERN, $name ) || str_starts_with( $name, 'xb:' ) ) {
			return '';
		}
		if ( true === $value ) {
			return ' ' . $name;
		}
		if ( ! is_string( $value ) ) {
			return '';
		}
		$dynamic = str_contains( $value, '{' );
		if ( $dynamic && Template::is_single( $value ) ) {
			// `disabled={cond}`: false/null drops the attribute, true makes it boolean.
			$result = $this->evaluate( substr( $value, 1, -1 ), $context );
			if ( null === $result || false === $result ) {
				return '';
			}
			if ( true === $result ) {
				return ' ' . $name;
			}
			$text = Value::to_string( $result );
		} else {
			$text = $dynamic ? $this->interpolate( $value, $context ) : $value;
		}
		if ( 'class' === strtolower( $name ) ) {
			$this->styles->add_class_attribute( $text );
		}
		// Static values were validated at save time; dynamic ones are checked here.
		$lower = strtolower( $name );
		if ( $dynamic && in_array( $lower, array( 'srcset', 'imagesrcset' ), true ) ) {
			$text = implode( ', ', array_filter( array_map( array( self::class, 'srcset_candidate' ), explode( ',', $text ) ) ) );
		} elseif ( $dynamic && 'ping' === $lower ) {
			$text = implode( ' ', array_filter( array_map( 'esc_url', preg_split( '/\s+/', trim( $text ) ) ?: array() ) ) );
		} elseif ( $dynamic && 'style' === $lower ) {
			$text = safecss_filter_attr( $text );
		} elseif ( $dynamic && 'src' === $lower && '' !== $text && TransferMarkup::is_transfer_media_prop( $value ) && $this->transfer_managed( $context ) && ! self::site_upload_url( $text ) ) {
			// `src={props.immagine}` in a component Histeon CMS manages, the dynamic source a transfer carries: only an
			// image of this site (docs/spec/transfer-markup.md §5). Components WordPress alone manages are unchanged.
			return '';
		}
		$escaped = $dynamic && in_array( $lower, MarkupPolicy::URL_ATTRIBUTES, true ) && ! in_array( $lower, array( 'srcset', 'imagesrcset', 'ping' ), true )
			? esc_url( $text )
			: esc_attr( $text );
		return ' ' . $name . '="' . $escaped . '"';
	}

	/**
	 * Whether the component whose props are in scope was written by an import from Histeon CMS: its definition, or the
	 * post that owns its key (PageProfileAdapter::is_transfer_imported()). A download alone never makes one so.
	 */
	private function transfer_managed( Context $context ): bool {
		$ref = array() === $context->stack ? null : $context->stack[ count( $context->stack ) - 1 ];
		if ( ! is_string( $ref ) ) {
			return false;
		}
		if ( ! array_key_exists( $ref, $this->transfer_managed ) ) {
			$definition = $this->components->find( $ref );
			$owner      = ComponentRegistry::owner( $ref );
			$this->transfer_managed[ $ref ] = ( null !== $definition && PageProfileAdapter::is_transfer_imported( $definition['id'] ) ) || ( null !== $owner && PageProfileAdapter::is_transfer_imported( $owner ) );
		}
		return $this->transfer_managed[ $ref ];
	}

	/**
	 * Whether a URL is under this site's uploads: absolute (http or https, the site's own host and uploads path) or
	 * root-relative to that path. No whitespace, controls or backslashes, which browsers drop or read as `/`, and no
	 * dot segments, written or percent-encoded (`/../`, `%2e`), which would climb out of the uploads.
	 */
	private static function site_upload_url( string $url ): bool {
		// Checked as printed: a value esc_url() would change (it drops `^ < > " ` { }` and spaces, and rewrites `&`)
		// could become a dot segment only after the check, so it is refused.
		if ( esc_url( $url ) !== $url ) {
			return false;
		}
		// The value as the browser reads the attribute (character references resolved, G3: none left incomplete), so
		// `.&#9;./` cannot hide a dot segment behind a tab the URL parser then removes.
		try {
			$decoded = TransferMarkup::decode_attribute( $url, 'The image source' );
		} catch ( \XB\Remote\TransferMarkupViolation ) {
			return false;
		}
		if ( 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $decoded ) || str_contains( strtolower( $decoded ), '%2e' ) ) {
			return false;
		}
		$path_part = (string) preg_replace( '/[?#].*$/s', '', $decoded );
		if ( 1 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path_part ) ) {
			return false;
		}
		$base = (string) wp_upload_dir( null, false )['baseurl'];
		if ( '' === $base ) {
			return false;
		}
		$base     = untrailingslashit( $base ) . '/';
		$no_scheme = (string) preg_replace( '#^https?:#i', '', $base );
		if ( str_starts_with( $no_scheme, '//' ) && ( str_starts_with( $url, 'https:' . $no_scheme ) || str_starts_with( $url, 'http:' . $no_scheme ) ) ) {
			return true;
		}
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		return '/' !== $path && str_starts_with( $path, '/' ) && str_starts_with( $url, $path ) && ! str_starts_with( $url, '//' );
	}

	/**
	 * An image from the content (`<img src={item.image.url}>` or `src={item.image}`) gets what makes it load well
	 * when the author did not write it: width and height (no layout shift), srcset and sizes (the right file for
	 * the screen). The attachment is known from the expression, so nothing is looked up by URL. Only missing
	 * attributes are added; nothing written by the author is changed.
	 *
	 * @param array<string, mixed> $attributes
	 */
	private function image_extras( array $attributes, Context $context ): string {
		$src = $attributes['src'] ?? null;
		if ( ! is_string( $src ) || ! Template::is_single( $src ) ) {
			return '';
		}
		$expr = trim( substr( $src, 1, -1 ) );
		$node = null;
		$size = 'full';
		// Evaluated quietly: the src attribute itself reports any problem with the expression.
		$quiet = fn( string $source ): mixed => $this->evaluator->evaluate( Parser::parse( $source ), $context->scope );
		try {
			$value = $quiet( $expr );
			if ( $value instanceof \XB\Data\ImageNode ) {
				$node = $value;
			} elseif ( 1 === preg_match( '/^(.+)\.sizes\.([a-zA-Z_][\w-]*)\.url$/', $expr, $m ) && in_array( $m[2], get_intermediate_image_sizes(), true ) ) {
				// The shape of Etch's data: `image.sizes.medium_large.url`.
				$parent = $quiet( $m[1] );
				$node   = $parent instanceof \XB\Data\ImageNode ? $parent : null;
				$size   = $m[2];
			} elseif ( 1 === preg_match( '/^(.+)\.([a-zA-Z_][\w-]*)$/', $expr, $m ) && ( 'url' === $m[2] || in_array( $m[2], get_intermediate_image_sizes(), true ) ) ) {
				// `.url` (the original) or a registered size (`.medium_large`): that size's own dimensions.
				$parent = $quiet( $m[1] );
				$node   = $parent instanceof \XB\Data\ImageNode ? $parent : null;
				$size   = 'url' === $m[2] ? 'full' : $m[2];
			}
		} catch ( \Throwable ) {
			return '';
		}
		if ( null === $node ) {
			return '';
		}
		$id    = (int) $node->get( 'id' );
		$has   = array_change_key_case( $attributes, CASE_LOWER );
		$extra = '';
		$file  = wp_get_attachment_image_src( $id, $size );
		foreach ( array( 'width' => 1, 'height' => 2 ) as $dimension => $at ) {
			$value = is_array( $file ) ? (int) $file[ $at ] : 0;
			if ( ! isset( $has[ $dimension ] ) && $value > 0 ) {
				$extra .= ' ' . $dimension . '="' . $value . '"';
			}
		}
		if ( ! isset( $has['srcset'] ) ) {
			$srcset = (string) wp_get_attachment_image_srcset( $id, $size );
			$srcset = implode( ', ', array_filter( array_map( array( self::class, 'srcset_candidate' ), explode( ',', $srcset ) ) ) );
			if ( 'full' !== $size && is_array( $file ) ) {
				// A size was chosen: no file wider than it.
				$srcset = self::srcset_up_to( $srcset, (int) $file[1] );
			}
			if ( '' !== $srcset ) {
				$extra .= ' srcset="' . esc_attr( $srcset ) . '"';
				if ( ! isset( $has['sizes'] ) ) {
					$sizes = (string) wp_get_attachment_image_sizes( $id, $size );
					// A lazy image may let the browser use its laid-out width (sizes="auto").
					$lazy  = 'lazy' === ( $has['loading'] ?? null );
					if ( '' !== $sizes ) {
						$extra .= ' sizes="' . esc_attr( ( $lazy ? 'auto, ' : '' ) . $sizes ) . '"';
					}
				}
			}
		}
		return $extra;
	}

	/**
	 * The candidates of a srcset no wider than $width (`768w` ≤ 768). Candidates without a width descriptor stay;
	 * a width of 0 or less leaves the srcset as it is.
	 */
	public static function srcset_up_to( string $srcset, int $width ): string {
		if ( $width <= 0 ) {
			return $srcset;
		}
		$kept = array_filter(
			array_map( 'trim', explode( ',', $srcset ) ),
			static fn ( string $c ): bool => '' !== $c && ( 1 !== preg_match( '/\s(\d+)w$/', $c, $m ) || (int) $m[1] <= $width )
		);
		return implode( ', ', $kept );
	}

	private static function srcset_candidate( string $candidate ): string {
		$parts = preg_split( '/\s+/', trim( $candidate ), 2 ) ?: array();
		$url   = esc_url( (string) ( $parts[0] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}
		$descriptor = isset( $parts[1] ) && 1 === preg_match( '/^\d+(\.\d+)?[wx]$/', $parts[1] ) ? ' ' . $parts[1] : '';
		return $url . $descriptor;
	}

	private function text( string $content, Context $context ): string {
		if ( ! str_contains( $content, '{' ) && ! str_contains( $content, '\\' ) ) {
			return self::literal_quotes( $content );
		}
		try {
			$parts = Template::split( self::static_tags( $content ) );
		} catch ( SyntaxError $e ) {
			return $this->problem( $e->getMessage() );
		}
		$html = '';
		foreach ( $parts as [ $kind, $text ] ) {
			if ( Template::LITERAL === $kind ) {
				$html .= self::literal_quotes( $text );
				continue;
			}
			$value = $this->evaluate( $text, $context );
			$html .= $value instanceof \XB\Expr\InlineHtml ? (string) $value : esc_html( Value::to_string( $value ) );
		}
		return $html;
	}

	/**
	 * Inline tags in text carry no interpolations (docs/spec/dsl.md §3.4): braces inside a tag are kept as written.
	 * Otherwise the split would cut the tag, unquote its attribute and let a value's spaces start new attributes.
	 * Expressions outside tags are skipped whole, so a comparison such as {a<b} is not mistaken for a tag.
	 */
	private static function static_tags( string $content ): string {
		if ( ! str_contains( $content, '<' ) ) {
			return $content;
		}
		$out    = '';
		$length = strlen( $content );
		for ( $i = 0; $i < $length; ++$i ) {
			$c = $content[ $i ];
			if ( '\\' === $c && isset( $content[ $i + 1 ] ) && ( '{' === $content[ $i + 1 ] || '}' === $content[ $i + 1 ] ) ) {
				$out .= $c . $content[ $i + 1 ];
				++$i;
			} elseif ( '{' === $c ) {
				$close = Template::closing_brace( $content, $i );
				$out  .= substr( $content, $i, $close - $i + 1 );
				$i     = $close;
			} elseif ( '<' === $c && isset( $content[ $i + 1 ] ) && 1 === preg_match( '/[A-Za-z\/!?]/', $content[ $i + 1 ] ) ) {
				$quote = '';
				for ( ; $i < $length; ++$i ) {
					$t = $content[ $i ];
					if ( '' === $quote && '>' === $t ) {
						$out .= $t;
						break;
					}
					if ( '' !== $quote ) {
						$quote = $t === $quote ? '' : $quote;
					} elseif ( '"' === $t || "'" === $t ) {
						$quote = $t;
					}
					if ( '\\' === $t && isset( $content[ $i + 1 ] ) && ( '{' === $content[ $i + 1 ] || '}' === $content[ $i + 1 ] ) ) {
						$out .= $t . $content[ $i + 1 ];
						++$i;
						continue;
					}
					$out .= '{' === $t || '}' === $t ? '\\' . $t : $t;
				}
			} else {
				$out .= $c;
			}
		}
		return $out;
	}

	/**
	 * Quotes in text written in XB become entities (same rendering): WordPress's wptexturize, which runs over the
	 * whole page, then leaves them as written instead of turning them into typographic quotes. Markup is untouched.
	 */
	private static function literal_quotes( string $html ): string {
		if ( ! str_contains( $html, "'" ) && ! str_contains( $html, '"' ) ) {
			return $html;
		}
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $html );
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = str_replace( array( "'", '"' ), array( '&#039;', '&quot;' ), $part );
			}
		}
		return implode( '', $parts );
	}

	/** @param list<array<string, mixed>> $branches */
	private function condition( array $branches, Context $context ): string {
		foreach ( $branches as $branch ) {
			if ( 'xb/branch' !== ( $branch['blockName'] ?? null ) ) {
				continue;
			}
			$attrs = is_array( $branch['attrs'] ?? null ) ? $branch['attrs'] : array();
			$test  = $attrs['test'] ?? null;
			if ( null === $test || Value::truthy( $this->evaluate( (string) $test, $context ) ) ) {
				$children = is_array( $branch['innerBlocks'] ?? null ) ? array_values( $branch['innerBlocks'] ) : array();
				return $this->render_blocks( $children, $context );
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 */
	private function loop( array $attrs, array $children, Context $context ): string {
		$as    = (string) ( $attrs['as'] ?? 'item' );
		$index = isset( $attrs['index'] ) ? (string) $attrs['index'] : null;
		if ( 1 !== preg_match( Spec::IDENTIFIER_PATTERN, $as ) || ( null !== $index && 1 !== preg_match( Spec::IDENTIFIER_PATTERN, $index ) ) ) {
			return $this->problem( __( 'nome di variabile del loop non valido', 'xb' ) );
		}
		if ( $this->loop_depth >= self::MAX_LOOP_DEPTH ) {
			return $this->problem( __( 'troppi loop annidati', 'xb' ) );
		}
		// `{:else}`: a last branch without a test, shown instead of the items when there are none (docs/spec/dsl.md).
		$empty = null;
		$last  = end( $children );
		if ( is_array( $last ) && 'xb/branch' === ( $last['blockName'] ?? null ) && ! isset( $last['attrs']['test'] ) ) {
			$empty    = $last;
			$children = array_slice( $children, 0, -1 );
		}
		$items = $this->loop_items( is_array( $attrs['source'] ?? null ) ? $attrs['source'] : array(), $context );
		$facet = null;
		if ( \XB\StaticSite\ExportMode::active() && 'expr' === ( $attrs['source']['kind'] ?? null ) && 1 === preg_match( '/^\s*facets\.([a-z][a-z0-9_]{0,30})\.items\s*$/', (string) ( $attrs['source']['expr'] ?? '' ), $m ) && \XB\Render\Facets::exported( $m[1] ) ) {
			$facet = $m[1];
		}
		$html  = '';
		$count = 0;
		++$this->loop_depth;
		try {
			foreach ( $items as $key => $item ) {
				if ( ++$count > self::MAX_LOOP_ITEMS ) {
					// Visitors see the first items; the builder says why the rest is missing. The static build of
					// Histeon CMS refuses such a loop instead (docs/spec/blocks.md).
					$html .= $this->problem( sprintf( /* translators: %d: maximum loop items */ __( 'il loop supera %d elementi: mostrati solo i primi', 'xb' ), self::MAX_LOOP_ITEMS ) );
					break;
				}
				if ( $this->nodes > self::MAX_NODES ) {
					break;
				}
				$vars = array( $as => $item );
				if ( null !== $index ) {
					$vars[ $index ] = $key;
				}
				$chunk = $this->render_blocks( $children, $context->with( $vars ) );
				// The static ZIP filters a facet's results in the browser (P9): each result's first element says which.
				if ( null !== $facet && $item instanceof \XB\Data\PostNode ) {
					$tags = new \WP_HTML_Tag_Processor( $chunk );
					if ( $tags->next_tag() ) {
						$tags->set_attribute( 'data-xb-facet-item', $facet . ':' . $item->id() );
						$chunk = $tags->get_updated_html();
					}
				}
				$html .= $chunk;
			}
		} finally {
			--$this->loop_depth;
		}
		if ( 0 === $count && null !== $empty ) {
			return $this->render_blocks( is_array( $empty['innerBlocks'] ?? null ) ? $empty['innerBlocks'] : array(), $context );
		}
		return $html;
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array<mixed>
	 */
	private function loop_items( array $source, Context $context ): array {
		if ( 'preset' === ( $source['kind'] ?? null ) && is_string( $source['id'] ?? null ) ) {
			$args = array();
			foreach ( is_array( $source['args'] ?? null ) ? $source['args'] : array() as $name => $expression ) {
				$args[ (string) $name ] = $this->evaluate( (string) $expression, $context );
			}
			$items = $this->presets->run( $source['id'], $args );
			return array_key_exists( 'groupBy', $source ) ? $this->group_items( $items, $source['groupBy'] ) : $items;
		}
		$value = $this->evaluate( (string) ( $source['expr'] ?? '' ), $context );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * A query's result in groups (doc 23 of Publish, item 187): `{#loop foto().groupBy("anno") as gruppo}` lists the
	 * groups `{key, items}` the groupBy modifier makes from a list, in the query's order, from one query.
	 *
	 * @param array<mixed> $items
	 * @return array<mixed>
	 */
	private function group_items( array $items, mixed $group_by ): array {
		$field = is_array( $group_by ) ? ( $group_by['field'] ?? null ) : null;
		$how   = is_array( $group_by ) ? ( $group_by['how'] ?? null ) : null;
		if ( ! is_array( $group_by ) || array_diff( array_keys( $group_by ), array( 'field', 'how' ) ) || ! is_string( $field ) || 1 !== preg_match( Spec::GROUP_BY_FIELD_PATTERN, $field ) || ( null !== $how && ( ! is_string( $how ) || 1 !== preg_match( Spec::GROUP_BY_HOW_PATTERN, $how ) ) ) ) {
			$this->problem( __( 'raggruppamento della query non valido', 'xb' ) );
			return array();
		}
		$args = array( array( 'value' => array( 'type' => 'Literal', 'value' => $field ) ) );
		if ( null !== $how ) {
			$args[] = array( 'value' => array( 'type' => 'Literal', 'value' => $how ) );
		}
		$call = array(
			'type'   => 'Call',
			'callee' => array(
				'type'     => 'Member',
				'object'   => array( 'type' => 'Identifier', 'name' => 'items' ),
				'property' => 'groupBy',
			),
			'args'   => $args,
		);
		try {
			$groups = $this->evaluator->evaluate( $call, array( 'items' => array_values( $items ) ) );
		} catch ( EvalError $e ) {
			$this->problem( $e->getMessage() );
			return array();
		}
		return is_array( $groups ) ? array_values( $groups ) : array();
	}

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 */
	private function component( array $attrs, array $children, Context $context ): string {
		$scope = $this->component_scope( $attrs, $children, $context );
		if ( is_string( $scope ) ) {
			return $this->problem( $scope );
		}
		if ( ! $context->annotate ) {
			$this->scripts?->use( $scope['id'] );
		}
		return $this->render_blocks( $scope['blocks'], $scope['context'] );
	}

	/**
	 * A component instance: its definition's blocks and the context they render in (props evaluated, slots filled),
	 * or why it cannot render. Also read by the conversion to Fondamenta (Export\Core\Converter).
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @return array{id: int, blocks: list<array<string, mixed>>, context: Context}|string
	 */
	public function component_scope( array $attrs, array $children, Context $context ): array|string {
		$ref = (string) ( $attrs['ref'] ?? '' );
		if ( in_array( $ref, $context->stack, true ) ) {
			return sprintf( /* translators: %s: component key */ __( 'il componente "%s" include sé stesso', 'xb' ), $ref );
		}
		if ( count( $context->stack ) >= self::MAX_COMPONENT_DEPTH ) {
			return sprintf( /* translators: 1: component key, 2: maximum component depth */ __( 'il componente "%1$s" supera la profondità massima di %2$d', 'xb' ), $ref, self::MAX_COMPONENT_DEPTH );
		}
		$definition = $this->components->find( $ref );
		if ( null === $definition ) {
			return sprintf( /* translators: %s: component key */ __( 'componente "%s" non trovato', 'xb' ), $ref );
		}

		$props = array();
		foreach ( $definition['props'] as $name => $schema ) {
			$props[ (string) $name ] = $schema['default'] ?? null;
		}
		foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $name => $value ) {
			$props[ (string) $name ] = $this->prop_value( $value, $context );
		}

		$slots = array();
		foreach ( $children as $fill ) {
			if ( 'xb/slot-fill' === ( $fill['blockName'] ?? null ) ) {
				$slot_name           = (string) ( $fill['attrs']['name'] ?? Spec::DEFAULT_SLOT );
				$slots[ $slot_name ] = array(
					'blocks'  => is_array( $fill['innerBlocks'] ?? null ) ? array_values( $fill['innerBlocks'] ) : array(),
					'context' => $context,
				);
			}
		}

		// A template part reads the page it is on as `props.page` too (docs/spec/templates.md §5): a header marks the
		// active item of its menu.
		$part = $definition['id'] > 0 && 'wp_template_part' === get_post_type( $definition['id'] );
		return array(
			'id'      => $definition['id'],
			'blocks'  => $definition['blocks'],
			'context' => $context->for_component( $ref, $props, $slots, (string) ( $attrs['uid'] ?? '' ), $this->declared_slots( $definition['blocks'] ), $part ),
		);
	}

	/**
	 * Slot names the component's own markup declares (a slot declared but left empty is still known).
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private function declared_slots( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? null;
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/slot' === $name ) {
				$names[] = (string) ( $attrs['name'] ?? Spec::DEFAULT_SLOT );
			}
			// A nested component owns its slots; they are not visible to this component.
			if ( 'xb/component' !== $name && is_array( $block['innerBlocks'] ?? null ) ) {
				$names = array_merge( $names, $this->declared_slots( array_values( array_filter( $block['innerBlocks'], 'is_array' ) ) ) );
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function prop_value( mixed $value, Context $context ): mixed {
		if ( is_array( $value ) && isset( $value['expr'] ) && is_string( $value['expr'] ) ) {
			return $this->evaluate( $value['expr'], $context );
		}
		if ( is_array( $value ) && isset( $value['tpl'] ) && is_string( $value['tpl'] ) ) {
			return $this->interpolate( $value['tpl'], $context );
		}
		return $value;
	}

	/** @param list<array<string, mixed>> $children */
	private function passthrough( array $children, Context $context ): string {
		foreach ( $children as $child ) {
			if ( null !== ( $child['blockName'] ?? null ) ) {
				return $this->foreign( $child, $context );
			}
		}
		return '';
	}

	/** Blocks whose text WordPress runs shortcodes in: the content's, and the markup of a block template. */
	private const SHORTCODE_BLOCKS = array( 'core/shortcode', 'core/html' );

	/**
	 * A block of WordPress or of another plugin, rendered by WordPress. Shortcodes: WordPress runs them on the whole
	 * content (the_content) and on the markup of a block template before its blocks, never on a block by itself. A
	 * component is drawn later than both, so inside a component outside the content (a footer in a template) the
	 * shortcodes of a shortcode or HTML block run here; in the content the_content runs them afterwards, once.
	 *
	 * @param array<string, mixed> $block
	 */
	private function foreign( array $block, Context $context ): string {
		$html = render_block( $block );
		if ( array() !== $context->stack && ! doing_filter( 'the_content' ) && str_contains( $html, '[' ) && self::has_shortcode_block( array( $block ), 0 ) ) {
			$html = do_shortcode( $html );
		}
		return $html;
	}

	/** @param array<mixed> $blocks */
	private static function has_shortcode_block( array $blocks, int $depth ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( in_array( $block['blockName'] ?? null, self::SHORTCODE_BLOCKS, true ) || ( $depth < 20 && self::has_shortcode_block( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $depth + 1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function post_content( Context $context ): string {
		// On a list (the posts page, an archive, a search) the slot of a template is empty, as in Histeon Publish:
		// the global post there is only the first item listed, and its text does not belong under the list.
		if ( ! is_singular() && ( is_home() || is_archive() || is_search() ) ) {
			return '';
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || isset( $this->rendering_posts[ $post->ID ] ) ) {
			return '';
		}
		$this->rendering_posts[ $post->ID ] = true;
		try {
			/** This filter is documented in wp-includes/post-template.php */
			$content = (string) apply_filters( 'the_content', str_replace( ']]>', ']]&gt;', get_the_content( null, false, $post ) ) );
		} finally {
			unset( $this->rendering_posts[ $post->ID ] );
		}
		return $content;
	}

	// ------------------------------------------------------------------ expressions

	/** An expression's value, as `{…}` would evaluate it here (the conversion to Fondamenta writes it). */
	public function value( string $source, Context $context ): mixed {
		return $this->evaluate( $source, $context );
	}

	/**
	 * The items a loop source gives here: a preset with its arguments (and groups), or a list.
	 *
	 * @param array<string, mixed> $source
	 * @return array<mixed>
	 */
	public function items( array $source, Context $context ): array {
		return $this->loop_items( $source, $context );
	}

	/** Whether a branch test holds, as {#if} would decide it (the block editor shows only that branch). */
	public function test( string $source, Context $context ): bool {
		return Value::truthy( $this->evaluate( $source, $context ) );
	}

	private function evaluate( string $source, Context $context ): mixed {
		try {
			return $this->evaluator->evaluate( Parser::parse( $source ), $context->scope );
		} catch ( SyntaxError | EvalError $e ) {
			$this->problem( $e->getMessage() . ' in {' . $source . '}' );
			return null;
		}
	}

	/** Interpolates `{…}` into plain text (escaping is the caller's job). */
	private function interpolate( string $value, Context $context ): string {
		try {
			$parts = Template::split( $value );
		} catch ( SyntaxError $e ) {
			$this->problem( $e->getMessage() );
			return '';
		}
		$text = '';
		foreach ( $parts as [ $kind, $part ] ) {
			$text .= Template::LITERAL === $kind ? $part : Value::to_string( $this->evaluate( $part, $context ) );
		}
		return $text;
	}

	/** Render problems never break the page; builders see them as HTML comments when debugging. */
	private function problem( string $message ): string {
		if ( ! $this->debug || ! current_user_can( 'xb_build' ) ) {
			return '';
		}
		return '<!-- xb: ' . esc_html( str_replace( '--', '- -', $message ) ) . ' -->';
	}
}
