<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\Shortcuts;

/** The builder's keyboard shortcuts, per user — docs/spec/shortcuts.md. */
final class ShortcutsTest extends IntegrationTestCase {

	public function test_each_user_keeps_their_own(): void {
		$a = $this->user( 'administrator' );
		$b = $this->user( 'administrator' );
		wp_set_current_user( $a );
		$res = $this->request( 'POST', '/shortcuts', array( 'shortcuts' => array( 'dup' => array( 'Mod+J' ), 'del' => array(), 'comp-xb/accordion' => array( 'Alt+Shift+A' ) ) ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( 'Mod+J' ), Shortcuts::get( $a )['dup'] );
		$this->assertSame( array(), Shortcuts::get( $a )['del'], 'a default turned off stays off' );
		$this->assertSame( array(), Shortcuts::get( $b ) );
		wp_set_current_user( $b );
		$this->assertEquals( (object) array(), $this->request( 'GET', '/shortcuts' )->get_data()['shortcuts'] );
		wp_set_current_user( $a );
		$this->request( 'POST', '/shortcuts', array( 'shortcuts' => array() ) );
		$this->assertSame( '', get_user_meta( $a, Shortcuts::META, true ), 'back to the defaults: nothing stored' );
	}

	public function test_invalid_maps_are_refused(): void {
		$bad = array(
			'reserved'   => array( 'x' => array( 'Mod+C' ) ),
			'unknown'    => array( 'x' => array( 'Mod+Enter' ) ),
			'markup'     => array( 'x' => array( '<script>' ) ),
			'twice'      => array( 'x' => array( 'Alt+J' ), 'y' => array( 'Alt+J' ) ),
			'too many'   => array( 'x' => array( 'Alt+1', 'Alt+2', 'Alt+3', 'Alt+4' ) ),
			'bad id'     => array( '<b>' => array( 'Alt+J' ) ),
			'not a list' => array( 'x' => 'Alt+J' ),
		);
		foreach ( $bad as $case => $map ) {
			$this->assertSame( 400, $this->request( 'POST', '/shortcuts', array( 'shortcuts' => $map ) )->get_status(), $case );
		}
		$many = array();
		for ( $i = 0; $i <= Shortcuts::MAX; $i++ ) {
			$many[ 'cmd' . $i ] = array();
		}
		$this->assertSame( 400, $this->request( 'POST', '/shortcuts', array( 'shortcuts' => $many ) )->get_status() );
	}

	public function test_builders_only(): void {
		wp_set_current_user( $this->user( 'author' ) );
		$this->assertSame( 403, $this->request( 'GET', '/shortcuts' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/shortcuts', array( 'shortcuts' => array() ) )->get_status() );
	}
}
