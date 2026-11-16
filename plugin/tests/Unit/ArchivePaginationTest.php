<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Data\ArchivePagination;

/**
 * The pure part of the archive pagination fields (docs/spec/templates.md §5): numbers and addresses.
 */
final class ArchivePaginationTest extends TestCase {

	private static function link( int $n ): string {
		return ArchivePagination::page_url( 'https://example.test/libri/', $n, 'page', static fn ( string $p ): string => $p . '/' );
	}

	public function test_first_middle_and_last_page(): void {
		$first = ArchivePagination::fields( 0, 3, 25, self::link( ... ) );
		$this->assertSame( 1, $first['page'], 'not paged is page 1' );
		$this->assertSame( 3, $first['totalPages'] );
		$this->assertSame( 25, $first['totalRecords'] );
		$this->assertSame( '', $first['prevUrl'] );
		$this->assertSame( 'https://example.test/libri/page/2/', $first['nextUrl'] );
		$this->assertSame(
			array(
				array( 'number' => 1, 'url' => 'https://example.test/libri/', 'current' => true ),
				array( 'number' => 2, 'url' => 'https://example.test/libri/page/2/', 'current' => false ),
				array( 'number' => 3, 'url' => 'https://example.test/libri/page/3/', 'current' => false ),
			),
			$first['pageLinks']
		);

		$middle = ArchivePagination::fields( 2, 3, 25, self::link( ... ) );
		$this->assertSame( 'https://example.test/libri/', $middle['prevUrl'], 'page 1 is the archive link itself' );
		$this->assertSame( 'https://example.test/libri/page/3/', $middle['nextUrl'] );
		$this->assertSame( 'https://example.test/libri/page/2/', $middle['url'], 'url is the page shown' );
		$this->assertSame( 'https://example.test/libri/', $first['url'], 'page 1 is the archive link' );
		$this->assertSame( array( false, true, false ), array_column( $middle['pageLinks'], 'current' ) );

		$last = ArchivePagination::fields( 3, 3, 25, self::link( ... ) );
		$this->assertSame( 'https://example.test/libri/page/2/', $last['prevUrl'] );
		$this->assertSame( '', $last['nextUrl'] );
	}

	public function test_an_empty_archive_has_one_page(): void {
		$empty = ArchivePagination::fields( 0, 0, 0, self::link( ... ) );
		$this->assertSame( 1, $empty['totalPages'] );
		$this->assertSame( 0, $empty['totalRecords'] );
		$this->assertSame( '', $empty['prevUrl'] );
		$this->assertSame( '', $empty['nextUrl'] );
		$this->assertSame( array( array( 'number' => 1, 'url' => 'https://example.test/libri/', 'current' => true ) ), $empty['pageLinks'] );
	}

	public function test_page_links_stop_at_the_first_hundred(): void {
		$many = ArchivePagination::fields( 150, 200, 2000, self::link( ... ) );
		$this->assertCount( ArchivePagination::MAX_PAGE_LINKS, $many['pageLinks'] );
		$this->assertSame( 100, $many['pageLinks'][99]['number'] );
		$this->assertNotContains( true, array_column( $many['pageLinks'], 'current' ), 'page 150 is past the listed links' );
		$this->assertSame( 'https://example.test/libri/page/149/', $many['prevUrl'] );
		$this->assertSame( 'https://example.test/libri/page/151/', $many['nextUrl'] );
		$this->assertSame( 'https://example.test/libri/page/150/', $many['url'], 'also past the listed links' );
	}

	public function test_addresses_as_wordpress_writes_them(): void {
		$slash   = static fn ( string $p ): string => $p . '/';
		$noslash = static fn ( string $p ): string => $p;
		$this->assertSame( 'https://example.test/libri/page/2/', ArchivePagination::page_url( 'https://example.test/libri', 2, 'page', $slash ) );
		$this->assertSame( 'https://example.test/libri/page/2', ArchivePagination::page_url( 'https://example.test/libri', 2, 'page', $noslash ), 'a structure without a trailing slash' );
		$this->assertSame( 'https://example.test/pagina/2/', ArchivePagination::page_url( 'https://example.test/', 2, 'pagina', $slash ), 'the site pagination base' );
		$this->assertSame( 'https://example.test/page/2/?post_type=libro', ArchivePagination::page_url( 'https://example.test/?post_type=libro', 2, 'page', $slash ), 'a query string of the archive link stays after the path' );
		// Plain permalinks.
		$this->assertSame( 'https://example.test/?post_type=libro&paged=2', ArchivePagination::page_url( 'https://example.test/?post_type=libro', 2, null, $slash ) );
		$this->assertSame( 'https://example.test/?paged=3', ArchivePagination::page_url( 'https://example.test/', 3, null, $slash ) );
		$this->assertSame( 'https://example.test/?cat=7', ArchivePagination::page_url( 'https://example.test/?cat=7', 1, null, $slash ), 'page 1 is the link unchanged' );
	}
}
