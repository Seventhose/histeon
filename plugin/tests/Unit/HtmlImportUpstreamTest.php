<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Note 12 of the 3 November 2026 audit: the files «Importa HTML» copies from Histeon Publish are the ones UPSTREAM
 * names, byte for byte.
 */
final class HtmlImportUpstreamTest extends TestCase {

	public function test_the_copied_files_match_their_recorded_hashes(): void {
		$dir      = dirname( __DIR__, 2 ) . '/assets/html-import';
		$upstream = (string) file_get_contents( $dir . '/UPSTREAM' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 3, preg_match_all( '/^([0-9a-f]{64})  (\S+)$/m', $upstream, $rows, PREG_SET_ORDER ) );
		$names = array();
		foreach ( $rows as [ , $hash, $name ] ) {
			$names[] = $name;
			$this->assertSame( $hash, hash_file( 'sha256', $dir . '/' . $name ), $name );
		}
		$this->assertSame( array( 'histos-html-import.js', 'sqlite3.mjs', 'sqlite3.wasm' ), $names );
	}
}
