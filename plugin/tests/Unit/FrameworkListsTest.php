<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Framework\Lists;

/** Names coming from third-party plugins end up in the editor and in CSS: only clean identifiers pass. */
final class FrameworkListsTest extends TestCase {

	public function test_keeps_identifiers_and_strips_prefixes(): void {
		$this->assertSame( array( 'space-m', 'btn--primary', '-neg', 'primary' ), Lists::names( array( '--space-m', '.btn--primary', '-neg', 'primary', 'primary' ) ) );
	}

	public function test_drops_anything_that_could_break_out(): void {
		$this->assertSame(
			array(),
			Lists::names( array( 'x;}body{color:red', '</style>', 'a b', '', 1, null, array( 'nested' ), str_repeat( 'a', 200 ), '1abc' ) )
		);
	}

	public function test_grouped_requires_string_groups(): void {
		$this->assertSame( array( 'colors' => array( 'primary' ) ), Lists::grouped( array( 'colors' => array( 'primary' ), 0 => array( 'x' ), 'empty' => array() ) ) );
		$this->assertSame( array(), Lists::grouped( 'not an array' ) );
	}
}
