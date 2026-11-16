<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use XB\Remote\PageProfile;
use XB\Remote\ProfileValidator;
use XB\Remote\TransferMarkupViolation;

/**
 * The documents of the fixture shared with Histeon Publish (tests/fixtures/histos-transfer-markup-v1.json) read as a
 * WordPress import reads them: parse_blocks(), then ProfileValidator::assert_page_blocks() with the vector's image
 * assets, as PageProfileAdapter does before anything is written. Accepted documents must declare exactly the media
 * references Publish declares, and the render vectors must print the same HTML as Publish's static renderer.
 */
final class TransferMarkupDocumentsTest extends IntegrationTestCase {

	/** @return array<string, mixed> */
	private static function fixture(): array {
		static $fixture = null;
		if ( null === $fixture ) {
			$bytes = file_get_contents( dirname( __DIR__ ) . '/fixtures/histos-transfer-markup-v1.json' );
			if ( false === $bytes ) {
				throw new \RuntimeException( 'The shared transfer markup fixture is not readable.' );
			}
			$decoded = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
			$fixture = is_array( $decoded ) ? $decoded : array();
		}
		return $fixture;
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function document_vectors(): iterable {
		foreach ( self::fixture()['documents'] as $index => $vector ) {
			yield sprintf( '#%d %s → %s', $index, $vector['name'], $vector['expect'] ) => array( $vector );
		}
	}

	/**
	 * @param array<string, mixed> $vector
	 * @return array<string, string>
	 */
	private static function component_ids( array $vector ): array {
		$ids = array();
		foreach ( array_values( $vector['componentRefs'] ?? array() ) as $position => $ref ) {
			$ids[ (string) $ref ] = sprintf( '00000000-0000-4000-8000-%012d', $position + 1 );
		}
		return $ids;
	}

	/** @param array<string, mixed> $vector */
	#[DataProvider( 'document_vectors' )]
	public function test_document( array $vector ): void {
		/** @var list<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}> $blocks */
		$blocks    = array_values( parse_blocks( (string) $vector['content'] ) );
		$assets    = array_fill_keys( array_map( 'strval', $vector['assets'] ), true );
		$reusable  = 'document' !== $vector['ownerKind'];
		$outcome   = 'accept';
		try {
			ProfileValidator::assert_page_blocks( $blocks, $assets, self::component_ids( $vector ), $reusable, 'fixture', true, array() );
		} catch ( TransferMarkupViolation $violation ) {
			$outcome = $violation->reason . ': ' . $violation->getMessage();
		}
		$this->assertSame( $vector['expect'], explode( ':', $outcome )[0], $outcome );
		if ( 'accept' !== $vector['expect'] ) {
			return;
		}

		// The media references PageProfile requires for this content are the ones Publish writes.
		$references = ( new \ReflectionMethod( PageProfile::class, 'content_references' ) )->invoke( null, (string) $vector['content'], self::component_ids( $vector ) );
		$actual     = array();
		foreach ( is_array( $references ) ? $references : array() as $reference ) {
			if ( 'attrs.ref' !== $reference['path'] ) {
				$actual[] = array_filter( array( 'blockUid' => $reference['uid'], 'path' => $reference['path'], 'targetId' => $reference['targetId'], 'index' => $reference['index'] ?? null ), static fn ( mixed $value ): bool => null !== $value );
			}
		}
		$expected = $vector['references'] ?? array();
		$order    = static fn ( array $a, array $b ): int => strcmp( $a['blockUid'] . $a['path'] . ( $a['index'] ?? '' ), $b['blockUid'] . $b['path'] . ( $b['index'] ?? '' ) );
		usort( $actual, $order );
		usort( $expected, $order );
		$this->assertEquals( $expected, $actual );

		if ( isset( $vector['expectedBody'] ) ) {
			$this->assertSame( $vector['expectedBody'], $this->render( $blocks ) );
		}
	}

	public function test_the_fixture_has_render_vectors_and_documents_with_srcset_references(): void {
		$documents = self::fixture()['documents'];
		$this->assertCount( 6, array_filter( $documents, static fn ( array $vector ): bool => isset( $vector['expectedBody'] ) ) );
		$this->assertNotEmpty( array_filter( $documents, static fn ( array $vector ): bool => in_array( 'attrs.attributes.srcset', array_column( $vector['references'] ?? array(), 'path' ), true ) ) );
	}

	/**
	 * Item 189 of Publish's doc 23: `url.path` travels (a menu written in WP with it stopped every send, since the site
	 * could not read itself back); `url` alone, `url.query` and `url["path"]` do not, and a loop variable named `url` is
	 * its own scope.
	 */
	public function test_url_path_is_portable_and_nothing_else_of_url(): void {
		$text  = static fn ( string $content ): string => '<!-- wp:xb/text {"uid":"fu000001","content":"' . $content . '"} /-->';
		$check = static fn ( string $content, string $kind = 'document' ) => ProfileValidator::assert_transfer_block_tree( array_values( parse_blocks( $content ) ), $kind, 'fixture' );

		$check( $text( '{url.path}' ) );
		$check( $text( '{url.path.includes(\\u0022/blog/\\u0022)}' ) );
		$check( '<!-- wp:xb/if {"uid":"fu000002"} --><!-- wp:xb/branch {"uid":"fu000003","test":"url.path == \\u0022/festival/\\u0022 || page.slug == \\u0022x\\u0022"} --><!-- wp:xb/text {"uid":"fu000004","content":"sì"} /--><!-- /wp:xb/branch --><!-- /wp:xb/if -->' );
		$check( $text( '{url.path}' ), 'component' );
		$check( '<!-- wp:xb/loop {"uid":"fu000005","source":{"kind":"expr","expr":"page.items"},"as":"url"} --><!-- wp:xb/text {"uid":"fu000006","content":"{url.query}"} /--><!-- /wp:xb/loop -->' );
		foreach ( array( '{url}', '{url.query.q}', '{url[\\u0022path\\u0022]}', '{url.host}' ) as $content ) {
			try {
				$check( $text( $content ) );
				self::fail( 'Not portable, accepted: ' . $content );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( 'scope is not portable', $error->getMessage(), $content );
			}
		}
	}

	/**
	 * Item 187 of Publish's doc 23: a query loop's result in groups travels as `source.groupBy` {field, how?}, literal
	 * strings with the DSL's rules; anything else in it, or on an expression loop, is refused.
	 */
	public function test_a_grouped_query_loop_travels_and_only_with_a_valid_group_by(): void {
		$loop  = static fn ( array $source ): string => '<!-- wp:xb/loop ' . (string) wp_json_encode( array( 'uid' => 'gl000001', 'source' => $source, 'as' => 'gruppo' ) ) . ' --><!-- wp:xb/text {"uid":"gl000002","content":"{gruppo.key} {gruppo.items.length()}"} /--><!-- /wp:xb/loop -->';
		$check = static fn ( array $source ) => ProfileValidator::assert_transfer_block_tree( array_values( parse_blocks( $loop( $source ) ) ), 'document', 'fixture', array( 'foto' => array( 'limit' => true ) ) );

		$check( array( 'kind' => 'preset', 'id' => 'foto', 'groupBy' => array( 'field' => 'anno' ) ) );
		$check( array( 'kind' => 'preset', 'id' => 'foto', 'args' => array( 'limit' => '20' ), 'groupBy' => array( 'field' => 'publishedAt', 'how' => 'F Y' ) ) );
		$check( array( 'kind' => 'preset', 'id' => 'foto', 'groupBy' => array( 'field' => 'fields.autore', 'how' => 'letter' ) ) );
		$invalid = array(
			array( 'field' => 'a b' ),
			array( 'field' => '' ),
			array( 'field' => 3 ),
			array( 'how' => 'Y' ),
			array( 'field' => 'anno', 'how' => '' ),
			array( 'field' => 'anno', 'how' => array( 'Y' ) ),
			array( 'field' => 'anno', 'how' => "Y\n" ),
			array( 'field' => 'anno', 'how' => str_repeat( 'Y', 33 ) ),
			array( 'field' => 'anno', 'order' => 'desc' ),
			'anno',
		);
		foreach ( $invalid as $group_by ) {
			try {
				$check( array( 'kind' => 'preset', 'id' => 'foto', 'groupBy' => $group_by ) );
				self::fail( 'Invalid groupBy, accepted: ' . wp_json_encode( $group_by ) );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( 'groupBy is invalid', $error->getMessage(), (string) wp_json_encode( $group_by ) );
			}
		}
		$this->expectException( \DomainException::class );
		$check( array( 'kind' => 'expr', 'expr' => 'page.items', 'groupBy' => array( 'field' => 'anno' ) ) );
	}

	/**
	 * A loop's {:else} does not see the loop variable or index (a non-portable scope, as in Publish), and any `test` key,
	 * even null, makes a loop branch a condition, which is refused as `context`.
	 */
	public function test_a_loop_else_has_the_scope_outside_the_loop(): void {
		$loop = static fn ( string $else_content, string $branch_attrs = '' ): string => '<!-- wp:xb/loop {"uid":"fx000001","source":{"kind":"expr","expr":"page.items"},"as":"voce","index":"i"} --><!-- wp:xb/text {"uid":"fx000002","content":"{i}: {voce}"} /--><!-- wp:xb/branch {"uid":"fx000003"' . $branch_attrs . '} --><!-- wp:xb/text {"uid":"fx000004","content":"' . $else_content . '"} /--><!-- /wp:xb/branch --><!-- /wp:xb/loop -->';
		$check = static fn ( string $content ) => ProfileValidator::assert_transfer_block_tree( array_values( parse_blocks( $content ) ), 'document', 'fixture' );

		$check( $loop( '{page.title}: vuoto' ) );
		foreach ( array( '{voce}', '{i}', '{voce.title ?? page.title}' ) as $content ) {
			try {
				$check( $loop( $content ) );
				self::fail( 'The {:else} read the loop scope: ' . $content );
			} catch ( TransferMarkupViolation $violation ) {
				self::fail( 'A scope error is not a markup violation: ' . $violation->getMessage() );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( 'scope is not portable', $error->getMessage(), $content );
			}
		}
		foreach ( array( ',"test":null', ',"test":"page.ok"', ',"test":true' ) as $attrs ) {
			try {
				$check( $loop( 'vuoto', $attrs ) );
				self::fail( 'A loop branch with a test was accepted: ' . $attrs );
			} catch ( TransferMarkupViolation $violation ) {
				$this->assertSame( 'context', $violation->reason, $attrs );
				$this->assertStringContainsString( 'fixture', $violation->getMessage() );
			}
		}
	}

	/** A violation keeps its reason and names the document, for the per-document errors of the import report. */
	public function test_a_violation_names_the_document(): void {
		$blocks = array_values( parse_blocks( '<!-- wp:xb/element {"uid":"fx000001","tag":"div","attributes":{"hx-get":"/x"}} /-->' ) );
		try {
			ProfileValidator::assert_transfer_block_tree( $blocks, 'document', '550e8400-e29b-41d4-a716-446655440061' );
			self::fail( 'A framework attribute was accepted.' );
		} catch ( TransferMarkupViolation $violation ) {
			$this->assertSame( 'attribute', $violation->reason );
			$this->assertStringContainsString( '550e8400-e29b-41d4-a716-446655440061', $violation->getMessage() );
		}
	}

	/** Inline styles keep their name-stable check for PageProfileAdapter, now with references and CSS escapes resolved. */
	public function test_inline_css_profile_resolves_references_and_escapes(): void {
		ProfileValidator::assert_css_profile( 'color: var(--xb-primary); margin: 0 auto' );
		foreach ( array( 'background: url(x)', 'background: &#117;rl(x)', 'background: \\75 rl(x)', 'background: image-set("a.png" 1x)', 'behavior: url(x.htc)' ) as $css ) {
			try {
				ProfileValidator::assert_css_profile( $css );
				self::fail( 'Inline CSS loading a resource was accepted: ' . $css );
			} catch ( TransferMarkupViolation $violation ) {
				$this->assertSame( 'style', $violation->reason, $css );
			}
		}
		$this->expectException( TransferMarkupViolation::class );
		ProfileValidator::assert_css_profile( 'color: &#58red' );
	}
}
