<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/**
 * Doc 26 voce 208: on a multisite the administrator of a site has no unfiltered_html. What a super administrator
 * wrote survives that administrator's edits; what the site administrator adds goes through the restricted tier,
 * Etch blocks included; expressions never print a `javascript:` address. Runs on the network of dev/multisite.sh:
 *   COMPOSE_PROJECT_NAME=xb-multisite XB_PORT=8895 dev/test.sh --group multisite
 */
#[\PHPUnit\Framework\Attributes\Group( 'multisite' )]
final class MultisiteSanitizerTest extends IntegrationTestCase {

	protected function setUp(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Serve la rete di dev/multisite.sh.' );
		}
		parent::setUp();
	}

	private const ETCH_TRUSTED = '<!-- wp:etch/element {"tag":"div","attributes":{"class":"banner","onclick":"apri()"},"script":"YWxlcnQoMSk="} /-->';
	private const ETCH_NEW     = '<!-- wp:etch/element {"tag":"div","attributes":{"class":"nuovo","onmouseover":"ruba()","href":"javascript:alert(2)"},"script":"YWxlcnQoMik="} /-->';

	public function test_a_site_administrator_keeps_what_the_network_wrote_and_cannot_add_code(): void {
		$this->assertTrue( is_super_admin( 1 ) && user_can( 1, 'unfiltered_html' ) );
		$admin = $this->user( 'administrator' );
		$this->assertFalse( user_can( $admin, 'unfiltered_html' ), 'a site administrator on a multisite' );

		$text   = $this->text( 'Testo originale' );
		$trusted = array(
			$this->el( 'p', array(), array( $text ), 'p1' ),
			$this->el( 'script', array(), array( $this->text( 'window.misura = 1;', 'js1' ) ), 'sc1' ),
			$this->el( 'a', array( 'href' => '#', 'onclick' => 'traccia()' ), array( $this->text( 'Link', 't2' ) ), 'a1' ),
		);
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $trusted ) . self::ETCH_TRUSTED,
			)
		);
		$this->assertStringContainsString( 'traccia()', $this->content( $id ), 'the network wrote it' );

		// The site administrator changes only a text, through the block editor's save: nothing of the network goes.
		wp_set_current_user( $admin );
		$edited    = $trusted;
		$edited[0] = $this->el( 'p', array(), array( $this->text( 'Testo cambiato', $text['attrs']['uid'] ) ), 'p1' );
		$result    = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $edited ) . self::ETCH_TRUSTED ),
			),
			true
		);
		$this->assertSame( $id, $result );
		$stored = $this->content( $id );
		$this->assertStringContainsString( 'Testo cambiato', $stored );
		$this->assertStringContainsString( 'window.misura = 1;', $stored, 'the trusted <script> stays' );
		$this->assertStringContainsString( 'traccia()', $stored, 'the trusted handler stays' );
		$this->assertStringContainsString( 'YWxlcnQoMSk=', $stored, 'the Etch block keeps its script' );
		$this->assertStringContainsString( 'apri()', $stored );

		// What the site administrator adds is restricted: no handlers, no javascript:, no script, in Histeon and Etch blocks.
		$added   = array_merge(
			$edited,
			array(
				$this->el( 'a', array( 'href' => 'javascript:alert(3)', 'onclick' => 'ruba()' ), array( $this->text( 'Nuovo' ) ) ),
				$this->el( 'script', array(), array( $this->text( 'ruba()' ) ) ),
				$this->block( 'xb/raw', array( 'uid' => $this->uid(), 'content' => '<p>[gallery]</p><script>ruba()</script><img src="x" onerror="ruba()">' ) ),
			)
		);
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $added ) . self::ETCH_TRUSTED . self::ETCH_NEW ),
			)
		);
		$stored = $this->content( $id );
		// What is left of the raw HTML is text: no tags, no handlers.
		$this->assertSame( 2, substr_count( $stored, 'onclick' ), 'only the network\'s two handlers' );
		$this->assertStringNotContainsString( 'onmouseover', $stored );
		$this->assertStringNotContainsString( 'onerror', $stored );
		$this->assertStringNotContainsString( '\\u003cscript', $stored );
		$this->assertSame( 1, substr_count( $stored, '"tag":"script"' ), 'only the network\'s script element' );
		$this->assertStringNotContainsString( 'alert(3)', $stored );
		$this->assertStringNotContainsString( 'alert(2)', $stored );
		$this->assertStringNotContainsString( 'YWxlcnQoMik=', $stored, 'a new Etch block loses its script' );
		$this->assertStringContainsString( 'nuovo', $stored, 'and keeps what is harmless' );
		$this->assertStringContainsString( 'traccia()', $stored, 'the network\'s handler is still there' );
		$this->assertStringContainsString( '[gallery]', $stored );

		// An expression never prints a javascript: address, whoever wrote it.
		wp_set_current_user( 1 );
		$html = $this->render( array( $this->el( 'a', array( 'href' => '{"javascript:alert(4)"}' ), array( $this->text( 'x' ) ) ) ) );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}
}
