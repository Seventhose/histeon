<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import\Etch;

use XB\Expr\Modifiers;
use XB\Expr\Parser;
use XB\Expr\SyntaxError;
use XB\Expr\Template;

/**
 * Expressions as saved by Etch → XB expressions — docs/spec/import-etch.md §3.
 * The two languages share braces, dotted paths and method-style modifiers; the rewrite is token-based (strings
 * are never touched) and every result is checked with XB's parser. What does not translate is reported, never
 * guessed.
 */
final class Expressions {

	/** Etch modifiers that are XB modifiers under another name, renamed by the token rewrite. */
	public const RENAMED = array(
		'dateFormat'  => 'date',
		'format'      => 'date',
		'toUpperCase' => 'upper',
		'toLowerCase' => 'lower',
		'toSlug'      => 'slug',
	);

	/** Etch's comparison modifiers (`x.less(3, "sì", "no")`) and the XB operator each becomes. */
	private const COMPARISONS = array(
		'equal'          => '==',
		'less'           => '<',
		'lessOrEqual'    => '<=',
		'greater'        => '>',
		'greaterOrEqual' => '>=',
	);

	/** Fields XB's post node knows natively; anything else under a post comes from `xb_post_data`. */
	public const POST_FIELDS = array( 'id', 'title', 'url', 'slug', 'type', 'excerpt', 'date', 'modified', 'author', 'image', 'categories', 'tags', 'terms', 'meta', 'fields', 'acf', 'jetengine', 'metabox', 'parent', 'commentCount', 'readingTime' );

	/**
	 * Etch's own post fields (its base post data, and WordPress's raw post fields it adds) that XB names otherwise.
	 * Etch's `featuredImage` and `thumbnail` are both the full-size image URL.
	 */
	public const POST_RENAMED = array(
		'featuredImage' => array( 'image', 'url' ),
		'thumbnail'     => array( 'image', 'url' ),
		'post_date'     => array( 'date' ),
		'post_modified' => array( 'modified' ),
		'post_title'    => array( 'title' ),
		'post_name'     => array( 'slug' ),
		'post_excerpt'  => array( 'excerpt' ),
		'post_type'     => array( 'type' ),
		'ID'            => array( 'id' ),
	);

	/** @var list<string> */
	public array $issues = array();
	/** @var array<string, true> Custom post fields used (e.g. `evento`), for the report. */
	public array $custom = array();

	/**
	 * @param list<string> $post_roots Names that hold a post here: `this` plus the loop variables in scope.
	 */
	public function expression( string $source, array $post_roots = array( 'this' ) ): ?string {
		$out = $this->rewrite( $source );
		try {
			$node    = Parser::parse( $out );
			$changed = false;
			$node    = $this->translate( $node, $changed );
			$node    = $this->fields( $node, $post_roots, $changed );
			if ( $changed ) {
				$out = self::print( $node );
				Parser::parse( $out );
			}
		} catch ( SyntaxError $e ) {
			$this->issues[] = sprintf( /* translators: 1: expression, 2: error */ __( 'Espressione non convertita: {%1$s} (%2$s)', 'xb' ), self::short( $source ), $e->getMessage() );
			return null;
		}
		$this->collect_custom( $out, $post_roots );
		return $out;
	}

	/**
	 * Etch modifiers that XB writes another way (§3): `isTruthy`/`isFalsy` and the comparisons become operators,
	 * `includes` with values for true and false a condition, `at` an index, `slice(start, end)` XB's
	 * `slice(start, length)`, `startsWith`/`endsWith` a comparison with a slice. A modifier XB does not know is an
	 * error: the expression stays text in the page and the report names it.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 * @throws SyntaxError When a modifier has no XB form.
	 */
	private function translate( array $node, bool &$changed ): array {
		foreach ( array( 'object', 'argument', 'left', 'right', 'test', 'consequent', 'alternate', 'index', 'callee' ) as $key ) {
			if ( is_array( $node[ $key ] ?? null ) ) {
				$node[ $key ] = $this->translate( $node[ $key ], $changed );
			}
		}
		foreach ( array( 'elements', 'args', 'properties' ) as $key ) {
			foreach ( is_array( $node[ $key ] ?? null ) ? $node[ $key ] : array() as $i => $child ) {
				if ( 'elements' === $key ) {
					$node[ $key ][ $i ] = $this->translate( $child, $changed );
				} else {
					$node[ $key ][ $i ]['value'] = $this->translate( $child['value'], $changed );
				}
			}
		}
		if ( 'Call' !== ( $node['type'] ?? '' ) || 'Member' !== ( $node['callee']['type'] ?? '' ) ) {
			return $node;
		}
		$name    = (string) $node['callee']['property'];
		$subject = $node['callee']['object'];
		$args    = array_map( static fn ( array $a ): array => $a['value'], $node['args'] );
		$true    = $args[0] ?? self::literal( true );
		$false   = $args[1] ?? self::literal( false );
		$result  = null;
		if ( 'isTruthy' === $name && count( $args ) <= 2 ) {
			$result = self::condition( $subject, $true, $false );
		} elseif ( 'isFalsy' === $name && count( $args ) <= 2 ) {
			$result = self::condition( $subject, $args[1] ?? self::literal( false ), $args[0] ?? self::literal( true ) );
		} elseif ( isset( self::COMPARISONS[ $name ] ) && count( $args ) >= 1 && count( $args ) <= 3 ) {
			$test   = array(
				'type'  => 'Binary',
				'op'    => self::COMPARISONS[ $name ],
				'left'  => $subject,
				'right' => $args[0],
			);
			$result = count( $args ) > 1 ? self::condition( $test, $args[1], $args[2] ?? self::literal( false ) ) : $test;
		} elseif ( 'includes' === $name && count( $args ) > 1 && count( $args ) <= 3 ) {
			$node['args'] = array( array( 'value' => $args[0] ) );
			$result       = self::condition( $node, $args[1], $args[2] ?? self::literal( false ) );
		} elseif ( ( 'startsWith' === $name || 'endsWith' === $name ) && count( $args ) >= 1 && count( $args ) <= 3 && is_string( self::constant( $args[0] ) ) ) {
			$length = mb_strlen( (string) self::constant( $args[0] ) );
			$slice  = 'startsWith' === $name ? array( self::literal( 0 ), self::literal( $length ) ) : array( self::literal( -$length ) );
			$test   = array(
				'type'  => 'Binary',
				'op'    => '==',
				'left'  => self::call( $subject, 'slice', $slice ),
				'right' => $args[0],
			);
			$result = count( $args ) > 1 ? self::condition( $test, $args[1], $args[2] ?? self::literal( false ) ) : $test;
		} elseif ( 'at' === $name && 1 === count( $args ) && is_int( self::constant( $args[0] ) ) && self::constant( $args[0] ) >= 0 ) {
			$result = array(
				'type'   => 'Index',
				'object' => $subject,
				'index'  => $args[0],
			);
		} elseif ( 'slice' === $name && 2 === count( $args ) ) {
			$start = self::constant( $args[0] );
			$end   = self::constant( $args[1] );
			if ( ! is_int( $start ) || ! is_int( $end ) || $start < 0 || $end < $start ) {
				throw new SyntaxError( __( 'slice(inizio, fine) di Etch con valori calcolati o negativi non ha una forma in Histeon', 'xb' ), 0 );
			}
			$result = self::call( $subject, 'slice', array( $args[0], self::literal( $end - $start ) ) );
		} elseif ( ( 'truncateWords' === $name || 'truncateChars' === $name ) && 1 === count( $args ) ) {
			$result = self::call( $subject, $name, array( $args[0], self::literal( '...' ) ) ); // Etch's ellipsis.
		} elseif ( ! in_array( $name, self::modifiers(), true ) ) {
			throw new SyntaxError( sprintf( /* translators: %s: modifier name */ __( 'il modificatore di Etch "%s" non ha un equivalente in Histeon', 'xb' ), $name ), 0 );
		}
		if ( null === $result ) {
			return $node;
		}
		$changed = true;
		return $result;
	}

	/**
	 * Etch's names for post fields XB has under another name (POST_RENAMED), on the posts in scope; and the query
	 * string, `url.parameter.<name>` in Etch, `url.query.<name>` in XB.
	 *
	 * @param array<string, mixed> $node
	 * @param list<string>         $post_roots
	 * @return array<string, mixed>
	 */
	private function fields( array $node, array $post_roots, bool &$changed ): array {
		foreach ( array( 'object', 'argument', 'left', 'right', 'test', 'consequent', 'alternate', 'index', 'callee' ) as $key ) {
			if ( is_array( $node[ $key ] ?? null ) ) {
				$node[ $key ] = $this->fields( $node[ $key ], $post_roots, $changed );
			}
		}
		foreach ( array( 'elements', 'args', 'properties' ) as $key ) {
			foreach ( is_array( $node[ $key ] ?? null ) ? $node[ $key ] : array() as $i => $child ) {
				if ( 'elements' === $key ) {
					$node[ $key ][ $i ] = $this->fields( $child, $post_roots, $changed );
				} else {
					$node[ $key ][ $i ]['value'] = $this->fields( $child['value'], $post_roots, $changed );
				}
			}
		}
		if ( 'Member' !== ( $node['type'] ?? '' ) || 'Identifier' !== ( $node['object']['type'] ?? '' ) ) {
			return $node;
		}
		$root     = (string) $node['object']['name'];
		$property = (string) $node['property'];
		if ( in_array( $root, $post_roots, true ) && isset( self::POST_RENAMED[ $property ] ) ) {
			$out = $node['object'];
			foreach ( self::POST_RENAMED[ $property ] as $name ) {
				$out = array(
					'type'     => 'Member',
					'object'   => $out,
					'property' => $name,
				);
			}
			$changed = true;
			return $out;
		}
		if ( 'url' === $root && 'parameter' === $property && ! in_array( 'url', $post_roots, true ) ) {
			$node['property'] = 'query';
			$changed          = true;
		}
		return $node;
	}

	/** @return list<string> XB's modifiers, with those a site adds (`xb_modifiers`). */
	private static function modifiers(): array {
		static $names = null;
		$names ??= ( new Modifiers( static fn (): array => function_exists( 'apply_filters' ) ? (array) apply_filters( 'xb_modifiers', array() ) : array() ) )->names();
		return $names;
	}

	/**
	 * @param array<string, mixed> $test
	 * @param array<string, mixed> $yes
	 * @param array<string, mixed> $no
	 * @return array<string, mixed>
	 */
	private static function condition( array $test, array $yes, array $no ): array {
		return array(
			'type'       => 'Conditional',
			'test'       => $test,
			'consequent' => $yes,
			'alternate'  => $no,
		);
	}

	/**
	 * @param array<string, mixed>       $subject
	 * @param list<array<string, mixed>> $args
	 * @return array<string, mixed>
	 */
	private static function call( array $subject, string $name, array $args ): array {
		return array(
			'type'   => 'Call',
			'callee' => array(
				'type'     => 'Member',
				'object'   => $subject,
				'property' => $name,
			),
			'args'   => array_map( static fn ( array $a ): array => array( 'value' => $a ), $args ),
		);
	}

	/** @return array<string, mixed> */
	private static function literal( mixed $value ): array {
		return array(
			'type'  => 'Literal',
			'value' => $value,
		);
	}

	/** @param array<string, mixed> $node */
	private static function constant( array $node ): mixed {
		return 'Literal' === ( $node['type'] ?? '' ) ? $node['value'] : null;
	}

	/**
	 * The expression back as text. Operators and conditions inside another node get parentheses, always: a few
	 * more than needed, never one too few.
	 *
	 * @param array<string, mixed> $node
	 */
	private static function print( array $node ): string {
		$inner = static fn ( array $n ): string => in_array( $n['type'] ?? '', array( 'Binary', 'Conditional' ), true ) ? '(' . self::print( $n ) . ')' : self::print( $n );
		switch ( $node['type'] ?? '' ) {
			case 'Literal':
				$v = $node['value'];
				if ( is_string( $v ) ) {
					return '"' . str_replace( array( '\\', '"', "\n", "\t", "\r" ), array( '\\\\', '\\"', '\\n', '\\t', '\\r' ), $v ) . '"';
				}
				if ( is_bool( $v ) ) {
					return $v ? 'true' : 'false';
				}
				if ( null === $v ) {
					return 'null';
				}
				return is_float( $v ) ? rtrim( rtrim( sprintf( '%.10F', $v ), '0' ), '.' ) : (string) $v;
			case 'Identifier':
				return (string) $node['name'];
			case 'Member':
				return $inner( $node['object'] ) . '.' . $node['property'];
			case 'Call':
				return $inner( $node['callee'] ) . '(' . implode( ', ', array_map( static fn ( array $a ): string => self::print( $a['value'] ), $node['args'] ) ) . ')';
			case 'Index':
				return $inner( $node['object'] ) . '[' . self::print( $node['index'] ) . ']';
			case 'Unary':
				return '!' . $inner( $node['argument'] );
			case 'Binary':
				return $inner( $node['left'] ) . ' ' . $node['op'] . ' ' . $inner( $node['right'] );
			case 'Conditional':
				return $inner( $node['test'] ) . ' ? ' . $inner( $node['consequent'] ) . ' : ' . $inner( $node['alternate'] );
			case 'Array':
				return '[' . implode( ', ', array_map( self::print( ... ), $node['elements'] ) ) . ']';
			case 'Object':
				return '{' . implode( ', ', array_map( static fn ( array $p ): string => self::print( self::literal( (string) $p['key'] ) ) . ': ' . self::print( $p['value'] ), $node['properties'] ) ) . '}';
			default:
				throw new SyntaxError( __( 'Espressione non rappresentabile', 'xb' ), 0 );
		}
	}

	/**
	 * A text or attribute value with interpolations. Untranslatable parts become literal text (braces escaped).
	 *
	 * @param list<string> $post_roots
	 */
	public function template( string $value, array $post_roots = array( 'this' ) ): string {
		if ( ! str_contains( $value, '{' ) ) {
			return $value;
		}
		$out = '';
		$len = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $value[ $i ];
			if ( '\\' === $c && isset( $value[ $i + 1 ] ) && ( '{' === $value[ $i + 1 ] || '}' === $value[ $i + 1 ] ) ) {
				$out .= $c . $value[ $i + 1 ];
				++$i;
				continue;
			}
			if ( '{' !== $c ) {
				$out .= '}' === $c ? '\\}' : $c;
				continue;
			}
			try {
				$close = Template::closing_brace( $value, $i );
			} catch ( SyntaxError ) {
				$this->issues[] = sprintf( /* translators: %s: text */ __( 'Graffa non chiusa in "%s": resa come testo', 'xb' ), self::short( $value ) );
				$out .= '\\{';
				continue;
			}
			$inner = substr( $value, $i + 1, $close - $i - 1 );
			$xb    = $this->expression( $inner, $post_roots );
			$out  .= null === $xb ? '\\{' . str_replace( array( '{', '}' ), array( '\\{', '\\}' ), $inner ) . '\\}' : '{' . $xb . '}';
			$i     = $close;
		}
		return $out;
	}

	/** Token rewrite outside string literals. */
	public function rewrite( string $source ): string {
		$out  = '';
		$len  = strlen( $source );
		$i    = 0;
		$path = false; // The last token was a name, an index or a call: a `.` after it is a member.
		while ( $i < $len ) {
			$c = $source[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end = $i + 1;
				while ( $end < $len && $source[ $end ] !== $c ) {
					$end += '\\' === $source[ $end ] ? 2 : 1;
				}
				$out .= substr( $source, $i, $end - $i + 1 );
				$i    = $end + 1;
				$path = false;
				continue;
			}
			if ( 1 === preg_match( '/\G[A-Za-z_$][A-Za-z0-9_$]*/', $source, $m, 0, $i ) ) {
				$word   = $m[0];
				$member = '.' === self::previous( $source, $i );
				$next   = substr( $source, $i + strlen( $word ) );
				if ( $member && isset( self::RENAMED[ $word ] ) && ( 'dateFormat' === $word || 1 === preg_match( '/^\s*\(/', $next ) ) ) {
					$word = self::RENAMED[ $word ]; // `format` and the others only as modifiers: a field may have that name.
				} elseif ( $member && 'permalink' === $word ) {
					$word = 'url';
					if ( 1 === preg_match( '/^\s*\.\s*(relative|full|absolute)\b/', $next, $p ) ) {
						$i += strlen( $p[0] ); // `.permalink.relative` → `.url`.
					}
				}
				$out .= $word;
				$i   += strlen( $m[0] );
				$path = true;
				continue;
			}
			// A numeric path segment (`item.categories.0.name`) is an index in XB: `item.categories[0].name`.
			if ( '.' === $c && $path && 1 === preg_match( '/\G\.\s*(\d+)(?![\w$])/', $source, $n, 0, $i ) ) {
				$out .= '[' . $n[1] . ']';
				$i   += strlen( $n[0] );
				continue;
			}
			if ( ! ctype_space( $c ) ) {
				$path = ']' === $c || ')' === $c;
			}
			$out .= $c;
			++$i;
		}
		return $out;
	}

	private static function previous( string $s, int $i ): string {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! ctype_space( $s[ $j ] ) ) {
				return $s[ $j ];
			}
		}
		return '';
	}

	/** @param list<string> $post_roots */
	private function collect_custom( string $expr, array $post_roots ): void {
		$roots = implode( '|', array_map( static fn ( string $r ): string => preg_quote( $r, '/' ), $post_roots ) );
		if ( '' === $roots ) {
			return;
		}
		preg_match_all( '/(?<![\w$.])(?:' . $roots . ')\s*\.\s*([A-Za-z_$][\w$]*)/', (string) preg_replace( '/(["\']).*?(?<!\\\\)\1/s', '""', $expr ), $m );
		foreach ( $m[1] as $field ) {
			if ( ! in_array( $field, self::POST_FIELDS, true ) ) {
				$this->custom[ $field ] = true;
			}
		}
	}

	private static function short( string $s ): string {
		return mb_strlen( $s ) > 80 ? mb_substr( $s, 0, 77 ) . '…' : $s;
	}
}
