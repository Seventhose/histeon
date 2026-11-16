<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Media\Video;

/**
 * A YouTube or Vimeo video that asks nothing of them before the visitor's click (E9): the poster is downloaded once
 * by the server into the media library, the player loads from youtube-nocookie.com or with dnt=1 on click.
 */
final class VideoTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $requested = array();

	protected function setUp(): void {
		parent::setUp();
		$this->requested = array();
		$jpeg = (string) base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdBRkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0//wAARCAAGAAgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwCtRRRXnH0Z/9k=', true );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ) use ( $jpeg ): array {
				$this->requested[] = $url;
				$reply = static fn ( int $code, string $body, string $type ): array => array( 'headers' => array( 'content-type' => $type ), 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
				if ( str_starts_with( $url, 'https://www.youtube.com/oembed?' ) ) {
					return $reply( 200, '{"title":"Un video <b>di prova</b>","thumbnail_url":"https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"}', 'application/json' );
				}
				if ( str_starts_with( $url, 'https://vimeo.com/api/oembed.json?' ) ) {
					return $reply( 200, '{"title":"Vimeo di prova","thumbnail_url":"https://i.vimeocdn.com/video/1-d_640"}', 'application/json' );
				}
				if ( str_starts_with( $url, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/' ) || str_starts_with( $url, 'https://i.vimeocdn.com/video/' ) ) {
					return $reply( 200, $jpeg, 'image/jpeg' );
				}
				return $reply( 404, '', 'text/plain' );
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => Video::POSTER_META ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			wp_delete_attachment( (int) $id, true );
		}
		parent::tearDown();
	}

	public function test_reads_youtube_and_vimeo_addresses_and_nothing_else(): void {
		$this->assertSame( array( 'youtube', 'dQw4w9WgXcQ' ), Video::parse( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10' ) );
		$this->assertSame( array( 'youtube', 'dQw4w9WgXcQ' ), Video::parse( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertSame( array( 'youtube', 'dQw4w9WgXcQ' ), Video::parse( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) );
		$this->assertSame( array( 'vimeo', '76979871' ), Video::parse( 'https://vimeo.com/76979871' ) );
		$this->assertSame( array( 'vimeo', '76979871' ), Video::parse( 'https://player.vimeo.com/video/76979871' ) );
		$this->assertNull( Video::parse( 'https://evil.example/watch?v=dQw4w9WgXcQ' ) );
		$this->assertNull( Video::parse( 'javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ' ) );
		$this->assertNull( Video::parse( 'https://www.youtube.com/watch?v=short' ) );
	}

	public function test_downloads_the_poster_once_and_answers_the_player_addresses(): void {
		$data = $this->request( 'POST', '/video', array( 'url' => 'https://youtu.be/dQw4w9WgXcQ' ) )->get_data();
		$this->assertSame( 'youtube', $data['provider'] );
		$this->assertSame( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $data['embed'] );
		$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $data['watch'] );
		$this->assertSame( 'Un video di prova', $data['title'] );
		$this->assertStringStartsWith( (string) wp_upload_dir( null, false )['baseurl'] . '/', $data['poster']['url'], 'The poster is served by the site.' );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $data['poster']['id'] ) );

		// The same video again: the poster already in the library, nothing downloaded.
		$asked = count( $this->requested );
		$again = $this->request( 'POST', '/video', array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) )->get_data();
		$this->assertSame( $data['poster']['id'], $again['poster']['id'] );
		$this->assertSame( $asked + 1, count( $this->requested ), 'only the title (oEmbed) again' );

		$vimeo = $this->request( 'POST', '/video', array( 'url' => 'https://vimeo.com/76979871' ) )->get_data();
		$this->assertSame( 'https://player.vimeo.com/video/76979871', $vimeo['embed'] );
		$this->assertSame( 'Vimeo di prova', $vimeo['title'] );
		foreach ( $this->requested as $url ) {
			$this->assertMatchesRegularExpression( '#^https://(www\.youtube\.com/oembed\?|i\.ytimg\.com/|vimeo\.com/api/oembed\.json\?|i\.vimeocdn\.com/)#', $url );
		}
	}

	public function test_needs_upload_rights_and_a_video_address(): void {
		$this->assertSame( 400, $this->request( 'POST', '/video', array( 'url' => 'https://example.com/x' ) )->get_status() );
		wp_set_current_user( $this->user( 'contributor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/video', array( 'url' => 'https://youtu.be/dQw4w9WgXcQ' ) )->get_status() );
	}
}
