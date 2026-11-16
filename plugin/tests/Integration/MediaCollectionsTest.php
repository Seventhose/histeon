<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Media\Collections;

/** Media collections — docs/spec/media.md. */
final class MediaCollectionsTest extends IntegrationTestCase {

	public function test_collections_are_private_and_need_upload_files(): void {
		$taxonomy = get_taxonomy( Collections::TAXONOMY );
		$this->assertInstanceOf( \WP_Taxonomy::class, $taxonomy );
		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->publicly_queryable );
		$this->assertFalse( $taxonomy->query_var );
		$this->assertFalse( is_taxonomy_viewable( Collections::TAXONOMY ) );

		$route = '/wp/v2/' . Collections::TAXONOMY;
		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertContains( rest_do_request( $this->req( 'POST', $route, array( 'name' => 'Xbt no' ) ) )->get_status(), array( 401, 403 ) );

		wp_set_current_user( $this->user( 'author' ) );
		$created = rest_do_request( $this->req( 'POST', $route, array( 'name' => 'Xbt raccolta' ) ) );
		$this->assertSame( 201, $created->get_status() );
		$term = (int) $created->get_data()['id'];
		$child = rest_do_request( $this->req( 'POST', $route, array( 'name' => 'Xbt figlia', 'parent' => $term ) ) );
		$this->assertSame( 201, $child->get_status() );
		$this->assertSame( $term, (int) $child->get_data()['parent'] );

		$this->assertSame( 200, rest_do_request( $this->req( 'GET', $route, array() ) )->get_status() );
		// Note 43 of the 3 November audit: the core terms controller would list them to anyone.
		foreach ( array( 0, $this->user( 'subscriber' ) ) as $reader ) {
			wp_set_current_user( $reader );
			$this->assertContains( rest_do_request( $this->req( 'GET', $route, array() ) )->get_status(), array( 401, 403 ) );
			$this->assertContains( rest_do_request( $this->req( 'GET', $route . '/' . $term, array() ) )->get_status(), array( 401, 403 ) );
		}

		wp_set_current_user( 1 );
		wp_delete_term( (int) $child->get_data()['id'], Collections::TAXONOMY );
		wp_delete_term( $term, Collections::TAXONOMY );
	}

	/** @param array<string, mixed> $body */
	private function req( string $method, string $route, array $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}
}
