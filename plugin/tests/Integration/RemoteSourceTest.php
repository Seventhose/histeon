<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Data\DataNode;
use XB\Render\RemoteSource;
use XB\Render\Sources;

/** Doc 26 voce 214: a data source read from an address, with its copy and the last good one. */
final class RemoteSourceTest extends IntegrationTestCase {

	private int $calls = 0;
	private int $status = 200;
	private string $body = '{"items":[{"giorno":"lunedì","ore":"9-13"},{"giorno":"martedì","ore":"14-18"}],"altro":1}';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( RemoteSource::HOSTS, array( 'example.org' ) );
		$this->set_option( Sources::OPTION, get_option( Sources::OPTION, array() ) );
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%xb_src_%'" );
		wp_clear_scheduled_hook( RemoteSource::HOOK );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function answer( mixed $pre, array $args, string $url ): array {
		++$this->calls;
		$this->assertStringStartsWith( 'https://example.org/', $url );
		return array(
			'headers'  => array(),
			'body'     => $this->body,
			'response' => array( 'code' => $this->status, 'message' => 'x' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_read_kept_and_served_when_the_service_fails(): void {
		$sources = Sources::validate( array( 'orari' => array( 'type' => 'url', 'url' => 'https://example.org/orari.json', 'path' => 'items', 'ttl' => 1 ) ) );
		$this->assertSame( 5, $sources['orari']['ttl'], 'at least five minutes' );
		update_option( Sources::OPTION, $sources );

		$this->assertSame( 'martedì', ( new DataNode() )->get( 'orari' )[1]['giorno'] );
		$this->assertSame( 1, $this->calls );
		( new DataNode() )->get( 'orari' );
		$this->assertSame( 1, $this->calls, 'the copy is used' );

		delete_transient( 'xb_src_' . md5( 'https://example.org/orari.json|items' ) );
		$this->assertCount( 2, ( new DataNode() )->get( 'orari' ), 'the last good copy, at once' );
		$this->assertSame( 1, $this->calls );
		$this->assertNotFalse( wp_next_scheduled( RemoteSource::HOOK, array( 'https://example.org/orari.json', 'items', 5 ) ), 'read again in the background' );

		$this->status = 500;
		$this->assertCount( 2, RemoteSource::refresh( 'https://example.org/orari.json', 'items', 5 ), 'a failing service leaves the last good copy' );
	}

	public function test_only_https_from_allowed_hosts_and_valid_json(): void {
		foreach ( array( 'http://example.org/x.json', 'https://u:p@example.org/x.json', 'javascript:alert(1)' ) as $url ) {
			try {
				Sources::validate( array( 'x' => array( 'type' => 'url', 'url' => $url ) ) );
				$this->fail( "accepted: $url" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->assertInstanceOf( \WP_Error::class, RemoteSource::fetch( 'https://example.com/x.json' ), 'a host nobody allowed' );
		$this->assertSame( 0, $this->calls, 'never called' );
		$this->body = '{"a":{"__proto__":1}}';
		$this->assertInstanceOf( \WP_Error::class, RemoteSource::fetch( 'https://example.org/x.json' ), 'the limits of written sources' );
		$this->body = 'non è json';
		$this->assertInstanceOf( \WP_Error::class, RemoteSource::fetch( 'https://example.org/x.json' ) );

		$editor = $this->user( 'editor' );
		( new \WP_User( $editor ) )->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->request( 'POST', '/sources/hosts', array( 'hosts' => array( 'evil.example' ) ) )->get_status(), 'only an administrator allows hosts' );
		wp_set_current_user( 1 );
		$this->assertSame( array( 'api.example.org' ), $this->request( 'POST', '/sources/hosts', array( 'hosts' => array( 'API.example.org', 'not a host', '127.0.0.1' ) ) )->get_data()['hosts'] );
	}
}
