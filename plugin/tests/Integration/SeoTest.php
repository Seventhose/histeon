<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Seo\Seo;

/** XB pages for SEO plugins: the rendered page, as a visitor sees it (ADR 0011). */
final class SeoTest extends IntegrationTestCase {

	/** @param array<string, mixed> $args */
	private function seo_page( array $args = array() ): int {
		$img  = home_url( '/wp-content/uploads/zq-seo.jpg' );
		$html = $this->markup(
			array(
				$this->el( 'h1', array( 'class' => 'zq-seo-title' ), array( $this->text( '{this.title}' ) ) ),
				$this->el( 'p', array(), array( $this->text( 'Ciao {user.name}, leggere fa bene.' ) ) ),
				$this->el( 'img', array( 'src' => $img, 'alt' => 'Lettori in piazza' ) ),
				$this->el( 'img', array( 'src' => $img, 'alt' => 'di nuovo' ) ),
				$this->el( 'img', array( 'src' => 'https://elsewhere.test/x.jpg', 'alt' => 'esterna' ) ),
			)
		);
		return $this->post( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina zq SEO', 'post_content' => $html ), $args ) );
	}

	public function test_the_page_as_an_anonymous_visitor_sees_it(): void {
		$admin = get_userdata( get_current_user_id() );
		$this->assertInstanceOf( \WP_User::class, $admin );
		$page = Seo::page( $this->seo_page() );
		$this->assertStringContainsString( 'Pagina zq SEO', $page['text'], 'expressions resolved' );
		$this->assertStringContainsString( 'leggere fa bene', $page['text'] );
		$this->assertStringNotContainsString( $admin->display_name, $page['text'], 'never the logged-in user' );
		$this->assertCount( 1, $page['images'], 'this site only, no repeats' );
		$this->assertSame( 'Lettori in piazza', $page['images'][0]['alt'] );
		$this->assertSame( get_current_user_id(), $admin->ID, 'the user is restored' );
	}

	public function test_a_preview_request_neither_computes_nor_stores_the_shared_copy(): void {
		$id = $this->seo_page();
		\XB\Versioning\Preview::use_dev();
		try {
			$this->assertSame( '', Seo::page( $id )['text'], 'development content never reaches descriptions' );
		} finally {
			\XB\Versioning\Preview::reset();
		}
		$this->assertStringContainsString( 'leggere fa bene', Seo::page( $id )['text'], 'the live render still works afterwards' );
	}

	public function test_the_shared_copy_ignores_the_query_of_the_request_that_computed_it(): void {
		$id = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina zq query', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Valore [{url.query.zq}]' ) ) ) ) ) ) );
		$_GET['zq'] = 'SCELTO-DAL-VISITATORE';
		try {
			$text = Seo::page( $id )['text'];
		} finally {
			unset( $_GET['zq'] );
		}
		$this->assertStringNotContainsString( 'SCELTO-DAL-VISITATORE', $text );
		$this->assertStringContainsString( 'Valore []', $text );
	}

	/** … nor its address: url.path is the page's own. */
	public function test_the_shared_copy_ignores_the_address_of_the_request_that_computed_it(): void {
		$id       = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'zq-percorso', 'post_title' => 'Pagina zq percorso', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Percorso [{url.path}]' ) ) ) ) ) ) );
		$previous = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/cerca/ATTACCANTE-SCELTO/';
		try {
			$text = Seo::page( $id )['text'];
			$this->assertSame( '/cerca/ATTACCANTE-SCELTO/', $_SERVER['REQUEST_URI'], 'the request keeps its own address' );
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous;
			}
		}
		$this->assertStringNotContainsString( 'ATTACCANTE', $text );
		$this->assertStringContainsString( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ), $text );
	}

	/** doc 23, voce 174: `page` is the page summarised, not the archive or page of the request that computed the copy. */
	public function test_the_shared_copy_reads_its_own_page_context(): void {
		$cat = wp_insert_term( 'Archivio zq SEO', 'category' );
		$this->assertIsArray( $cat );
		$other = $this->post( array( 'post_title' => 'Articolo zq altrui', 'post_category' => array( (int) $cat['term_id'] ) ) );
		$id    = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pagina zq contesto', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Titolo [{page.title}] [{props.page.title}] [{this.title}]' ) ) ) ) ) ) );
		$GLOBALS['wp_query']     = new \WP_Query( array( 'cat' => (int) $cat['term_id'] ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $other );
		try {
			$this->assertTrue( is_category() );
			$text = Seo::page( $id )['text'];
		} finally {
			wp_delete_term( (int) $cat['term_id'], 'category' );
		}
		$this->assertStringContainsString( 'Titolo [Pagina zq contesto] [Pagina zq contesto] [Pagina zq contesto]', $text );
		$this->assertStringNotContainsString( 'Archivio zq SEO', $text );
		$current = get_post();
		$this->assertInstanceOf( \WP_Post::class, $current );
		$this->assertSame( $other, $current->ID, 'the request keeps its own post' );
	}

	public function test_rendering_for_seo_does_not_add_css_to_the_current_page(): void {
		$styles = Plugin::instance()->styles;
		$styles->reset();
		Seo::page( $this->seo_page() );
		$this->assertFalse( $styles->uses( 'zq-seo-title' ) );
	}

	public function test_wordpress_excerpt_and_the_plugins_get_the_text_and_images(): void {
		$id = $this->seo_page();
		$this->assertStringContainsString( 'leggere fa bene', get_the_excerpt( $id ) );
		$written = $this->seo_page( array( 'post_excerpt' => 'Scritto a mano' ) );
		$this->assertSame( 'Scritto a mano', get_the_excerpt( $written ), 'a written excerpt wins' );

		$existing = array( array( 'src' => home_url( '/wp-content/uploads/zq-seo.jpg' ) ), array( 'src' => home_url( '/altra.jpg' ) ) );
		$this->assertCount( 2, apply_filters( 'wpseo_sitemap_urlimages', $existing, $id ), 'Yoast: no duplicates' );
		$this->assertCount( 1, apply_filters( 'rank_math/sitemap/urlimages', array(), $id ), 'Rank Math' );

		$this->assertContains( 'xb/element', apply_filters( 'slim_seo_allowed_blocks', array( 'core/paragraph' ) ) );
		ob_start();
		do_action( 'slim_seo_sitemap_post', get_post( $id ) );
		$this->assertStringContainsString( '<image:loc>' . esc_url( home_url( '/wp-content/uploads/zq-seo.jpg' ) ) . '</image:loc>', (string) ob_get_clean() );

		$this->assertStringContainsString( 'leggere fa bene', (string) apply_filters( 'the_seo_framework_get_excerpt', '', array( 'id' => $id ) ) );
		$this->assertSame( 'Già pronto', apply_filters( 'the_seo_framework_get_excerpt', 'Già pronto', array( 'id' => $id ) ) );
		$params = apply_filters( 'the_seo_framework_image_generation_params', array( 'cbs' => array() ), array( 'id' => $id ), 'social' );
		$this->assertArrayHasKey( 'xb', $params['cbs'] );
		$this->assertSame( array( array( 'url' => home_url( '/wp-content/uploads/zq-seo.jpg' ) ) ), iterator_to_array( call_user_func( $params['cbs']['xb'], array( 'id' => $id ) ), false ) );
	}

	public function test_nothing_for_protected_or_non_xb_content(): void {
		$protected = $this->seo_page( array( 'post_password' => 'segreto' ) );
		$this->assertSame( '', Seo::page( $protected )['text'] );
		$plain = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Normale</p>' ) );
		$this->assertSame( array(), Seo::page( $plain )['images'] );
		$this->assertSame( array( array( 'src' => 'x' ) ), apply_filters( 'wpseo_sitemap_urlimages', array( array( 'src' => 'x' ) ), $plain ) );
	}
}
