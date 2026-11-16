<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Scripts\Scripts;

final class ScriptsTest extends IntegrationTestCase {

	private function restricted_builder(): int {
		$id   = $this->user( 'author' );
		$user = get_userdata( $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		return $id;
	}

	public function test_trusted_builders_save_scripts_printed_on_the_page(): void {
		$id   = $this->post( array( 'post_type' => 'page' ) );
		$code = "console.log('a\\\\b');\nconst s = '</script><img src=x onerror=alert(1)>';";
		$res  = $this->save_dev( $id, $this->markup( array( $this->text( 'x' ) ) ), array( 'script' => $code ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '', Scripts::get( $id ), 'not live before publishing' );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$this->assertSame( $code, Scripts::get( $id ), 'backslashes survive' );

		$this->go_to( $id );
		ob_start();
		Plugin::instance()->scripts->print_footer();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-xb-script="' . $id . '"', $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ), 'the code cannot close the script element' );
	}

	public function test_restricted_builders_cannot_write_scripts_anywhere(): void {
		$builder = $this->restricted_builder();
		wp_set_current_user( $builder );
		$id  = $this->post(
			array(
				'post_author' => $builder,
				'post_status' => 'draft',
			)
		);
		$doc = $this->request( 'GET', "/documents/$id" )->get_data();
		$this->assertFalse( $doc['scriptEditable'] );

		$res = $this->save_dev( $id, $this->markup( array( $this->text( 'x' ) ) ), array( 'script' => 'alert(1)' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( '', (string) $this->request( 'GET', "/documents/$id" )->get_data()['script'] );

		// Sending the unchanged (empty) script is not an attempt to write it.
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( array( $this->text( 'y' ) ) ), array( 'script' => '' ) )->get_status() );

		// Every other meta API is closed too.
		$this->assertFalse( current_user_can( 'edit_post_meta', $id, Scripts::META ) );
		$this->assertFalse( Scripts::set( $id, 'alert(1)' ) );
		$core = new \WP_REST_Request( 'POST', "/wp/v2/posts/$id" );
		$core->set_param( 'meta', array( Scripts::META => 'alert(1)' ) );
		rest_do_request( $core );
		$this->assertSame( '', Scripts::get( $id ) );
	}

	public function test_publishing_scripts_requires_unfiltered_html_from_the_publisher(): void {
		$id = $this->post( array( 'post_type' => 'page' ) );
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( array( $this->text( 'x' ) ) ), array( 'script' => 'window.ok = 1;' ) )->get_status() );
		$publisher = $this->user( 'editor' );
		$user      = get_userdata( $publisher );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		$user->add_cap( 'xb_publish' );
		wp_set_current_user( $publisher );
		add_filter( 'map_meta_cap', $deny = static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );
		$res = $this->publish( array( 'post:' . $id ) );
		remove_filter( 'map_meta_cap', $deny );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( '', Scripts::get( $id ) );
		$this->assertStringNotContainsString( '"content":"x"', $this->content( $id ), 'the whole release was rolled back' );
	}

	public function test_component_scripts_follow_the_component(): void {
		$component = $this->component( 'js/widget', array( $this->el( 'div', array( 'class' => 'w' ) ) ) );
		$this->assertTrue( Scripts::set( $component, 'window.widget = 1;' ) );
		$this->render( array( $this->block( 'xb/component', array( 'ref' => 'js/widget' ) ) ) );
		ob_start();
		Plugin::instance()->scripts->print_footer();
		$this->assertStringContainsString( 'window.widget = 1;', (string) ob_get_clean() );
	}

	public function test_component_creation_schema_and_keys(): void {
		$created = $this->request(
			'POST',
			'/components',
			array(
				'ref'   => 'test/hero-box',
				'title' => 'Hero <b>box</b>',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = (int) $created->get_data()['id'];
		$this->track( $id );
		$this->assertSame( 'Hero box', get_the_title( $id ) );
		$this->assertSame( 409, $this->request( 'POST', '/components', array( 'ref' => 'test/hero-box', 'title' => 'x' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/components', array( 'ref' => 'Bad Ref', 'title' => 'x' ) )->get_status() );

		$doc  = $created->get_data();
		$save = fn ( array $schema ): \WP_REST_Response => $this->save_dev( $id, $this->markup( array( $this->text( '{props.title}' ) ) ), array( 'schema' => $schema ) );
		$this->assertSame( 200, $save( array( 'props' => array( 'title' => array( 'type' => 'string', 'default' => 'Ciao' ) ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		Plugin::instance()->components->forget();
		$this->assertSame( 'Ciao', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/hero-box' ) ) ) ) );
		$this->assertSame( 400, $save( array( 'props' => array( 'bad-name' => array( 'type' => 'string' ) ) ) )->get_status() );
		$this->assertSame( 400, $save( array( 'props' => array( 'x' => array( 'type' => 'php' ) ) ) )->get_status() );
		$this->assertSame( 400, $save( array( 'props' => array( 'x' => array( 'type' => 'string', 'default' => array( 'nested' ) ) ) ) )->get_status() );
		$this->assertArrayHasKey( 'ref', $doc );

		// A template part of the active theme holds keys in the same namespace: a new component cannot take one.
		$part = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'xb-test-header', 'post_title' => 'Header', 'post_content' => '' ), true );
		$this->assertIsInt( $part );
		$this->track( $part );
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		update_post_meta( $part, \XB\Render\ComponentRegistry::META_KEY, 'test/site-header' );
		$this->assertSame( 409, $this->request( 'POST', '/components', array( 'ref' => 'test/site-header', 'title' => 'x' ) )->get_status() );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/components', array( 'ref' => 'test/other', 'title' => 'x' ) )->get_status() );
	}
}
