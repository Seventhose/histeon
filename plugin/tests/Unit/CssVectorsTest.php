<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XB\Styles\CssCompiler;
use XB\Styles\StyleRegistry;

/** Runs packages/spec/vectors/css.json — the same file the TypeScript compiler is tested against. */
final class CssVectorsTest extends TestCase {

	/** @return array<string, mixed> */
	private static function vectors(): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/packages/spec/vectors/css.json' ), true, 512, JSON_THROW_ON_ERROR );
		return is_array( $data ) ? $data : array();
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function compile_vectors(): iterable {
		foreach ( self::vectors()['compile'] as $i => $v ) {
			yield $i . ': ' . $v['selector'] => array( $v['selector'], $v['body'], $v['css'] );
		}
	}

	/** @return iterable<string, array{string, ?string}> */
	public static function key_vectors(): iterable {
		foreach ( self::vectors()['keyClass'] as $v ) {
			yield $v['selector'] => array( $v['selector'], $v['key'] );
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function named_query_vectors(): iterable {
		foreach ( self::vectors()['namedQueries']['cases'] as $v ) {
			yield $v['css'] => array( $v['css'], $v['out'] );
		}
	}

	#[DataProvider( 'named_query_vectors' )]
	public function test_named_queries( string $css, string $out ): void {
		$this->assertSame( $out, \XB\Styles\NamedQueries::resolve( $css, self::vectors()['namedQueries']['queries'] ) );
	}

	#[DataProvider( 'compile_vectors' )]
	public function test_compile( string $selector, string $body, string $css ): void {
		$this->assertSame( $css, CssCompiler::compile( $selector, $body ) );
	}

	#[DataProvider( 'key_vectors' )]
	public function test_key_class( string $selector, ?string $key ): void {
		$this->assertSame( $key, StyleRegistry::key_class( $selector ) );
	}
}
