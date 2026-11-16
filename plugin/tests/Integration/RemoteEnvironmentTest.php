<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\PageProfileAdapter;

/** Doc 23 item 125: the link tells Histeon Publish the site's PHP and Histeon versions. */
final class RemoteEnvironmentTest extends IntegrationTestCase {

	public function test_capabilities_say_php_and_histeon(): void {
		$answer = PageProfileAdapter::capabilities( array( 'secret' => '', 'user' => 1, 'publish' => true, 'read' => true, 'write' => true, 'label' => '', 'created' => 0, 'last' => 0 ) );
		$this->assertSame( array( 'php' => PHP_VERSION, 'histeon' => XB_VERSION ), $answer['environment'] );
	}

	/** Note 50 of the 3 November audit: plain HTTP only when both the environment and the site's own name are local. */
	public function test_plain_http_needs_a_local_name_too(): void {
		$this->assertSame( 'local', wp_get_environment_type() );
		$this->assertTrue( \XB\Remote\Link::local(), 'the test site is on localhost' );
		foreach ( array( 'http://sito.local' => true, 'http://shop.test/' => true, 'http://app.localhost' => true, 'http://127.0.0.1:8080' => true, 'http://example.com' => false, 'http://staging.example.org' => false, 'http://evil.local.example.com' => false ) as $home => $local ) {
			$filter = static fn (): string => $home;
			add_filter( 'home_url', $filter );
			try {
				$this->assertSame( $local, \XB\Remote\Link::local(), $home );
			} finally {
				remove_filter( 'home_url', $filter );
			}
		}
	}
}
