<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Ai\AgentSession;
use XB\Ai\Scopes;
use XB\Media\Compress;

/**
 * Doc 26, voci 209, 210, 215, 216, 217: what an agent may change by area, contents with their way back, media
 * through the Abilities, loop queries and sources, an image made lighter where it is.
 */
final class AgentAreasTest extends IntegrationTestCase {

	/** @param array<string, mixed> $input */
	private function call( string $name, array $input = array() ): mixed {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, $name );
		return $ability->execute( $input );
	}

	public function test_areas_default_saved_by_an_administrator_and_followed_by_the_abilities(): void {
		$this->set_option( Scopes::OPTION, false );
		$this->assertSame( Scopes::DEFAULT, $this->request( 'GET', '/agent-scopes' )->get_data()['enabled'] );
		$this->assertNotContains( 'contenuti', Scopes::enabled(), 'contents change the live site: off until someone says so' );

		$saved = $this->request( 'POST', '/agent-scopes', array( 'scopes' => array( 'testi', 'contenuti' ) ) )->get_data();
		$this->assertSame( array( 'testi', 'contenuti' ), $saved['enabled'] );

		$result = $this->call( 'xb/set-style', array( 'selector' => '.scheda', 'css' => 'color: red;' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'xb_agent_scope', $result->get_error_code() );
		$this->assertStringContainsString( 'stile', $result->get_error_message() );

		$editor = $this->user( 'editor' );
		( new \WP_User( $editor ) )->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->request( 'POST', '/agent-scopes', array( 'scopes' => Scopes::ALL ) )->get_status(), 'only an administrator chooses' );
		$this->assertFalse( $this->request( 'GET', '/agent-scopes' )->get_data()['canChange'] );
	}

	public function test_contents_change_and_come_back_with_undo(): void {
		register_post_meta(
			'post',
			'xb_prova_prezzo',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		$this->set_option( AgentSession::OPTION, array() );
		$this->set_option( Scopes::OPTION, Scopes::ALL );
		$id = $this->post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Titolo vero',
				'post_excerpt' => 'Riassunto vero',
			)
		);
		update_post_meta( $id, 'xb_prova_prezzo', '10' );

		$found = $this->call( 'xb/search-content', array( 'search' => 'Titolo vero' ) );
		$this->assertContains( $id, array_column( $found['items'], 'id' ) );
		$this->assertSame( '10', $this->call( 'xb/get-content', array( 'id' => $id ) )['fields']['xb_prova_prezzo'] );

		$after = $this->call(
			'xb/update-content',
			array(
				'id'     => $id,
				'title'  => 'Titolo dell’agente',
				'fields' => array( 'xb_prova_prezzo' => '12' ),
			)
		);
		$this->assertSame( 'Titolo dell’agente', $after['title'] );
		$this->assertSame( '12', get_post_meta( $id, 'xb_prova_prezzo', true ) );
		$this->assertSame( 'draft', get_post_status( $id ), 'the status never changes' );
		$this->call( 'xb/update-content', array( 'id' => $id, 'title' => 'Secondo titolo' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->call( 'xb/update-content', array( 'id' => $id, 'fields' => array( 'non_esiste' => 1 ) ) ) );
		$session = AgentSession::all()[1];
		$this->assertSame( 'Titolo vero', $session['contents'][ $id ]['title'] ?? null, 'the first value is the one to go back to' );
		$this->assertContains( 'post:' . $id, array_column( $this->request( 'GET', '/agent-sessions' )->get_data()[0]['docs'], 'doc' ) );

		$undo = $this->request( 'POST', '/agent-sessions/1', array( 'action' => 'undo' ) )->get_data();
		$this->assertContains( 'post:' . $id, $undo['restored'] );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'Titolo vero', $post->post_title );
		$this->assertSame( 'Riassunto vero', $post->post_excerpt );
		$this->assertSame( '10', get_post_meta( $id, 'xb_prova_prezzo', true ) );

		$this->set_option( Scopes::OPTION, Scopes::DEFAULT );
		$this->assertSame( 'xb_agent_scope', $this->call( 'xb/update-content', array( 'id' => $id, 'title' => 'No' ) )->get_error_code() );
		unregister_post_meta( 'post', 'xb_prova_prezzo' );
	}

	public function test_media_through_the_abilities(): void {
		$this->set_option( Scopes::OPTION, Scopes::ALL );
		$id = $this->image( 'image/png', 40, 20 );
		$listed = $this->call( 'xb/list-media', array( 'missing_alt' => true ) );
		$this->assertContains( $id, array_column( $listed['items'], 'id' ) );
		$this->assertSame( 'Un cerchio rosso', $this->call( 'xb/set-media-alt', array( 'id' => $id, 'alt' => 'Un cerchio rosso' ) )['alt'] );
		$this->assertNotContains( $id, array_column( $this->call( 'xb/list-media', array( 'missing_alt' => true ) )['items'], 'id' ) );
		$term = wp_insert_term( 'Prova agente ' . wp_generate_password( 4, false, false ), 'xb_media_collection' );
		$this->assertIsArray( $term );
		$this->assertSame( array( (int) $term['term_id'] ), $this->call( 'xb/set-media-collections', array( 'id' => $id, 'collections' => array( (int) $term['term_id'], 999999 ) ) )['collections'] );
		$this->assertContains( $id, array_column( $this->call( 'xb/list-media', array( 'collection' => (int) $term['term_id'] ) )['items'], 'id' ) );
		wp_delete_term( (int) $term['term_id'], 'xb_media_collection' );
	}

	public function test_loop_queries_and_sources_from_an_agent_are_versioned_and_checked(): void {
		$this->set_option( Scopes::OPTION, Scopes::ALL );
		$this->call(
			'xb/set-query',
			array(
				'name'  => 'ultimi_tre',
				'query' => array(
					'type' => 'posts',
					'args' => array(
						'post_type'      => 'post',
						'posts_per_page' => 3,
					),
				),
			)
		);
		$this->call( 'xb/set-source', array( 'name' => 'orari', 'data' => array( array( 'giorno' => 'lunedì' ) ) ) );
		$queries = $this->request( 'GET', '/queries' )->get_data();
		$this->assertArrayHasKey( 'ultimi_tre', (array) $queries['presets'] );
		$this->assertSame( 'lunedì', ( (array) $queries['sources'] )['orari']['data'][0]['giorno'] );

		$bad = $this->call( 'xb/set-query', array( 'name' => 'x', 'query' => array( 'type' => 'posts', 'args' => array( 'post_status' => 'draft' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $bad, 'the Queries panel\'s forbidden arguments stay forbidden' );
		$this->call( 'xb/set-query', array( 'name' => 'ultimi_tre', 'query' => null ) );
		$this->assertArrayNotHasKey( 'ultimi_tre', (array) $this->request( 'GET', '/queries' )->get_data()['presets'] );
	}

	public function test_an_image_made_lighter_in_place_keeps_its_address_and_sizes_and_comes_back(): void {
		$id      = $this->image( 'image/jpeg', 1200, 800, 100 );
		$path    = (string) get_attached_file( $id );
		$url     = wp_get_attachment_url( $id );
		$meta    = (array) wp_get_attachment_metadata( $id );
		$medium  = dirname( $path ) . '/' . (string) ( $meta['sizes']['medium']['file'] ?? '' );
		$backup  = (string) preg_replace( '/\.jpg$/', '.xb-originale.jpg', $path );
		$before  = (int) filesize( $path );
		$lighter = $this->jpeg( 1200, 800, 30 );

		$this->assertInstanceOf( \WP_Error::class, Compress::replace( $id, $this->jpeg( 1200, 600, 30 ) ), 'other proportions are refused' );
		$this->assertInstanceOf( \WP_Error::class, Compress::replace( $id, $this->jpeg( 1200, 800, 100 ) ), 'a file not lighter is refused' );

		$done = Compress::replace( $id, $lighter );
		$this->assertIsArray( $done );
		$this->assertSame( $before, $done['before'] );
		$this->assertLessThan( $before, $done['after'] );
		$this->assertSame( $url, wp_get_attachment_url( $id ), 'same address' );
		$this->assertFileExists( $medium, 'the sizes keep their names' );
		$this->assertFileExists( $backup );
		$this->assertSame( $done['after'], ( (array) wp_get_attachment_metadata( $id ) )['filesize'] ?? null );

		$restored = $this->request( 'POST', '/media/' . $id . '/restore' );
		$this->assertSame( 200, $restored->get_status() );
		$this->assertSame( $before, (int) filesize( $path ) );
		$this->assertFileDoesNotExist( $backup );
		$this->assertSame( '', get_post_meta( $id, Compress::META, true ) );
	}

	public function test_presets_of_the_site_are_cleaned(): void {
		$this->set_option( Compress::PRESETS, array() );
		$request = new \WP_REST_Request( 'POST', '/xb/v1/media/presets' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'presets' => array(
						array( 'id' => 'Blog', 'label' => 'Blog <b>1200</b>', 'max' => 1200, 'formats' => array( 'webp', 'gif' ), 'quality' => 200 ),
						array( 'id' => 'web', 'label' => 'Ruba il nome', 'max' => 10 ),
					),
				)
			)
		);
		$saved = rest_do_request( $request )->get_data()['presets'];
		$this->assertSame(
			array(
				array( 'id' => 'blog', 'label' => 'Blog 1200', 'max' => 1200, 'formats' => array( 'webp' ), 'quality' => 95 ),
			),
			$saved
		);
	}

	/** @var list<int> Library items made here, deleted with their files. */
	private array $attachments = array();

	protected function tearDown(): void {
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachments = array();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * A test image in the library: PNG or JPEG of that size, with its intermediate sizes.
	 *
	 * @param int<1, max> $width
	 * @param int<1, max> $height
	 */
	private function image( string $mime, int $width, int $height, int $quality = 90 ): int {
		$file = 'image/png' === $mime ? $this->png( $width, $height ) : $this->jpeg( $width, $height, $quality );
		$ext  = 'image/png' === $mime ? 'png' : 'jpg';
		$up   = wp_upload_bits( 'xb-agente-' . wp_generate_password( 6, false, false ) . '.' . $ext, null, (string) file_get_contents( $file ) );
		$this->assertEmpty( $up['error'] );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'prova',
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		$this->attachments[] = $id;
		return $id;
	}

	/**
	 * A noisy JPEG (so the quality changes its weight), in a temporary file.
	 *
	 * @param int<1, max> $width
	 * @param int<1, max> $height
	 */
	private function jpeg( int $width, int $height, int $quality ): string {
		$img = imagecreatetruecolor( $width, $height );
		mt_srand( 7 );
		for ( $i = 0; $i < 4000; $i++ ) {
			imagefilledellipse( $img, mt_rand( 0, $width ), mt_rand( 0, $height ), 30, 30, (int) imagecolorallocate( $img, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
		}
		$tmp = (string) wp_tempnam( 'xb-jpeg' );
		imagejpeg( $img, $tmp, $quality );
		return $tmp;
	}

	/**
	 * @param int<1, max> $width
	 * @param int<1, max> $height
	 */
	private function png( int $width, int $height ): string {
		$img = imagecreatetruecolor( $width, $height );
		imagefilledellipse( $img, (int) ( $width / 2 ), (int) ( $height / 2 ), $height, $height, (int) imagecolorallocate( $img, 200, 0, 0 ) );
		$tmp = (string) wp_tempnam( 'xb-png' );
		imagepng( $img, $tmp );
		return $tmp;
	}
}
