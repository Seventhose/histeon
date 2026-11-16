<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Framework\Carry;

/** The CSS carried from a framework (Carry::safe_css), checked without WordPress. */
final class CarrySafeCssTest extends TestCase {

	/** Note 106 of the 3 November 2026 audit: a removal cannot rebuild what an earlier step took away. */
	public function test_no_removal_rebuilds_what_it_takes_away(): void {
		$this->assertSame( '\3C /style><b>', Carry::safe_css( '</style><b>' ) );
		$this->assertStringNotContainsString( '</', Carry::safe_css( '<<script/style><b>' ) );
		$this->assertStringNotContainsString( 'expression(', Carry::safe_css( 'width: expreexpression(ssion(alert(1))' ) );
		$this->assertStringNotContainsString( 'javascript:', strtolower( Carry::safe_css( 'url(javajavascript:script:x)' ) ) );
		$this->assertSame( '.a{color:red}', Carry::safe_css( '.a{color:red}' ) );
	}
}
