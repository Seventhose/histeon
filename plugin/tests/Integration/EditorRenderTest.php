<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** What Gutenberg shows of XB content: rendered by the server as the site renders it (ADR 0010, U12). */
final class EditorRenderTest extends IntegrationTestCase {

	public function test_renders_expressions_and_branches_for_whoever_edits_the_post(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Titolo zqer' ) );
		$items = array(
			'title' => $this->markup( array( $this->el( 'h1', array( 'class' => 'x-{this.id}' ), array( $this->text( '{this.title}' ) ) ) ) ),
		);
		$tests = array(
			'yes' => 'this.title',
			'no'  => 'this.nothing_here',
		);

		$editor = $this->user( 'editor' ); // No xb_build: clients edit texts in Gutenberg and must see the page.
		wp_set_current_user( $editor );
		$res = $this->request( 'POST', '/editor-render', array( 'post_id' => $page, 'items' => $items, 'tests' => $tests ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertStringContainsString( 'Titolo zqer', (string) $data['items']->title );
		$this->assertStringContainsString( 'class="x-' . $page . '"', (string) $data['items']->title );
		$this->assertStringNotContainsString( 'data-xb-uid', (string) $data['items']->title, 'not the builder preview' );
		$this->assertTrue( $data['tests']->yes );
		$this->assertFalse( $data['tests']->no );
	}

	public function test_only_for_editors_of_that_post_and_with_their_trust(): void {
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$hostile = $this->markup( array( $this->el( 'div', array( 'onclick' => 'alert(1)' ), array( $this->text( 'x' ) ) ) ) );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'POST', '/editor-render', array( 'post_id' => $page, 'items' => array( 'a' => $hostile ) ) )->get_status() );

		$author = $this->user( 'author' );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->request( 'POST', '/editor-render', array( 'post_id' => $page, 'items' => array( 'a' => $hostile ) ) )->get_status(), 'someone else\'s page' );
		$own = $this->post( array( 'post_type' => 'post', 'post_author' => $author ) );
		$res = $this->request( 'POST', '/editor-render', array( 'post_id' => $own, 'items' => array( 'a' => $hostile, '../bad key' => $hostile ) ) );
		$this->assertSame( 200, $res->get_status() );
		$items = (array) $res->get_data()['items'];
		$this->assertStringNotContainsString( 'onclick', (string) $items['a'], 'filtered as a save by this author would be' );
		$this->assertArrayNotHasKey( '../bad key', $items );

		$many = array_fill_keys( array_map( static fn ( int $i ): string => 'k' . $i, range( 1, 401 ) ), '' );
		$this->assertSame( 413, $this->request( 'POST', '/editor-render', array( 'post_id' => $own, 'items' => $many ) )->get_status() );
	}

	/** Lista della spesa 149: the answer carries what the renderings depend on, and a changed component changes it. */
	public function test_the_render_version_follows_the_published_components(): void {
		$own   = $this->post( array( 'post_type' => 'page' ) );
		$first = $this->request( 'POST', '/editor-render', array( 'post_id' => $own, 'items' => array() ) )->get_data();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $first['version'] );
		$id = $this->component( 'e2e/versione', array( $this->el( 'p', array(), array( $this->text( 'Prima' ) ) ) ) );
		$second = $this->request( 'POST', '/editor-render', array( 'post_id' => $own, 'items' => array() ) )->get_data();
		$this->assertNotSame( $first['version'], $second['version'], 'a new component' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'ID' => $id ) );
		$this->assertNotSame( $second['version'], \XB\Render\ComponentRegistry::render_version(), 'a component saved again' );
	}
}
