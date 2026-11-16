<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;
use XB\Templates\Templates;
use XB\Versioning\Preview;

final class TemplatesTest extends IntegrationTestCase {

	/** @param array<string, mixed> $args */
	private function template( string $slug, string $content, array $args = array() ): int {
		$id = $this->post(
			array_merge(
				array(
					'post_type'    => 'wp_template',
					'post_name'    => $slug,
					'post_title'   => $slug,
					'post_content' => $content,
				),
				$args
			)
		);
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		return $id;
	}

	public function test_sample_type_from_slug(): void {
		$cases = array(
			'single'           => 'post',
			'single-post'      => 'post',
			'single-page-x'    => 'page',
			'page'             => 'page',
			'page-about'       => 'page',
			'front-page'       => 'page',
			'singular'         => 'post',
			'archive'          => null,
			'search'           => null,
			'404'              => null,
			'single-unknown-x' => null,
		);
		foreach ( $cases as $slug => $type ) {
			$post = get_post( $this->template( 'xb-test-' . wp_generate_password( 4, false ), '' ) );
			$this->assertInstanceOf( \WP_Post::class, $post );
			$post->post_name = (string) $slug;
			$this->assertSame( $type, Templates::sample_type( $post ), (string) $slug );
		}
	}

	public function test_theme_file_templates_are_written_once(): void {
		$id = Templates::materialize( 'wp_template', get_stylesheet() . '//page' );
		$this->track( $id );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'wp_template', $post->post_type );
		$this->assertSame( 'page', $post->post_name );
		$this->assertSame( array( get_stylesheet() ), wp_get_post_terms( $id, 'wp_theme', array( 'fields' => 'names' ) ) );
		$this->assertStringContainsString( '<!-- wp:', $post->post_content );
		$this->assertSame( $id, Templates::materialize( 'wp_template', get_stylesheet() . '//page' ), 'never duplicated' );
	}

	public function test_materialize_refuses_unknown_templates_and_types(): void {
		foreach ( array( array( 'wp_template', get_stylesheet() . '//nope' ), array( 'wp_template', 'other-theme//page' ), array( 'post', get_stylesheet() . '//page' ) ) as [ $type, $id ] ) {
			try {
				Templates::materialize( $type, $id );
				$this->fail( "$type $id" );
			} catch ( \RuntimeException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_canvas_renders_a_template_with_a_sample_and_restores_globals(): void {
		$sample   = $this->post( array( 'post_title' => 'Articolo campione', 'post_content' => '<!-- wp:paragraph --><p>Corpo del campione</p><!-- /wp:paragraph -->' ) );
		$template = $this->template( 'single', '' );
		$before   = $GLOBALS['post'] ?? null;
		$response = $this->request(
			'POST',
			'/render',
			array(
				'post_id' => $template,
				'context' => $sample,
				'content' => $this->markup( array( $this->el( 'h1', array(), array( $this->text( '{this.title}' ) ) ), $this->block( 'xb/post-content', array() ) ) ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$html = (string) $response->get_data()['html'];
		$this->assertStringContainsString( 'Articolo campione', $html );
		$this->assertStringContainsString( 'Corpo del campione', $html );
		$this->assertSame( $before, $GLOBALS['post'] ?? null, 'globals restored' );
	}

	public function test_only_theme_editors_open_templates(): void {
		$template = $this->template( 'index', '' );
		$this->assertTrue( BuilderScreen::can_open( $template ) );
		$editor = $this->user( 'editor' );
		$user = get_user_by( 'id', $editor );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		$this->assertFalse( BuilderScreen::can_open( $template ), 'templates need edit_theme_options' );
		$this->assertSame( 403, $this->request( 'GET', '/documents/' . $template )->get_status() );
	}

	public function test_preview_tokens_are_scoped(): void {
		$page     = $this->post( array( 'post_type' => 'page', 'post_title' => 'Pagina A' ) );
		$other    = $this->post( array( 'post_type' => 'page', 'post_title' => 'Pagina B' ) );
		$template = $this->template( 'page', '' );
		$visit    = function ( int $token_for, int $url_of ): bool {
			$_GET[ Preview::PARAM ]  = Preview::token( $token_for );
			$_SERVER['REQUEST_URI'] = (string) wp_parse_url( (string) get_permalink( $url_of ), PHP_URL_PATH );
			Preview::detect();
			$active = Preview::active();
			Preview::reset();
			unset( $_GET[ Preview::PARAM ] );
			return $active;
		};
		$this->assertTrue( $visit( $page, $page ) );
		$this->assertFalse( $visit( $page, $other ), "a page's token opens only that page" );
		// Query variables pick the post in WordPress: they cannot point a page's token at another page.
		foreach ( array( array( 'page_id' => (string) $other ), array( 'p' => (string) $other ), array( 'pagename' => get_post_field( 'post_name', $other ) ), array( 'page_id' => (string) $page, 'p' => (string) $other ) ) as $vars ) {
			$_GET += $vars;
			$this->assertFalse( $visit( $page, $page ), 'query override: ' . (string) wp_json_encode( $vars ) );
			$_GET = array_diff_key( $_GET, $vars );
		}
		// A taxonomy's query variable, an author, a date: WordPress would show another post. (A content type's,
		// registered later, is caught after the main query.)
		foreach ( array( array( 'category_name' => 'news' ), array( 'tag' => 'x' ), array( 'author' => '1' ), array( 'm' => '2026' ), array( 'year' => '2026' ) ) as $vars ) {
			$_GET += $vars;
			$this->assertFalse( $visit( $page, $page ), 'another post\'s variable: ' . (string) wp_json_encode( $vars ) );
			$_GET = array_diff_key( $_GET, $vars );
		}
		$_GET['utm_source'] = 'mail';
		$_GET['cerca']      = 'parola';
		$this->assertTrue( $visit( $page, $page ), 'tracking parameters and the page\'s own filters leave the page as it is' );
		unset( $_GET['utm_source'], $_GET['cerca'] );
		$_GET['page_id'] = (string) $page;
		$this->assertTrue( $visit( $page, $page ), 'plain permalinks name the page itself' );
		unset( $_GET['page_id'] );
		$this->assertTrue( $visit( $template, $other ), "a template's token opens the pages it renders" );
		// Note 112 of the 3 November audit: not the REST API, which answers for every post at once.
		$_GET[ Preview::PARAM ]  = Preview::token( $template );
		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( rest_url( 'wp/v2/pages' ), PHP_URL_PATH );
		Preview::detect();
		$this->assertFalse( Preview::active(), 'a template token on /wp-json/' );
		Preview::reset();
		$_GET['rest_route']     = '/wp/v2/pages';
		$_SERVER['REQUEST_URI'] = '/';
		Preview::detect();
		$this->assertFalse( Preview::active(), 'a template token with ?rest_route=' );
		Preview::reset();
		unset( $_GET['rest_route'], $_GET[ Preview::PARAM ] );
		$this->assertStringContainsString( (string) wp_parse_url( (string) get_permalink( $other ), PHP_URL_PATH ), Preview::url( $template, (string) get_permalink( $other ) ) );
	}

	/** In the builder and for an agent, a page someone else is still writing shows as published. */
	public function test_the_builder_shows_development_versions_only_of_what_its_user_may_edit(): void {
		$admin_page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Di altri', 'post_content' => 'pubblicato' ) );
		$this->assertSame( 200, $this->save_dev( $admin_page, $this->markup( array( $this->text( 'bozza segreta' ) ) ) )->get_status() );
		$admin  = get_current_user_id();
		$author = $this->user( 'author' );
		( new \WP_User( $author ) )->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		Preview::use_dev();
		try {
			$this->assertSame( 'pubblicato', Preview::content( 'pubblicato', $admin_page ), 'not editable by this user: as published' );
		} finally {
			Preview::reset();
		}
		wp_set_current_user( $admin );
		Preview::use_dev();
		try {
			$this->assertStringContainsString( 'bozza segreta', Preview::content( 'pubblicato', $admin_page ) );
		} finally {
			Preview::reset();
		}
	}

	public function test_templates_are_versioned_documents(): void {
		$template = $this->template( 'index', $this->markup( array( $this->text( 'prima' ) ) ) );
		$this->assertSame( 200, $this->save_dev( $template, $this->markup( array( $this->text( 'dopo' ) ) ) )->get_status() );
		$this->assertStringContainsString( 'prima', $this->content( $template ), 'dev only until published' );
		$this->assertSame( 200, $this->publish()->get_status() );
		$this->assertStringContainsString( 'dopo', $this->content( $template ) );
	}

	public function test_templates_are_grouped_by_what_they_show(): void {
		register_post_type( 'zq-evento', array( 'public' => true, 'labels' => array( 'name' => 'Eventi zq' ) ) );
		$cases = array(
			array( 'wp_template', 'index', 'site' ),
			array( 'wp_template', 'front-page', 'site' ),
			array( 'wp_template', '404', 'site' ),
			array( 'wp_template', 'page', 'pages' ),
			array( 'wp_template', 'page-chi-siamo', 'pages' ),
			array( 'wp_template', 'single', 'posts' ),
			array( 'wp_template', 'singular', 'posts' ),
			array( 'wp_template', 'single-post-ciao', 'posts' ),
			array( 'wp_template', 'archive', 'archives' ),
			array( 'wp_template', 'author-mario', 'archives' ),
			array( 'wp_template', 'single-zq-evento', 'type:zq-evento' ),
			array( 'wp_template', 'archive-zq-evento', 'type:zq-evento' ),
			array( 'wp_template', 'category-news', 'taxonomies' ),
			array( 'wp_template', 'taxonomy-genere', 'taxonomies' ),
			array( 'wp_template', 'landing-pulita', 'custom' ),
		);
		foreach ( $cases as [ $type, $slug, $group ] ) {
			$this->assertSame( $group, \XB\Templates\Templates::classify( $type, $slug )['key'], $slug );
		}
		$this->assertSame( 'Eventi zq', \XB\Templates\Templates::classify( 'wp_template', 'single-zq-evento' )['label'] );
		$this->assertSame( 'part:header', \XB\Templates\Templates::classify( 'wp_template_part', 'header', 'header' )['key'] );
		$this->assertSame( 'part:footer', \XB\Templates\Templates::classify( 'wp_template_part', 'site-footer', 'footer' )['key'] );
		$this->assertSame( 'part:other', \XB\Templates\Templates::classify( 'wp_template_part', 'sidebar' )['key'] );
		$this->assertLessThan( \XB\Templates\Templates::classify( 'wp_template_part', 'header', 'header' )['order'], \XB\Templates\Templates::classify( 'wp_template', 'index' )['order'], 'templates before parts' );
		unregister_post_type( 'zq-evento' );

		$all = \XB\Templates\Templates::all();
		$this->assertNotEmpty( $all );
		$orders = array_column( $all, 'groupOrder' );
		$sorted = $orders;
		sort( $sorted );
		$this->assertSame( $sorted, $orders, 'listed group by group' );
	}
}
