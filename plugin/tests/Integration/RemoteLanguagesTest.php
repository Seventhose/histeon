<?php
declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Cms\Bridge;
use XB\Cms\Link;

/**
 * The language links of a transfer from Histeon Publish (docs/spec/multilingual.md).
 */
final class RemoteLanguagesTest extends IntegrationTestCase {
	private const SOURCE = '11111111-1111-4111-8111-111111111111';
	private const TRANSLATION = '22222222-2222-4222-8222-222222222222';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Link::OPTION, get_option( Link::OPTION, false ) );
		delete_option( Link::OPTION );
		Link::create( 'Prova', true, true, false );
	}

	protected function tearDown(): void {
		Link::revoke();
		remove_all_actions( 'xb_remote_language_link' );
		parent::tearDown();
	}

	public function test_a_link_that_cannot_write_is_refused(): void {
		Link::revoke();
		Link::create( 'Solo lettura', true, false, false );
		$response = $this->send( array() );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'xb_cms_scope', $response->get_error_code() );
	}

	private function linked( string $id ): int {
		$post = $this->post( array( 'post_type' => 'page' ) );
		update_post_meta( $post, '_xb_page_profile_id', $id );
		return $post;
	}

	/** @param list<mixed> $links */
	private function send( array $links ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/xb/v1/cms/v1/languages' );
		$request->set_body( (string) wp_json_encode( array( 'links' => $links ) ) );
		return Bridge::languages( $request );
	}

	public function test_a_link_reaches_the_language_plugin(): void {
		$source      = $this->linked( self::SOURCE );
		$translation = $this->linked( self::TRANSLATION );
		$seen        = array();
		$listener    = static function ( int $post, int $original, string $language, string $mode ) use ( &$seen ): void {
			$seen[] = array( $post, $original, $language, $mode );
		};
		add_action( 'xb_remote_language_link', $listener, 10, 4 );
		$response = $this->send( array( array( 'translationId' => self::TRANSLATION, 'sourceId' => self::SOURCE, 'language' => 'pt-BR', 'mode' => 'follow' ) ) );
		remove_action( 'xb_remote_language_link', $listener, 10 );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( array( 'taken' => 1 ), $response->get_data() );
		$this->assertSame( array( array( $translation, $source, 'pt-BR', 'follow' ) ), $seen );
	}

	public function test_without_a_language_plugin_nothing_is_taken(): void {
		$this->linked( self::SOURCE );
		$this->linked( self::TRANSLATION );
		$response = $this->send( array( array( 'translationId' => self::TRANSLATION, 'sourceId' => self::SOURCE, 'language' => 'en', 'mode' => 'follow' ) ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( array( 'taken' => 0 ), $response->get_data() );
	}

	public function test_a_link_to_a_record_waits_for_the_release_that_creates_it(): void {
		$this->set_option( Bridge::WAITING_LANGUAGE_LINKS, array() );
		$source   = $this->linked( self::SOURCE );
		$seen     = array();
		$listener = static function ( int $post, int $original ) use ( &$seen ): void {
			$seen[] = array( $post, $original );
		};
		add_action( 'xb_remote_language_link', $listener, 10, 2 );
		$link     = array( 'translationId' => self::TRANSLATION, 'sourceId' => self::SOURCE, 'language' => 'en', 'mode' => 'follow' );
		$response = $this->send( array( $link ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( array( 'taken' => 1 ), $response->get_data() );
		$this->assertSame( array(), $seen );
		$this->assertSame( array( $link ), get_option( Bridge::WAITING_LANGUAGE_LINKS ) );

		// A release that has not created it yet changes nothing.
		Bridge::apply_waiting_language_links();
		$this->assertSame( array(), $seen );
		$this->assertSame( array( $link ), get_option( Bridge::WAITING_LANGUAGE_LINKS ) );

		$translation = $this->linked( self::TRANSLATION );
		Bridge::apply_waiting_language_links();
		remove_action( 'xb_remote_language_link', $listener, 10 );
		$this->assertSame( array( array( $translation, $source ) ), $seen );
		$this->assertSame( array(), get_option( Bridge::WAITING_LANGUAGE_LINKS ) );
	}

	/** Note 52 of the 3 November audit: links waiting for one link are forgotten when it is revoked or replaced. */
	public function test_waiting_links_end_with_their_link(): void {
		$this->set_option( Bridge::WAITING_LANGUAGE_LINKS, array() );
		$waiting = array( array( 'translationId' => self::TRANSLATION, 'sourceId' => self::SOURCE, 'language' => 'en', 'mode' => 'follow' ) );
		update_option( Bridge::WAITING_LANGUAGE_LINKS, $waiting, false );
		Link::revoke();
		$this->assertFalse( get_option( Bridge::WAITING_LANGUAGE_LINKS ) );
		update_option( Bridge::WAITING_LANGUAGE_LINKS, $waiting, false );
		Link::create( 'Un altro progetto', true, true, false );
		$this->assertFalse( get_option( Bridge::WAITING_LANGUAGE_LINKS ) );
	}

	/** Note 51 of the 3 November audit: two posts with one identity are neither of them. */
	public function test_a_duplicated_identity_names_no_post(): void {
		$first = $this->linked( self::SOURCE );
		$this->assertSame( $first, \XB\Remote\PageProfileAdapter::post_of_entity( self::SOURCE ) );
		$copy = $this->post( array( 'post_title' => 'Copia' ) );
		update_post_meta( $copy, '_xb_page_profile_id', self::SOURCE );
		$this->assertSame( 0, \XB\Remote\PageProfileAdapter::post_of_entity( self::SOURCE ) );
	}

	public function test_bad_links_are_skipped_and_a_bad_body_is_refused(): void {
		$this->linked( self::SOURCE );
		$seen     = 0;
		$listener = static function () use ( &$seen ): void {
			++$seen;
		};
		add_action( 'xb_remote_language_link', $listener );
		$this->send(
			array(
				array( 'translationId' => self::TRANSLATION, 'sourceId' => self::SOURCE, 'language' => 'en', 'mode' => 'follow' ),
				array( 'translationId' => self::SOURCE, 'sourceId' => self::SOURCE, 'language' => '../x', 'mode' => 'follow' ),
				array( 'translationId' => self::SOURCE, 'sourceId' => self::SOURCE, 'language' => 'en', 'mode' => 'other' ),
				'nope',
			)
		);
		remove_action( 'xb_remote_language_link', $listener );
		$this->assertSame( 0, $seen );
		$request = new \WP_REST_Request( 'POST', '/xb/v1/cms/v1/languages' );
		$request->set_body( '{"links":"x"}' );
		$this->assertInstanceOf( \WP_Error::class, Bridge::languages( $request ) );
	}
}
