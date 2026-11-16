<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Expr\Parser;
use XB\Expr\SyntaxError;
use XB\Expr\Template;
use XB\Plugin;
use XB\Render\Queries;

/**
 * Reads one Histeon block tree and says, element by element, where each piece would go on the way out to
 * Gutenberg — docs/spec/export-core.md §2-§4. It writes nothing and builds no block: it is the plan the
 * converter of the next steps (doc 25 of Histeon Publish, §11) will follow, measured on real sites first.
 *
 * Destinations of an element: a core block for text, a static block of Fondamenta, a dynamic block of
 * Fondamenta, a synced pattern, a block of Gutenberg already there, frozen into `core/html`, or lost. The fate of
 * each expression is told apart: written at conversion, bound to a dynamic block, or lost.
 */
final class Analyzer {

	public const CORE      = 'core';
	public const FONDAMENTA = 'fondamenta';
	public const DYNAMIC   = 'fondamenta-dinamico';
	public const PATTERN   = 'pattern';
	public const GUTENBERG = 'gutenberg';
	public const UNROLLED  = 'srotolato';
	public const FROZEN    = 'congelato';
	public const LOST      = 'perso';

	/** Destinations that end up as real, editable blocks (the top figure of the report). */
	public const REAL = array( self::CORE, self::FONDAMENTA, self::DYNAMIC, self::PATTERN, self::GUTENBERG, self::UNROLLED );

	public const WRITTEN = 'scritta';
	public const BOUND   = 'legata';
	public const DROPPED = 'persa';

	/** Elements that hold text and become core text blocks. */
	private const TEXT_TAGS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
	/** Inline elements that the rich text of a core block keeps as they are. */
	private const INLINE = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );
	/** Markup that goes into `core/html` as it is: code, embedded documents, media players, vector drawings. */
	private const FROZEN_TAGS = array( 'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'canvas', 'svg', 'math', 'video', 'audio' );
	/** Containers with a Fondamenta block of their own (doc 25 §11, step 3; names provisional). */
	/** Roots that depend on who visits or on the request: no conversion can keep them. */
	private const VISITOR = array( 'user' );
	/** Values of a post fondamenta_valore() names (theme/fondamenta/inc/blocchi.php): a legame binds them. */
	private const POST_KEYS = array( 'id', 'title', 'url', 'slug', 'type', 'excerpt', 'date', 'modified', 'publishedAt', 'updatedAt', 'author', 'image', 'categories', 'tags', 'terms', 'fields' );
	/** Modifiers a legame reproduces as formats. */
	private const KEPT_MODIFIERS = array( 'date', 'truncateWords', 'join', 'upper', 'lower', 'slice' );
	private const MAX_DEPTH      = 100;
	private const MAX_ITEMS      = 2000;

	/** @var array<string, int> Elements per destination. */
	public array $counts = array();
	/** @var array<string, int> Expressions per fate. */
	public array $expressions = array();
	/** @var array<string, int> Loops per destination. */
	public array $loops = array();
	/** @var array<string, int> Components per destination (pattern, srotolato = expanded). */
	public array $components = array();
	/** @var array<string, int> Conditions: resolved at conversion, or lost. */
	public array $conditions = array();
	/** @var list<array{uid: string, path: string, node: string, dest: string, target: string, elements: int, reason: string}> */
	public array $items = array();
	/** @var list<array{uid: string, path: string, expr: string, fate: string, target: string, reason: string}> */
	public array $notes = array();
	public int $forms = 0;
	public int $behaviors = 0;

	/** @var array<string, true> Component refs being expanded (no endless recursion). */
	private array $expanding = array();
	/** @var list<string> Blocks the expressions of the element being read are bound to. */
	private array $bound = array();
	/** @var array<string, string> Computed props of the component being expanded: name → the instance's value. */
	private array $computed = array();

	/**
	 * @param string $kind 'contenuto' (a page, post or record: `this` is that one), 'template', 'parte' or 'componente'.
	 */
	public function __construct( private readonly string $kind ) {
		foreach ( array( self::CORE, self::FONDAMENTA, self::DYNAMIC, self::PATTERN, self::GUTENBERG, self::UNROLLED, self::FROZEN, self::LOST ) as $dest ) {
			$this->counts[ $dest ] = 0;
		}
		$this->expressions = array_fill_keys( array( self::WRITTEN, self::BOUND, self::DROPPED ), 0 );
		$this->loops       = array_fill_keys( array( self::DYNAMIC, self::UNROLLED, self::FROZEN, self::LOST ), 0 );
		$this->components  = array_fill_keys( array( self::PATTERN, self::UNROLLED ), 0 );
		$this->conditions  = array_fill_keys( array( 'risolte', 'se', 'perse' ), 0 );
	}

	/**
	 * @param list<array<string, mixed>> $blocks parse_blocks() of the document.
	 */
	public function analyze( array $blocks ): void {
		$scope = array(
			'dynamic' => 'contenuto' !== $this->kind,
			'posts'   => 'contenuto' === $this->kind ? array() : array( 'this' ),
			'locals'  => array(),
			'props'   => 'componente' === $this->kind,
		);
		// `page` (props.page) is the page shown: fixed in a content, one per use in a template, part or component.
		$this->walk( $blocks, $scope, array(), 0 );
	}

	/** Elements that become real blocks, over all elements; 1.0 for a document without elements. */
	public function share(): float {
		$total = array_sum( $this->counts );
		$real  = 0;
		foreach ( self::REAL as $dest ) {
			$real += $this->counts[ $dest ];
		}
		return $total > 0 ? $real / $total : 1.0;
	}

	/**
	 * @param list<array<string, mixed>>                                                                     $blocks
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool}                    $scope
	 * @param list<string>                                                                                   $path
	 */
	private function walk( array $blocks, array $scope, array $path, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$this->item( '', $path, 'html', self::FROZEN, 'core/html', __( 'HTML fuori dai blocchi: resta com\'è', 'xb' ) );
				}
				continue;
			}
			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			$uid      = (string) ( $attrs['uid'] ?? '' );
			match ( $name ) {
				'xb/element' => $this->element( $attrs, $children, $scope, $path, $depth ),
				'xb/text' => $this->bare_text( $attrs, $scope, $path ),
				'xb/loop' => $this->loop( $attrs, $children, $scope, $path, $depth ),
				'xb/if' => $this->condition( $children, $scope, $path, $depth ),
				'xb/branch', 'xb/slot-fill' => $this->walk( $children, $scope, $path, $depth + 1 ),
				'xb/component' => $this->component( $attrs, $children, $scope, $path, $depth ),
				'xb/raw' => $this->item( $uid, $path, 'xb/raw', self::FROZEN, 'core/html', __( 'HTML scritto a mano: resta com\'è, non si modifica a vista', 'xb' ) ),
				'xb/post-content' => $this->item( $uid, $path, 'xb/post-content', self::DYNAMIC, 'fondamenta/contenuto', '' ),
				'xb/slot' => null,
				'xb/passthrough' => $this->passthrough( $uid, $children, $scope, $path, $depth ),
				default => str_starts_with( $name, 'xb/' )
					? $this->item( $uid, $path, $name, self::LOST, '', __( 'blocco di una versione più recente di Histeon', 'xb' ) )
					: $this->foreign( $name, $children, $scope, $path, $depth ),
			};
		}
	}

	/**
	 * @param array<string, mixed>                                                         $attrs
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function element( array $attrs, array $children, array $scope, array $path, int $depth ): void {
		$tag        = strtolower( (string) ( $attrs['tag'] ?? 'div' ) );
		$uid        = (string) ( $attrs['uid'] ?? '' );
		$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
		$here       = array( ...$path, self::label( $tag, $attributes ) );
		if ( $this->histeon_form( $tag, $attributes, $children ) ) {
			++$this->forms;
			$this->item( $uid, $path, $tag, self::LOST, '', __( 'form di Histeon (/xb-form): si rifà nel plugin dei form scelto, Fluent Forms per primo; i messaggi ricevuti vanno esportati prima', 'xb' ), 1 + self::count_elements( $children ) );
			return;
		}
		if ( in_array( $tag, self::FROZEN_TAGS, true ) ) {
			$this->item( $uid, $path, $tag, self::FROZEN, 'core/html', sprintf( /* translators: %s: HTML tag */ __( '<%s> resta com\'è in core/html', 'xb' ), $tag ), 1 + self::count_elements( $children ) );
			return;
		}
		foreach ( $attributes as $name => $value ) {
			if ( is_string( $name ) && str_starts_with( $name, 'data-xb-' ) ) {
				++$this->behaviors;
				break;
			}
		}
		$this->bound   = array();
		$this->attributes( $uid, $tag, $attributes, $scope, $here );

		// Text: paragraphs and headings with plain text, lists of such items.
		$plain = array_diff( array_keys( $attributes ), array( 'class', 'id' ) ) === array();
		if ( in_array( $tag, self::TEXT_TAGS, true ) && $plain && $this->rich( $children ) ) {
			$this->texts( $children, $scope, $here );
			$this->place( $uid, $path, $tag, 'p' === $tag ? 'core/paragraph' : 'core/heading' );
			return;
		}
		if ( in_array( $tag, array( 'ul', 'ol' ), true ) && $plain && array() !== $children && $this->list_items( $children ) ) {
			$this->item( $uid, $path, $tag, self::CORE, 'core/list', '' );
			foreach ( $children as $li ) {
				$li_attrs = is_array( $li['attrs'] ?? null ) ? $li['attrs'] : array();
				$this->texts( is_array( $li['innerBlocks'] ?? null ) ? array_values( $li['innerBlocks'] ) : array(), $scope, array( ...$here, 'li' ) );
				$this->item( (string) ( $li_attrs['uid'] ?? '' ), $here, 'li', self::CORE, 'core/list-item', '' );
			}
			return;
		}

		$target = 'fondamenta/elemento';
		// Text inside a Fondamenta block is its own rich text: inline elements stay in it.
		$rich = $this->rich( $children );
		if ( $rich ) {
			$this->texts( $children, $scope, $here );
		}
		$this->place( $uid, $path, $tag, $target );
		if ( ! $rich ) {
			$this->walk( $children, $scope, $here, $depth + 1 );
		}
	}

	/**
	 * Records an element: its static block, or fondamenta/elemento with legami when it shows values of the content.
	 *
	 * @param list<string> $path
	 */
	private function place( string $uid, array $path, string $tag, string $target ): void {
		$bound = array_values( array_unique( $this->bound ) );
		if ( array() !== $bound ) {
			/* translators: %s: names of values */
			$this->item( $uid, $path, $tag, self::DYNAMIC, 'fondamenta/elemento', sprintf( __( 'legami: %s', 'xb' ), implode( ', ', $bound ) ) );
			return;
		}
		$this->item( $uid, $path, $tag, str_starts_with( $target, 'core/' ) ? self::CORE : self::FONDAMENTA, $target, '' );
	}

	/**
	 * A loop: a Fondamenta query block, unrolled (values written), frozen or lost — doc 25 §3.3.
	 *
	 * @param array<string, mixed>                                                         $attrs
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function loop( array $attrs, array $children, array $scope, array $path, int $depth ): void {
		$uid    = (string) ( $attrs['uid'] ?? '' );
		$as     = (string) ( $attrs['as'] ?? 'item' );
		$index  = (string) ( $attrs['index'] ?? '' );
		$source = is_array( $attrs['source'] ?? null ) ? $attrs['source'] : array();
		$here   = array( ...$path, '{#loop}' );
		$inner  = self::count_elements( $children );
		if ( 'expr' === ( $source['kind'] ?? '' ) ) {
			$expr  = (string) ( $source['expr'] ?? '' );
			$roots = self::roots( $expr );
			$live  = null === $roots ? array() : $this->dynamic_roots( $roots, $scope );
			$block = null === $roots ? null : $this->list_block( $expr, $scope );
			if ( null !== $block ) {
				// A list a core block shows by itself: the terms of a content, the page links of an archive.
				++$this->loops[ self::DYNAMIC ];
				/* translators: %s: expression */
				$this->item( $uid, $path, 'xb/loop', self::DYNAMIC, $block, sprintf( __( 'loop su {%s}: il blocco disegna l\'elenco con il suo markup', 'xb' ), self::short( $expr ) ), 1 + $inner );
				return;
			}
			if ( null === $roots || array() !== $live ) {
				++$this->loops[ self::LOST ];
				$reason = null === $roots
					? __( 'loop su un\'espressione non valida', 'xb' )
					/* translators: %s: expression */
					: sprintf( __( 'loop su {%s}, che cambia da contenuto a contenuto (un ripetitore o una lista calcolata): arriverà con il plugin dei campi scelto', 'xb' ), self::short( $expr ) );
				$this->item( $uid, $path, 'xb/loop', self::LOST, '', $reason, $inner );
				return;
			}
			++$this->loops[ self::UNROLLED ];
			/* translators: %s: expression */
			$this->item( $uid, $path, 'xb/loop', self::UNROLLED, '', sprintf( __( 'lista {%s} srotolata: una copia per elemento, valori scritti; i dati non stanno più in un posto solo', 'xb' ), self::short( $expr ) ), 0 );
			$scope['locals'] = array( ...$scope['locals'], $as, ...( '' !== $index ? array( $index ) : array() ) );
			$this->walk( $children, $scope, $here, $depth + 1 );
			return;
		}
		$id     = (string) ( $source['id'] ?? '' );
		$preset = Queries::presets()[ $id ] ?? null;
		$type   = in_array( $id, array( 'posts', 'terms', 'main', 'users' ), true ) ? $id : ( is_array( $preset ) ? $preset['type'] : '' );
		$group  = is_array( $source['groupBy'] ?? null );
		$args   = is_array( $source['args'] ?? null ) ? $source['args'] : array();
		$moving = array();
		foreach ( $args as $value ) {
			$roots = is_string( $value ) ? self::roots( $value ) : array();
			if ( null !== $roots ) {
				$moving = array( ...$moving, ...$this->dynamic_roots( $roots, $scope ) );
			}
		}
		if ( '' === $type ) {
			++$this->loops[ self::LOST ];
			/* translators: %s: query name */
			$this->item( $uid, $path, 'xb/loop', self::LOST, '', sprintf( __( 'query «%s» che non esiste più', 'xb' ), $id ), $inner );
			return;
		}
		if ( in_array( $type, array( 'terms', 'users' ), true ) || $group ) {
			$reason = $group
				? __( 'loop a gruppi (groupBy): nessun blocco lo fa; si congela e non segue i contenuti nuovi', 'xb' )
				/* translators: %s: query type */
				: sprintf( __( 'query di %s: nessuna query di Fondamenta per termini o utenti; si congela e non segue i cambi', 'xb' ), 'terms' === $type ? __( 'termini', 'xb' ) : __( 'utenti', 'xb' ) );
			$dest   = $scope['dynamic'] && array() !== $moving ? self::LOST : self::FROZEN;
			++$this->loops[ $dest ];
			$this->item( $uid, $path, 'xb/loop', $dest, self::FROZEN === $dest ? 'core/html' : '', $reason, $inner );
			return;
		}
		++$this->loops[ self::DYNAMIC ];
		$reason = '';
		if ( array() !== $moving && $scope['dynamic'] ) {
			/* translators: %s: names */
			$reason = sprintf( __( 'argomenti della query calcolati da %s: la query di Fondamenta li ignora', 'xb' ), implode( ', ', array_unique( $moving ) ) );
		}
		if ( '' === $reason && is_array( $preset ) && array_intersect( array_keys( $preset['args'] ), array( 'meta_query', 'meta_key', 'meta_value', 'meta_compare' ) ) !== array() ) {
			$reason = __( 'condizioni sui campi (meta_query): la query di Fondamenta le applica come dati', 'xb' );
		}
		$this->item( $uid, $path, 'xb/loop', self::DYNAMIC, 'main' === $type ? 'fondamenta/loop (query principale)' : 'fondamenta/loop', $reason );
		$scope['dynamic'] = true;
		// The index changes at every item too, and no block shows it.
		$scope['posts'] = array( ...$scope['posts'], $as, ...( '' !== $index ? array( $index ) : array() ) );
		$this->walk( $children, $scope, $here, $depth + 1 );
	}

	/**
	 * A condition: resolved at conversion where the answer is known, lost where it depends on the visitor or on
	 * values that change from one content to another.
	 *
	 * @param list<array<string, mixed>>                                                   $branches
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function condition( array $branches, array $scope, array $path, int $depth ): void {
		$lost = array();
		foreach ( $branches as $branch ) {
			$test = (string) ( ( is_array( $branch['attrs'] ?? null ) ? $branch['attrs'] : array() )['test'] ?? '' );
			if ( '' === $test ) {
				continue;
			}
			$roots = self::roots( $test );
			if ( null === $roots ) {
				$lost[] = $test;
				continue;
			}
			if ( array() !== array_intersect( $roots, self::VISITOR ) || array() !== $this->dynamic_roots( $roots, $scope ) ) {
				$lost[] = $test;
			}
		}
		if ( array() === $lost ) {
			++$this->conditions['risolte'];
		} elseif ( $this->decidable( $branches, $scope ) ) {
			// fondamenta/se: shown when a named value of the content is there (or is not).
			++$this->conditions['se'];
		} else {
			++$this->conditions['perse'];
			foreach ( $lost as $test ) {
				$this->note( '', $path, $test, self::DROPPED, '', __( 'condizione che dipende da chi visita o dal contenuto mostrato: Fondamenta non ha un blocco di condizione; resta quello che vede un visitatore anonimo', 'xb' ) );
			}
		}
		$this->walk( $branches, $scope, $path, $depth + 1 );
	}

	/**
	 * Whether fondamenta/se decides a condition: one test on a value the conversion names (`x.excerpt`, `!x.image`),
	 * with at most an `{:else}`.
	 *
	 * @param list<array<string, mixed>>                                                   $branches
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 */
	private function decidable( array $branches, array $scope ): bool {
		$test = (string) ( $branches[0]['attrs']['test'] ?? '' );
		if ( count( $branches ) > 2 || ( 2 === count( $branches ) && isset( $branches[1]['attrs']['test'] ) ) || '' === $test ) {
			return false;
		}
		try {
			$node = Parser::parse( $test );
		} catch ( SyntaxError ) {
			return false;
		}
		// Comparisons joined with || and && (a menu item of the section shown) are decided too.
		foreach ( self::operands( $node, '||' ) as $or ) {
			foreach ( self::operands( $or, '&&' ) as $one ) {
				if ( ! $this->simple_test( $one, $scope ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return list<array<string, mixed>>
	 */
	private static function operands( array $node, string $op ): array {
		if ( 'Binary' === ( $node['type'] ?? '' ) && $op === ( $node['op'] ?? '' ) && is_array( $node['left'] ?? null ) && is_array( $node['right'] ?? null ) ) {
			return array( ...self::operands( $node['left'], $op ), ...self::operands( $node['right'], $op ) );
		}
		return array( $node );
	}

	/**
	 * One test fondamenta/se takes: a named value, negated or not, compared with a literal or `.includes(literal)`.
	 *
	 * @param array<string, mixed>                                                         $node
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 */
	private function simple_test( array $node, array $scope ): bool {
		while ( 'Unary' === ( $node['type'] ?? '' ) && '!' === ( $node['op'] ?? '' ) && is_array( $node['argument'] ?? null ) ) {
			$node = $node['argument'];
		}
		if ( 'Binary' === ( $node['type'] ?? '' ) && in_array( $node['op'] ?? '', array( '==', '!=', '>', '>=', '<', '<=' ), true ) ) {
			$left  = is_array( $node['left'] ?? null ) ? $node['left'] : array();
			$right = is_array( $node['right'] ?? null ) ? $node['right'] : array();
			$node  = 'Literal' === ( $right['type'] ?? '' ) ? $left : ( 'Literal' === ( $left['type'] ?? '' ) ? $right : array() );
		} elseif ( 'Call' === ( $node['type'] ?? '' ) && 'includes' === ( $node['callee']['property'] ?? '' ) && is_array( $node['callee']['object'] ?? null ) ) {
			$node = $node['callee']['object'];
		}
		[ $root, $path, $calls ] = self::calls( $node );
		if ( null === $root || array() !== $calls ) {
			return false;
		}
		[ $fate, $target ] = $this->fate( $root . ( array() === $path ? '' : '.' . implode( '.', $path ) ), $scope, true, false );
		return self::BOUND === $fate && 'override del pattern' !== $target;
	}

	/**
	 * An instance: a synced pattern (the same wp_block, texts as overrides) or expanded in place — doc 25 §11, D3.
	 *
	 * @param array<string, mixed>                                                         $attrs
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function component( array $attrs, array $children, array $scope, array $path, int $depth ): void {
		$ref        = (string) ( $attrs['ref'] ?? '' );
		$uid        = (string) ( $attrs['uid'] ?? '' );
		$definition = '' !== $ref ? Plugin::instance()->components->find( $ref ) : null;
		if ( null === $definition ) {
			/* translators: %s: component key */
			$this->item( $uid, $path, 'xb/component', self::LOST, '', sprintf( __( 'componente «%s» senza definizione', 'xb' ), $ref ) );
			return;
		}
		// Computed props, as the definition will read them: `{props.titolo}` becomes the instance's `{x.title}`.
		$computed = array();
		$moving   = false;
		foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $name => $value ) {
			if ( is_array( $value ) && ( isset( $value['expr'] ) || isset( $value['tpl'] ) ) ) {
				$source                      = $this->substitute( isset( $value['expr'] ) ? '{' . (string) $value['expr'] . '}' : (string) $value['tpl'] );
				$computed[ (string) $name ] = $source;
				$roots                       = self::template_roots( $source );
				$moving                      = $moving || null === $roots || array() !== $this->dynamic_roots( $roots, $scope );
			}
		}
		$why  = $moving ? __( 'props calcolate da valori che cambiano a ogni uso', 'xb' ) : self::expand_reason( $ref, $definition['blocks'], $children );
		if ( null === $why && ( $definition['id'] <= 0 || 'wp_block' !== get_post_type( $definition['id'] ) ) ) {
			$why = __( 'definito in una parte di template, non in un componente', 'xb' );
		} elseif ( null === $why && ! Converter::pattern_props( $definition['blocks'] ) ) {
			$why = __( 'props in testi o attributi che un override non sovrascrive per intero', 'xb' );
		}
		$here = array( ...$path, $ref );
		if ( null === $why ) {
			foreach ( $computed as $name => $source ) {
				$this->value( $uid, $name, $source, $scope, $here, false, false );
			}
			++$this->components[ self::PATTERN ];
			/* translators: %s: component key */
			$this->item( $uid, $path, 'xb/component', self::PATTERN, 'core/block', sprintf( __( 'pattern sincronizzato «%s»: le props diventano override', 'xb' ), $ref ) );
			return;
		}
		++$this->components[ self::UNROLLED ];
		/* translators: 1: component key, 2: reason */
		$this->item( $uid, $path, 'xb/component', self::UNROLLED, '', sprintf( __( '«%1$s» espanso nella pagina (%2$s): la definizione unica si perde', 'xb' ), $ref, $why ), 0 );
		if ( isset( $this->expanding[ $ref ] ) ) {
			return;
		}
		$this->expanding[ $ref ] = true;
		// Expanded: literal props are written; computed ones are read where the definition uses them.
		$outer                   = $this->computed;
		$this->computed          = $computed;
		$inner                   = $scope;
		$inner['props']          = false;
		// Inside the expanded definition `props` are this instance's values, written at conversion.
		$inner['locals'][]       = '#props';
		$this->walk( $definition['blocks'], $inner, $here, $depth + 1 );
		$this->computed = $outer;
		$this->walk( $children, $scope, $here, $depth + 1 );
		unset( $this->expanding[ $ref ] );
	}

	/**
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function passthrough( string $uid, array $children, array $scope, array $path, int $depth ): void {
		$inner = $children[0] ?? null;
		$name  = is_array( $inner ) ? (string) ( $inner['blockName'] ?? '' ) : '';
		if ( '' === $name ) {
			return;
		}
		$this->item( $uid, $path, $name, self::GUTENBERG, $name, '' );
		$grand = is_array( $inner['innerBlocks'] ?? null ) ? array_values( $inner['innerBlocks'] ) : array();
		$this->walk( $grand, $scope, array( ...$path, $name ), $depth + 1 );
	}

	/**
	 * A block of another plugin outside a passthrough (left in place by an import): it stays; Histeon blocks inside
	 * it are read as usual.
	 *
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function foreign( string $name, array $children, array $scope, array $path, int $depth ): void {
		$this->item( '', $path, $name, self::GUTENBERG, $name, '' );
		$this->walk( $children, $scope, array( ...$path, $name ), $depth + 1 );
	}

	/**
	 * Text with no element around it: a paragraph.
	 *
	 * @param array<string, mixed>                                                         $attrs
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function bare_text( array $attrs, array $scope, array $path ): void {
		if ( ! empty( $attrs['verbatim'] ) ) {
			return;
		}
		$uid = (string) ( $attrs['uid'] ?? '' );
		$this->value( $uid, '', (string) ( $attrs['content'] ?? '' ), $scope, $path, true, false );
		$this->item( $uid, $path, 'xb/text', self::CORE, 'core/paragraph', __( 'testo senza elemento attorno: diventa un paragrafo', 'xb' ) );
	}

	/**
	 * The expressions in the text children of a block that keeps them as rich text (inline elements included).
	 *
	 * @param list<array<string, mixed>>                                                   $children
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function texts( array $children, array $scope, array $path ): void {
		$whole = 1 === count( $children );
		foreach ( $children as $child ) {
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			if ( 'xb/text' === ( $child['blockName'] ?? '' ) ) {
				if ( empty( $attrs['verbatim'] ) ) {
					$this->value( (string) ( $attrs['uid'] ?? '' ), '', (string) ( $attrs['content'] ?? '' ), $scope, $path, $whole, false );
				}
				continue;
			}
			$tag        = strtolower( (string) ( $attrs['tag'] ?? '' ) );
			$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
			$here       = array( ...$path, $tag );
			$this->attributes( (string) ( $attrs['uid'] ?? '' ), $tag, $attributes, $scope, $here );
			$this->texts( is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array(), $scope, $here );
		}
	}

	/**
	 * @param array<mixed>                                                                 $attributes
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function attributes( string $uid, string $tag, array $attributes, array $scope, array $path ): void {
		foreach ( $attributes as $name => $value ) {
			if ( is_string( $value ) && str_contains( $value, '{' ) ) {
				// Values a block takes from its source: the link of a title, the image and its text of a featured image.
				$taken = ( 'href' === $name && 'a' === $tag ) || ( 'img' === $tag && in_array( $name, array( 'src', 'srcset', 'sizes', 'alt', 'width', 'height' ), true ) );
				$this->value( $uid, (string) $name, $value, $scope, $path, $taken, 'class' === $name );
			}
		}
	}

	/**
	 * The fate of each expression in a text or attribute value — doc 25 §3.5.
	 *
	 * @param bool                                                                         $whole Whether a dynamic block can take the whole value (a text alone in its block, a link, an image source).
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @param list<string>                                                                 $path
	 */
	private function value( string $uid, string $attribute, string $value, array $scope, array $path, bool $whole, bool $is_class ): void {
		$value = $this->substitute( $value );
		try {
			$parts = Template::split( $value );
		} catch ( SyntaxError ) {
			$this->note( $uid, $path, $value, self::DROPPED, '', __( 'graffa non chiusa', 'xb' ) );
			return;
		}
		$exprs = array_values( array_filter( $parts, static fn ( array $p ): bool => Template::EXPR === $p[0] ) );
		$mixed = count( $exprs ) > 1 || count( $exprs ) !== count( array_filter( $parts, static fn ( array $p ): bool => Template::EXPR === $p[0] || '' !== trim( $p[1] ) ) );
		foreach ( $exprs as $part ) {
			[ $fate, $target, $reason ] = $this->fate( $part[1], $scope, $whole && ! $mixed, $is_class );
			if ( self::BOUND === $fate && 'override del pattern' !== $target ) {
				$this->bound[] = $target;
			}
			if ( '' !== $attribute && self::DROPPED === $fate ) {
				/* translators: 1: attribute, 2: reason */
				$reason = sprintf( __( 'attributo %1$s: %2$s', 'xb' ), $attribute, $reason );
			}
			$this->note( $uid, $path, $part[1], $fate, $target, $reason );
		}
	}

	/**
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @return array{0: string, 1: string, 2: string} Fate, target block, reason.
	 */
	private function fate( string $source, array $scope, bool $whole, bool $is_class ): array {
		try {
			$node = Parser::parse( $source );
		} catch ( SyntaxError $e ) {
			/* translators: %s: error */
			return array( self::DROPPED, '', sprintf( __( 'espressione non valida (%s)', 'xb' ), $e->getMessage() ) );
		}
		$roots = self::collect_roots( $node );
		if ( array() !== array_intersect( $roots, self::VISITOR ) ) {
			return array( self::DROPPED, '', __( 'dipende da chi visita o dall\'indirizzo richiesto', 'xb' ) );
		}
		if ( in_array( 'facets', $roots, true ) ) {
			[ $root, $path, $calls ] = self::calls( $node );
			$kept                    = array() === array_diff( array_column( $calls, 0 ), self::KEPT_MODIFIERS );
			return 'facets' === $root && $kept && Converter::facet_value( $path )
				? array( self::BOUND, 'facet.' . implode( '.', $path ), '' )
				: array( self::DROPPED, '', __( 'valore dei filtri che nessun blocco mostra', 'xb' ) );
		}
		$moving = $this->dynamic_roots( $roots, $scope );
		if ( array() === $moving ) {
			$reason = in_array( 'site', $roots, true ) || in_array( 'this', $roots, true ) || in_array( 'data', $roots, true )
				? __( 'valore scritto alla conversione: non segue i cambi successivi', 'xb' )
				: '';
			return array( self::WRITTEN, '', $reason );
		}
		unset( $whole, $is_class );
		// The conversion binds it — a legame of fondamenta/elemento, also inside a text or a class — when
		// fondamenta_valore() names it.
		[ $root, $path, $calls ] = self::calls( $node );
		if ( null === $root ) {
			return array( self::DROPPED, '', __( 'espressione con operatori: nessun blocco la calcola', 'xb' ) );
		}
		foreach ( $calls as [ $modifier ] ) {
			if ( ! in_array( $modifier, self::KEPT_MODIFIERS, true ) ) {
				/* translators: %s: modifier */
				return array( self::DROPPED, '', sprintf( __( 'modificatore %s(): nessun blocco lo riproduce', 'xb' ), $modifier ) );
			}
		}
		if ( in_array( $root, $scope['posts'], true ) ) {
			if ( array() === $path ) {
				/* translators: %s: name */
				return array( self::DROPPED, '', sprintf( __( '«%s» cambia da un uso all\'altro e nessun blocco lo mostra', 'xb' ), $root ) );
			}
			return in_array( $path[0], self::POST_KEYS, true )
				? array( self::BOUND, implode( '.', $path ), '' )
				/* translators: %s: property */
				: array( self::DROPPED, '', sprintf( __( 'nessun blocco mostra «%s» di un contenuto', 'xb' ), implode( '.', $path ) ) );
		}
		if ( 'url' === $root ) {
			return array( 'path' ) === $path
				? array( self::BOUND, 'url.path', '' )
				: array( self::DROPPED, '', __( 'dipende da chi visita o dall\'indirizzo richiesto', 'xb' ) );
		}
		if ( in_array( $root, array( 'page', 'props' ), true ) ) {
			$path = 'props' === $root && 'page' === ( $path[0] ?? '' ) ? array_slice( $path, 1 ) : $path;
			if ( 'props' === $root && $scope['props'] && 'page' !== ( $path[0] ?? null ) ) {
				return array( self::BOUND, 'override del pattern', '' );
			}
			return in_array( $path[0] ?? '', array( 'title', 'name', 'page', 'totalPages', 'totalRecords', 'prevUrl', 'nextUrl', 'id', 'slug', 'url', 'type', 'excerpt', 'date', 'modified', 'image', 'fields', 'categories', 'tags', 'terms' ), true )
				? array( self::BOUND, 'page.' . ( 'name' === $path[0] ? 'title' : implode( '.', $path ) ), '' )
				/* translators: %s: property */
				: array( self::DROPPED, '', sprintf( __( 'nessun blocco mostra «%s» della pagina', 'xb' ), implode( '.', $path ) ) );
		}
		/* translators: %s: name */
		return array( self::DROPPED, '', sprintf( __( '«%s» cambia da un uso all\'altro e nessun blocco lo mostra', 'xb' ), $root ) );
	}

	/**
	 * The core block that draws a whole list by itself, for a loop on the terms of a content or on the page links of
	 * an archive; null for anything else.
	 *
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 */
	private function list_block( string $expr, array $scope ): ?string {
		try {
			// A computed prop of an expanded component reads as the instance's value.
			[ $root, $path, $modifiers ] = self::chain( Parser::parse( substr( $this->substitute( '{' . $expr . '}' ), 1, -1 ) ) );
		} catch ( SyntaxError ) {
			return null;
		}
		if ( null === $root || array() !== $modifiers ) {
			return null;
		}
		if ( 'facets' === $root ) {
			return null === Converter::facet_list( $path ) ? null : 'fondamenta/loop (filtri)';
		}
		$own  = in_array( $root, $scope['posts'], true );
		$page = 'props' === $root && 'page' === ( $path[0] ?? '' ) ? array_slice( $path, 1 ) : ( 'page' === $root ? $path : null );
		if ( null !== $page && 'contenuto' !== $this->kind ) {
			$path = $page;
		}
		if ( ! $own && ( null === $page || 'contenuto' === $this->kind ) ) {
			return null;
		}
		if ( in_array( $path[0] ?? '', array( 'categories', 'tags', 'terms' ), true ) ) {
			return 'fondamenta/loop (termini)';
		}
		if ( 'fields' === ( $path[0] ?? '' ) && 2 === count( $path ) && isset( self::multiple()[ $path[1] ] ) ) {
			return 'fondamenta/loop (campo)';
		}
		if ( 'fields' === ( $path[0] ?? '' ) && 2 === count( $path ) && isset( self::multiple()[ '#' . $path[1] ] ) ) {
			return 'fondamenta/loop (righe)';
		}
		return ! $own && array( 'pageLinks' ) === $path && in_array( $this->kind, array( 'template', 'parte' ), true ) ? 'fondamenta/loop (pagine)' : null;
	}

	/**
	 * Fields that hold more than one simple value (a list of years): a loop over them is fondamenta/loop (campo); a
	 * repeater, as `#name`: fondamenta/loop (righe).
	 *
	 * @return array<string, true>
	 */
	private static function multiple(): array {
		$out = array();
		foreach ( \XB\Content\ContentTypes::live()['fields'] as $list ) {
			foreach ( $list as $field ) {
				if ( ! empty( $field['multiple'] ) && ! in_array( $field['type'], array( 'image', 'post', 'repeater' ), true ) ) {
					$out[ (string) $field['name'] ] = true;
				}
				// A repeater, marked: its rows are fondamenta/loop (righe).
				if ( 'repeater' === $field['type'] ) {
					$out[ '#' . $field['name'] ] = true;
				}
			}
		}
		return $out;
	}

	/** A value with the computed props of the expanded component put in place: `{props.titolo}` → `{x.title}`. */
	private function substitute( string $value ): string {
		foreach ( $this->computed as $name => $source ) {
			$value = str_replace( '{props.' . $name . '}', $source, $value );
		}
		return $value;
	}

	/**
	 * Roots read by every expression of a text or attribute value; null when one does not parse.
	 *
	 * @return list<string>|null
	 */
	private static function template_roots( string $value ): ?array {
		try {
			$parts = Template::split( $value );
		} catch ( SyntaxError ) {
			return null;
		}
		$out = array();
		foreach ( $parts as $part ) {
			if ( Template::EXPR === $part[0] ) {
				$roots = self::roots( $part[1] );
				if ( null === $roots ) {
					return null;
				}
				$out = array( ...$out, ...$roots );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Roots that change from one use to another here: the post roots and loop variables of a template, part or
	 * loop, and the props of a component.
	 *
	 * @param list<string>                                                                 $roots
	 * @param array{dynamic: bool, posts: list<string>, locals: list<string>, props: bool} $scope
	 * @return list<string>
	 */
	private function dynamic_roots( array $roots, array $scope ): array {
		$moving = array();
		foreach ( $roots as $root ) {
			if ( 'url' === $root || in_array( $root, $scope['posts'], true ) || ( $scope['props'] && 'props' === $root ) ) {
				$moving[] = $root;
			} elseif ( ( 'page' === $root || ( 'props' === $root && ! in_array( '#props', $scope['locals'], true ) ) ) && in_array( $this->kind, array( 'template', 'parte' ), true ) ) {
				// props.page in a template or part is the page shown.
				$moving[] = $root;
			} elseif ( 'page' === $root && 'componente' === $this->kind ) {
				$moving[] = $root;
			}
		}
		return $moving;
	}

	/**
	 * Root names an expression reads; null when it does not parse.
	 *
	 * @return list<string>|null
	 */
	private static function roots( string $source ): ?array {
		$source = trim( $source );
		if ( str_starts_with( $source, '{' ) && str_ends_with( $source, '}' ) && Template::is_single( $source ) ) {
			$source = substr( $source, 1, -1 );
		}
		try {
			return self::collect_roots( Parser::parse( $source, true ) );
		} catch ( SyntaxError ) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $node
	 * @return list<string>
	 */
	private static function collect_roots( array $node ): array {
		$out   = array();
		$stack = array( $node );
		while ( array() !== $stack ) {
			$n = array_pop( $stack );
			if ( 'Identifier' === ( $n['type'] ?? '' ) ) {
				$out[] = (string) $n['name'];
				continue;
			}
			foreach ( $n as $key => $child ) {
				// A member's property is a name, not a variable: only its object is read.
				if ( 'property' === $key && 'Member' === ( $n['type'] ?? '' ) ) {
					continue;
				}
				if ( is_array( $child ) ) {
					$stack[] = $child;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A plain chain `root.a.b.modifier(…)`: its root, path and modifiers; a null root for anything else.
	 *
	 * @param array<string, mixed> $node
	 * @return array{0: string|null, 1: list<string>, 2: list<string>}
	 */
	public static function chain( array $node ): array {
		$path      = array();
		$modifiers = array();
		while ( true ) {
			$type = $node['type'] ?? '';
			if ( 'Identifier' === $type ) {
				return array( (string) $node['name'], array_reverse( $path ), array_reverse( $modifiers ) );
			}
			if ( 'Member' === $type && is_array( $node['object'] ?? null ) ) {
				$path[] = (string) $node['property'];
				$node   = $node['object'];
				continue;
			}
			if ( 'Call' === $type && is_array( $node['callee'] ?? null ) && 'Member' === ( $node['callee']['type'] ?? '' ) && is_array( $node['callee']['object'] ?? null ) ) {
				$modifiers[] = (string) $node['callee']['property'];
				$node        = $node['callee']['object'];
				continue;
			}
			return array( null, array(), array() );
		}
	}

	/**
	 * A plain chain with its modifiers' literal arguments: `x.date('j F Y')` → `x`, `[]`, `[['date', ['j F Y']]]`.
	 * A modifier with an argument that is not a literal makes the root null.
	 *
	 * @param array<string, mixed> $node
	 * @return array{0: string|null, 1: list<string>, 2: list<array{0: string, 1: list<mixed>}>}
	 */
	public static function calls( array $node ): array {
		$path  = array();
		$calls = array();
		while ( true ) {
			$type = $node['type'] ?? '';
			if ( 'Identifier' === $type ) {
				return array( (string) $node['name'], array_reverse( $path ), array_reverse( $calls ) );
			}
			if ( 'Member' === $type && is_array( $node['object'] ?? null ) ) {
				$path[] = (string) $node['property'];
				$node   = $node['object'];
				continue;
			}
			if ( 'Call' === $type && is_array( $node['callee'] ?? null ) && 'Member' === ( $node['callee']['type'] ?? '' ) && is_array( $node['callee']['object'] ?? null ) ) {
				$args = array();
				foreach ( is_array( $node['args'] ?? null ) ? $node['args'] : array() as $arg ) {
					if ( ! is_array( $arg ) || isset( $arg['name'] ) || 'Literal' !== ( $arg['value']['type'] ?? '' ) ) {
						return array( null, array(), array() );
					}
					$args[] = $arg['value']['value'] ?? null;
				}
				$calls[] = array( (string) $node['callee']['property'], $args );
				$node    = $node['callee']['object'];
				continue;
			}
			return array( null, array(), array() );
		}
	}

	/**
	 * Root names an expression (with or without braces) reads; null when it does not parse.
	 *
	 * @return list<string>|null
	 */
	public static function roots_of( string $source ): ?array {
		return self::roots( $source );
	}

	/**
	 * Why a component cannot be a synced pattern, or null when it can: slots, props used in classes, conditions,
	 * loops or styles, slot fills in the instance — doc 25 §3.4 and §11 (D3).
	 *
	 * @param list<array<string, mixed>> $definition
	 * @param list<array<string, mixed>> $fills
	 */
	public static function expand_reason( string $ref, array $definition, array $fills ): ?string {
		if ( str_starts_with( $ref, 'articolo/' ) ) {
			return __( 'blocco per chi scrive', 'xb' );
		}
		if ( array() !== $fills ) {
			return __( 'contenuto negli slot', 'xb' );
		}
		$stack = $definition;
		while ( array() !== $stack ) {
			$block = array_pop( $stack );
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/slot' === $name ) {
				return __( 'ha uno slot', 'xb' );
			}
			if ( 'xb/branch' === $name && str_contains( (string) ( $attrs['test'] ?? '' ), 'props' ) ) {
				return __( 'props in una condizione', 'xb' );
			}
			if ( 'xb/loop' === $name && str_contains( (string) wp_json_encode( $attrs['source'] ?? null ), 'props' ) ) {
				return __( 'props in un loop', 'xb' );
			}
			if ( 'xb/element' === $name && is_array( $attrs['attributes'] ?? null ) ) {
				foreach ( array( 'class', 'style' ) as $attribute ) {
					if ( str_contains( (string) ( $attrs['attributes'][ $attribute ] ?? '' ), 'props.' ) ) {
						/* translators: %s: attribute */
						return sprintf( __( 'props nell\'attributo %s', 'xb' ), $attribute );
					}
				}
			}
			foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
				$stack[] = $child;
			}
		}
		return null;
	}

	/**
	 * Whether children are only text and inline elements that rich text keeps: no blocks, loops or conditions.
	 *
	 * @param list<array<string, mixed>> $children
	 */
	private function rich( array $children ): bool {
		if ( array() === $children ) {
			return false;
		}
		foreach ( $children as $child ) {
			$name = (string) ( $child['blockName'] ?? '' );
			if ( 'xb/text' === $name ) {
				continue;
			}
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			if ( 'xb/element' !== $name || ! in_array( strtolower( (string) ( $attrs['tag'] ?? '' ) ), self::INLINE, true ) ) {
				return false;
			}
			$inner = is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array();
			if ( array() !== $inner && ! $this->rich( $inner ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether every child is an `li` with plain text only (no attributes but class).
	 *
	 * @param list<array<string, mixed>> $children
	 */
	private function list_items( array $children ): bool {
		foreach ( $children as $child ) {
			$attrs = is_array( $child['attrs'] ?? null ) ? $child['attrs'] : array();
			$inner = is_array( $child['innerBlocks'] ?? null ) ? array_values( $child['innerBlocks'] ) : array();
			if ( 'xb/element' !== ( $child['blockName'] ?? '' ) || 'li' !== strtolower( (string) ( $attrs['tag'] ?? '' ) ) || ! $this->rich( $inner ) ) {
				return false;
			}
			if ( array_diff( array_keys( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() ), array( 'class' ) ) !== array() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A form that posts to Histeon's own endpoint (docs/spec/static.md §4).
	 *
	 * @param array<mixed>               $attributes
	 * @param list<array<string, mixed>> $children
	 */
	private function histeon_form( string $tag, array $attributes, array $children ): bool {
		if ( 'form' !== $tag ) {
			return false;
		}
		if ( str_contains( (string) ( $attributes['action'] ?? '' ), 'xb-form' ) ) {
			return true;
		}
		return str_contains( (string) wp_json_encode( $children ), '"name":"xb_form"' );
	}

	/** @param list<array<string, mixed>> $blocks */
	private static function count_elements( array $blocks ): int {
		$n     = 0;
		$stack = $blocks;
		while ( array() !== $stack ) {
			$block = array_pop( $stack );
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'xb/element' === ( $block['blockName'] ?? '' ) ) {
				++$n;
			}
			foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
				$stack[] = $child;
			}
		}
		return $n;
	}

	/**
	 * Records an element (with the elements inside it that share its fate).
	 *
	 * @param list<string> $path
	 */
	private function item( string $uid, array $path, string $node, string $dest, string $target, string $reason, int $elements = 1 ): void {
		$this->counts[ $dest ] += $elements;
		if ( count( $this->items ) < self::MAX_ITEMS ) {
			$this->items[] = array(
				'uid'      => $uid,
				'path'     => implode( ' > ', array_slice( $path, -4 ) ),
				'node'     => $node,
				'dest'     => $dest,
				'target'   => $target,
				'elements' => $elements,
				'reason'   => $reason,
			);
		}
	}

	/** @param list<string> $path */
	private function note( string $uid, array $path, string $expr, string $fate, string $target, string $reason ): void {
		++$this->expressions[ $fate ];
		if ( count( $this->notes ) < self::MAX_ITEMS ) {
			$this->notes[] = array(
				'uid'    => $uid,
				'path'   => implode( ' > ', array_slice( $path, -4 ) ),
				'expr'   => self::short( $expr ),
				'fate'   => $fate,
				'target' => $target,
				'reason' => $reason,
			);
		}
	}

	/** @param array<mixed> $attributes */
	private static function label( string $tag, array $attributes ): string {
		$class = trim( (string) ( is_string( $attributes['class'] ?? null ) ? $attributes['class'] : '' ) );
		$first = '' !== $class ? strtok( $class, " \t\n" ) : false;
		return false !== $first && ! str_contains( $first, '{' ) ? $tag . '.' . $first : $tag;
	}

	private static function short( string $text ): string {
		return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 79 ) . '…' : $text;
	}
}
