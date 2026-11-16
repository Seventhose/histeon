<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Security\StructureLock;

/** A locked structure (lista della spesa 138): editors change texts and pictures only; builders keep every right. */
final class StructureLockTest extends IntegrationTestCase {

	private function page_markup( string $text, string $class = 'title', string $extra = '' ): string {
		return $this->markup(
			array(
				$this->el( 'h1', array( 'class' => $class ), array( $this->text( $text, 'tx000001' ) ), 'el000001' ),
				$this->el( 'img', array( 'src' => 'https://a.test/a.jpg', 'alt' => 'Foto' ), array(), 'el000002' ),
			)
		) . $extra;
	}

	private function raw( int $id ): string {
		clean_post_cache( $id );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		return $post->post_content;
	}

	private function save( int $page, string $content ): int {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
		$request->set_body_params( array( 'content' => $content ) );
		return rest_do_request( $request )->get_status();
	}

	public function test_an_editor_changes_only_texts_on_a_locked_page_and_a_builder_everything(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $this->page_markup( 'Ciao' ) ) );
		update_post_meta( $page, StructureLock::META, true );
		$builder = get_current_user_id();
		$editor  = $this->user( 'editor' );
		wp_set_current_user( $editor );
		$this->assertTrue( StructureLock::applies( $page ) );

		$this->assertSame( 200, $this->save( $page, $this->page_markup( 'Buongiorno' ) ), 'a text may change' );
		$this->assertStringContainsString( 'Buongiorno', $this->raw( $page ) );
		$this->assertSame( 403, $this->save( $page, $this->page_markup( 'Buongiorno', 'altra' ) ), 'a class may not' );
		$this->assertSame( 403, $this->save( $page, $this->page_markup( 'Buongiorno', 'title', '<!-- wp:paragraph --><p>in più</p><!-- /wp:paragraph -->' ) ), 'nor a block added' );
		// Every other way in keeps the stored content.
		wp_update_post( array( 'ID' => $page, 'post_content' => wp_slash( $this->page_markup( 'Buongiorno', 'rotta' ) ) ) );
		$this->assertStringNotContainsString( 'rotta', $this->raw( $page ) );
		// The editor cannot unlock it.
		$unlock = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
		$unlock->set_body_params( array( 'meta' => array( StructureLock::META => false ) ) );
		$this->assertNotSame( 200, rest_do_request( $unlock )->get_status() );
		$this->assertTrue( StructureLock::locked( $page ) );

		// Whoever builds is not held by the lock.
		wp_set_current_user( $builder );
		$this->assertFalse( StructureLock::applies( $page ) );
		$this->assertSame( 200, $this->save( $page, $this->page_markup( 'Buongiorno', 'nuova' ) ) );
		$this->assertStringContainsString( 'nuova', $this->raw( $page ) );
	}

	public function test_an_unlocked_page_is_not_touched(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $this->page_markup( 'Ciao' ) ) );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertFalse( StructureLock::applies( $page ) );
		$this->assertSame( 200, $this->save( $page, $this->page_markup( 'Ciao', 'altra' ) ) );
	}
}
