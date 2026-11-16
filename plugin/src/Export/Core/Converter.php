<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Content\ContentTypes;
use XB\Expr\Parser;
use XB\Expr\SyntaxError;
use XB\Expr\Template;
use XB\Expr\Value;
use XB\Plugin;
use XB\Render\Context;
use XB\Render\Renderer;
use XB\Spec;

/**
 * Histeon blocks → Fondamenta's blocks and core blocks for text — docs/spec/export-core.md §11 (doc 25 of Histeon
 * Publish, §11 step 3). Nothing is written: convert() returns parse_blocks()-shaped blocks.
 *
 * Whatever does not change from one use to another is rendered now by Histeon itself, as an anonymous visitor
 * would see it, so the saved HTML of a static block is the page as it was: the shell of an element (its tag and
 * attributes), the text of a paragraph, a condition already decided, a list written in the page. What changes —
 * the post a template shows, the items of a query, the terms of a post, the pages of an archive — becomes a
 * dynamic block of Fondamenta (fondamenta/loop, fondamenta/se, fondamenta/contenuto) or a «legame»: a named value
 * of fondamenta_valore(), with its formats. Anything else is frozen into core/html or lost, and noted.
 */
final class Converter {

	/** Text elements that become core blocks when they hold only text and at most a class and an id. */
	private const HEADINGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
	/** Elements that stay as they are, in core/html. */
	private const FROZEN = array( 'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'canvas', 'svg', 'math', 'video', 'audio' );
	private const INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );
	/** Names a value of a post has in fondamenta_valore() (the builder's names). */
	private const POST_KEYS = array( 'id', 'title', 'url', 'slug', 'type', 'excerpt', 'date', 'modified', 'author', 'image', 'categories', 'tags', 'terms', 'fields', 'publishedAt', 'updatedAt' );
	private const TERM_KEYS = array( 'name', 'label', 'slug', 'url', 'count', 'description', 'id' );
	private const LINK_KEYS = array( 'url', 'number', 'current' );
	private const MAX_DEPTH = 100;
	private const MAX_UNROLL = 100;

	/** @var list<array{uid: string, node: string, cosa: string, reason: string}> What was frozen, lost or approximated. */
	public array $notes = array();
	/** @var array<string, int> Blocks written, by name. */
	public array $blocks = array();

	private Renderer $renderer;
	/** @var array<string, string> Field name → its type, for every field Histeon knows. */
	private array $field_types = array();
	/** @var array<string, true> Fields that hold more than one value. */
	private array $multiple = array();
	/** @var array<string, array<string, array<string, mixed>>> Repeaters: name → the fields of a row, by name. */
	private array $repeaters = array();
	/** @var array<string, string> Computed props of the component being expanded: name → the instance's value. */
	private array $computed = array();
	/** The last expression no legame could name, for the note. */
	private string $failed = '';
	/**
	 * Synced patterns, by the component post that defines them: its converted blocks and what each named block
	 * lets an instance override (attribute → prop). False: the component is expanded.
	 *
	 * @var array<string, array{id: int, blocks: list<array<string, mixed>>, map: array<string, array<string, string>>}|false>
	 */
	private static array $patterns = array();
	/**
	 * Histeon forms already made in the chosen form plugin (§16), by name: the block that takes each one's place.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $forms = array();
	/** @var array<string, array<string, string>>|null While a pattern is converted: its overridable blocks. */
	private ?array $overrides = null;

	/**
	 * @param string $kind 'contenuto', 'template', 'parte' or 'componente' (Report::kind()).
	 */
	public function __construct( private readonly string $kind ) {
		$this->renderer = Plugin::instance()->renderer;
		foreach ( ContentTypes::live()['fields'] as $list ) {
			foreach ( $list as $field ) {
				$this->field_types[ (string) $field['name'] ] ??= (string) $field['type'];
				if ( ! empty( $field['multiple'] ) && ! in_array( $field['type'], array( 'image', 'post', 'repeater' ), true ) ) {
					$this->multiple[ (string) $field['name'] ] = true;
				}
				if ( 'repeater' === $field['type'] ) {
					$this->repeaters[ (string) $field['name'] ] ??= self::by_name( $field['sub'] );
				}
			}
		}
	}

	/**
	 * Converts a document's blocks. The context is the one Histeon renders the document in (Context::from_globals()
	 * with the document as the global post, an anonymous visitor).
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public function convert( array $blocks, Context $context ): array {
		$dynamic = 'contenuto' !== $this->kind;
		return $this->walk(
			$blocks,
			array(
				'context' => $context,
				'post'    => $dynamic ? 'this' : null,
				'term'    => null,
				'link'    => null,
				'item'    => null,
				'voce'    => null,
				'riga'    => null,
				'righe'   => array(),
				'page'    => $dynamic,
				'fills'   => array(),
			),
			0
		);
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function walk( array $blocks, array $scope, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}
		$out = array();
		foreach ( $blocks as $block ) {
			$name     = (string) ( $block['blockName'] ?? '' );
			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			$uid      = (string) ( $attrs['uid'] ?? '' );
			$new      = match ( $name ) {
				'' => '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ? array() : array( $this->html( (string) $block['innerHTML'], $uid, 'html', __( 'HTML fuori dai blocchi', 'xb' ) ) ),
				'xb/element' => $this->element( $attrs, $children, $scope, $depth ),
				'xb/text' => $this->bare_text( $attrs, $scope ),
				'xb/loop' => $this->loop( $attrs, $children, $scope, $depth ),
				'xb/if' => $this->condition( $children, $scope, $depth ),
				'xb/branch', 'xb/slot-fill' => $this->walk( $children, $scope, $depth + 1 ),
				'xb/component' => $this->component( $attrs, $children, $scope, $depth ),
				'xb/slot' => $this->slot( $attrs, $scope, $depth ),
				'xb/raw' => array( $this->html( (string) ( $attrs['content'] ?? '' ), $uid, 'xb/raw', '' ) ),
				'xb/passthrough' => $this->foreign_list( $children, $scope, $depth ),
				'xb/post-content' => array( $this->make( 'fondamenta/contenuto', array(), array(), array() ) ),
				default => str_starts_with( $name, 'xb/' ) ? $this->lose( $uid, $name, __( 'blocco di una versione più recente di Histeon', 'xb' ) ) : array( $this->foreign( $block, $scope, $depth ) ),
			};
			array_push( $out, ...$new );
		}
		return $out;
	}

	// ------------------------------------------------------------------ elements

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function element( array $attrs, array $children, array $scope, int $depth ): array {
		$tag        = strtolower( (string) ( $attrs['tag'] ?? 'div' ) );
		$uid        = (string) ( $attrs['uid'] ?? '' );
		$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
		$static     = ! $this->moves( array( 'blockName' => 'xb/element', 'attrs' => $attrs, 'innerBlocks' => $children ), $scope );

		// A Histeon form made in the chosen form plugin (§16): that plugin's block in its place.
		if ( self::histeon_form( $tag, $attributes, $children ) ) {
			$form = Forms\Reader::read( array( 'blockName' => 'xb/element', 'attrs' => $attrs, 'innerBlocks' => $children ) );
			if ( isset( self::$forms[ $form['name'] ] ) ) {
				$block = self::$forms[ $form['name'] ];
				$this->count( (string) $block['blockName'] );
				return array( $block );
			}
		}
		if ( in_array( $tag, self::FROZEN, true ) || self::histeon_form( $tag, $attributes, $children ) ) {
			if ( ! $static ) {
				/* translators: %s: HTML tag */
				return $this->lose( $uid, $tag, sprintf( __( '<%s> con valori che cambiano: nessun blocco lo tiene', 'xb' ), $tag ) );
			}
			$reason = 'form' === $tag ? __( 'form di Histeon: resta com\'è ma non invierà più; va rifatto nel plugin dei form scelto', 'xb' ) : '';
			return array( $this->html( $this->render( array( 'blockName' => 'xb/element', 'attrs' => $attrs, 'innerBlocks' => $children ), $scope ), $uid, $tag, $reason ) );
		}

		// The shell: the element with its fixed attributes, rendered by Histeon; values that change become legami.
		$fixed  = array();
		$legami = array();
		foreach ( $attributes as $name => $value ) {
			$name  = (string) $name;
			$value = is_string( $value ) ? $this->substitute_value( $value ) : $value;
			if ( ! is_string( $value ) || ! $this->value_moves( $value, $scope ) ) {
				$fixed[ $name ] = $value;
				continue;
			}
			$parts = $this->parts( $value, $scope, false );
			if ( null === $parts ) {
				/* translators: 1: attribute, 2: expression */
				$this->note( $uid, $tag, 'perso', sprintf( __( 'attributo %1$s: {%2$s} non ha un legame; tolto', 'xb' ), $name, $this->failed ) );
				continue;
			}
			$legami[ $name ] = $parts;
		}
		$shell = $this->render(
			array(
				'blockName'   => 'xb/element',
				'attrs'       => array( 'uid' => $uid, 'tag' => $tag ) + ( array() !== $fixed ? array( 'attributes' => $fixed ) : array() ),
				'innerBlocks' => array(),
			),
			$scope
		);
		$end = strpos( $shell, '>' );
		if ( false === $end ) {
			/* translators: %s: HTML tag */
			return $this->lose( $uid, $tag, sprintf( __( '<%s> che Histeon non disegna', 'xb' ), $tag ) );
		}
		$open  = substr( $shell, 0, $end + 1 );
		$close = in_array( $tag, Spec::VOID_ELEMENTS, true ) ? '' : '</' . $tag . '>';
		$saved = self::attributes_of( $open );
		// In a pattern, an inline element holding a prop is a block of its own, so an instance can override it.
		$alone = 1 === count( $children ) && 'xb/text' === ( $children[0]['blockName'] ?? null );
		$rich  = self::rich( $children ) && ! ( null !== $this->overrides && ! $alone && str_contains( (string) wp_json_encode( $children ), '{props.' ) );

		// In a pattern: the text and the attributes an instance may override, and the name they go by.
		$over = array();
		if ( null !== $this->overrides ) {
			if ( 1 === count( $children ) && 'xb/text' === ( $children[0]['blockName'] ?? null ) && 1 === preg_match( '/^\{props\.([A-Za-z_]\w*)\}$/', (string) ( $children[0]['attrs']['content'] ?? '' ), $m ) ) {
				$over['contenuto'] = $m[1];
			}
			foreach ( array( 'href', 'src', 'alt', 'title' ) as $name ) {
				if ( is_string( $attributes[ $name ] ?? null ) && 1 === preg_match( '/^\{props\.([A-Za-z_]\w*)\}$/', $attributes[ $name ], $m ) ) {
					$over[ $name ] = $m[1];
				}
			}
		}
		// Text in core blocks: paragraphs, headings and lists that hold only text, with at most a class and an id.
		$plain = array() === $legami && array() === array_diff( array_keys( $saved ), array( 'class', 'id' ) );
		if ( $plain && $static && $rich && ( 'p' === $tag || in_array( $tag, self::HEADINGS, true ) ) ) {
			$text  = $this->render_list( $children, $scope );
			$attrs = self::core_class( $saved ) + ( 'p' === $tag ? array() : array( 'level' => (int) substr( $tag, 1 ) ) );
			if ( 2 === ( $attrs['level'] ?? 0 ) ) {
				unset( $attrs['level'] );
			}
			if ( isset( $over['contenuto'] ) ) {
				// Core text blocks take pattern overrides on their own `content`.
				$attrs = $this->overridable( $attrs, array( 'content' => $over['contenuto'] ), array() );
			}
			return array( $this->make( 'p' === $tag ? 'core/paragraph' : 'core/heading', $attrs, array(), array( $open . $text . $close ) ) );
		}
		if ( $plain && $static && in_array( $tag, array( 'ul', 'ol' ), true ) && array() !== $children && $this->list_items( $children ) ) {
			$items = array();
			foreach ( $children as $li ) {
				$li_open = $this->render( array( 'blockName' => 'xb/element', 'attrs' => $li['attrs'], 'innerBlocks' => array() ), $scope );
				$li_open = substr( $li_open, 0, (int) strpos( $li_open, '>' ) + 1 );
				$items[] = $this->make( 'core/list-item', self::core_class( self::attributes_of( $li_open ) ), array(), array( $li_open . $this->render_list( is_array( $li['innerBlocks'] ?? null ) ? array_values( $li['innerBlocks'] ) : array(), $scope ) . '</li>' ) );
			}
			return array( $this->make( 'core/list', self::core_class( $saved ) + ( 'ol' === $tag ? array( 'ordered' => true ) : array() ), $items, array_merge( array( $open ), array_fill( 0, count( $items ), null ), array( $close ) ) ) );
		}

		$block = array_filter(
			array(
				'tagName'  => 'div' === $tag ? null : $tag,
				'attributi' => array() === $saved ? null : array_map( null, array_keys( $saved ), array_values( $saved ) ),
				'legami'   => array() === $legami ? null : $legami,
			),
			static fn ( mixed $v ): bool => null !== $v
		);
		if ( array() !== $over ) {
			$block = $this->overridable( $block, $over, $saved );
		}
		if ( '' === $close ) {
			$block['modo'] = 'vuoto';
			return array( $this->make( 'fondamenta/elemento', $block, array(), array( $open ) ) );
		}
		// Rich text that changes holds parts only when it is text alone: an inline element with values that change
		// (a link to the post around its title) is a block of its own, inside this one.
		$text_only = array() === array_filter( $children, static fn ( array $c ): bool => 'xb/text' !== ( $c['blockName'] ?? null ) );
		if ( $rich && array() !== $children && ( $static || $text_only || ! $this->inline_moves( $children, $scope ) ) ) {
			$block['modo'] = 'testo';
			if ( $static ) {
				return array( $this->make( 'fondamenta/elemento', $block, array(), array( $open . $this->render_list( $children, $scope ) . $close ) ) );
			}
			$parts = $this->rich_parts( $children, $scope );
			if ( null === $parts ) {
				/* translators: %s: expression */
				$this->note( $uid, $tag, 'perso', sprintf( __( 'testo: {%s} non ha un legame; resta solo la parte fissa', 'xb' ), $this->failed ) );
				$parts = array();
			}
			$block['legami']['#testo'] = $parts;
			return array( $this->make( 'fondamenta/elemento', $block, array(), array( $open . $close ) ) );
		}
		$inner = $this->walk( $children, $scope, $depth + 1 );
		return array( $this->make( 'fondamenta/elemento', $block, $inner, array_merge( array( $open ), array_fill( 0, count( $inner ), null ), array( $close ) ) ) );
	}

	/**
	 * Text with no element around it, among other blocks.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<string, mixed> $scope
	 * @return list<array<string, mixed>>
	 */
	private function bare_text( array $attrs, array $scope ): array {
		$block = array(
			'blockName'   => 'xb/text',
			'attrs'       => $attrs,
			'innerBlocks' => array(),
		);
		if ( $this->moves( $block, $scope ) ) {
			$parts = $this->parts( $this->substitute_value( (string) ( $attrs['content'] ?? '' ) ), $scope, true );
			if ( null === $parts ) {
				return $this->lose( (string) ( $attrs['uid'] ?? '' ), 'xb/text', __( 'testo con valori che cambiano senza un elemento attorno', 'xb' ) );
			}
			return array( $this->make( 'fondamenta/testo', array( 'legami' => array( '#testo' => $parts ) ), array(), array() ) );
		}
		$html = $this->render( $block, $scope );
		if ( '' === $html ) {
			return array();
		}
		// A space between inline elements (`</li> <li>`) is part of how the line breaks: it stays, unnoted.
		return array( $this->html( $html, (string) ( $attrs['uid'] ?? '' ), 'xb/text', '' === trim( $html ) ? '' : __( 'testo senza un elemento attorno', 'xb' ) ) );
	}

	// ------------------------------------------------------------------ logic

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function loop( array $attrs, array $children, array $scope, int $depth ): array {
		$uid    = (string) ( $attrs['uid'] ?? '' );
		$as     = (string) ( $attrs['as'] ?? 'item' );
		$index  = isset( $attrs['index'] ) ? (string) $attrs['index'] : null;
		$source = is_array( $attrs['source'] ?? null ) ? $attrs['source'] : array();
		$else   = null;
		$last   = end( $children );
		if ( is_array( $last ) && 'xb/branch' === ( $last['blockName'] ?? null ) && ! isset( $last['attrs']['test'] ) ) {
			$else     = $last;
			$children = array_slice( $children, 0, -1 );
		}
		$loop = array(
			'blockName'   => 'xb/loop',
			'attrs'       => $attrs,
			'innerBlocks' => $children,
		);

		if ( 'preset' === ( $source['kind'] ?? null ) ) {
			$id   = (string) ( $source['id'] ?? '' );
			$args = array();
			foreach ( is_array( $source['args'] ?? null ) ? $source['args'] : array() as $name => $expression ) {
				$expression = $this->substitute_expr( (string) $expression );
				if ( $this->expr_moves( $expression, $scope ) ) {
					/* translators: %s: argument */
					$this->note( $uid, 'xb/loop', 'perso', sprintf( __( 'argomento «%s» calcolato dal contenuto mostrato: la query lo ignora', 'xb' ), (string) $name ) );
					continue;
				}
				$args[ (string) $name ] = $this->renderer->value( $expression, $scope['context'] );
			}
			$query = isset( $source['groupBy'] ) ? null : ( new \XB\Render\LoopPresets() )->query_args( $id, $args );
			if ( null === $query ) {
				return $this->freeze_loop( $loop, $scope, isset( $source['groupBy'] ) ? __( 'loop a gruppi', 'xb' ) : __( 'query di termini o di utenti', 'xb' ) );
			}
			$inner         = $scope;
			$inner['post'] = $as;
			$inner['term'] = null;
			$inner['link'] = null;
			$inner['item'] = null;
			$inner['voce'] = null;
			$inner['riga'] = null;
			if ( null !== $index ) {
				$this->note( $uid, 'xb/loop', 'perso', __( 'indice del loop: nessun blocco lo mostra', 'xb' ) );
			}
			return array( $this->loop_block( array( 'fonte' => $query['fonte'] ) + ( array() !== $query['query'] ? array( 'query' => $query['query'] ) : array() ), $children, $else, $inner, $scope, $depth ) );
		}

		$expr = $this->substitute_expr( (string) ( $source['expr'] ?? '' ) );
		if ( ! $this->expr_moves( $expr, $scope ) ) {
			return $this->unroll( $expr, $as, $index, $children, $else, $scope, $depth, $uid );
		}
		[ $root, $path ] = self::chain_of( $expr );
		// The terms of the page shown (`props.page.terms.category` in a template): as those of a post, read from the page.
		$of_page = false;
		$terms   = $path;
		if ( $scope['page'] && ( 'page' === $root || ( 'props' === $root && 'page' === ( $path[0] ?? '' ) ) ) ) {
			$terms   = 'props' === $root ? array_slice( $path, 1 ) : $path;
			$of_page = in_array( $terms[0] ?? '', array( 'categories', 'tags', 'terms' ), true );
		}
		if ( null !== $root && ( $of_page || $root === $scope['post'] ) && in_array( $terms[0] ?? '', array( 'categories', 'tags', 'terms' ), true ) ) {
			$taxonomy = match ( $terms[0] ) {
				'categories' => 'category',
				'tags' => 'post_tag',
				default => (string) ( $terms[1] ?? '' ),
			};
			if ( '' !== $taxonomy && count( $terms ) <= ( 'terms' === $terms[0] ? 2 : 1 ) ) {
				$inner         = $scope;
				$inner['term'] = $as;
				return array( $this->loop_block( array( 'fonte' => 'termini', 'tassonomia' => $taxonomy ) + ( $of_page ? array( 'pagina' => true ) : array() ), $children, $else, $inner, $scope, $depth ) );
			}
		}
		// The values of a field with more than one: of the item shown, or of the page.
		$own  = null !== $root && $root === $scope['post'] && 2 === count( $path ) && 'fields' === $path[0];
		$page = $scope['page'] && ( ( 'page' === $root && 2 === count( $path ) && 'fields' === $path[0] ) || ( 'props' === $root && 3 === count( $path ) && 'page' === $path[0] && 'fields' === $path[1] ) );
		if ( ( $own || $page ) && isset( $this->multiple[ (string) end( $path ) ] ) ) {
			$inner         = $scope;
			$inner['item'] = $as;
			return array( $this->loop_block( array( 'fonte' => 'campo', 'campo' => (string) end( $path ) ) + ( $page ? array( 'pagina' => true ) : array() ), $children, $else, $inner, $scope, $depth ) );
		}
		// A filtered list (the builder's facets): its results, the options of a filter, the active filters, its pages.
		$list = 'facets' === $root ? self::facet_list( $path ) : null;
		if ( null !== $list ) {
			$inner         = $scope;
			$inner['post'] = 'risultati' === $list['elenco'] ? $as : null;
			$inner['voce'] = 'risultati' === $list['elenco'] ? null : $as;
			$inner['term'] = null;
			$inner['link'] = null;
			$inner['item'] = null;
			$inner['riga'] = null;
			return array( $this->loop_block( array( 'fonte' => 'filtri' ) + $list, $children, $else, $inner, $scope, $depth ) );
		}
		// The rows of a repeater: of the item shown, of the page, or of a repeater in the row around.
		$rows = null;
		if ( ( $own || $page ) && isset( $this->repeaters[ (string) end( $path ) ] ) ) {
			$rows = array( array( 'campo' => (string) end( $path ) ) + ( $page ? array( 'pagina' => true ) : array() ), $this->repeaters[ (string) end( $path ) ] );
		} elseif ( null !== $root && $root === $scope['riga'] && 1 === count( $path ) && 'repeater' === ( $scope['righe'][ $path[0] ]['type'] ?? '' ) ) {
			$rows = array(
				array(
					'campo'  => $path[0],
					'dentro' => true,
				),
				self::by_name( is_array( $scope['righe'][ $path[0] ]['sub'] ?? null ) ? $scope['righe'][ $path[0] ]['sub'] : array() ),
			);
		}
		if ( null !== $rows ) {
			$inner          = $scope;
			$inner['riga']  = $as;
			$inner['righe'] = $rows[1];
			if ( null !== $index ) {
				$this->note( $uid, 'xb/loop', 'perso', __( 'indice del loop: nessun blocco lo mostra', 'xb' ) );
			}
			return array( $this->loop_block( array( 'fonte' => 'righe' ) + $rows[0], $children, $else, $inner, $scope, $depth ) );
		}
		$page_links = ( 'page' === $root && array( 'pageLinks' ) === $path ) || ( 'props' === $root && array( 'page', 'pageLinks' ) === $path );
		if ( $page_links && $scope['page'] ) {
			$inner         = $scope;
			$inner['link'] = $as;
			return array( $this->loop_block( array( 'fonte' => 'pagine' ), $children, $else, $inner, $scope, $depth ) );
		}
		/* translators: %s: expression */
		return $this->lose( $uid, 'xb/loop', sprintf( __( 'loop su {%s}, che cambia da contenuto a contenuto: arriverà con il plugin dei campi scelto', 'xb' ), $expr ) );
	}

	/**
	 * @param array<string, mixed>       $attributes
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>|null  $else
	 * @param array<string, mixed>       $inner Scope of the items.
	 * @param array<string, mixed>       $scope Scope of the loop.
	 * @return array<string, mixed>
	 */
	private function loop_block( array $attributes, array $children, ?array $else, array $inner, array $scope, int $depth ): array {
		$blocks = $this->walk( $children, $inner, $depth + 1 );
		if ( null !== $else ) {
			$empty    = $this->walk( is_array( $else['innerBlocks'] ?? null ) ? array_values( $else['innerBlocks'] ) : array(), $scope, $depth + 1 );
			$blocks[] = $this->make( 'fondamenta/altrimenti', array(), $empty, array_fill( 0, count( $empty ), null ) );
		}
		return $this->make( 'fondamenta/loop', $attributes, $blocks, array_fill( 0, count( $blocks ), null ) );
	}

	/**
	 * A list whose items are known now (written in the page, from a data source): one copy of the blocks per item.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>|null  $else
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function unroll( string $expr, string $as, ?string $index, array $children, ?array $else, array $scope, int $depth, string $uid ): array {
		$items = $this->renderer->items(
			array(
				'kind' => 'expr',
				'expr' => $expr,
			),
			$scope['context']
		);
		if ( array() === $items && null !== $else ) {
			return $this->walk( is_array( $else['innerBlocks'] ?? null ) ? array_values( $else['innerBlocks'] ) : array(), $scope, $depth + 1 );
		}
		$out   = array();
		$count = 0;
		foreach ( $items as $key => $item ) {
			if ( ++$count > self::MAX_UNROLL ) {
				break;
			}
			$inner            = $scope;
			$inner['context'] = $scope['context']->with( array( $as => $item ) + ( null !== $index ? array( $index => $key ) : array() ) );
			array_push( $out, ...$this->walk( $children, $inner, $depth + 1 ) );
		}
		/* translators: %s: expression */
		$this->note( $uid, 'xb/loop', 'srotolato', sprintf( __( 'lista {%s} srotolata: una copia per elemento, valori scritti', 'xb' ), $expr ) );
		return $out;
	}

	/**
	 * @param array<string, mixed> $loop
	 * @param array<string, mixed> $scope
	 * @return list<array<string, mixed>>
	 */
	private function freeze_loop( array $loop, array $scope, string $what ): array {
		$uid = (string) ( $loop['attrs']['uid'] ?? '' );
		if ( $this->moves( $loop, $scope ) ) {
			/* translators: %s: kind of loop */
			return $this->lose( $uid, 'xb/loop', sprintf( __( '%s con valori che cambiano: nessun blocco lo fa', 'xb' ), $what ) );
		}
		/* translators: %s: kind of loop */
		return array( $this->html( $this->render( $loop, $scope ), $uid, 'xb/loop', sprintf( __( '%s: congelato, non segue i cambi', 'xb' ), $what ) ) );
	}

	/**
	 * @param list<array<string, mixed>> $branches
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function condition( array $branches, array $scope, int $depth ): array {
		$tests = array();
		$fixed = true;
		foreach ( $branches as $branch ) {
			$test    = isset( $branch['attrs']['test'] ) ? $this->substitute_expr( (string) $branch['attrs']['test'] ) : null;
			$tests[] = $test;
			$fixed   = $fixed && ( null === $test || ! $this->expr_moves( $test, $scope ) );
		}
		if ( $fixed ) {
			// Decided now, as an anonymous visitor of this page.
			foreach ( $branches as $i => $branch ) {
				if ( null === $tests[ $i ] || $this->renderer->test( $tests[ $i ], $scope['context'] ) ) {
					return $this->walk( is_array( $branch['innerBlocks'] ?? null ) ? array_values( $branch['innerBlocks'] ) : array(), $scope, $depth + 1 );
				}
			}
			return array();
		}
		$first  = $branches[0];
		$legame = null === $tests[0] ? null : $this->test_of( $tests[0], $scope );
		$plain  = count( $branches ) <= 2 && ( 1 === count( $branches ) || null === $tests[1] );
		if ( null === $legame || ! $plain ) {
			// What no block decides: the {:else} branch stays (the usual case, such as a menu item that is not the
			// current one), or nothing.
			$last = end( $branches );
			$else = isset( $last['attrs']['test'] ) ? null : $last;
			/* translators: %s: test */
			$this->note( (string) ( $first['attrs']['uid'] ?? '' ), 'xb/if', 'perso', sprintf( null === $else ? __( 'condizione {%s}: nessun blocco la decide; il suo contenuto è tolto', 'xb' ) : __( 'condizione {%s}: nessun blocco la decide; resta il ramo {:else}', 'xb' ), (string) $tests[0] ) );
			return null === $else ? array() : $this->walk( is_array( $else['innerBlocks'] ?? null ) ? array_values( $else['innerBlocks'] ) : array(), $scope, $depth + 1 );
		}
		$inside = $this->walk( is_array( $first['innerBlocks'] ?? null ) ? array_values( $first['innerBlocks'] ) : array(), $scope, $depth + 1 );
		$out = array( $this->make( 'fondamenta/se', $legame, $inside, array_fill( 0, count( $inside ), null ) ) );
		if ( 2 === count( $branches ) ) {
			// The {:else}: the same test, turned round.
			$other = $this->walk( is_array( $branches[1]['innerBlocks'] ?? null ) ? array_values( $branches[1]['innerBlocks'] ) : array(), $scope, $depth + 1 );
			$out[] = $this->make( 'fondamenta/se', self::opposite( $legame ), $other, array_fill( 0, count( $other ), null ) );
		}
		return $out;
	}

	/**
	 * A test fondamenta/se can decide, as its attributes: a named value (`post.excerpt`), its negation
	 * (`!post.image`), one comparison with a written value (`props.page.totalPages > 1`), or comparisons joined with
	 * `||` and `&&` (`url.path == "/festival/" || url.path.includes("/scrittore/")`). Null for anything else.
	 *
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>|null
	 */
	private function test_of( string $test, array $scope ): ?array {
		try {
			$node = Parser::parse( $test );
		} catch ( SyntaxError ) {
			return null;
		}
		$groups = array();
		foreach ( self::split_op( $node, '||' ) as $or ) {
			$group = array();
			foreach ( self::split_op( $or, '&&' ) as $and ) {
				$one = $this->simple_test( $and, $scope );
				if ( null === $one ) {
					return null;
				}
				$group[] = $one;
			}
			$groups[] = $group;
		}
		if ( 1 === count( $groups ) && 1 === count( $groups[0] ) ) {
			$one = $groups[0][0];
			$out = array( 'legame' => array( 'chiave' => $one['chiave'] ) );
			if ( 'pieno' !== $one['confronto'] ) {
				$out += array(
					'confronto' => $one['confronto'],
					'valore'    => $one['valore'],
				);
			}
			return $out + ( empty( $one['nega'] ) ? array() : array( 'vuoto' => true ) );
		}
		return array( 'oppure' => $groups );
	}

	/**
	 * The operands of a chain of one operator: `a || b || c` → a, b, c.
	 *
	 * @param array<string, mixed> $node
	 * @return list<array<string, mixed>>
	 */
	private static function split_op( array $node, string $op ): array {
		if ( 'Binary' === ( $node['type'] ?? '' ) && $op === ( $node['op'] ?? '' ) && is_array( $node['left'] ?? null ) && is_array( $node['right'] ?? null ) ) {
			return array( ...self::split_op( $node['left'], $op ), ...self::split_op( $node['right'], $op ) );
		}
		return array( $node );
	}

	/**
	 * One test: a named value, a comparison with a literal, `.includes(literal)`, each possibly negated with `!`.
	 *
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 * @return array{chiave: string, confronto: string, valore: string, nega?: bool}|null
	 */
	private function simple_test( array $node, array $scope ): ?array {
		$negate = false;
		while ( 'Unary' === ( $node['type'] ?? '' ) && '!' === ( $node['op'] ?? '' ) && is_array( $node['argument'] ?? null ) ) {
			$negate = ! $negate;
			$node   = $node['argument'];
		}
		$compare = 'pieno';
		$written = '';
		if ( 'Binary' === ( $node['type'] ?? '' ) && in_array( $node['op'] ?? '', array( '==', '!=', '>', '>=', '<', '<=' ), true ) ) {
			$left  = is_array( $node['left'] ?? null ) ? $node['left'] : array();
			$right = is_array( $node['right'] ?? null ) ? $node['right'] : array();
			$flip  = array( '>' => '<', '>=' => '<=', '<' => '>', '<=' => '>=', '==' => '==', '!=' => '!=' );
			if ( 'Literal' === ( $right['type'] ?? '' ) && is_scalar( $right['value'] ?? null ) ) {
				[ $node, $compare, $written ] = array( $left, (string) $node['op'], $right['value'] );
			} elseif ( 'Literal' === ( $left['type'] ?? '' ) && is_scalar( $left['value'] ?? null ) ) {
				[ $node, $compare, $written ] = array( $right, $flip[ (string) $node['op'] ], $left['value'] );
			} else {
				return null;
			}
		} elseif ( 'Call' === ( $node['type'] ?? '' ) && 'includes' === ( $node['callee']['property'] ?? '' ) && is_array( $node['callee']['object'] ?? null ) && 1 === count( (array) ( $node['args'] ?? array() ) ) && 'Literal' === ( $node['args'][0]['value']['type'] ?? '' ) && is_string( $node['args'][0]['value']['value'] ?? null ) ) {
			[ $node, $compare, $written ] = array( $node['callee']['object'], 'contiene', $node['args'][0]['value']['value'] );
		}
		[ $root, $path, $calls ] = Analyzer::calls( $node );
		if ( null === $root || array() !== $calls ) {
			return null;
		}
		$key = $this->key( $root . ( array() === $path ? '' : '.' . implode( '.', $path ) ), $scope );
		if ( null === $key || array() !== $key['modi'] ) {
			return null;
		}
		return array(
			'chiave'    => $key['chiave'],
			'confronto' => $compare,
			'valore'    => is_bool( $written ) ? ( $written ? 'true' : 'false' ) : (string) $written,
		) + ( $negate ? array( 'nega' => true ) : array() );
	}

	/**
	 * The attributes of fondamenta/se for the opposite test (the {:else} of a condition).
	 *
	 * @param array<string, mixed> $se
	 * @return array<string, mixed>
	 */
	private static function opposite( array $se ): array {
		if ( isset( $se['vuoto'] ) ) {
			unset( $se['vuoto'] );
			return $se;
		}
		return $se + array( 'vuoto' => true );
	}

	/**
	 * A component instance, expanded: its definition's blocks with this instance's props and slots.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function component( array $attrs, array $children, array $scope, int $depth ): array {
		$uid = (string) ( $attrs['uid'] ?? '' );
		$ref = (string) ( $attrs['ref'] ?? '' );
		// Props that change from one use to another are read where the definition uses them: `{props.titolo}` → `{x.title}`.
		$computed = array();
		foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $name => $value ) {
			if ( is_array( $value ) && ( isset( $value['expr'] ) || isset( $value['tpl'] ) ) ) {
				$source = isset( $value['expr'] ) ? '{' . $this->substitute_expr( (string) $value['expr'] ) . '}' : $this->substitute_value( (string) $value['tpl'] );
				if ( $this->value_moves( $source, $scope ) ) {
					$computed[ (string) $name ] = $source;
				}
			}
		}
		$found = $this->renderer->component_scope( $attrs, $children, $scope['context'] );
		if ( is_string( $found ) ) {
			return $this->lose( $uid, 'xb/component', $found );
		}
		// D3 of doc 25: a synced pattern where the component can be one, with this instance's values as overrides.
		$pattern = array() === $computed && null === $this->overrides ? self::pattern( $ref, $children ) : null;
		if ( null !== $pattern ) {
			$content = array();
			foreach ( $pattern['map'] as $name => $fields ) {
				foreach ( $fields as $field => $prop ) {
					if ( ! array_key_exists( $prop, is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() ) ) {
						continue;
					}
					$content[ $name ][ $field ] = in_array( $field, array( 'contenuto', 'content' ), true )
						? $this->render_text( '{props.' . $prop . '}', array( 'context' => $found['context'] ) + $scope )
						: Value::to_string( $found['context']->scope['props'][ $prop ] ?? null );
				}
			}
			return array( $this->make( 'core/block', array( 'ref' => $pattern['id'] ) + ( array() === $content ? array() : array( 'content' => $content ) ), array(), array() ) );
		}
		$fills = array();
		foreach ( $children as $fill ) {
			if ( 'xb/slot-fill' === ( $fill['blockName'] ?? null ) ) {
				$fills[ (string) ( $fill['attrs']['name'] ?? Spec::DEFAULT_SLOT ) ] = is_array( $fill['innerBlocks'] ?? null ) ? array_values( $fill['innerBlocks'] ) : array();
			}
		}
		$outer            = $this->computed;
		$this->computed   = $computed;
		$inner            = $scope;
		$inner['context'] = $found['context'];
		$inner['fills']   = array(
			'blocks'   => $fills,
			'scope'    => $scope,
			'computed' => $outer,
		);
		try {
			return $this->walk( $found['blocks'], $inner, $depth + 1 );
		} finally {
			$this->computed = $outer;
		}
	}

	/**
	 * The synced pattern a component becomes, converted once per request; null when it is expanded instead: not a
	 * pattern for the report's rules (slots, props in classes, conditions or loops: Analyzer::expand_reason()), props
	 * used other than as a whole text or a whole link, image or title, values of the page shown, or a definition
	 * that is not a wp_block post.
	 *
	 * @param list<array<string, mixed>> $fills
	 * @return array{id: int, blocks: list<array<string, mixed>>, map: array<string, array<string, string>>}|null
	 */
	public static function pattern( string $ref, array $fills = array() ): ?array {
		if ( array() !== $fills ) {
			return null;
		}
		if ( ! array_key_exists( $ref, self::$patterns ) ) {
			self::$patterns[ $ref ] = false;
			$definition             = Plugin::instance()->components->find( $ref );
			if ( null !== $definition && $definition['id'] > 0 && 'wp_block' === get_post_type( $definition['id'] ) && null === Analyzer::expand_reason( $ref, $definition['blocks'], array() ) && self::pattern_props( $definition['blocks'] ) ) {
				$converter = new self( 'componente' );
				$found     = $converter->renderer->component_scope( array( 'ref' => $ref ), array(), Context::from_globals() );
				if ( ! is_string( $found ) ) {
					$converter->overrides = array();
					$blocks               = $converter->walk(
						$found['blocks'],
						array(
							'context' => $found['context'],
							'post'    => null,
							'term'    => null,
							'link'    => null,
							'item'    => null,
							'voce'    => null,
							'riga'    => null,
							'righe'   => array(),
							'page'    => false,
							'fills'   => array(),
						),
						0
					);
					self::$patterns[ $ref ] = array(
						'id'     => $definition['id'],
						'blocks' => $blocks,
						'map'    => $converter->overrides,
					);
				}
			}
		}
		$pattern = self::$patterns[ $ref ];
		return false === $pattern ? null : $pattern;
	}

	/**
	 * The patterns converted so far, by the post that defines them.
	 *
	 * @return array<int, list<array<string, mixed>>>
	 */
	public static function converted_patterns(): array {
		$out = array();
		foreach ( self::$patterns as $pattern ) {
			if ( false !== $pattern ) {
				$out[ $pattern['id'] ] = $pattern['blocks'];
			}
		}
		return $out;
	}

	/** Forgets the patterns converted in this request (tests, a new conversion). */
	public static function forget_patterns(): void {
		self::$patterns = array();
	}

	/**
	 * Whether a definition uses its props only where an instance can override them, and reads nothing of the page
	 * it is on: then one pattern serves every instance.
	 *
	 * @param list<array<string, mixed>> $blocks
	 */
	public static function pattern_props( array $blocks ): bool {
		$whole = static fn ( string $v ): bool => 1 === preg_match( '/^\{props\.[A-Za-z_]\w*\}$/', $v );
		$reads = static fn ( string $v ): bool => str_contains( $v, '{' ) && 1 === preg_match( '/\b(props|this|page|url|user)\b/', $v );
		foreach ( $blocks as $block ) {
			$name     = (string) ( $block['blockName'] ?? '' );
			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			if ( 'xb/element' === $name ) {
				foreach ( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() as $attribute => $value ) {
					if ( is_string( $value ) && $reads( $value ) && ! ( in_array( $attribute, array( 'href', 'src', 'alt', 'title' ), true ) && $whole( $value ) ) ) {
						return false;
					}
				}
				// A text that is a prop is overridable only as the element's whole text.
				if ( 1 === count( $children ) && 'xb/text' === ( $children[0]['blockName'] ?? null ) && $whole( (string) ( $children[0]['attrs']['content'] ?? '' ) ) ) {
					continue;
				}
			} elseif ( 'xb/text' === $name && $reads( (string) ( $attrs['content'] ?? '' ) ) ) {
				return false;
			} elseif ( in_array( $name, array( 'xb/branch', 'xb/loop', 'xb/component' ), true ) && $reads( (string) wp_json_encode( $attrs ) ) ) {
				return false;
			}
			if ( ! self::pattern_props( $children ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An element of a pattern that an instance may override: its name, a binding to the pattern's overrides, and
	 * the default values of its overridable attributes as attributes of the block.
	 *
	 * @param array<string, mixed>  $block
	 * @param array<string, string> $over  Attribute → prop.
	 * @param array<string, string> $saved The element's attributes as Histeon drew them.
	 * @return array<string, mixed>
	 */
	private function overridable( array $block, array $over, array $saved ): array {
		$name = implode( ' · ', array_values( array_unique( $over ) ) );
		$base = $name;
		for ( $i = 2; isset( $this->overrides[ $name ] ); $i++ ) {
			$name = $base . ' ' . $i;
		}
		$this->overrides[ $name ] = $over;
		foreach ( array_keys( $over ) as $field ) {
			if ( ! in_array( $field, array( 'contenuto', 'content' ), true ) && isset( $saved[ $field ] ) ) {
				$block[ $field ] = $saved[ $field ];
			}
		}
		$block['metadata'] = array(
			'name'     => $name,
			'bindings' => array( '__default' => array( 'source' => 'core/pattern-overrides' ) ),
		);
		return $block;
	}

	/**
	 * A slot of the component being expanded: the instance's blocks, read in the instance's scope.
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<string, mixed> $scope
	 * @return list<array<string, mixed>>
	 */
	private function slot( array $attrs, array $scope, int $depth ): array {
		$fills  = is_array( $scope['fills'] ) ? $scope['fills'] : array();
		$blocks = $fills['blocks'][ (string) ( $attrs['name'] ?? Spec::DEFAULT_SLOT ) ] ?? array();
		if ( array() === $blocks || ! is_array( $fills['scope'] ?? null ) ) {
			return array();
		}
		$inside         = $this->computed;
		$this->computed = is_array( $fills['computed'] ?? null ) ? $fills['computed'] : array();
		try {
			return $this->walk( $blocks, $fills['scope'], $depth + 1 );
		} finally {
			$this->computed = $inside;
		}
	}

	/**
	 * A block of Gutenberg (in a passthrough or left by an import): kept, with Histeon blocks inside it converted.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 * @return list<array<string, mixed>>
	 */
	private function foreign_list( array $children, array $scope, int $depth ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( '' !== (string) ( $child['blockName'] ?? '' ) ) {
				$out[] = $this->foreign( $child, $scope, $depth );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function foreign( array $block, array $scope, int $depth ): array {
		// A part names the theme it was read from (WordPress writes it in while loading a template): the theme the
		// site switches to must find its own part of that name.
		if ( 'core/template-part' === ( $block['blockName'] ?? null ) && is_array( $block['attrs'] ?? null ) ) {
			unset( $block['attrs']['theme'] );
		}
		$inner = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
		if ( array() === $inner || ! str_contains( (string) wp_json_encode( $inner ), '"blockName":"xb\\/' ) ) {
			$this->count( (string) $block['blockName'] );
			return $block;
		}
		// Each inner block may become several: the placeholders of the parent's HTML follow.
		$blocks  = array();
		$content = array();
		$i       = 0;
		foreach ( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array() as $piece ) {
			if ( null !== $piece ) {
				$content[] = $piece;
				continue;
			}
			$converted = isset( $inner[ $i ] ) ? $this->walk( array( $inner[ $i ] ), $scope, $depth + 1 ) : array();
			++$i;
			foreach ( $converted as $one ) {
				$blocks[]  = $one;
				$content[] = null;
			}
		}
		$block['innerBlocks']  = $blocks;
		$block['innerContent'] = $content;
		$this->count( (string) $block['blockName'] );
		return $block;
	}

	// ------------------------------------------------------------------ values

	/**
	 * A text or attribute value as legame parts: written parts as Histeon prints them, values that change by name.
	 * Null when a value that changes has no name.
	 *
	 * @param array<string, mixed> $scope
	 * @return list<string|array{chiave: string, modi?: list<string>}>|null
	 */
	private function parts( string $value, array $scope, bool $html ): ?array {
		try {
			$split = Template::split( $value );
		} catch ( SyntaxError ) {
			return null;
		}
		$out = array();
		foreach ( $split as [ $kind, $text ] ) {
			if ( Template::LITERAL === $kind ) {
				$out[] = $html ? $this->render_text( self::escape_braces( $text ), $scope ) : $text;
				continue;
			}
			if ( ! $this->expr_moves( $text, $scope ) ) {
				$out[] = $html ? $this->render_text( '{' . $text . '}', $scope ) : Value::to_string( $this->renderer->value( $text, $scope['context'] ) );
				continue;
			}
			$key = $this->key( $text, $scope );
			if ( null === $key ) {
				$this->failed = $text;
				return null;
			}
			$out[] = array() === $key['modi'] ? array( 'chiave' => $key['chiave'] ) : $key;
		}
		// Adjacent written parts as one.
		$merged = array();
		foreach ( $out as $part ) {
			$last = array_key_last( $merged );
			if ( is_string( $part ) && null !== $last && is_string( $merged[ $last ] ) ) {
				$merged[ $last ] .= $part;
			} elseif ( '' !== $part ) {
				$merged[] = $part;
			}
		}
		return $merged;
	}

	/**
	 * The parts of rich text that changes: text blocks and inline elements that do not change themselves.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 * @return list<string|array<string, mixed>>|null
	 */
	private function rich_parts( array $children, array $scope ): ?array {
		$out = array();
		foreach ( $children as $child ) {
			if ( ! $this->moves( $child, $scope ) ) {
				$out[] = $this->render( $child, $scope );
				continue;
			}
			if ( 'xb/text' !== ( $child['blockName'] ?? null ) ) {
				$this->failed = (string) ( $child['attrs']['tag'] ?? '' );
				return null;
			}
			$parts = $this->parts( $this->substitute_value( (string) ( $child['attrs']['content'] ?? '' ) ), $scope, true );
			if ( null === $parts ) {
				return null;
			}
			array_push( $out, ...$parts );
		}
		return $out;
	}

	/**
	 * Whether an inline element among rich children reads values that change.
	 *
	 * @param list<array<string, mixed>> $children
	 * @param array<string, mixed>       $scope
	 */
	private function inline_moves( array $children, array $scope ): bool {
		foreach ( $children as $child ) {
			if ( 'xb/text' !== ( $child['blockName'] ?? null ) && $this->moves( $child, $scope ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The legame key of an expression that changes: a value of the post, term or page link being shown, or of the
	 * page an archive template shows. Null when fondamenta_valore() has no name for it.
	 *
	 * @param array<string, mixed> $scope
	 * @return array{chiave: string, modi: list<string>}|null
	 */
	private function key( string $source, array $scope ): ?array {
		try {
			$node = Parser::parse( $source );
		} catch ( SyntaxError ) {
			return null;
		}
		return $this->key_of( $node, $scope );
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 * @return array{chiave: string, modi: list<string>}|null
	 */
	private function key_of( array $node, array $scope ): ?array {
		// `x ? "testo" : null`: the text when the value is there (`aria-current={p.current ? "page" : null}`).
		if ( 'Conditional' === ( $node['type'] ?? '' ) && is_array( $node['test'] ?? null ) ) {
			$then = is_array( $node['consequent'] ?? null ) ? $node['consequent'] : array();
			$else = is_array( $node['alternate'] ?? null ) ? $node['alternate'] : array();
			if ( 'Literal' !== ( $then['type'] ?? '' ) || ! is_string( $then['value'] ?? null ) || 'Literal' !== ( $else['type'] ?? '' ) || ! in_array( $else['value'] ?? null, array( null, '' ), true ) ) {
				return null;
			}
			$test = $this->key_of( $node['test'], $scope );
			return null === $test || array() !== $test['modi'] ? null : array(
				'chiave' => $test['chiave'],
				'modi'   => array( 'se:' . $then['value'] ),
			);
		}
		[ $root, $path, $calls ] = Analyzer::calls( $node );
		if ( null === $root ) {
			return null;
		}
		$modi = array();
		foreach ( $calls as $call ) {
			$modo = self::modo( $call );
			if ( null === $modo ) {
				return null;
			}
			$modi[] = $modo;
		}
		if ( $root === $scope['post'] && in_array( $path[0] ?? '', self::POST_KEYS, true ) ) {
			$path[0] = match ( $path[0] ) {
				'publishedAt' => 'date',
				'updatedAt' => 'modified',
				default => $path[0],
			};
			if ( 'image' === $path[0] && 1 === count( $path ) ) {
				$path[] = 'url';
			}
			if ( 'author' === $path[0] && 1 === count( $path ) ) {
				$path[] = 'name';
			}
			if ( 'fields' === $path[0] && 2 === count( $path ) && 'image' === ( $this->field_types[ $path[1] ] ?? '' ) ) {
				$path[] = 'url';
			}
			if ( count( $path ) > 3 || ( 'fields' === $path[0] && count( $path ) < 2 ) ) {
				return null;
			}
			return array( 'chiave' => implode( '.', $path ), 'modi' => $modi );
		}
		if ( $root === $scope['term'] && 1 === count( $path ) && in_array( $path[0], self::TERM_KEYS, true ) ) {
			return array( 'chiave' => 'term.' . ( 'label' === $path[0] ? 'name' : $path[0] ), 'modi' => $modi );
		}
		if ( 'url' === $root && array( 'path' ) === $path ) {
			return array( 'chiave' => 'url.path', 'modi' => $modi );
		}
		if ( $root === $scope['item'] && array() === $path ) {
			return array( 'chiave' => 'item', 'modi' => $modi );
		}
		if ( 'facets' === $root && self::facet_value( $path ) ) {
			return array( 'chiave' => 'facet.' . implode( '.', $path ), 'modi' => $modi );
		}
		if ( $root === $scope['voce'] && 1 === count( $path ) ) {
			return array( 'chiave' => 'voce.' . $path[0], 'modi' => $modi );
		}
		// A value of the row of a repeater; an image of the row by its parts.
		$sub = $root === $scope['riga'] ? ( $scope['righe'][ $path[0] ?? '' ] ?? null ) : null;
		if ( is_array( $sub ) && 'repeater' !== $sub['type'] ) {
			if ( 'image' === $sub['type'] && count( $path ) <= 2 ) {
				return array( 'chiave' => 'riga.' . $path[0] . '.' . ( $path[1] ?? 'url' ), 'modi' => $modi );
			}
			return 1 === count( $path ) ? array( 'chiave' => 'riga.' . $path[0], 'modi' => $modi ) : null;
		}
		if ( $root === $scope['link'] && 1 === count( $path ) && in_array( $path[0], self::LINK_KEYS, true ) ) {
			return array( 'chiave' => 'link.' . $path[0], 'modi' => $modi );
		}
		$page = 'props' === $root && 'page' === ( $path[0] ?? '' ) ? array_slice( $path, 1 ) : ( 'page' === $root ? $path : null );
		if ( $scope['page'] && null !== $page && in_array( $page, array( array( 'title' ), array( 'name' ) ), true ) ) {
			return array( 'chiave' => 'page.title', 'modi' => $modi );
		}
		if ( $scope['page'] && null !== $page && 1 === count( $page ) && in_array( $page[0], array( 'page', 'totalPages', 'totalRecords', 'prevUrl', 'nextUrl' ), true ) ) {
			return array( 'chiave' => 'page.' . $page[0], 'modi' => $modi );
		}
		// The page shown, not the item of a loop: its fields, slug, address…, read from the queried object.
		if ( $scope['page'] && null !== $page && in_array( $page[0] ?? '', array( 'id', 'slug', 'url', 'type', 'excerpt', 'date', 'modified', 'image', 'fields', 'categories', 'tags', 'terms' ), true ) && count( $page ) <= 3 ) {
			if ( 'image' === $page[0] && 1 === count( $page ) ) {
				$page[] = 'url';
			}
			if ( 'fields' === $page[0] && 2 === count( $page ) && 'image' === ( $this->field_types[ $page[1] ] ?? '' ) ) {
				$page[] = 'url';
			}
			if ( 'fields' === $page[0] && count( $page ) < 2 ) {
				return null;
			}
			return array( 'chiave' => 'page.' . implode( '.', $page ), 'modi' => $modi );
		}
		return null;
	}

	/**
	 * Fields by name.
	 *
	 * @param array<mixed> $fields
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_name( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && is_string( $field['name'] ?? null ) ) {
				$out[ $field['name'] ] = $field + array( 'type' => 'text' );
			}
		}
		return $out;
	}

	/**
	 * A list of a filtered list (`facets.<nome>.…`) that fondamenta/loop repeats: its results, the options of one
	 * filter, the active filters, the page links, the hidden fields. Null for anything else.
	 *
	 * @param list<string> $path The path after `facets`.
	 * @return array{facet: string, elenco: string, filtro?: string}|null
	 */
	public static function facet_list( array $path ): ?array {
		$name = (string) ( $path[0] ?? '' );
		$rest = array_slice( $path, 1 );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,30}$/', $name ) ) {
			return null;
		}
		$lists = array(
			'items'     => 'risultati',
			'active'    => 'attivi',
			'pageLinks' => 'pagine',
			'hidden'    => 'nascosti',
		);
		if ( 1 === count( $rest ) && isset( $lists[ $rest[0] ] ) ) {
			return array(
				'facet'  => $name,
				'elenco' => $lists[ $rest[0] ],
			);
		}
		if ( 3 === count( $rest ) && 'filters' === $rest[0] && 'options' === $rest[2] ) {
			return array(
				'facet'  => $name,
				'elenco' => 'opzioni',
				'filtro' => $rest[1],
			);
		}
		return null;
	}

	/**
	 * Whether a value of a filtered list has a name in fondamenta_valore() (`facet.<nome>.…`): the counts, the links,
	 * the chosen values, and the label, name and value of a filter.
	 *
	 * @param list<string> $path The path after `facets`.
	 */
	public static function facet_value( array $path ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,30}$/', (string) ( $path[0] ?? '' ) ) ) {
			return false;
		}
		$rest = array_slice( $path, 1 );
		return ( 1 === count( $rest ) && in_array( $rest[0], array( 'total', 'page', 'pages', 'perPage', 'pageKey', 'action', 'resetUrl', 'prevUrl', 'nextUrl', 'hasFilters' ), true ) )
			|| ( 2 === count( $rest ) && 'values' === $rest[0] )
			|| ( 3 === count( $rest ) && 'filters' === $rest[0] && in_array( $rest[2], array( 'kind', 'key', 'label', 'name', 'multiple', 'value', 'min', 'max' ), true ) );
	}

	/**
	 * A modifier as a format of fondamenta_valore().
	 *
	 * @param array{0: string, 1: list<mixed>} $call
	 */
	private static function modo( array $call ): ?string {
		[ $name, $args ] = $call;
		$first           = $args[0] ?? null;
		return match ( true ) {
			'date' === $name && ( null === $first || is_string( $first ) ) => 'date:' . (string) $first,
			'truncateWords' === $name && is_numeric( $first ) && ! isset( $args[1] ) => 'words:' . (int) $first,
			'join' === $name && ( null === $first || is_string( $first ) ) => 'join:' . ( $first ?? ', ' ),
			'slice' === $name && is_numeric( $first ) && ( ! isset( $args[1] ) || is_numeric( $args[1] ) ) => 'slice:' . (int) $first . ( isset( $args[1] ) ? ':' . (int) $args[1] : '' ),
			in_array( $name, array( 'upper', 'lower' ), true ) && array() === $args => $name,
			default => null,
		};
	}

	/**
	 * Whether a block reads anything that changes here (deep).
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $scope
	 */
	private function moves( array $block, array $scope ): bool {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		switch ( $block['blockName'] ?? null ) {
			case 'xb/text':
				if ( empty( $attrs['verbatim'] ) && $this->value_moves( $this->substitute_value( (string) ( $attrs['content'] ?? '' ) ), $scope ) ) {
					return true;
				}
				break;
			case 'xb/element':
				foreach ( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() as $value ) {
					if ( is_string( $value ) && $this->value_moves( $value, $scope ) ) {
						return true;
					}
				}
				break;
			case 'xb/branch':
				if ( isset( $attrs['test'] ) && $this->expr_moves( $this->substitute_expr( (string) $attrs['test'] ), $scope ) ) {
					return true;
				}
				break;
			case 'xb/loop':
				$source = is_array( $attrs['source'] ?? null ) ? $attrs['source'] : array();
				if ( 'preset' === ( $source['kind'] ?? null ) ) {
					return true; // A query: its items change over time.
				}
				if ( $this->expr_moves( $this->substitute_expr( (string) ( $source['expr'] ?? '' ) ), $scope ) ) {
					return true;
				}
				break;
			case 'xb/component':
				foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $value ) {
					if ( is_array( $value ) && ( isset( $value['expr'] ) ? $this->expr_moves( $this->substitute_expr( (string) $value['expr'] ), $scope ) : ( isset( $value['tpl'] ) && $this->value_moves( $this->substitute_value( (string) $value['tpl'] ), $scope ) ) ) ) {
						return true;
					}
				}
				$definition = Plugin::instance()->components->find( (string) ( $attrs['ref'] ?? '' ) );
				if ( null !== $definition ) {
					foreach ( $definition['blocks'] as $inner ) {
						if ( 'xb/slot' !== ( $inner['blockName'] ?? null ) && $this->moves( $inner, $scope ) ) {
							return true;
						}
					}
				}
				break;
			case 'xb/post-content':
				return true;
		}
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
			if ( is_array( $child ) && $this->moves( $child, $scope ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $scope */
	private function value_moves( string $value, array $scope ): bool {
		if ( ! str_contains( $value, '{' ) ) {
			return false;
		}
		try {
			$split = Template::split( $value );
		} catch ( SyntaxError ) {
			return false;
		}
		foreach ( $split as [ $kind, $text ] ) {
			if ( Template::EXPR === $kind && $this->expr_moves( $text, $scope ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $scope */
	private function expr_moves( string $source, array $scope ): bool {
		$roots = Analyzer::roots_of( $source );
		if ( null === $roots ) {
			return false;
		}
		$moving = array_filter( array( $scope['post'], $scope['term'], $scope['link'], $scope['item'], $scope['voce'], $scope['riga'] ) );
		foreach ( $roots as $root ) {
			// A filtered list changes with the address asked for.
			if ( in_array( $root, $moving, true ) || 'facets' === $root ) {
				return true;
			}
			// The address asked for changes with every request, also in a page (its query, another language prefix).
			if ( 'url' === $root || ( $scope['page'] && ( 'page' === $root || ( 'props' === $root && str_contains( $source, 'props.page' ) ) ) ) ) {
				return true;
			}
		}
		// A computed prop of the component being expanded.
		foreach ( array_keys( $this->computed ) as $name ) {
			if ( 1 === preg_match( '/\bprops\.' . preg_quote( $name, '/' ) . '\b/', $source ) ) {
				return true;
			}
		}
		return false;
	}

	/** An expression with the computed props of the expanded component put in place. */
	private function substitute_expr( string $source ): string {
		foreach ( $this->computed as $name => $value ) {
			if ( Template::is_single( $value ) ) {
				$inner  = substr( $value, 1, -1 );
				$source = (string) preg_replace( '/\bprops\.' . preg_quote( $name, '/' ) . '\b/', 1 === preg_match( '/^[\w.]+$/', $inner ) ? $inner : '(' . $inner . ')', $source );
			}
		}
		return $source;
	}

	/** A text or attribute value with the computed props of the expanded component put in place. */
	private function substitute_value( string $value ): string {
		foreach ( $this->computed as $name => $source ) {
			$value = str_replace( '{props.' . $name . '}', $source, $value );
		}
		if ( array() === $this->computed || ! str_contains( $value, '{' ) ) {
			return $value;
		}
		try {
			$split = Template::split( $value );
		} catch ( SyntaxError ) {
			return $value;
		}
		$out = '';
		foreach ( $split as [ $kind, $text ] ) {
			$out .= Template::LITERAL === $kind ? self::escape_braces( $text ) : '{' . $this->substitute_expr( $text ) . '}';
		}
		return $out;
	}

	/**
	 * Root and path of a plain chain.
	 *
	 * @return array{0: string|null, 1: list<string>}
	 */
	private static function chain_of( string $source ): array {
		try {
			[ $root, $path ] = Analyzer::chain( Parser::parse( $source ) );
		} catch ( SyntaxError ) {
			return array( null, array() );
		}
		return array( $root, $path );
	}

	// ------------------------------------------------------------------ rendering

	/**
	 * Histeon's HTML for a block, with the attributes and texts of the computed props put in place.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, mixed> $scope
	 */
	private function render( array $block, array $scope ): string {
		return $this->renderer->render_block( $block, $scope['context'] );
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @param array<string, mixed>       $scope
	 */
	private function render_list( array $blocks, array $scope ): string {
		return $this->renderer->render_blocks( $blocks, $scope['context'] );
	}

	/** @param array<string, mixed> $scope */
	private function render_text( string $content, array $scope ): string {
		return '' === $content ? '' : $this->render(
			array(
				'blockName'   => 'xb/text',
				'attrs'       => array( 'content' => $content ),
				'innerBlocks' => array(),
			),
			$scope
		);
	}

	/**
	 * Attributes of an opening tag, in order, decoded.
	 *
	 * @return array<string, string>
	 */
	private static function attributes_of( string $open ): array {
		$tag = new \WP_HTML_Tag_Processor( $open );
		$out = array();
		if ( ! $tag->next_tag() ) {
			return $out;
		}
		foreach ( $tag->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
			$value        = $tag->get_attribute( $name );
			$out[ $name ] = is_string( $value ) ? $value : '';
		}
		return $out;
	}

	/**
	 * The class of a core block, as its `className` attribute.
	 *
	 * @param array<string, string> $saved
	 * @return array<string, string>
	 */
	private static function core_class( array $saved ): array {
		return isset( $saved['class'] ) && '' !== trim( $saved['class'] ) ? array( 'className' => trim( $saved['class'] ) ) : array();
	}

	private static function escape_braces( string $text ): string {
		return str_replace( array( '{', '}' ), array( '\\{', '\\}' ), $text );
	}

	/** @param list<array<string, mixed>> $children */
	private static function rich( array $children ): bool {
		if ( array() === $children ) {
			return false;
		}
		foreach ( $children as $child ) {
			$name  = $child['blockName'] ?? null;
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			if ( 'xb/text' === $name ) {
				continue;
			}
			if ( 'xb/element' !== $name || ! in_array( strtolower( (string) ( $attrs['tag'] ?? '' ) ), self::INLINE, true ) ) {
				return false;
			}
			$inner = is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array();
			if ( array() !== $inner && ! self::rich( $inner ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<array<string, mixed>> $children */
	private function list_items( array $children ): bool {
		foreach ( $children as $child ) {
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			$inner = is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array();
			if ( 'xb/element' !== ( $child['blockName'] ?? null ) || 'li' !== strtolower( (string) ( $attrs['tag'] ?? '' ) ) || ! self::rich( $inner ) ) {
				return false;
			}
			if ( array() !== array_diff( array_keys( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() ), array( 'class' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<mixed>               $attributes
	 * @param list<array<string, mixed>> $children
	 */
	private static function histeon_form( string $tag, array $attributes, array $children ): bool {
		return 'form' === $tag && ( str_contains( (string) ( is_string( $attributes['action'] ?? null ) ? $attributes['action'] : '' ), 'xb-form' ) || str_contains( (string) wp_json_encode( $children ), '"name":"xb_form"' ) );
	}

	// ------------------------------------------------------------------ output

	/**
	 * A block in the shape parse_blocks() gives.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $inner
	 * @param list<string|null>          $content
	 * @return array<string, mixed>
	 */
	private function make( string $name, array $attrs, array $inner, array $content ): array {
		$this->count( $name );
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		);
	}

	private function count( string $name ): void {
		$this->blocks[ $name ] = ( $this->blocks[ $name ] ?? 0 ) + 1;
	}

	/** @return array<string, mixed> */
	private function html( string $html, string $uid, string $node, string $reason ): array {
		if ( '' !== $reason ) {
			$this->note( $uid, $node, 'core/html', $reason );
		}
		return $this->make( 'core/html', array(), array(), array( $html ) );
	}

	/** @return list<array<string, mixed>> */
	private function lose( string $uid, string $node, string $reason ): array {
		$this->note( $uid, $node, 'perso', $reason );
		return array();
	}

	private function note( string $uid, string $node, string $cosa, string $reason ): void {
		$this->notes[] = array(
			'uid'    => $uid,
			'node'   => $node,
			'cosa'   => $cosa,
			'reason' => $reason,
		);
	}
}
