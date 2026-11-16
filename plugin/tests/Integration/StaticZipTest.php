<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\ReleasesScreen;

/** The published site as a zip from the Releases screen — docs/spec/static.md §7. */
final class StaticZipTest extends IntegrationTestCase {

	public function test_zip_holds_the_public_pages_and_nothing_else(): void {
		if ( ! defined( 'XB_EXPORT_ORIGIN' ) ) {
			$this->markTestSkipped( 'XB_EXPORT_ORIGIN non definita nel WordPress di sviluppo.' );
		}
		$public = $this->post( array( 'post_type' => 'page', 'post_title' => 'Pagina zip pubblica', 'post_name' => 'xbt-zip-pubblica' ) );
		$this->post( array( 'post_type' => 'page', 'post_title' => 'Bozza zip', 'post_name' => 'xbt-zip-bozza', 'post_status' => 'draft' ) );
		$zip = ReleasesScreen::build_zip();
		try {
			$this->assertFileExists( $zip['file'] );
			$this->assertStringEndsWith( '.zip', $zip['name'] );
			$archive = new \ZipArchive();
			$this->assertTrue( $archive->open( $zip['file'] ) );
			$names = array();
			for ( $i = 0; $i < $archive->numFiles; $i++ ) {
				$names[] = (string) $archive->getNameIndex( $i );
			}
			$this->assertContains( 'index.html', $names );
			$this->assertContains( 'xbt-zip-pubblica/index.html', $names );
			$this->assertNotContains( 'xbt-zip-bozza/index.html', $names, 'drafts are not public' );
			$this->assertSame( array(), array_filter( $names, static fn ( string $n ): bool => str_starts_with( basename( $n ), '.' ) || str_ends_with( $n, '.php' ) ), 'no dotfiles, no PHP' );
			$this->assertStringContainsString( 'Pagina zip pubblica', (string) $archive->getFromName( 'xbt-zip-pubblica/index.html' ) );
			$archive->close();
			$this->assertSame( array(), glob( dirname( $zip['file'] ) . '/zip-*', GLOB_ONLYDIR ) ?: array(), 'the bundle folder is gone' );
		} finally {
			wp_delete_file( $zip['file'] );
		}
		unset( $public );
	}

	/** Note 60 of the 3 November audit: a build that stops halfway leaves no folder of pages behind. */
	public function test_a_failed_build_leaves_nothing_in_the_work_area(): void {
		$before = glob( \XB\StaticSite\Bundle::root() . '/*', GLOB_ONLYDIR ) ?: array();
		$fail   = static function (): never {
			throw new \RuntimeException( 'interrotto' );
		};
		add_filter( 'xb_static_paths', $fail );
		try {
			( new \XB\StaticSite\Exporter() )->build( 'fallita' );
			$this->fail( 'the build went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'interrotto', $e->getMessage() );
		} finally {
			remove_filter( 'xb_static_paths', $fail );
		}
		$this->assertSame( $before, glob( \XB\StaticSite\Bundle::root() . '/*', GLOB_ONLYDIR ) ?: array() );
	}
}
