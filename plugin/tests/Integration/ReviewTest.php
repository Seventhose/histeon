<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Review\Comments;
use XB\Versioning\Preview;

/** Comments and approval from the signed preview — docs/spec/review.md. */
final class ReviewTest extends IntegrationTestCase {

	private int $page = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		$this->page  = $this->post( array( 'post_type' => 'page', 'post_title' => 'Da rivedere', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Ciao' ) ) ) ) ) ) );
		$this->token = Preview::token( $this->page );
		foreach ( array( 'xb_rv_site' ) as $key ) {
			delete_transient( $key );
		}
	}

	/** @param array<string, string> $body */
	private function anonymous( string $route, array $body ): \WP_REST_Response {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			return $this->request( 'POST', $route, $body );
		} finally {
			wp_set_current_user( $user );
		}
	}

	public function test_only_a_valid_link_comments_and_the_text_stays_text(): void {
		$ok = $this->anonymous( '/review/comments', array( 'token' => $this->token, 'uid' => 'abc123', 'text' => "<script>alert(1)</script>Più spazio\nqui", 'name' => '<b>Maria</b>' ) );
		$this->assertSame( 201, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 403, $this->anonymous( '/review/comments', array( 'token' => 'x.1.1.y', 'uid' => 'abc123', 'text' => 'no' ) )->get_status() );
		$this->assertSame( 403, $this->anonymous( '/review/comments', array( 'token' => $this->token, 'uid' => 'abc123', 'text' => 'bot', 'email' => 'a@b.c' ) )->get_status(), 'honeypot' );
		$this->assertSame( 400, $this->anonymous( '/review/comments', array( 'token' => $this->token, 'uid' => '../x', 'text' => 'x' ) )->get_status() );

		$list = $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data();
		$this->assertCount( 1, $list['comments'] );
		$c = $list['comments'][0];
		$this->assertSame( 'abc123', $c['uid'] );
		$this->assertSame( 'Maria', $c['author'] );
		$this->assertStringNotContainsString( '<script', $c['text'] );
		$this->assertStringContainsString( "Più spazio\nqui", $c['text'] );
		$this->assertSame( 'open', $c['status'] );

		// Resolved, then deleted, by someone who can build the page; never by a visitor.
		$this->assertSame( 200, $this->request( 'POST', '/review/comments/' . $c['id'], array( 'status' => 'resolved' ) )->get_status() );
		$this->assertSame( 'resolved', $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data()['comments'][0]['status'] );
		$this->assertSame( 401, $this->anonymous( '/review/comments/' . $c['id'], array( 'status' => 'open' ) )->get_status() );
		wp_set_current_user( $this->user( 'author' ) );
		$this->assertSame( 403, $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_status() );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->request( 'DELETE', '/review/comments/' . $c['id'] )->get_status() );
		$this->assertCount( 0, $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data()['comments'] );
	}

	public function test_a_link_cannot_flood_the_list(): void {
		$codes = array();
		for ( $i = 0; $i < 32; $i++ ) {
			$codes[] = $this->anonymous( '/review/comments', array( 'token' => $this->token, 'uid' => 'abc', 'text' => "n. $i" ) )->get_status();
		}
		$this->assertSame( 30, count( array_filter( $codes, static fn ( int $c ): bool => 201 === $c ) ) );
		$this->assertSame( 429, end( $codes ) );
	}

	public function test_approval_says_which_version_was_seen(): void {
		$seen = (string) \XB\Plugin::instance()->versioning->working_copy( \XB\Versioning\Documents::for_post( $this->page ) )['head'];
		$this->assertSame( 400, $this->anonymous( '/review/approve', array( 'token' => $this->token, 'name' => ' ', 'head' => $seen ) )->get_status() );
		$this->assertSame( 200, $this->anonymous( '/review/approve', array( 'token' => $this->token, 'name' => 'Cliente', 'head' => $seen ) )->get_status() );
		$approval = $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data()['approval'];
		$this->assertSame( 'Cliente', $approval['name'] );
		$this->assertTrue( $approval['current'], 'the version in dev now' );
		$this->save_dev( $this->page, $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Cambiato dopo' ) ) ) ) ) );
		$this->assertFalse( $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data()['approval']['current'], 'changed after the approval' );
		$this->assertNotNull( Comments::approval( $this->page ) );
		// Note 113 of the 3 November audit: the version on the reviewer's screen, not one saved since.
		$this->assertSame( 409, $this->anonymous( '/review/approve', array( 'token' => $this->token, 'name' => 'Cliente', 'head' => $seen ) )->get_status() );
		$this->assertFalse( $this->request( 'GET', '/review/comments', array( 'post' => (string) $this->page ) )->get_data()['approval']['current'], 'the stale approval was not recorded as current' );
	}
}
