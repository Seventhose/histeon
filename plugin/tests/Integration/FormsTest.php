<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Forms\Forms;

final class FormsTest extends IntegrationTestCase {

	/** @param array<string, mixed> $data */
	private function submit( array $data, ?string $signature = null ): \WP_REST_Response {
		$body    = (string) wp_json_encode( $data );
		$request = new \WP_REST_Request( 'POST', '/xb/v1/forms' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-XB-Signature', $signature ?? hash_hmac( 'sha256', $body, Forms::secret() ) );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private function payload( array $fields = array( 'nome' => 'Ada', 'messaggio' => 'Ciao <script>alert(1)</script>' ) ): array {
		return array(
			'form'   => 'contatti',
			'fields' => $fields,
			'ts'     => time(),
			'id'     => wp_generate_uuid4(),
		);
	}

	public function test_valid_submission_is_stored_privately_as_text(): void {
		wp_set_current_user( 0 );
		$response = $this->submit( $this->payload() );
		$this->assertSame( 201, $response->get_status() );
		$posts = get_posts(
			array(
				'post_type'   => Forms::POST_TYPE,
				'post_status' => 'private',
				'numberposts' => 1,
			)
		);
		$this->assertNotEmpty( $posts );
		$this->track( $posts[0]->ID );
		$this->assertStringNotContainsString( '<script>', $posts[0]->post_content );
		$this->assertStringContainsString( 'nome: Ada', $posts[0]->post_content );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertFalse( current_user_can( 'read_post', $posts[0]->ID ), 'personal data: administrators only' );
		$this->assertFalse( current_user_can( 'edit_post', $posts[0]->ID ) );
	}

	public function test_signature_time_window_replay_and_size(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->submit( $this->payload(), 'deadbeef' )->get_status() );

		$old       = $this->payload();
		$old['ts'] = time() - 3600;
		$this->assertSame( 403, $this->submit( $old )->get_status() );

		$once = $this->payload();
		$this->assertSame( 201, $this->submit( $once )->get_status() );
		$this->assertSame( 403, $this->submit( $once )->get_status(), 'replayed' );

		$this->assertSame( 413, $this->submit( $this->payload( array( 'x' => str_repeat( 'a', 70000 ) ) ) )->get_status() );

		foreach ( get_posts( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'private', 'numberposts' => 10 ) ) as $post ) {
			$this->track( $post->ID );
		}
	}

	public function test_secret_is_not_the_stored_value(): void {
		$secret = Forms::secret();
		$this->assertSame( 64, strlen( $secret ) );
		$this->assertNotSame( get_option( Forms::SECRET ), $secret, 'a database leak alone does not reveal the signing key' );
		$this->assertSame( $secret, Forms::secret(), 'stable' );
	}

	public function test_rate_limit_and_cleanup(): void {
		wp_set_current_user( 0 );
		update_option( Forms::RATE_PREFIX . gmdate( 'YmdHi' ), '30', false );
		$this->assertSame( 429, $this->submit( $this->payload() )->get_status() );
		delete_option( Forms::RATE_PREFIX . gmdate( 'YmdHi' ) );
		update_option( Forms::RATE_PREFIX . '200001010000', '5', false );

		add_option( Forms::SEEN_PREFIX . 'old', (string) ( time() - 3600 ), '', false );
		add_option( Forms::SEEN_PREFIX . 'new', (string) time(), '', false );
		Forms::cleanup();
		$this->assertFalse( get_option( Forms::SEEN_PREFIX . 'old' ) );
		$this->assertNotFalse( get_option( Forms::SEEN_PREFIX . 'new' ) );
		$this->assertFalse( get_option( Forms::RATE_PREFIX . '200001010000' ), 'old rate counters are removed' );
		delete_option( Forms::SEEN_PREFIX . 'new' );
	}

	/** Note 8 of the 3 November audit: more replay markers than one batch are all forgotten in one cleanup. */
	public function test_cleanup_forgets_more_than_one_batch(): void {
		global $wpdb;
		$old  = (string) ( time() - 3600 );
		$rows = array();
		for ( $i = 0; $i < 5100; $i++ ) {
			$rows[] = $wpdb->prepare( '(%s, %s, %s)', Forms::SEEN_PREFIX . 'lotto' . $i, $old, 'off' );
		}
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		Forms::cleanup();
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Forms::SEEN_PREFIX . 'lotto' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, $left );
	}

	public function test_privacy_export_and_erase(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 201, $this->submit( $this->payload( array( 'email' => 'ada@example.org', 'messaggio' => 'ciao' ) ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->payload( array( 'email' => 'bob@example.org' ) ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->payload( array( 'email' => 'jada@example.org', 'note' => 'cc ada@example.org.uk' ) ) )->get_status() );
		$this->assertSame( array(), Forms::export_personal_data( 'da@example.org' )['data'], 'no substring matches' );

		$export = Forms::export_personal_data( 'ada@example.org' );
		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString( 'ada@example.org', (string) $export['data'][0]['data'][0]['value'] );
		$this->assertSame( array(), Forms::export_personal_data( 'not-an-email' )['data'] );

		$this->assertTrue( Forms::erase_personal_data( 'ada@example.org' )['items_removed'] );
		$this->assertSame( array(), Forms::export_personal_data( 'ada@example.org' )['data'] );
		$this->assertCount( 1, Forms::export_personal_data( 'bob@example.org' )['data'], 'other people are untouched' );
		$this->assertCount( 1, Forms::export_personal_data( 'JADA@example.org' )['data'], 'similar addresses are untouched; case-insensitive' );

		foreach ( get_posts( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'private', 'numberposts' => 10 ) ) as $post ) {
			$this->track( $post->ID );
		}
	}
}
