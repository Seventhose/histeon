<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import\Etch;

use XB\Blocks;
use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Versioning\Documents;
use XB\Versioning\Versioning;

/**
 * Import of an Etch site — docs/spec/import-etch.md, ADR 0008.
 *
 * plan() reports what would change; prepare() writes it as dev commits (pages, posts, components, templates,
 * styles, the XB reset in `settings`) and adds the loop presets. Nothing goes live until a release is
 * published, and that release can be rolled back. Etch's own data is only read.
 */
final class Migration {

	public const OPTION     = 'xb_etch_import';
	private const MAX_DOCS  = 2000;
	private const STATUSES  = array( 'publish', 'draft', 'private', 'future', 'pending' );
	private const MARKER    = 'wp:etch/';

	public function __construct( private readonly Versioning $versioning ) {}

	public static function instance(): self {
		return new self( Plugin::instance()->versioning );
	}

	/** Whether this site has anything saved by Etch. */
	public static function detected(): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type <> 'revision' LIMIT 1", '%' . $wpdb->esc_like( self::MARKER ) . '%' ) );
		return null !== $found || is_array( get_option( 'etch_global_stylesheets' ) );
	}

	/** Whether some content still has Etch blocks (not imported yet), whatever the options left behind. */
	public static function has_content(): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft') LIMIT 1", '%' . $wpdb->esc_like( self::MARKER ) . '%' ) );
	}

	/** @return array<string, mixed> */
	public function plan(): array {
		return $this->run( false );
	}

	/** @return array<string, mixed> */
	public function prepare(): array {
		return $this->run( true );
	}

	/** @return array<string, mixed> */
	private function run( bool $write ): array {
		if ( ! current_user_can( 'xb_build' ) ) {
			throw new \RuntimeException( __( 'Serve il permesso xb_build per importare.', 'xb' ) );
		}
		$posts       = $this->posts();
		$components  = $this->components( $posts );
		$image_props = array();
		foreach ( array_keys( $components ) as $component ) {
			$post  = get_post( $component );
			$names = $post instanceof \WP_Post ? Converter::image_props( parse_blocks( $post->post_content ) ) : array();
			if ( array() !== $names ) {
				$image_props[ $component ] = $names;
			}
		}
		[ $loops, $presets, $loop_issues ] = $this->loops();

		$report = array(
			'documents'  => array(),
			'components' => ( array_map( static fn ( int $id, string $key ): array => array( 'id' => $id, 'title' => get_the_title( $id ), 'key' => $key ), array_keys( $components ), $components ) ),
			'presets'    => array_keys( $presets ),
			'styles'     => array(),
			'custom'     => array(),
			'issues'     => $loop_issues,
			'written'    => $write,
		);
		$custom      = array();
		$all_scripts = '';

		// What could stop the import is checked before the first document is written (notes 68 and 69 of the audit of
		// 3 November 2026): the queries merged with the site's, and the size of the styles, with the builder's ceiling.
		$style_bytes = $this->styles( false, $posts )['total'];
		if ( $style_bytes > \XB\Rest\Controller::MAX_CSS_BYTES ) {
			/* translators: 1: bytes of CSS, 2: maximum bytes */
			$too_large = sprintf( __( 'Gli stili, con quelli di Etch, superano la dimensione massima (%1$d byte su %2$d): l\'importazione non scrive nulla. Alleggerisci i fogli globali di Etch e riprova.', 'xb' ), $style_bytes, \XB\Rest\Controller::MAX_CSS_BYTES );
			if ( $write ) {
				throw new \RuntimeException( $too_large );
			}
			$report['issues'][] = $too_large;
		}
		if ( $write ) {
			$settings = $this->versioning->working_copy( Documents::SETTINGS );
			\XB\Render\Queries::validate( array_merge( is_array( $settings['payload']['presets'] ?? null ) ? $settings['payload']['presets'] : array(), $presets ) );
		}

		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				$report['issues'][] = sprintf( /* translators: 1: post title, 2: post ID */ __( 'Non puoi modificare «%1$s» (#%2$d): saltato', 'xb' ), get_the_title( $post ), $post->ID );
				continue;
			}
			$doc       = Documents::for_post( $post->ID );
			$trusted   = self::author_trusted( $post );
			$converter = new Converter( $components, $loops, $image_props );
			$content   = Blocks::serialize( $converter->convert( parse_blocks( $post->post_content ) ) );
			$issues    = $converter->report();
			$custom   += $converter->expressions->custom;

			$copy                = $this->versioning->working_copy( $doc );
			$payload             = $copy['payload'];
			$payload['content']  = $content;
			$scripts             = $converter->scripts;
			if ( array() !== $scripts && ! $trusted ) {
				$issues[] = sprintf( /* translators: %d: number of scripts */ _n( '%d script non importato: l\'autore non ha il permesso unfiltered_html', '%d script non importati: l\'autore non ha il permesso unfiltered_html', count( $scripts ), 'xb' ), count( $scripts ) );
				$scripts  = array();
			}
			if ( array() !== $scripts ) {
				$all_scripts      .= implode( "\n", $scripts );
				$existing          = trim( (string) ( $payload['script'] ?? '' ) );
				$payload['script'] = ( '' === $existing ? '' : $existing . "\n\n" ) . implode( "\n\n", $scripts );
				if ( strlen( $payload['script'] ) > \XB\Scripts\Scripts::MAX_BYTES ) {
					$issues[]          = __( 'JavaScript oltre la dimensione massima: non importato', 'xb' );
					$payload['script'] = $existing;
					$scripts           = array();
				}
			}
			if ( 'wp_block' === $post->post_type && isset( $components[ $post->ID ] ) ) {
				$payload['ref']    = $components[ $post->ID ];
				$payload['schema'] = $this->schema( $post->ID, $issues );
			}
			if ( $write ) {
				$this->versioning->save_dev( $doc, $payload, $copy['head'], $copy['base'], __( 'Importazione da Etch', 'xb' ), false, ! $trusted );
			}
			$report['documents'][] = array(
				'doc'     => $doc,
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'title'   => get_the_title( $post ),
				'trusted' => $trusted,
				'counts'  => $converter->counts,
				'scripts' => count( $scripts ),
				'issues'  => $issues,
			);
		}

		$report['styles']   = array_diff_key( $this->styles( $write, $posts ), array( 'total' => true ) );
		$report['safelist'] = $this->safelist( $all_scripts, $write );
		ksort( $custom );
		$report['custom'] = array_keys( $custom );
		if ( array() !== $report['custom'] ) {
			$report['issues'][] = sprintf( /* translators: %s: list of field names */ __( 'Campi aggiunti da plugin usati nelle espressioni: %s. Il plugin che li fornisce deve aggiungerli anche al filtro xb_post_data (docs/spec/import-etch.md §5).', 'xb' ), implode( ', ', $report['custom'] ) );
		}

		// Post types the imported queries list but WordPress does not make viewable (Etch listed them anyway).
		$extra = array();
		foreach ( $presets as $preset ) {
			foreach ( (array) ( $preset['args']['post_type'] ?? array() ) as $type ) {
				if ( is_string( $type ) && post_type_exists( $type ) && ! is_post_type_viewable( $type ) ) {
					$extra[ $type ] = true;
				}
			}
		}
		$report['types'] = array_keys( $extra );
		if ( array() !== $report['types'] ) {
			$report['issues'][] = sprintf( /* translators: %s: list of content types */ __( 'Tipi di contenuto non pubblici usati nei loop, ammessi esplicitamente: %s (Impostazioni → Histeon, opzione xb_loop_types)', 'xb' ), implode( ', ', $report['types'] ) );
		}

		// Taxonomies the imported term queries list but WordPress does not make public (an Etch site counting titles
		// with WooCommerce's private product_type): allowed explicitly, as the content types above.
		$hidden_tax = array();
		foreach ( $presets as $preset ) {
			if ( 'terms' !== $preset['type'] ) {
				continue;
			}
			foreach ( (array) ( $preset['args']['taxonomy'] ?? array() ) as $taxonomy ) {
				$object = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
				if ( $object && ! $object->public && ! in_array( $object->name, \XB\Render\LoopPresets::INTERNAL_TAXONOMIES, true ) ) {
					$hidden_tax[ $object->name ] = true;
				}
			}
		}
		$report['taxonomies'] = array_keys( $hidden_tax );
		if ( array() !== $report['taxonomies'] ) {
			$report['issues'][] = sprintf( /* translators: %s: list of taxonomies */ __( 'Tassonomie non pubbliche usate nei loop di termini, ammesse esplicitamente: %s (Impostazioni → Histeon, opzione xb_loop_taxonomies)', 'xb' ), implode( ', ', $report['taxonomies'] ) );
		}

		// A site built with Etch usually runs a CSS framework: keep using it in XB, if none was chosen yet.
		$frameworks          = Plugin::instance()->frameworks;
		$report['framework'] = null;
		if ( ! $frameworks->chosen() ) {
			foreach ( array( 'acss', 'core-framework' ) as $candidate ) {
				$provider = $frameworks->all()[ $candidate ] ?? null;
				if ( null !== $provider && $provider->available() ) {
					$report['framework'] = $candidate;
					break;
				}
			}
		}

		if ( $write ) {
			// Queries and allowed types are part of the settings document: they go live with the release.
			$settings                         = $this->versioning->working_copy( Documents::SETTINGS );
			$settings['payload']['reset']     = true;
			$settings['payload']['presets']   = \XB\Render\Queries::validate( array_merge( is_array( $settings['payload']['presets'] ?? null ) ? $settings['payload']['presets'] : array(), $presets ) );
			$settings['payload']['loopTypes'] = \XB\Render\Queries::validate_types( array_merge( is_array( $settings['payload']['loopTypes'] ?? null ) ? $settings['payload']['loopTypes'] : array(), $report['types'] ) );
			$settings['payload']['loopTaxonomies'] = \XB\Render\Queries::validate_taxonomies( array_merge( is_array( $settings['payload']['loopTaxonomies'] ?? null ) ? $settings['payload']['loopTaxonomies'] : array(), $report['taxonomies'] ) );
			if ( null !== $report['framework'] ) {
				$settings['payload']['framework'] = $report['framework'];
			}
			$this->versioning->save_dev( Documents::SETTINGS, $settings['payload'], $settings['head'], $settings['base'], __( 'Reset CSS di Histeon (importazione da Etch)', 'xb' ) );
			update_option(
				self::OPTION,
				array(
					'at'   => gmdate( 'c' ),
					'by'   => get_current_user_id(),
					'docs' => count( $report['documents'] ),
				),
				false
			);
			$this->versioning->store()->audit( 'import', 'etch', array( 'docs' => array_column( $report['documents'], 'doc' ), 'presets' => array_keys( $presets ) ) );
		}
		return $report;
	}

	/**
	 * Documents with Etch blocks, live versions only (revisions are history, not content).
	 *
	 * @return list<\WP_Post>
	 */
	private function posts(): array {
		return self::query_posts();
	}

	/** @return list<\WP_Post> */
	private static function query_posts(): array {
		global $wpdb;
		$types    = implode( ',', array_fill( 0, count( Documents::POST_TYPES ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status IN ($statuses) AND post_content LIKE %s ORDER BY FIELD(post_type, 'wp_block') DESC, ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( Documents::POST_TYPES, self::STATUSES, array( '%' . $wpdb->esc_like( self::MARKER ) . '%', self::MAX_DOCS ) )
			)
		);
		return array_values( array_filter( array_map( 'get_post', array_map( 'intval', $ids ) ), static fn ( mixed $p ): bool => $p instanceof \WP_Post ) );
	}

	/**
	 * Components: every wp_block with Etch content or used by an Etch component block, keyed for XB.
	 *
	 * @param list<\WP_Post> $posts
	 * @return array<int, string> Post ID → component key.
	 */
	private function components( array $posts ): array {
		$ids = array();
		foreach ( $posts as $post ) {
			if ( 'wp_block' === $post->post_type ) {
				$ids[ $post->ID ] = true;
			}
			preg_match_all( '/<!--\s+wp:etch\/component\s+(\{.*?\})\s+\/?-->/s', $post->post_content, $m );
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && is_numeric( $attrs['ref'] ?? null ) ) {
					$ids[ (int) $attrs['ref'] ] = true;
				}
			}
		}
		$keys = array();
		$used = array();
		foreach ( array_keys( $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type ) {
				continue;
			}
			$current = (string) get_post_meta( $id, ComponentRegistry::META_KEY, true );
			$key     = '' !== $current ? $current : self::key_for( (string) get_post_meta( $id, 'etch_component_html_key', true ), $post );
			$base    = $key;
			for ( $n = 2; isset( $used[ $key ] ) || ( null !== ComponentRegistry::owner( $key ) && ComponentRegistry::owner( $key ) !== $id ); $n++ ) {
				$key = $base . '-' . self::suffix( $n );
			}
			$used[ $key ] = true;
			$keys[ $id ]  = $key;
		}
		return $keys;
	}

	/** `ColContatti` → `col-contatti`; without a key, the title; always a valid component key. */
	public static function key_for( string $html_key, \WP_Post $post ): string {
		$source = '' !== $html_key ? (string) preg_replace( '/(?<!^)([A-Z])/', '-$1', $html_key ) : $post->post_title;
		$parts  = array_values( array_filter( explode( '-', sanitize_title( remove_accents( $source ) ) ), static fn ( string $p ): bool => '' !== $p ) );
		$parts  = array_map( static fn ( string $p ): string => 1 === preg_match( '/^[a-z]/', $p ) ? $p : 'n' . $p, $parts );
		$key    = implode( '-', array_map( static fn ( string $p ): string => (string) preg_replace( '/[^a-z0-9]/', '', $p ), $parts ) );
		return 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $key ) ? mb_substr( $key, 0, 80 ) : 'component-' . $post->ID;
	}

	/** Letters, so the suffix keeps the key valid (`-2` would start a segment with a digit). */
	private static function suffix( int $n ): string {
		return 'v' . $n;
	}

	/**
	 * @param array<int, string> $issues
	 * @return array{props: list<mixed>}
	 */
	private function schema( int $id, array &$issues ): array {
		$props = get_post_meta( $id, 'etch_component_properties', true );
		if ( is_array( $props ) && array() !== $props ) {
			$issues[] = __( 'Props del componente da ridefinire nel pannello Componente: lo schema di Etch non è stato convertito', 'xb' );
		}
		$current = json_decode( (string) get_post_meta( $id, ComponentRegistry::META_SCHEMA, true ), true );
		return is_array( $current ) && is_array( $current['props'] ?? null ) ? array( 'props' => array_values( $current['props'] ) ) : array( 'props' => array() );
	}

	/**
	 * Etch queries → XB loop sources (by Etch id and by key) and the presets to store.
	 *
	 * @return array{0: array<string, array{kind: string, id?: string, expr?: string, item?: string}>, 1: array<string, array{type: string, args: array<mixed>}>, 2: list<string>}
	 */
	private function loops(): array {
		return self::etch_loops();
	}

	/**
	 * The same, read from Etch's option: also used to render Etch content while Etch is off (Runtime).
	 *
	 * @return array{0: array<string, array{kind: string, id?: string, expr?: string, item?: string}>, 1: array<string, array{type: string, args: array<mixed>}>, 2: list<string>}
	 */
	public static function etch_loops(): array {
		$sources = array();
		$presets = array();
		$issues  = array();
		foreach ( (array) get_option( 'etch_loops', array() ) as $id => $loop ) {
			if ( ! is_array( $loop ) || ! is_array( $loop['config'] ?? null ) ) {
				continue;
			}
			$key    = is_string( $loop['key'] ?? null ) ? $loop['key'] : (string) $id;
			$type   = (string) ( $loop['config']['type'] ?? '' );
			$source = null;
			if ( 'wp-query' === $type && is_array( $loop['config']['args'] ?? null ) ) {
				$preset             = 'etch_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $key );
				$presets[ $preset ] = array(
					'type' => 'posts',
					// Visibility is XB's to decide (published, readable): such arguments are dropped.
					'args' => array_diff_key( $loop['config']['args'], array_flip( \XB\Render\Queries::FORBIDDEN_ARGS ) ),
				);
				$source             = array(
					'kind' => 'preset',
					'id'   => $preset,
					'item' => 'post',
				);
			} elseif ( 'wp-terms' === $type && is_array( $loop['config']['args'] ?? null ) ) {
				// get_terms() arguments, as Etch passes them; get_terms() lists every term, XB's term loops 20 unless told.
				$args = array_diff_key( $loop['config']['args'], array_flip( \XB\Render\Queries::FORBIDDEN_ARGS ) );
				if ( ! isset( $args['number'] ) || (int) $args['number'] <= 0 ) {
					$args['number'] = \XB\Render\LoopPresets::MAX_ITEMS;
				}
				$preset             = 'etch_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $key );
				$presets[ $preset ] = array(
					'type' => 'terms',
					'args' => $args,
				);
				$source             = array(
					'kind' => 'preset',
					'id'   => $preset,
					'item' => 'term',
				);
			} elseif ( 'main-query' === $type ) {
				$preset             = 'etch_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $key );
				$presets[ $preset ] = array(
					'type' => 'main',
					'args' => array_diff_key( is_array( $loop['config']['args'] ?? null ) ? $loop['config']['args'] : array(), array_flip( \XB\Render\Queries::FORBIDDEN_ARGS ) ),
				);
				$source             = array(
					'kind' => 'preset',
					'id'   => $preset,
					'item' => 'post',
				);
			} elseif ( 'json' === $type && is_array( $loop['config']['data'] ?? null ) ) {
				$expr = self::literal( $loop['config']['data'] );
				try {
					\XB\Expr\Parser::parse( $expr );
					$source = array(
						'kind' => 'expr',
						'expr' => $expr,
						'item' => 'data',
					);
				} catch ( \XB\Expr\SyntaxError ) {
					$issues[] = sprintf( /* translators: %s: loop name */ __( 'Dati statici del loop «%s» non convertibili', 'xb' ), $key );
				}
			} else {
				$issues[] = sprintf( /* translators: 1: loop name, 2: query type */ __( 'Query «%1$s» di tipo %2$s non supportata', 'xb' ), $key, '' === $type ? '?' : $type );
			}
			if ( null !== $source ) {
				$sources[ (string) $id ] = $source;
				$sources[ $key ]         = $source;
			}
		}
		return array( $sources, $presets, $issues );
	}

	/** A JSON value as an XB literal (`{label: "Home", url: "/"}`). */
	public static function literal( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return '[' . implode( ', ', array_map( array( self::class, 'literal' ), $value ) ) . ']';
			}
			$parts = array();
			foreach ( $value as $k => $v ) {
				$name    = 1 === preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', (string) $k ) ? (string) $k : (string) wp_json_encode( (string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$parts[] = $name . ': ' . self::literal( $v );
			}
			return '{' . implode( ', ', $parts ) . '}';
		}
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Etch stylesheets → XB global sheets (id `etch-<key>`); selector styles → XB entries, in the order Etch printed
	 * them, with the read-only defaults the elements use; custom media queries resolved (StyleData).
	 *
	 * @param list<\WP_Post> $posts Documents with Etch content.
	 * @return array{globals: int, selectors: int, bytes: int, total: int}
	 */
	private function styles( bool $write, array $posts ): array {
		$copy    = $this->versioning->working_copy( Documents::STYLES );
		$payload = $copy['payload'];
		$globals = array_values( array_filter( is_array( $payload['globals'] ?? null ) ? $payload['globals'] : array(), static fn ( mixed $g ): bool => is_array( $g ) && ! str_starts_with( (string) ( $g['id'] ?? '' ), 'etch-' ) ) );
		$styles  = array_values( array_filter( is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array(), static fn ( mixed $s ): bool => is_array( $s ) && ! str_starts_with( (string) ( $s['id'] ?? '' ), 'etch-' ) ) );
		$count   = array(
			'globals'   => 0,
			'selectors' => 0,
			'bytes'     => 0,
			'total'     => 0,
		);
		$sheets = get_option( 'etch_global_stylesheets', array() );
		$media  = StyleData::custom_media( $sheets );
		foreach ( is_array( $sheets ) ? $sheets : array() as $key => $sheet ) {
			$css = is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ? StyleData::resolve( StyleData::without_definitions( $sheet['css'] ), $media ) : '';
			if ( '' === trim( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ) ) {
				continue; // Empty, or only custom media: nothing a page would print.
			}
			$globals[] = array(
				'id'  => 'etch-' . sanitize_key( (string) $key ),
				'css' => $css,
			);
			++$count['globals'];
			$count['bytes'] += strlen( $css );
		}
		foreach ( self::selector_styles( $posts ) as $key => $entry ) {
			$css      = StyleData::resolve( $entry['css'], $media );
			$styles[] = array(
				'id'       => substr( 'etch-' . sanitize_key( (string) $key ), 0, 64 ),
				'selector' => $entry['selector'],
				'css'      => $css,
			);
			++$count['selectors'];
			$count['bytes'] += strlen( $css );
		}
		// Everything the style document would hold: the site's own rules and sheets as well as Etch's.
		foreach ( array_merge( $styles, $globals ) as $entry ) {
			$count['total'] += is_string( $entry['css'] ?? null ) ? strlen( $entry['css'] ) : 0;
		}
		if ( $write && $count['globals'] + $count['selectors'] > 0 && $count['total'] <= \XB\Rest\Controller::MAX_CSS_BYTES ) {
			$this->versioning->save_dev(
				Documents::STYLES,
				array(
					'styles'  => $styles,
					'globals' => $globals,
				),
				$copy['head'],
				$copy['base'],
				__( 'Importazione da Etch: stili', 'xb' )
			);
		}
		return $count;
	}

	/**
	 * Etch's selector styles a page can print, in the order Etch printed them: the editable ones, and the read-only
	 * defaults Etch keeps in the same option (`:where([data-etch-element="section"])`…) when an element uses them,
	 * as Etch printed them only then. Also used while Etch is off (Runtime).
	 *
	 * @param list<\WP_Post>    $posts Documents with Etch content.
	 * @param list<string>|null $order StyleData::order() of those documents, when already known.
	 * @return array<string, array{selector: string, css: string}>
	 */
	public static function selector_styles( array $posts, ?array $order = null ): array {
		$stored = get_option( 'etch_styles', array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return array();
		}
		$order ??= StyleData::order( $posts );
		$used    = array_flip( $order );
		$out     = array();
		foreach ( StyleData::sorted( $stored, $order ) as $key => $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['selector'] ?? null ) || ! \XB\Styles\CssCompiler::valid_selector( $entry['selector'] ) || ! is_string( $entry['css'] ?? null ) || '' === trim( $entry['css'] ) ) {
				continue;
			}
			if ( ! empty( $entry['readonly'] ) && ! isset( $used[ (string) $key ] ) ) {
				continue; // A default no element uses: Etch did not print it.
			}
			$out[ (string) $key ] = array(
				'selector' => $entry['selector'],
				'css'      => $entry['css'],
			);
		}
		return $out;
	}

	/**
	 * Documents with Etch content, for the order of the styles while Etch is off (Runtime).
	 *
	 * @return list<\WP_Post>
	 */
	public static function etch_posts(): array {
		return self::query_posts();
	}

	/**
	 * The classes of Etch's CSS (global sheets and selector styles) that scripts add after the page loads: the
	 * on-demand CSS cannot see them in the HTML, so they go to the safelist. Also used while Etch is off (Runtime).
	 *
	 * @return list<string> `.class` entries, sorted.
	 */
	public static function script_classes( string $scripts ): array {
		if ( '' === $scripts ) {
			return array();
		}
		$css = '';
		foreach ( (array) get_option( 'etch_global_stylesheets', array() ) as $sheet ) {
			$css .= is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ? $sheet['css'] . "\n" : '';
		}
		foreach ( (array) get_option( 'etch_styles', array() ) as $entry ) {
			if ( is_array( $entry ) && empty( $entry['readonly'] ) && is_string( $entry['selector'] ?? null ) ) {
				$css .= $entry['selector'] . "{}\n" . ( is_string( $entry['css'] ?? null ) ? $entry['css'] : '' ) . "\n";
			}
		}
		preg_match_all( '/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $m );
		$found = array();
		foreach ( array_unique( $m[1] ) as $class ) {
			if ( strlen( $class ) > 2 && 1 === preg_match( '/(?<![\w-])' . preg_quote( $class, '/' ) . '(?![\w-])/', $scripts ) ) {
				$found[] = '.' . $class;
			}
		}
		sort( $found );
		return $found;
	}

	/**
	 * Classes that scripts add at runtime never appear in the HTML, so CSS on demand would drop their rules
	 * (docs/spec/styles.md §3). Every class of the imported CSS that an imported script mentions goes in the safelist.
	 *
	 * @return list<string>
	 */
	private function safelist( string $scripts, bool $write ): array {
		if ( '' === $scripts ) {
			return array();
		}
		$found = self::script_classes( $scripts );
		if ( $write && array() !== $found ) {
			$stored = preg_split( '/[\s,]+/', (string) get_option( \XB\Styles\PageStyles::SAFELIST, '' ) ) ?: array();
			update_option( \XB\Styles\PageStyles::SAFELIST, implode( ' ', array_values( array_unique( array_filter( array_merge( $stored, $found ) ) ) ) ), false );
		}
		return $found;
	}

	/**
	 * Trusted only when whoever wrote and last edited the post may publish unfiltered HTML (ADR 0008, I3).
	 * Templates created by code have no author: only users with edit_theme_options can write them, so they are
	 * trusted when every such user also has unfiltered_html.
	 */
	public static function author_trusted( \WP_Post $post ): bool {
		$users = array_unique( array_filter( array( (int) $post->post_author, (int) get_post_meta( $post->ID, '_edit_last', true ) ) ) );
		if ( array() === $users ) {
			if ( ! in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
				return false;
			}
			$editors = get_users(
				array(
					'capability' => 'edit_theme_options',
					'fields'     => 'ID',
					'number'     => 500,
				)
			);
			$users   = array_map( 'intval', $editors );
			if ( array() === $users ) {
				return false;
			}
		}
		foreach ( $users as $user ) {
			if ( ! user_can( $user, 'unfiltered_html' ) ) {
				return false;
			}
		}
		return true;
	}
}
