<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Merge;

/**
 * What goes online is published by a person (threat model T6): the routes that publish, restore or deploy refuse a
 * request authenticated with an application password, the way an agent outside the browser talks to the REST API
 * of WordPress, and still work from the builder (login cookie and `wp_rest` nonce, here a logged-in user).
 * rest_get_authenticated_app_password() reads the global that WordPress sets after an application password login.
 */
final class ReleaseAppPasswordTest extends IntegrationTestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		parent::tearDown();
	}

	private function as_application_password(): void {
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
	}

	private function as_builder(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	/** @return list<array<string, mixed>> */
	private function tree( string $heading, string $extra_class = '' ): array {
		$attrs = '' === $extra_class ? array() : array( 'class' => $extra_class );
		return array(
			$this->el(
				'section',
				$attrs,
				array( $this->el( 'h1', array(), array( $this->text( $heading, 'aptexta1' ) ), 'apagh101' ) ),
				'apsect01'
			),
		);
	}

	private function heading( int $id ): string {
		return Merge::texts( parse_blocks( $this->content( $id ) ) )['aptexta1'] ?? '';
	}

	private function releases(): int {
		return count( Plugin::instance()->versioning->store()->releases() );
	}

	private function assert_refused( \WP_REST_Response $response, string $what ): void {
		$this->assertSame( 403, $response->get_status(), $what );
		$this->assertSame( 'xb_app_password', $response->get_data()['code'] ?? null, $what );
		$this->assertStringContainsString( 'password per applicazioni', (string) ( $response->get_data()['message'] ?? '' ), $what );
	}

	public function test_publishing_now_refuses_application_passwords(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $this->tree( 'Titolo' ) ),
			)
		);
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Titolo nuovo' ) ) )->get_status() );
		$before = $this->releases();

		$this->as_application_password();
		$this->assert_refused( $this->publish( array( 'post:' . $id ) ), 'POST /releases' );
		$this->assert_refused( $this->publish(), 'POST /releases without docs' );
		$this->assertSame( 'Titolo', $this->heading( $id ), 'nothing went online' );
		$this->assertSame( $before, $this->releases(), 'no release was written' );
		$this->assertContains( 'post:' . $id, array_column( Plugin::instance()->versioning->changes(), 'doc' ), 'the change waits' );

		$this->as_builder();
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$this->assertSame( 'Titolo nuovo', $this->heading( $id ) );
		$this->assertSame( $before + 1, $this->releases() );
	}

	public function test_restoring_a_release_refuses_application_passwords(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $this->tree( 'Titolo' ) ),
			)
		);
		$this->save_dev( $id, $this->markup( $this->tree( 'Titolo', 'v1' ) ) );
		$v1 = (int) $this->publish( array( 'post:' . $id ) )->get_data()['id'];
		$this->save_dev( $id, $this->markup( $this->tree( 'Titolo', 'v2' ) ) );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$before = $this->releases();

		$this->as_application_password();
		$this->assert_refused( $this->request( 'POST', "/releases/$v1/restore" ), 'POST /releases/{id}/restore' );
		$this->assertStringContainsString( '"class":"v2"', $this->content( $id ), 'the live page stays as it is' );
		$this->assertSame( $before, $this->releases() );

		$this->as_builder();
		$this->assertSame( 200, $this->request( 'POST', "/releases/$v1/restore" )->get_status() );
		$this->assertStringContainsString( '"class":"v1"', $this->content( $id ) );
	}

	/** The permission callback answers before any work: no Cloudflare mock needed for the refusal. */
	public function test_deploy_and_rollback_refuse_application_passwords(): void {
		$this->as_application_password();
		$this->assert_refused( $this->request( 'POST', '/deploy' ), 'POST /deploy' );
		$this->assert_refused( $this->request( 'POST', '/deploy/1/rollback' ), 'POST /deploy/{id}/rollback' );
		$this->assertSame( 200, $this->request( 'GET', '/deploy' )->get_status(), 'reading the deploy history is not going online' );
		$this->assertSame( 200, $this->request( 'GET', '/releases' )->get_status(), 'nor is reading the releases' );

		// Scheduling was already closed (V2.2): still the same refusal.
		$this->assert_refused( $this->request( 'POST', '/releases/schedule', array( 'at' => '2030-01-01T09:00' ) ), 'POST /releases/schedule' );
	}

	/** BASSA-S5: what a person scheduled is cancelled by a person, not by an agent with an application password. */
	public function test_cancelling_the_scheduled_release_refuses_application_passwords(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $this->tree( 'Titolo' ) ),
			)
		);
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Titolo nuovo' ) ) )->get_status() );
		$this->as_builder();
		$scheduled = $this->request(
			'POST',
			'/releases/schedule',
			array(
				'at'   => wp_date( 'Y-m-d\\TH:i', time() + DAY_IN_SECONDS ),
				'docs' => array( 'post:' . $id ),
			)
		);
		$this->assertSame( 200, $scheduled->get_status(), (string) wp_json_encode( $scheduled->get_data() ) );
		$this->assertNotNull( \XB\Versioning\Scheduled::view() );

		$this->as_application_password();
		$this->assert_refused( $this->request( 'DELETE', '/releases/schedule' ), 'DELETE /releases/schedule' );
		$this->assert_refused( $this->request( 'DELETE', '/releases/schedule', array( 'last' => true ) ), 'DELETE /releases/schedule?last' );
		$this->assertNotNull( \XB\Versioning\Scheduled::view(), 'the scheduled release is still there' );

		$this->as_builder();
		$this->assertSame( 200, $this->request( 'DELETE', '/releases/schedule' )->get_status() );
		$this->assertNull( \XB\Versioning\Scheduled::view() );
	}
}
