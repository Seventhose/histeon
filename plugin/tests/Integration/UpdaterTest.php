<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Updates\Updater;

/** Updates from GitHub, signed (docs/spec/updates.md). GitHub is replaced by pre_http_request: no network. */
final class UpdaterTest extends IntegrationTestCase {

	/** @var non-empty-string */
	private string $pair;
	private string $id;
	/** @var list<string> */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->pair = sodium_crypto_sign_keypair();
		$this->id   = random_bytes( 8 );
		$this->set_option( Updater::OPTION, false );
		delete_option( Updater::OPTION );
		delete_site_transient( Updater::CACHE );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_site_transient( Updater::CACHE );
		parent::tearDown();
	}

	private function public_key(): string {
		return base64_encode( 'Ed' . $this->id . sodium_crypto_sign_publickey( $this->pair ) );
	}

	private function signature( string $data, string $name ): string {
		$sk      = sodium_crypto_sign_secretkey( $this->pair );
		$sig     = sodium_crypto_sign_detached( sodium_crypto_generichash( $data, '', 64 ), $sk );
		$comment = "timestamp:1\tfile:$name\thashed";
		return "untrusted comment: x\n" . base64_encode( 'ED' . $this->id . $sig ) . "\ntrusted comment: $comment\n" . base64_encode( sodium_crypto_sign_detached( $sig . $comment, $sk ) ) . "\n";
	}

	/** GitHub with one release: its API answer, the ZIP and the signature. */
	private function github( string $version, string $zip, string $sig ): void {
		$base = "https://github.com/acme/histos/releases/download/v$version/";
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) use ( $version, $zip, $sig, $base ) {
				$this->requests[] = $url;
				$body             = match ( $url ) {
					'https://api.github.com/repos/acme/histos/releases/latest' => (string) wp_json_encode(
						array(
							'tag_name' => "v$version",
							'html_url' => "https://github.com/acme/histos/releases/tag/v$version",
							'assets'   => array(
								array( 'name' => "xb-$version.zip", 'browser_download_url' => $base . "xb-$version.zip" ),
								array( 'name' => "xb-$version.zip.minisig", 'browser_download_url' => $base . "xb-$version.zip.minisig" ),
							),
						)
					),
					$base . "xb-$version.zip" => $zip,
					$base . "xb-$version.zip.minisig" => $sig,
					default => null,
				};
				if ( null === $body ) {
					return new \WP_Error( 'blocked', 'no network in tests' );
				}
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $body ); // download_url streams to a file.
				}
				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
			},
			10,
			3
		);
	}

	public function test_off_by_default_and_silent(): void {
		$this->github( '9.0.0', 'zip', 'sig' );
		$this->assertFalse( Updater::settings()['enabled'] );
		$this->assertNull( Updater::latest() );
		$transient = Updater::offer( (object) array( 'response' => array() ) );
		$this->assertSame( array(), $transient->response );
		$this->assertSame( array(), $this->requests, 'no request to GitHub while off' );
	}

	public function test_settings_are_checked(): void {
		foreach ( array( array( true, 'acme/histos', '' ), array( false, 'non valido', '' ), array( false, 'acme/histos', 'RWQ…non una chiave' ) ) as [ $on, $repo, $key ] ) {
			try {
				Updater::save( $on, $repo, $key );
				$this->fail( "accettato: $repo / $key" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		Updater::save( true, 'acme/histos', $this->public_key() );
		$this->assertTrue( Updater::settings()['enabled'] );
	}

	public function test_a_signed_release_is_offered_and_installed_only_if_the_signature_holds(): void {
		$zip = 'PK pacchetto di prova ' . random_bytes( 64 );
		$this->github( '9.1.0', $zip, $this->signature( $zip, 'xb-9.1.0.zip' ) );
		Updater::save( true, 'acme/histos', $this->public_key() );

		$transient = Updater::offer( (object) array( 'response' => array() ) );
		$this->assertSame( '9.1.0', $transient->response['xb/xb.php']->new_version );
		$package = $transient->response['xb/xb.php']->package;
		$this->assertSame( 'https://github.com/acme/histos/releases/download/v9.1.0/xb-9.1.0.zip', $package );

		$file = Updater::download( false, $package );
		$this->assertIsString( $file );
		$this->assertSame( $zip, file_get_contents( $file ), 'the verified file goes to WordPress' );
		wp_delete_file( $file );
		$this->assertSame( 'non nostro', Updater::download( 'non nostro', $package ), 'another handler\'s answer is left alone' );
		$this->assertFalse( Updater::download( false, 'https://example.com/altro.zip' ), 'other packages are WordPress\'s' );
	}

	public function test_a_tampered_or_foreign_release_is_never_installed(): void {
		$zip = 'PK pacchetto vero';
		$this->github( '9.2.0', $zip . ' manomesso', $this->signature( $zip, 'xb-9.2.0.zip' ) );
		Updater::save( true, 'acme/histos', $this->public_key() );
		$result = Updater::download( false, Updater::latest()['zip'] ?? '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'non corrisponde', $result->get_error_message() );

		remove_all_filters( 'pre_http_request' );
		delete_site_transient( Updater::CACHE );
		$this->github( '9.3.0', $zip, $this->signature( $zip, 'xb-1.0.0.zip' ) );
		$result = Updater::download( false, Updater::latest()['zip'] ?? '' );
		$this->assertInstanceOf( \WP_Error::class, $result, 'a genuine signature of another package' );

		// Another signed build of the same version, under the release's asset name: a package without modules, or
		// one whose signed name only contains the version.
		foreach ( array( 'xb-9.4.0-1234567-senza-moduli.zip', 'not-xb-9.4.0.zip', 'xb-9.4.0.zip.old' ) as $name ) {
			remove_all_filters( 'pre_http_request' );
			delete_site_transient( Updater::CACHE );
			$this->github( '9.4.0', $zip, $this->signature( $zip, $name ) );
			$result = Updater::download( false, Updater::latest()['zip'] ?? '' );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
		}
	}

	public function test_an_offered_update_that_no_longer_matches_the_signed_release_is_refused(): void {
		$zip = 'PK pacchetto offerto';
		$this->github( '9.4.0', $zip, $this->signature( $zip, 'xb-9.4.0.zip' ) );
		Updater::save( true, 'acme/histos', $this->public_key() );
		$package = Updater::offer( (object) array( 'response' => array() ) )->response['xb/xb.php']->package;

		// Between the offer and the install the release changes (signature removed, API down, updates switched off).
		remove_all_filters( 'pre_http_request' );
		delete_site_transient( Updater::CACHE );
		add_filter( 'pre_http_request', static fn (): array => array( 'response' => array( 'code' => 403 ), 'body' => '' ) );
		$result = Updater::download( false, $package, null, array( 'plugin' => 'xb/xb.php', 'type' => 'plugin', 'action' => 'update' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'our plugin is never installed without the signature check' );
		$this->assertSame( 'xb_update_signature', $result->get_error_code() );
		$this->assertFalse( Updater::download( false, $package, null, array( 'plugin' => 'altro/altro.php' ) ), 'other plugins stay WordPress\'s' );
	}

	public function test_only_who_updates_plugins_configures_the_update_source(): void {
		$this->assertTrue( Updater::can_configure() );
		$editor = $this->user( 'editor' );
		$previous = get_current_user_id();
		wp_set_current_user( $editor );
		( new \WP_User( $editor ) )->add_cap( 'manage_options' );
		$this->assertFalse( Updater::can_configure(), 'manage_options alone does not choose the code the site installs' );
		wp_set_current_user( $previous );
	}

	public function test_releases_without_a_signed_zip_are_ignored(): void {
		$this->assertNull( Updater::parse( array( 'tag_name' => 'v1.2.3', 'assets' => array( array( 'name' => 'xb-1.2.3.zip', 'browser_download_url' => 'https://github.com/a/b/xb-1.2.3.zip' ) ) ) ) );
		$this->assertNull( Updater::parse( array( 'tag_name' => 'v1.2.3', 'prerelease' => true ) ) );
		$this->assertNull( Updater::parse( array( 'tag_name' => 'v1.2.3', 'assets' => array( array( 'name' => 'xb-1.2.3.zip', 'browser_download_url' => 'http://evil.example/xb.zip' ), array( 'name' => 'xb-1.2.3.zip.minisig', 'browser_download_url' => 'https://github.com/a/b/s' ) ) ) ), 'only from github.com' );
	}
}
