<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Expr\SyntaxError;
use XB\Expr\Template;

final class TemplateTest extends TestCase {

	public function test_splits_literals_interpolations_and_escapes(): void {
		$this->assertSame(
			array( array( 'literal', 'Ciao ' ), array( 'expr', 'user.name ?? "}"' ), array( 'literal', ' {letterale}' ) ),
			Template::split( 'Ciao {user.name ?? "}"} \{letterale\}' )
		);
	}

	public function test_single_interpolation(): void {
		$this->assertTrue( Template::is_single( '{a.b}' ) );
		$this->assertFalse( Template::is_single( '{a} {b}' ) );
		$this->assertFalse( Template::is_single( 'x{a}' ) );
	}

	public function test_unclosed_brace(): void {
		$this->expectException( SyntaxError::class );
		Template::split( 'a {b' );
	}
}
