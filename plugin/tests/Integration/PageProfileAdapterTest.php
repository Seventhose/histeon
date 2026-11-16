<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;
use XB\Remote\OperationConflictException;
use XB\Remote\ProfileConflictException;

final class PageProfileAdapterTest extends IntegrationTestCase {
	/** @var list<array{id: int, taxonomy: string}> */
	private array $profile_terms = array();
	/** @var list<string> */
	private array $profile_post_types = array();
	/** @var list<string> */
	private array $profile_taxonomies = array();
	/** @var list<string> Media files a test leaves outside the attachments it tracks. */
	private array $media_files = array();

	/**
	 * @param array<array-key, mixed> $entities
	 * @return array<string, mixed>
	 */
	private function profile_entity_by_id( array $entities, string $id ): array {
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$normalized = array();
			foreach ( $entity as $key => $value ) {
				if ( ! is_string( $key ) ) {
					throw new \UnexpectedValueException( 'A profile entity must use string keys.' );
				}
				$normalized[ $key ] = $value;
			}
			if ( ( $normalized['id'] ?? null ) === $id ) {
				return $normalized;
			}
		}
		throw new \UnexpectedValueException( 'Profile fixture is missing entity ' . $id . '.' );
	}

	/** The content of a document as it is in development (where Histeon CMS sends arrive). */
	private function dev_content( int $post_id ): string {
		$payload = PageProfileAdapter::dev_payload( \XB\Versioning\Documents::for_post( $post_id ) );
		return is_string( $payload['content'] ?? null ) ? $payload['content'] : '';
	}

	/**
	 * @param mixed $rows
	 * @return list<array<string, mixed>>
	 */
	private function require_profile_rows( mixed $rows ): array {
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			throw new \UnexpectedValueException( 'Profile references must be a JSON list.' );
		}
		$normalized_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new \UnexpectedValueException( 'Every profile reference must be a JSON object.' );
			}
			$normalized_row = array();
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $key ) ) {
					throw new \UnexpectedValueException( 'Profile reference keys must be strings.' );
				}
				$normalized_row[ $key ] = $value;
			}
			$normalized_rows[] = $normalized_row;
		}
		return $normalized_rows;
	}

	/**
	 * @param array<string, mixed>          $manifest
	 * @param list<array<string, mixed>>    $entities
	 * @param list<array<string, mixed>>    $references
	 * @param array<string, string>         $files
	 */
	private function build_profile_archive( array $manifest, array $entities, array $references, array $files ): string {
		$manifest_files = $manifest['files'] ?? null;
		if ( ! is_array( $manifest_files ) || ! array_is_list( $manifest_files ) ) {
			throw new \UnexpectedValueException( 'Transfer manifest is missing its file list.' );
		}
		$used_media = array();
		foreach ( $entities as $entity ) {
			if ( 'asset' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['path'] ?? null ) ) {
				$used_media[ $entity['payload']['path'] ] = true;
			}
		}
		$manifest_files = array_values( array_filter( $manifest_files, static fn ( array $file ): bool => ! is_string( $file['path'] ?? null ) || ! str_starts_with( $file['path'], 'media/' ) || isset( $used_media[ $file['path'] ] ) ) );
		foreach ( array_keys( $files ) as $path ) {
			if ( str_starts_with( $path, 'media/' ) && ! isset( $used_media[ $path ] ) ) {
				unset( $files[ $path ] );
			}
		}
		$files['entities.ndjson'] = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$files['references.ndjson'] = array() === $references ? '' : implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n";
		foreach ( $manifest_files as &$file ) {
			if ( ! is_array( $file ) || ! is_string( $file['path'] ?? null ) || ! isset( $files[ $file['path'] ] ) ) {
				throw new \UnexpectedValueException( 'Transfer manifest references an unavailable file.' );
			}
			$bytes = $files[ $file['path'] ];
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['files'] = $manifest_files;
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		return PageProfile::archive( $manifest, $entities, $references, $files );
	}

	protected function tearDown(): void {
		foreach ( $this->media_files as $media_file ) {
			if ( '' !== $media_file && is_file( $media_file ) ) {
				wp_delete_file( $media_file );
			}
		}
		$this->media_files = array();
		foreach ( $this->profile_terms as $term ) {
			$registered_for_cleanup = false;
			if ( ! taxonomy_exists( $term['taxonomy'] ) ) {
				$registered = register_taxonomy( $term['taxonomy'], array() );
				$registered_for_cleanup = ! is_wp_error( $registered );
			}
			wp_delete_term( $term['id'], $term['taxonomy'] );
			if ( $registered_for_cleanup ) {
				unregister_taxonomy( $term['taxonomy'] );
			}
		}
		$this->profile_terms = array();
		parent::tearDown();
		\XB\Content\ContentTypes::forget();
		$restored_definitions = \XB\Content\ContentTypes::live();
		foreach ( $this->profile_taxonomies as $taxonomy ) {
			if ( ! isset( $restored_definitions['taxonomies'][ $taxonomy ] ) && taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		foreach ( $this->profile_post_types as $post_type ) {
			if ( ! isset( $restored_definitions['types'][ $post_type ] ) && post_type_exists( $post_type ) ) {
				// A published profile registered its fields too: they would look like another plugin's next time.
				foreach ( array_keys( get_registered_meta_keys( 'post', $post_type ) ) as $meta_key ) {
					unregister_meta_key( 'post', $meta_key, $post_type );
				}
				unregister_post_type( $post_type );
			}
		}
		$this->profile_post_types = array();
		$this->profile_taxonomies = array();
		\XB\Content\ContentTypes::forget();
	}

	private function isolate_operation( string $operation_id, string $project_id ): void {
		$options = array(
			'xb_remote_operation_' . $operation_id,
			'xb_remote_operation_lock_' . $operation_id,
			'xb_remote_project_lock_' . $project_id,
	);
		foreach ( $options as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
			delete_option( $option );
		}
	}

	public function test_import_keeps_published_input_as_a_draft_when_publish_was_not_requested(): void {
		$unmanaged_page_ids = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $unmanaged_page ) {
			if ( '' === (string) get_post_meta( $unmanaged_page->ID, '_xb_page_profile_id', true ) ) {
				$unmanaged_page_ids[] = $unmanaged_page->ID;
			}
		}
		$this->assertNotEmpty( $unmanaged_page_ids, 'The WordPress fixture must include pages outside the Histeon project.' );
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		foreach ( $entities as &$entity ) {
			if ( 'document' === $entity['kind'] ) {
				$entity['payload']['status'] = 'published';
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );

		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = 'entities.ndjson' === $path
				? implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n"
				: ( 'references.ndjson' === $path
					? ( array() === $references ? '' : implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n" )
					: (string) base64_decode( $fixture['files'][ $path ], true ) );
			$this->assertNotFalse( $bytes, 'All fixture files must decode.' );
			$files[ $path ] = $bytes;
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$operation_id = $manifest['selection']['snapshot'];
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $operation_id, $manifest['project']['id'] );

		$archive = PageProfile::archive( $manifest, $entities, $references, $files );
		$active_operation_id = wp_generate_uuid4();
		add_option( 'xb_remote_project_lock_' . $manifest['project']['id'], array( 'operationId' => $active_operation_id, 'projectId' => $manifest['project']['id'], 'requestHash' => str_repeat( 'a', 64 ), 'userId' => get_current_user_id(), 'createdAt' => time(), 'updatedAt' => time() ), '', false );
		try {
			PageProfileAdapter::import( $archive, false );
			$this->fail( 'A second active transfer for the project must be rejected.' );
		} catch ( OperationConflictException $error ) {
			$this->assertStringContainsString( 'già in corso', $error->getMessage() );
		}
		$this->assertFalse( get_option( 'xb_remote_operation_lock_' . $operation_id, false ), 'a rejected concurrent import releases its operation lock' );
		delete_option( 'xb_remote_project_lock_' . $manifest['project']['id'] );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertSame( 'import', $report['action'] );
		$this->assertFalse( $report['published'] );
		$this->assertCount( 1, $report['pages'] );
		$page_id = $report['pages'][0]['postId'];
		$this->track( $page_id );
		$this->assertSame( 'draft', get_post_status( $page_id ) );
		$this->assertSame( 'draft', $report['pages'][0]['status'] );
		$status = get_option( 'xb_remote_operation_' . $operation_id );
		$this->assertSame( 'import', $status['action'] );
		$this->assertSame( 'complete', $status['state'] );
		$this->assertTrue( $status['complete'] );
		$this->assertFalse( $status['published'] );
		$this->assertSame( $manifest['project'], $status['project'] );
		$this->assertSame( $report['heads'], $status['heads'] );
		$this->assertSame( $report, $status['report'], 'the complete response is retained for an exact idempotent retry' );
		$this->assertSame( hash( 'sha256', $archive ), $status['requestHash'] );
		$this->assertSame( 'import', $status['action'] );
		$this->assertSame( $manifest['project']['id'], $status['projectId'] );
		$this->assertSame( $report, PageProfileAdapter::import( $archive, false ), 'the same operation and body return the original report without creating duplicate content' );
		$public_status = PageProfileAdapter::status( $operation_id, get_current_user_id() );
		$this->assertIsArray( $public_status );
		$this->assertArrayNotHasKey( 'requestHash', $public_status );
		$this->assertArrayNotHasKey( 'report', $public_status );
		$status['state'] = 'partial';
		$status['complete'] = false;
		unset( $status['report'] );
		update_option( 'xb_remote_operation_' . $operation_id, $status, false );
		try {
			PageProfileAdapter::import( $archive, false );
			$this->fail( 'An incomplete operation must be checked and replanned, not blindly replayed.' );
		} catch ( OperationConflictException $error ) {
			$this->assertStringContainsString( 'non è completa', $error->getMessage() );
		}
		$managed_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $report['pages'][0]['id'] ) );
		$this->assertCount( 1, $managed_pages, 'checking an incomplete operation never creates duplicate pages' );
		$changed_manifest = $manifest;
		$changed_manifest['project']['name'] = 'Richiesta differente';
		$changed_manifest['manifestHash'] = PageProfile::manifest_hash( $changed_manifest );
		try {
			PageProfileAdapter::import( PageProfile::archive( $changed_manifest, $entities, $references, $files ), false );
			$this->fail( 'A reused operation ID with different archive bytes must be rejected.' );
		} catch ( OperationConflictException $error ) {
			$this->assertStringContainsString( 'richiesta diversa', $error->getMessage() );
		}
		$this->assertFalse( get_option( 'xb_remote_operation_lock_' . $operation_id, false ) );
		$this->assertFalse( get_option( 'xb_remote_project_lock_' . $manifest['project']['id'], false ) );
		foreach ( $unmanaged_page_ids as $unmanaged_page_id ) {
			$this->assertSame( '', (string) get_post_meta( $unmanaged_page_id, '_xb_page_profile_id', true ), 'Import must not claim unrelated WordPress pages.' );
		}

		$media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'meta_key' => '_xb_page_profile_id' ) );
		foreach ( $media as $attachment ) {
			$this->track( $attachment->ID );
		}
	}

	public function test_status_recovers_stale_operations_from_every_persisted_import_phase(): void {
		$phases = array( 'media', 'cms-content', 'components', 'templates', 'pages', 'styles' );
		$stale_at = time() - 7201;
		$user_id = get_current_user_id();

		foreach ( $phases as $phase ) {
			$operation_id = wp_generate_uuid4();
			$project_id = wp_generate_uuid4();
			$lock = array(
				'operationId' => $operation_id,
				'projectId' => $project_id,
				'requestHash' => str_repeat( 'a', 64 ),
				'userId' => $user_id,
				'createdAt' => $stale_at,
				'updatedAt' => $stale_at,
			);
			$this->set_option( 'xb_remote_operation_' . $operation_id, array(
				'operationId' => $operation_id,
				'action' => 'import',
				'published' => false,
				'state' => 'applying',
				'phase' => $phase,
				'changed' => array(),
				'updatedAt' => gmdate( 'c', $stale_at ),
				'userId' => $user_id,
				'projectId' => $project_id,
				'requestHash' => str_repeat( 'a', 64 ),
			) );
			$this->set_option( 'xb_remote_operation_lock_' . $operation_id, $lock );
			$this->set_option( 'xb_remote_project_lock_' . $project_id, $lock );

			$recovered = PageProfileAdapter::status( $operation_id, $user_id );

			$this->assertIsArray( $recovered, 'A stale operation from phase ' . $phase . ' remains inspectable after a process interruption.' );
			$this->assertSame( 'partial', $recovered['state'], 'An interrupted import is never reported as complete.' );
			$this->assertSame( 'interrupted', $recovered['phase'], 'The persisted phase is normalized to a recoverable interruption.' );
			$this->assertSame( 'operation-interrupted', $recovered['failure'] );
			$this->assertArrayNotHasKey( 'userId', $recovered );
			$this->assertArrayNotHasKey( 'requestHash', $recovered );
			$this->assertFalse( get_option( 'xb_remote_operation_lock_' . $operation_id, false ) );
			$this->assertFalse( get_option( 'xb_remote_project_lock_' . $project_id, false ) );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function interrupted_import_phases(): array {
		return array(
			'media'       => array( 'media' ),
			'cms content' => array( 'cms-content' ),
			'components'  => array( 'components' ),
			'templates'   => array( 'templates' ),
			'pages'       => array( 'pages' ),
			'styles'      => array( 'styles' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'interrupted_import_phases' )]
	public function test_a_real_php_import_process_can_be_killed_and_recovered_during_each_phase( string $phase ): void {
		global $wpdb;
		$project_fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v3.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $project_fixture['profile']['manifest'];
		$entities = $project_fixture['profile']['entities'];
		foreach ( $entities as $entity ) {
			if ( 'content-type' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['key'] ?? null ) ) {
				$this->profile_post_types[] = $entity['payload']['key'];
			} elseif ( 'taxonomy' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['key'] ?? null ) ) {
				$this->profile_taxonomies[] = $entity['payload']['key'];
			}
		}
		$references = $this->require_profile_rows( $project_fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $project_fixture['profile']['manifest']['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$encoded = $project_fixture['files'][ $file['path'] ] ?? null;
			$decoded = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			$this->assertIsString( $decoded, 'Every CMS fixture file must decode before the crash test.' );
			$files[ $file['path'] ] = $decoded;
		}

		// Add one real media import and one reusable component so each structural phase has work to interrupt.
		$page_fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$asset = $this->profile_entity_by_id( $page_fixture['profile']['entities'], '42345678-1234-4234-8234-123456789abc' );
		$asset['id'] = wp_generate_uuid4();
		$asset['revisionHash'] = PageProfile::revision_hash( $asset );
		$asset_path = $asset['payload']['path'];
		$asset_bytes = base64_decode( (string) $page_fixture['files'][ $asset_path ], true );
		$this->assertIsString( $asset_bytes );
		$files[ $asset_path ] = $asset_bytes;
		$manifest['files'][] = array( 'path' => $asset_path, 'bytes' => strlen( $asset_bytes ), 'sha256' => hash( 'sha256', $asset_bytes ) );
		$entities[] = $asset;

		$component_id = wp_generate_uuid4();
		$component = array(
			'id'           => $component_id,
			'kind'         => 'component',
			'schemaVersion'=> 1,
			'payload'      => array(
				'title'   => 'Interruzione di prova',
				'ref'     => 'crash-test/recovery-card',
				'schema'  => array( 'props' => array() ),
				'content' => '<!-- wp:xb/element {"uid":"crash0001","tag":"div"} /-->',
				'script'  => '',
			),
		);
		$component['revisionHash'] = PageProfile::revision_hash( $component );
		$entities[] = $component;
		$manifest['requiredCapabilities'][] = 'components:definition:v1';
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$operation_id = $manifest['selection']['snapshot'];
		$project_id = $manifest['project']['id'];
		$target_entity_id = match ( $phase ) {
			'media'       => $asset['id'],
			'components'  => $component_id,
			'templates'   => $this->profile_entity_by_id( $entities, '550e8400-e29b-41d4-a716-446655440112' )['id'],
			'pages'       => $this->profile_entity_by_id( $entities, '550e8400-e29b-41d4-a716-446655440101' )['id'],
			'styles'      => $this->profile_entity_by_id( $entities, '550e8400-e29b-41d4-a716-446655440102' )['id'],
			default       => '',
		};
		$this->assertContains( $phase, array( 'media', 'cms-content', 'components', 'templates', 'pages', 'styles' ) );

		foreach ( array(
			'xb_page_profile_project_id',
			'xb_page_profile_project_name',
			'xb_page_profile_styles_base_hash',
			'xb_page_profile_styles_id',
			'xb_page_profile_tombstones',
			'xb_page_profile_content_type_ids',
			'xb_page_profile_content_type_templates',
			'xb_page_profile_taxonomy_ids',
			'xb_page_profile_query_ids',
			'xb_page_profile_source_ids',
			'xb_page_profile_relational_bases',
			'xb_page_profile_default_locale',
			'xb_page_profile_relational_routes',
		) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $operation_id, $project_id );

		$archive = $this->build_profile_archive( $manifest, $entities, $references, $files );
		$archive_path = tempnam( sys_get_temp_dir(), 'xb-import-crash-archive-' );
		$marker_path = tempnam( sys_get_temp_dir(), 'xb-import-crash-marker-' );
		$script_path = dirname( __DIR__ ) . '/Fixtures/interrupt-page-profile-import.php';
		$this->assertNotFalse( $archive_path );
		$this->assertNotFalse( $marker_path );
		$this->assertNotFalse( file_put_contents( $archive_path, $archive ) );
		@unlink( $marker_path ); // The helper waits until it creates this marker after a real WordPress mutation.

		$post_ids_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ), true );
		$term_rows_before = $wpdb->get_results( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}", ARRAY_A );
		$terms_before = array();
		foreach ( is_array( $term_rows_before ) ? $term_rows_before : array() as $term_row ) {
			$terms_before[ (int) $term_row['term_id'] . ':' . (string) $term_row['taxonomy'] ] = true;
		}

		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process = proc_open(
			array( PHP_BINARY, $script_path, ABSPATH . 'wp-load.php', $archive_path, $operation_id, $phase, $target_entity_id, $marker_path ),
			$descriptors,
			$pipes
		);
		$this->assertIsResource( $process, 'The isolated PHP import process must start.' );
		fclose( $pipes[0] );
		$marker = null;
		$child_output = '';
		try {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $marker_path ) && microtime( true ) < $deadline ) {
				$state = proc_get_status( $process );
				if ( ! $state['running'] ) {
					$child_output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
					$this->fail( 'The child import exited before reaching phase ' . $phase . '. Output: ' . $child_output );
				}
				usleep( 50000 );
			}
			$this->assertFileExists( $marker_path, 'The child import must perform the selected phase mutation before the timeout.' );
			$marker_json = file_get_contents( $marker_path );
			$marker = is_string( $marker_json ) ? json_decode( $marker_json, true, 32, JSON_THROW_ON_ERROR ) : null;
			$this->assertIsArray( $marker );
			$this->assertSame( $phase, $marker['phase'] ?? null );
			$this->assertSame( $operation_id, $marker['operationId'] ?? null );
			$this->assertSame(
				array(
					'media'       => 'asset-imported',
					'cms-content' => 'content-definition-saved',
					'components'  => 'component-imported',
					'templates'   => 'template-imported',
					'pages'       => 'page-imported',
					'styles'      => 'styles-applied',
				)[ $phase ],
				$marker['event'] ?? null
			);
			$this->assertTrue( proc_terminate( $process, 9 ), 'The import process must accept SIGKILL while suspended after the phase mutation.' );
			$exit_deadline = microtime( true ) + 5;
			do {
				$state = proc_get_status( $process );
				if ( ! $state['running'] ) {
					break;
				}
				usleep( 50000 );
			} while ( microtime( true ) < $exit_deadline );
			$this->assertFalse( $state['running'], 'The import subprocess must be gone before the recovery check.' );
		} finally {
			if ( is_resource( $process ) ) {
				$state = proc_get_status( $process );
				if ( $state['running'] ) {
					proc_terminate( $process, 9 );
				}
				foreach ( array( 1, 2 ) as $pipe_index ) {
					if ( isset( $pipes[ $pipe_index ] ) && is_resource( $pipes[ $pipe_index ] ) ) {
						fclose( $pipes[ $pipe_index ] );
					}
				}
				proc_close( $process );
			}
			@unlink( $archive_path );
			@unlink( $marker_path );
		}

		wp_cache_flush();
		$raw_status = get_option( 'xb_remote_operation_' . $operation_id );
		$this->assertIsArray( $raw_status );
		$this->assertSame( 'applying', $raw_status['state'] ?? null );
		$this->assertSame( $phase, $raw_status['phase'] ?? null );
		if ( in_array( $phase, array( 'media', 'components', 'pages' ), true ) ) {
			$this->assertContains( $target_entity_id, (array) ( $raw_status['changed'] ?? array() ) );
		}
		$stale_at = time() - 7201;
		foreach ( array( 'xb_remote_operation_lock_' . $operation_id, 'xb_remote_project_lock_' . $project_id ) as $lock_key ) {
			$lock = get_option( $lock_key );
			$this->assertIsArray( $lock, 'A killed import must leave a recoverable lock record.' );
			$lock['updatedAt'] = $stale_at;
			update_option( $lock_key, $lock, false );
		}
		$recovered = PageProfileAdapter::status( $operation_id, get_current_user_id() );
		$this->assertIsArray( $recovered );
		$this->assertSame( 'partial', $recovered['state'] );
		$this->assertSame( 'interrupted', $recovered['phase'] );
		$this->assertSame( 'operation-interrupted', $recovered['failure'] );
		$this->assertArrayNotHasKey( 'userId', $recovered );
		$this->assertArrayNotHasKey( 'requestHash', $recovered );
		$this->assertFalse( get_option( 'xb_remote_operation_lock_' . $operation_id, false ) );
		$this->assertFalse( get_option( 'xb_remote_project_lock_' . $project_id, false ) );

		// A new plan after inspecting an interrupted import must converge to the requested profile without
		// duplicating entities that the killed request had already written.
		$retry_manifest = $manifest;
		$retry_operation_id = wp_generate_uuid4();
		$retry_manifest['selection']['snapshot'] = $retry_operation_id;
		$retry_archive = $this->build_profile_archive( $retry_manifest, $entities, $references, $files );
		// The child process was killed; mirror the next WordPress request bootstrapping its saved CMS definitions.
		\XB\Content\ContentTypes::forget();
		\XB\Content\ContentTypes::register();
		try {
			$retry_report = PageProfileAdapter::import( $retry_archive, false );
		} catch ( ProfileConflictException $error ) {
			$this->fail( 'A fresh plan must reconcile the interrupted ' . $phase . ' phase: ' . wp_json_encode( $error->conflicts ) );
		}
		$this->assertSame( $retry_operation_id, $retry_report['operationId'] ?? null );
		$this->assertTrue( $retry_report['complete'] ?? false );
		$reconciled = PageProfile::decode( PageProfileAdapter::export() );
		$reconciled_ids = array_column( $reconciled['entities'], 'id' );
		$reconciled_counts = array_count_values( $reconciled_ids );
		foreach ( $entities as $entity ) {
			$this->assertSame( 1, $reconciled_counts[ $entity['id'] ] ?? 0, 'Recovery after phase ' . $phase . ' must leave exactly one copy of ' . $entity['kind'] . ' ' . $entity['id'] . '.' );
			if ( in_array( $entity['kind'], array( 'component', 'template-part', 'template' ), true ) && ! array_key_exists( 'status', $entity['payload'] ) ) {
				$restored = $this->profile_entity_by_id( $reconciled['entities'], $entity['id'] );
				$this->assertArrayNotHasKey( 'status', $restored['payload'], 'Legacy reusable profiles keep their omitted status field stable during recovery.' );
			}
		}
		$reconciled_asset = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $asset['id'] ) );
		$this->assertCount( 1, $reconciled_asset, 'Replanning must not duplicate media written before the crash.' );
		$reconciled_asset_path = get_attached_file( $reconciled_asset[0]->ID );
		$this->assertIsString( $reconciled_asset_path );
		$this->assertSame( hash( 'sha256', $asset_bytes ), hash_file( 'sha256', $reconciled_asset_path ) );

		foreach ( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ) as $post_id ) {
			if ( ! isset( $post_ids_before[ $post_id ] ) ) {
				$this->track( $post_id );
			}
		}
		$term_rows_after = $wpdb->get_results( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}", ARRAY_A );
		foreach ( is_array( $term_rows_after ) ? $term_rows_after : array() as $term_row ) {
			$key = (int) $term_row['term_id'] . ':' . (string) $term_row['taxonomy'];
			if ( ! isset( $terms_before[ $key ] ) ) {
				$this->profile_terms[] = array( 'id' => (int) $term_row['term_id'], 'taxonomy' => (string) $term_row['taxonomy'] );
			}
		}
	}

	/**
	 * Sends the project fixture (types, records, templates, a page) and tracks everything it creates.
	 *
	 * @return array{report: array<string, mixed>, manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>}
	 */
	private function send_project_fixture( bool $publish ): array {
		global $wpdb;
		$project_fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v3.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $project_fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $project_fixture['profile']['entities'];
		$references = $this->require_profile_rows( $project_fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$encoded = $project_fixture['files'][ $file['path'] ] ?? null;
			$decoded = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			$this->assertIsString( $decoded );
			$files[ $file['path'] ] = $decoded;
		}
		foreach ( $entities as $entity ) {
			if ( 'content-type' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['key'] ?? null ) ) {
				$this->profile_post_types[] = $entity['payload']['key'];
			} elseif ( 'taxonomy' === ( $entity['kind'] ?? null ) && is_string( $entity['payload']['key'] ?? null ) ) {
				$this->profile_taxonomies[] = $entity['payload']['key'];
			}
		}
		foreach ( array(
			'xb_page_profile_project_id',
			'xb_page_profile_project_name',
			'xb_page_profile_styles_base_hash',
			'xb_page_profile_styles_id',
			'xb_page_profile_tombstones',
			'xb_page_profile_content_type_ids',
			'xb_page_profile_content_type_templates',
			'xb_page_profile_taxonomy_ids',
			'xb_page_profile_query_ids',
			'xb_page_profile_source_ids',
			'xb_page_profile_relational_bases',
			'xb_page_profile_default_locale',
			'xb_page_profile_relational_routes',
		) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}

		$post_ids_before = array_fill_keys( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ), true );
		$term_rows_before = $wpdb->get_results( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}", ARRAY_A );
		$terms_before = array();
		foreach ( is_array( $term_rows_before ) ? $term_rows_before : array() as $term_row ) {
			$terms_before[ (int) $term_row['term_id'] . ':' . (string) $term_row['taxonomy'] ] = true;
		}
		$archive = $this->build_profile_archive( $manifest, $entities, $references, $files );
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$report = PageProfileAdapter::import( $archive, $publish );
		$this->assertTrue( $report['complete'] ?? false );
		foreach ( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) ) as $post_id ) {
			if ( ! isset( $post_ids_before[ $post_id ] ) ) {
				$this->track( $post_id );
			}
		}
		$term_rows_after = $wpdb->get_results( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy}", ARRAY_A );
		foreach ( is_array( $term_rows_after ) ? $term_rows_after : array() as $term_row ) {
			$key = (int) $term_row['term_id'] . ':' . (string) $term_row['taxonomy'];
			if ( ! isset( $terms_before[ $key ] ) ) {
				$this->profile_terms[] = array( 'id' => (int) $term_row['term_id'], 'taxonomy' => (string) $term_row['taxonomy'] );
			}
		}

		return array( 'report' => $report, 'manifest' => $manifest, 'entities' => $entities, 'references' => $references, 'files' => $files );
	}

	public function test_a_wordpress_edit_after_an_unpublished_import_is_still_a_conflict(): void {
		[ 'report' => $report, 'manifest' => $manifest, 'entities' => $entities, 'references' => $references, 'files' => $files ] = $this->send_project_fixture( false );
		// Records wait for the next release; the profile already shows them as sent.
		$record_id = '550e8400-e29b-41d4-a716-446655440107';
		$this->assertSame( array(), get_posts( array( 'post_type' => 'speaker', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) ) );
		$this->assertContains( array( 'id' => $record_id, 'reason' => 'next-release' ), $report['pending'] );
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertContains( $record_id, array_column( $exported['entities'], 'id' ) );

		// The new page is a draft in WordPress; the profile shows it as the CMS sent it (published, waiting).
		$page_id = '550e8400-e29b-41d4-a716-446655440101';
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $page_id ) );
		$this->assertCount( 1, $pages );
		$page = $pages[0];
		$this->assertSame( 'draft', $page->post_status );
		$this->assertSame( 'published', $this->profile_entity_by_id( $exported['entities'], $page_id )['payload']['status'] );

		$updated_page = wp_update_post( array( 'ID' => $page->ID, 'post_title' => 'Modificato in WordPress' ), true );
		$this->assertNotInstanceOf( \WP_Error::class, $updated_page );
		$this->assertSame( 'Modificato in WordPress', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $page_id )['payload']['title'], 'A WordPress edit is never covered by the fields waiting for a release.' );

		$retry_manifest = $manifest;
		$retry_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$retry_archive = $this->build_profile_archive( $retry_manifest, $entities, $references, $files );
		try {
			PageProfileAdapter::import( $retry_archive, false );
			$this->fail( 'A new plan must not replace a WordPress edit made after the imported draft was created.' );
		} catch ( ProfileConflictException $error ) {
			$this->assertContains( $page_id, array_column( $error->conflicts, 'id' ) );
		}
		$this->assertSame( 'Modificato in WordPress', get_post_field( 'post_title', $page->ID ) );
	}

	public function test_the_next_builder_release_applies_what_histos_cms_sent(): void {
		[ 'report' => $report ] = $this->send_project_fixture( false );
		$page_id   = '550e8400-e29b-41d4-a716-446655440101';
		$record_id = '550e8400-e29b-41d4-a716-446655440107';
		$page      = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $page_id ) )[0];
		$this->assertSame( 'draft', $page->post_status );
		$this->assertFalse( post_type_exists( 'speaker' ), 'Types sent without publishing are registered only by a release.' );
		$this->assertContains( array( 'id' => $record_id, 'reason' => 'next-release' ), $report['pending'] );

		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		$this->assertSame( 'publish', get_post_status( $page->ID ), 'The page takes the status the CMS sent with the release.' );
		$this->assertSame( '', get_post_meta( $page->ID, '_xb_page_profile_pending_fields', true ) );
		$this->assertTrue( post_type_exists( 'speaker' ) );
		$records = get_posts( array( 'post_type' => 'speaker', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) );
		$this->assertCount( 1, $records, 'The queued record follows the release.' );
		$this->track( $records[0]->ID );
		$this->assertSame( 'publish', $records[0]->post_status );
		$this->assertNotContains( $record_id, array_keys( \XB\Remote\ContentProfileAdapter::queue()['entities'] ) );
		foreach ( get_posts( array( 'post_type' => 'events', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $event ) {
			$this->track( $event->ID );
		}
		foreach ( array( 'event-category', 'speaker-topic' ) as $taxonomy ) {
			$term_ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) );
			foreach ( is_array( $term_ids ) ? $term_ids : array() as $term_id ) {
				$this->profile_terms[] = array( 'id' => (int) $term_id, 'taxonomy' => $taxonomy );
			}
		}
		$this->assertNotEmpty( get_option( 'xb_page_profile_content_type_templates' ), 'The type templates follow the release.' );
	}

	public function test_a_wordpress_edit_before_the_release_wins_over_the_queued_record(): void {
		$this->send_project_fixture( true );
		$record_id = '550e8400-e29b-41d4-a716-446655440107';
		$record    = get_posts( array( 'post_type' => 'speaker', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) )[0];
		$exported  = PageProfile::decode( PageProfileAdapter::export() );

		// The CMS sends a new title without publishing; someone then edits the live record in WordPress.
		$entities = array();
		foreach ( $exported['entities'] as $entity ) {
			if ( $record_id === $entity['id'] ) {
				$entity['payload']['title'] = 'Titolo dal CMS';
				$entity['revisionHash']     = PageProfile::revision_hash( $entity );
			}
			$entities[] = $entity;
		}
		$manifest = $exported['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $exported['references'], $exported['files'] ), false );
		$this->assertContains( array( 'id' => $record_id, 'reason' => 'next-release' ), $report['pending'] );
		$this->assertSame( 'Titolo dal CMS', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $record_id )['payload']['title'] );

		wp_update_post( array( 'ID' => $record->ID, 'post_title' => 'Modificato in WordPress' ) );
		$this->assertSame( 'Modificato in WordPress', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $record_id )['payload']['title'], 'The download shows the WordPress edit, so the CMS sees the conflict.' );

		\XB\Remote\ContentProfileAdapter::apply_queue( 1 );
		$this->assertSame( 'Modificato in WordPress', get_post_field( 'post_title', $record->ID ), 'A release never replaces a WordPress edit made after the send.' );
		$this->assertArrayNotHasKey( $record_id, \XB\Remote\ContentProfileAdapter::queue()['entities'] );
	}

	public function test_unlinked_tombstone_conflicts_before_wordpress_content_or_operation_state_changes(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$previous = $entities[0];
		$previous_hash = PageProfile::revision_hash( $previous );
		$replacement = $previous;
		$replacement['id'] = wp_generate_uuid4();
		$replacement['payload']['title'] = 'Pagina conservata';
		$replacement['payload']['slug'] = 'pagina-conservata';
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
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$files = array();
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
		$archive = PageProfile::archive( $manifest, $entities, $references, $files );
		$operation_key = 'xb_remote_operation_' . $manifest['selection']['snapshot'];
		$project_option_before = get_option( 'xb_page_profile_project_id', false );
		$page_ids_before = wp_list_pluck( get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1 ) ), 'ID' );

		try {
			PageProfileAdapter::import( $archive, false );
			$this->fail( 'A tombstone import must stop before changing WordPress.' );
		} catch ( ProfileConflictException $error ) {
			$this->assertCount( 1, $error->conflicts );
			$this->assertSame( $previous['id'], $error->conflicts[0]['id'] );
		}

		$this->assertFalse( get_option( $operation_key, false ), 'a rejected tombstone must not create an operation record' );
		$this->assertSame( $project_option_before, get_option( 'xb_page_profile_project_id', false ), 'a rejected tombstone must not pair or change the project' );
		$this->assertSame( $page_ids_before, wp_list_pluck( get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1 ) ), 'ID' ), 'a rejected tombstone must not change WordPress content' );
	}

	public function test_trashing_an_unrelated_wordpress_post_does_not_create_a_cms_tombstone(): void {
		$post_id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'draft', 'post_title' => 'Blocco non collegato', 'post_content' => '<!-- wp:paragraph --><p>Contenuto</p><!-- /wp:paragraph -->' ), true );
		$this->assertIsInt( $post_id );
		$this->track( $post_id );
		update_post_meta( $post_id, '_xb_page_profile_id', wp_generate_uuid4() );
		update_post_meta( $post_id, '_xb_page_profile_base_hash', str_repeat( 'a', 64 ) );

		PageProfileAdapter::record_profile_trash( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_xb_page_profile_tombstone', true ) );
	}

	public function test_linked_page_tombstone_is_trashed_recoverably_and_exported_with_the_same_head(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$source_page = current( array_filter( $entities, static fn ( array $entity ): bool => 'document' === $entity['kind'] ) );
		$this->assertIsArray( $source_page );
		$second_page = $source_page;
		$second_page['id'] = wp_generate_uuid4();
		$second_page['payload']['title'] = 'Pagina di conservazione';
		$second_page['payload']['slug'] = 'pagina-di-conservazione';
		$second_page['revisionHash'] = PageProfile::revision_hash( $second_page );
		$entities[] = $second_page;
		$second_references = array_map(
			static function ( array $reference ) use ( $source_page, $second_page ): array {
				if ( $source_page['id'] === $reference['ownerId'] ) {
					$reference['ownerId'] = $second_page['id'];
				}
				return $reference;
			},
			$references
		);
		$references = array_merge( $references, $second_references );
		$archive_files = array();
		$entity_bytes = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$reference_bytes = implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n";
		foreach ( $manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = 'entities.ndjson' === $path ? $entity_bytes : ( 'references.ndjson' === $path ? $reference_bytes : (string) base64_decode( $fixture['files'][ $path ], true ) );
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
			$archive_files[ $path ] = $bytes;
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$initial_id = $manifest['selection']['snapshot'];
		$this->isolate_operation( $initial_id, $manifest['project']['id'] );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$initial_report = PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $archive_files ), false );
		$this->assertCount( 2, $initial_report['pages'] );
		foreach ( $initial_report['pages'] as $page ) {
			$this->track( $page['postId'] );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$linked_page_id = $source_page['id'];
		$base_profile = PageProfile::decode( PageProfileAdapter::export() );
		$previous = current( array_filter( $base_profile['entities'], static fn ( array $entity ): bool => $linked_page_id === $entity['id'] ) );
		$this->assertIsArray( $previous );
		$this->assertSame( $previous['revisionHash'], $previous['baseHash'] );
		$tombstone = array(
			'id' => $previous['id'],
			'kind' => 'tombstone',
			'schemaVersion' => 1,
			'baseHash' => $previous['baseHash'],
			'payload' => array(
				'entityKind' => 'document',
				'deletedAt' => gmdate( 'Y-m-d\\TH:i:s.000\\Z' ),
				'label' => $previous['payload']['title'],
				'previousRevisionHash' => $previous['revisionHash'],
				'previousPayload' => $previous['payload'],
			),
		);
		$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
		$entities = array_values( array_filter( $base_profile['entities'], static fn ( array $entity ): bool => $linked_page_id !== $entity['id'] ) );
		$entities[] = $tombstone;
		$references = array_values( array_filter( $base_profile['references'], static fn ( array $reference ): bool => $linked_page_id !== $reference['ownerId'] ) );
		$manifest = $base_profile['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$archive_files = $base_profile['files'];
		$archive_files['entities.ndjson'] = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$archive_files['references.ndjson'] = implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n";
		foreach ( $manifest['files'] as &$file ) {
			$bytes = $archive_files[ $file['path'] ];
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$delete_archive = PageProfile::archive( $manifest, $entities, $references, $archive_files );
		// Note 91 of the 3 November audit: trashing asks for delete_post, as WordPress does, not only edit_post.
		$no_delete = static fn ( array $caps, string $cap ): array => 'delete_post' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $no_delete, 10, 2 );
		try {
			PageProfileAdapter::import( $delete_archive, true );
			$this->fail( 'A link user who cannot delete the page trashed it.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'cestinare', $error->getMessage() );
		} finally {
			remove_filter( 'map_meta_cap', $no_delete, 10 );
		}
		$this->assertNotSame( 'trash', get_post_status( $initial_report['pages'][0]['postId'] ) );
		// Deletions reach the Trash only when publishing (docs/spec/cms-bridge.md §6).
		$delete_report = PageProfileAdapter::import( $delete_archive, true );
		$this->assertSame( array( $linked_page_id ), array_column( $delete_report['deletions'], 'id' ) );
		$this->assertSame( $tombstone['revisionHash'], $delete_report['heads'][ $linked_page_id ] );
		$page_post = get_post( $initial_report['pages'][0]['postId'] );
		$this->assertInstanceOf( \WP_Post::class, $page_post );
		$this->assertSame( 'trash', $page_post->post_status );
		$this->assertSame( PageProfile::canonical( $tombstone ), PageProfile::canonical( get_option( 'xb_page_profile_tombstones' )[ $linked_page_id ] ) );
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$exported_tombstone = current( array_filter( $exported['entities'], static fn ( array $entity ): bool => $linked_page_id === $entity['id'] ) );
		$this->assertIsArray( $exported_tombstone );
		$this->assertSame( $tombstone['revisionHash'], $exported_tombstone['revisionHash'] );

		$this->assertNotFalse( wp_untrash_post( $page_post->ID ) );
		$this->assertSame( '', get_post_meta( $page_post->ID, '_xb_page_profile_tombstone', true ) );
		$this->assertArrayNotHasKey( $linked_page_id, get_option( 'xb_page_profile_tombstones', array() ) );
		$restored = PageProfile::decode( PageProfileAdapter::export() );
		$restored_page = current( array_filter( $restored['entities'], static fn ( array $entity ): bool => $linked_page_id === $entity['id'] ) );
		$this->assertIsArray( $restored_page );
		$this->assertSame( 'document', $restored_page['kind'] );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $page_post->ID ) );
		$this->assertSame( 'trash', get_post_status( $page_post->ID ) );
		$manually_deleted = PageProfile::decode( PageProfileAdapter::export() );
		$manual_tombstone = current( array_filter( $manually_deleted['entities'], static fn ( array $entity ): bool => $linked_page_id === $entity['id'] ) );
		$this->assertIsArray( $manual_tombstone );
		$this->assertSame( 'tombstone', $manual_tombstone['kind'] );
		$this->assertSame( 'Pagina prova 😀', $manual_tombstone['payload']['label'] );
	}

	public function test_relational_profile_preserves_same_slug_across_locales_and_distinct_metadata_for_shared_media(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v3.json' ), true, 128, JSON_THROW_ON_ERROR );
		$page_fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$bytes = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			$this->assertNotFalse( $bytes, 'Every original profile file must decode.' );
			$files[ $file['path'] ] = $bytes;
		}

		$source_asset = current( array_filter( $page_fixture['profile']['entities'], static fn ( array $entity ): bool => 'asset' === $entity['kind'] ) );
		$this->assertIsArray( $source_asset );
		$media_path = $source_asset['payload']['path'];
		$media_bytes = base64_decode( (string) $page_fixture['files'][ $media_path ], true );
		$this->assertNotFalse( $media_bytes, 'The shared PNG fixture must decode.' );
		$sha256 = hash( 'sha256', $media_bytes );
		$media_path = 'media/' . $sha256;
		$files[ $media_path ] = $media_bytes;
		$manifest['files'][] = array( 'path' => $media_path, 'bytes' => strlen( $media_bytes ), 'sha256' => $sha256, 'mediaType' => 'image/png' );
		usort( $manifest['files'], static fn ( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );

		$speaker_id = '550e8400-e29b-41d4-a716-446655440107';
		$italian_id = '550e8400-e29b-41d4-a716-446655440204';
		$asset_it_id = '550e8400-e29b-41d4-a716-446655440201';
		$asset_en_id = '550e8400-e29b-41d4-a716-446655440202';
		foreach ( $entities as &$entity ) {
			if ( 'content-type' === $entity['kind'] && 'speaker' === $entity['payload']['key'] ) {
				$entity['payload']['fields'][] = array( 'key' => 'portrait', 'label' => 'Ritratto', 'type' => 'asset', 'required' => false, 'public' => true, 'multiple' => false );
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
			if ( 'content-record' === $entity['kind'] && $speaker_id === $entity['id'] ) {
				$entity['payload']['values']['portrait'] = $asset_en_id;
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$speaker = $this->profile_entity_by_id( $entities, $speaker_id );
		$italian = $speaker;
		$italian['id'] = $italian_id;
		$italian['payload']['locale'] = 'it-IT';
		$italian['payload']['title'] = 'Ada relatrice';
		$italian['payload']['values']['biography'] = 'Ricercatrice italiana';
		$italian['payload']['values']['portrait'] = $asset_it_id;
		unset( $italian['baseHash'] );
		$italian['revisionHash'] = PageProfile::revision_hash( $italian );
		$entities[] = $italian;

		foreach ( array(
			array( 'id' => $asset_it_id, 'alt' => 'Ada al convegno', 'title' => 'ada-it.png', 'caption' => 'Relatrice italiana' ),
			array( 'id' => $asset_en_id, 'alt' => 'Ada at the conference', 'title' => 'ada-en.png', 'caption' => 'English speaker portrait' ),
		) as $metadata ) {
			$asset = array(
				'id' => $metadata['id'], 'kind' => 'asset', 'schemaVersion' => 1,
				'payload' => array( 'sha256' => $sha256, 'mediaType' => 'image/png', 'bytes' => strlen( $media_bytes ), 'path' => $media_path, 'alt' => $metadata['alt'], 'title' => $metadata['title'], 'caption' => $metadata['caption'] ),
			);
			$asset['revisionHash'] = PageProfile::revision_hash( $asset );
			$entities[] = $asset;
		}

		foreach ( array(
			'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id',
			'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids', 'xb_page_profile_query_ids',
			'xb_page_profile_source_ids', 'xb_page_profile_relational_bases', 'xb_page_profile_default_locale', 'xb_page_profile_relational_routes',
			'xb_page_profile_tombstones', \XB\Content\ContentTypes::OPTION, \XB\Render\LoopPresets::OPTION, \XB\Render\Sources::OPTION,
		) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		// Records reach WordPress when publishing; without it they wait for the next release.
		$report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $references, $files ), true );
		$this->assertSame( 3, $report['content']['records'] );
		$this->profile_post_types = array( 'speaker', 'events' );
		$this->profile_taxonomies = array( 'speaker-topic', 'event-category' );
		$profile_entity_ids = array_fill_keys( array_column( $entities, 'id' ), true );
		foreach ( get_posts( array( 'post_type' => array_values( get_post_types() ), 'post_status' => array( 'draft', 'publish', 'private', 'pending', 'future', 'inherit', 'trash' ), 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $post ) {
			$profile_id = (string) get_post_meta( $post->ID, '_xb_page_profile_id', true );
			if ( isset( $profile_entity_ids[ $profile_id ] ) ) {
				$this->track( $post->ID );
			}
		}
		foreach ( $entities as $entity ) {
			if ( 'term' !== $entity['kind'] ) { continue; }
			$term_ids = get_terms( array( 'taxonomy' => $entity['payload']['taxonomyKey'], 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => $entity['id'] ) );
			$this->assertFalse( is_wp_error( $term_ids ) );
			foreach ( $term_ids as $term_id ) { $this->profile_terms[] = array( 'id' => (int) $term_id, 'taxonomy' => $entity['payload']['taxonomyKey'] ); }
		}

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$exported_en = $this->profile_entity_by_id( $exported['entities'], $speaker_id );
		$exported_it = $this->profile_entity_by_id( $exported['entities'], $italian_id );
		$this->assertSame( 'ada-relatrice', $exported_en['payload']['slug'] );
		$this->assertSame( 'ada-relatrice', $exported_it['payload']['slug'] );
		$this->assertSame( 'en-US', $exported_en['payload']['locale'] );
		$this->assertSame( 'it-IT', $exported_it['payload']['locale'] );
		$this->assertSame( $asset_en_id, $exported_en['payload']['values']['portrait'] );
		$this->assertSame( $asset_it_id, $exported_it['payload']['values']['portrait'] );
		$exported_asset_en = $this->profile_entity_by_id( $exported['entities'], $asset_en_id );
		$exported_asset_it = $this->profile_entity_by_id( $exported['entities'], $asset_it_id );
		$this->assertSame( $exported_asset_en['payload']['path'], $exported_asset_it['payload']['path'] );
		$this->assertSame( 'Ada at the conference', $exported_asset_en['payload']['alt'] );
		$this->assertSame( 'English speaker portrait', $exported_asset_en['payload']['caption'] );
		$this->assertSame( 'Ada al convegno', $exported_asset_it['payload']['alt'] );
		$this->assertSame( 'Relatrice italiana', $exported_asset_it['payload']['caption'] );
		$this->assertSame( $media_bytes, $exported['files'][ $exported_asset_en['payload']['path'] ] );
		$this->assertCount( 1, array_filter( $exported['manifest']['files'], static fn ( array $file ): bool => str_starts_with( $file['path'], 'media/' ) ) );

		$english_post = get_posts( array( 'post_type' => 'speaker', 'post_status' => array( 'draft', 'publish', 'private' ), 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $speaker_id ) );
		$italian_post = get_posts( array( 'post_type' => 'speaker', 'post_status' => array( 'draft', 'publish', 'private' ), 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $italian_id ) );
		$this->assertCount( 1, $english_post );
		$this->assertCount( 1, $italian_post );
		$this->assertNotSame( $english_post[0]->post_name, $italian_post[0]->post_name, 'WordPress receives collision-free native slugs while Histeon retains its localized portable slug.' );
	}

	public function test_linked_content_record_tombstone_is_recoverable_and_rejects_incoming_relations(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v3.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = 'entities.ndjson' === $path
				? implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n"
				: ( 'references.ndjson' === $path
					? ( array() === $references ? '' : implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n" )
					: (string) base64_decode( $fixture['files'][ $path ], true ) );
			$this->assertNotFalse( $bytes, 'All fixture files must decode.' );
			$files[ $path ] = $bytes;
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$operation_id = $manifest['selection']['snapshot'];
		foreach ( array(
			'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id',
			'xb_page_profile_content_type_ids', 'xb_page_profile_content_type_templates', 'xb_page_profile_taxonomy_ids',
			'xb_page_profile_query_ids', 'xb_page_profile_source_ids', 'xb_page_profile_relational_bases',
			'xb_page_profile_default_locale', 'xb_page_profile_relational_routes', 'xb_page_profile_tombstones',
			\XB\Content\ContentTypes::OPTION, \XB\Render\LoopPresets::OPTION, \XB\Render\Sources::OPTION,
		) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $operation_id, $manifest['project']['id'] );
		// Published: the records must exist in WordPress before they can be deleted.
		$initial_report = PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $files ), true );
		foreach ( $entities as $entity ) {
			if ( 'term' !== ( $entity['kind'] ?? null ) ) { continue; }
			$taxonomy = (string) ( $entity['payload']['taxonomyKey'] ?? '' );
			$term_ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => '_xb_page_profile_id', 'meta_value' => $entity['id'] ) );
			if ( is_wp_error( $term_ids ) ) {
				throw new \RuntimeException( $term_ids->get_error_message() );
			}
			foreach ( $term_ids as $term_id ) {
				$this->profile_terms[] = array( 'id' => (int) $term_id, 'taxonomy' => $taxonomy );
			}
		}
		foreach ( array( 'pages', 'components', 'templateParts', 'templates' ) as $collection ) {
			foreach ( $initial_report[ $collection ] ?? array() as $item ) {
				$this->track( (int) $item['postId'] );
			}
		}
		foreach ( get_posts( array( 'post_type' => array( 'speaker', 'events' ), 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $record_post ) {
			$this->track( $record_post->ID );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$this->assertSame( 2, $initial_report['content']['records'] );

		$profile = PageProfile::decode( PageProfileAdapter::export() );
		$record_id = '550e8400-e29b-41d4-a716-446655440107';
		$event_id = '550e8400-e29b-41d4-a716-446655440108';
		$speaker = $this->profile_entity_by_id( $profile['entities'], $record_id );
		$event = $this->profile_entity_by_id( $profile['entities'], $event_id );
		$this->assertSame( 'content-record', $speaker['kind'] );
		$this->assertSame( 'content-record', $event['kind'] );
		$this->assertSame( $speaker['revisionHash'], $speaker['baseHash'] );
		$this->assertSame( $event['revisionHash'], $event['baseHash'] );
		$speaker_posts = get_posts( array( 'post_type' => 'speaker', 'post_status' => array( 'draft', 'publish', 'private' ), 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) );
		$this->assertCount( 1, $speaker_posts );
		$speaker_post_id = (int) $speaker_posts[0]->ID;
		$this->assertFalse( wp_trash_post( $speaker_post_id ), 'WordPress must block manual deletion while another live CMS record references this record.' );
		$this->assertSame( 'publish', get_post_status( $speaker_post_id ) );
		$this->assertSame( '', get_post_meta( $speaker_post_id, '_xb_page_profile_tombstone', true ) );
		$this->assertFalse( wp_delete_post( $speaker_post_id, true ), 'A linked CMS record without a recoverable tombstone cannot be permanently deleted.' );
		$this->assertSame( 'publish', get_post_status( $speaker_post_id ) );
		$event_posts = get_posts( array( 'post_type' => 'events', 'post_status' => array( 'draft', 'publish', 'private' ), 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $event_id ) );
		$this->assertCount( 1, $event_posts );
		$event_post_id = (int) $event_posts[0]->ID;

		$make_archive = static function ( array $deleted_ids, array $omitted_ids = array() ) use ( $profile ): string {
			$entities = array();
			foreach ( $profile['entities'] as $entity ) {
				if ( in_array( $entity['id'], $omitted_ids, true ) ) {
					continue;
				}
				if ( ! in_array( $entity['id'], $deleted_ids, true ) ) {
					$entities[] = $entity;
					continue;
				}
				$tombstone = array(
					'id' => $entity['id'], 'kind' => 'tombstone', 'schemaVersion' => 1, 'baseHash' => $entity['baseHash'],
					'payload' => array(
						'entityKind' => $entity['kind'], 'deletedAt' => gmdate( 'Y-m-d\\TH:i:s.000\\Z' ),
						'label' => $entity['payload']['title'], 'previousRevisionHash' => $entity['revisionHash'],
						'previousPayload' => $entity['payload'],
					),
				);
				$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
				$entities[] = $tombstone;
			}
			$manifest = $profile['manifest'];
			if ( ! array_filter( $entities, static fn ( array $entity ): bool => 'content-record' === $entity['kind'] ) ) {
				$manifest['requiredCapabilities'] = array_values( array_diff( $manifest['requiredCapabilities'], array( 'content:records:v1' ) ) );
			}
			$manifest['selection']['snapshot'] = wp_generate_uuid4();
			$files = $profile['files'];
			$files['entities.ndjson'] = implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
			$files['references.ndjson'] = array() === $profile['references'] ? '' : implode( "\n", array_map( PageProfile::canonical(...), $profile['references'] ) ) . "\n";
			foreach ( $manifest['files'] as &$file ) {
				$bytes = $files[ $file['path'] ];
				$file['bytes'] = strlen( $bytes );
				$file['sha256'] = hash( 'sha256', $bytes );
			}
			unset( $file );
			$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
			return PageProfile::archive( $manifest, $entities, $profile['references'], $files );
		};

		// Note 91 of the 3 November audit: an existing record is overwritten only by someone who may edit it.
		$no_edit = static fn ( array $caps, string $cap, int $user, array $args ): array => 'edit_post' === $cap && $speaker_post_id === (int) ( $args[0] ?? 0 ) ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $no_edit, 10, 4 );
		try {
			PageProfileAdapter::import( $make_archive( array() ), true );
			$this->fail( 'A link user who cannot edit the record overwrote it.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( $record_id, $error->getMessage() );
		} finally {
			remove_filter( 'map_meta_cap', $no_edit, 10 );
		}

		try {
			PageProfileAdapter::import( $make_archive( array( $record_id ), array( $event_id ) ), false );
			$this->fail( 'WordPress must reject deleting a record while another record still relates to it.' );
		} catch ( ProfileConflictException $error ) {
			$this->assertSame( $record_id, $error->conflicts[0]['id'] );
		}
		$this->assertSame( 'publish', get_post_status( get_posts( array( 'post_type' => 'speaker', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $record_id ) )[0]->ID ) );

		$delete_report = PageProfileAdapter::import( $make_archive( array( $event_id ) ), true );
		$this->assertSame( array( $event_id ), array_column( $delete_report['deletions'], 'id' ) );
		$event_post = get_post( $event_post_id );
		$this->assertInstanceOf( \WP_Post::class, $event_post );
		$this->assertSame( 'trash', $event_post->post_status );
		$deleted_profile = PageProfile::decode( PageProfileAdapter::export() );
		$deleted_event = $this->profile_entity_by_id( $deleted_profile['entities'], $event_id );
		$this->assertSame( 'tombstone', $deleted_event['kind'] );
		$this->assertSame( $event['revisionHash'], $deleted_event['payload']['previousRevisionHash'] );
		$this->assertSame( 'Incontro portabile', $deleted_event['payload']['previousPayload']['title'] );

		$this->assertNotFalse( wp_untrash_post( $event_post->ID ) );
		$this->assertSame( '', get_post_meta( $event_post->ID, '_xb_page_profile_tombstone', true ) );
		$restored_profile = PageProfile::decode( PageProfileAdapter::export() );
		$restored_event = $this->profile_entity_by_id( $restored_profile['entities'], $event_id );
		$this->assertSame( 'content-record', $restored_event['kind'] );
		$this->assertSame( 'Incontro portabile', $restored_event['payload']['title'] );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $event_post->ID ) );
		$manual_delete = PageProfile::decode( PageProfileAdapter::export() );
		$manual_tombstone = $this->profile_entity_by_id( $manual_delete['entities'], $event_id );
		$this->assertSame( 'tombstone', $manual_tombstone['kind'] );
		$this->assertSame( 'content-record', $manual_tombstone['payload']['entityKind'] );
		$this->assertNotFalse( wp_untrash_post( $event_post->ID ) );
		// WordPress restores from the Trash as a draft; republish it to match the profile the CMS deletes from.
		wp_publish_post( $event_post->ID );
		$batch_report = PageProfileAdapter::import( $make_archive( array( $record_id, $event_id ) ), true );
		$this->assertEqualsCanonicalizing( array( $record_id, $event_id ), array_column( $batch_report['deletions'], 'id' ) );
		$this->assertSame( 'trash', get_post_status( $speaker_post_id ) );
		$this->assertSame( 'trash', get_post_status( $event_post_id ) );
	}

	public function test_component_tombstone_requires_no_references_and_can_be_restored(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$bytes = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			$this->assertNotFalse( $bytes, 'Every profile fixture file must decode.' );
			$files[ $file['path'] ] = $bytes;
		}
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$initial_report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $references, $files ), false );
		foreach ( array( 'pages', 'components', 'templateParts', 'templates' ) as $collection ) {
			foreach ( $initial_report[ $collection ] ?? array() as $item ) {
				$this->track( (int) $item['postId'] );
			}
		}
		$component_id = '550e8400-e29b-41d4-a716-446655440023';
		$component_post_id = (int) $initial_report['components'][0]['postId'];
		$profile = PageProfile::decode( PageProfileAdapter::export() );
		$component = $this->profile_entity_by_id( $profile['entities'], $component_id );
		$make_tombstone = static function ( array $previous ): array {
			$tombstone = array(
				'id' => $previous['id'],
				'kind' => 'tombstone',
				'schemaVersion' => 1,
				'baseHash' => $previous['baseHash'],
				'payload' => array(
					'entityKind' => $previous['kind'],
					'deletedAt' => gmdate( 'Y-m-d\\TH:i:s.000\\Z' ),
					'label' => $previous['payload']['title'],
					'previousRevisionHash' => $previous['revisionHash'],
					'previousPayload' => $previous['payload'],
				),
			);
			$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
			return $tombstone;
		};
		$blocked_entities = array_values( array_filter( $profile['entities'], static fn ( array $entity ): bool => $component_id !== $entity['id'] ) );
		$blocked_entities[] = $make_tombstone( $component );
		$blocked_manifest = $profile['manifest'];
		$blocked_manifest['profile'] = PageProfile::PROFILE;
		$blocked_manifest['requiredCapabilities'] = array_values( array_diff( $blocked_manifest['requiredCapabilities'], array( 'components:definition:v1' ) ) );
		$blocked_manifest['selection']['snapshot'] = wp_generate_uuid4();
		try {
			$this->build_profile_archive( $blocked_manifest, $blocked_entities, $profile['references'], $profile['files'] );
			$this->fail( 'A component still referenced by a page cannot be removed from a valid transfer profile.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'reference', strtolower( $error->getMessage() ) );
		}

		$page_id = '550e8400-e29b-41d4-a716-446655440021';
		$updated_entities = $profile['entities'];
		foreach ( $updated_entities as &$entity ) {
			if ( $page_id !== $entity['id'] ) {
				continue;
			}
			$entity['payload']['content'] = '<!-- wp:xb/element {"uid":"homeelem123","tag":"main"} --><!-- wp:xb/text {"uid":"hometext123","content":"Pagina senza componente"} /--><!-- /wp:xb/element -->';
			$entity['revisionHash'] = PageProfile::revision_hash( $entity );
		}
		unset( $entity );
		$update_manifest = $profile['manifest'];
		$update_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$update_references = array_values( array_filter( $profile['references'], static fn ( array $reference ): bool => $component_id !== ( $reference['targetId'] ?? null ) ) );
		PageProfileAdapter::import( $this->build_profile_archive( $update_manifest, $updated_entities, $update_references, $profile['files'] ), false );

		$base_profile = PageProfile::decode( PageProfileAdapter::export() );
		$component = $this->profile_entity_by_id( $base_profile['entities'], $component_id );
		$component_tombstone = $make_tombstone( $component );
		$delete_entities = array_values( array_filter( $base_profile['entities'], static fn ( array $entity ): bool => $component_id !== $entity['id'] ) );
		$delete_entities[] = $component_tombstone;
		$delete_manifest = $base_profile['manifest'];
		$delete_manifest['profile'] = PageProfile::PROFILE;
		$delete_manifest['requiredCapabilities'] = array_values( array_diff( $delete_manifest['requiredCapabilities'], array( 'components:definition:v1' ) ) );
		$delete_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$delete_report = PageProfileAdapter::import( $this->build_profile_archive( $delete_manifest, $delete_entities, $base_profile['references'], $base_profile['files'] ), true );
		$this->assertSame( array( $component_id ), array_column( $delete_report['deletions'], 'id' ) );
		$this->assertSame( 'trash', get_post_status( $component_post_id ) );
		$deleted_profile = PageProfile::decode( PageProfileAdapter::export() );
		$deleted_component = $this->profile_entity_by_id( $deleted_profile['entities'], $component_id );
		$this->assertSame( 'tombstone', $deleted_component['kind'] );
		$this->assertSame( $component['revisionHash'], $deleted_component['payload']['previousRevisionHash'] );

		$component_post = get_post( $component_post_id );
		$this->assertInstanceOf( \WP_Post::class, $component_post );
		$this->assertNotFalse( wp_untrash_post( $component_post->ID ) );
		$this->assertSame( '', get_post_meta( $component_post_id, '_xb_page_profile_tombstone', true ) );
		$restored_profile = PageProfile::decode( PageProfileAdapter::export() );
		$restored_component = $this->profile_entity_by_id( $restored_profile['entities'], $component_id );
		$this->assertSame( 'component', $restored_component['kind'] );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $component_post_id ) );
		$manual_delete = PageProfile::decode( PageProfileAdapter::export() );
		$manual_tombstone = $this->profile_entity_by_id( $manual_delete['entities'], $component_id );
		$this->assertSame( 'tombstone', $manual_tombstone['kind'] );
		$this->assertSame( 'component', $manual_tombstone['payload']['entityKind'] );
		$this->assertNotFalse( wp_delete_post( $component_post_id, true ), 'A component with a remembered tombstone may be permanently deleted after it has been trashed.' );
	}

	public function test_unreferenced_media_tombstone_requires_no_references_and_can_be_restored(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$bytes = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			$this->assertNotFalse( $bytes, 'Every profile fixture file must decode.' );
			$files[ $file['path'] ] = $bytes;
		}
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$initial_report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $references, $files ), false );
		$page_post_id = (int) $initial_report['pages'][0]['postId'];
		$this->track( $page_post_id );
		$asset_id = '42345678-1234-4234-8234-123456789abc';
		$asset_posts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $asset_id ) );
		$this->assertCount( 1, $asset_posts );
		$asset_post_id = (int) $asset_posts[0]->ID;
		$this->track( $asset_post_id );

		$profile = PageProfile::decode( PageProfileAdapter::export() );
		$asset = $this->profile_entity_by_id( $profile['entities'], $asset_id );
		$make_tombstone = static function ( array $previous ): array {
			$tombstone = array(
				'id' => $previous['id'],
				'kind' => 'tombstone',
				'schemaVersion' => 1,
				'baseHash' => $previous['baseHash'],
				'payload' => array(
					'entityKind' => 'asset',
					'deletedAt' => gmdate( 'Y-m-d\\TH:i:s.000\\Z' ),
					'label' => $previous['payload']['title'],
					'previousRevisionHash' => $previous['revisionHash'],
					'previousPayload' => $previous['payload'],
				),
			);
			$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
			return $tombstone;
		};
		$blocked_entities = array_values( array_filter( $profile['entities'], static fn ( array $entity ): bool => $asset_id !== $entity['id'] ) );
		$blocked_entities[] = $make_tombstone( $asset );
		$blocked_manifest = $profile['manifest'];
		$blocked_manifest['selection']['snapshot'] = wp_generate_uuid4();
		try {
			$this->build_profile_archive( $blocked_manifest, $blocked_entities, $profile['references'], $profile['files'] );
			$this->fail( 'Media still referenced by a page cannot be removed from a valid transfer profile.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'media', strtolower( $error->getMessage() ) );
		}

		$page_id = '22345678-1234-4234-8234-123456789abc';
		$updated_entities = $profile['entities'];
		foreach ( $updated_entities as &$entity ) {
			if ( $page_id !== $entity['id'] ) {
				continue;
			}
			$entity['payload']['content'] = '<!-- wp:xb/text {"uid":"assetremoved1","content":"Immagine rimossa"} /-->';
			$entity['revisionHash'] = PageProfile::revision_hash( $entity );
		}
		unset( $entity );
		$update_manifest = $profile['manifest'];
		$update_manifest['selection']['snapshot'] = wp_generate_uuid4();
		PageProfileAdapter::import( $this->build_profile_archive( $update_manifest, $updated_entities, array(), $profile['files'] ), false );

		$unreferenced_profile = PageProfile::decode( PageProfileAdapter::export() );
		$unreferenced_asset = $this->profile_entity_by_id( $unreferenced_profile['entities'], $asset_id );
		$this->assertSame( 'asset', $unreferenced_asset['kind'], 'Unreferenced Histeon media remains in the editable transfer profile.' );
		$delete_tombstone = $make_tombstone( $unreferenced_asset );
		$delete_entities = array_values( array_filter( $unreferenced_profile['entities'], static fn ( array $entity ): bool => $asset_id !== $entity['id'] ) );
		$delete_entities[] = $delete_tombstone;
		$delete_manifest = $unreferenced_profile['manifest'];
		$delete_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$delete_report = PageProfileAdapter::import( $this->build_profile_archive( $delete_manifest, $delete_entities, array(), $unreferenced_profile['files'] ), true );
		$this->assertSame( array( $asset_id ), array_column( $delete_report['deletions'], 'id' ) );
		$this->assertSame( 'trash', get_post_status( $asset_post_id ) );
		$this->assertSame( 'tombstone', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $asset_id )['kind'] );

		$asset_post = get_post( $asset_post_id );
		$this->assertInstanceOf( \WP_Post::class, $asset_post );
		$this->assertNotFalse( wp_untrash_post( $asset_post->ID ) );
		$this->assertSame( '', get_post_meta( $asset_post_id, '_xb_page_profile_tombstone', true ) );
		$this->assertSame( 'asset', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $asset_id )['kind'] );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $asset_post_id ) );
		$this->assertSame( 'tombstone', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $asset_id )['kind'] );
		$this->assertNotFalse( wp_delete_post( $asset_post_id, true ), 'Media with a remembered tombstone may be permanently deleted after it has been trashed.' );
	}

	public function test_project_profile_imports_component_definitions_as_native_reusable_blocks(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$path = $file['path'];
			$bytes = base64_decode( $fixture['files'][ $path ], true );
			$this->assertNotFalse( $bytes, 'All fixture files must decode.' );
			$files[ $path ] = $bytes;
		}
		$operation_id = $manifest['selection']['snapshot'];
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $operation_id, $manifest['project']['id'] );

		$archive = PageProfile::archive( $manifest, $entities, $references, $files );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertCount( 1, $report['components'] );
		$component = $report['components'][0];
		$component_post = get_post( $component['postId'] );
		$this->assertInstanceOf( \WP_Post::class, $component_post );
		$this->track( $component_post->ID );
		$this->assertSame( 'wp_block', $component_post->post_type );
		$this->assertSame( 'draft', $component_post->post_status );
		$this->assertSame( $component['id'], get_post_meta( $component_post->ID, '_xb_page_profile_id', true ) );
		$this->assertSame( 'ui/card', get_post_meta( $component_post->ID, \XB\Render\ComponentRegistry::META_KEY, true ) );
		$this->assertEquals( array( 'props' => array( 'heading' => array( 'type' => 'string', 'default' => 'Titolo', 'label' => 'Titolo' ) ) ), json_decode( (string) get_post_meta( $component_post->ID, \XB\Render\ComponentRegistry::META_SCHEMA, true ), true ) );
		$page_post = get_post( $report['pages'][0]['postId'] );
		$this->assertInstanceOf( \WP_Post::class, $page_post );
		$this->track( $page_post->ID );
		$this->assertSame( 'draft', $page_post->post_status );
		$this->assertStringContainsString( 'wp:xb/component', $this->dev_content( $page_post->ID ) );
		$expected_heads = array_column( $entities, 'revisionHash', 'id' );
		ksort( $expected_heads, SORT_STRING );
		$actual_heads = $report['heads'];
		ksort( $actual_heads, SORT_STRING );
		// The v3 snapshot also reports generated project baseline entities, such as Histeon styles.
		$this->assertSame( $expected_heads, array_intersect_key( $actual_heads, $expected_heads ) );
	}

	public function test_template_project_imports_and_exports_templates_parts_and_page_assignment(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$page_id = '550e8400-e29b-41d4-a716-446655440021';
		$part_id = '550e8400-e29b-41d4-a716-446655440025';
		$template_id = '550e8400-e29b-41d4-a716-446655440026';
		foreach ( $entities as &$entity ) {
			if ( 'document' === $entity['kind'] && $page_id === $entity['id'] ) {
				$entity['payload']['templateRef'] = 'site/page';
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$part = array(
			'id' => $part_id,
			'kind' => 'template-part',
			'schemaVersion' => 1,
			'payload' => array(
				'title' => 'Piè di pagina',
				'ref' => 'site/footer',
				'schema' => array( 'props' => array(
					'show' => array( 'type' => 'boolean', 'default' => true ),
					'items' => array( 'type' => 'array', 'default' => array( 'uno', 'due' ) ),
				) ),
				'content' => '<!-- wp:xb/element {"uid":"partroot123","tag":"footer"} --><!-- wp:xb/if {"uid":"partif0001"} --><!-- wp:xb/branch {"uid":"partbranch01","test":"props.show"} --><!-- wp:xb/loop {"uid":"partloop001","source":{"kind":"expr","expr":"props.items"},"as":"item","index":"idx"} --><!-- wp:xb/text {"uid":"parttext001","content":"{item.upper()}"} /--><!-- /wp:xb/loop --><!-- /wp:xb/branch --><!-- wp:xb/branch {"uid":"partelse001"} --><!-- wp:xb/text {"uid":"parttext002","content":"Nessun evento"} /--><!-- /wp:xb/branch --><!-- /wp:xb/if --><!-- /wp:xb/element -->',
				'script' => '',
				'area' => 'footer',
			),
		);
		$part['revisionHash'] = PageProfile::revision_hash( $part );
		$template = array(
			'id' => $template_id,
			'kind' => 'template',
			'schemaVersion' => 1,
			'payload' => array(
				'title' => 'Pagina',
				'ref' => 'site/page',
				'schema' => array( 'props' => array( 'page' => array( 'type' => 'object', 'default' => array( 'title' => '', 'slug' => '', 'url' => '/' ) ) ) ),
				'content' => '<!-- wp:xb/component {"uid":"footeruse123","ref":"site/footer"} /--><!-- wp:xb/text {"uid":"pagetext123","content":"{props.page.title}"} /--><!-- wp:xb/if {"uid":"pageif00001"} --><!-- wp:xb/branch {"uid":"pagebranch01","test":"props.page.slug == \\"home\\""} --><!-- wp:xb/slot {"uid":"pageslot123","name":"default"} /--><!-- /wp:xb/branch --><!-- wp:xb/branch {"uid":"pageelse001"} --><!-- wp:xb/text {"uid":"pageelse002","content":"Contenuto standard"} /--><!-- /wp:xb/branch --><!-- /wp:xb/if -->',
				'script' => '',
			),
		);
		$template['revisionHash'] = PageProfile::revision_hash( $template );
		$entities[] = $part;
		$entities[] = $template;
		$references[] = array( 'ownerId' => $template_id, 'blockUid' => 'footeruse123', 'path' => 'attrs.ref', 'targetId' => $part_id, 'role' => 'document' );
		$manifest['profile'] = PageProfile::TEMPLATE_PROJECT_PROFILE;
		$manifest['requiredCapabilities'][] = 'templates:definition:v1';
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = 'entities.ndjson' === $path
				? implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n"
				: ( 'references.ndjson' === $path
					? implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n"
					: (string) base64_decode( $fixture['files'][ $path ], true ) );
			$this->assertNotFalse( $bytes );
			$files[ $path ] = $bytes;
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		$operation_id = $manifest['selection']['snapshot'];
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $operation_id, $manifest['project']['id'] );

		$report = PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $files ), false );
		$this->assertCount( 1, $report['templates'] );
		$this->assertCount( 1, $report['templateParts'] );
		$template_post = get_post( $report['templates'][0]['postId'] );
		$part_post = get_post( $report['templateParts'][0]['postId'] );
		$page_post = get_post( $report['pages'][0]['postId'] );
		$this->assertInstanceOf( \WP_Post::class, $template_post );
		$this->assertInstanceOf( \WP_Post::class, $part_post );
		$this->assertInstanceOf( \WP_Post::class, $page_post );
		$this->track( $template_post->ID );
		$this->track( $part_post->ID );
		$this->track( $page_post->ID );
		// The project fixture also includes a reusable component. Remove it after
		// this test so the next test starts without unpaired Histeon content.
		foreach ( $report['components'] as $component ) {
			$this->track( (int) $component['postId'] );
		}
		$this->assertSame( 'wp_template', $template_post->post_type );
		$this->assertEquals( $template['payload']['schema'], json_decode( (string) get_post_meta( $template_post->ID, '_xb_page_profile_template_schema', true ), true ) );
		$this->assertSame( 'wp_template_part', $part_post->post_type );
		$part_areas = wp_get_object_terms( $part_post->ID, 'wp_template_part_area', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $part_areas ) ) {
			throw new \RuntimeException( 'Impossibile verificare l’area del template part: ' . $part_areas->get_error_message() );
		}
		$this->assertContains( 'footer', $part_areas );
		$this->assertStringContainsString( 'wp:xb/post-content', $this->dev_content( $template_post->ID ) );
		$this->assertStringContainsString( '{props.page.title}', $this->dev_content( $template_post->ID ) );
		$this->assertSame( 'site/page', get_post_meta( $page_post->ID, '_xb_page_profile_template_ref', true ) );
		$this->assertSame( $template_post->post_name, get_post_meta( $page_post->ID, '_wp_page_template', true ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertSame( PageProfile::TEMPLATE_PROJECT_PROFILE, $exported['manifest']['profile'] );
		$exported_page = current( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'document' === $entity['kind'] && $page_id === $entity['id'] ) );
		$exported_template = current( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'template' === $entity['kind'] && $template_id === $entity['id'] ) );
		$exported_part = current( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'template-part' === $entity['kind'] && $part_id === $entity['id'] ) );
		if ( ! is_array( $exported_page ) || ! is_array( $exported_template ) || ! is_array( $exported_part ) ) {
			throw new \RuntimeException( 'L’esportazione non ha mantenuto pagina, template e template part.' );
		}
		$this->assertSame( 'site/page', $exported_page['payload']['templateRef'] );
		$this->assertStringContainsString( 'wp:xb/slot', $exported_template['payload']['content'] );
		$this->assertStringContainsString( '{props.page.title}', $exported_template['payload']['content'] );
		$this->assertStringContainsString( 'wp:xb/if', $exported_template['payload']['content'] );
		$this->assertStringContainsString( 'props.page.slug', $exported_template['payload']['content'] );
		$this->assertEquals( $template['payload']['schema'], $exported_template['payload']['schema'] );
		$this->assertStringContainsString( 'wp:xb/loop', $exported_part['payload']['content'] );
		$this->assertStringContainsString( 'props.items', $exported_part['payload']['content'] );
		$this->assertSame( 'footer', $exported_part['payload']['area'] );

		$invalid_entities = $entities;
		foreach ( $invalid_entities as &$invalid_entity ) {
			if ( 'template' !== $invalid_entity['kind'] || $template_id !== $invalid_entity['id'] ) {
				continue;
			}
			$invalid_entity['payload']['content'] = str_replace( '"ref":"site/footer"', '"props":{"undeclared":"no"},"ref":"site/footer"', $invalid_entity['payload']['content'] );
			$invalid_entity['baseHash'] = $exported_template['revisionHash'];
			$invalid_entity['revisionHash'] = PageProfile::revision_hash( $invalid_entity );
		}
		unset( $invalid_entity );
		$invalid_manifest = $manifest;
		$invalid_files = $files;
		foreach ( $invalid_manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = match ( $path ) {
				'entities.ndjson' => implode( "\n", array_map( PageProfile::canonical(...), $invalid_entities ) ) . "\n",
				'references.ndjson' => implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n",
				default => $invalid_files[ $path ],
			};
			$invalid_files[ $path ] = $bytes;
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$invalid_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$invalid_manifest['manifestHash'] = PageProfile::manifest_hash( $invalid_manifest );
		try {
			PageProfileAdapter::import( PageProfile::archive( $invalid_manifest, $invalid_entities, $references, $invalid_files ), false );
			$this->fail( 'WordPress must reject component props that are not declared by the target schema.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'does not declare prop undeclared', $error->getMessage() );
		}
		$after_rejection = PageProfile::decode( PageProfileAdapter::export() );
		$unchanged_template = current( array_filter( $after_rejection['entities'], static fn ( array $entity ): bool => 'template' === $entity['kind'] && $template_id === $entity['id'] ) );
		if ( ! is_array( $unchanged_template ) ) {
			throw new \RuntimeException( 'Il template è scomparso dopo il rifiuto del payload non valido.' );
		}
		$this->assertSame( $exported_template['revisionHash'], $unchanged_template['revisionHash'] );

		// A template must be unassigned before it can be deleted. Its part may be deleted
		// in the same operation because the only remaining reference belongs to that template.
		$unassigned_entities = $exported['entities'];
		foreach ( $unassigned_entities as &$entity ) {
			if ( 'document' === $entity['kind'] && $page_id === $entity['id'] ) {
				unset( $entity['payload']['templateRef'] );
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$unassign_manifest = $exported['manifest'];
		$unassign_manifest['selection']['snapshot'] = wp_generate_uuid4();
		PageProfileAdapter::import( $this->build_profile_archive( $unassign_manifest, $unassigned_entities, $exported['references'], $exported['files'] ), false );
		$unassigned_profile = PageProfile::decode( PageProfileAdapter::export() );
		$template = $this->profile_entity_by_id( $unassigned_profile['entities'], $template_id );
		$part = $this->profile_entity_by_id( $unassigned_profile['entities'], $part_id );
		$make_tombstone = static function ( array $previous ): array {
			$tombstone = array(
				'id' => $previous['id'],
				'kind' => 'tombstone',
				'schemaVersion' => 1,
				'baseHash' => $previous['baseHash'],
				'payload' => array(
					'entityKind' => $previous['kind'],
					'deletedAt' => gmdate( 'Y-m-d\\TH:i:s.000\\Z' ),
					'label' => $previous['payload']['title'],
					'previousRevisionHash' => $previous['revisionHash'],
					'previousPayload' => $previous['payload'],
				),
			);
			$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
			return $tombstone;
		};
		$deleted_ids = array( $template_id, $part_id );
		$delete_entities = array_values( array_filter( $unassigned_profile['entities'], static fn ( array $entity ): bool => ! in_array( $entity['id'], $deleted_ids, true ) ) );
		$delete_entities[] = $make_tombstone( $template );
		$delete_entities[] = $make_tombstone( $part );
		$delete_references = array_values( array_filter( $unassigned_profile['references'], static fn ( array $reference ): bool => ! in_array( $reference['ownerId'] ?? null, $deleted_ids, true ) && ! in_array( $reference['targetId'] ?? null, $deleted_ids, true ) ) );
		$delete_manifest = $unassigned_profile['manifest'];
		$delete_manifest['profile'] = PageProfile::PROJECT_PROFILE;
		$delete_manifest['requiredCapabilities'] = array_values( array_diff( $delete_manifest['requiredCapabilities'], array( 'templates:definition:v1' ) ) );
		$delete_manifest['selection']['snapshot'] = wp_generate_uuid4();
		$delete_report = PageProfileAdapter::import( $this->build_profile_archive( $delete_manifest, $delete_entities, $delete_references, $unassigned_profile['files'] ), true );
		$this->assertEqualsCanonicalizing( $deleted_ids, array_column( $delete_report['deletions'], 'id' ) );
		$this->assertSame( 'trash', get_post_status( $template_post->ID ) );
		$this->assertSame( 'trash', get_post_status( $part_post->ID ) );
		$this->assertNotFalse( wp_untrash_post( $template_post->ID ) );
		$this->assertNotFalse( wp_untrash_post( $part_post->ID ) );
		$this->assertSame( 'template', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $template_id )['kind'] );
		$this->assertSame( 'template-part', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $part_id )['kind'] );
		$this->assertFalse( wp_delete_post( $template_post->ID, true ), 'A linked template cannot be permanently deleted while active.' );
		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $template_post->ID ) );
		$this->assertSame( 'tombstone', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $template_id )['kind'] );
		$this->assertNotFalse( wp_delete_post( $template_post->ID, true ), 'A template with a remembered tombstone may be permanently deleted after it has been trashed.' );
	}

	public function test_static_page_profile_round_trips_histos_design_tokens_and_named_queries(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = $fixture['profile']['entities'];
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$css_media = array( 'reference' => '../media/logo.png?v=4', 'assetId' => '42345678-1234-4234-8234-123456789abc' );
		foreach ( $entities as &$entity ) {
			if ( 'styles' !== $entity['kind'] ) {
				continue;
			}
			$entity['payload']['styles'][0]['css'] = 'background-image:url(../media/logo.png?v=4)';
			$entity['payload']['styles'][0]['assets'] = array( $css_media );
			$entity['payload']['globals'][0]['css'] = 'body{background-image:url(../media/logo.png?v=4)}';
			$entity['payload']['globals'][0]['assets'] = array( $css_media );
			$entity['payload']['globals'][0]['source'] = array(
				'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => 'Core Framework',
				'version' => '1.2', 'license' => 'MIT', 'sourceUrl' => 'https://example.com/docs', 'rightsConfirmed' => true,
				'preset' => array( 'format' => 'core-json-v1', 'data' => '{"id":"preset-1","name":"Core","description":"","date":"2026-01-01","groups":{},"cssString":".x{color:red}"}' ),
				'configuration' => array( 'format' => 'opaque-utf8-v1', 'data' => "custom: true\n" ),
			);
			$entity['payload']['design'] = array( 'radius' => 12 );
			$entity['payload']['tokenOverrides'] = array( 'xb-primary' => '#123456' );
			$entity['payload']['queries'] = array( 'mobile' => '(width < 30em)' );
			$entity['revisionHash'] = PageProfile::revision_hash( $entity );
		}
		unset( $entity );
		$files = array();
		foreach ( $manifest['files'] as &$file ) {
			$path = $file['path'];
			$bytes = 'entities.ndjson' === $path
				? implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n"
				: ( 'references.ndjson' === $path
					? implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n"
					: (string) base64_decode( $fixture['files'][ $path ], true ) );
			$this->assertNotFalse( $bytes );
			$files[ $path ] = $bytes;
			$file['bytes'] = strlen( $bytes );
			$file['sha256'] = hash( 'sha256', $bytes );
		}
		unset( $file );
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_design', 'xb_token_overrides', 'xb_named_queries', 'xb_global_styles' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );

		$framework_before = PageProfileAdapter::dev_payload( \XB\Versioning\Documents::SETTINGS )['framework'] ?? null;
		$report = PageProfileAdapter::import( PageProfile::archive( $manifest, $entities, $references, $files ), false );
		$this->assertTrue( $report['complete'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
		}
		$fixture_asset = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_id', 'meta_value' => '42345678-1234-4234-8234-123456789abc' ) );
		foreach ( $fixture_asset as $asset ) {
			$this->track( $asset->ID );
		}
		// Sent without publishing: in the development copy of the settings, not live yet.
		$dev_settings = PageProfileAdapter::dev_payload( \XB\Versioning\Documents::SETTINGS );
		$this->assertEquals( array( 'radius' => 12 ), $dev_settings['design'] );
		$this->assertSame( array( 'xb-primary' => '#123456' ), $dev_settings['tokens'] );
		$this->assertSame( array( 'mobile' => '(width < 30em)' ), $dev_settings['queries'] );
		$this->assertSame( $framework_before ?? 'xb-tokens', $dev_settings['framework'], 'a project of Publish is on Histeon CSS: a site with no choice gets it, a choice already made stays' );
		$this->assertFalse( get_option( 'xb_design' ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$styles = current( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'styles' === $entity['kind'] ) );
		$this->assertIsArray( $styles );
		$this->assertSame( array( 'radius' => 12 ), $styles['payload']['design'] );
		$this->assertSame( array( 'xb-primary' => '#123456' ), $styles['payload']['tokenOverrides'] );
		$this->assertSame( array( 'mobile' => '(width < 30em)' ), $styles['payload']['queries'] );
		$this->assertSame( 'Core Framework', $styles['payload']['globals'][0]['source']['name'] );
		$this->assertSame( 'https://example.com/docs', $styles['payload']['globals'][0]['source']['sourceUrl'] );
		$this->assertSame( 'core-json-v1', $styles['payload']['globals'][0]['source']['preset']['format'] );
		$this->assertSame( '{"id":"preset-1","name":"Core","description":"","date":"2026-01-01","groups":{},"cssString":".x{color:red}"}', $styles['payload']['globals'][0]['source']['preset']['data'] );
		$this->assertSame( 'opaque-utf8-v1', $styles['payload']['globals'][0]['source']['configuration']['format'] );
		$this->assertSame( "custom: true\n", $styles['payload']['globals'][0]['source']['configuration']['data'] );
		$this->assertEquals( array( $css_media ), $styles['payload']['styles'][0]['assets'] );
		$this->assertEquals( array( $css_media ), $styles['payload']['globals'][0]['assets'] );
		$this->assertNotEmpty( $fixture_asset );
		\XB\Versioning\Preview::use_dev();
		$rendered_css = \XB\Plugin::instance()->styles->all_css();
		\XB\Versioning\Preview::reset();
		$this->assertStringContainsString( wp_get_attachment_url( $fixture_asset[0]->ID ) . '?v=4', $rendered_css, 'WordPress resolves the portable relative CSS link to its imported attachment URL.' );
	}

	public function test_relational_query_loop_must_reference_a_query_in_the_transfer_profile_before_writes(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $fixture['profile']['entities'];
		$component_content = '<!-- wp:xb/loop {"uid":"missingloop01","source":{"kind":"preset","id":"missing_events"},"as":"item"} --><!-- wp:xb/text {"uid":"missingtext01","content":"{item.title}"} /--><!-- /wp:xb/loop -->';
		foreach ( $entities as &$entity ) {
			if ( 'component' === $entity['kind'] && 'ui/card' === ( $entity['payload']['ref'] ?? null ) ) {
				$entity['payload']['content'] = $component_content;
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$references = $this->require_profile_rows( $fixture['profile']['references'] ?? null );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$decoded = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			$this->assertIsString( $decoded, 'Every non-entity fixture file must decode before preflight.' );
			$files[ $file['path'] ] = $decoded;
		}
		$archive = $this->build_profile_archive( $manifest, $entities, $references, $files );
		$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array_values( get_post_types() ), 'post_status' => array( 'draft', 'publish', 'private', 'pending', 'future', 'inherit', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$before_options = array();
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_content_type_ids', 'xb_page_profile_query_ids', \XB\Content\ContentTypes::OPTION, \XB\Render\LoopPresets::OPTION ) as $option ) {
			$before_options[ $option ] = get_option( $option, false );
		}

		try {
			PageProfileAdapter::import( $archive, false );
			$this->fail( 'An archive loop cannot reference a query preset omitted from the profile.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'query outside the transfer profile', $error->getMessage() );
		}

		$after_posts = array_map( 'intval', get_posts( array( 'post_type' => array_values( get_post_types() ), 'post_status' => array( 'draft', 'publish', 'private', 'pending', 'future', 'inherit', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$this->assertSame( $before_posts, $after_posts, 'The invalid preset reference must be rejected before WordPress creates or updates posts.' );
		foreach ( $before_options as $option => $value ) {
			$this->assertSame( $value, get_option( $option, false ), 'Preflight must not mutate ' . $option . '.' );
		}
	}

	public function test_static_form_profile_round_trips_explicit_action_and_method(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $fixture['profile']['entities'];
		$page_id = '22345678-1234-4234-8234-123456789abc';
		$form_content = '<!-- wp:xb/element {"uid":"form0001","tag":"form","attributes":{"action":"https://forms.example/submit","method":"post"}} --><!-- wp:xb/element {"uid":"input0001","tag":"input","attributes":{"name":"email","type":"email","required":true}} /--><!-- /wp:xb/element -->';
		foreach ( $entities as &$entity ) {
			if ( $page_id === $entity['id'] ) {
				$entity['payload']['content'] = $form_content;
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$bytes = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			$this->assertIsString( $bytes, 'Every media fixture file must decode before import.' );
			$files[ $file['path'] ] = $bytes;
		}
		$archive = $this->build_profile_archive( $manifest, $entities, array(), $files );
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}

		$report = PageProfileAdapter::import( $archive, false );
		$this->assertTrue( $report['complete'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$round_trip = $this->profile_entity_by_id( $exported['entities'], $page_id );
		$this->assertStringContainsString( '"action":"https://forms.example/submit"', $round_trip['payload']['content'] );
		$this->assertStringContainsString( '"method":"post"', $round_trip['payload']['content'] );
		$this->assertStringContainsString( '"name":"email"', $round_trip['payload']['content'] );
	}

	/**
	 * A fixture page carrying JavaScript (doc 18 §6.6 of Histeon Publish): the archive, its manifest and the page id.
	 *
	 * @return array{0: string, 1: array<string, mixed>, 2: string}
	 */
	private function script_profile_archive( string $script, bool $declare_capability = true ): array {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		if ( $declare_capability ) {
			$manifest['requiredCapabilities'][] = PageProfile::SCRIPT_CAPABILITY;
		}
		$entities = $fixture['profile']['entities'];
		$page_id = '22345678-1234-4234-8234-123456789abc';
		foreach ( $entities as &$entity ) {
			if ( $page_id === $entity['id'] ) {
				$entity['payload']['script'] = $script;
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$files[ $file['path'] ] = (string) base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		return array( $this->build_profile_archive( $manifest, $entities, $fixture['profile']['references'] ?? array(), $files ), $manifest, $page_id );
	}

	public function test_a_page_script_round_trips_under_its_capability_for_a_user_with_unfiltered_html(): void {
		$script = "document.body.dataset.ready = '</script>';";
		[ $archive, , $page_id ] = $this->script_profile_archive( $script );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		// Note 90 of the 3 November audit: the check names the documents that carry JavaScript.
		$plan = PageProfileAdapter::import( $archive, false, true );
		$this->assertCount( 1, array_filter( $plan['entities'], static fn ( array $row ): bool => true === $row['script'] ) );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertTrue( $report['complete'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertContains( PageProfile::SCRIPT_CAPABILITY, $exported['manifest']['requiredCapabilities'] );
		$this->assertSame( $script, $this->profile_entity_by_id( $exported['entities'], $page_id )['payload']['script'] );
	}

	public function test_a_page_script_is_refused_without_its_capability_or_for_a_user_without_unfiltered_html(): void {
		try {
			[ $undeclared ] = $this->script_profile_archive( 'alert(1)', false );
			PageProfileAdapter::import( $undeclared, false );
			$this->fail( 'A script without its capability must be refused.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'requires', $error->getMessage() );
		}
		[ $archive ] = $this->script_profile_archive( 'alert(1)' );
		$filter = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $filter, 10, 2 );
		try {
			PageProfileAdapter::import( $archive, false );
			$this->fail( 'A user without unfiltered_html must not import JavaScript.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'unfiltered_html', $error->getMessage() );
			$this->assertStringContainsString( 'Nothing was written', $error->getMessage() );
		} finally {
			remove_filter( 'map_meta_cap', $filter, 10 );
		}
	}

	public function test_static_page_profile_rejects_forms_without_explicit_action_or_method_before_writes(): void {
		$this->set_option( 'xb_page_profile_project_id', get_option( 'xb_page_profile_project_id', false ) );
		foreach (
			array(
				array( 'uid' => 'form0001', 'tag' => 'form', 'attributes' => array( 'method' => 'post' ) ),
				array( 'uid' => 'form0002', 'tag' => 'form', 'attributes' => array( 'action' => '/forms/contact' ) ),
			)
			as $form_attrs
		) {
			$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
			$manifest = $fixture['profile']['manifest'];
			$manifest['selection']['snapshot'] = wp_generate_uuid4();
			$entities = $fixture['profile']['entities'];
			$page_id = '22345678-1234-4234-8234-123456789abc';
			$page = current( array_filter( $entities, static fn ( array $entity ): bool => 'document' === $entity['kind'] ) );
			$this->assertIsArray( $page );
			$page['payload']['content'] = '<!-- wp:xb/element ' . PageProfile::canonical( $form_attrs ) . ' /-->';
			$page['revisionHash'] = PageProfile::revision_hash( $page );
			foreach ( $entities as &$entity ) {
				if ( $page_id === $entity['id'] ) {
					$entity = $page;
				}
			}
			unset( $entity );
			$files = array();
			foreach ( $manifest['files'] as $file ) {
				if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
					continue;
				}
				$bytes = base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
				$this->assertIsString( $bytes, 'Every media fixture file must decode before preflight.' );
				$files[ $file['path'] ] = $bytes;
			}
			$archive = $this->build_profile_archive( $manifest, $entities, array(), $files );
			$operation_id = $manifest['selection']['snapshot'];
			$this->isolate_operation( $operation_id, $manifest['project']['id'] );
			$before_project_id = get_option( 'xb_page_profile_project_id', false );
			$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );

			try {
				PageProfileAdapter::import( $archive, false );
				$this->fail( 'A portable Histeon form without both endpoint and method must be rejected during preflight.' );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( 'requires a static same-site relative or HTTPS action and an explicit GET or POST method', $error->getMessage() );
			}

			$this->assertFalse( get_option( 'xb_remote_operation_' . $operation_id, false ), 'An invalid form must not create an operation record.' );
			$this->assertSame( $before_project_id, get_option( 'xb_page_profile_project_id', false ), 'An invalid form must not pair or modify the WordPress site.' );
			$this->assertSame( $before_posts, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ), 'An invalid form must not create or update WordPress content.' );
		}
	}

	/** Markup from Histeon CMS is held to the restricted tier even when an administrator owns the link (threat model T26). */
	public function test_static_page_profile_rejects_script_capable_markup_before_writes(): void {
		$this->set_option( 'xb_page_profile_project_id', get_option( 'xb_page_profile_project_id', false ) );
		$text = static fn ( string $content ): string => '<!-- wp:xb/element {"uid":"para0001","tag":"p"} --><!-- wp:xb/text ' . PageProfile::canonical( array( 'uid' => 'text0001', 'content' => $content ) ) . ' /--><!-- /wp:xb/element -->';
		$element = static fn ( array $attributes ): string => '<!-- wp:xb/element ' . PageProfile::canonical( array( 'uid' => 'link0001', 'tag' => 'a', 'attributes' => $attributes ) ) . ' /-->';
		foreach (
			array(
				$text( 'Hi <img src=x onerror=alert(1)>' ),
				$text( '<script>alert(1)</script>' ),
				$text( '<a href="javascript:alert(1)">go</a>' ),
				$text( '<a href="java&#x09;script:alert(1)">go</a>' ),
				$text( '<span style="x" onmouseover="alert(1)">go</span>' ),
				$text( '<svg><a href="https://example.com">go</a></svg>' ),
				$text( '<!-- x --><b>go</b>' ),
				$element( array( 'href' => 'javascript:alert(1)' ) ),
				$element( array( 'href' => 'https://example.com', 'x-on:click' => 'alert(1)' ) ),
				$element( array( 'href' => 'https://example.com', '@click' => 'alert(1)' ) ),
			)
			as $content
		) {
			$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
			$manifest = $fixture['profile']['manifest'];
			$manifest['selection']['snapshot'] = wp_generate_uuid4();
			$entities = $fixture['profile']['entities'];
			$page_id = '22345678-1234-4234-8234-123456789abc';
			foreach ( $entities as &$entity ) {
				if ( $page_id === $entity['id'] ) {
					$entity['payload']['content'] = $content;
					$entity['revisionHash'] = PageProfile::revision_hash( $entity );
				}
			}
			unset( $entity );
			$files = array();
			foreach ( $manifest['files'] as $file ) {
				if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
					continue;
				}
				$files[ $file['path'] ] = (string) base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			}
			$archive = $this->build_profile_archive( $manifest, $entities, array(), $files );
			$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
			$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );

			try {
				PageProfileAdapter::import( $archive, false );
				$this->fail( 'Script-capable markup from Histeon CMS must be rejected: ' . $content );
			} catch ( \DomainException $error ) {
				$this->assertMatchesRegularExpression( '/not allowed in (?:markup|text) from Histeon CMS|not inline formatting/', $error->getMessage(), $content );
			}
			$this->assertSame( $before_posts, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ), 'Rejected markup must not create or update WordPress content.' );
		}
	}

	public function test_static_page_profile_keeps_inline_formatting_and_safe_links(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $fixture['profile']['entities'];
		$page_id = '22345678-1234-4234-8234-123456789abc';
		$inline = 'Tom &amp; Jerry: <strong>bold</strong>, <a href="https://example.com/a?b=1" target="_blank" rel="noopener">link</a>, <a href="/contatti">here</a><br>, <time datetime="2026-10-25">today</time>';
		foreach ( $entities as &$entity ) {
			if ( $page_id === $entity['id'] ) {
				$entity['payload']['content'] = '<!-- wp:xb/element {"uid":"para0001","tag":"p"} --><!-- wp:xb/text ' . PageProfile::canonical( array( 'uid' => 'text0001', 'content' => $inline ) ) . ' /--><!-- /wp:xb/element --><!-- wp:xb/element {"uid":"link0001","tag":"a","attributes":{"href":"mailto:info@example.com","class":"cta"}} /-->';
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			if ( in_array( $file['path'], array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
				continue;
			}
			$files[ $file['path'] ] = (string) base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
		}
		$archive = $this->build_profile_archive( $manifest, $entities, array(), $files );
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}

		$report = PageProfileAdapter::import( $archive, false );
		$this->assertTrue( $report['complete'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$round_trip = $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], $page_id );
		$blocks = parse_blocks( $round_trip['payload']['content'] );
		$this->assertSame( $inline, $blocks[0]['innerBlocks'][0]['attrs']['content'] );
		$this->assertSame( 'mailto:info@example.com', $blocks[1]['attrs']['attributes']['href'] );
	}

	// ------------------------------------------------------------------ media in development (§5)

	private const FIXTURE_ASSET = '42345678-1234-4234-8234-123456789abc';
	private const FIXTURE_PAGE  = '22345678-1234-4234-8234-123456789abc';
	/** Other bytes for the fixture image: a 1×1 PNG. */
	private const OTHER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

	/**
	 * The shared fixture sent with a publish: its page online, showing the fixture image. Returns the profile
	 * downloaded right after, the attachment and the page.
	 *
	 * @return array{profile: array<string, mixed>, attachment: \WP_Post, page: \WP_Post}
	 */
	private function publish_media_fixture(): array {
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$entities = array();
		foreach ( $fixture['profile']['entities'] as $entity ) {
			if ( 'document' === $entity['kind'] ) {
				$entity['payload']['status'] = 'published';
				$entity['revisionHash']      = PageProfile::revision_hash( $entity );
			}
			$entities[] = $entity;
		}
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$files[ $file['path'] ] = str_starts_with( $file['path'], 'media/' ) ? (string) base64_decode( $fixture['files'][ $file['path'] ], true ) : '';
		}
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $this->require_profile_rows( $fixture['profile']['references'] ), $files ), true );
		$this->assertTrue( $report['published'] );
		$attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::FIXTURE_ASSET ) );
		$pages       = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::FIXTURE_PAGE ) );
		$this->assertCount( 1, $attachments );
		$this->assertCount( 1, $pages );
		$this->track( $attachments[0]->ID );
		$this->track( $pages[0]->ID );
		$this->media_files[] = (string) get_attached_file( $attachments[0]->ID );
		$this->assertSame( 'publish', $pages[0]->post_status );
		return array( 'profile' => PageProfile::decode( PageProfileAdapter::export() ), 'attachment' => $attachments[0], 'page' => $pages[0] );
	}

	/**
	 * A downloaded profile sent back with the fixture image changed (new bytes and/or texts), as a new operation.
	 *
	 * @param array<string, mixed>  $profile
	 * @param array<string, string> $texts
	 * @return array{archive: string, entities: list<array<string, mixed>>}
	 */
	private function media_send( array $profile, ?string $bytes, array $texts ): array {
		$manifest = $profile['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$files    = $profile['files'];
		$entities = array();
		foreach ( $profile['entities'] as $entity ) {
			if ( self::FIXTURE_ASSET === $entity['id'] ) {
				if ( null !== $bytes ) {
					$path                         = 'media/' . hash( 'sha256', $bytes );
					$entity['payload']['sha256']  = hash( 'sha256', $bytes );
					$entity['payload']['bytes']   = strlen( $bytes );
					$entity['payload']['path']    = $path;
					$files[ $path ]               = $bytes;
					$manifest['files'][]          = array( 'path' => $path, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'mediaType' => 'application/octet-stream' );
				}
				$entity['payload']      = array_merge( $entity['payload'], $texts );
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
			}
			$entities[] = $entity;
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		return array( 'archive' => $this->build_profile_archive( $manifest, $entities, $profile['references'], $files ), 'entities' => $entities );
	}

	/**
	 * Histeon Publish advances its sync bases only if every head it sent comes back.
	 *
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, mixed>       $report
	 */
	private function assert_heads_confirmed( array $entities, array $report ): void {
		foreach ( $entities as $entity ) {
			$this->assertSame( $entity['revisionHash'], $report['heads'][ $entity['id'] ] ?? null, 'Head of ' . $entity['kind'] . ' ' . $entity['id'] );
		}
	}

	/** The content of a document as it is online. */
	private function live_content( int $post_id ): string {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post->post_content : '';
	}

	/** @return array{file: string, url: string, path: string} The file waiting for a release on an attachment. */
	private function waiting_file( int $attachment_id ): array {
		$file    = (string) get_post_meta( $attachment_id, '_xb_page_profile_pending_file', true );
		$uploads = wp_upload_dir( null, false );
		$this->assertNotSame( '', $file, 'The new bytes wait in a file of their own.' );
		$this->media_files[] = trailingslashit( $uploads['basedir'] ) . $file;
		return array( 'file' => $file, 'url' => trailingslashit( $uploads['baseurl'] ) . $file, 'path' => trailingslashit( $uploads['basedir'] ) . $file );
	}

	public function test_a_development_send_keeps_new_media_bytes_off_the_live_site_until_the_release(): void {
		[ 'profile' => $before, 'attachment' => $attachment, 'page' => $page ] = $this->publish_media_fixture();
		$live_url     = (string) wp_get_attachment_url( $attachment->ID );
		$live_file    = (string) get_attached_file( $attachment->ID );
		$live_hash    = (string) get_post_meta( $attachment->ID, '_xb_page_profile_sha256', true );
		$first_release = (int) get_option( 'xb_current_release' );
		$this->assertStringContainsString( $live_url, $this->live_content( $page->ID ) );
		$bytes = (string) base64_decode( self::OTHER_PNG, true );

		[ 'archive' => $archive, 'entities' => $entities ] = $this->media_send( $before, $bytes, array( 'alt' => 'Logo nuovo', 'caption' => 'Didascalia nuova' ) );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertFalse( $report['published'] );
		$this->assertContains( array( 'id' => self::FIXTURE_ASSET, 'reason' => 'next-release' ), $report['pending'] );
		$this->assert_heads_confirmed( $entities, $report );

		// Online nothing changes: the attachment keeps its file, hash, texts; the page its image.
		clean_post_cache( $attachment->ID );
		$this->assertSame( $live_file, get_attached_file( $attachment->ID ) );
		$this->assertSame( $live_url, wp_get_attachment_url( $attachment->ID ) );
		$this->assertSame( $live_hash, get_post_meta( $attachment->ID, '_xb_page_profile_sha256', true ) );
		$this->assertSame( 'Logo accessibile', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Didascalia fixture', get_post_field( 'post_excerpt', $attachment->ID ) );
		$this->assertSame( $live_hash, hash_file( 'sha256', $live_file ) );
		$this->assertStringContainsString( $live_url, $this->live_content( $page->ID ) );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => self::FIXTURE_ASSET ) ), 'No second attachment in the library.' );

		// In development the page, sent unchanged, shows the waiting file, and can be released.
		$waiting = $this->waiting_file( $attachment->ID );
		$this->assertSame( $bytes, file_get_contents( $waiting['path'] ) );
		$this->assertStringContainsString( $waiting['url'], $this->dev_content( $page->ID ) );
		$this->assertContains( 'post:' . $page->ID, array_column( \XB\Plugin::instance()->versioning->changes(), 'doc' ) );
		// The download shows the media as sent: the head Histeon Publish keeps as its base.
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$asset    = $this->profile_entity_by_id( $exported['entities'], self::FIXTURE_ASSET );
		$this->assertSame( $this->profile_entity_by_id( $entities, self::FIXTURE_ASSET )['revisionHash'], $asset['revisionHash'] );
		$this->assertSame( $asset['revisionHash'], $asset['baseHash'] );
		$this->assertSame( $bytes, $exported['files'][ $asset['payload']['path'] ] );
		$this->assertSame( $this->profile_entity_by_id( $before['entities'], self::FIXTURE_PAGE )['revisionHash'], $this->profile_entity_by_id( $exported['entities'], self::FIXTURE_PAGE )['revisionHash'] );

		// The release puts the new file, its texts and the page online together.
		\XB\Plugin::instance()->versioning->publish( null, 'Release del builder' );
		clean_post_cache( $attachment->ID );
		$this->assertSame( $waiting['url'], wp_get_attachment_url( $attachment->ID ) );
		$this->assertSame( hash( 'sha256', $bytes ), get_post_meta( $attachment->ID, '_xb_page_profile_sha256', true ) );
		$this->assertSame( 'Logo nuovo', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Didascalia nuova', get_post_field( 'post_excerpt', $attachment->ID ) );
		$this->assertSame( 'image/png', get_post_mime_type( $attachment->ID ) );
		$this->assertIsArray( wp_get_attachment_metadata( $attachment->ID ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_file', true ) );
		$this->assertStringContainsString( $waiting['url'], $this->live_content( $page->ID ) );
		$this->assertFileExists( $live_file, 'The earlier file stays: an earlier release shows it.' );
		$released = PageProfile::decode( PageProfileAdapter::export() );
		foreach ( $exported['entities'] as $entity ) {
			$this->assertSame( $entity['revisionHash'], $this->profile_entity_by_id( $released['entities'], $entity['id'] )['revisionHash'], 'The release changes no head: the CMS bases stay valid.' );
		}

		// Restoring the earlier release brings back the page with the earlier file: it still leads to the attachment.
		\XB\Plugin::instance()->versioning->restore( $first_release, false );
		$this->assertStringContainsString( $live_url, $this->live_content( $page->ID ) );
		$this->assertSame( $asset['revisionHash'], $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['revisionHash'] );
	}

	public function test_a_development_send_of_new_alt_text_waits_for_the_release_and_yields_to_wordpress_edits(): void {
		[ 'profile' => $before, 'attachment' => $attachment, 'page' => $page ] = $this->publish_media_fixture();
		$live_file = (string) get_attached_file( $attachment->ID );
		$live_dev  = $this->dev_content( $page->ID );

		[ 'archive' => $archive, 'entities' => $entities ] = $this->media_send( $before, null, array( 'alt' => 'Logo descritto meglio' ) );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertContains( array( 'id' => self::FIXTURE_ASSET, 'reason' => 'next-release' ), $report['pending'] );
		$this->assert_heads_confirmed( $entities, $report );
		clean_post_cache( $attachment->ID );
		$this->assertSame( 'Logo accessibile', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ), 'CMS fields and pages that read the alt online keep it.' );
		$this->assertSame( $live_file, get_attached_file( $attachment->ID ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_file', true ), 'Same bytes: no new file.' );
		$this->assertSame( $live_dev, $this->dev_content( $page->ID ) );
		$this->assertSame( 'Logo descritto meglio', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['payload']['alt'] );

		PageProfileAdapter::apply_pending_media( 1 );
		clean_post_cache( $attachment->ID );
		$this->assertSame( 'Logo descritto meglio', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
		$this->assertSame( $this->profile_entity_by_id( $entities, self::FIXTURE_ASSET )['revisionHash'], $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['revisionHash'] );

		// A WordPress edit after the send wins: the download shows it, and the release drops the waiting change.
		[ 'archive' => $archive ] = $this->media_send( PageProfile::decode( PageProfileAdapter::export() ), null, array( 'alt' => 'Alt dal CMS' ) );
		PageProfileAdapter::import( $archive, false );
		update_post_meta( $attachment->ID, '_wp_attachment_image_alt', 'Modificato in WordPress' );
		$this->assertSame( 'Modificato in WordPress', $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['payload']['alt'] );
		PageProfileAdapter::apply_pending_media( 1 );
		$this->assertSame( 'Modificato in WordPress', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
	}

	/** M6: a send that changes only a media's texts can be released on its own, with the usual checks. */
	public function test_waiting_media_alone_make_a_release_and_only_from_a_complete_send(): void {
		[ 'profile' => $before, 'attachment' => $attachment ] = $this->publish_media_fixture();
		$versioning = \XB\Plugin::instance()->versioning;
		$this->assertCount( 0, $versioning->changes() );

		[ 'archive' => $archive ] = $this->media_send( $before, null, array( 'alt' => 'Solo il testo alternativo' ) );
		PageProfileAdapter::import( $archive, false );
		$this->assertSame( 1, PageProfileAdapter::pending_media_count() );
		$labels = array_column( $versioning->changes(), 'label', 'doc' );
		$this->assertSame( array( \XB\Versioning\Documents::MEDIA ), array_keys( $labels ), 'No document changed: the media are the one item.' );
		$this->assertStringContainsString( '1 media', (string) ( $labels[ \XB\Versioning\Documents::MEDIA ] ?? '' ) );

		// The same permission as any release.
		$builder = $this->user( 'editor' );
		$user    = get_userdata( $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		try {
			$versioning->publish( null, 'Solo media', array( \XB\Versioning\Documents::MEDIA ) );
			$this->fail( 'Released without xb_publish.' );
		} catch ( \XB\Versioning\VersioningException $e ) {
			$this->assertSame( 403, $e->status );
		}
		$this->assertSame( 1, PageProfileAdapter::pending_media_count() );

		wp_set_current_user( 1 );
		$release = $versioning->publish( null, 'Solo media', array( \XB\Versioning\Documents::MEDIA ) );
		$this->assertSame( array(), $release['docs'] );
		$this->assertSame( $release['id'], (int) get_option( 'xb_current_release' ) );
		clean_post_cache( $attachment->ID );
		$this->assertSame( 'Solo il testo alternativo', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( array(), $versioning->changes() );

		// A send that did not complete (failed, interrupted) leaves nothing to release, and a release drops it.
		[ 'archive' => $archive ] = $this->media_send( PageProfile::decode( PageProfileAdapter::export() ), null, array( 'alt' => 'Da un invio fallito' ) );
		$report = PageProfileAdapter::import( $archive, false );
		$status = get_option( 'xb_remote_operation_' . $report['operationId'] );
		$this->assertIsArray( $status );
		update_option( 'xb_remote_operation_' . $report['operationId'], array_merge( $status, array( 'state' => 'partial', 'failure' => 'operation-failed' ) ), false );
		$this->assertSame( 0, PageProfileAdapter::pending_media_count() );
		$this->assertSame( array(), $versioning->changes() );
		try {
			$versioning->publish( null, 'Niente', array( \XB\Versioning\Documents::MEDIA ) );
			$this->fail( 'Released media of a failed send.' );
		} catch ( \XB\Versioning\VersioningException $e ) {
			$this->assertSame( 400, $e->status );
		}
		PageProfileAdapter::apply_pending_media( 1 );
		clean_post_cache( $attachment->ID );
		$this->assertSame( 'Solo il testo alternativo', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
	}

	public function test_a_publish_send_puts_new_media_online_at_once(): void {
		[ 'profile' => $before, 'attachment' => $attachment, 'page' => $page ] = $this->publish_media_fixture();
		$live_url = (string) wp_get_attachment_url( $attachment->ID );
		$bytes    = (string) base64_decode( self::OTHER_PNG, true );

		[ 'archive' => $archive, 'entities' => $entities ] = $this->media_send( $before, $bytes, array( 'alt' => 'Logo pubblicato' ) );
		$report = PageProfileAdapter::import( $archive, true );
		$this->assertTrue( $report['published'] );
		$this->assertNotContains( self::FIXTURE_ASSET, array_column( $report['pending'], 'id' ) );
		$this->assert_heads_confirmed( $entities, $report );
		clean_post_cache( $attachment->ID );
		$new_url = (string) wp_get_attachment_url( $attachment->ID );
		$this->media_files[] = (string) get_attached_file( $attachment->ID );
		$this->assertNotSame( $live_url, $new_url );
		$this->assertSame( $bytes, file_get_contents( (string) get_attached_file( $attachment->ID ) ) );
		$this->assertSame( 'Logo pubblicato', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
		// The page that shows it, sent unchanged and with nothing else waiting, goes online with it.
		$this->assertStringContainsString( $new_url, $this->live_content( $page->ID ) );
		$this->assertSame( $this->profile_entity_by_id( $entities, self::FIXTURE_ASSET )['revisionHash'], $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['revisionHash'] );
	}

	/**
	 * WordPress swaps a very large upload for a `-scaled` copy and keeps the upload beside it. The upload is what
	 * was sent: the send is confirmed, and an export carries the same bytes back.
	 */
	public function test_a_large_image_that_wordpress_scales_still_travels_as_the_upload(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is missing.' );
		}
		[ 'profile' => $before, 'attachment' => $attachment ] = $this->publish_media_fixture();
		ob_start();
		imagejpeg( imagecreatetruecolor( 120, 40 ), null, 90 );
		$bytes = (string) ob_get_clean();
		$small = static fn (): int => 60;
		add_filter( 'big_image_size_threshold', $small );
		try {
			[ 'archive' => $archive, 'entities' => $entities ] = $this->media_send( $before, $bytes, array( 'mediaType' => 'image/jpeg' ) );
			$report = PageProfileAdapter::import( $archive, true );
		} finally {
			remove_filter( 'big_image_size_threshold', $small );
		}
		clean_post_cache( $attachment->ID );
		$file                = (string) get_attached_file( $attachment->ID );
		$this->media_files[] = $file;
		$this->media_files[] = (string) wp_get_original_image_path( $attachment->ID );
		$this->assertStringEndsWith( '-scaled.jpg', $file, 'WordPress made its smaller copy the attached file' );
		$this->assert_heads_confirmed( $entities, $report );
		$asset = $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET );
		$this->assertSame( hash( 'sha256', $bytes ), $asset['payload']['sha256'] );
	}

	public function test_rolling_back_a_development_send_drops_the_waiting_media_and_its_file(): void {
		[ 'profile' => $before, 'attachment' => $attachment, 'page' => $page ] = $this->publish_media_fixture();
		$live_url = (string) wp_get_attachment_url( $attachment->ID );
		[ 'archive' => $archive ] = $this->media_send( $before, (string) base64_decode( self::OTHER_PNG, true ), array( 'alt' => 'Logo nuovo' ) );
		PageProfileAdapter::import( $archive, false );
		$waiting = $this->waiting_file( $attachment->ID );
		$this->assertFileExists( $waiting['path'] );

		// Histeon Publish's rollback: the profile from before the send, based on the heads WordPress shows now.
		$current  = PageProfile::decode( PageProfileAdapter::export() );
		$manifest = $before['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = array();
		foreach ( $before['entities'] as $entity ) {
			$entity['baseHash'] = $this->profile_entity_by_id( $current['entities'], $entity['id'] )['revisionHash'];
			$entities[]         = $entity;
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $before['references'], $before['files'] ), false );
		$this->assertNotContains( self::FIXTURE_ASSET, array_column( $report['pending'], 'id' ) );
		$this->assert_heads_confirmed( $before['entities'], $report );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_media', true ) );
		$this->assertSame( '', get_post_meta( $attachment->ID, '_xb_page_profile_pending_file', true ) );
		$this->assertFileDoesNotExist( $waiting['path'], 'No document shows the waiting file any more.' );
		$this->assertSame( $live_url, wp_get_attachment_url( $attachment->ID ) );
		$this->assertStringContainsString( $live_url, $this->dev_content( $page->ID ) );
		$this->assertStringNotContainsString( $waiting['url'], $this->dev_content( $page->ID ) );
		$this->assertSame( $this->profile_entity_by_id( $before['entities'], self::FIXTURE_ASSET )['revisionHash'], $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::FIXTURE_ASSET )['revisionHash'] );
	}

	// ------------------------------------------------------------------ histos-transfer-markup-v1 (W2)

	private const MARKUP_PAGE      = '550e8400-e29b-41d4-a716-446655440061';
	private const MARKUP_COMPONENT = '550e8400-e29b-41d4-a716-446655440063';
	private const MARKUP_LOGO      = '550e8400-e29b-41d4-a716-446655440064';
	private const MARKUP_LOGO_2X   = '550e8400-e29b-41d4-a716-446655440065';

	/**
	 * The markup fixture written by Histeon Publish (SVG component, forms with a textarea text, picture and srcset,
	 * details), as a new operation.
	 *
	 * @return array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>}
	 */
	private function markup_fixture(): array {
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-markup-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$files[ $file['path'] ] = str_starts_with( $file['path'], 'media/' ) ? (string) base64_decode( (string) $fixture['files'][ $file['path'] ], true ) : '';
		}
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		return array( 'manifest' => $manifest, 'entities' => $fixture['profile']['entities'], 'references' => $this->require_profile_rows( $fixture['profile']['references'] ), 'files' => $files );
	}

	/**
	 * Sends the markup fixture and tracks what it creates.
	 *
	 * @param array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>} $profile
	 * @return array{report: array<string, mixed>, page: int, component: int, logo: int, logo2x: int}
	 */
	private function send_markup( array $profile, bool $publish = false ): array {
		$report = PageProfileAdapter::import( $this->build_profile_archive( $profile['manifest'], $profile['entities'], $profile['references'], $profile['files'] ), $publish );
		$this->assertTrue( $report['complete'] );
		$ids = array();
		foreach ( array( 'page' => self::MARKUP_PAGE, 'component' => self::MARKUP_COMPONENT, 'logo' => self::MARKUP_LOGO, 'logo2x' => self::MARKUP_LOGO_2X ) as $key => $profile_id ) {
			$posts = get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => '_xb_page_profile_id', 'meta_value' => $profile_id ) );
			$this->assertCount( 1, $posts, $key );
			$this->track( $posts[0]->ID );
			if ( 'attachment' === $posts[0]->post_type ) {
				$this->media_files[] = (string) get_attached_file( $posts[0]->ID );
			}
			$ids[ $key ] = $posts[0]->ID;
		}
		return array( 'report' => $report ) + $ids;
	}

	/**
	 * @param list<array<string, mixed>> $references
	 * @return list<string>
	 */
	private function owner_references( array $references, string $owner_id ): array {
		$rows = array_map( PageProfile::canonical(...), array_values( array_filter( $references, static fn ( array $reference ): bool => $owner_id === $reference['ownerId'] ) ) );
		sort( $rows );
		return $rows;
	}

	public function test_capabilities_declare_transfer_markup_v1(): void {
		$link         = array( 'secret' => 'x', 'user' => 1, 'publish' => false, 'read' => true, 'write' => true, 'label' => 'test', 'created' => time(), 'last' => time() );
		$capabilities = PageProfileAdapter::capabilities( $link )['capabilities'];
		$this->assertSame( array( 'histos-transfer-markup-v1', 'histos-media-links-v1', 'histos-url-path-v1', 'histos-loop-group-v1', 'histos-svg-text-v1' ), $capabilities['markup'] );
		$this->assertSame( \XB\Remote\TransferMarkup::ID, $capabilities['markup'][0] );
	}

	/** SVG, forms with a textarea text, picture/srcset with attachments and details come back exactly as sent. */
	public function test_transfer_markup_round_trips_svg_picture_srcset_details_and_forms(): void {
		$profile = $this->markup_fixture();
		$sent    = $this->send_markup( $profile );

		// In WordPress each srcset candidate is the attachment's own file, with its descriptor.
		$logo    = (string) wp_get_attachment_url( $sent['logo'] );
		$logo_2x = (string) wp_get_attachment_url( $sent['logo2x'] );
		$this->assertNotSame( $logo, $logo_2x );
		$blocks  = parse_blocks( $this->dev_content( $sent['page'] ) );
		$picture = $blocks[2];
		$this->assertSame( 'picture', $picture['attrs']['tag'] );
		$this->assertSame( $logo . ' 1x, ' . $logo_2x . ' 2x', $picture['innerBlocks'][0]['attrs']['attributes']['srcset'] );
		$this->assertSame( $logo . ' 1x, ' . $logo_2x . ' 2x', $picture['innerBlocks'][1]['attrs']['attributes']['srcset'] );
		$this->assertSame( $logo, $picture['innerBlocks'][1]['attrs']['attributes']['src'] );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		foreach ( array( self::MARKUP_PAGE, self::MARKUP_COMPONENT ) as $id ) {
			$before = $this->profile_entity_by_id( $profile['entities'], $id );
			$after  = $this->profile_entity_by_id( $exported['entities'], $id );
			$this->assertSame( $before['payload']['content'], $after['payload']['content'], $id );
			$this->assertSame( $this->owner_references( $profile['references'], $id ), $this->owner_references( $exported['references'], $id ), $id );
		}
		// Ordered as Histeon Publish orders them, the index included.
		$keys = array_map( static fn ( array $reference ): string => implode( "\0", array( $reference['ownerId'], $reference['blockUid'], $reference['path'], $reference['targetId'], (string) ( $reference['index'] ?? '' ) ) ), $exported['references'] );
		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $keys );
		$this->assertContains( self::MARKUP_LOGO_2X, array_column( $exported['entities'], 'id' ) );
	}

	/**
	 * Doc 23 item 121: a link to a media file arrives as the attachment's file, leaves again as its media identity, and
	 * a link to anything else (an intermediate size, a page) stays a plain link. A reference to another media fails.
	 */
	public function test_a_link_to_a_media_file_round_trips_as_its_identity(): void {
		$profile = $this->markup_fixture();
		$link    = '<!-- wp:xb/element {"uid":"medialink01","tag":"a","attributes":{"class":"galleria__tessera","href":"histos-media://' . self::MARKUP_LOGO . '"}} --><!-- wp:xb/text {"uid":"medialink02","content":"Apri il file"} /--><!-- /wp:xb/element -->';
		try {
			$tampered = $this->with_media_link( $profile, $link, self::MARKUP_LOGO_2X );
			PageProfileAdapter::import( $this->build_profile_archive( $tampered['manifest'], $tampered['entities'], $tampered['references'], $tampered['files'] ), false );
			$this->fail( 'A link reference naming another media was imported.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'Media reference does not match', $error->getMessage() );
		}

		$sent   = $this->send_markup( $this->with_media_link( $profile, $link, self::MARKUP_LOGO ) );
		$logo   = (string) wp_get_attachment_url( $sent['logo'] );
		$blocks = parse_blocks( $this->dev_content( $sent['page'] ) );
		$anchor = $blocks[ count( $blocks ) - 1 ];
		$this->assertSame( 'a', $anchor['attrs']['tag'] );
		$this->assertSame( $logo, $anchor['attrs']['attributes']['href'] );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$page     = $this->profile_entity_by_id( $exported['entities'], self::MARKUP_PAGE );
		$this->assertStringEndsWith( $link, $page['payload']['content'] );
		$links = array_values( array_filter( $exported['references'], static fn ( array $reference ): bool => 'medialink01' === $reference['blockUid'] ) );
		$this->assertCount( 1, $links );
		$this->assertSame( array( 'attrs.attributes.href', self::MARKUP_LOGO, 'media' ), array( $links[0]['path'], $links[0]['targetId'], $links[0]['role'] ) );

		$base = (string) substr( $this->dev_content( $sent['page'] ), 0, - strlen( serialize_block( $anchor ) ) );
		foreach ( array( (string) preg_replace( '/(\.png)$/', '-150x150$1', $logo ), home_url( '/contatti/' ) ) as $plain ) {
			$anchor['attrs']['attributes']['href'] = $plain;
			$this->assertSame( 200, $this->save_dev( $sent['page'], $base . serialize_block( $anchor ) )->get_status() );
			$exported = PageProfile::decode( PageProfileAdapter::export() );
			$page     = $this->profile_entity_by_id( $exported['entities'], self::MARKUP_PAGE );
			$this->assertStringContainsString( (string) wp_json_encode( $plain, JSON_UNESCAPED_SLASHES ), $page['payload']['content'], $plain );
			$this->assertSame( array(), array_filter( $exported['references'], static fn ( array $reference ): bool => 'attrs.attributes.href' === $reference['path'] ), $plain );
		}
	}

	/**
	 * The markup fixture with a link block appended to its page and the link's media reference.
	 *
	 * @param array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>} $profile
	 * @return array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>}
	 */
	private function with_media_link( array $profile, string $link, string $target ): array {
		foreach ( $profile['entities'] as $index => $entity ) {
			if ( self::MARKUP_PAGE === $entity['id'] ) {
				$entity['payload']['content'] .= $link;
				$entity['revisionHash']         = PageProfile::revision_hash( $entity );
				$profile['entities'][ $index ]  = $entity;
			}
		}
		$profile['references'][] = array( 'ownerId' => self::MARKUP_PAGE, 'blockUid' => 'medialink01', 'path' => 'attrs.attributes.href', 'targetId' => $target, 'role' => 'media' );
		return $profile;
	}

	/** The builder's SVG namespace declarations leave on export; any other one, and sprite references, are refused. */
	public function test_export_strips_exactly_the_svg_namespaces_and_explains_use_and_href(): void {
		$profile = $this->markup_fixture();
		$sent    = $this->send_markup( $profile );
		$base    = $this->dev_content( $sent['page'] );
		$svg     = static fn ( array $attributes, string $inner = '' ): string => '<!-- wp:xb/element ' . PageProfile::canonical( array( 'uid' => 'svgns0001', 'tag' => 'svg', 'attributes' => $attributes ) ) . ' -->' . ( '' === $inner ? '<!-- wp:xb/element {"uid":"svgns0002","tag":"path","attributes":{"d":"M0 0h24v24H0z"}} /-->' : $inner ) . '<!-- /wp:xb/element -->';
		$portable = $svg( array( 'viewBox' => '0 0 24 24' ) );

		$this->assertSame( 200, $this->save_dev( $sent['page'], $base . $svg( array( 'xmlns' => 'http://www.w3.org/2000/svg', 'xmlns:xlink' => 'http://www.w3.org/1999/xlink', 'viewBox' => '0 0 24 24' ) ) )->get_status() );
		$page = $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::MARKUP_PAGE );
		$this->assertSame( $this->profile_entity_by_id( $profile['entities'], self::MARKUP_PAGE )['payload']['content'] . $portable, $page['payload']['content'] );
		$this->assertStringContainsString( 'xmlns', $this->dev_content( $sent['page'] ), 'Only the profile loses them, not the WordPress document.' );

		foreach (
			array(
				'xhtml namespace'        => array( $svg( array( 'xmlns' => 'http://www.w3.org/1999/xhtml', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'namespace with a space' => array( $svg( array( 'xmlns' => 'http://www.w3.org/2000/svg ', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'xlink on a path'        => array( $svg( array( 'viewBox' => '0 0 24 24' ), '<!-- wp:xb/element {"uid":"svgns0002","tag":"path","attributes":{"xmlns:xlink":"http://www.w3.org/1999/xlink","d":"M0 0"}} /-->' ), 'attribute' ),
				'namespace on a div'     => array( '<!-- wp:xb/element {"uid":"svgns0003","tag":"div","attributes":{"xmlns":"http://www.w3.org/2000/svg"}} /-->', 'attribute' ),
				'namespace on a g'       => array( $svg( array( 'viewBox' => '0 0 24 24' ), '<!-- wp:xb/element {"uid":"svgns0002","tag":"g","attributes":{"xmlns":"http://www.w3.org/2000/svg"}} /-->' ), 'attribute' ),
				'upper-case name'        => array( $svg( array( 'XMLNS' => 'http://www.w3.org/2000/svg', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'upper-case namespace'   => array( $svg( array( 'xmlns' => 'HTTP://WWW.W3.ORG/2000/SVG', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'xlink for svg'          => array( $svg( array( 'xmlns:xlink' => 'http://www.w3.org/2000/svg', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'another prefix'         => array( $svg( array( 'xmlns:svg' => 'http://www.w3.org/2000/svg', 'viewBox' => '0 0 24 24' ) ), 'attribute' ),
				'use'                    => array( $svg( array( 'viewBox' => '0 0 24 24' ), '<!-- wp:xb/element {"uid":"svgns0002","tag":"use","attributes":{"href":"#icon"}} /-->' ), 'tag' ),
				'href'                   => array( $svg( array( 'viewBox' => '0 0 24 24' ), '<!-- wp:xb/element {"uid":"svgns0002","tag":"path","attributes":{"href":"#icon","d":"M0 0"}} /-->' ), 'attribute' ),
			)
			as $case => [ $content, $reason ]
		) {
			$this->assertSame( 200, $this->save_dev( $sent['page'], $base . $content )->get_status(), $case );
			try {
				PageProfileAdapter::export();
				$this->fail( 'Exported: ' . $case );
			} catch ( \XB\Remote\TransferMarkupViolation $violation ) {
				$this->assertSame( $reason, $violation->reason, $case . ': ' . $violation->getMessage() );
				if ( in_array( $case, array( 'use', 'href' ), true ) ) {
					$this->assertStringContainsString( 'shapes drawn in place', $violation->getMessage(), $case );
				}
			}
		}
	}

	/** A srcset leaves WordPress only as the attachments' own files: sizes, other uploads and external URLs stop it. */
	public function test_export_refuses_srcset_candidates_that_are_not_original_attachment_files(): void {
		$profile = $this->markup_fixture();
		$sent    = $this->send_markup( $profile );
		$base    = $this->dev_content( $sent['page'] );
		$title   = get_the_title( $sent['page'] );
		$logo    = (string) wp_get_attachment_url( $sent['logo'] );
		$size    = (string) preg_replace( '/(\.png)$/', '-150x150$1', $logo );
		$uploads = trailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$img     = static fn ( string $srcset ): string => '<!-- wp:xb/element ' . PageProfile::canonical( array( 'uid' => 'srcset001', 'tag' => 'img', 'attributes' => array( 'alt' => '', 'srcset' => $srcset ) ) ) . ' /-->';
		foreach (
			array(
				'intermediate size' => array( $img( $logo . ' 1x, ' . $size . ' 2x' ), 'intermediate size' ),
				'external URL'      => array( $img( $logo . ' 1x, https://cdn.example.com/logo.png 2x' ), 'external' ),
				'scheme-relative'   => array( $img( '//cdn.example.com/logo.png 2x' ), 'external' ),
				'not an attachment' => array( $img( $uploads . 'histos-not-an-attachment.png 2x' ), 'not a WordPress attachment' ),
				'uploads prefix'    => array( $img( rtrim( $uploads, '/' ) . '.example.com/logo.png 1x' ), 'external' ),
				'dynamic'           => array( $img( '{this.image.url} 1x' ), 'static value' ),
				'seventeen'         => array( $img( implode( ', ', array_fill( 0, 17, $logo ) ) ), 'value of srcset' ),
			)
			as $case => [ $content, $message ]
		) {
			$this->assertSame( 200, $this->save_dev( $sent['page'], $base . $content )->get_status(), $case );
			try {
				PageProfileAdapter::export();
				$this->fail( 'Exported: ' . $case );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( $message, $error->getMessage(), $case );
				$this->assertStringContainsString( $title, $error->getMessage(), $case );
			}
		}
		// The same image twice, at two densities: two references, told apart by their index.
		$this->assertSame( 200, $this->save_dev( $sent['page'], $base . $img( $logo . ' 1x, ' . $logo . ' 2x' ) )->get_status() );
		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$rows     = array_values( array_filter( $exported['references'], static fn ( array $reference ): bool => 'srcset001' === $reference['blockUid'] ) );
		$this->assertSame( array( 0, 1 ), array_column( $rows, 'index' ) );
		$this->assertSame( array( self::MARKUP_LOGO, self::MARKUP_LOGO ), array_column( $rows, 'targetId' ) );
	}

	/** New bytes sent without publishing: the srcset in development follows the waiting file, and still exports. */
	public function test_a_development_media_send_moves_srcset_candidates_and_exports_them(): void {
		$profile = $this->markup_fixture();
		$sent    = $this->send_markup( $profile, true );
		$live    = (string) wp_get_attachment_url( $sent['logo'] );
		$before  = PageProfile::decode( PageProfileAdapter::export() );

		$bytes    = (string) base64_decode( self::OTHER_PNG, true );
		$manifest = $before['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$files    = $before['files'];
		$entities = array();
		foreach ( $before['entities'] as $entity ) {
			if ( self::MARKUP_LOGO === $entity['id'] ) {
				$path                        = 'media/' . hash( 'sha256', $bytes );
				$entity['payload']['sha256'] = hash( 'sha256', $bytes );
				$entity['payload']['bytes']  = strlen( $bytes );
				$entity['payload']['path']   = $path;
				$files[ $path ]              = $bytes;
				$manifest['files'][]         = array( 'path' => $path, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'mediaType' => 'application/octet-stream' );
				$entity['revisionHash']      = PageProfile::revision_hash( $entity );
			}
			$entities[] = $entity;
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $before['references'], $files ), false );
		$waiting = $this->waiting_file( $sent['logo'] );

		$picture = parse_blocks( $this->dev_content( $sent['page'] ) )[2];
		$this->assertSame( $waiting['url'] . ' 1x, ' . (string) wp_get_attachment_url( $sent['logo2x'] ) . ' 2x', $picture['innerBlocks'][0]['attrs']['attributes']['srcset'] );
		$this->assertStringContainsString( $live, $this->live_content( $sent['page'] ), 'Online the page keeps the live file until the release.' );
		$after = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertSame( $this->owner_references( $before['references'], self::MARKUP_PAGE ), $this->owner_references( $after['references'], self::MARKUP_PAGE ) );
		$this->assertSame( hash( 'sha256', $bytes ), $this->profile_entity_by_id( $after['entities'], self::MARKUP_LOGO )['payload']['sha256'] );
	}

	/** A form needs unfiltered_html to be saved: without it the send stops before anything is written. */
	public function test_a_form_from_a_link_user_without_unfiltered_html_is_refused_before_writes(): void {
		$profile = $this->markup_fixture();
		$operation_id = $profile['manifest']['selection']['snapshot'];
		$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$before_project = get_option( 'xb_page_profile_project_id', false );
		$deny = static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			try {
				PageProfileAdapter::import( $this->build_profile_archive( $profile['manifest'], $profile['entities'], $profile['references'], $profile['files'] ), false );
				$this->fail( 'A form reached a user who cannot save it.' );
			} catch ( \DomainException $error ) {
				$this->assertStringContainsString( 'cannot save forms', $error->getMessage() );
				$this->assertStringContainsString( self::MARKUP_PAGE, $error->getMessage() );
			}
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$this->assertFalse( get_option( 'xb_remote_operation_' . $operation_id, false ), 'No operation record.' );
		$this->assertSame( $before_project, get_option( 'xb_page_profile_project_id', false ), 'The site is not paired.' );
		$this->assertSame( $before_posts, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ), 'Nothing is created.' );
	}

	/** Note 89 of the 3 November audit: a page title that wp_kses_post() would change stops the send before writes. */
	public function test_a_title_with_markup_is_refused_before_writes(): void {
		$profile = $this->markup_fixture();
		foreach ( $profile['entities'] as &$entity ) {
			if ( self::MARKUP_PAGE === $entity['id'] ) {
				$entity['payload']['title'] = 'Pagina<script>alert(1)</script>';
				$entity['revisionHash']     = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$before = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		try {
			PageProfileAdapter::import( $this->build_profile_archive( $profile['manifest'], $profile['entities'], $profile['references'], $profile['files'] ), false );
			$this->fail( 'A title with a script reached WordPress.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( self::MARKUP_PAGE, $error->getMessage() );
		}
		$this->assertSame( $before, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ), 'Nothing is created.' );
		$this->assertFalse( PageProfileAdapter::markup_filtered( 'Rossi & figli' ) );
		$this->assertFalse( PageProfileAdapter::markup_filtered( 'Rossi &amp; figli' ) );
		$this->assertTrue( PageProfileAdapter::markup_filtered( '<img src=x onerror=alert(1)>' ) );
	}

	/** Note 93 of the 3 November audit: an xb/* block keeps no HTML of its own through the import. */
	public function test_restored_content_keeps_no_hidden_html_in_histeon_blocks(): void {
		$restore = new \ReflectionMethod( PageProfileAdapter::class, 'restore_media' );
		$content = '<!-- wp:xb/element {"uid":"hidden01","tag":"div"} -->nascosto<!-- wp:xb/text {"uid":"hidden02","content":"visibile"} /--><!-- /wp:xb/element -->';
		$out     = (string) $restore->invoke( null, $content, array() );
		$this->assertStringContainsString( 'visibile', $out );
		$this->assertStringNotContainsString( 'nascosto', $out );
	}

	/** Note 86 of the 3 November audit: a media title with an inner extension does not keep it in the file name. */
	public function test_a_media_title_keeps_no_inner_extension(): void {
		$upload = new \ReflectionMethod( PageProfileAdapter::class, 'upload_media' );
		$bytes  = (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true );
		$this->set_option( 'xb_page_profile_written_media', get_option( 'xb_page_profile_written_media', false ) );
		$file   = (string) $upload->invoke( null, array( 'mediaType' => 'image/gif', 'sha256' => hash( 'sha256', $bytes . 'n86' ), 'title' => 'foto.php' ), $bytes );
		try {
			$this->assertStringEndsWith( '.gif', $file );
			$this->assertStringNotContainsString( '.php.', basename( $file ) );
		} finally {
			wp_delete_file( $file );
		}
	}

	/** A srcset that smuggles an external URL next to project media stops the send before anything is written. */
	public function test_a_srcset_with_an_external_candidate_is_refused_before_writes(): void {
		foreach ( array( 'https://tracker.example.com/p.gif 2x', '//tracker.example.com/p.gif 2x', '/__cms-media/x 2x', 'histos-media://550e8400-e29b-41d4-a716-446655440099 2x' ) as $smuggled ) {
			$profile = $this->markup_fixture();
			foreach ( $profile['entities'] as &$entity ) {
				if ( self::MARKUP_PAGE === $entity['id'] ) {
					$entity['payload']['content'] = str_replace( '"srcset":"histos-media://550e8400-e29b-41d4-a716-446655440064 1x, histos-media://550e8400-e29b-41d4-a716-446655440065 2x"}} /--><!-- wp:xb/element {"uid":"markup000012"', '"srcset":"histos-media://550e8400-e29b-41d4-a716-446655440064 1x, ' . $smuggled . '"}} /--><!-- wp:xb/element {"uid":"markup000012"', $entity['payload']['content'] );
					$entity['revisionHash']       = PageProfile::revision_hash( $entity );
				}
			}
			unset( $entity );
			$references = array_values( array_filter( $profile['references'], static fn ( array $reference ): bool => ! ( 'markup000011' === $reference['blockUid'] && 1 === ( $reference['index'] ?? null ) ) ) );
			if ( str_starts_with( $smuggled, 'histos-media://' ) ) {
				$references[] = array( 'ownerId' => self::MARKUP_PAGE, 'blockUid' => 'markup000011', 'path' => 'attrs.attributes.srcset', 'targetId' => '550e8400-e29b-41d4-a716-446655440099', 'role' => 'media', 'index' => 1 );
			}
			$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
			try {
				PageProfileAdapter::import( $this->build_profile_archive( $profile['manifest'], $profile['entities'], $references, $profile['files'] ), false );
				$this->fail( 'Smuggled: ' . $smuggled );
			} catch ( \XB\Remote\TransferMarkupViolation $violation ) {
				$this->assertSame( 'media', $violation->reason, $smuggled );
			} catch ( \InvalidArgumentException $error ) {
				// A reference to an asset the profile lacks is refused when the archive is read.
				$this->assertStringStartsWith( 'histos-media://', $smuggled, $error->getMessage() );
			}
			$this->assertSame( $before_posts, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ), $smuggled );
		}
	}

	/** An uploads URL a srcset cannot list (a space or a comma) stops the send in the preflight, not halfway (review of A6). */
	public function test_a_srcset_whose_upload_urls_cannot_be_listed_is_refused_before_writes(): void {
		$profile = $this->markup_fixture();
		$before  = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$spaced  = static function ( array $dir ): array {
			$dir['url']     = $dir['url'] . '/media del sito';
			$dir['baseurl'] = $dir['baseurl'] . '/media,sito';
			return $dir;
		};
		add_filter( 'upload_dir', $spaced );
		try {
			PageProfileAdapter::import( $this->build_profile_archive( $profile['manifest'], $profile['entities'], $profile['references'], $profile['files'] ), false );
			$this->fail( 'A srcset was about to be written with URLs it cannot list.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'srcset cannot list', $error->getMessage() );
		} finally {
			remove_filter( 'upload_dir', $spaced );
		}
		$this->assertFalse( get_option( 'xb_remote_operation_' . $profile['manifest']['selection']['snapshot'], false ), 'No operation record.' );
		$this->assertSame( $before, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block', 'attachment' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ) );
	}

	/** The same holds for a form that only a component definition holds, nested in other elements. */
	public function test_a_form_inside_a_component_from_a_link_user_without_unfiltered_html_is_refused_before_writes(): void {
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-project-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities = $fixture['profile']['entities'];
		foreach ( $entities as &$entity ) {
			if ( 'component' === $entity['kind'] ) {
				$entity['payload']['content'] = '<!-- wp:xb/element {"uid":"cform00001","tag":"div"} --><!-- wp:xb/element {"uid":"cform00002","tag":"form","attributes":{"action":"/cerca/","method":"get"}} --><!-- wp:xb/element {"uid":"cform00003","tag":"input","attributes":{"name":"q","type":"search"}} /--><!-- /wp:xb/element --><!-- /wp:xb/element -->';
				$entity['revisionHash']       = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$files = array();
		foreach ( $manifest['files'] as $file ) {
			$files[ $file['path'] ] = str_starts_with( $file['path'], 'media/' ) ? (string) base64_decode( (string) $fixture['files'][ $file['path'] ], true ) : '';
		}
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$before_posts = array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		$deny = static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $this->require_profile_rows( $fixture['profile']['references'] ), $files ), false );
			$this->fail( 'A form in a component reached a user who cannot save it.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'cannot save forms', $error->getMessage() );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$this->assertFalse( get_option( 'xb_remote_operation_' . $manifest['selection']['snapshot'], false ) );
		$this->assertSame( $before_posts, array_map( 'intval', get_posts( array( 'post_type' => array( 'page', 'wp_block' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ) );

		// With unfiltered_html the same send goes through.
		$this->isolate_operation( $manifest['selection']['snapshot'], $manifest['project']['id'] );
		$report = PageProfileAdapter::import( $this->build_profile_archive( $manifest, $entities, $this->require_profile_rows( $fixture['profile']['references'] ), $files ), false );
		$this->assertTrue( $report['complete'] );
		foreach ( array_merge( $report['pages'], $report['components'] ) as $written ) {
			$this->track( (int) $written['postId'] );
		}
	}

	/** `src={props.immagine}` passes the checks and the writes, and comes back as the same expression. */
	public function test_a_component_with_a_dynamic_image_prop_imports_and_round_trips(): void {
		$profile = $this->markup_fixture();
		$component_content = '<!-- wp:xb/element {"uid":"cardimg001","tag":"img","attributes":{"src":"{props.immagine}","alt":""}} /-->';
		foreach ( $profile['entities'] as &$entity ) {
			if ( self::MARKUP_COMPONENT === $entity['id'] ) {
				$entity['payload']['schema']  = array( 'props' => array( 'label' => array( 'type' => 'string', 'default' => 'Icona', 'label' => 'Etichetta' ), 'immagine' => array( 'type' => 'image', 'default' => '', 'label' => 'Immagine' ) ) );
				$entity['payload']['content'] = $component_content;
				$entity['revisionHash']       = PageProfile::revision_hash( $entity );
			}
		}
		unset( $entity );
		$sent = $this->send_markup( $profile, true );
		$this->assertStringContainsString( '{props.immagine}', $this->dev_content( $sent['component'] ) );
		$exported = $this->profile_entity_by_id( PageProfile::decode( PageProfileAdapter::export() )['entities'], self::MARKUP_COMPONENT );
		$this->assertSame( $component_content, $exported['payload']['content'] );

		// The imported component is managed by the profile: an instance value outside the site's uploads prints no src.
		$this->assertTrue( PageProfileAdapter::is_transfer_imported( $sent['component'] ) );
		\XB\Plugin::instance()->components->forget();
		\XB\Plugin::instance()->renderer->reset_budget();
		$uploads = untrailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$render  = fn ( string $value ): string => $this->render( array( $this->block( 'xb/component', array( 'ref' => 'ui/icona', 'props' => array( 'immagine' => $value ) ) ) ) );
		$this->assertStringContainsString( '<img', $render( 'https://tracker.example.com/p.gif' ) );
		$this->assertStringNotContainsString( ' src=', $render( 'https://tracker.example.com/p.gif' ) );
		$this->assertStringContainsString( ' src="' . $uploads . '/2026/10/logo.png"', $render( $uploads . '/2026/10/logo.png' ) );

		// Any other dynamic source is still refused before writes, and on export.
		$this->assertSame( 200, $this->save_dev( $sent['page'], $this->dev_content( $sent['page'] ) . '<!-- wp:xb/element {"uid":"dynimg001","tag":"img","attributes":{"src":"{page.title}","alt":""}} /-->' )->get_status() );
		try {
			PageProfileAdapter::export();
			$this->fail( 'A dynamic source other than a component prop was exported.' );
		} catch ( \DomainException $error ) {
			$this->assertStringContainsString( 'whole component prop', $error->getMessage() );
		}
	}

	/** A Histeon page of the site, not sent by any project. */
	private function site_page( string $title, string $text = 'Del sito' ): int {
		return $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( $text ) ) ) ) ) ) );
	}

	/** A check before a send leaves the site as it was, and shows the identities the download after it stores. */
	public function test_a_check_writes_nothing_and_the_download_after_it_keeps_its_identities(): void {
		$this->markup_fixture();
		$this->set_option( 'xb_page_profile_project_id', '' );
		$page = $this->site_page( 'Pagina del sito' );

		$check = PageProfile::decode( PageProfileAdapter::export( 'read' ) );
		$this->assertSame( '', get_post_meta( $page, '_xb_page_profile_id', true ) );
		$this->assertSame( '', get_post_meta( $page, '_xb_page_profile_base_hash', true ) );
		$this->assertSame( '', get_option( 'xb_page_profile_project_id' ) );

		$download = PageProfile::decode( PageProfileAdapter::export() );
		$this->assertSame( array_column( $check['entities'], 'id' ), array_column( $download['entities'], 'id' ) );
		$this->assertSame( $check['manifest']['project']['id'], $download['manifest']['project']['id'] );
		$this->assertContains( get_post_meta( $page, '_xb_page_profile_id', true ), array_column( $check['entities'], 'id' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) get_post_meta( $page, '_xb_page_profile_base_hash', true ) );
	}

	/** A send reads only what the project owns, and stamps nothing: the site's pages stay the site's. */
	public function test_a_send_reads_what_the_project_owns_and_nothing_of_the_site(): void {
		$profile = $this->markup_fixture();
		$sent    = $this->send_markup( $profile );
		$page    = $this->site_page( 'Pagina del sito' );

		$linked = PageProfile::decode( PageProfileAdapter::export( 'linked' ) );
		$titles = array_map( static fn ( array $entity ): mixed => $entity['payload']['title'] ?? null, $linked['entities'] );
		$this->assertNotContains( 'Pagina del sito', $titles );
		$this->assertContains( get_the_title( $sent['page'] ), $titles );
		$this->assertSame( '', get_post_meta( $page, '_xb_page_profile_id', true ) );
	}

	/**
	 * Item 190 of Publish's doc 23: an image of the site's own uploads written with the other scheme (http:// on a
	 * site moved to https) is the same attachment, and travels as its identity; another host stays out.
	 */
	public function test_an_upload_of_the_site_under_the_other_scheme_travels_as_its_media(): void {
		[ 'attachment' => $attachment ] = $this->publish_media_fixture();
		$url    = (string) wp_get_attachment_url( $attachment->ID );
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$other  = set_url_scheme( $url, 'https' === $scheme ? 'http' : 'https' );
		$this->assertNotSame( $url, $other );
		$page    = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Logo con lo schema vecchio', 'post_content' => $this->markup( array( $this->el( 'img', array( 'alt' => 'Logo', 'src' => $other ) ) ) ) ) );
		$foreign = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Logo di un altro sito', 'post_content' => $this->markup( array( $this->el( 'img', array( 'alt' => 'Logo', 'src' => 'https://altro.example/wp-content/uploads/logo.png' ) ) ) ) ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$left_out = array_column( $exported['manifest']['leftOut'], 'title' );
		$this->assertNotContains( 'Logo con lo schema vecchio', $left_out );
		$this->assertContains( 'Logo di un altro sito', $left_out );
		$asset_id = (string) get_post_meta( $attachment->ID, '_xb_page_profile_id', true );
		$page_id  = (string) get_post_meta( $page, '_xb_page_profile_id', true );
		$this->assertNotSame( '', $page_id );
		$this->assertSame( '', get_post_meta( $foreign, '_xb_page_profile_id', true ) );
		$this->assertStringContainsString( 'histos-media://' . $asset_id, (string) $this->profile_entity_by_id( $exported['entities'], $page_id )['payload']['content'] );
		$this->assertContains( $asset_id, array_column( array_filter( $exported['references'], static fn ( array $reference ): bool => $reference['ownerId'] === $page_id ), 'targetId' ) );
	}

	/** One page of the site that cannot travel stays out, named, unstamped; the others are read. */
	public function test_a_page_of_the_site_that_cannot_travel_stays_out_and_is_named(): void {
		$this->markup_fixture();
		$good = $this->site_page( 'Pagina buona' );
		$bad  = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina dinamica', 'post_content' => $this->markup( array( $this->el( 'img', array( 'alt' => '', 'srcset' => '{this.image.url} 1x' ) ) ) ) ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$left_out = array_column( $exported['manifest']['leftOut'], null, 'title' );
		$this->assertSame( 'page', $left_out['Pagina dinamica']['kind'] );
		$this->assertStringContainsString( 'static value', $left_out['Pagina dinamica']['reason'] );
		$this->assertSame( '', get_post_meta( $bad, '_xb_page_profile_id', true ) );
		$this->assertNotSame( '', get_post_meta( $good, '_xb_page_profile_id', true ) );
		$this->assertContains( 'Pagina buona', array_map( static fn ( array $entity ): mixed => $entity['payload']['title'] ?? null, $exported['entities'] ) );
	}

	/**
	 * Doc 23 of Publish, item 197: a check reading names a content the project knows too, marked as blocking, where a
	 * download stops at it; and it writes nothing.
	 */
	public function test_a_check_names_a_known_content_that_cannot_travel_and_a_download_stops_there(): void {
		$this->markup_fixture();
		$this->site_page( 'Pagina buona' );
		$bad = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina nota', 'post_content' => $this->markup( array( $this->el( 'img', array( 'alt' => '', 'srcset' => '{this.image.url} 1x' ) ) ) ) ) );
		update_post_meta( $bad, '_xb_page_profile_base_hash', str_repeat( 'a', 64 ) );

		$check    = PageProfile::decode( PageProfileAdapter::export( 'check' ) );
		$left_out = array_column( $check['manifest']['leftOut'], null, 'title' );
		$this->assertSame( 'page', $left_out['Pagina nota']['kind'] );
		$this->assertTrue( $left_out['Pagina nota']['blocks'] );
		$this->assertStringContainsString( 'static value', $left_out['Pagina nota']['reason'] );
		$this->assertSame( '', get_post_meta( $bad, '_xb_page_profile_id', true ), 'a check writes no identity' );

		try {
			PageProfileAdapter::export( 'read' );
			$this->fail( 'A reading other than a check stops at a known content that cannot travel.' );
		} catch ( \DomainException $e ) {
			$this->assertStringStartsWith( 'Pagina nota: ', $e->getMessage() );
		}
		$this->assertContains( 'check', PageProfileAdapter::capabilities( array( 'secret' => '', 'user' => 1, 'publish' => false, 'read' => true, 'write' => false, 'label' => 'prova', 'created' => 0, 'last' => 0 ) )['capabilities']['readings'] );
	}

	/** Doc 23 of Publish, items 190 and 204: a template of the site without the page's content stays out, named. */
	public function test_a_template_without_the_content_block_stays_out_and_is_named(): void {
		$this->markup_fixture();
		$this->site_page( 'Pagina buona' );
		$template = $this->post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'single-senza-contenuto', 'post_title' => 'Senza contenuto', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Solo testo' ) ) ) ) ) ) );
		wp_set_object_terms( $template, get_stylesheet(), 'wp_theme' );

		$exported = PageProfile::decode( PageProfileAdapter::export( 'read' ) );
		$left_out = array_column( $exported['manifest']['leftOut'], null, 'title' );
		$this->assertSame( 'template', $left_out['Senza contenuto']['kind'] );
		$this->assertStringContainsString( 'Contenuto del post', $left_out['Senza contenuto']['reason'] );
		$this->assertArrayNotHasKey( 'blocks', $left_out['Senza contenuto'] );
	}

	/** A site with nothing that travels gets a reason, not an empty archive. */
	public function test_a_site_where_no_page_can_travel_says_why(): void {
		$this->markup_fixture();
		$pages = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'draft', 'publish' ), 'posts_per_page' => -1 ) );
		$this->assertSame( array(), array_filter( $pages, static fn ( \WP_Post $post ): bool => has_blocks( $post ) && str_contains( $post->post_content, 'wp:xb/' ) ), 'The test site must start without Histeon pages.' );
		$this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina dinamica', 'post_content' => $this->markup( array( $this->el( 'img', array( 'alt' => '', 'srcset' => '{this.image.url} 1x' ) ) ) ) ) );
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'Pagina dinamica' );
		PageProfileAdapter::export();
	}

	/** A send interrupted after writing a file finds it again; a file in use is never taken for a new one. */
	public function test_an_interrupted_send_writes_each_file_once(): void {
		$this->set_option( 'xb_page_profile_written_media', array() );
		$bytes   = (string) base64_decode( self::OTHER_PNG, true );
		$payload = array( 'mediaType' => 'image/png', 'title' => 'interrotto', 'sha256' => hash( 'sha256', $bytes ) );
		$upload  = new \ReflectionMethod( PageProfileAdapter::class, 'upload_media' );
		$used    = new \ReflectionMethod( PageProfileAdapter::class, 'media_used' );

		$first = $upload->invoke( null, $payload, $bytes );
		$this->media_files[] = (string) $first;
		$this->assertSame( $first, $upload->invoke( null, $payload, $bytes ) );

		$used->invoke( null, $payload['sha256'] );
		$second = $upload->invoke( null, $payload, $bytes );
		$this->media_files[] = (string) $second;
		$this->assertNotSame( $first, $second );
		$used->invoke( null, $payload['sha256'] );
	}

	/** A file of a send never taken up again goes after a week, unless an attachment has it; newer ones stay. */
	public function test_files_of_an_abandoned_send_are_deleted_after_a_week(): void {
		$this->set_option( 'xb_page_profile_written_media', array() );
		$bytes  = (string) base64_decode( self::OTHER_PNG, true );
		$upload = new \ReflectionMethod( PageProfileAdapter::class, 'upload_media' );
		$prune  = new \ReflectionMethod( PageProfileAdapter::class, 'prune_written_media' );
		$write  = function ( string $title ) use ( $upload, $bytes ): string {
			$file = (string) $upload->invoke( null, array( 'mediaType' => 'image/png', 'title' => $title, 'sha256' => hash( 'sha256', $bytes . $title ) ), $bytes );
			$this->media_files[] = $file;
			return $file;
		};
		// Distinct hashes for the same bytes: the entries are keyed by the hash the profile gave.
		$old    = $write( 'abbandonato' );
		$fresh  = $write( 'recente' );
		$in_use = $write( 'usato' );
		$legacy = $write( 'vecchio-formato' );
		$written = get_option( 'xb_page_profile_written_media' );
		$age     = static function ( string $title, int $days ) use ( &$written, $bytes ): void {
			$written[ hash( 'sha256', $bytes . $title ) ]['at'] = time() - $days * DAY_IN_SECONDS;
		};
		$age( 'abbandonato', 8 );
		$age( 'recente', 2 );
		$age( 'usato', 30 );
		$written[ hash( 'sha256', $bytes . 'vecchio-formato' ) ] = _wp_relative_upload_path( $legacy );
		update_option( 'xb_page_profile_written_media', $written, false );
		$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'usato', 'post_status' => 'inherit' ), $in_use );

		$prune->invoke( null );

		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh );
		$this->assertFileExists( $in_use );
		$this->assertFileExists( $legacy );
		$left = get_option( 'xb_page_profile_written_media' );
		$this->assertSame( array( hash( 'sha256', $bytes . 'recente' ), hash( 'sha256', $bytes . 'vecchio-formato' ) ), array_keys( $left ) );
		$this->assertSame( _wp_relative_upload_path( $legacy ), $left[ hash( 'sha256', $bytes . 'vecchio-formato' ) ]['file'] );
		$this->assertIsInt( $left[ hash( 'sha256', $bytes . 'vecchio-formato' ) ]['at'] );
		wp_delete_attachment( (int) $attachment, true );
	}

	/** A rollback brings back a foreign block removed since; a block the site never held is still refused. */
	public function test_a_foreign_block_held_in_an_earlier_version_may_come_back(): void {
		$block = '<!-- wp:paragraph --><p>Prima</p><!-- /wp:paragraph --><!-- wp:acf/hero {"a":1} /-->';
		$known = \XB\Content\ArticleBlocks::class;
		$earlier = static fn (): array => parse_blocks( $block );
		$incoming = \XB\Content\ArticleBlocks::from_core( parse_blocks( $block ) );
		$known::assert_foreign_known( $incoming, array(), $earlier, 'Articolo' );

		$this->expectException( \DomainException::class );
		$known::assert_foreign_known( \XB\Content\ArticleBlocks::from_core( parse_blocks( '<!-- wp:acf/altro {"b":2} /-->' ) ), array(), $earlier, 'Articolo' );
	}

	/** The name a project gives to categories reaches the administration. */
	public function test_a_named_native_taxonomy_shows_its_name_in_wordpress(): void {
		$this->set_option( 'xb_page_profile_native_taxonomy_labels', array( 'category' => 'Edizioni' ) );
		$args = \XB\Remote\ContentProfileAdapter::native_taxonomy_labels( array(), 'category' );
		$this->assertSame( 'Edizioni', $args['labels']['name'] );
		$this->assertSame( array(), \XB\Remote\ContentProfileAdapter::native_taxonomy_labels( array(), 'post_tag' ) );
	}

	/** A query of the site on a type the project lacks stays out, named, and with it what loops over it. */
	public function test_a_query_on_a_type_the_project_lacks_stays_out_with_what_uses_it(): void {
		$this->markup_fixture();
		$versioning = \XB\Plugin::instance()->versioning;
		$settings   = $versioning->working_copy( \XB\Versioning\Documents::SETTINGS );
		$settings['payload']['presets'] = array( 'tema_eventi' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'tema_evento' ) ) );
		$versioning->save_dev( \XB\Versioning\Documents::SETTINGS, $settings['payload'], $settings['head'], $settings['base'], 'test' );
		$this->site_page( 'Pagina buona' );
		$this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elenco eventi', 'post_content' => $this->markup( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'tema_eventi' ) ), array( $this->text( 'Riga' ) ) ) ) ) ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$kinds    = array_column( $exported['manifest']['leftOut'], 'kind', 'title' );
		$this->assertSame( 'query', $kinds['tema_eventi'] );
		$this->assertSame( 'page', $kinds['Elenco eventi'] );
		$this->assertSame( array(), array_values( array_filter( $exported['entities'], static fn ( array $entity ): bool => 'query-preset' === $entity['kind'] && 'tema_eventi' === ( $entity['payload']['key'] ?? null ) ) ) );
	}

	/** Going back to a version from before any framework choice undoes the choice. */
	public function test_a_version_without_a_framework_clears_the_choice(): void {
		$this->set_option( \XB\Framework\Frameworks::OPTION, 'xb-tokens' );
		\XB\Versioning\Documents::apply( \XB\Versioning\Documents::SETTINGS, array( 'framework' => null ), true );
		$this->assertFalse( get_option( \XB\Framework\Frameworks::OPTION ) );
	}

	/** Defaults stored sorted, as a settings document stores them, are still defaults. */
	public function test_default_queries_in_another_order_do_not_stop_a_first_send(): void {
		$this->markup_fixture();
		$sorted = \XB\Styles\NamedQueries::DEFAULTS;
		ksort( $sorted );
		$this->set_option( \XB\Styles\NamedQueries::OPTION, $sorted );
		$start = PageProfileAdapter::can_initialize_project();
		$this->set_option( \XB\Styles\NamedQueries::OPTION, \XB\Styles\NamedQueries::DEFAULTS );
		$this->assertSame( PageProfileAdapter::can_initialize_project(), $start );
	}

	/** What uses a component left out cannot travel either, whatever the order they are read in. */
	public function test_a_page_using_a_component_that_cannot_travel_stays_out_with_it(): void {
		$this->markup_fixture();
		$this->component( 'prova/rotto', array( $this->el( 'img', array( 'alt' => '', 'srcset' => '{this.image.url} 1x' ) ) ) );
		$this->site_page( 'Pagina buona' );
		$this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Usa il rotto', 'post_content' => $this->markup( array( $this->block( 'xb/component', array( 'ref' => 'prova/rotto', 'uid' => 'rotto0001' ) ) ) ) ) );

		$exported = PageProfile::decode( PageProfileAdapter::export() );
		$kinds    = array_column( $exported['manifest']['leftOut'], 'kind', 'title' );
		$this->assertSame( 'page', $kinds['Usa il rotto'] );
		$this->assertContains( 'component', $kinds );
		$this->assertNotContains( 'Usa il rotto', array_map( static fn ( array $entity ): mixed => $entity['payload']['title'] ?? null, $exported['entities'] ) );
	}

	/** A publish send releases what it brought: work of the site left in development stays there. */
	public function test_a_publish_send_leaves_the_work_of_the_site_in_development(): void {
		$other = $this->site_page( 'Lavoro del sito', 'Online' );
		$this->assertSame( 200, $this->save_dev( $other, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Ancora in sviluppo' ) ) ) ) ) )->get_status() );

		$profile = $this->markup_fixture();
		$this->set_option( 'xb_page_profile_project_id', $profile['manifest']['project']['id'] );
		$this->send_markup( $profile, true );
		$this->assertStringNotContainsString( 'Ancora in sviluppo', $this->live_content( $other ) );
		$this->assertContains( \XB\Versioning\Documents::for_post( $other ), array_column( \XB\Plugin::instance()->versioning->changes(), 'doc' ) );
	}

	/** The release dialogs start from what a send brought, so they must tell it from work of the site. */
	public function test_changes_tell_a_send_from_work_of_the_site(): void {
		$other = $this->site_page( 'Lavoro del sito', 'Online' );
		$this->assertSame( 200, $this->save_dev( $other, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Ancora in sviluppo' ) ) ) ) ) )->get_status() );

		$profile = $this->markup_fixture();
		$this->set_option( 'xb_page_profile_project_id', $profile['manifest']['project']['id'] );
		$sent = $this->send_markup( $profile );
		$sent_docs = array_column( array_filter( \XB\Plugin::instance()->versioning->changes(), static fn ( array $change ): bool => $change['sent'] ), 'doc' );
		$this->assertContains( \XB\Versioning\Documents::for_post( $sent['page'] ), $sent_docs );
		$this->assertNotContains( \XB\Versioning\Documents::for_post( $other ), $sent_docs );
	}
}
