<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/**
 * Evaluates parsed expressions against a scope (root name → value). Never executes PHP from the expression:
 * the only callable behaviour is the modifier registry.
 */
final class Evaluator {

	public function __construct( private readonly Modifiers $modifiers ) {}

	/**
	 * @param array<string, mixed> $scope
	 * @throws SyntaxError|EvalError
	 */
	public function evaluate_source( string $source, array $scope ): mixed {
		return $this->evaluate( Parser::parse( $source ), $scope );
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 * @throws EvalError
	 */
	public function evaluate( array $node, array $scope ): mixed {
		switch ( $node['type'] ) {
			case 'Literal':
				return $node['value'];
			case 'Identifier':
				return $scope[ $node['name'] ] ?? null;
			case 'Member':
				return Value::member( $this->evaluate( $node['object'], $scope ), $node['property'] );
			case 'Index':
				$key = $this->evaluate( $node['index'], $scope );
				return is_int( $key ) || is_string( $key ) ? Value::member( $this->evaluate( $node['object'], $scope ), $key ) : null;
			case 'Unary':
				return ! Value::truthy( $this->evaluate( $node['argument'], $scope ) );
			case 'Binary':
				return $this->binary( $node, $scope );
			case 'Conditional':
				return Value::truthy( $this->evaluate( $node['test'], $scope ) )
					? $this->evaluate( $node['consequent'], $scope )
					: $this->evaluate( $node['alternate'], $scope );
			case 'Array':
				return array_map( fn ( array $element ): mixed => $this->evaluate( $element, $scope ), $node['elements'] );
			case 'Object':
				$out = array();
				foreach ( $node['properties'] as $property ) {
					$out[ $property['key'] ] = $this->evaluate( $property['value'], $scope );
				}
				return $out;
			case 'Call':
				if ( 'Member' !== $node['callee']['type'] ) {
					throw new EvalError( __( 'Un preset può essere usato solo come sorgente di un loop', 'xb' ) );
				}
				$subject = $this->evaluate( $node['callee']['object'], $scope );
				$args    = array_values( array_map( fn ( array $arg ): mixed => $this->evaluate( $arg['value'], $scope ), $node['args'] ) );
				return $this->modifiers->apply( $node['callee']['property'], $subject, $args );
		}
		throw new EvalError( __( 'Nodo di espressione sconosciuto', 'xb' ) );
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $scope
	 */
	private function binary( array $node, array $scope ): mixed {
		$op = $node['op'];
		if ( '??' === $op ) {
			return $this->evaluate( $node['left'], $scope ) ?? $this->evaluate( $node['right'], $scope );
		}
		if ( '||' === $op ) {
			return Value::truthy( $this->evaluate( $node['left'], $scope ) ) || Value::truthy( $this->evaluate( $node['right'], $scope ) );
		}
		if ( '&&' === $op ) {
			return Value::truthy( $this->evaluate( $node['left'], $scope ) ) && Value::truthy( $this->evaluate( $node['right'], $scope ) );
		}
		$left  = $this->evaluate( $node['left'], $scope );
		$right = $this->evaluate( $node['right'], $scope );
		return match ( $op ) {
			'==' => Value::equals( $left, $right ),
			'!=' => ! Value::equals( $left, $right ),
			default => Value::compare( $op, $left, $right ),
		};
	}
}
