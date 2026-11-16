<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Library\Sections;

/** The section library (docs/spec/library.md). */
final class LibraryTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Sections::OPTION, array() );
	}

	private function clip( string $text = 'Ciao' ): string {
		return (string) wp_json_encode(
			array(
				'xb'      => 'clip',
				'version' => 1,
				'origin'  => 'altro.test',
				'markup'  => $this->markup( array( $this->el( 'section', array( 'class' => 'hero' ), array( $this->text( $text ) ) ) ) ),
				'styles'  => array( array( 'selector' => '.hero', 'css' => 'padding: 2rem;' ) ),
			)
		);
	}

	public function test_save_list_get_and_delete(): void {
		$saved = $this->request( 'POST', '/sections', array( 'name' => 'Hero', 'clip' => $this->clip() ) );
		$this->assertSame( 200, $saved->get_status() );
		$id   = $saved->get_data()['id'];
		$list = $this->request( 'GET', '/sections' )->get_data()['sections'];
		$this->assertSame( array( 'Hero' ), array_column( $list, 'name' ) );
		$this->assertArrayNotHasKey( 'clip', $list[0], 'the list stays light' );
		$clip = json_decode( (string) $this->request( 'GET', '/sections/' . $id )->get_data()['clip'], true );
		$this->assertSame( '.hero', $clip['styles'][0]['selector'] );
		$this->assertSame( 200, $this->request( 'DELETE', '/sections/' . $id )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/sections/' . $id )->get_status() );
	}

	/** A builder removes only the sections they saved; xb_publish removes any (S6). */
	public function test_only_the_author_or_a_publisher_removes_a_section(): void {
		$id      = (string) $this->request( 'POST', '/sections', array( 'name' => 'Dell\'amministratore', 'clip' => $this->clip() ) )->get_data()['id'];
		$builder = $this->user( 'editor' );
		$user    = get_user_by( 'id', $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		$user->remove_cap( 'xb_publish' );
		wp_set_current_user( $builder );
		$this->assertFalse( current_user_can( 'xb_publish' ) );
		$refused = $this->request( 'DELETE', '/sections/' . $id );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/sections/' . $id )->get_status(), 'still there' );
		$own = (string) $this->request( 'POST', '/sections', array( 'name' => 'Mia', 'clip' => $this->clip( 'Mia' ) ) )->get_data()['id'];
		$this->assertSame( 200, $this->request( 'DELETE', '/sections/' . $own )->get_status(), 'their own' );
		$this->assertSame( 404, $this->request( 'DELETE', '/sections/' . $own )->get_status() );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->request( 'DELETE', '/sections/' . $id )->get_status(), 'a publisher removes any' );
	}

	/** M2: a library file carries the pictures its sections use; the other site gets them from the file. */
	public function test_a_library_carries_its_pictures(): void {
		$upload = wp_upload_bits( 'zq-libreria.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$image = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'zq', 'post_status' => 'inherit' ), $upload['file'] );
		$this->track( $image );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Il porto' );
		$url  = (string) wp_get_attachment_url( $image );
		$clip = (string) wp_json_encode(
			array(
				'xb'      => 'clip',
				'version' => 1,
				'origin'  => 'qui.test',
				'markup'  => $this->markup( array( $this->el( 'img', array( 'src' => $url, 'alt' => 'x' ) ) ) ),
				'styles'  => array( array( 'selector' => '.hero', 'css' => 'background: url(' . $url . ');' ) ),
			)
		);
		$this->assertSame( 200, $this->request( 'POST', '/sections', array( 'name' => 'Con foto', 'clip' => $clip ) )->get_status() );
		$file = $this->request( 'GET', '/sections/export' )->get_data();
		$this->assertCount( 1, $file['media'] );
		$this->assertSame( array( $url ), $file['media'][0]['urls'] );
		$this->assertSame( 'image/gif', $file['media'][0]['mime'] );

		// As another site: a new section id, the same pictures.
		$file['sections'][0]['id'] = wp_generate_uuid4();
		$file['hash']              = hash( 'sha256', (string) wp_json_encode( $file['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->set_option( Sections::OPTION, array() );
		$result = $this->request( 'POST', '/sections/import', array( 'data' => $file ) )->get_data();
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 1, $result['media'] );
		$copy = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_xb_import_source', 'meta_value' => 'library:' . hash( 'sha256', (string) file_get_contents( $upload['file'] ) ), 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertCount( 1, $copy );
		$this->track( (int) $copy[0] );
		$this->assertSame( 'Il porto', get_post_meta( (int) $copy[0], '_wp_attachment_image_alt', true ) );
		$new   = (string) wp_get_attachment_url( (int) $copy[0] );
		$saved = json_decode( (string) Sections::get( $file['sections'][0]['id'] ), true );
		$this->assertStringContainsString( $new, (string) $saved['markup'] );
		$this->assertStringContainsString( 'url(' . $new . ')', (string) $saved['styles'][0]['css'] );

		// An altered picture list is ignored: the sections arrive, the pictures do not.
		$file['sections'][0]['id'] = wp_generate_uuid4();
		$file['hash']              = hash( 'sha256', (string) wp_json_encode( $file['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$file['media'][0]['mime']  = 'image/svg+xml';
		$this->assertSame( 0, $this->request( 'POST', '/sections/import', array( 'data' => $file ) )->get_data()['media'] );
	}

	/**
	 * The export reads pictures from disk, past the web server: not one in a folder the server keeps closed, nor one
	 * the user may not read.
	 */
	public function test_the_export_reads_no_picture_the_web_server_keeps_closed(): void {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'zq-protetti';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$path = $dir . '/zq-segreta.gif';
		file_put_contents( $path, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		try {
			$image = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'zq protetta', 'post_status' => 'inherit' ), $path );
			$this->track( $image );
			$url  = (string) wp_get_attachment_url( $image );
			$clip = (string) wp_json_encode( array( 'xb' => 'clip', 'version' => 1, 'origin' => 'qui.test', 'markup' => $this->markup( array( $this->el( 'img', array( 'src' => $url, 'alt' => 'x' ) ) ) ), 'styles' => array() ) );
			$saved = $this->request( 'POST', '/sections', array( 'name' => 'Con foto protetta', 'clip' => $clip ) );
			$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
			$file = $this->request( 'GET', '/sections/export' )->get_data();
			$this->assertSame( array(), array_values( array_filter( $file['media'], static fn ( array $m ): bool => in_array( $url, $m['urls'], true ) ) ) );
		} finally {
			wp_delete_file( $path );
			wp_delete_file( $dir . '/.htaccess' );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function test_only_clips_are_kept_and_only_builders_use_it(): void {
		foreach ( array( '', 'nonsense', '{"xb":"clip","version":2,"origin":"x","markup":"","styles":[]}', '{"xb":"clip","version":1,"origin":"x","markup":"","styles":[{"selector":1}]}' ) as $bad ) {
			$this->assertSame( 400, $this->request( 'POST', '/sections', array( 'name' => 'X', 'clip' => $bad ) )->get_status(), $bad );
		}
		$this->assertSame( 400, $this->request( 'POST', '/sections', array( 'name' => '  ', 'clip' => $this->clip() ) )->get_status(), 'a name' );
		$this->assertSame( 400, $this->request( 'POST', '/sections', array( 'name' => 'Grande', 'clip' => str_repeat( ' ', Sections::MAX_CLIP + 1 ) ) )->get_status() );
		// Extra keys never reach the store: the clip is rebuilt from what it should have.
		$id    = Sections::save( 'Pulita', (string) wp_json_encode( json_decode( $this->clip(), true ) + array( 'evil' => '<script>' ) ) );
		$this->assertStringNotContainsString( 'evil', (string) Sections::get( $id ) );

		// A builder without unfiltered_html saves only what they could write in a document: whoever inserts it later.
		$restricted = $this->user( 'author' );
		( new \WP_User( $restricted ) )->add_cap( 'xb_build' );
		$previous = get_current_user_id();
		wp_set_current_user( $restricted );
		$hostile = json_decode( $this->clip(), true );
		$hostile['markup'] = $this->markup( array( $this->el( 'div', array( 'class' => 'hero', 'onclick' => 'alert(1)' ), array( $this->text( 'Ciao' ) ) ) ) ) . '<!-- wp:xb/raw {"uid":"raw00001","content":"<img src=x onerror=alert(2)>"} /-->';
		$hostile['styles'][] = array( 'selector' => '.spy', 'css' => 'background:url(https://evil.example/x)' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$saved = (string) Sections::get( Sections::save( 'Ostile', (string) wp_json_encode( $hostile ) ) );
		$this->assertStringNotContainsString( 'onclick', $saved );
		$this->assertStringNotContainsString( 'onerror', $saved );
		$this->assertStringNotContainsString( 'evil.example', $saved );
		$this->assertStringContainsString( 'Ciao', $saved );
		wp_set_current_user( $previous );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', '/sections' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/sections', array( 'name' => 'X', 'clip' => $this->clip() ) )->get_status() );
	}

	public function test_a_library_moves_between_sites_once_and_unaltered(): void {
		Sections::save( 'Hero', $this->clip( 'Uno' ) );
		Sections::save( 'Footer', $this->clip( 'Due' ) );
		$file = $this->request( 'GET', '/sections/export' )->get_data();
		$this->assertSame( 'xb-library', $file['format'] );
		$this->assertCount( 2, $file['sections'] );

		$this->set_option( Sections::OPTION, array() );
		$first = $this->request( 'POST', '/sections/import', array( 'data' => $file ) );
		$this->assertSame( array( 'added' => 2, 'skipped' => 0, 'media' => 0 ), $first->get_data() );
		$this->assertSame( array( 'added' => 0, 'skipped' => 2, 'media' => 0 ), $this->request( 'POST', '/sections/import', array( 'data' => $file ) )->get_data(), 'the same file twice adds nothing' );

		$altered                         = $file;
		$altered['sections'][0]['name'] = 'Cambiato';
		$this->assertSame( 400, $this->request( 'POST', '/sections/import', array( 'data' => $altered ) )->get_status(), 'hash' );
		$broken                         = $file;
		$broken['sections'][0]['clip'] = 'nonsense';
		$broken['hash']                 = hash( 'sha256', (string) wp_json_encode( $broken['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( 400, $this->request( 'POST', '/sections/import', array( 'data' => $broken ) )->get_status(), 'every clip is checked' );
		$this->assertSame( 400, $this->request( 'POST', '/sections/import', array( 'data' => array( 'format' => 'other' ) ) )->get_status() );
		$this->assertContains( 'library_import', array_column( \XB\Plugin::instance()->versioning->store()->audit_log( 5 ), 'action' ) );
	}

	/** Notes 75 and 76 of the 3 November audit: a library file is added whole or not at all, each id once. */
	public function test_a_library_file_is_added_whole_or_not_at_all(): void {
		Sections::save( 'Hero', $this->clip( 'Uno' ) );
		Sections::save( 'Footer', $this->clip( 'Due' ) );
		$file = $this->request( 'GET', '/sections/export' )->get_data();
		$this->set_option( Sections::OPTION, array() );

		$twice                           = $file;
		$twice['sections'][1]['id']      = $twice['sections'][0]['id'];
		$twice['hash']                   = hash( 'sha256', (string) wp_json_encode( $twice['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( 400, $this->request( 'POST', '/sections/import', array( 'data' => $twice ) )->get_status(), 'the same id twice' );
		$this->assertSame( array(), Sections::index() );

		$nameless                        = $file;
		$nameless['sections'][1]['name'] = '<b></b>';
		$nameless['hash']                = hash( 'sha256', (string) wp_json_encode( $nameless['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( 400, $this->request( 'POST', '/sections/import', array( 'data' => $nameless ) )->get_status(), 'a section without a name' );
		$this->assertSame( array(), Sections::index(), 'the first section is not left behind' );
	}

	/** Note 74 of the 3 November audit: bytes that are not a picture never reach the public uploads folder. */
	public function test_a_library_picture_is_checked_before_it_is_written(): void {
		$writes = 0;
		$count  = static function ( mixed $bits ) use ( &$writes ): mixed {
			++$writes;
			return $bits;
		};
		add_filter( 'wp_upload_bits', $count );
		Sections::save( 'Hero', $this->clip() );
		$file                 = $this->request( 'GET', '/sections/export' )->get_data();
		$file['media']        = array(
			array( 'urls' => array( 'https://altro.test/finto.gif' ), 'name' => 'finto.gif', 'mime' => 'image/gif', 'data' => base64_encode( '<?php echo 1; ?>' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			array( 'urls' => array( 'https://altro.test/finto.php' ), 'name' => 'finto.php', 'mime' => 'image/gif', 'data' => base64_encode( (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		);
		$file['media_hash']   = hash( 'sha256', (string) wp_json_encode( $file['media'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->set_option( Sections::OPTION, array() );
		$result = $this->request( 'POST', '/sections/import', array( 'data' => $file ) )->get_data();
		remove_filter( 'wp_upload_bits', $count );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 0, $result['media'] );
		$this->assertSame( 0, $writes, 'nothing written, not even for a moment' );
	}
}
