<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Where a file of the media library is used (E1): the contents that name it, in any of its sizes, and featured images. */
final class MediaUsageTest extends IntegrationTestCase {

	/** @return array{0: int, 1: string} The attachment and its URL without the extension. */
	private function image(): array {
		$upload = wp_upload_bits( 'xb-uso-' . wp_generate_password( 6, false, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'foto', 'post_status' => 'inherit' ), $upload['file'] );
		$this->track( $id );
		return array( $id, substr( (string) $upload['url'], 0, -4 ) );
	}

	public function test_lists_the_contents_that_use_a_file(): void {
		[ $id, $url ] = $this->image();
		$page         = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Con la foto ridotta',
				'post_content' => '<!-- wp:paragraph --><p><img src="' . $url . '-300x150.gif" alt=""></p><!-- /wp:paragraph -->',
			)
		);
		$draft        = $this->post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Bozza con la foto',
				'post_content' => '<p><a href="' . $url . '.gif">foto</a></p>',
			)
		);
		$featured     = $this->post( array( 'post_title' => 'In evidenza' ) );
		set_post_thumbnail( $featured, $id );
		// A file whose name starts the same way is another file.
		$this->post( array( 'post_content' => '<img src="' . $url . '0.gif" alt="">' ) );

		$response = $this->request( 'GET', "/media/$id/usage" );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$ids  = array_column( $data['items'], 'id' );
		sort( $ids );
		$expected = array( $page, $draft, $featured );
		sort( $expected );
		$this->assertSame( $expected, $ids );
		$by_id = array_column( $data['items'], null, 'id' );
		$this->assertSame( 'Con la foto ridotta', $by_id[ $page ]['title'] );
		$this->assertSame( 'draft', $by_id[ $draft ]['status'] );
		$this->assertTrue( $by_id[ $featured ]['featured'] );
		$this->assertSame( 0, $data['hidden'] );
	}

	public function test_needs_upload_rights_and_hides_what_one_cannot_edit(): void {
		[ $id, $url ] = $this->image();
		$this->post( array( 'post_content' => '<img src="' . $url . '.gif" alt="">' ) );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'GET', "/media/$id/usage" )->get_status() );

		// An author sees where it is used in their own contents; the others are only counted.
		wp_set_current_user( $this->user( 'author' ) );
		$data = $this->request( 'GET', "/media/$id/usage" )->get_data();
		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 1, $data['hidden'] );

		wp_set_current_user( 1 );
		$this->assertSame( 404, $this->request( 'GET', '/media/999999/usage' )->get_status() );
	}
}
