<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Render\ComponentUsage;
use XB\Versioning\Documents;

/** Lista della spesa 162: a component in use, online or in development, is not deleted. */
final class ComponentUsageTest extends IntegrationTestCase {

	public function test_usages_online_and_in_development_and_the_refusal(): void {
		$ref       = 'e2e/componente-in-uso';
		$component = $this->component( $ref, array( $this->el( 'div', array( 'class' => 'x' ) ) ) );
		$post      = get_post( $component );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( array(), ComponentUsage::usages( $ref ) );
		$this->assertSame( '', ComponentUsage::refusal( $post ) );

		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Usa il componente', 'post_content' => $this->markup( array( $this->block( 'xb/component', array( 'uid' => $this->uid(), 'ref' => $ref ) ) ) ) ) );
		$this->assertSame( array( array( 'id' => $page, 'title' => 'Usa il componente', 'type' => 'page', 'dev' => false ) ), ComponentUsage::usages( $ref ) );

		// Only in development: a page whose saved version does not hold it yet.
		$draft      = $this->post( array( 'post_type' => 'page', 'post_title' => 'Bozza', 'post_content' => $this->markup( array( $this->el( 'p' ) ) ) ) );
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::for_post( $draft ) );
		$payload    = $copy['payload'];
		$payload['content'] = $this->markup( array( $this->block( 'xb/component', array( 'uid' => $this->uid(), 'ref' => $ref ) ) ) );
		$versioning->save_dev( Documents::for_post( $draft ), $payload, (string) $copy['head'], (string) $copy['base'], 'test' );
		$uses = ComponentUsage::usages( $ref );
		$dev  = array();
		foreach ( $uses as $use ) {
			if ( $use['id'] === $draft ) {
				$dev[] = $use['dev'];
			}
		}
		$this->assertSame( array( true ), $dev );
		$why = ComponentUsage::refusal( $post );
		$this->assertStringContainsString( 'Usa il componente', $why );
		$this->assertStringContainsString( 'Bozza (in sviluppo)', $why );

		// The block editor deletes through REST: refused, with the list.
		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/blocks/' . $component );
		$response = rest_do_request( $request );
		$this->assertSame( 409, $response->get_status() );
		$error = $response->as_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertStringContainsString( 'Usa il componente', $error->get_error_message() );
		$this->assertNotNull( get_post( $component ) );
		// Code that deletes on purpose is not stopped (WP-CLI, migrations): this test runs as such.
		$this->assertNull( apply_filters( 'pre_trash_post', null, get_post( $component ), '' ) );
	}
}
