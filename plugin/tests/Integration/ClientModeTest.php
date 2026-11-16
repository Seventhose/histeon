<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;
use XB\Capabilities;
use XB\Security\ContentOnly;

/** Client mode (docs/spec/client-mode.md). */
final class ClientModeTest extends IntegrationTestCase {

	private function page_markup( string $text, string $class = 'title', string $src = 'https://a.test/a.jpg', string $extra = '' ): string {
		return $this->markup(
			array(
				$this->el( 'h1', array( 'class' => $class ), array( $this->text( $text, 'tx000001' ) ), 'el000001' ),
				$this->el( 'img', array( 'src' => $src, 'alt' => 'Foto' ), array(), 'el000002' ),
			)
		) . $extra;
	}

	private function raw( int $id ): string {
		clean_post_cache( $id );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		return $post->post_content;
	}

	private function client(): int {
		Capabilities::grant();
		$id   = $this->user( Capabilities::CLIENT_ROLE );
		$user = get_user_by( 'id', $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertFalse( $user->has_cap( 'xb_build' ) );
		$this->assertFalse( $user->has_cap( 'publish_pages' ) );
		return $id;
	}

	public function test_only_texts_and_pictures_may_change(): void {
		$before = $this->page_markup( 'Ciao {post.title}' );
		$this->assertSame( '', ContentOnly::check( $before, $this->page_markup( 'Buongiorno {post.title}' ) ), 'a text' );
		$this->assertSame( '', ContentOnly::check( $before, $this->page_markup( 'Ciao {post.title}', 'title', 'https://a.test/b.jpg' ) ), 'a picture' );
		foreach (
			array(
				'expression' => $this->page_markup( 'Ciao {user.email}' ),
				'class'      => $this->page_markup( 'Ciao {post.title}', 'title big' ),
				'block'      => $this->page_markup( 'Ciao {post.title}', 'title', 'https://a.test/a.jpg', $this->markup( array( $this->el( 'p', array(), array(), 'el000009' ) ) ) ),
				'dynamic'    => '',
			) as $what => $after
		) {
			if ( 'dynamic' === $what ) {
				$this->assertNotSame( '', ContentOnly::check( $this->page_markup( 'x', 'title', '{post.image}' ), $this->page_markup( 'x', 'title', 'https://a.test/c.jpg' ) ), 'a dynamic image stays dynamic' );
				continue;
			}
			$this->assertNotSame( '', ContentOnly::check( $before, $after ), $what );
		}
	}

	public function test_a_client_saves_texts_in_the_builder_and_nothing_else(): void {
		$page   = $this->post( array( 'post_type' => 'page', 'post_content' => $this->page_markup( 'Titolo' ) ) );
		$client = $this->client();
		wp_set_current_user( $client );
		$this->assertTrue( BuilderScreen::can_open( $page ) );
		$this->assertTrue( Capabilities::content_only() );

		$doc = $this->request( 'GET', '/documents/' . $page );
		$this->assertSame( 200, $doc->get_status() );
		$head = $doc->get_data()['head'];
		$base = $doc->get_data()['base'];
		$save = fn ( string $content, array $extra = array() ): \WP_REST_Response => $this->request( 'POST', '/documents/' . $page, array( 'content' => $content, 'head' => $head, 'base' => $base ) + $extra );

		$this->assertSame( 403, $save( $this->page_markup( 'Titolo', 'title red' ) )->get_status(), 'a class' );
		$this->assertSame( 403, $save( $this->page_markup( 'Titolo {user.email}' ) )->get_status(), 'a new expression' );
		$this->assertSame( 403, $save( $this->page_markup( 'Nuovo' ), array( 'script' => 'alert(1)' ) )->get_status(), 'a script' );
		$ok = $save( $this->page_markup( 'Nuovo titolo' ) );
		$this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertStringContainsString( 'Nuovo titolo', (string) $ok->get_data()['content'] );

		$this->assertSame( 200, $this->request( 'GET', '/styles' )->get_status(), 'reads what draws the page' );
		$this->assertSame( 403, $this->request( 'POST', '/styles', array( 'styles' => array(), 'globals' => array(), 'head' => 'x', 'base' => 'x' ) )->get_status(), 'never writes styles' );
		$this->assertSame( 403, $this->request( 'POST', '/releases', array( 'notes' => 'x' ) )->get_status(), 'never publishes' );
		$this->assertSame( 200, $this->request( 'POST', '/render', array( 'post_id' => $page, 'content' => $this->page_markup( 'Altro testo' ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/render', array( 'post_id' => $page, 'content' => $this->page_markup( '{user.email}' ) ) )->get_status(), 'the preview follows the same rule' );
		$this->assertSame( 403, $this->request( 'POST', '/render', array( 'post_id' => 0, 'content' => $this->page_markup( 'x' ) ) )->get_status() );
	}

	/** List 135: with xb_edit_props, off by default, a client also sets the literal props of simple types. */
	public function test_xb_edit_props_lets_a_client_set_literal_props_and_nothing_more(): void {
		$this->component(
			'ui/scheda',
			array( $this->el( 'article', array(), array( $this->text( '{props.titolo}' ) ) ) ),
			array(
				'titolo' => array( 'type' => 'string' ),
				'tono'   => array( 'type' => 'enum', 'options' => array( 'chiaro', 'scuro' ) ),
				'link'   => array( 'type' => 'url' ),
				'corpo'  => array( 'type' => 'rich-text' ),
			)
		);
		$card   = fn ( array $props ): string => $this->markup( array( $this->block( 'xb/component', array( 'uid' => 'cp000001', 'ref' => 'ui/scheda', 'props' => $props ) ) ) );
		$page   = $this->post( array( 'post_type' => 'page', 'post_content' => $card( array( 'titolo' => 'Prima' ) ) ) );
		$client = $this->client();
		wp_set_current_user( $client );
		$this->assertFalse( Capabilities::content_props(), 'off by default: the client role does not have it' );

		$doc  = $this->request( 'GET', '/documents/' . $page );
		$save = fn ( string $content ): \WP_REST_Response => $this->request( 'POST', '/documents/' . $page, array( 'content' => $content, 'head' => $doc->get_data()['head'], 'base' => $doc->get_data()['base'] ) );
		$this->assertSame( 403, $save( $card( array( 'titolo' => 'Dopo' ) ) )->get_status(), 'without the capability a prop never changes' );

		get_role( Capabilities::CLIENT_ROLE )?->add_cap( Capabilities::EDIT_PROPS );
		try {
			wp_set_current_user( 0 );
			wp_set_current_user( $client );
			$this->assertTrue( Capabilities::content_props() );
			$before = $card( array( 'titolo' => 'Prima' ) );
			$this->assertSame( '', ContentOnly::check( $before, $card( array( 'titolo' => 'Dopo', 'tono' => 'scuro', 'link' => '/contatti/' ) ), true ) );
			foreach (
				array(
					'expression'      => array( 'titolo' => array( 'expr' => 'user.email' ) ),
					'braces'          => array( 'titolo' => '{user.email}' ),
					'option'          => array( 'titolo' => 'Prima', 'tono' => 'rosso' ),
					'scheme'          => array( 'titolo' => 'Prima', 'link' => 'javascript:alert(1)' ),
					'rich text'       => array( 'titolo' => 'Prima', 'corpo' => '<b>x</b>' ),
					'undeclared prop' => array( 'titolo' => 'Prima', 'altro' => 'x' ),
				) as $what => $props
			) {
				$this->assertNotSame( '', ContentOnly::check( $before, $card( $props ), true ), $what );
			}
			$this->assertNotSame( '', ContentOnly::check( $card( array( 'titolo' => array( 'expr' => 'post.title' ) ) ), $card( array( 'titolo' => 'x' ) ), true ), 'a dynamic prop stays dynamic' );
			$this->assertNotSame( '', ContentOnly::check( $before, $card( array( 'titolo' => 'Dopo' ) ) ), 'without the flag props never change' );

			$ok = $save( $card( array( 'titolo' => 'Dopo', 'tono' => 'scuro' ) ) );
			$this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
			$this->assertSame( 403, $this->request( 'POST', '/documents/' . $page, array( 'content' => $card( array( 'titolo' => 'Dopo', 'link' => 'javascript:alert(1)' ) ), 'head' => $ok->get_data()['head'], 'base' => $ok->get_data()['base'] ) )->get_status() );
		} finally {
			get_role( Capabilities::CLIENT_ROLE )?->remove_cap( Capabilities::EDIT_PROPS );
		}
	}

	public function test_outside_the_builder_a_client_cannot_change_histos_content(): void {
		$page   = $this->post( array( 'post_type' => 'page', 'post_title' => 'Prima', 'post_content' => $this->page_markup( 'Titolo' ) ) );
		$stored = $this->raw( $page );
		wp_set_current_user( $this->client() );

		$rest = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
		$rest->set_body_params( array( 'content' => $this->page_markup( 'Titolo', 'title red' ) ) );
		$this->assertSame( 403, rest_do_request( $rest )->get_status(), 'the block editor' );

		$title = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
		$title->set_body_params( array( 'title' => 'Dopo', 'content' => $stored ) );
		$this->assertSame( 200, rest_do_request( $title )->get_status(), 'the title still changes' );
		$this->assertSame( 'Dopo', get_the_title( $page ) );

		wp_update_post( array( 'ID' => $page, 'post_content' => '<p>sostituita</p>' ) );
		$this->assertSame( $stored, $this->raw( $page ), 'the classic editor and quick edit keep the content' );

		wp_set_current_user( 1 );
		wp_update_post( array( 'ID' => $page, 'post_content' => wp_slash( $this->page_markup( 'Admin' ) ) ) );
		$this->assertStringContainsString( 'Admin', $this->raw( $page ), 'builders are not affected' );
	}
}
