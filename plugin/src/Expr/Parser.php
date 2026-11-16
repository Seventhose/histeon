<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

use XB\Spec;

/**
 * Expression parser — docs/spec/dsl.md §4. PHP port of packages/dsl/src/expr.ts; both are checked against
 * packages/spec/vectors/expressions.json.
 *
 * Nodes are plain arrays (cheap to cache and to serialize):
 *   Literal{value} Identifier{name} Member{object,property} Index{object,index} Call{callee,args}
 *   Unary{op,argument} Binary{op,left,right} Conditional{test,consequent,alternate} Array{elements} Object{properties}
 * Args are {name?: string, value: node}.
 *
 * @phpstan-type Node array<string, mixed>
 */
final class Parser {

	private const PRECEDENCE = array(
		'??' => 1,
		'||' => 2,
		'&&' => 3,
		'==' => 4,
		'!=' => 4,
		'<'  => 5,
		'<=' => 5,
		'>'  => 5,
		'>=' => 5,
	);

	private const TWO_CHAR = array( '??', '||', '&&', '==', '!=', '<=', '>=' );
	private const ONE_CHAR = '!<>.,:?()[]{}-';
	private const ESCAPES  = array(
		'n' => "\n",
		't' => "\t",
		'r' => "\r",
	);

	/** @var list<array{t: string, v: string, value: string|int|float|null, start: int}> */
	private array $tokens = array();
	private int $i = 0;
	/** @var list<array<string, mixed>> */
	private array $preset_calls = array();
	private int $depth          = 0;

	private const MAX_DEPTH    = 64;
	private const MAX_ELEMENTS = 1000;

	/** @var array<string, array<string, mixed>> */
	private static array $cache = array();

	private function __construct(
		private readonly string $source,
		private readonly bool $allow_preset_call,
	) {}

	/**
	 * Parses and caches (per request) an expression.
	 *
	 * @return array<string, mixed>
	 * @throws SyntaxError When the expression is invalid.
	 */
	public static function parse( string $source, bool $allow_preset_call = false ): array {
		$key = ( $allow_preset_call ? 'p:' : 'e:' ) . $source;
		if ( ! isset( self::$cache[ $key ] ) ) {
			if ( count( self::$cache ) > 2000 ) {
				self::$cache = array();
			}
			$parser = new self( $source, $allow_preset_call );
			$parser->tokenize();
			self::$cache[ $key ] = $parser->parse_root();
		}
		return self::$cache[ $key ];
	}

	// ------------------------------------------------------------------ lexer

	private function tokenize(): void {
		$src = $this->source;
		$len = strlen( $src );
		$i   = 0;
		while ( $i < $len ) {
			$c = $src[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( ctype_digit( $c ) ) {
				$text           = 1 === preg_match( '/\d+(?:\.\d+)?/A', $src, $m, 0, $i ) ? $m[0] : $c;
				$this->tokens[] = array(
					't'     => 'num',
					'v'     => $text,
					'value' => str_contains( $text, '.' ) ? (float) $text : (int) $text,
					'start' => $i,
				);
				$i             += strlen( $text );
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$j     = $i + 1;
				$value = '';
				while ( true ) {
					if ( $j >= $len ) {
						throw new SyntaxError( __( 'Stringa non chiusa', 'xb' ), $i );
					}
					$d = $src[ $j ];
					if ( $d === $c ) {
						break;
					}
					if ( '\\' === $d ) {
						$e      = $src[ $j + 1 ] ?? '';
						$value .= self::ESCAPES[ $e ] ?? $e;
						$j     += 2;
						continue;
					}
					$value .= $d;
					++$j;
				}
				$this->tokens[] = array(
					't'     => 'str',
					'v'     => substr( $src, $i, $j + 1 - $i ),
					'value' => $value,
					'start' => $i,
				);
				$i              = $j + 1;
				continue;
			}
			if ( ctype_alpha( $c ) || '_' === $c || '$' === $c ) {
				$name           = 1 === preg_match( '/[A-Za-z_$][A-Za-z0-9_$]*/A', $src, $m, 0, $i ) ? $m[0] : $c;
				$this->tokens[] = array(
					't'     => 'ident',
					'v'     => $name,
					'value' => null,
					'start' => $i,
				);
				$i             += strlen( $name );
				continue;
			}
			$three = substr( $src, $i, 3 );
			if ( '===' === $three || '!==' === $three ) {
				throw new SyntaxError( sprintf( /* translators: %s: operator */ __( 'Operatore "%s" non supportato: "==" e "!=" sono già stretti', 'xb' ), $three ), $i );
			}
			$two = substr( $src, $i, 2 );
			if ( in_array( $two, self::TWO_CHAR, true ) ) {
				$this->tokens[] = array(
					't'     => 'punct',
					'v'     => $two,
					'value' => null,
					'start' => $i,
				);
				$i             += 2;
				continue;
			}
			if ( str_contains( self::ONE_CHAR, $c ) ) {
				$this->tokens[] = array(
					't'     => 'punct',
					'v'     => $c,
					'value' => null,
					'start' => $i,
				);
				++$i;
				continue;
			}
			throw new SyntaxError( sprintf( /* translators: %s: character */ __( 'Carattere inatteso "%s" nell\'espressione', 'xb' ), $c ), $i );
		}
		$this->tokens[] = array(
			't'     => 'eof',
			'v'     => '',
			'value' => null,
			'start' => $len,
		);
	}

	// ------------------------------------------------------------------ parser

	/** @return array<string, mixed> */
	private function parse_root(): array {
		if ( 'eof' === $this->peek()['t'] ) {
			throw new SyntaxError( __( 'Espressione vuota', 'xb' ), 0 );
		}
		$root = $this->parse_conditional();
		$rest = $this->peek();
		if ( 'eof' !== $rest['t'] ) {
			throw new SyntaxError( sprintf( /* translators: %s: token */ __( 'Token inatteso "%s"', 'xb' ), $rest['v'] ), $rest['start'] );
		}
		if ( array() !== $this->preset_calls && ( count( $this->preset_calls ) > 1 || ( $root !== $this->preset_calls[0] && ! $this->is_preset_group_by( $root, $this->preset_calls[0] ) ) ) ) {
			throw new SyntaxError( __( 'Un preset di loop deve essere l\'intera sorgente del loop: dopo la query è ammesso solo .groupBy("campo") o .groupBy("campo", "modo")', 'xb' ), 0 );
		}
		return $root;
	}

	/**
	 * `preset(…).groupBy("field", "how"?)` (doc 23 of Publish, item 187): one groupBy right after the call, with literal
	 * strings, as packages/dsl/src/expr.ts reads it.
	 *
	 * @param array<string, mixed> $root
	 * @param array<string, mixed> $call
	 * @throws SyntaxError When the groupBy has other arguments.
	 */
	private function is_preset_group_by( array $root, array $call ): bool {
		$callee = is_array( $root['callee'] ?? null ) ? $root['callee'] : array();
		if ( 'Call' !== $root['type'] || 'Member' !== ( $callee['type'] ?? null ) || 'groupBy' !== ( $callee['property'] ?? null ) || ( $callee['object'] ?? null ) !== $call ) {
			return false;
		}
		$values = array();
		foreach ( is_array( $root['args'] ?? null ) ? $root['args'] : array() as $arg ) {
			$value    = is_array( $arg ) && is_array( $arg['value'] ?? null ) ? $arg['value'] : array();
			$values[] = 'Literal' === ( $value['type'] ?? null ) && is_string( $value['value'] ?? null ) ? $value['value'] : null;
		}
		if ( array() === $values || count( $values ) > 2 || in_array( null, $values, true ) ) {
			throw new SyntaxError( __( 'groupBy dopo una query vuole il campo e, se serve, il modo, come testi scritti: .groupBy("anno") oppure .groupBy("date", "Y")', 'xb' ), 0 );
		}
		if ( 1 !== preg_match( Spec::GROUP_BY_FIELD_PATTERN, (string) $values[0] ) ) {
			/* translators: %s: the field written in groupBy */
			throw new SyntaxError( sprintf( __( 'Campo di groupBy non valido "%s": un nome o un percorso con punti (fields.anno)', 'xb' ), mb_substr( (string) $values[0], 0, 80 ) ), 0 );
		}
		if ( isset( $values[1] ) && 1 !== preg_match( Spec::GROUP_BY_HOW_PATTERN, $values[1] ) ) {
			throw new SyntaxError( __( 'Modo di groupBy non valido: "letter" o un formato di data ("Y", "F Y"), al massimo 32 caratteri', 'xb' ), 0 );
		}
		return true;
	}

	/** @return array{t: string, v: string, value: string|int|float|null, start: int} */
	private function peek( int $offset = 0 ): array {
		$index = min( $this->i + $offset, count( $this->tokens ) - 1 );
		return $this->tokens[ $index ];
	}

	/** @return array{t: string, v: string, value: string|int|float|null, start: int} */
	private function next(): array {
		$token = $this->peek();
		if ( 'eof' !== $token['t'] ) {
			++$this->i;
		}
		return $token;
	}

	/** @phpstan-impure Depends on the token cursor, which parsing advances. */
	private function is_punct( string $v, int $offset = 0 ): bool {
		$token = $this->peek( $offset );
		return 'punct' === $token['t'] && $v === $token['v'];
	}

	private function expect( string $v ): void {
		$token = $this->next();
		if ( 'punct' !== $token['t'] || $v !== $token['v'] ) {
			throw new SyntaxError( sprintf( /* translators: 1: expected token, 2: token found (or "end") */ __( 'Atteso "%1$s", trovato "%2$s"', 'xb' ), $v, '' === $token['v'] ? /* translators: end of the expression */ __( 'fine', 'xb' ) : $token['v'] ), $token['start'] );
		}
	}

	/**
	 * `test ? a : b` — lowest precedence, right-associative.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_conditional(): array {
		if ( ++$this->depth > self::MAX_DEPTH ) {
			throw new SyntaxError( __( 'Espressione troppo annidata', 'xb' ), 0 );
		}
		try {
			return $this->parse_conditional_inner();
		} finally {
			--$this->depth;
		}
	}

	/** @return array<string, mixed> */
	private function parse_conditional_inner(): array {
		$test = $this->parse_binary( 1 );
		if ( ! $this->is_punct( '?' ) ) {
			return $test;
		}
		$this->next();
		$consequent = $this->parse_conditional();
		$this->expect( ':' );
		return array(
			'type'       => 'Conditional',
			'test'       => $test,
			'consequent' => $consequent,
			'alternate'  => $this->parse_conditional(),
		);
	}

	/** @return array<string, mixed> */
	private function parse_binary( int $min_prec ): array {
		$left = $this->parse_unary();
		while ( true ) {
			$token = $this->peek();
			$prec  = 'punct' === $token['t'] ? ( self::PRECEDENCE[ $token['v'] ] ?? null ) : null;
			if ( null === $prec || $prec < $min_prec ) {
				return $left;
			}
			$this->next();
			$right = $this->parse_binary( $prec + 1 );
			$left  = array(
				'type'  => 'Binary',
				'op'    => $token['v'],
				'left'  => $left,
				'right' => $right,
			);
		}
	}

	/** @return array<string, mixed> */
	private function parse_unary(): array {
		if ( $this->is_punct( '!' ) ) {
			if ( ++$this->depth > self::MAX_DEPTH ) {
				throw new SyntaxError( __( 'Espressione troppo annidata', 'xb' ), 0 );
			}
			$this->next();
			try {
				$argument = $this->parse_unary();
			} finally {
				--$this->depth;
			}
			return array(
				'type'     => 'Unary',
				'op'       => '!',
				'argument' => $argument,
			);
		}
		return $this->parse_postfix( $this->parse_primary() );
	}

	/** @return array<string, mixed> */
	private function parse_primary(): array {
		$token = $this->next();
		switch ( $token['t'] ) {
			case 'num':
			case 'str':
				return array(
					'type'  => 'Literal',
					'value' => $token['value'],
				);
			case 'ident':
				if ( 'true' === $token['v'] || 'false' === $token['v'] ) {
					return array(
						'type'  => 'Literal',
						'value' => 'true' === $token['v'],
					);
				}
				if ( 'null' === $token['v'] ) {
					return array(
						'type'  => 'Literal',
						'value' => null,
					);
				}
				return array(
					'type' => 'Identifier',
					'name' => $token['v'],
				);
			case 'punct':
				return $this->parse_punct_primary( $token );
			default:
				throw new SyntaxError( __( 'Espressione incompleta', 'xb' ), $token['start'] );
		}
	}

	/**
	 * @param array{t: string, v: string, value: string|int|float|null, start: int} $token
	 * @return array<string, mixed>
	 */
	private function parse_punct_primary( array $token ): array {
		if ( '-' === $token['v'] ) {
			$number = $this->next();
			if ( 'num' !== $number['t'] ) {
				throw new SyntaxError( __( '"-" è ammesso solo davanti a un numero', 'xb' ), $token['start'] );
			}
			return array(
				'type'  => 'Literal',
				'value' => is_int( $number['value'] ) ? -$number['value'] : -(float) $number['value'],
			);
		}
		if ( '(' === $token['v'] ) {
			$inner = $this->parse_conditional();
			$this->expect( ')' );
			return $inner;
		}
		if ( '[' === $token['v'] ) {
			$elements = array();
			while ( ! $this->is_punct( ']' ) ) {
				if ( count( $elements ) >= self::MAX_ELEMENTS ) {
					throw new SyntaxError( __( 'Troppi elementi nella lista', 'xb' ), $token['start'] );
				}
				$elements[] = $this->parse_conditional();
				if ( ! $this->is_punct( ']' ) ) {
					$this->expect( ',' );
				}
			}
			$this->expect( ']' );
			return array(
				'type'     => 'Array',
				'elements' => $elements,
			);
		}
		if ( '{' === $token['v'] ) {
			$properties = array();
			while ( ! $this->is_punct( '}' ) ) {
				$key = $this->next();
				if ( 'ident' !== $key['t'] && 'str' !== $key['t'] ) {
					throw new SyntaxError( __( 'Chiave di oggetto non valida', 'xb' ), $key['start'] );
				}
				$this->expect( ':' );
				$properties[] = array(
					'key'   => 'str' === $key['t'] ? (string) $key['value'] : $key['v'],
					'value' => $this->parse_conditional(),
				);
				if ( ! $this->is_punct( '}' ) ) {
					$this->expect( ',' );
				}
			}
			$this->expect( '}' );
			return array(
				'type'       => 'Object',
				'properties' => $properties,
			);
		}
		throw new SyntaxError( sprintf( /* translators: %s: token */ __( 'Token inatteso "%s"', 'xb' ), $token['v'] ), $token['start'] );
	}

	/**
	 * @param array<string, mixed> $expr
	 * @return array<string, mixed>
	 */
	private function parse_postfix( array $expr ): array {
		while ( true ) {
			if ( $this->is_punct( '.' ) ) {
				$this->next();
				$name = $this->next();
				if ( 'ident' !== $name['t'] ) {
					throw new SyntaxError( __( 'Atteso un nome dopo "."', 'xb' ), $name['start'] );
				}
				$expr = array(
					'type'     => 'Member',
					'object'   => $expr,
					'property' => $name['v'],
				);
				if ( $this->is_punct( '(' ) ) {
					$expr = array(
						'type'   => 'Call',
						'callee' => $expr,
						'args'   => $this->parse_args( false ),
					);
				}
				continue;
			}
			if ( $this->is_punct( '[' ) ) {
				$this->next();
				$index = $this->parse_conditional();
				$this->expect( ']' );
				$expr = array(
					'type'   => 'Index',
					'object' => $expr,
					'index'  => $index,
				);
				continue;
			}
			if ( $this->is_punct( '(' ) ) {
				if ( 'Identifier' !== $expr['type'] || ! $this->allow_preset_call ) {
					throw new SyntaxError(
						__( 'Chiamata a funzione non consentita: usa un modificatore (es. valore.upper())', 'xb' ),
						$this->peek()['start']
					);
				}
				$expr                 = array(
					'type'   => 'Call',
					'callee' => $expr,
					'args'   => $this->parse_args( true ),
				);
				$this->preset_calls[] = $expr;
				continue;
			}
			return $expr;
		}
	}

	/** @return list<array<string, mixed>> */
	private function parse_args( bool $named ): array {
		$this->expect( '(' );
		$args = array();
		$seen = array();
		while ( ! $this->is_punct( ')' ) ) {
			$start    = $this->peek()['start'];
			$is_named = 'ident' === $this->peek()['t'] && $this->is_punct( ':', 1 );
			if ( $is_named !== $named ) {
				throw new SyntaxError(
					$named
						? __( 'Gli argomenti di un preset devono essere nominati (es. limit: 3)', 'xb' )
						: __( 'I modificatori accettano solo argomenti posizionali', 'xb' ),
					$start
				);
			}
			$arg = array();
			if ( $is_named ) {
				$name = $this->next()['v'];
				$this->next();
				if ( isset( $seen[ $name ] ) ) {
					throw new SyntaxError( sprintf( /* translators: %s: argument name */ __( 'Argomento "%s" duplicato', 'xb' ), $name ), $start );
				}
				$seen[ $name ] = true;
				$arg['name']   = $name;
			}
			$arg['value'] = $this->parse_conditional();
			$args[]       = $arg;
			if ( ! $this->is_punct( ')' ) ) {
				$this->expect( ',' );
			}
		}
		$this->expect( ')' );
		return $args;
	}
}
