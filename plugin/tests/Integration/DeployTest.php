<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Deploy\Credentials;
use XB\Deploy\Deployer;
use XB\StaticSite\Assets;
use XB\StaticSite\Bundle;
use XB\StaticSite\Exporter;
use XB\StaticSite\ExportMode;
use XB\StaticSite\Scanner;
use XB\StaticSite\SecurityHeaders;

/** Static export and Cloudflare deploy against the local API mock (dev/cloudflare-mock). */
final class DeployTest extends IntegrationTestCase {

	private const ACCOUNT = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'XB_CLOUDFLARE_API_BASE' ) || ! str_contains( (string) XB_CLOUDFLARE_API_BASE, 'cfmock' ) ) {
			$this->markTestSkipped( 'Serve il mock di Cloudflare del WordPress di sviluppo (dev/up.sh).' );
		}
		$this->set_option( Credentials::OPTION, get_option( Credentials::OPTION ) );
		$this->set_option( Deployer::AUTO, get_option( Deployer::AUTO ) );
		wp_remote_get( 'http://cfmock:8080/__reset' );
	}

	private function configure(): void {
		$token = (string) getenv( 'XB_MOCK_TOKEN' );
		if ( '' === $token ) {
			$this->markTestSkipped( 'Definisci XB_MOCK_TOKEN per eseguire i test Cloudflare con il mock locale.' );
		}
		Credentials::save( self::ACCOUNT, 'xb-test-site', $token );
	}

	/** @return array<string, mixed> */
	private function mock(): array {
		$state = json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://cfmock:8080/__state' ) ), true );
		return is_array( $state ) ? $state : array();
	}

	public function test_token_is_encrypted_and_never_exposed(): void {
		$token = (string) getenv( 'XB_MOCK_TOKEN' );
		$this->configure();
		$raw = (string) wp_json_encode( get_option( Credentials::OPTION ) );
		$this->assertStringNotContainsString( $token, $raw, 'not stored in clear' );
		$this->assertSame( $token, Credentials::get()['token'] );
		$status = (string) wp_json_encode( $this->request( 'GET', '/deploy' )->get_data() );
		$this->assertStringNotContainsString( $token, $status );
		$this->assertStringContainsString( '"tokenSet":true', $status );

		// Keeping the token when it is not resent, refusing garbage.
		Credentials::save( self::ACCOUNT, 'xb-test-site', '' );
		$this->assertSame( $token, Credentials::get()['token'] );
		$this->assertSame( 400, $this->request( 'POST', '/deploy/config', array( 'account' => 'nope', 'script' => 'x' ) )->get_status() );
		$this->assertSame( '', Credentials::decrypt( 'v1:' . base64_encode( random_bytes( 40 ) ) ), 'tampered ciphertext is rejected' );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/deploy/config', array( 'account' => self::ACCOUNT, 'script' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/deploy' )->get_status() );
	}

	/** P4: paginated, author and date archives are part of the export, as Static Bridge 2.2.0 lists them. */
	public function test_export_lists_every_archive_page(): void {
		$this->set_option( 'posts_per_page', 2 );
		$term = wp_insert_term( 'Archivio zq ' . wp_generate_password( 4, false, false ), 'category' );
		$this->assertIsArray( $term );
		$author = $this->user( 'author' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $author, 'post_date' => '2011-03-0' . ( $i + 1 ) . ' 10:00:00', 'post_category' => array( (int) $term['term_id'] ) ) );
		}
		try {
			$urls     = ( new Exporter() )->urls();
			$link     = get_term_link( (int) $term['term_id'] );
			$this->assertIsString( $link );
			$category = (string) wp_parse_url( $link, PHP_URL_PATH );
			$this->assertContains( $category, $urls );
			$this->assertContains( $category . 'page/2/', $urls, '5 posts, 2 per page' );
			$this->assertContains( $category . 'page/3/', $urls );
			$this->assertNotContains( $category . 'page/4/', $urls );
			$by = (string) wp_parse_url( get_author_posts_url( $author ), PHP_URL_PATH );
			$this->assertContains( $by, $urls, 'author archive' );
			$this->assertContains( $by . 'page/3/', $urls );
			$this->assertContains( (string) wp_parse_url( get_month_link( 2011, 3 ), PHP_URL_PATH ), $urls, 'month archive' );
			$this->assertContains( (string) wp_parse_url( get_year_link( 2011 ), PHP_URL_PATH ), $urls, 'year archive' );

			$cap = static fn (): int => 2;
			add_filter( 'xb_static_archive_pages', $cap );
			try {
				$this->assertNotContains( $category . 'page/3/', ( new Exporter() )->urls(), 'a cap per archive' );
			} finally {
				remove_filter( 'xb_static_archive_pages', $cap );
			}
		} finally {
			wp_delete_term( (int) $term['term_id'], 'category' );
		}
	}

	public function test_export_renders_public_pages_and_copies_assets_safely(): void {
		// The framework as a whole file (not inlined on demand): a stylesheet the pages link, to be copied.
		$this->set_option( \XB\Styles\PageStyles::MODE, 'full' );
		$page  = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Statica',
				'post_content' => $this->markup( array( $this->el( 'p', array( 'class' => 'static' ), array( $this->text( 'Pagina {this.title}' ) ) ) ) ),
			)
		);
		$draft = $this->post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Bozza segreta',
				'post_status' => 'draft',
			)
		);
		$build = ( new Exporter() )->build( 'test' );
		$this->assertSame( array(), $build['log'], implode( "\n", $build['log'] ) );
		$files = Bundle::files( $build['dir'] );
		$path  = (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		$this->assertArrayHasKey( '/' . Bundle::page_file( $path ), $files );
		$this->assertArrayHasKey( '/404.html', $files );
		$html = (string) file_get_contents( $files[ '/' . Bundle::page_file( $path ) ] );
		$this->assertStringContainsString( '<p class="static">Pagina Statica</p>', $html );
		$this->assertStringNotContainsString( 'data-xb-uid', $html );
		$this->assertStringNotContainsString( 'api.w.org', $html, 'REST discovery removed' );
		$this->assertStringNotContainsString( 'http://localhost', $html, 'links are root-relative' );
		$css = array_filter( array_keys( $files ), static fn ( string $f ): bool => str_ends_with( $f, '.css' ) );
		$this->assertNotEmpty( $css, 'stylesheets referenced by the pages are copied' );
		foreach ( $css as $file ) {
			$this->assertMatchesRegularExpression( '#^/wp-(content|includes)/#', $file );
		}
		foreach ( array_keys( $files ) as $file ) {
			$this->assertStringNotContainsString( '.php', $file );
		}
		$this->assertStringNotContainsString( 'Bozza segreta', implode( '', array_map( 'file_get_contents', $files ) ) );
		$this->assertFalse( Scanner::blocking( $build['findings'] ), (string) wp_json_encode( $build['findings'] ) );
		unset( $draft );
	}

	public function test_assets_never_leave_wp_content_or_wp_includes(): void {
		$dir    = Bundle::create( 'assets-test' );
		$assets = new Assets( $dir );
		$this->assertNull( $assets->copy( '/wp-content/../wp-config.php', '/' ) );
		$this->assertNull( $assets->copy( '/wp-content/plugins/xb/xb.php', '/' ) );
		$this->assertNull( $assets->copy( '/wp-config.php', '/' ) );
		$this->assertNull( $assets->copy( 'https://evil.example/x.css', '/' ) );
		$this->assertNull( $assets->copy( '//evil.example/x.css', '/' ) );
		$this->assertNull( $assets->copy( '/wp-content/%2e%2e/wp-config.php', '/' ) );
		$this->assertSame( '/wp-content/plugins/xb/assets/tokens/xb-tokens.css', $assets->copy( '/wp-content/plugins/xb/assets/tokens/xb-tokens.css?ver=1', '/' ) );
		$this->assertSame( 1, $assets->count() );
	}

	/** A loopback answer is not proof that visitors can download a file: in uploads only published media goes. */
	public function test_only_published_media_leaves_uploads(): void {
		$uploads = wp_get_upload_dir();
		$sub     = 'xb-export-test-' . wp_generate_password( 6, false, false );
		wp_mkdir_p( $uploads['basedir'] . '/' . $sub );
		foreach ( array( 'media.png', 'media-300x200.png', 'media-640x480.png', 'private.png', 'internal.pdf' ) as $file ) {
			file_put_contents( $uploads['basedir'] . "/$sub/$file", 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$draft = $this->post( array( 'post_status' => 'draft' ) );
		foreach ( array( 'media.png' => 0, 'private.png' => $draft ) as $file => $parent ) {
			$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $file, 'post_status' => 'inherit', 'post_parent' => $parent ), "$sub/$file", $parent, true );
			$this->assertIsInt( $id );
			$this->track( $id );
			update_post_meta( $id, '_wp_attached_file', "$sub/$file" );
			if ( 'media.png' === $file ) {
				wp_update_attachment_metadata( $id, array( 'file' => "$sub/$file", 'sizes' => array( 'medium' => array( 'file' => 'media-300x200.png', 'width' => 300, 'height' => 200, 'mime-type' => 'image/png' ) ) ) );
			}
		}
		$base   = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$dir    = Bundle::create( 'assets-media-test' );
		$assets = new Assets( $dir );
		try {
			$this->assertNotNull( $assets->copy( "$base/$sub/media.png", '/' ), 'a library image' );
			$this->assertNotNull( $assets->copy( "$base/$sub/media-300x200.png", '/' ), 'one of its sizes' );
			$this->assertNull( $assets->copy( "$base/$sub/media-640x480.png", '/' ), 'named like a size, but not one WordPress made (note 58)' );
			$this->assertNull( $assets->copy( "$base/$sub/private.png", '/' ), 'attached to an unpublished post' );
			$this->assertNull( $assets->copy( "$base/$sub/internal.pdf", '/' ), 'not a media item at all' );
		} finally {
			foreach ( glob( $uploads['basedir'] . "/$sub/*" ) ?: array() as $file ) {
				wp_delete_file( $file );
			}
			rmdir( $uploads['basedir'] . "/$sub" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	/**
	 * doc 23, voce 170: «in uploads» is where the file really is, not the prefix of its address. A link outside uploads
	 * that points in, or an uploads address WordPress no longer uses (upload_url_path, a CDN), still meets the check.
	 */
	public function test_uploads_are_recognised_by_the_real_path(): void {
		$uploads = wp_get_upload_dir();
		$sub     = 'xb-export-real-' . wp_generate_password( 6, false, false );
		$link    = 'xb-zq-link-' . wp_generate_password( 6, false, false );
		wp_mkdir_p( $uploads['basedir'] . '/' . $sub );
		foreach ( array( 'media.png', 'internal.pdf' ) as $file ) {
			file_put_contents( $uploads['basedir'] . "/$sub/$file", 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'media', 'post_status' => 'inherit' ), "$sub/media.png", 0, true );
		$this->assertIsInt( $id );
		$this->track( $id );
		update_post_meta( $id, '_wp_attached_file', "$sub/media.png" );
		$this->assertTrue( symlink( $uploads['basedir'] . '/' . $sub, WP_CONTENT_DIR . '/' . $link ) );
		$cdn = static function ( array $dir ): array {
			$dir['baseurl'] = 'https://cdn.example.test/files';
			$dir['url']     = $dir['baseurl'] . $dir['subdir'];
			return $dir;
		};
		try {
			$assets = new Assets( Bundle::create( 'assets-real-test' ) );
			$this->assertNull( $assets->copy( "/wp-content/$link/internal.pdf", '/' ), 'a link from outside uploads to a file that is no media item' );
			$this->assertNotNull( $assets->copy( "/wp-content/$link/media.png", '/' ), 'the same link to a library image' );

			add_filter( 'upload_dir', $cdn );
			$assets = new Assets( Bundle::create( 'assets-cdn-test' ) );
			$this->assertNull( $assets->copy( "/wp-content/uploads/$sub/internal.pdf", '/' ), 'uploads served from another address' );
			$this->assertNotNull( $assets->copy( "/wp-content/uploads/$sub/media.png", '/' ), 'a library image under that address' );
		} finally {
			remove_filter( 'upload_dir', $cdn );
			unlink( WP_CONTENT_DIR . '/' . $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			foreach ( glob( $uploads['basedir'] . "/$sub/*" ) ?: array() as $file ) {
				wp_delete_file( $file );
			}
			rmdir( $uploads['basedir'] . "/$sub" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	public function test_export_mode_requires_a_valid_token(): void {
		$this->assertTrue( ExportMode::valid( ExportMode::token() ) );
		$this->assertFalse( ExportMode::valid( ( time() + 60 ) . '.forged' ) );
		$this->assertFalse( ExportMode::valid( ( time() - 1 ) . '.' . hash_hmac( 'sha256', 'xb-export|' . ( time() - 1 ), wp_salt( 'auth' ) ) ) );
	}

	/** Note 117 of the 3 November audit: a site that is not local and not on HTTPS gets no forms through the Worker. */
	public function test_forms_are_off_without_https_outside_a_local_site(): void {
		$this->configure();
		add_filter( 'xb_remote_plain_http', '__return_false' );
		try {
			$done = $this->request( 'POST', '/deploy' );
		} finally {
			remove_all_filters( 'xb_remote_plain_http' );
		}
		$this->assertSame( 200, $done->get_status(), (string) wp_json_encode( $done->get_data() ) );
		$this->assertCount( 1, $done->get_data()['warnings'] );
		$this->assertStringContainsString( 'https', $done->get_data()['warnings'][0] );
	}

	public function test_deploy_dedupes_uploads_and_rolls_back_instantly(): void {
		$this->configure();
		$before = glob( Bundle::root() . '/*', GLOB_ONLYDIR ) ?: array();
		$first  = $this->request( 'POST', '/deploy' );
		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );
		$one = $first->get_data();
		$this->assertGreaterThan( 0, $one['files'] );
		$this->assertSame( $one['files'], $one['uploaded'], 'everything uploaded the first time' );
		$this->assertSame( $one['version'], $this->mock()['active']['xb-test-site'] ?? null );
		$this->assertSame( array( 'ASSETS', 'XB_FORMS_ENDPOINT', 'XB_SECURITY_HEADERS' ), $this->mock()['versions'][ $one['version'] ]['bindings'] );
		$this->assertSame( array(), $one['warnings'], 'a local site keeps its forms over plain HTTP' );
		$this->assertSame( $before, glob( Bundle::root() . '/*', GLOB_ONLYDIR ) ?: array(), 'the bundle does not stay on the origin' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( Bundle::root() . '/.htaccess' ) );

		$this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Nuova pagina',
				'post_content' => $this->markup( array( $this->text( 'nuova' ) ) ),
			)
		);
		$two = $this->request( 'POST', '/deploy' )->get_data();
		$this->assertLessThan( $two['files'], $two['uploaded'], 'unchanged files are not uploaded again' );
		$this->assertSame( $two['version'], $this->mock()['active']['xb-test-site'] ?? null );

		$this->assertSame( 200, $this->request( 'POST', '/deploy/' . $one['id'] . '/rollback' )->get_status() );
		$this->assertSame( $one['version'], $this->mock()['active']['xb-test-site'] ?? null, 'previous version live again' );
		$this->assertSame( 'live', ( new Deployer() )->history( 1 )[0]['status'] );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/deploy' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/deploy' )->get_status(), 'deploy history is for publishers only' );
	}

	/** P5: the static site answers with the security headers, from a closed list (docs/spec/static.md). */
	public function test_security_headers_reach_the_static_site(): void {
		$this->configure();
		$deploy = $this->request( 'POST', '/deploy' );
		$this->assertSame( 200, $deploy->get_status(), (string) wp_json_encode( $deploy->get_data() ) );
		$version = $this->mock()['versions'][ $deploy->get_data()['version'] ];
		$this->assertStringContainsString( 'X-Frame-Options: SAMEORIGIN', (string) $version['headers'], 'assets config of the Worker version' );
		$site = wp_remote_get( 'http://cfmock:8080/__site/xb-test-site/' );
		$this->assertSame( 'nosniff', wp_remote_retrieve_header( $site, 'x-content-type-options' ) );
		$csp  = wp_remote_retrieve_header( $site, 'content-security-policy' );
		$this->assertIsString( $csp );
		$this->assertStringContainsString( "frame-ancestors 'self'", $csp );
		$this->assertSame( 404, wp_remote_retrieve_response_code( wp_remote_get( 'http://cfmock:8080/__site/xb-test-site/_headers' ) ), 'configuration, not a served file' );

		// The ZIP carries the same file for the hosts that read it.
		$build = ( new Exporter() )->build( 'headers-test' );
		try {
			$this->assertSame( SecurityHeaders::file(), (string) file_get_contents( $build['dir'] . '/_headers' ) );
		} finally {
			Bundle::delete( $build['dir'] );
		}

		// A site may change the values, never add a header; values must stay on one line.
		$filter = static fn (): array => array( 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => "no-referrer\r\nSet-Cookie: x=1", 'Set-Cookie' => 'x=1', 'Permissions-Policy' => '' );
		add_filter( 'xb_static_security_headers', $filter );
		try {
			$headers = SecurityHeaders::all();
		} finally {
			remove_filter( 'xb_static_security_headers', $filter );
		}
		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertArrayNotHasKey( 'Set-Cookie', $headers );
		$this->assertArrayNotHasKey( 'Referrer-Policy', $headers, 'a value with a line break is left out' );
		$this->assertArrayNotHasKey( 'Permissions-Policy', $headers, 'an empty value turns a header off' );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
	}

	/**
	 * doc 23, voce 171: putting back an older static version says which contents withdrawn since it was built it
	 * serves again (draft, private, trash), before (the deploy panel) and after (the rollback's warnings).
	 */
	public function test_a_rollback_names_the_contents_withdrawn_since_that_version(): void {
		global $wpdb;
		$this->configure();
		$before = gmdate( 'Y-m-d H:i:s', time() - 7200 );
		$old    = array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_date_gmt' => $before,
			'post_date'     => get_date_from_gmt( $before ),
		);
		$draft   = $this->post( array( 'post_title' => 'Ritirata zq bozza' ) + $old );
		$trashed = $this->post( array( 'post_title' => 'Ritirata zq cestino' ) + $old );
		$private = $this->post( array( 'post_title' => 'Ritirata zq privata' ) + $old );
		$this->post( array( 'post_title' => 'Rimasta zq online' ) + $old );
		$first = $this->request( 'POST', '/deploy' )->get_data();
		$this->assertIsArray( $first );
		// That version was built an hour ago.
		$wpdb->update( \XB\Versioning\Schema::table( 'deploys' ), array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $first['id'] ) );
		$later = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Nata dopo zq' ) );

		wp_update_post( array( 'ID' => $draft, 'post_status' => 'draft' ) );
		wp_trash_post( $trashed );
		delete_post_meta( $trashed, Deployer::WITHDRAWN_META ); // A trash from before this check: WordPress's own trash meta says it.
		wp_update_post( array( 'ID' => $private, 'post_status' => 'private' ) );
		wp_update_post( array( 'ID' => $later, 'post_status' => 'draft' ) );
		$this->assertNotSame( '', get_post_meta( $draft, Deployer::WITHDRAWN_META, true ) );
		$this->request( 'POST', '/deploy' );

		$row = null;
		foreach ( $this->request( 'GET', '/deploy' )->get_data()['history'] as $deploy ) {
			if ( $deploy['id'] === $first['id'] ) {
				$row = $deploy;
			}
		}
		$this->assertIsArray( $row );
		$this->assertSame( 3, $row['withdrawn']['count'], (string) wp_json_encode( $row['withdrawn'] ) );
		$names = implode( ' ', $row['withdrawn']['items'] );
		foreach ( array( 'Ritirata zq bozza', 'Ritirata zq cestino', 'Ritirata zq privata' ) as $title ) {
			$this->assertStringContainsString( $title, $names );
		}
		$this->assertStringNotContainsString( 'Nata dopo zq', $names, 'not in that version: published after it was built' );
		$this->assertStringNotContainsString( 'Rimasta zq online', $names );

		$back = $this->request( 'POST', '/deploy/' . $first['id'] . '/rollback' );
		$this->assertSame( 200, $back->get_status() );
		$this->assertCount( 1, $back->get_data()['warnings'] );
		$this->assertStringContainsString( 'Ritirata zq privata', $back->get_data()['warnings'][0] );
		$this->assertSame( 3, ( new Deployer() )->withdrawn_since( $back->get_data()['id'] )['count'], 'a rollback serves the files of the version it went back to' );

		// Back online, the content is no longer withdrawn.
		wp_update_post( array( 'ID' => $private, 'post_status' => 'publish' ) );
		$this->assertSame( 2, ( new Deployer() )->withdrawn_since( $first['id'] )['count'] );
	}

	public function test_bundle_delete_stays_inside_the_work_area(): void {
		$outside = wp_upload_dir()['basedir'] . '/xb-keep-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );
		Bundle::delete( $outside );
		Bundle::delete( Bundle::root() . '/../' . basename( $outside ) );
		$this->assertDirectoryExists( $outside );
		rmdir( $outside );
		$this->assertFalse( Bundle::safe_path( '/a?b' ) );
		$this->assertFalse( Bundle::safe_path( '/a#b' ) );
		$this->assertFalse( Bundle::safe_path( '/x.php/y' ) );
	}

	public function test_a_bundle_with_secrets_is_never_deployed(): void {
		$this->configure();
		$this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Fuga di nonce',
				'post_content' => $this->markup( array( $this->block( 'xb/raw', array( 'content' => '<a href="/x?_wpnonce=abcdef1234">x</a>' ) ) ) ),
			)
		);
		$response = $this->request( 'POST', '/deploy' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array(), $this->mock()['versions'] );
		$this->assertSame( 'blocked', ( new Deployer() )->history( 1 )[0]['status'] );
	}

	/** The scanner blocks real tokens and annotations, not the words: a post cannot stop every deploy. */
	public function test_the_scanner_blocks_real_leaks_not_their_names(): void {
		$dir = Bundle::create( 'scanner-words' );
		$write = static function ( string $html ) use ( $dir ): array {
			file_put_contents( $dir . '/index.html', $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return array_column( ( new \XB\StaticSite\Scanner() )->scan( $dir ), 'severity' );
		};
		$this->assertNotContains( 'block', $write( '<p>Il parametro xb_preview= e l’attributo data-xb-uid sono interni a Histeon.</p>' ) );
		$this->assertContains( 'block', $write( '<a href="/?xb_preview=1890000000.12.1.' . str_repeat( 'a', 64 ) . '">x</a>' ) );
		$this->assertContains( 'block', $write( '<div data-xb-uid="abcd1234">x</div>' ) );
		\XB\StaticSite\Bundle::delete( $dir );
		$policy = new \XB\Security\MarkupPolicy();
		$this->assertNotNull( $policy->check_attribute( 'data-xb-uid', 'abcd1234', \XB\Security\MarkupPolicy::TIER_RESTRICTED ), 'authors cannot write the annotation' );
	}

	public function test_shipped_scripts_may_name_the_nonce_header_but_pages_and_uploads_may_not(): void {
		$dir   = Bundle::create( 'scanner-shipped' );
		$rules = static function ( string $relative, string $content ) use ( $dir ): array {
			wp_mkdir_p( dirname( $dir . $relative ) );
			file_put_contents( $dir . $relative, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$found = array_values( array_filter( ( new Scanner() )->scan( $dir ), static fn ( array $f ): bool => $f['file'] === $relative ) );
			wp_delete_file( $dir . $relative );
			return array_column( $found, 'rule' );
		};
		$code = 'r.setRequestHeader("X-WP-Nonce",t.nonce)';
		$this->assertSame( array(), $rules( '/wp-includes/js/dist/api-fetch.min.js', $code ), 'a library WordPress ships names the header' );
		$this->assertSame( array(), $rules( '/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js', $code ) );
		$this->assertSame( array(), $rules( '/wp-content/themes/xb-blank/app.css', '/* _wpnonce= */' ) );
		$this->assertContains( 'nonce WordPress', $rules( '/wp-content/uploads/cache/app.js', 'var a={"nonce":"0123456789"};' ), 'uploads and caches are written at run time' );
		$this->assertContains( 'nonce WordPress', $rules( '/wp-content/plugins/x/page.html', $code ), 'only scripts and styles' );
		$this->assertContains( 'nonce WordPress', $rules( '/index.html', '<script>var a={"nonce":"0123456789"};</script>' ) );
		$this->assertContains( 'percorso del server', $rules( '/wp-content/plugins/x/app.js', rtrim( ABSPATH, '/' ) . '/x' ), 'the other rules still read shipped code' );
		Bundle::delete( $dir );
	}
}
