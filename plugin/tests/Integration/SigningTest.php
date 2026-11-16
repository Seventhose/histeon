<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Versioning\Documents;
use XB\Versioning\Signing;
use XB\Versioning\SiteTransfer;
use XB\Versioning\Transfer;

/** Signed exports between sites (P8, docs/spec/signing.md). */
final class SigningTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Signing::KEY_OPTION, false );
		$this->set_option( Signing::TRUSTED_OPTION, array() );
		$this->set_option( Signing::REQUIRE_OPTION, false );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		parent::tearDown();
	}

	/** @return array<string, mixed> A signed release file of this site. */
	private function release_file(): array {
		$page = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-firma-' . wp_generate_password( 6, false, false ), 'post_title' => 'Firmata' ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Testo firmato' ) ) ) ) ) )->get_status() );
		$response = $this->publish( array( Documents::for_post( $page ) ) );
		$this->assertSame( 200, $response->get_status() );
		$file = Transfer::instance()->export( (int) $response->get_data()['id'] );
		// Only this test's page (the dev site holds other tests' documents too), signed again as the site would.
		$post              = get_post( $page );
		$name              = $post instanceof \WP_Post ? $post->post_name : '';
		$file['documents'] = array_values( array_filter( $file['documents'], static fn ( array $d ): bool => 'post' === $d['kind'] && $name === $d['key'] ) );
		$file['hash']      = hash( 'sha256', (string) wp_json_encode( $file['documents'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return Signing::sign( $file );
	}

	/** @return array{0: non-empty-string, 1: string} Secret and public key of another site. */
	private static function other_site(): array {
		$pair = sodium_crypto_sign_keypair();
		return array( sodium_crypto_sign_secretkey( $pair ), sodium_crypto_sign_publickey( $pair ) );
	}

	private function refused( callable $call, string $why ): void {
		try {
			$call();
			$this->fail( "accepted: $why" );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_exports_are_signed_with_the_site_key_shown_by_its_fingerprint(): void {
		$file = $this->release_file();
		$this->assertSame( 'ed25519', $file['signature']['alg'] );
		$this->assertSame( base64_encode( Signing::public_key() ), $file['signature']['key'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertMatchesRegularExpression( '/^([0-9A-F]{4} ){15}[0-9A-F]{4}$/', Signing::fingerprint( Signing::public_key() ) );
		$this->assertSame( 'own', Signing::check( $file )['status'] );
		// The private key is stored encrypted, never in clear.
		$stored = get_option( Signing::KEY_OPTION );
		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'v1:', (string) $stored['secret'] );
	}

	public function test_a_file_from_another_site_is_trusted_once_by_choice_and_tampering_is_refused(): void {
		[ $secret, $public ] = self::other_site();
		$file                = Signing::sign_with( array( 'site' => 'https://altro.test/' ) + $this->release_file(), $secret, 'https://altro.test/' );
		$check               = Signing::check( $file );
		$this->assertSame( 'new', $check['status'] );
		$this->assertSame( Signing::fingerprint( $public ), $check['fingerprint'] );
		$this->assertSame( 'https://altro.test/', $check['site'] );

		$plan = Transfer::instance()->import( $file, false );
		$this->assertSame( 'new', $plan['signature']['status'] );
		$this->refused( static fn () => Transfer::instance()->import( $file, true ), 'a new key without trust' );
		Transfer::instance()->import( $file, true, array( 'trust' => true ) );
		$this->assertSame( 'trusted', Signing::check( $file )['status'] );
		Transfer::instance()->import( $file, true );

		// Changed after signing, the hash recomputed as an attacker would: the signature no longer matches.
		$tampered                                          = $file;
		$tampered['documents'][0]['payload']['content']   .= '<!-- wp:xb/text {"uid":"zzzzzzzz","content":"aggiunto"} /-->';
		$tampered['hash']                                  = hash( 'sha256', (string) wp_json_encode( $tampered['documents'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( 'invalid', Signing::check( $tampered )['status'] );
		$this->refused( static fn () => Transfer::instance()->import( $tampered, true, array( 'trust' => true ) ), 'a broken signature' );
	}

	public function test_revocation_refuses_a_key_and_rotation_carries_trust_to_the_next(): void {
		[ $old_secret, $old_public ] = self::other_site();
		$base                        = $this->release_file();
		Signing::trust( $old_public, 'https://altro.test/' );

		// A new key, vouched for by the old one: trusted at once, and the old one stops counting here.
		[ $new_secret, $new_public ] = self::other_site();
		$rotated                     = Signing::sign_with( $base, $new_secret, 'https://altro.test/', array( 'from' => $old_public, 'secret' => $old_secret ) );
		$this->assertSame( 'rotated', Signing::check( $rotated )['status'] );
		Transfer::instance()->import( $rotated, true );
		$this->assertSame( 'trusted', Signing::check( Signing::sign_with( $base, $new_secret, 'https://altro.test/' ) )['status'] );
		$this->assertSame( 'new', Signing::check( Signing::sign_with( $base, $old_secret, 'https://altro.test/' ) )['status'], 'the old key is no longer trusted after the rotation' );

		// Revoked: refused, and a rotation starting from it is worth nothing.
		Signing::revoke( Signing::fingerprint( $new_public ) );
		$this->assertSame( 'revoked', Signing::check( Signing::sign_with( $base, $new_secret, 'https://altro.test/' ) )['status'] );
		$this->refused( static fn () => Transfer::instance()->import( Signing::sign_with( $base, $new_secret, 'https://altro.test/' ), true, array( 'trust' => true ) ), 'a revoked key' );
		[ $third ] = self::other_site();
		$this->assertSame( 'new', Signing::check( Signing::sign_with( $base, $third, 'https://altro.test/', array( 'from' => $new_public, 'secret' => $new_secret ) ) )['status'] );
	}

	public function test_unsigned_files_need_an_explicit_choice_unless_signatures_are_required(): void {
		$file = $this->release_file();
		unset( $file['signature'] );
		$this->assertSame( 'unsigned', Signing::check( $file )['status'] );
		$this->refused( static fn () => Transfer::instance()->import( $file, true ), 'unsigned without a choice' );
		Transfer::instance()->import( $file, true, array( 'allowUnsigned' => true ) );
		update_option( Signing::REQUIRE_OPTION, true );
		$this->refused( static fn () => Transfer::instance()->import( $file, true, array( 'allowUnsigned' => true ) ), 'unsigned when signatures are required' );
	}

	/** Project packages of Histeon Publish carry no signature: «Richiedi la firma» refuses them as files too. */
	public function test_unsigned_project_packages_are_refused_when_signatures_are_required(): void {
		$this->set_option( Signing::REQUIRE_OPTION, get_option( Signing::REQUIRE_OPTION, false ) );
		update_option( Signing::REQUIRE_OPTION, false );
		$this->assertNull( \XB\Admin\ReleasesScreen::project_refused() );
		update_option( Signing::REQUIRE_OPTION, true );
		$this->assertStringContainsString( 'non sono firmati', (string) \XB\Admin\ReleasesScreen::project_refused() );
	}

	public function test_the_site_key_rotates_and_vouches_for_the_new_one(): void {
		$old = Signing::public_key();
		Signing::rotate();
		$this->assertNotSame( $old, Signing::public_key() );
		$file = $this->release_file();
		$this->assertSame( base64_encode( $old ), $file['signature']['rotation']['from'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( 'own', Signing::check( $file )['status'] );
	}

	public function test_site_files_are_signed_with_the_hash_of_each_media(): void {
		$upload = wp_upload_bits( 'xb-firma-' . wp_generate_password( 6, false, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true ) );
		$media  = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'pixel', 'post_status' => 'inherit' ), $upload['file'] );
		$this->track( $media );
		$this->files[] = (string) $upload['file'];
		$page          = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-firma-sito', 'post_title' => 'Con immagine' ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'img', array( 'src' => (string) $upload['url'], 'alt' => '' ) ) ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( array( Documents::for_post( $page ) ) )->get_status() );

		$path          = (string) tempnam( get_temp_dir(), 'xb-firma' );
		$this->files[] = $path;
		SiteTransfer::instance()->export( $path, array( Documents::for_post( $page ) ) );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$manifest = json_decode( (string) $zip->getFromName( 'site.json' ), true );
		$this->assertSame( 'ed25519', $manifest['signature']['alg'] );
		$this->assertNotEmpty( $manifest['media'] );
		$this->assertSame( 64, strlen( (string) $manifest['media'][0]['sha256'] ) );
		$this->assertSame( 'own', SiteTransfer::instance()->import( $path, false )['signature']['status'] );

		// A media file swapped inside the archive: refused, the manifest says otherwise.
		$zip->addFromString( $manifest['media'][0]['file'], 'GIF89a cambiato' );
		$zip->close();
		$this->refused( static fn () => SiteTransfer::instance()->import( $path, true ), 'a swapped media file' );
	}

	/** Note 95 of the 3 November audit: the site named is the signed one, not the label next to the signature. */
	public function test_the_site_shown_is_the_one_the_signature_covers(): void {
		[ $secret ] = self::other_site();
		$file       = Signing::sign_with( array( 'format' => 'xb-release', 'site' => 'https://vero.test/', 'documents' => array() ), $secret, 'https://vero.test/' );
		$file['signature']['site'] = 'https://banca.example/';
		$check = Signing::check( $file );
		$this->assertSame( 'new', $check['status'], 'the label is outside the signature' );
		$this->assertSame( 'https://vero.test/', $check['site'] );
	}

	/** Note 96 of the 3 November audit: a revoked key is never pushed out of the list by newer ones. */
	public function test_revoked_keys_are_never_forgotten(): void {
		[ , $first ] = self::other_site();
		Signing::trust( $first, 'https://vecchio.test/' );
		$this->assertTrue( Signing::revoke( Signing::fingerprint( $first ) ) );
		for ( $i = 0; $i < 201; $i++ ) {
			Signing::trust( random_bytes( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ), 'https://altro' . $i . '.test/' );
		}
		$keys = Signing::trusted();
		$this->assertContains( Signing::fingerprint( $first ), array_column( array_filter( $keys, static fn ( array $k ): bool => $k['revoked'] ), 'fingerprint' ) );
		$this->assertCount( 200, array_filter( $keys, static fn ( array $k ): bool => ! $k['revoked'] ) );
	}

	/** Note 94 of the 3 November audit: a file that cannot be encoded is never «signed» over an empty body. */
	public function test_a_file_that_cannot_be_encoded_is_not_signed(): void {
		[ $secret ] = self::other_site();
		$this->expectException( \JsonException::class );
		Signing::sign_with( array( 'format' => 'xb-release', 'n' => INF ), $secret, 'https://vero.test/' );
	}

	/** Note 98 of the 3 November audit: a key the site cannot read is said, and replaced by a new one on request. */
	public function test_an_unreadable_key_is_reported_and_replaced(): void {
		$this->assertNotSame( '', Signing::public_key() );
		$this->assertFalse( Signing::unreadable() );
		$stored           = get_option( Signing::KEY_OPTION );
		$stored['secret'] = 'illeggibile';
		update_option( Signing::KEY_OPTION, $stored, false );
		$this->assertTrue( Signing::unreadable() );
		$this->assertSame( array( 'x' => 1 ), Signing::sign( array( 'x' => 1 ) ), 'unsigned, as the notice says' );
		ob_start();
		Signing::notice();
		$this->assertStringContainsString( 'notice-warning', (string) ob_get_clean() );
		Signing::rotate();
		$this->assertFalse( Signing::unreadable() );
		$this->assertNotSame( '', Signing::public_key() );
	}
}
