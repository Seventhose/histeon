<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Preview;

/** Lista della spesa 161: a preview seen as a role changes Histeon's `user`, never WordPress's permissions. */
final class PreviewRolesTest extends IntegrationTestCase {

	private function as_visitor( string $url, int $id ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET                   = $query;
		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
		Preview::detect();
		$this->go_to( $id );
		$stored = get_post_field( 'post_content', $id );
		$html   = $this->render( parse_blocks( is_string( $stored ) ? $stored : '' ) );
		$this->assertFalse( current_user_can( 'edit_posts' ), 'the request is anonymous for WordPress' );
		Preview::reset();
		$_GET = array();
		wp_set_current_user( 1 );
		return $html;
	}

	public function test_the_page_seen_as_a_guest_a_subscriber_and_the_role_list(): void {
		$page = array(
			$this->block(
				'xb/if',
				array( 'uid' => $this->uid() ),
				array(
					$this->block( 'xb/branch', array( 'uid' => $this->uid(), 'test' => 'user.loggedIn' ), array( $this->el( 'p', array(), array( $this->text( 'Ciao {user.name}, ruolo {user.role}' ) ) ) ) ),
					$this->block( 'xb/branch', array( 'uid' => $this->uid() ), array( $this->el( 'p', array(), array( $this->text( 'Accedi' ) ) ) ) ),
				)
			),
		);
		$id   = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( $page ) ) );
		$roles = Preview::roles();
		$this->assertArrayHasKey( 'guest', $roles );
		$this->assertArrayHasKey( 'subscriber', $roles );
		$this->assertArrayNotHasKey( 'editor', $roles, 'only roles that cannot write' );
		$this->assertStringContainsString( 'Accedi', $this->as_visitor( Preview::url( $id, null, 'guest' ), $id ) );
		$subscriber = $this->as_visitor( Preview::url( $id, null, 'subscriber' ), $id );
		$this->assertStringContainsString( 'ruolo subscriber', $subscriber );
		$this->assertStringContainsString( 'Visitatore di prova', $subscriber );
		// The role is signed: another one in the same token opens nothing (the real, logged-in visitor stays).
		$forged = str_replace( 'as-subscriber', 'as-guest', Preview::url( $id, null, 'subscriber' ) );
		$this->assertSame( null, Preview::validate( (string) ( wp_parse_args( (string) wp_parse_url( $forged, PHP_URL_QUERY ) )[ Preview::PARAM ] ?? '' ) ) );
		$this->assertArrayHasKey( 'roles', $this->request( 'GET', '/preview-link', array( 'post' => $id, 'as' => 'subscriber' ) )->get_data() );
		unset( $roles );
		Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
	}
}
