<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Import\Choices;

/**
 * The choices of an HTML import (doc 27 of Histeon Publish, G4) against the fixture shared with Publish
 * (tests/fixtures/histos-import-choices-v1.json, a byte-identical copy of packages/histos-contract/fixtures/ there,
 * written by Publish's import-choices-contract.test.ts from its code): the same rules, prompt, schemas, sorting and
 * reading of a reply, so the PHP route checks a model's answers exactly as Publish does.
 */
final class ImportChoicesVectorsTest extends TestCase {

	/** Pinned in both repositories (here and in Publish's import-choices-contract.test.ts). */
	private const FIXTURE_SHA256 = '4d3b5afce43e08e087936bb584a00f9040c912929dda3e2bb4506f6716553241';

	private static function bytes(): string {
		$bytes = file_get_contents( dirname( __DIR__ ) . '/fixtures/histos-import-choices-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $bytes ? '' : $bytes;
	}

	/** @return array<string, mixed> */
	private static function fixture(): array {
		$decoded = json_decode( self::bytes(), true, 512, JSON_THROW_ON_ERROR );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Decoded as a reply is decoded: objects as stdClass, so a JSON object and a list stay distinct. */
	private static function objects(): \stdClass {
		$decoded = json_decode( self::bytes(), false, 512, JSON_THROW_ON_ERROR );
		self::assertInstanceOf( \stdClass::class, $decoded );
		return $decoded;
	}

	/** JSON of a value with the keys of every object sorted: structures compare whatever order PHP built them in. */
	private static function canonical( mixed $value ): string {
		return (string) json_encode( self::sorted( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'sorted' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}

	public function test_the_fixture_is_the_pinned_one(): void {
		$this->assertSame( self::FIXTURE_SHA256, hash( 'sha256', self::bytes() ) );
		$fixture = self::fixture();
		$this->assertSame( Choices::ID, $fixture['id'] );
		$this->assertSame( 1, $fixture['version'] );
	}

	public function test_the_rules_and_constants_are_publish_s(): void {
		$fixture = self::fixture();
		$this->assertSame( Choices::KINDS, $fixture['kinds'] );
		foreach ( Choices::KINDS as $kind ) {
			$this->assertSame( $fixture['rules'][ $kind ], Choices::RULES[ $kind ], $kind );
		}
		$this->assertSame( Choices::RESERVED_PROPS, $fixture['reservedProps'] );
		$this->assertSame( Choices::RESERVED_TYPES, $fixture['reservedTypes'] );
		$this->assertSame( Choices::ALT_DECORATIVE, $fixture['altDecorative'] );
		$this->assertSame( Choices::CONTEXT_MAX, $fixture['contextMax'] );
		$this->assertSame( Choices::BATCH_SIZE, $fixture['batchSize'] );
		$this->assertSame( Choices::MAX_BATCHES, $fixture['maxBatches'] );
		$this->assertSame( $fixture['systemPrompt'], Choices::SYSTEM_PROMPT );
		$this->assertLessThan( 1500, Choices::js_length( Choices::SYSTEM_PROMPT ) );
		$this->assertSame( self::canonical( $fixture['schema'] ), self::canonical( Choices::response_schema() ) );
	}

	public function test_every_shape_has_publish_s_outcome(): void {
		foreach ( self::fixture()['shapes'] as $index => $shape ) {
			$this->assertSame( $shape['problem'], Choices::shape_problem( $shape['kind'], $shape['value'] ), "#$index {$shape['kind']} " . json_encode( $shape['value'], JSON_UNESCAPED_UNICODE ) );
		}
	}

	public function test_answers_are_sorted_as_publish_sorts_them(): void {
		$fixture = self::objects();
		$choices = array_map(
			static fn ( \stdClass $choice ): array => array(
				'id'      => (string) $choice->id,
				'kind'    => (string) $choice->kind,
				'value'   => (string) $choice->value,
				'context' => (string) $choice->context,
			),
			$fixture->choices
		);
		foreach ( $fixture->sorts as $sort ) {
			$expected = json_decode( (string) json_encode( $sort->expected ), true );
			$this->assertSame( self::canonical( $expected ), self::canonical( Choices::sort_answers( array_values( $choices ), $sort->answers ) ), $sort->name );
		}
	}

	public function test_batches_are_written_byte_for_byte(): void {
		foreach ( self::fixture()['messages'] as $index => $message ) {
			$this->assertSame( $message['user'], Choices::user_message( $message['batch'] ), "messaggio #$index" );
			$ids = array_map( static fn ( array $choice ): string => $choice['id'], $message['batch'] );
			$this->assertSame( self::canonical( $message['idSchema'] ), self::canonical( Choices::response_schema( array_values( $ids ) ) ), "schema #$index" );
		}
	}

	public function test_replies_are_read_as_publish_reads_them(): void {
		foreach ( self::objects()->replies as $index => $reply ) {
			try {
				$items = Choices::parse_reply( $reply->text );
				$this->assertFalse( property_exists( $reply, 'error' ), "#$index: atteso «" . ( $reply->error ?? '' ) . '»' );
				$this->assertSame( json_encode( $reply->items ), json_encode( $items ), "#$index" );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertTrue( property_exists( $reply, 'error' ), "#$index: " . $e->getMessage() );
				$this->assertSame( $reply->error, $e->getMessage(), "#$index" );
			}
		}
	}

	public function test_a_request_s_choices_are_checked(): void {
		$good = array(
			array(
				'id'      => 'classe:x-1',
				'kind'    => 'classe',
				'value'   => 'x-1',
				'context' => '<p>: «testo»',
			),
		);
		$this->assertSame( $good, Choices::read_choices( $good, 200 ) );
		$bad = array(
			'non un elenco'          => array( 'a' => $good[0] ),
			'tipo sconosciuto'       => array( array( 'kind' => 'ruolo' ) + $good[0] ),
			'id di un altro tipo'    => array( array( 'id' => 'alt:x-1' ) + $good[0] ),
			'id vuoto dopo il tipo'  => array( array( 'id' => 'classe:' ) + $good[0] ),
			'valore troppo lungo'    => array( array( 'value' => str_repeat( 'a', 201 ) ) + $good[0] ),
			'contesto troppo lungo'  => array( array( 'context' => str_repeat( 'a', 301 ) ) + $good[0] ),
			'id ripetuto'            => array( $good[0], $good[0] ),
			'valore non testo'       => array( array( 'value' => 3 ) + $good[0] ),
			'UTF-8 non valido'       => array( array( 'context' => "\xff" ) + $good[0] ),
		);
		foreach ( $bad as $name => $raw ) {
			try {
				Choices::read_choices( $raw, 200 );
				$this->fail( $name );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $name );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		Choices::read_choices( array_fill( 0, 3, $good[0] ), 2 );
	}

	public function test_lengths_count_as_javascript_counts_them(): void {
		$this->assertSame( 2, Choices::js_length( '🚲' ) );
		$this->assertSame( 5, Choices::js_length( 'città' ) );
		$this->assertSame( '🚲', Choices::js_slice( '🚲🚲', 3 ) );
		$this->assertSame( 'ab', Choices::js_slice( 'ab', 120 ) );
	}
}
