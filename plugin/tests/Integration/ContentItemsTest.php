<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Before a content type goes away, its contents can be moved to another type or trashed (D3). */
final class ContentItemsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		register_post_type( 'xbt_evento', array( 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Eventi' ) );
		register_post_type( 'xbt_incontro', array( 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'label' => 'Incontri' ) );
	}

	protected function tearDown(): void {
		parent::tearDown();
		unregister_post_type( 'xbt_evento' );
		unregister_post_type( 'xbt_incontro' );
	}

	public function test_the_contents_of_a_type_are_counted_moved_or_trashed(): void {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->post( array( 'post_type' => 'xbt_evento', 'post_status' => 'publish', 'post_title' => 'Evento ' . $i ) );
		}
		$types = array_column( (array) $this->request( 'GET', '/content' )->get_data()['postTypes'], null, 'name' );
		$this->assertSame( 3, $types['xbt_evento']['count'] ?? null, 'the builder can say how many contents a removal leaves behind' );

		$moved = $this->request( 'POST', '/content/items', array( 'type' => 'xbt_evento', 'action' => 'move', 'to' => 'xbt_incontro' ) );
		$this->assertSame( 200, $moved->get_status(), (string) wp_json_encode( $moved->get_data() ) );
		$this->assertSame( array( 'done' => 3, 'skipped' => 0, 'left' => 0 ), $moved->get_data() );
		$this->assertSame( 'xbt_incontro', get_post_type( $ids[0] ) );

		$trashed = $this->request( 'POST', '/content/items', array( 'type' => 'xbt_incontro', 'action' => 'trash' ) );
		$this->assertSame( 3, $trashed->get_data()['done'] );
		$this->assertSame( 'trash', get_post_status( $ids[1] ), 'recoverable, not deleted' );

		$this->assertSame( 400, $this->request( 'POST', '/content/items', array( 'type' => 'page', 'action' => 'trash' ) )->get_status(), 'never pages or posts' );
		$this->assertSame( 400, $this->request( 'POST', '/content/items', array( 'type' => 'xbt_evento', 'action' => 'move', 'to' => 'wp_template' ) )->get_status() );
		$builder = $this->user( 'editor' );
		$user    = get_user_by( 'id', $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		$this->assertSame( 403, $this->request( 'POST', '/content/items', array( 'type' => 'xbt_evento', 'action' => 'trash' ) )->get_status(), 'administrators only' );
	}
}
