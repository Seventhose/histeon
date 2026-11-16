<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import\Etch;

use XB\Blocks;
use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Styles\CssCompiler;
use XB\Styles\StyleRegistry;

/**
 * Etch turned off, the site unchanged — docs/spec/import-etch.md §7. While Etch is not active, content it saved
 * (blocks `etch/*` in pages, templates, parts and components) is converted when it is rendered, with the same
 * Converter as the import, and rendered by Histeon: elements, texts and expressions, loops (Etch queries as presets
 * known for the request), components (by their Etch post), dynamic images; Etch's selector styles and global sheets
 * join Histeon's CSS on demand. Nothing is written: the import stays recommended (LegacyReminder), never required.
 *
 * Trust is the import's: content whose author and last editor have unfiltered_html renders as converted, element
 * scripts included; any other goes through the restricted pipeline and its scripts are dropped. Converted uids are
 * random, but they reach the HTML only in the builder's annotated preview, never on the public site.
 */
final class Runtime {

	/** Converted runtime components: ref → Etch post ID. */
	private const COMPONENT_PREFIX = 'etchrt-c';

	/** @var list<int> Posts whose content is being rendered (the_content), innermost last. */
	private static array $documents = array();
	/** @var array<string, true> Etch blocks being rendered now, by hash (a kept block must not render itself). */
	private static array $rendering = array();
	/** @var list<array{0: string, 1: bool}> Nested sources being rendered (synced patterns, template parts) and their trust. */
	private static array $sources = array();
	/** @var array<string, true> Element scripts to print, by hash. */
	private static array $scripts = array();
	/** @var array<string, string>|null */
	private static ?array $script_code = null;
	/** @var array{0: array<string, array{kind: string, id?: string, expr?: string}>, 1: array<string, array{type: string, args: array<mixed>}>, 2: list<string>}|null */
	private static ?array $loops = null;

	public static function register(): void {
		// After core's _wp_add_block_level_preset_styles (10), which answers null whatever it receives.
		add_filter( 'pre_render_block', array( self::class, 'render' ), 20, 3 );
		add_filter( 'the_content', array( self::class, 'enter' ), 1 );
		add_filter( 'the_content', array( self::class, 'leave' ), 999 );
		// Content nested without the_content keeps the trust of its own post, not of the page around it.
		add_filter( 'render_block_data', array( self::class, 'enter_source' ), 10, 1 );
		add_filter( 'render_block', array( self::class, 'leave_source' ), 10, 2 );
		add_filter( 'xb_component_definition', array( self::class, 'component' ), 10, 2 );
		add_filter( 'xb_loop_presets', array( self::class, 'presets' ) );
		add_filter( 'xb_loop_post_types', array( self::class, 'post_types' ) );
		add_filter( 'xb_loop_taxonomies', array( self::class, 'taxonomies' ) );
		add_filter( 'xb_runtime_css', array( self::class, 'css' ) );
		add_filter( 'xb_reset_enabled', array( self::class, 'reset' ) );
		add_filter( 'xb_css_safelist', array( self::class, 'safelist' ) );
		add_action( 'save_post', array( self::class, 'forget' ) );
		// Etch's queries read once per request: read again when they change (a conversion depends on them).
		foreach ( array( 'add_option_etch_loops', 'update_option_etch_loops', 'delete_option_etch_loops' ) as $hook ) {
			add_action( $hook, array( self::class, 'queries_changed' ) );
		}
		add_action( 'edit_attachment', array( self::class, 'flush' ) );
		add_action( 'delete_attachment', array( self::class, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'media_meta' ), 10, 3 );
		}
		add_action( 'wp_footer', array( self::class, 'print_scripts' ), 30 );
	}

	/** Etch is off: its blocks are not registered (only meaningful once `init` has run). */
	public static function active(): bool {
		return did_action( 'init' ) > 0 && ! \WP_Block_Type_Registry::get_instance()->is_registered( 'etch/element' );
	}

	public const HAS_CONTENT = 'xb_etch_runtime_content';

	/** Whether some content still has Etch blocks: asked once an hour, and again after any save. */
	public static function has_content(): bool {
		$cached = get_transient( self::HAS_CONTENT );
		if ( false === $cached ) {
			$cached = Migration::has_content() ? 'yes' : 'no';
			set_transient( self::HAS_CONTENT, $cached, HOUR_IN_SECONDS );
		}
		return 'yes' === $cached;
	}

	public static function forget(): void {
		delete_transient( self::HAS_CONTENT );
		delete_transient( self::STYLE_ORDER );
	}

	/** Histeon's reset, as the import turns it on: Etch's own reset is gone with Etch. */
	public static function reset( mixed $enabled ): bool {
		return (bool) $enabled || ( self::active() && self::has_content() );
	}

	public static function enter( mixed $content ): mixed {
		$id = get_the_ID();
		self::$documents[] = is_int( $id ) ? $id : 0;
		return $content;
	}

	public static function leave( mixed $content ): mixed {
		array_pop( self::$documents );
		return $content;
	}

	/**
	 * A synced pattern or a template part about to render: its blocks are judged by its own author too.
	 *
	 * @param mixed $parsed
	 */
	public static function enter_source( mixed $parsed ): mixed {
		$key = is_array( $parsed ) ? self::source_key( $parsed ) : null;
		if ( null !== $key ) {
			$attrs = is_array( $parsed['attrs'] ?? null ) ? $parsed['attrs'] : array();
			if ( 'core/block' === $parsed['blockName'] ) {
				$post    = get_post( (int) ( $attrs['ref'] ?? 0 ) );
				$trusted = $post instanceof \WP_Post && 'wp_block' === $post->post_type && Migration::author_trusted( $post );
			} else {
				$trusted = self::template_trusted( (string) ( $attrs['theme'] ?? get_stylesheet() ) . '//' . (string) ( $attrs['slug'] ?? '' ), 'wp_template_part' );
			}
			self::$sources[] = array( $key, $trusted );
		}
		return $parsed;
	}

	/**
	 * @param mixed $content
	 * @param mixed $block
	 */
	public static function leave_source( mixed $content, mixed $block ): mixed {
		$key = is_array( $block ) ? self::source_key( $block ) : null;
		if ( null !== $key ) {
			// Drop this source's own entry and anything above it (a render another plugin cut short cannot leave
			// stale trust behind). Core renders a synced pattern twice through render_block (render_block_core_block
			// calls render() on the same block again), so a source no longer on the stack leaves it as it is:
			// emptying it would forget the untrusted pattern around this one.
			for ( $i = count( self::$sources ) - 1; $i >= 0; --$i ) {
				if ( self::$sources[ $i ][0] === $key ) {
					self::$sources = array_slice( self::$sources, 0, $i );
					break;
				}
			}
		}
		return $content;
	}

	/** @param array<string, mixed> $block */
	private static function source_key( array $block ): ?string {
		$name  = $block['blockName'] ?? null;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( 'core/block' === $name ) {
			return 'block:' . (int) ( $attrs['ref'] ?? 0 );
		}
		if ( 'core/template-part' === $name ) {
			return 'part:' . (string) ( $attrs['theme'] ?? '' ) . '//' . (string) ( $attrs['slug'] ?? '' );
		}
		return null;
	}

	/**
	 * An Etch block, rendered by Histeon.
	 *
	 * @param string|null          $pre
	 * @param array<string, mixed> $parsed
	 */
	public static function render( ?string $pre, array $parsed, ?\WP_Block $parent = null ): ?string {
		if ( null !== $pre || ! str_starts_with( (string) ( $parsed['blockName'] ?? '' ), 'etch/' ) || ! self::active() ) {
			return $pre;
		}
		// A block the converter keeps as it is (a loop whose Etch query no longer exists, a block type it does not
		// know) comes back here from inside its own rendering: with Etch off nothing can draw it, so it draws
		// nothing, instead of converting itself forever.
		$self = md5( (string) wp_json_encode( $parsed ) );
		if ( isset( self::$rendering[ $self ] ) ) {
			return '';
		}
		self::$rendering[ $self ] = true;
		try {
			$html = '';
			foreach ( self::convert( array( $parsed ), self::trusted( $parent ) ) as $block ) {
				$html .= render_block( $block );
			}
			return $html;
		} finally {
			unset( self::$rendering[ $self ] );
		}
	}

	/**
	 * Etch blocks → Histeon blocks, sanitized for who wrote them. Cached (docs/spec/import-etch.md §7, «Cache»): the
	 * key holds everything the result depends on — the blocks themselves, the trust, the component keys, Etch's
	 * queries, Histeon's version and a generation bumped when media change — so a changed input is a new key, and
	 * untrusted content can never meet the result of trusted content.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function convert( array $blocks, bool $trusted ): array {
		$components = self::components( $blocks );
		$key        = self::cache_key( $blocks, $trusted, $components );
		$entry      = self::$memo[ $key ] ?? self::cached( $key );
		if ( null === $entry ) {
			$entry = self::convert_now( $blocks, $trusted, $components );
			self::store( $key, $entry );
		}
		self::$memo[ $key ] = $entry;
		foreach ( $entry['scripts'] as $code ) {
			$hash                   = md5( $code );
			self::$scripts[ $hash ] = true;
			self::$script_code      ??= array();
			self::$script_code[ $hash ] = $code;
		}
		return $entry['blocks'];
	}

	/**
	 * The conversion itself.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @param array<int, string>          $components
	 * @return array{blocks: list<array<string, mixed>>, scripts: list<string>}
	 */
	private static function convert_now( array $blocks, bool $trusted, array $components ): array {
		$converter  = new Converter( $components, self::loops()[0], self::image_props( $components ) );
		$serialized = Blocks::serialize( $converter->convert( $blocks ) );
		// Serialized and parsed again: blocks complete as WordPress renders them (innerContent, innerHTML).
		if ( ! $trusted ) {
			// The import's restricted pipeline: Histeon's markup policy, then the kses of restricted authors.
			/** @var list<array<string, mixed>> $restricted */
			$restricted = parse_blocks( Plugin::instance()->sanitizer->restricted_content( $serialized ) );
			return array(
				'blocks'  => $restricted,
				'scripts' => array(),
			);
		}
		/** @var list<array<string, mixed>> $converted */
		$converted = parse_blocks( $serialized );
		return array(
			'blocks'  => $converted,
			'scripts' => $converter->scripts,
		);
	}

	// ------------------------------------------------------------------ cache

	/** Option: bumped when something outside the content changes a conversion (media). */
	public const GENERATION = 'xb_etch_runtime_generation';
	private const CACHE_GROUP = 'xb_etch_runtime';
	/** Converted content kept in a transient (sites without a persistent object cache) only up to this size. */
	private const CACHE_MAX_BYTES = 512 * 1024;

	/** @var array<string, array{blocks: list<array<string, mixed>>, scripts: list<string>}> This request's conversions. */
	private static array $memo = array();

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param array<int, string>          $components
	 */
	public static function cache_key( array $blocks, bool $trusted, array $components ): string {
		$input = (string) wp_json_encode( array( XB_VERSION, (int) get_option( self::GENERATION, 0 ), $trusted, $components, self::image_props( $components ), self::loops()[0], $blocks ) );
		return 'xb_etchrt_' . substr( hash( 'sha256', $input ), 0, 40 );
	}

	/** @return array{blocks: list<array<string, mixed>>, scripts: list<string>}|null */
	private static function cached( string $key ): ?array {
		$entry = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $entry && ! wp_using_ext_object_cache() ) {
			$entry = get_transient( $key );
			if ( false !== $entry ) {
				wp_cache_set( $key, $entry, self::CACHE_GROUP, DAY_IN_SECONDS );
			}
		}
		if ( ! is_array( $entry ) || ! is_array( $entry['blocks'] ?? null ) || ! array_is_list( $entry['blocks'] ) || ! is_array( $entry['scripts'] ?? null ) ) {
			return null;
		}
		/** @var list<array<string, mixed>> $blocks */
		$blocks = array_values( array_filter( $entry['blocks'], 'is_array' ) );
		return array(
			'blocks'  => $blocks,
			'scripts' => array_values( array_filter( $entry['scripts'], 'is_string' ) ),
		);
	}

	/** @param array{blocks: list<array<string, mixed>>, scripts: list<string>} $entry */
	private static function store( string $key, array $entry ): void {
		wp_cache_set( $key, $entry, self::CACHE_GROUP, DAY_IN_SECONDS );
		if ( ! wp_using_ext_object_cache() && strlen( serialize( $entry ) ) <= self::CACHE_MAX_BYTES ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- size only, as the transient will be stored.
			set_transient( $key, $entry, DAY_IN_SECONDS );
		}
	}

	/** Every cached conversion is stale: a new generation, new keys (old entries expire by themselves). */
	public static function flush(): void {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, true );
		self::$memo = array();
	}

	/**
	 * Image data the converter reads (alt text, sizes) changed.
	 *
	 * @param mixed $meta_id
	 * @param mixed $object_id
	 * @param mixed $meta_key
	 */
	public static function media_meta( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		if ( in_array( $meta_key, array( '_wp_attachment_image_alt', '_wp_attachment_metadata' ), true ) ) {
			self::flush();
		}
	}

	/**
	 * Component keys for the Etch components a subtree uses: the Histeon key if it was imported, else a runtime key.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return array<int, string>
	 */
	private static function components( array $blocks ): array {
		$out  = array();
		$walk = static function ( array $list ) use ( &$walk, &$out ): void {
			foreach ( $list as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( 'etch/component' === ( $block['blockName'] ?? null ) && is_numeric( $block['attrs']['ref'] ?? null ) ) {
					$id         = (int) $block['attrs']['ref'];
					$key        = (string) get_post_meta( $id, ComponentRegistry::META_KEY, true );
					$out[ $id ] = '' !== $key ? $key : self::COMPONENT_PREFIX . $id;
				}
				$walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
			}
		};
		$walk( $blocks );
		return $out;
	}

	/**
	 * The props of these components that their dynamic images use as attachment ID (Converter::image_props()),
	 * read once per request.
	 *
	 * @param array<int, string> $components
	 * @return array<int, list<string>>
	 */
	private static function image_props( array $components ): array {
		static $memo = array();
		$out = array();
		foreach ( array_keys( $components ) as $id ) {
			if ( ! isset( $memo[ $id ] ) ) {
				$post        = get_post( $id );
				$memo[ $id ] = $post instanceof \WP_Post && 'wp_block' === $post->post_type ? Converter::image_props( parse_blocks( $post->post_content ) ) : array();
			}
			if ( array() !== $memo[ $id ] ) {
				$out[ $id ] = $memo[ $id ];
			}
		}
		return $out;
	}

	/**
	 * The definition of an Etch component not imported yet: its post's content, converted.
	 *
	 * @return array{id: int, blocks: list<array<string, mixed>>, props: array<string, array<string, mixed>>}|mixed
	 */
	public static function component( mixed $definition, string $ref ): mixed {
		if ( null !== $definition || ! str_starts_with( $ref, self::COMPONENT_PREFIX ) || ! self::active() ) {
			return $definition;
		}
		$post = get_post( (int) substr( $ref, strlen( self::COMPONENT_PREFIX ) ) );
		if ( ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status ) {
			return $definition;
		}
		return array(
			'id'     => $post->ID,
			'blocks' => self::convert( parse_blocks( $post->post_content ), Migration::author_trusted( $post ) ),
			'props'  => array(),
		);
	}

	/**
	 * Etch's queries as presets for this request (stored presets win, see Queries::presets()).
	 *
	 * @param mixed $extra
	 * @return array<string, mixed>
	 */
	public static function presets( mixed $extra ): array {
		$extra = is_array( $extra ) ? $extra : array();
		if ( ! self::active() ) {
			return $extra;
		}
		try {
			return $extra + \XB\Render\Queries::validate( self::loops()[1] );
		} catch ( \InvalidArgumentException ) {
			return $extra;
		}
	}

	/**
	 * Post types the site's Etch queries list although WordPress does not make them viewable: Etch listed them, and
	 * the import allows them explicitly (Impostazioni → Histeon, xb_loop_types). Only types those queries name.
	 *
	 * @param mixed $types
	 * @return list<string>
	 */
	public static function post_types( mixed $types ): array {
		$types = array_values( array_filter( is_array( $types ) ? $types : array(), 'is_string' ) );
		if ( ! self::active() ) {
			return $types;
		}
		foreach ( self::loops()[1] as $preset ) {
			foreach ( (array) ( $preset['args']['post_type'] ?? array() ) as $type ) {
				if ( is_string( $type ) && post_type_exists( $type ) && ! in_array( $type, $types, true ) ) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	/**
	 * The same for taxonomies: the ones the site's Etch term queries list although WordPress does not make them
	 * public (Impostazioni → Histeon, xb_loop_taxonomies). Never WordPress internals (LoopPresets::listable_taxonomy).
	 *
	 * @param mixed $taxonomies
	 * @return list<string>
	 */
	public static function taxonomies( mixed $taxonomies ): array {
		$taxonomies = array_values( array_filter( is_array( $taxonomies ) ? $taxonomies : array(), 'is_string' ) );
		if ( ! self::active() ) {
			return $taxonomies;
		}
		foreach ( self::loops()[1] as $preset ) {
			if ( 'terms' !== $preset['type'] ) {
				continue;
			}
			foreach ( (array) ( $preset['args']['taxonomy'] ?? array() ) as $taxonomy ) {
				if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, $taxonomies, true ) ) {
					$taxonomies[] = $taxonomy;
				}
			}
		}
		return $taxonomies;
	}

	public static function queries_changed(): void {
		self::$loops = null;
		self::$memo  = array();
	}

	/** @return array{0: array<string, array{kind: string, id?: string, expr?: string}>, 1: array<string, array{type: string, args: array<mixed>}>, 2: list<string>} */
	private static function loops(): array {
		return self::$loops ??= Migration::etch_loops();
	}

	/** Transient: the order of Etch's selector styles over the site's Etch content (StyleData::order()). */
	public const STYLE_ORDER = 'xb_etch_runtime_style_order';

	/** @return list<string> */
	private static function style_order(): array {
		$cached = get_transient( self::STYLE_ORDER );
		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, 'is_string' ) );
		}
		$order = StyleData::order( Migration::etch_posts() );
		set_transient( self::STYLE_ORDER, $order, HOUR_IN_SECONDS );
		return $order;
	}

	/**
	 * Etch's global sheets and selector styles, unless the import brought them already: as the import brings them,
	 * in the order Etch printed them, with the read-only defaults the elements use and custom media resolved.
	 */
	public static function css( mixed $css ): string {
		$css = is_string( $css ) ? $css : '';
		if ( ! self::active() ) {
			return $css;
		}
		foreach ( ( new StyleRegistry() )->globals( true ) as $sheet ) {
			if ( str_starts_with( (string) $sheet['id'], 'etch-' ) ) {
				return $css;
			}
		}
		$sheets = get_option( 'etch_global_stylesheets', array() );
		$media  = StyleData::custom_media( $sheets );
		$out    = '';
		foreach ( is_array( $sheets ) ? $sheets : array() as $sheet ) {
			$text = is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ? StyleData::resolve( StyleData::without_definitions( $sheet['css'] ), $media ) : '';
			if ( '' !== trim( $text ) ) {
				$out .= CssCompiler::sanitize( $text ) . "\n";
			}
		}
		foreach ( Migration::selector_styles( array(), self::style_order() ) as $entry ) {
			$out .= CssCompiler::compile( $entry['selector'], StyleData::resolve( $entry['css'], $media ) ) . "\n";
		}
		return $css . $out;
	}

	/**
	 * Classes that the page's Etch scripts add after loading: kept by the on-demand CSS, as the import does.
	 *
	 * @param mixed $entries
	 * @return list<string>
	 */
	public static function safelist( mixed $entries ): array {
		$entries = array_values( array_filter( is_array( $entries ) ? $entries : array(), 'is_string' ) );
		if ( null === self::$script_code || array() === self::$script_code ) {
			return $entries;
		}
		return array_values( array_unique( array_merge( $entries, Migration::script_classes( implode( "\n", self::$script_code ) ) ) ) );
	}

	/** Element scripts of trusted Etch content, once each, as Etch printed them. */
	public static function print_scripts(): void {
		foreach ( array_keys( self::$scripts ) as $hash ) {
			$code = self::$script_code[ $hash ] ?? '';
			if ( '' !== $code ) {
				// The code was written by a user with unfiltered_html; only the end of the element is neutralized.
				echo '<script>' . str_ireplace( '</script', '<\/script', $code ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		self::$scripts = array();
	}

	/**
	 * Whether the content being rendered was written by trusted users: every synced pattern or template part it is
	 * nested in, then the template part, the post (the_content), or the template it belongs to. Unknown: not trusted.
	 */
	private static function trusted( ?\WP_Block $parent ): bool {
		foreach ( self::$sources as [ , $source_trusted ] ) {
			if ( ! $source_trusted ) {
				return false;
			}
		}
		if ( null !== $parent && 'core/template-part' === $parent->name ) {
			return self::template_trusted( (string) ( $parent->attributes['theme'] ?? get_stylesheet() ) . '//' . (string) ( $parent->attributes['slug'] ?? '' ), 'wp_template_part' );
		}
		$document = end( self::$documents );
		if ( is_int( $document ) && $document > 0 ) {
			$post = get_post( $document );
			return $post instanceof \WP_Post && Migration::author_trusted( $post );
		}
		global $_wp_current_template_id;
		return is_string( $_wp_current_template_id ) && '' !== $_wp_current_template_id && self::template_trusted( $_wp_current_template_id, 'wp_template' );
	}

	/** A template from the theme's files is the theme's code; one edited in the site has an author to check. */
	private static function template_trusted( string $id, string $type ): bool {
		$template = get_block_template( $id, 'wp_template_part' === $type ? 'wp_template_part' : 'wp_template' );
		if ( ! $template instanceof \WP_Block_Template ) {
			return false;
		}
		if ( empty( $template->wp_id ) ) {
			return 'theme' === $template->source;
		}
		$post = get_post( (int) $template->wp_id );
		return $post instanceof \WP_Post && Migration::author_trusted( $post );
	}
}
