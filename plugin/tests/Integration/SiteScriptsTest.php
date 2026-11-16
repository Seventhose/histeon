<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Scripts\SiteScripts;
use XB\Versioning\Documents;

/** The site's own scripts (lista della spesa 148): checked, versioned, printed by scope, never from an untrusted write. */
final class SiteScriptsTest extends IntegrationTestCase {

	private const SRI = 'sha384-oqVuAfXRKap7fdgcCY5uykM6+R9GqQ8K/uxy9rx7HNQlGYl1kPzQho1wx4JwY8wC';

	public function test_addresses_and_integrity_are_checked(): void {
		$ok = SiteScripts::validate(
			array(
				array( 'id' => 'mappa', 'src' => home_url( '/wp-content/uploads/js/mappa.js' ), 'scope' => 'site', 'position' => 'footer', 'strategy' => 'defer' ),
				array( 'id' => 'gsap', 'src' => 'https://cdn.example.com/gsap.min.js', 'integrity' => self::SRI, 'scope' => 'type:page', 'position' => 'head', 'strategy' => 'async' ),
				array( 'id' => 'locale', 'src' => '/wp-content/uploads/js/x.mjs', 'strategy' => 'module' ),
			)
		);
		$this->assertCount( 3, $ok );
		foreach (
			array(
				array( array( 'id' => 'x', 'src' => 'http://altro.example/x.js' ) ),
				array( array( 'id' => 'x', 'src' => 'https://cdn.example.com/x.js' ) ),
				array( array( 'id' => 'x', 'src' => 'javascript:alert(1)' ) ),
				array( array( 'id' => 'x', 'src' => '/../../etc/x.js' ) ),
				array( array( 'id' => 'x', 'src' => 'https://user:pw@cdn.example.com/x.js', 'integrity' => self::SRI ) ),
				array( array( 'id' => 'X Y', 'src' => '/a.js' ) ),
				array( array( 'id' => 'a', 'src' => '/a.js' ), array( 'id' => 'a', 'src' => '/b.js' ) ),
				array( array( 'id' => 'a', 'src' => '/a.js', 'scope' => 'everywhere' ) ),
			) as $bad
		) {
			try {
				SiteScripts::validate( $bad );
				$this->fail( 'accettato: ' . wp_json_encode( $bad ) );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$attrs = SiteScripts::attributes( $ok[1] );
		$this->assertSame( array( 'id' => 'xb-site-script-gsap', 'src' => 'https://cdn.example.com/gsap.min.js', 'async' => true, 'integrity' => self::SRI, 'crossorigin' => 'anonymous' ), $attrs );
		$this->assertSame( 'module', SiteScripts::attributes( $ok[2] )['type'] );
	}

	public function test_saved_in_development_published_with_a_release_and_printed_by_scope(): void {
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::SETTINGS );
		$payload    = $copy['payload'];
		$payload[ SiteScripts::KEY ] = array( array( 'id' => 'solo-pagine', 'src' => '/wp-content/uploads/js/p.js', 'scope' => 'type:page', 'position' => 'footer', 'strategy' => 'defer' ) );
		$versioning->save_dev( Documents::SETTINGS, $payload, (string) $copy['head'], (string) $copy['base'], 'test' );
		$this->assertSame( array(), get_option( SiteScripts::OPTION, array() ), 'development does not change the site' );
		$this->assertSame( 200, $this->publish( array( Documents::SETTINGS ) )->get_status() );
		$this->assertSame( 'solo-pagine', get_option( SiteScripts::OPTION )[0]['id'] );

		$page = $this->post( array( 'post_type' => 'page' ) );
		$this->go_to( $page );
		ob_start();
		SiteScripts::print_footer();
		$footer = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="xb-site-script-solo-pagine"', $footer );
		$this->assertStringContainsString( 'defer', $footer );
		$this->go_to( $this->post() );
		ob_start();
		SiteScripts::print_footer();
		$this->assertSame( '', (string) ob_get_clean(), 'a page-only script is not on a post' );
	}

	public function test_without_unfiltered_html_or_from_an_import_the_scripts_do_not_change(): void {
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::SETTINGS );
		$payload    = $copy['payload'];
		$payload[ SiteScripts::KEY ] = array( array( 'id' => 'intruso', 'src' => '/wp-content/uploads/js/i.js' ) );
		try {
			$versioning->save_dev( Documents::SETTINGS, $payload, (string) $copy['head'], (string) $copy['base'], 'import', false, true );
			$this->fail( 'an untrusted write changed the site scripts' );
		} catch ( \XB\Versioning\VersioningException $e ) {
			$this->assertSame( 403, $e->status );
		}
		// The REST route refuses whoever lacks unfiltered_html.
		$builder = $this->user( 'author' ); // On a single site an Editor has unfiltered_html: an Author does not.
		( new \WP_User( $builder ) )->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		$this->assertContains( $this->request( 'POST', '/site-scripts', array( 'scripts' => $payload[ SiteScripts::KEY ], 'head' => (string) $copy['head'], 'base' => (string) $copy['base'] ) )->get_status(), array( 401, 403 ) );
	}
}
