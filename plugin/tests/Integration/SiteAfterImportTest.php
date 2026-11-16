<?php
/**
 * Doc 23 items 234-235: after a file import, the site as in Publish, when the person ticks it: the project's home
 * as the front page, a «Blog» page for the posts, the posts at /blog/<slug>/ and their old addresses redirected.
 *
 * @package XB
 */

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Cms\FrontPage;

final class SiteAfterImportTest extends IntegrationTestCase {

	private const HOME = '5e1f0c2a-7d4b-4c8e-9a61-3b2d8f0e4c17';

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'category_base', 'tag_base' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );
		update_option( 'category_base', '' );
		update_option( 'tag_base', '' );
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
	}

	/** A published page of the project, as an import leaves it: with its profile identity. */
	private function project_page( string $slug, string $id ): int {
		$post_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ucfirst( $slug ), 'post_name' => $slug ) );
		$this->track( $post_id );
		update_post_meta( $post_id, '_xb_page_profile_id', $id );
		return $post_id;
	}

	public function test_the_offers_say_what_would_change_and_do_only_what_is_ticked(): void {
		$home = $this->project_page( 'home', self::HOME );
		$site = array( 'homePage' => array( 'id' => self::HOME, 'title' => 'Home' ), 'blogPage' => null, 'posts' => 1 );
		$offers = FrontPage::offers( $site );
		$this->assertSame( array( 'home' => 'Home', 'blog' => null, 'on' => true ), $offers['front'] ?? null );
		$this->assertSame( array( 'on' => true ), $offers['blog'] ?? null );
		$this->assertSame( array( 'current' => '/%postname%/', 'on' => true ), $offers['permalinks'] ?? null );

		// Nothing ticked: nothing changes.
		$this->assertSame( array(), FrontPage::after_import( $site, array() ) );
		$this->assertSame( 'posts', get_option( 'show_on_front' ) );

		$said = FrontPage::after_import( $site, array( 'front' => true, 'blog' => true, 'permalinks' => true ) );
		$this->assertCount( 3, $said );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( $home, (int) get_option( 'page_on_front' ) );
		$blog = get_post( (int) get_option( 'page_for_posts' ) );
		$this->assertInstanceOf( \WP_Post::class, $blog );
		$this->track( $blog->ID );
		$this->assertSame( 'blog', $blog->post_name );
		$this->assertSame( FrontPage::POSTS_PERMALINK, get_option( 'permalink_structure' ) );
		$this->assertSame( 'category', get_option( 'category_base' ) );
		$this->assertSame( 'tag', get_option( 'tag_base' ) );

		$post = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'La stagione', 'post_name' => 'la-stagione' ) );
		$this->track( $post );
		$this->assertSame( home_url( '/blog/la-stagione/' ), get_permalink( $post ) );
		$this->assertSame( home_url( '/category/uncategorized/' ), get_category_link( 1 ) );
		// Everything done: nothing more to offer.
		$this->assertSame( array(), FrontPage::offers( $site ) );
	}

	public function test_a_front_page_of_the_site_s_own_is_offered_but_not_ticked(): void {
		$this->project_page( 'home', self::HOME );
		$other = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Benvenuti', 'post_name' => 'benvenuti' ) );
		$this->track( $other );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $other );
		$offers = FrontPage::offers( array( 'homePage' => array( 'id' => self::HOME, 'title' => 'Home' ), 'blogPage' => null, 'posts' => 0 ) );
		$this->assertFalse( $offers['front']['on'] ?? null );
		$this->assertArrayNotHasKey( 'permalinks', $offers, 'no posts, no address to change' );
	}
}
