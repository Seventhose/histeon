<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\SiteTransfer;
use XB\Versioning\Store;

/** The whole site in a `.xbsite` file (docs/spec/site-transfer.md). */
final class SiteTransferTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_xb_import_source', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_attachment( (int) $id, true );
		}
		foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'post_name__in' => array( 'xb-site-pagina', 'xb-site-articolo' ) ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$term = get_term_by( 'slug', 'xb-site-cat', 'category' );
		if ( $term instanceof \WP_Term ) {
			wp_delete_term( $term->term_id, 'category' );
		}
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tearDown();
	}

	private function temp(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file          = wp_tempnam( 'xbsite-test' );
		$this->files[] = $file;
		return $file;
	}

	/**
	 * The attachments an import created.
	 *
	 * @phpstan-impure
	 * @return list<int>
	 */
	private function imported(): array {
		return array_values( array_map( 'intval', get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_xb_import_source', 'fields' => 'ids', 'post_status' => 'inherit', 'numberposts' => -1 ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	private function image(): int {
		$upload = wp_upload_bits( 'xb-site-' . wp_generate_password( 6, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'foto', 'post_status' => 'inherit' ), $upload['file'] );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Una foto' );
		return $id;
	}

	/**
	 * A copy of an export with only some contents and media (the dev site is shared with other suites), the hash
	 * recomputed as the exporter does.
	 *
	 * @param callable(array<string, mixed>): array<string, mixed> $change
	 * @param array<string, string>                              $extra  More entries for the archive.
	 */
	private function rebuild( string $from, callable $change, array $extra = array() ): string {
		$read     = SiteTransfer::read( $from );
		$manifest = $change( $read['manifest'] );
		unset( $manifest['hash'], $manifest['signature'] );
		$manifest['hash'] = hash( 'sha256', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		// Signed again, as the exporting site does (P8).
		$manifest = \XB\Versioning\Signing::sign( $manifest );
		$source           = new \ZipArchive();
		$this->assertTrue( $source->open( $from ) );
		$to  = $this->temp();
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $to, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $manifest['media'] as $item ) {
			$body = $source->getFromName( (string) $item['file'] );
			if ( false !== $body ) {
				$zip->addFromString( (string) $item['file'], $body );
			}
		}
		foreach ( $extra as $name => $body ) {
			$zip->addFromString( $name, $body );
		}
		$zip->addFromString( 'site.json', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();
		$source->close();
		return $to;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private static function only_test( array $manifest, int $media ): array {
		$manifest['documents'] = array_values( array_filter( $manifest['documents'], static fn ( array $d ): bool => str_starts_with( (string) ( $d['key'] ?? '' ), 'xb-site-' ) ) );
		$manifest['contents']  = array_values( array_filter( $manifest['contents'], static fn ( array $c ): bool => str_starts_with( (string) $c['key'], 'xb-site-' ) ) );
		$manifest['media']     = array_values( array_filter( $manifest['media'], static fn ( array $m ): bool => $media === $m['id'] ) );
		return $manifest;
	}

	public function test_a_site_moves_with_its_media_and_lands_in_development(): void {
		$image = $this->image();
		$url   = (string) wp_get_attachment_url( $image );
		$small = (string) preg_replace( '/\.gif$/', '-300x150.gif', $url );
		$page  = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-site-pagina', 'post_title' => 'Pagina traslocata' ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'img', array( 'src' => $url, 'alt' => '' ) ), $this->text( "Piccola: $small" ) ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( array( Documents::for_post( $page ) ) )->get_status() );
		wp_insert_term( 'Traslochi', 'category', array( 'slug' => 'xb-site-cat' ) );
		$post = $this->post( array( 'post_name' => 'xb-site-articolo', 'post_title' => 'Articolo semplice', 'post_status' => 'draft', 'post_content' => '<!-- wp:paragraph --><p>Foto: <img src="' . $url . '" alt=""></p><!-- /wp:paragraph -->' ) );
		set_post_thumbnail( $post, $image );
		wp_set_object_terms( $post, array( 'xb-site-cat' ), 'category' );

		$full   = $this->temp();
		$counts = SiteTransfer::instance()->export( $full );
		$this->assertGreaterThanOrEqual( 2, $counts['posts'] );
		$this->assertGreaterThanOrEqual( 1, $counts['media'] );
		$file     = $this->rebuild( $full, static fn ( array $m ): array => self::only_test( $m, $image ) );
		$manifest = SiteTransfer::read( $file )['manifest'];
		$this->assertCount( 1, $manifest['media'] );
		$this->assertContains( $small, $manifest['media'][0]['variants'], 'the resized copy the page uses belongs to the same attachment' );
		$this->assertSame( array( 'xb-site-pagina' ), array_column( $manifest['documents'], 'key' ), 'Histeon pages travel as documents, plain posts as contents' );

		// The other site: none of these contents (its uploads are another folder: the original file stays, so the
		// imported copy gets an address of its own).
		wp_delete_post( $page, true );
		wp_delete_post( $post, true );
		$this->track( $image );

		$plan = SiteTransfer::instance()->import( $file, false );
		$this->assertFalse( $plan['written'] );
		$this->assertSame( 1, $plan['media'] );
		$this->assertSame( array( 'crea', 'crea' ), array_column( $plan['contents'], 'action' ) );
		$this->assertSame( array(), $this->imported(), 'a dry run writes nothing' );

		$done = SiteTransfer::instance()->import( $file, true );
		$this->assertTrue( $done['written'] );
		$this->assertSame( 1, $done['media'] );
		$this->assertSame( '', $done['published'] );
		$new = $this->imported();
		$this->assertCount( 1, $new );
		$new_url = (string) wp_get_attachment_url( $new[0] );
		$this->assertNotSame( $url, $new_url );
		$this->assertSame( 'Una foto', get_post_meta( $new[0], '_wp_attachment_image_alt', true ) );

		$moved = get_page_by_path( 'xb-site-pagina', 'OBJECT', 'page' );
		$this->assertInstanceOf( \WP_Post::class, $moved );
		$this->assertSame( 'draft', $moved->post_status, 'new pages wait for a release' );
		$this->assertSame( 'Pagina traslocata', $moved->post_title );
		$store = Plugin::instance()->versioning->store();
		$dev   = $store->get( (string) $store->head( Documents::for_post( $moved->ID ), Store::DEV ) );
		$this->assertNotNull( $dev );
		$this->assertStringContainsString( $new_url, (string) $dev['payload']['content'], 'image addresses point to the imported media' );
		$this->assertStringNotContainsString( $url, (string) $dev['payload']['content'] );
		$this->assertStringNotContainsString( $small, (string) $dev['payload']['content'], 'a size this site lacks falls back to the original' );

		$plain = get_posts( array( 'post_type' => 'post', 'name' => 'xb-site-articolo', 'post_status' => 'any', 'numberposts' => 1 ) );
		$this->assertCount( 1, $plain );
		$this->assertStringContainsString( $new_url, $plain[0]->post_content );
		$this->assertSame( $new[0], (int) get_post_thumbnail_id( $plain[0] ) );
		$this->assertSame( array( 'xb-site-cat' ), wp_get_object_terms( $plain[0]->ID, 'category', array( 'fields' => 'slugs' ) ) );

		// Twice: the same media and contents are found again, not duplicated.
		$again = SiteTransfer::instance()->import( $file, true );
		$this->assertSame( array( 'aggiorna', 'aggiorna' ), array_column( $again['contents'], 'action' ) );
		$this->assertCount( 1, $this->imported() );

		// Imported and published at once: the page goes live with its original status.
		$live = SiteTransfer::instance()->import( $file, true, true );
		$this->assertNotSame( '', $live['published'], implode( ' | ', $live['issues'] ) );
		$this->assertSame( 'publish', get_post_status( $moved->ID ) );
		$this->assertStringContainsString( $new_url, $this->content( $moved->ID ) );
		$this->assertSame( 'draft', get_post_status( $plain[0]->ID ), 'a draft stays a draft' );
	}

	/**
	 * A copy of an exported site file as another site would send it: its address, and without some contents.
	 *
	 * @param list<string> $drop Slugs to leave out.
	 */
	private function as_from( string $file, string $site, array $drop = array(), ?bool $complete = true ): string {
		$copy = $this->temp();
		copy( $file, $copy );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $copy ) );
		$manifest         = json_decode( (string) $zip->getFromName( 'site.json' ), true );
		$manifest['site'] = $site;
		$manifest['contents'] = array_values( array_filter( $manifest['contents'], static fn ( array $c ): bool => ! in_array( $c['key'], $drop, true ) ) );
		if ( null === $complete ) {
			unset( $manifest['complete'] );
		} else {
			$manifest['complete'] = $complete;
		}
		unset( $manifest['hash'], $manifest['signature'] );
		$hash             = new \ReflectionMethod( SiteTransfer::class, 'hash' );
		$manifest['hash'] = $hash->invoke( null, $manifest );
		$manifest         = \XB\Versioning\Signing::sign( $manifest );
		$zip->addFromString( 'site.json', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();
		return $copy;
	}

	/** P6: what staging removed is proposed for the trash, and only trashed when asked; nothing written here is touched. */
	public function test_contents_removed_on_the_source_are_proposed_and_trashed_only_when_asked(): void {
		$keep  = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'zq-resta', 'post_title' => 'Resta', 'post_content' => 'a' ) );
		$gone  = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'zq-tolta', 'post_title' => 'Tolta', 'post_content' => 'b' ) );
		$full  = $this->temp();
		SiteTransfer::instance()->export( $full );
		// Arrived from the staging site once.
		SiteTransfer::instance()->import( $this->as_from( $full, 'https://staging.test/' ), true );
		$this->assertSame( 'https://staging.test', get_post_meta( $gone, SiteTransfer::ORIGIN_META, true ) );
		// Written here, never from staging.
		$local = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'zq-locale', 'post_title' => 'Locale', 'post_content' => 'c' ) );

		$next = $this->as_from( $full, 'https://staging.test/', array( 'zq-tolta', 'zq-locale' ) );
		$plan = SiteTransfer::instance()->import( $next, false );
		$this->assertContains( 'page «Tolta»', $plan['deletions'] );
		$this->assertNotContains( 'page «Resta»', $plan['deletions'] );
		$this->assertNotContains( 'page «Locale»', $plan['deletions'], 'what was written here is never proposed' );

		SiteTransfer::instance()->import( $next, true );
		$this->assertSame( 'publish', get_post_status( $gone ), 'not without asking' );
		$done = SiteTransfer::instance()->import( $next, true, false, true );
		$this->assertSame( 1, $done['deleted'] );
		$this->assertSame( 'trash', get_post_status( $gone ), 'in the trash, recoverable' );
		$this->assertSame( 'publish', get_post_status( $keep ) );
		$this->assertSame( 'publish', get_post_status( $local ) );

		// Another source, or a part of a site, proposes nothing.
		$this->assertSame( array(), SiteTransfer::instance()->import( $this->as_from( $full, 'https://altro.test/', array( 'zq-resta' ) ), false )['deletions'] );
		$this->assertSame( array(), SiteTransfer::instance()->import( $this->as_from( $full, 'https://staging.test/', array( 'zq-resta' ), false ), false )['deletions'] );
		$this->assertSame( array(), SiteTransfer::instance()->import( $this->as_from( $full, 'https://staging.test/', array( 'zq-resta' ), null ), false )['deletions'] );
	}

	public function test_unsafe_or_broken_files_are_refused(): void {
		$full = $this->temp();
		SiteTransfer::instance()->export( $full );
		$empty = static function ( array $m ): array {
			$m['documents'] = array();
			$m['contents']  = array();
			$m['media']     = array();
			return $m;
		};
		SiteTransfer::read( $this->rebuild( $full, $empty ) );
		$cases = array(
			'path outside'    => $this->rebuild( $full, $empty, array( '../evil.php' => '<?php' ) ),
			'stray file'      => $this->rebuild( $full, $empty, array( 'wp-config.php' => '<?php' ) ),
			'nested media'    => $this->rebuild( $full, $empty, array( 'media/1-a/b.gif' => 'x' ) ),
			'bad key'         => $this->rebuild( $full, static fn ( array $m ): array => array( 'contents' => array( array( 'type' => 'page', 'key' => '../x', 'status' => 'publish' ) ) ) + $empty( $m ) ),
			'bad status'      => $this->rebuild( $full, static fn ( array $m ): array => array( 'contents' => array( array( 'type' => 'page', 'key' => 'x', 'status' => 'trash' ) ) ) + $empty( $m ) ),
			'media not there' => $this->rebuild( $full, static fn ( array $m ): array => array( 'media' => array( array( 'id' => 1, 'file' => 'media/1-x.gif', 'url' => 'https://a.test/x.gif' ) ) ) + $empty( $m ) ),
			'bad site'        => $this->rebuild( $full, static fn ( array $m ): array => array( 'site' => 'javascript:alert(1)' ) + $empty( $m ) ),
		);
		// Tampered after export: the hash no longer matches.
		$tampered = $this->temp();
		copy( $this->rebuild( $full, $empty ), $tampered );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $tampered ) );
		$zip->addFromString( 'site.json', (string) str_replace( '"documents":[]', '"documents":[{"kind":"styles","payload":{}}]', (string) $zip->getFromName( 'site.json' ) ) );
		$zip->close();
		$cases['tampered'] = $tampered;
		$not_zip           = $this->temp();
		file_put_contents( $not_zip, '{"format":"xb-site"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$cases['not a zip'] = $not_zip;
		foreach ( $cases as $name => $file ) {
			try {
				SiteTransfer::read( $file );
				$this->fail( "$name accepted" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_a_file_type_this_site_refuses_is_skipped(): void {
		$full = $this->temp();
		SiteTransfer::instance()->export( $full );
		$file = $this->rebuild(
			$full,
			static function ( array $m ): array {
				$m['documents'] = array();
				$m['contents']  = array();
				$m['media']     = array( array( 'id' => 7, 'file' => 'media/7-shell.php', 'url' => 'https://a.test/wp-content/uploads/shell.php', 'sha256' => hash( 'sha256', '<?php echo 1;' ) ) );
				return $m;
			},
			array( 'media/7-shell.php' => '<?php echo 1;' )
		);
		$done = SiteTransfer::instance()->import( $file, true );
		$this->assertSame( 0, $done['media'] );
		$this->assertNotEmpty( array_filter( $done['issues'], static fn ( string $i ): bool => str_contains( $i, 'shell.php' ) ) );
		$this->assertSame( array(), $this->imported() );
	}

	public function test_without_rights_contents_are_skipped_and_nothing_goes_live(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-site-pagina', 'post_title' => 'Di un altro' ) );
		$full = $this->temp();
		SiteTransfer::instance()->export( $full );
		$file = $this->rebuild( $full, static fn ( array $m ): array => self::only_test( $m, 0 ) );

		$author = $this->user( 'author' );
		$user   = get_user_by( 'id', $author );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$done = SiteTransfer::instance()->import( $file, true, true );
		$this->assertSame( array( 'saltato' ), array_column( $done['contents'], 'action' ), 'someone else\'s page' );
		$this->assertSame( '', $done['published'], 'only an administrator who publishes publishes on import' );
		$this->assertSame( 'Di un altro', get_the_title( $page ) );
	}

	/**
	 * Without the right to publish (a staging link without it, or an importer without xb_publish) nothing online
	 * changes: the published page keeps its text and title; with the right, it changes.
	 */
	public function test_without_the_right_to_publish_online_content_does_not_change(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'xb-site-online', 'post_title' => 'Online', 'post_content' => '<p>Testo online</p>' ) );
		$full = $this->temp();
		SiteTransfer::instance()->export( $full );
		$file = $this->rebuild(
			$full,
			static function ( array $m ): array {
				$m = self::only_test( $m, 0 );
				foreach ( $m['contents'] as $i => $c ) {
					if ( 'xb-site-online' === $c['key'] ) {
						$m['contents'][ $i ]['title']   = 'Cambiata';
						$m['contents'][ $i ]['content'] = '<p>Testo cambiato</p>';
					}
				}
				return $m;
			}
		);
		$done = SiteTransfer::instance()->import( $file, true, false );
		$row  = array_values( array_filter( $done['contents'], static fn ( array $r ): bool => str_contains( $r['label'], 'Cambiata' ) || str_contains( $r['label'], 'Online' ) ) );
		$this->assertSame( 'saltato', $row[0]['action'] ?? null );
		$this->assertSame( 'Online', get_the_title( $page ) );
		$live = get_post_field( 'post_content', $page, 'raw' );
		$this->assertIsString( $live );
		$this->assertStringContainsString( 'Testo online', $live );
		$this->assertNotEmpty( array_filter( $done['issues'], static fn ( string $i ): bool => str_contains( $i, 'online' ) ) );

		SiteTransfer::instance()->import( $file, true, true );
		$this->assertSame( 'Cambiata', get_the_title( $page ), 'an administrator who publishes changes it' );
	}
}
