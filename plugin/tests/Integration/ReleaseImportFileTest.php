<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\ReleasesScreen;
use XB\Framework\Frameworks;
use XB\Remote\PageProfileAdapter;
use XB\Versioning\SiteTransfer;

/** Histeon → Release, «Importa un file» (lista P10): one field that recognises the file, and a first push that a fresh site accepts. */
final class ReleaseImportFileTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tearDown();
	}

	private function file( string $bytes ): string {
		$path = (string) wp_tempnam( 'xb-import-file' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $path;
		return $path;
	}

	/** @param array<string, mixed> $manifest */
	private function zip( array $manifest ): string {
		$path = $this->file( '' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( 'site.json', (string) wp_json_encode( $manifest ) );
		$zip->close();
		return $path;
	}

	public function test_the_single_upload_recognises_each_file_from_its_bytes(): void {
		$this->assertSame( 'release', ReleasesScreen::file_kind( $this->file( "\xEF\xBB\xBF {\"format\":\"xb-release\"}" ) ) );
		$this->assertSame( 'site', ReleasesScreen::file_kind( $this->zip( array( 'format' => SiteTransfer::FORMAT, 'version' => SiteTransfer::VERSION ) ) ) );
		$this->assertSame( 'project', ReleasesScreen::file_kind( $this->zip( array( 'format' => SiteTransfer::FORMAT, 'version' => 2, 'profile' => 'histos-static-project-v4' ) ) ) );
		$this->assertSame( '', ReleasesScreen::file_kind( $this->zip( array( 'format' => 'altro', 'version' => 2 ) ) ), 'a ZIP that is not Histeon' );
		$this->assertSame( '', ReleasesScreen::file_kind( $this->zip( array( 'format' => SiteTransfer::FORMAT, 'version' => 9 ) ) ), 'an unknown version' );
		$this->assertSame( '', ReleasesScreen::file_kind( $this->file( "<!doctype html><html></html>" ) ) );
	}

	public function test_the_release_screen_has_one_field_for_every_import(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		ob_start();
		ReleasesScreen::render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'type="file" name="file"' ) );
		$this->assertStringContainsString( 'xb_admin_import_file', $html );
		$this->assertStringNotContainsString( 'name="release"', $html, 'the separate release upload is gone' );
		$this->assertStringNotContainsString( 'name="site"', $html, 'the separate site upload is gone' );
		$this->assertStringContainsString( 'xb_admin_site_export', $html );
		$this->assertStringContainsString( 'xb_admin_cms_file_export', $html );
	}

	public function test_the_framework_saved_by_the_first_run_does_not_stop_the_first_push(): void {
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		delete_option( Frameworks::OPTION );
		$without = PageProfileAdapter::initialization_blockers();
		update_option( Frameworks::OPTION, 'xb-tokens' );
		$this->assertSame( $without, PageProfileAdapter::initialization_blockers(), '«Primo avvio» saves the default framework too: it is a choice, not data' );
		$this->assertSame( array() === $without, PageProfileAdapter::can_initialize_project() );
	}

	public function test_a_blocked_first_push_says_what_it_found(): void {
		$this->set_option( \XB\Styles\StyleRegistry::OPTION_STYLES, array( '.hero' => array( 'css' => 'color:red' ) ) );
		$this->assertContains( 'stili salvati nel pannello CSS', PageProfileAdapter::initialization_blockers() );
		$this->assertFalse( PageProfileAdapter::can_initialize_project() );
	}

	/** Note 13/14 of the 3 November audit: the site file kept between the check and the confirmation is bound to its bytes. */
	public function test_the_kept_site_file_is_bound_to_the_bytes_that_were_checked(): void {
		$site = new \ReflectionMethod( ReleasesScreen::class, 'site_file' );
		$path = trailingslashit( get_temp_dir() ) . 'xb-site-' . bin2hex( random_bytes( 16 ) ) . '.' . SiteTransfer::EXTENSION;
		file_put_contents( $path, 'checked bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $path;
		$hash          = (string) hash_file( 'sha256', $path );
		$this->assertSame( realpath( $path ), $site->invoke( null, array( 'file' => $path, 'sha256' => $hash ) ) );
		$this->assertNull( $site->invoke( null, array( 'file' => $path ) ), 'no hash, no file' );
		file_put_contents( $path, 'other bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertNull( $site->invoke( null, array( 'file' => $path, 'sha256' => $hash ) ), 'bytes changed after the check' );
		$other = trailingslashit( get_temp_dir() ) . 'other-' . bin2hex( random_bytes( 4 ) ) . '.' . SiteTransfer::EXTENSION;
		file_put_contents( $other, 'checked bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $other;
		$this->assertNull( $site->invoke( null, array( 'file' => $other, 'sha256' => $hash ) ), 'a temp file this screen did not name' );
	}
}
