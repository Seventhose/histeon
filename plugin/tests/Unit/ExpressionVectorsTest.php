<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XB\Expr\EvalError;
use XB\Expr\Evaluator;
use XB\Expr\Modifiers;
use XB\Expr\Parser;
use XB\Expr\SyntaxError;

/** Runs packages/spec/vectors/expressions.json — the same file the TypeScript parser is tested against. */
final class ExpressionVectorsTest extends TestCase {

	/** @return array<string, mixed> */
	private static function vectors(): array {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/packages/spec/vectors/expressions.json' );
		$data = json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
		return is_array( $data ) ? $data : array();
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function parse_vectors(): iterable {
		foreach ( self::vectors()['parse'] as $i => $vector ) {
			yield $i . ': ' . $vector['source'] => array( $vector );
		}
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function eval_vectors(): iterable {
		foreach ( self::vectors()['eval'] as $i => $vector ) {
			yield $i . ': ' . $vector['source'] => array( $vector );
		}
	}

	/** @param array<string, mixed> $vector */
	#[DataProvider( 'parse_vectors' )]
	public function test_parse( array $vector ): void {
		$preset = ( $vector['preset'] ?? false ) === true;
		if ( isset( $vector['error'] ) ) {
			$this->expectException( SyntaxError::class );
			$this->expectExceptionMessage( (string) $vector['error'] );
		}
		$this->assertNotEmpty( Parser::parse( (string) $vector['source'], $preset ) );
	}

	/** @param array<string, mixed> $vector */
	#[DataProvider( 'eval_vectors' )]
	public function test_eval( array $vector ): void {
		date_default_timezone_set( 'UTC' );
		$evaluator = new Evaluator( new Modifiers() );
		$scope     = is_array( $vector['scope'] ?? null ) ? $vector['scope'] : array();
		if ( isset( $vector['error'] ) ) {
			$this->expectException( EvalError::class );
			$this->expectExceptionMessage( (string) $vector['error'] );
		}
		$this->assertSame( $vector['result'] ?? null, $evaluator->evaluate_source( (string) $vector['source'], $scope ) );
	}
}
