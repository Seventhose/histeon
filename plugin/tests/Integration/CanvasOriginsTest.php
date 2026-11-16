<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;

/** What the builder's canvas may load (S5): the site's own origins plus those declared in Settings, origins only. */
final class CanvasOriginsTest extends IntegrationTestCase {

	public function test_only_origins_survive_and_the_site_is_always_there(): void {
		$this->assertSame(
			array( 'https://cdn.a.test', 'http://localhost:8080' ),
			BuilderScreen::declared_origins( "https://cdn.a.test/immagini/x.jpg\nhttp://localhost:8080\njavascript:alert(1)\n*\nhttps://b.test;img-src *\nftp://c.test\nhttps://cdn.a.test" )
		);
		$this->set_option( BuilderScreen::CANVAS_ORIGINS, "https://cdn.a.test\nnon-un-indirizzo" );
		$origins = BuilderScreen::canvas_origins();
		$home    = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertStringStartsWith( $home, $origins[0] );
		$this->assertContains( 'https://cdn.a.test', $origins );
		foreach ( $origins as $origin ) {
			$this->assertMatchesRegularExpression( '#^https?://[a-z0-9.-]+(:\d+)?$#', $origin );
		}

		add_filter( 'xb_canvas_origins', static fn ( array $list ): array => array_merge( $list, array( 'https://immagini.example/x', 'data:text/html,x' ) ) );
		$this->assertContains( 'https://immagini.example', BuilderScreen::canvas_origins() );
		$this->assertNotContains( 'data:text/html,x', BuilderScreen::canvas_origins() );
		remove_all_filters( 'xb_canvas_origins' );
	}
}
