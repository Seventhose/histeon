<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Remote\PageProfile;

final class PageProfileTest extends TestCase {

	public function test_jcs_float_serialization_matches_ecmascript_notation_and_boundaries(): void {
		$previous_precision = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '14' );
		try {
			$this->assertSame(
				'[0,1e-7,0.000001,100000000000000000000,1e+21,333333333.3333333,5e-324,1.7976931348623157e+308,9007199254740991]',
				PageProfile::canonical( array( -0.0, 1e-7, 1e-6, 1e20, 1e21, 333333333.33333329, 5e-324, 1.7976931348623157e308, 9007199254740991 ) )
			);
			$this->assertSame(
				'{"a":"latin","😀":"astral","":"private-use"}',
				PageProfile::canonical( json_decode( '{"\uE000":"private-use","😀":"astral","a":"latin"}', false, 128, JSON_THROW_ON_ERROR ) )
			);
		} finally {
			if ( false !== $previous_precision ) {
				ini_set( 'serialize_precision', $previous_precision );
			}
		}
	}

	/** Audit note 85: a query filter holds at most 256 leaf conditions across its groups, as in Histeon Publish. */
	public function test_filter_leaves_are_counted_across_groups_and_stop_past_the_limit(): void {
		$leaf  = array( 'field' => 'anno', 'op' => 'eq', 'value' => 1 );
		$group = static fn ( int $size ): array => array( 'or' => array_fill( 0, $size, $leaf ) );
		$this->assertSame( 1, PageProfile::filter_leaves( $leaf ) );
		$this->assertSame( 256, PageProfile::filter_leaves( array( 'and' => array_fill( 0, 8, $group( 32 ) ) ) ) );
		$this->assertSame( 257, PageProfile::filter_leaves( array( 'and' => array_fill( 0, 9, $group( 32 ) ) ) ), 'counting stops just past the limit' );
		$huge = array( 'and' => array_fill( 0, 32, array( 'or' => array_fill( 0, 32, array( 'and' => array_fill( 0, 32, $group( 32 ) ) ) ) ) ) );
		$this->assertGreaterThan( PageProfile::MAX_FILTER_LEAVES, PageProfile::filter_leaves( $huge ) );
		$this->assertLessThanOrEqual( PageProfile::MAX_FILTER_LEAVES + 32, PageProfile::filter_leaves( $huge ) );
	}

	public function test_empty_histos_style_settings_keep_the_json_object_shape(): void {
		$this->assertSame(
			'{"kind":"styles","payload":{"compiledCss":"","design":{},"globals":[],"queries":{},"styles":[],"tokenOverrides":{}},"schemaVersion":1}',
			PageProfile::canonical( array( 'kind' => 'styles', 'schemaVersion' => 1, 'payload' => array( 'styles' => array(), 'globals' => array(), 'compiledCss' => '', 'design' => array(), 'tokenOverrides' => array(), 'queries' => array() ) ) )
		);
	}

	public function test_empty_component_props_keep_the_json_object_shape_in_live_and_tombstone_hashes(): void {
		$previous = array(
			'kind' => 'component',
			'schemaVersion' => 1,
			'payload' => array(
				'title' => 'Teaser',
				'ref' => 'cards/teaser',
				'schema' => array( 'props' => array() ),
				'content' => '',
				'script' => '',
			),
		);
		$previous_canonical = '{"kind":"component","payload":{"content":"","ref":"cards/teaser","schema":{"props":{}},"script":"","title":"Teaser"},"schemaVersion":1}';
		$this->assertSame( $previous_canonical, PageProfile::canonical( $previous ) );
		$previous_hash = hash( 'sha256', $previous_canonical );
		$tombstone = array(
			'kind' => 'tombstone',
			'schemaVersion' => 1,
			'payload' => array(
				'entityKind' => 'component',
				'deletedAt' => '2026-10-24T12:00:00.000Z',
				'label' => 'Teaser',
				'previousRevisionHash' => $previous_hash,
				'previousPayload' => $previous['payload'],
			),
		);
		$this->assertSame(
			'{"kind":"tombstone","payload":{"deletedAt":"2026-10-24T12:00:00.000Z","entityKind":"component","label":"Teaser","previousPayload":{"content":"","ref":"cards/teaser","schema":{"props":{}},"script":"","title":"Teaser"},"previousRevisionHash":"' . $previous_hash . '"},"schemaVersion":1}',
			PageProfile::canonical( $tombstone )
		);
	}

	public function test_external_framework_css_provenance_is_strict_and_normalized(): void {
		$source = PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => ' Core Framework ',
				'version' => '1.2', 'license' => 'MIT', 'sourceUrl' => 'https://example.com/docs', 'rightsConfirmed' => true,
			)
		);
		$this->assertSame(
			array( 'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => 'Core Framework', 'rightsConfirmed' => true, 'version' => '1.2', 'license' => 'MIT', 'sourceUrl' => 'https://example.com/docs' ),
			$source
		);
		$this->assertNull( PageProfile::validate_framework_source( null ) );
		$this->expectException( \InvalidArgumentException::class );
		PageProfile::validate_framework_source( array( 'kind' => 'user-css', 'frameworkId' => 'custom', 'name' => 'CSS', 'rightsConfirmed' => false ) );
	}

	public function test_external_framework_source_url_rejects_invalid_schemes_hosts_credentials_and_whitespace(): void {
		foreach ( array( '', 'nota-url', 'ftp://example.com/css', 'https:///host-mancante', 'https://utente:segreto@example.com/css', 'https://example.com/percorso con spazi', 'https://example.com\\percorso', 'https://example.com/css\\x', "https://example.com/css\n", 'https://example.com/caffè', 'https://foo_bar.example/css', 'https://-foo.example/css', 'https://foo..example/css', 'https://%41.example/css', 'https://example.com/' . str_repeat( 'a', 2029 ) ) as $url ) {
			try {
				PageProfile::validate_framework_source(
					array(
						'kind' => 'user-css',
						'frameworkId' => 'custom',
						'name' => 'CSS di prova',
						'rightsConfirmed' => true,
						'sourceUrl' => $url,
					)
				);
				self::fail( 'Invalid external CSS source URL was accepted: ' . $url );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertSame( 'Invalid external CSS source URL.', $error->getMessage() );
			}
		}
		$source_url = 'https://example.com/' . str_repeat( 'a', 2028 );
		$accepted   = PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css',
				'frameworkId' => 'custom',
				'name' => 'CSS di prova',
				'rightsConfirmed' => true,
				'sourceUrl' => $source_url,
			)
		);
		if ( ! is_array( $accepted ) || ! is_string( $accepted['sourceUrl'] ?? null ) ) {
			throw new \UnexpectedValueException( 'A valid source URL was not returned.' );
		}
		$this->assertSame( $source_url, $accepted['sourceUrl'] );
		$this->assertSame( 2048, strlen( $accepted['sourceUrl'] ) );
	}

	public function test_core_framework_preset_data_is_bounded_and_preserved(): void {
		$core_json = '{"id":"preset-1","name":"Core","description":"","date":"2026-01-01","groups":{},"cssString":".x{color:red}"}';
		$source    = PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => 'Core Framework', 'rightsConfirmed' => true,
				'preset' => array( 'format' => 'core-json-v1', 'data' => $core_json ),
			)
		);
		$preset = is_array( $source ) ? ( $source['preset'] ?? null ) : null;
		$this->assertSame( array( 'format' => 'core-json-v1', 'data' => $core_json ), $preset );

		$compressed = (string) base64_decode( '4a+h4KGF5IGs4oG84YGm4KCj4oSg55ik4aC25oGz4LGg44Kg56yg5Lmb4YKrw67GrOSWoMeg4K64ybXmkqjgo7Djj4zCuFLnoKPjgKXlkJPkjLDksqDhs6jkgqDirr3UuOaAoeO7qOWRiOKgpuKhleWFreGuoOC6oM+44KqhO+OGiOGIseKpouWUoCA=', true );
		$source     = PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => 'Core Framework', 'rightsConfirmed' => true,
				'preset' => array( 'format' => 'core-lz-utf16-v1', 'data' => $compressed ),
			)
		);
		$preset = is_array( $source ) && is_array( $source['preset'] ?? null ) ? $source['preset'] : null;
		$this->assertSame( $compressed, is_array( $preset ) ? ( $preset['data'] ?? null ) : null );

		$this->expectException( \InvalidArgumentException::class );
		PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css', 'frameworkId' => 'custom', 'name' => 'CSS', 'rightsConfirmed' => true,
				'preset' => array( 'format' => 'core-lz-utf16-v1', 'data' => 'x' ),
			)
		);
	}

	public function test_user_framework_configuration_is_preserved_opaquely_and_bounded(): void {
		$data = '{"design":"kept as supplied"}';
		$source = PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css',
				'frameworkId' => 'automatic-css',
				'name' => 'Automatic.css',
				'rightsConfirmed' => true,
				'configuration' => array( 'format' => 'opaque-utf8-v1', 'data' => $data ),
			)
		);
		$this->assertSame( array( 'format' => 'opaque-utf8-v1', 'data' => $data ), $source['configuration'] ?? null );

		$this->expectException( \InvalidArgumentException::class );
		PageProfile::validate_framework_source(
			array(
				'kind' => 'user-css', 'frameworkId' => 'automatic-css', 'name' => 'Automatic.css', 'rightsConfirmed' => true,
				'configuration' => array( 'format' => 'opaque-utf8-v1', 'data' => str_repeat( 'x', 1048577 ) ),
			)
		);
	}

	public function test_user_framework_configuration_rejects_nul_and_invalid_utf8(): void {
		foreach ( array( "contains\0nul", "\xFF" ) as $data ) {
			try {
				PageProfile::validate_framework_source(
					array(
						'kind' => 'user-css', 'frameworkId' => 'automatic-css', 'name' => 'Automatic.css', 'rightsConfirmed' => true,
						'configuration' => array( 'format' => 'opaque-utf8-v1', 'data' => $data ),
					)
				);
				self::fail( 'Invalid UTF-8 configuration was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertSame( 'Invalid external framework configuration.', $error->getMessage() );
			}
		}
	}

	public function test_the_shared_profile_fixture_survives_php_archive_roundtrip(): void {
		foreach ( array( 'histos-static-page-v1.json', 'histos-static-project-v1.json', 'histos-static-project-v3.json', 'histos-static-project-v4.json', 'histos-static-markup-v1.json' ) as $fixture_name ) {
			$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/' . $fixture_name ), true, 128, JSON_THROW_ON_ERROR );
			$files   = array();
			foreach ( $fixture['profile']['manifest']['files'] as $file ) {
				$bytes = base64_decode( $fixture['files'][ $file['path'] ], true );
				$this->assertNotFalse( $bytes, 'Fixture file must have valid base64.' );
				$files[ $file['path'] ] = $bytes;
			}
			$archive = PageProfile::archive( $fixture['profile']['manifest'], $fixture['profile']['entities'], $fixture['profile']['references'], $files );
			$decoded = PageProfile::decode( $archive );
			$this->assertSame(
				PageProfile::canonical( $fixture['profile'] ),
				PageProfile::canonical( array( 'manifest' => $decoded['manifest'], 'entities' => $decoded['entities'], 'references' => $decoded['references'] ) )
			);
			foreach ( $fixture['files'] as $path => $expected ) {
				$bytes = 'entities.ndjson' === $path
					? implode( "\n", array_map( PageProfile::canonical(...), $decoded['entities'] ) ) . "\n"
					: ( 'references.ndjson' === $path
						? ( array() === $decoded['references'] ? '' : implode( "\n", array_map( PageProfile::canonical(...), $decoded['references'] ) ) . "\n" )
						: ( $decoded['files'][ $path ] ?? '' ) );
				$this->assertSame( $expected, base64_encode( $bytes ), 'Fixture bytes must remain identical: ' . $path );
			}
		}
	}

	public function test_relational_content_templates_must_be_included_in_the_shared_profile(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v3.json' ), true, 128, JSON_THROW_ON_ERROR );
		$event_type = current( array_filter( $fixture['profile']['entities'], static fn ( array $entity ): bool => 'content-type' === $entity['kind'] && 'events' === $entity['payload']['key'] ) );
		$this->assertSame( 'content/event-single', $event_type['payload']['singleTemplateRef'] );
		$this->assertSame( 'content/event-archive', $event_type['payload']['archiveTemplateRef'] );

		$entities = array_values( array_filter( $fixture['profile']['entities'], static fn ( array $entity ): bool => ! ( 'template' === $entity['kind'] && 'content/event-single' === $entity['payload']['ref'] ) ) );
		$entities_bytes = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$manifest = $fixture['profile']['manifest'];
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$bytes = 'entities.ndjson' === $file['path'] ? $entities_bytes : (string) base64_decode( $fixture['files'][ $file['path'] ], true );
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
			$files[ $file['path'] ] = $bytes;
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$this->expectException( \InvalidArgumentException::class );
		// Doc 23 item 233: the refusal names the object and says why in the site's language.
		$this->expectExceptionMessage( 'Tipo «events» non si può importare: usa un template che non è nel pacchetto.' );
		PageProfile::validate( $manifest, $entities, $fixture['profile']['references'], $files );
	}

	/** Doc 23 item 232: a query orders by a public field of its type with one value, as in Publish; nothing else. */
	public function test_a_query_orders_by_a_sortable_field_of_its_type_and_by_nothing_else(): void {
		$validate = function ( string $order_by ): void {
			$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v4.json' ), true, 128, JSON_THROW_ON_ERROR );
			$entities = array_values( array_map(
				static function ( array $entity ) use ( $order_by ): array {
					if ( 'query-preset' === $entity['kind'] ) {
						$entity['payload']['orderBy'] = $order_by;
						$entity['revisionHash']       = PageProfile::revision_hash( $entity );
					}
					return $entity;
				},
				$fixture['profile']['entities']
			) );
			$entities_bytes = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
			$manifest       = $fixture['profile']['manifest'];
			$files          = array();
			foreach ( $manifest['files'] as &$file ) {
				$bytes          = 'entities.ndjson' === $file['path'] ? $entities_bytes : (string) base64_decode( $fixture['files'][ $file['path'] ], true );
				$file['bytes']  = strlen( $bytes );
				$file['sha256'] = hash( 'sha256', $bytes );
				$files[ $file['path'] ] = $bytes;
			}
			unset( $file );
			$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
			PageProfile::validate( $manifest, $entities, $fixture['profile']['references'], $files );
		};
		$validate( 'starts_at' );
		$validate( 'slug' );
		foreach ( array( 'speaker', 'private_note', 'biography', 'missing' ) as $refused ) {
			try {
				$validate( $refused );
				$this->fail( 'A query must not order by ' . $refused . '.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertStringContainsString( 'Query «upcoming_events» non si può importare: orderBy «' . $refused . '» non ordina il tipo events', $error->getMessage(), $refused );
			}
		}
		// Doc 23 item 233: a value outside the grammar says which property and what it takes.
		try {
			$validate( 'Data' );
			$this->fail( 'A query must not order by a name outside the grammar.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertSame( 'Query «upcoming_events» non si può importare: orderBy è uno fra order, publishedAt, title, slug, createdAt, updatedAt, oppure la chiave di un campo del tipo.', $error->getMessage() );
		}
	}

	public function test_media_references_must_bind_the_exact_uid_and_target(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$fixture['profile']['references'][0]['targetId'] = '52345678-1234-4234-8234-123456789abc';
		$this->expectException( \InvalidArgumentException::class );
		PageProfile::validate( $fixture['profile']['manifest'], $fixture['profile']['entities'], $fixture['profile']['references'], array_map( static fn ( string $value ): string => (string) base64_decode( $value, true ), $fixture['files'] ) );
	}

	public function test_tombstones_require_a_canonical_timestamp_and_the_hash_of_the_previous_entity(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$entities = $fixture['profile']['entities'];
		$references = $fixture['profile']['references'];
		$previous = $entities[0];
		$previous_hash = PageProfile::revision_hash( $previous );
		$replacement = $previous;
		$replacement['id'] = '52345678-1234-4234-8234-123456789abc';
		$replacement['payload']['title'] = 'Pagina rimasta';
		$replacement['payload']['slug'] = 'pagina-rimasta';
		$replacement['revisionHash'] = PageProfile::revision_hash( $replacement );
		unset( $replacement['baseHash'] );
		$references[0]['ownerId'] = $replacement['id'];
		$tombstone = array(
			'id' => $previous['id'],
			'kind' => 'tombstone',
			'schemaVersion' => 1,
			'baseHash' => $previous_hash,
			'payload' => array(
				'entityKind' => 'document',
				'deletedAt' => '2026-10-24T12:00:00.000Z',
				'label' => $previous['payload']['title'],
				'previousRevisionHash' => $previous_hash,
				'previousPayload' => $previous['payload'],
			),
		);
		$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
		$entities[0] = $tombstone;
		$entities[] = $replacement;
		$files = array();
		$manifest = $fixture['profile']['manifest'];
		$entity_bytes = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$reference_bytes = implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n";
		foreach ( $manifest['files'] as &$file ) {
			$bytes = 'entities.ndjson' === $file['path'] ? $entity_bytes : ( 'references.ndjson' === $file['path'] ? $reference_bytes : (string) base64_decode( $fixture['files'][ $file['path'] ], true ) );
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
			$files[ $file['path'] ] = $bytes;
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		PageProfile::validate( $manifest, $entities, $references, $files );

		$extended_year_entities = $entities;
		$extended_year_entities[0]['payload']['deletedAt'] = '+010000-01-01T00:00:00.000Z';
		$extended_year_entities[0]['revisionHash'] = PageProfile::revision_hash( $extended_year_entities[0] );
		$extended_year_manifest = $fixture['profile']['manifest'];
		$extended_year_files = array();
		$extended_year_entity_bytes = implode( "\n", array_map( PageProfile::canonical(...), $extended_year_entities ) ) . "\n";
		foreach ( $extended_year_manifest['files'] as &$file ) {
			$bytes            = 'entities.ndjson' === $file['path'] ? $extended_year_entity_bytes : ( 'references.ndjson' === $file['path'] ? $reference_bytes : (string) base64_decode( $fixture['files'][ $file['path'] ], true ) );
			$file['bytes']    = strlen( $bytes );
			$file['sha256']   = hash( 'sha256', $bytes );
			$extended_year_files[ $file['path'] ] = $bytes;
		}
		unset( $file );
		$extended_year_manifest['manifestHash'] = PageProfile::manifest_hash( $extended_year_manifest );
		try {
			PageProfile::validate( $extended_year_manifest, $extended_year_entities, $references, $extended_year_files );
			self::fail( 'An extended ISO year was accepted in a tombstone timestamp.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertSame( 'Invalid canonical tombstone deletion date.', $error->getMessage() );
		}

		$entities[0]['payload']['previousPayload']['title'] = 'Snapshot alterato';
		$entities[0]['revisionHash'] = PageProfile::revision_hash( $entities[0] );
		$entity_bytes = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$manifest = $fixture['profile']['manifest'];
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$bytes = 'entities.ndjson' === $file['path'] ? $entity_bytes : ( 'references.ndjson' === $file['path'] ? $reference_bytes : (string) base64_decode( $fixture['files'][ $file['path'] ], true ) );
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
			$files[ $file['path'] ] = $bytes;
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Tombstone snapshot revision hash is invalid.' );
		PageProfile::validate( $manifest, $entities, $references, $files );
	}

	public function test_project_component_references_must_resolve_to_a_component(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$fixture['profile']['references'][0]['targetId'] = $fixture['profile']['entities'][0]['id'];
		$files = array();
		foreach ( $fixture['profile']['manifest']['files'] as $file ) {
			$files[ $file['path'] ] = (string) base64_decode( $fixture['files'][ $file['path'] ], true );
		}
		$this->expectException( \InvalidArgumentException::class );
		PageProfile::validate( $fixture['profile']['manifest'], $fixture['profile']['entities'], $fixture['profile']['references'], $files );
	}

	/** Notes 82 and 83 of the 3 November audit: a media file is named exactly by the hash of its bytes. */
	public function test_media_files_are_named_exactly_by_their_hash(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-markup-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$attempt = static function ( string $path, string $bytes, string $declared ) use ( $fixture ): ?string {
			$manifest = $fixture['profile']['manifest'];
			$files    = array();
			foreach ( $manifest['files'] as $file ) {
				$files[ $file['path'] ] = (string) base64_decode( $fixture['files'][ $file['path'] ], true );
			}
			$manifest['files'][]      = array( 'path' => $path, 'bytes' => strlen( $bytes ), 'sha256' => $declared );
			$files[ $path ]           = $bytes;
			$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
			try {
				PageProfile::validate( $manifest, $fixture['profile']['entities'], $fixture['profile']['references'], $files );
			} catch ( \InvalidArgumentException $e ) {
				return $e->getMessage();
			}
			return null;
		};
		$bytes = "\x89PNG\r\n\x1a\nextra";
		$hash  = hash( 'sha256', $bytes );
		$this->assertStringContainsString( 'not named by its hash', (string) $attempt( 'media/' . str_repeat( '0', 64 ), $bytes, $hash ) );
		$this->assertStringContainsString( 'unsupported transfer file path', (string) $attempt( 'media/' . $hash . "\n", $bytes, $hash ), 'a trailing newline is no part of a name' );
	}

	public function test_media_bytes_must_match_their_declared_type(): void {
		$this->assertTrue( PageProfile::bytes_match_media_type( "\x89PNG\r\n\x1a\n...", 'image/png' ) );
		$this->assertTrue( PageProfile::bytes_match_media_type( "RIFF\x00\x00\x00\x00WEBPVP8 ", 'image/webp' ) );
		$this->assertTrue( PageProfile::bytes_match_media_type( "\x00\x00\x00\x1cftypavif", 'image/avif' ) );
		$this->assertTrue( PageProfile::bytes_match_media_type( 'wOF2....', 'font/woff2' ) );
		$this->assertFalse( PageProfile::bytes_match_media_type( '<html><script>alert(1)</script>', 'image/png' ), 'HTML declared as an image' );
		$this->assertFalse( PageProfile::bytes_match_media_type( "\x89PNG\r\n\x1a\n...", 'image/jpeg' ), 'a PNG declared as JPEG' );
		$this->assertFalse( PageProfile::bytes_match_media_type( '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml' ), 'SVG never travels' );
		$this->assertFalse( PageProfile::bytes_match_media_type( '', 'image/gif' ) );
	}

	/**
	 * The shared markup profile (histos-transfer-markup-v1: SVG icon component, forms, picture with srcset, details,
	 * textarea text) with its references changed, re-hashed so that only the reference rules decide.
	 *
	 * @param \Closure(array<mixed>): array<mixed> $change
	 */
	private function validate_markup_profile_with( \Closure $change ): void {
		$fixture    = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-markup-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		/** @var list<array<string, mixed>> $references */
		$references = array_values( $change( $fixture['profile']['references'] ) );
		$manifest   = $fixture['profile']['manifest'];
		$files      = array();
		$bytes_of   = static fn ( array $items ): string => array() === $items ? '' : implode( "\n", array_map( PageProfile::canonical(...), $items ) ) . "\n";
		foreach ( $manifest['files'] as &$file ) {
			$bytes          = 'references.ndjson' === $file['path'] ? $bytes_of( $references ) : (string) base64_decode( $fixture['files'][ $file['path'] ], true );
			$file['bytes']  = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
			$files[ $file['path'] ] = $bytes;
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		PageProfile::validate( $manifest, $fixture['profile']['entities'], $references, $files );
	}

	/** @param array<mixed> $references */
	private static function srcset_reference_index( array $references, string $block_uid, int $index ): int {
		foreach ( $references as $position => $reference ) {
			if ( is_array( $reference ) && $block_uid === ( $reference['blockUid'] ?? null ) && 'attrs.attributes.srcset' === ( $reference['path'] ?? null ) && $index === ( $reference['index'] ?? null ) ) {
				return (int) $position;
			}
		}
		throw new \RuntimeException( 'The markup fixture lost its srcset reference ' . $block_uid . '#' . $index . '.' );
	}

	public function test_srcset_references_name_their_candidate_and_two_candidates_of_one_block_do_not_collide(): void {
		$this->validate_markup_profile_with(
			static function ( array $references ): array {
				// The fixture holds two candidates of the same <source> (index 0 and 1) and the same target on src and srcset of the <img>.
				self::srcset_reference_index( $references, 'markup000011', 0 );
				self::srcset_reference_index( $references, 'markup000011', 1 );
				self::srcset_reference_index( $references, 'markup000012', 0 );
				return $references;
			}
		);
		$this->addToAssertionCount( 1 );
	}

	/** @return iterable<string, array{\Closure(array<mixed>): array<mixed>, string}> */
	public static function broken_srcset_references(): iterable {
		yield 'srcset reference without index' => array(
			static function ( array $references ): array {
				unset( $references[ self::srcset_reference_index( $references, 'markup000011', 0 ) ]['index'] );
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'src reference with an index' => array(
			static function ( array $references ): array {
				foreach ( $references as &$reference ) {
					if ( 'attrs.attributes.src' === $reference['path'] ) {
						$reference['index'] = 0;
					}
				}
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'negative index' => array(
			static function ( array $references ): array {
				$references[ self::srcset_reference_index( $references, 'markup000011', 0 ) ]['index'] = -1;
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'index pointing at the other candidate' => array(
			static function ( array $references ): array {
				$first  = self::srcset_reference_index( $references, 'markup000011', 0 );
				$second = self::srcset_reference_index( $references, 'markup000011', 1 );
				$references[ $first ]['index'] = 1;
				unset( $references[ $second ] );
				return array_values( $references );
			},
			'Media reference does not match the document content.',
		);
		yield 'index past the last candidate' => array(
			static function ( array $references ): array {
				$references[ self::srcset_reference_index( $references, 'markup000012', 1 ) ]['index'] = 2;
				return $references;
			},
			'Media reference does not match the document content.',
		);
		yield 'missing structured reference for a srcset candidate' => array(
			static function ( array $references ): array {
				unset( $references[ self::srcset_reference_index( $references, 'markup000012', 1 ) ] );
				return array_values( $references );
			},
			'A document URI is missing its exact structured reference.',
		);
		yield 'index as a string' => array(
			static function ( array $references ): array {
				$references[ self::srcset_reference_index( $references, 'markup000011', 1 ) ]['index'] = '1';
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'index as a float' => array(
			static function ( array $references ): array {
				$references[ self::srcset_reference_index( $references, 'markup000011', 1 ) ]['index'] = 1.0;
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'srcset reference moved to the src path' => array(
			static function ( array $references ): array {
				$references[ self::srcset_reference_index( $references, 'markup000012', 1 ) ]['path'] = 'attrs.attributes.src';
				return $references;
			},
			'Invalid or unresolved media reference.',
		);
		yield 'one candidate referenced twice' => array(
			static function ( array $references ): array {
				$references[] = $references[ self::srcset_reference_index( $references, 'markup000011', 1 ) ];
				return $references;
			},
			'Duplicate reference slot.',
		);
	}

	/** @param \Closure(array<mixed>): array<mixed> $change */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'broken_srcset_references' )]
	public function test_srcset_references_must_bind_the_exact_candidate( \Closure $change, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		$this->validate_markup_profile_with( $change );
	}
}
