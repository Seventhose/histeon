<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Cms\Bridge;
use XB\Cms\Link;
use XB\Plugin;
use XB\Remote\Link as RemoteLink;
use XB\Versioning\Preview;

/** Histeon CMS ↔ WordPress (docs/spec/cms-bridge.md): the link, its permissions and the signed routes. */
final class CmsBridgeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['HTTPS'] = 'on';
		// A live site, whatever the dev stack says (plain HTTP refused).
		add_filter( 'xb_remote_plain_http', '__return_false' );
		$this->set_option( Link::OPTION, get_option( Link::OPTION, false ) );
		delete_option( Link::OPTION );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTPS'] );
		Link::revoke();
		RemoteLink::revoke_inbound();
		remove_all_filters( 'xb_remote_plain_http' );
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function decode( string $code ): array {
		$this->assertStringStartsWith( Link::CODE_PREFIX, $code );
		$json = base64_decode( strtr( substr( $code, strlen( Link::CODE_PREFIX ) ), '-_', '+/' ), true );
		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	private function secret(): string {
		$link = Link::get();
		$this->assertNotNull( $link );
		return $link['secret'];
	}

	/** @param array<string, string> $headers */
	private function call( string $method, string $route, array $headers = array(), string $body = '', string $type = 'application/zip' ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, Bridge::route( $route ) );
		$request->set_body( $body );
		$request->set_header( 'content-type', $type );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		wp_set_current_user( 0 );
		$response = rest_do_request( $request );
		wp_set_current_user( 1 );
		return $response;
	}

	private function signed( string $method, string $route, string $body = '', ?string $secret = null, string $type = 'application/zip' ): \WP_REST_Response {
		return $this->call( $method, $route, RemoteLink::headers( $secret ?? $this->secret(), $method, Bridge::route( $route ), $body ), $body, $type );
	}

	private function error_code( \WP_REST_Response $response ): string {
		$data = $response->get_data();
		return is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : '';
	}

	public function test_the_code_holds_the_address_a_secret_and_the_chosen_permissions_and_is_separate_from_staging(): void {
		$staging = RemoteLink::create_inbound( false, 'staging.test' );
		$this->assertNotSame( '', $staging );
		$code    = Link::create( 'Sito', true, false, true );
		$decoded = $this->decode( $code );
		$this->assertSame( untrailingslashit( home_url() ), $decoded['url'] );
		$this->assertSame( array( 'read' => true, 'write' => false, 'publish' => false ), $decoded['scopes'], 'Publishing needs the write permission.' );
		$this->assertSame( 32, strlen( (string) base64_decode( strtr( $decoded['secret'], '-_', '+/' ), true ) ) );
		$stored = get_option( Link::OPTION );
		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'v1:', $stored['secret'], 'encrypted at rest' );
		$this->assertNotNull( RemoteLink::inbound(), 'Pairing the CMS never disconnects staging.' );

		RemoteLink::revoke_inbound();
		$this->assertNotNull( Link::get(), 'Revoking staging never disconnects the CMS.' );
	}

	public function test_requests_need_a_fresh_valid_signature_over_https(): void {
		Link::create( '', true, true, false );
		$this->assertSame( 401, $this->call( 'GET', Bridge::CAPABILITIES )->get_status() );
		$this->assertSame( 401, $this->signed( 'GET', Bridge::CAPABILITIES, '', random_bytes( 32 ) )->get_status() );

		$headers = RemoteLink::headers( $this->secret(), 'GET', Bridge::route( Bridge::CAPABILITIES ), '' );
		$this->assertSame( 200, $this->call( 'GET', Bridge::CAPABILITIES, $headers )->get_status() );
		$replayed = $this->call( 'GET', Bridge::CAPABILITIES, $headers );
		$this->assertSame( 401, $replayed->get_status(), 'a replayed nonce' );
		$this->assertSame( 'xb_cms_refused', $replayed->as_error()?->get_error_code(), 'a refusal is never mistaken for a revoked link' );

		$old                       = RemoteLink::headers( $this->secret(), 'GET', Bridge::route( Bridge::CAPABILITIES ), '' );
		$old['X-Histos-Time']      = (string) ( time() - RemoteLink::SKEW - 5 );
		$old['X-Histos-Signature'] = RemoteLink::signature( $this->secret(), 'GET', Bridge::route( Bridge::CAPABILITIES ), (int) $old['X-Histos-Time'], $old['X-Histos-Nonce'], '' );
		$this->assertSame( 401, $this->call( 'GET', Bridge::CAPABILITIES, $old )->get_status(), 'an old request' );

		$other = RemoteLink::headers( $this->secret(), 'GET', Bridge::route( Bridge::EXPORT ), '' );
		$this->assertSame( 401, $this->call( 'GET', Bridge::CAPABILITIES, $other )->get_status(), 'signed for another route' );

		// A staging secret never opens the CMS routes.
		RemoteLink::create_inbound( false, 'staging.test' );
		$staging = RemoteLink::inbound();
		$this->assertNotNull( $staging );
		$this->assertSame( 401, $this->signed( 'GET', Bridge::CAPABILITIES, '', $staging['secret'] )->get_status() );

		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 403, $this->signed( 'GET', Bridge::CAPABILITIES )->get_status(), 'plain HTTP on a live site' );
		$_SERVER['HTTPS'] = 'on';
	}

	public function test_requests_run_as_the_administrator_who_created_the_link_while_they_still_are_one(): void {
		$owner = $this->user( 'administrator' );
		$user  = get_user_by( 'id', $owner );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $owner );
		Link::create( '', true, false, false );
		wp_set_current_user( 1 );
		$response = $this->signed( 'GET', Bridge::CAPABILITIES );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'read' => true, 'write' => false, 'publish' => false ), array_intersect_key( $response->get_data()['capabilities'], array_flip( array( 'read', 'write', 'publish' ) ) ) );
		$this->assertTrue( $response->get_data()['capabilities']['stagedMedia'], 'Histeon Publish can tell that sends without publishing keep media off the live site.' );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );

		$user->set_role( 'editor' );
		$this->assertSame( 403, $this->signed( 'GET', Bridge::CAPABILITIES )->get_status(), 'the owner is no longer an administrator' );
	}

	public function test_every_route_keeps_to_the_permissions_of_the_link(): void {
		Link::create( '', true, false, false );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::IMPORT, 'PK' ) ), 'read only: no sends' );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::PUBLISH, 'PK' ) ) );
		$this->assertNotSame( 'xb_cms_scope', $this->error_code( $this->signed( 'GET', Bridge::EXPORT ) ) );

		Link::create( '', false, true, false );
		$this->assertSame( 403, $this->signed( 'GET', Bridge::EXPORT )->get_status(), 'write only: no downloads' );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'GET', Bridge::EXPORT ) ) );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::PUBLISH, 'PK' ) ), 'sending is not publishing' );
		$this->assertSame( 415, $this->signed( 'POST', Bridge::IMPORT, 'PK', null, 'text/plain' )->get_status() );
		$this->assertSame( 'xb_cms_invalid', $this->error_code( $this->signed( 'POST', Bridge::IMPORT, 'not a zip' ) ) );

		$this->assertSame( 404, $this->signed( 'GET', '/cms/v1/status/' . wp_generate_uuid4() )->get_status(), 'no operation of this link' );
	}

	/** The static page fixture as a send from Histeon CMS. */
	private function page_archive(): string {
		$fixture  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/histos-static-page-v1.json' ), true, 128, JSON_THROW_ON_ERROR );
		$manifest = $fixture['profile']['manifest'];
		$manifest['selection']['snapshot'] = wp_generate_uuid4();
		$entities   = $fixture['profile']['entities'];
		$references = $fixture['profile']['references'] ?? array();
		$files      = array(
			'entities.ndjson'   => implode( "\n", array_map( \XB\Remote\PageProfile::canonical(...), $entities ) ) . "\n",
			'references.ndjson' => array() === $references ? '' : implode( "\n", array_map( \XB\Remote\PageProfile::canonical(...), $references ) ) . "\n",
		);
		foreach ( $manifest['files'] as &$file ) {
			if ( ! isset( $files[ $file['path'] ] ) ) {
				$files[ $file['path'] ] = (string) base64_decode( (string) ( $fixture['files'][ $file['path'] ] ?? '' ), true );
			}
			$file['bytes']  = strlen( $files[ $file['path'] ] );
			$file['sha256'] = hash( 'sha256', $files[ $file['path'] ] );
		}
		unset( $file );
		$manifest['manifestHash'] = \XB\Remote\PageProfile::manifest_hash( $manifest );
		return \XB\Remote\PageProfile::archive( $manifest, $entities, $references, $files );
	}

	public function test_a_signed_send_arrives_in_development_and_the_download_shows_it(): void {
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		// A development site over plain HTTP, as the fixture's media addresses expect.
		unset( $_SERVER['HTTPS'] );
		remove_all_filters( 'xb_remote_plain_http' );
		add_filter( 'xb_remote_plain_http', '__return_true' );
		Link::create( '', true, true, false );
		$response = $this->signed( 'POST', Bridge::IMPORT, $this->page_archive() );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$report = $response->get_data();
		$this->assertTrue( $report['complete'] );
		$this->assertFalse( $report['published'] );
		foreach ( $report['pages'] as $page ) {
			$this->track( (int) $page['postId'] );
			$this->assertSame( 'draft', get_post_status( (int) $page['postId'] ), 'A send never publishes.' );
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_id', 'meta_compare' => 'EXISTS' ) ) as $attachment ) {
			$this->track( $attachment->ID );
		}
		$this->assertSame( 200, $this->signed( 'GET', '/cms/v1/status/' . $report['operationId'] )->get_status() );

		$export = $this->signed( 'GET', Bridge::EXPORT );
		$this->assertSame( 200, $export->get_status() );
		$this->assertIsString( $export->get_data() );
		$this->assertStringStartsWith( 'PK', $export->get_data() );
		$this->assertSame( 'application/zip', $export->get_headers()['Content-Type'] ?? null );
		$decoded = \XB\Remote\PageProfile::decode( $export->get_data() );
		$this->assertContains( $report['pages'][0]['id'], array_column( $decoded['entities'], 'id' ) );
	}

	/**
	 * A published page of the project on the site: a page with a profile identity.
	 *
	 * @return array{0: string, 1: int}
	 */
	private function project_page( string $title, string $status = 'publish' ): array {
		$id   = wp_generate_uuid4();
		$post = $this->post( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => $status ) );
		update_post_meta( $post, '_xb_page_profile_id', $id );
		return array( $id, $post );
	}

	private function keep_reading_options(): void {
		foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) {
			$this->set_option( $option, get_option( $option ) );
		}
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );
	}

	public function test_the_home_and_posts_page_are_read_with_the_read_permission_and_set_only_with_publishing(): void {
		$this->keep_reading_options();
		[ $home, $home_post ] = $this->project_page( 'Home' );
		[ $blog, $blog_post ] = $this->project_page( 'Blog' );
		$capabilities = $this->signed_capabilities();
		$this->assertSame( array( 'histos-front-page-v1' ), $capabilities['siteSettings'] );
		$body = (string) wp_json_encode( array( 'frontPage' => $home, 'postsPage' => $blog ) );

		Link::create( '', true, false, false );
		$read = $this->signed( 'GET', Bridge::FRONT_PAGE );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( array( 'showOnFront' => 'posts', 'frontPage' => null, 'postsPage' => null, 'frontPageOther' => false, 'postsPageOther' => false ), $read->get_data() );
		$this->assertSame( 'no-store, private', $read->get_headers()['Cache-Control'] ?? null );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::FRONT_PAGE, $body, null, 'application/json' ) ), 'read only' );
		Link::create( '', true, true, false );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::FRONT_PAGE, $body, null, 'application/json' ) ), 'sending is not publishing' );
		Link::create( '', false, true, true );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'GET', Bridge::FRONT_PAGE ) ), 'no reading without the read permission' );
		$this->assertSame( 'posts', get_option( 'show_on_front' ), 'nothing changed so far' );
		$this->assertSame( 401, $this->call( 'POST', Bridge::FRONT_PAGE, array(), $body, 'application/json' )->get_status(), 'unsigned' );

		Link::create( '', true, true, true );
		$set = $this->signed( 'POST', Bridge::FRONT_PAGE, $body, null, 'application/json' );
		$this->assertSame( 200, $set->get_status(), (string) wp_json_encode( $set->get_data() ) );
		$this->assertSame( array( 'showOnFront' => 'page', 'frontPage' => $home, 'postsPage' => $blog, 'frontPageOther' => false, 'postsPageOther' => false ), $set->get_data() );
		$this->assertSame( array( 'page', $home_post, $blog_post ), array( get_option( 'show_on_front' ), (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$audit = Plugin::instance()->versioning->store()->audit_log( 1 )[0];
		$this->assertSame( 'cms_front_page', $audit['action'] );
		$this->assertSame( array( 'before' => array( 'show' => 'posts', 'front' => 0, 'posts' => 0 ), 'after' => array( 'show' => 'page', 'front' => $home_post, 'posts' => $blog_post ) ), json_decode( $audit['details'], true ) );

		// Without a posts page: the current one stays, unless it is the new home.
		$set = $this->signed( 'POST', Bridge::FRONT_PAGE, (string) wp_json_encode( array( 'frontPage' => $blog, 'postsPage' => null ) ), null, 'application/json' );
		$this->assertSame( 200, $set->get_status() );
		$this->assertSame( array( $blog_post, 0 ), array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
	}

	public function test_only_published_pages_of_the_project_become_the_home_and_the_request_is_closed(): void {
		$this->keep_reading_options();
		Link::create( '', true, true, true );
		[ $home ] = $this->project_page( 'Home' );
		[ $draft ] = $this->project_page( 'Bozza', 'draft' );
		$private = $this->project_page( 'Privata', 'private' )[0];
		$foreign = $this->post( array( 'post_type' => 'page', 'post_title' => 'Del sito' ) );
		$article = wp_generate_uuid4();
		update_post_meta( $this->post( array( 'post_title' => 'Articolo' ) ), '_xb_page_profile_id', $article );
		$protected = $this->project_page( 'Con password' );
		wp_update_post( array( 'ID' => $protected[1], 'post_password' => 'x' ) );
		$refused = array(
			'not json'         => 'front',
			'a missing key'    => (string) wp_json_encode( array( 'frontPage' => $home ) ),
			'an extra key'     => (string) wp_json_encode( array( 'frontPage' => $home, 'postsPage' => null, 'show' => 'page' ) ),
			'no home'          => (string) wp_json_encode( array( 'frontPage' => null, 'postsPage' => $home ) ),
			'a post id'        => (string) wp_json_encode( array( 'frontPage' => $foreign, 'postsPage' => null ) ),
			'not an identity'  => (string) wp_json_encode( array( 'frontPage' => 'home', 'postsPage' => null ) ),
			'unknown identity' => (string) wp_json_encode( array( 'frontPage' => wp_generate_uuid4(), 'postsPage' => null ) ),
			'a draft'          => (string) wp_json_encode( array( 'frontPage' => $draft, 'postsPage' => null ) ),
			'a private page'   => (string) wp_json_encode( array( 'frontPage' => $private, 'postsPage' => null ) ),
			'a password'       => (string) wp_json_encode( array( 'frontPage' => $protected[0], 'postsPage' => null ) ),
			'an article'       => (string) wp_json_encode( array( 'frontPage' => $article, 'postsPage' => null ) ),
			'a draft as posts' => (string) wp_json_encode( array( 'frontPage' => $home, 'postsPage' => $draft ) ),
			'the same twice'   => (string) wp_json_encode( array( 'frontPage' => $home, 'postsPage' => $home ) ),
		);
		foreach ( $refused as $why => $body ) {
			// WordPress itself answers 400 to a JSON body that does not parse; as plain text it reaches the route.
			$response = $this->signed( 'POST', Bridge::FRONT_PAGE, $body, null, 'not json' === $why ? 'text/plain' : 'application/json' );
			$this->assertSame( 422, $response->get_status(), $why );
			$this->assertSame( 'xb_cms_invalid', $this->error_code( $response ), $why );
		}
		$this->assertSame( array( 'posts', 0, 0 ), array( get_option( 'show_on_front' ), (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );

		// A page of the site that is not of the project is only «another page», never named.
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $foreign );
		$this->assertSame( array( 'showOnFront' => 'page', 'frontPage' => null, 'postsPage' => null, 'frontPageOther' => true, 'postsPageOther' => false ), $this->signed( 'GET', Bridge::FRONT_PAGE )->get_data() );

		// The owner who is no longer an administrator cannot change Settings → Reading.
		$owner = $this->user( 'administrator' );
		$user  = get_user_by( 'id', $owner );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $owner );
		Link::create( '', true, true, true );
		wp_set_current_user( 1 );
		// Still an administrator, but no longer allowed to publish: explicitly denied on the user, over the role.
		$user->add_cap( 'xb_publish', false );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'POST', Bridge::FRONT_PAGE, (string) wp_json_encode( array( 'frontPage' => $home, 'postsPage' => null ) ), null, 'application/json' ) ) );
		$this->assertSame( $foreign, (int) get_option( 'page_on_front' ) );
	}

	/** @return array<string, mixed> */
	private function signed_capabilities(): array {
		Link::create( '', true, false, false );
		$response = $this->signed( 'GET', Bridge::CAPABILITIES );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['capabilities'];
	}

	/** V1.6, threat model T32: the preview links Histeon Publish asks for its checks in the browser. */
	public function test_preview_links_open_the_changed_pages_for_ten_minutes_as_a_visitor_with_the_read_permission(): void {
		$changed   = $this->post( array( 'post_type' => 'page', 'post_title' => 'Cambiata' ) );
		$unchanged = $this->post( array( 'post_type' => 'page', 'post_title' => 'Uguale' ) );
		$this->publish();
		$this->assertSame( 200, $this->save_dev( $changed, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'nuovo' ) ) ) ) ) )->get_status() );
		$component = $this->component( 'scheda', array( $this->el( 'p', array(), array( $this->text( 'scheda' ) ) ) ) );
		$this->save_dev( $component, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'scheda nuova' ) ) ) ) ) );

		Link::create( '', true, false, false );
		$this->assertTrue( $this->signed_capabilities()['previewLinks'], 'Publish knows the site offers them' );
		$response = $this->signed( 'GET', Bridge::PREVIEW_LINKS );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		$data = $response->get_data();
		$this->assertSame( array( 'post:' . $changed ), array_column( $data['pages'], 'doc' ), 'only the changed page: not the unchanged one, not the component' );
		$this->assertSame( 0, $data['skipped'] );
		$this->assertEqualsWithDelta( time() + 600, $data['expires'], 5 );
		$page = $data['pages'][0];
		$this->assertSame( 'Cambiata', $page['title'] );
		$this->assertStringNotContainsString( Preview::PARAM, $page['page'], 'the address to keep has no token' );
		$query = array();
		parse_str( (string) wp_parse_url( $page['url'], PHP_URL_QUERY ), $query );
		$token = $query[ Preview::PARAM ] ?? null;
		$this->assertIsString( $token, 'the preview address carries one token' );
		$this->assertSame( $changed, Preview::validate( $token ) );
		$this->assertEqualsWithDelta( time() + 600, Preview::expires( $page['url'] ), 5, 'ten minutes, fixed' );
		$this->assertMatchesRegularExpression( '/^\d+\.' . $changed . '\.\d+\.as-guest\.[0-9a-f]+$/', $token, 'seen as a visitor without an account' );
		$audit = Plugin::instance()->versioning->store()->audit_log( 1 )[0];
		$this->assertSame( 'cms_preview_links', $audit['action'] );
		$this->assertSame( array( 'links' => 1 ), json_decode( $audit['details'], true ), 'the log never keeps a token' );

		Link::create( '', false, true, true );
		$this->assertSame( 'xb_cms_scope', $this->error_code( $this->signed( 'GET', Bridge::PREVIEW_LINKS ) ), 'no links without the read permission' );
		$this->assertFalse( $this->signed_capabilities_of_current_link()['previewLinks'] );
		$this->assertSame( 401, $this->call( 'GET', Bridge::PREVIEW_LINKS )->get_status(), 'unsigned' );
	}

	public function test_preview_links_stop_at_twenty_pages(): void {
		$ids = array();
		for ( $i = 0; $i < 22; $i++ ) {
			$ids[] = $this->post( array( 'post_type' => 'page', 'post_title' => 'Pagina ' . $i ) );
		}
		$this->publish();
		foreach ( $ids as $id ) {
			$this->save_dev( $id, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'nuovo ' . $id ) ) ) ) ) );
		}
		Link::create( '', true, false, false );
		$data = $this->signed( 'GET', Bridge::PREVIEW_LINKS )->get_data();
		$this->assertCount( 20, $data['pages'] );
		$this->assertSame( 2, $data['skipped'] );
	}

	/** @return array<string, mixed> */
	private function signed_capabilities_of_current_link(): array {
		return $this->signed( 'GET', Bridge::CAPABILITIES )->get_data()['capabilities'];
	}

	public function test_a_signed_revoke_closes_the_link_at_once(): void {
		Link::create( '', true, true, false );
		$secret = $this->secret();
		$this->assertSame( 200, $this->signed( 'POST', Bridge::REVOKE, '', $secret )->get_status() );
		$this->assertNull( Link::get() );
		$after = $this->signed( 'GET', Bridge::CAPABILITIES, '', $secret );
		$this->assertSame( 401, $after->get_status() );
		$this->assertSame( 'xb_cms_inactive', $after->as_error()?->get_error_code(), 'Histeon CMS may forget its secret only on this answer' );
	}
}
