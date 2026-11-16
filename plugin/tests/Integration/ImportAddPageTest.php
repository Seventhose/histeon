<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Import\AddPageRest;
use XB\Plugin;
use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;
use XB\Remote\ProfileConflictException;
use XB\Versioning\Documents;

/**
 * V5.2, «Importa una pagina di questo sito». The two readings of «Importa HTML» (AddPageRest): the pages to choose from
 * and the site's base for the converter, only for who may import, with the exact shape of Histeon Publish's
 * `AddPageBase` and the styles payload untouched. Then the package of an added page (Fixtures/add-page, made with the
 * converter the plugin ships, see make.mjs there) through PageProfileAdapter::import: no conflict on the base it was
 * built on, a conflict once the site's styles changed after it.
 */
final class ImportAddPageTest extends IntegrationTestCase {

	private const OPTIONS = array(
		'xb_page_profile_project_id',
		'xb_page_profile_project_name',
		'xb_page_profile_styles_id',
		'xb_page_profile_styles_base_hash',
		'xb_page_profile_written_media',
	);

	/** @return array<string, mixed> */
	private static function fixture_base(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/add-page/base.json' ), true, 64, JSON_THROW_ON_ERROR );
	}

	private static function fixture_package(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/add-page/pagina-aggiunta.xbsite' );
	}

	/**
	 * The site as base.json describes it: its project, its styles entity (id, styles and globals in development) and no
	 * design, token or named query of its own; one page built with Histeon, so the site is a project, not a new one.
	 */
	private function site_at_base(): void {
		$base = self::fixture_base();
		foreach ( self::OPTIONS as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		update_option( 'xb_page_profile_project_id', $base['projectId'], false );
		update_option( 'xb_page_profile_styles_id', $base['styles']['id'], false );
		delete_option( 'xb_page_profile_styles_base_hash' );
		$this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pagina di Histeon',
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Già di Histeon' ) ) ) ) ),
			)
		);
		$versioning = Plugin::instance()->versioning;
		$styles     = $versioning->working_copy( Documents::STYLES );
		$payload    = $base['styles']['payload'];
		$versioning->save_dev( Documents::STYLES, array( 'styles' => $payload['styles'], 'globals' => $payload['globals'] ) + $styles['payload'], (string) $styles['head'], (string) $styles['base'], 'test' );
		$settings = $versioning->working_copy( Documents::SETTINGS );
		$versioning->save_dev( Documents::SETTINGS, array( 'design' => array(), 'tokens' => array(), 'queries' => array() ) + $settings['payload'], (string) $settings['head'], (string) $settings['base'], 'test' );
	}

	/** The same operation id is never left behind by the test (PageProfileAdapterTest::isolate_operation()). */
	private function isolate( string $archive ): void {
		$manifest = PageProfile::decode( $archive )['manifest'];
		foreach ( array( 'xb_remote_operation_' . $manifest['selection']['snapshot'], 'xb_remote_operation_lock_' . $manifest['selection']['snapshot'], 'xb_remote_project_lock_' . $manifest['project']['id'] ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
			delete_option( $option );
		}
	}

	private function get( string $route ): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/xb/v1' . $route ) );
	}

	public function test_only_who_may_import_reads_the_pages_and_the_base(): void {
		foreach ( array( 'editor', 'author' ) as $role ) {
			wp_set_current_user( $this->user( $role ) );
			$this->assertSame( 403, $this->get( AddPageRest::LIST )->get_status(), $role );
			$this->assertSame( 403, $this->get( AddPageRest::BASE )->get_status(), $role );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( AddPageRest::LIST )->get_status() );
		$this->assertSame( 401, $this->get( AddPageRest::BASE )->get_status() );
		// Read-only: no other method.
		wp_set_current_user( 1 );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'POST', '/xb/v1' . AddPageRest::BASE ) )->get_status() );
	}

	public function test_the_base_has_the_shape_of_the_converter_and_the_styles_untouched(): void {
		$this->site_at_base();
		$this->post( array( 'post_type' => 'page', 'post_title' => 'Chi siamo', 'post_name' => 'chi-siamo-base', 'post_status' => 'draft' ) );
		$response = $this->get( AddPageRest::BASE );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		// As the browser reads it: JSON, with an empty map as {}.
		$json = (string) wp_json_encode( $response->get_data() );
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( array( 'projectId', 'styles', 'slugs', 'entityIds', 'assets', 'files' ), array_keys( $data ) );
		$base = self::fixture_base();
		$this->assertSame( $base['projectId'], $data['projectId'] );
		$this->assertSame( array( 'id', 'kind', 'payload', 'revisionHash', 'schemaVersion' ), array_keys( $data['styles'] ) );
		$this->assertSame( $base['styles']['id'], $data['styles']['id'] );
		$this->assertEquals( $base['styles']['payload'], $data['styles']['payload'], 'the payload as the site has it, not touched' );
		$this->assertSame( $base['styles']['revisionHash'], $data['styles']['revisionHash'], 'the revision the converter computes from the payload' );
		$this->assertSame( PageProfile::revision_hash( $data['styles'] ), $data['styles']['revisionHash'] );
		$this->assertStringContainsString( '"design":{}', $json );
		$this->assertStringContainsString( '"files":{}', $json, 'no style uses a media: no bytes' );
		$this->assertContains( 'chi-siamo-base', $data['slugs'], 'every page slug, of Histeon or not, in any state' );
		$this->assertContains( $base['styles']['id'], $data['entityIds'] );
		$this->assertSame( array_values( array_unique( $data['entityIds'] ) ), $data['entityIds'] );
		foreach ( $data['assets'] as $asset ) {
			$this->assertSame( 'asset', $asset['kind'] );
			$this->assertSame( PageProfile::revision_hash( $asset ), $asset['revisionHash'] );
		}
	}

	public function test_a_site_without_a_project_says_so(): void {
		foreach ( self::OPTIONS as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		delete_option( 'xb_page_profile_project_id' );
		$response = $this->get( AddPageRest::BASE );
		$this->assertSame( 409, $response->get_status() );
		$error = $response->as_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'xb_import_base_unavailable', $error->get_error_code() );
	}

	public function test_an_added_page_imports_without_conflict_and_an_old_base_is_stopped(): void {
		$this->site_at_base();
		$archive = self::fixture_package();
		$this->isolate( $archive );
		$profile = PageProfile::decode( $archive );
		$styles  = current( array_filter( $profile['entities'], static fn ( array $entity ): bool => 'styles' === $entity['kind'] ) );
		$this->assertIsArray( $styles );
		$this->assertSame( self::fixture_base()['styles']['revisionHash'], $styles['baseHash'], 'built on the live revision' );

		$plan = PageProfileAdapter::import( $archive, false, true );
		$this->assertTrue( $plan['check'] );
		$report = PageProfileAdapter::import( $archive, false );
		$this->assertTrue( $report['complete'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
		}
		foreach ( $profile['entities'] as $entity ) {
			if ( 'asset' === $entity['kind'] ) {
				$this->track( PageProfileAdapter::post_of_entity( $entity['id'] ) );
			}
		}
		$this->assertCount( 1, $report['pages'] );
		$added = get_post( (int) $report['pages'][0]['postId'] );
		$this->assertInstanceOf( \WP_Post::class, $added );
		$this->assertSame( 'pagina-aggiunta-di-prova', $added->post_name );
		$dev = PageProfileAdapter::dev_payload( Documents::STYLES );
		$by  = array_column( $dev['styles'], 'css', 'selector' );
		$this->assertSame( 'padding:1rem', $by['.sito-testata'], 'the site kept its own declarations' );
		// Since Publish V5.5 the page's rules hold only on its page: the subject of each selector carries the page's mark.
		$mark = '[data-histos-pagina="pagina-aggiunta-di-prova"]';
		$this->assertArrayHasKey( '.apertura:where(' . $mark . ',' . $mark . ' *,html:has(' . $mark . '),body:has(' . $mark . '))', $by, 'the page brought a style the site did not have, limited to its page' );
		$this->assertArrayNotHasKey( '.apertura', $by, 'no rule of the page for the whole site' );
		// M-P2 (Publish e2a76b4): the site's .sito-testata stays as it was, and the page's holds too, on its page only.
		$this->assertSame( 'padding:2rem;', $by[ '.sito-testata:where(' . $mark . ',' . $mark . ' *,html:has(' . $mark . '),body:has(' . $mark . '))' ] ?? null, 'the page\'s own .sito-testata, limited to its page' );
		$content = PageProfileAdapter::dev_payload( Documents::for_post( $added->ID ) )['content'] ?? '';
		$this->assertStringContainsString( '"data-histos-pagina":"pagina-aggiunta-di-prova"', (string) $content, 'the page carries the mark its rules name' );
		$this->assertSame( 'body{margin:0}', $dev['globals'][0]['css'], 'the site\'s sheets first, as they were' );

		// The site's styles that use a media: the base carries its bytes, and only them.
		$asset = current( array_filter( $profile['entities'], static fn ( array $entity ): bool => 'asset' === $entity['kind'] ) );
		$this->assertIsArray( $asset );
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::STYLES );
		$rules      = $copy['payload']['styles'];
		$rules[]    = array( 'id' => 'style-sfondo', 'selector' => '.sfondo', 'css' => 'background-image:url(banco.png)', 'assets' => array( array( 'reference' => 'banco.png', 'assetId' => $asset['id'] ) ) );
		$versioning->save_dev( Documents::STYLES, array( 'styles' => $rules ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'test' );
		$data = json_decode( (string) wp_json_encode( $this->get( AddPageRest::BASE )->get_data() ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( array( $asset['payload']['path'] ), array_keys( $data['files'] ) );
		$this->assertSame( $asset['payload']['sha256'], hash( 'sha256', (string) base64_decode( $data['files'][ $asset['payload']['path'] ], true ) ) );
		$this->assertContains( 'pagina-aggiunta-di-prova', $data['slugs'] );

		// The same package again: its base is no longer the live revision.
		$this->isolate( $archive );
		try {
			PageProfileAdapter::import( $archive, false, true );
			$this->fail( 'a package built on an old base must be a conflict' );
		} catch ( ProfileConflictException $e ) {
			$this->assertSame( array( $styles['id'] ), array_column( $e->conflicts, 'id' ) );
		}
	}

	public function test_the_list_has_the_published_pages_and_leaves_out_those_of_histeon(): void {
		$plain    = $this->post( array( 'post_type' => 'page', 'post_title' => 'Chi siamo & amici' ) );
		$draft    = $this->post( array( 'post_type' => 'page', 'post_title' => 'Bozza', 'post_status' => 'draft' ) );
		$secret   = $this->post( array( 'post_type' => 'page', 'post_title' => 'Riservata', 'post_password' => 'x' ) );
		$histeon  = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Già di Histeon',
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Histeon' ) ) ) ) ),
			)
		);
		$response = $this->get( AddPageRest::LIST );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		$data = $response->get_data();
		$ids  = array_column( $data['pages'], 'id' );
		$this->assertContains( $plain, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $secret, $ids );
		$this->assertNotContains( $histeon, $ids );
		$this->assertGreaterThanOrEqual( 1, $data['histeon'] );
		$this->assertGreaterThanOrEqual( 1, $data['protected'] );
		$row = $data['pages'][ array_search( $plain, $ids, true ) ];
		$this->assertSame( 'Chi siamo & amici', $row['title'] );
		$this->assertSame( get_permalink( $plain ), $row['url'] );
		$this->assertSame( wp_parse_url( (string) get_permalink( $plain ), PHP_URL_PATH ), $row['path'] );
	}
}
