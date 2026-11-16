<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Schema;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

/** Doc 23 item 124: a commit the database keeps differently from what was sent is refused at once, not found later. */
final class CommitReadBackTest extends IntegrationTestCase {

	public function test_a_commit_stored_differently_is_not_kept(): void {
		global $wpdb;
		$id         = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Originale' ) ) ) ) ) ) );
		$doc        = Documents::for_post( $id );
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( $doc );
		$payload    = $copy['payload'];
		$payload['content'] = $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Parola<wbr>lunga' ) ) ) ) );
		// A connection that changes the text on its way in, as another PHP or charset did on the test bench.
		$change = static fn ( string $query ): string => str_contains( $query, Schema::table( 'commits' ) ) && str_starts_with( ltrim( $query ), 'INSERT' ) ? str_replace( 'Parola', 'Paro1a', $query ) : $query;
		add_filter( 'query', $change );
		try {
			$versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'test' );
			$this->fail( 'a changed commit was kept' );
		} catch ( VersioningException $e ) {
			$this->assertStringContainsString( 'non ha salvato la versione così come è stata inviata', $e->getMessage() );
			$this->assertStringContainsString( 'PHP ' . PHP_VERSION, $e->getMessage() );
		} finally {
			remove_filter( 'query', $change );
		}
		$this->assertSame( $copy['head'], Plugin::instance()->versioning->store()->head( $doc, Store::DEV ), 'the head did not move' );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'commits' ) . " WHERE doc = %s AND message = 'test'", $doc ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Without the change the same save goes through.
		$versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'test' );
		$this->assertStringContainsString( 'Parola', (string) $versioning->working_copy( $doc )['payload']['content'] );
	}

	private function builder(): int {
		$id   = $this->user( 'author' );
		$user = get_userdata( $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		return $id;
	}

	/**
	 * @param list<array<string, mixed>> $styles
	 * @param list<array<string, mixed>> $globals
	 */
	private function save_styles( array $styles, array $globals ): \WP_REST_Response {
		$heads = $this->request( 'GET', '/styles' )->get_data();
		return $this->request( 'POST', '/styles', array( 'head' => $heads['head'], 'base' => $heads['base'], 'styles' => $styles, 'globals' => $globals ) );
	}

	/**
	 * Threat model T31, P2 of the ninth review: a body that is not JSON (application/x-www-form-urlencoded) brings bytes
	 * that are not UTF-8 as far as the filter of restricted CSS. The store wrote the version through wp_json_encode(),
	 * which repairs such strings with mbstring: with mbstring.substitute_character set to "none" the byte went away
	 * after the filter, and the development document held `@namespace`, `url(` of another site and `@import`.
	 * Now the filter leaves such a sheet without CSS, and the store does not repair with mbstring (the tests below).
	 */
	public function test_bytes_that_are_not_utf8_put_no_name_together_after_the_filter(): void {
		$this->set_option( 'xb_styles', array() );
		$this->set_option( 'xb_global_styles', array() );
		wp_set_current_user( $this->builder() );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$substitute = mb_substitute_character();
		mb_substitute_character( 'none' );
		try {
			$saved = $this->save_styles(
				array(),
				array(
					array( 'id' => 'a', 'css' => "@name\xFFspace \"y\";.card{margin:0}" ),
					array( 'id' => 'b', 'css' => ".dopo{color:green;background:u\xFFrl(https://altro.example/a.png)}" ),
					array( 'id' => 'c', 'css' => "@imp\xFFort \"https://altro.example/a.css\";" ),
					array( 'id' => 'd', 'css' => '.resta{content:"è €"}' ),
				)
			);
			$dev   = $this->request( 'GET', '/styles' )->get_data();
		} finally {
			mb_substitute_character( $substitute );
		}
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame(
			array(
				'a' => '',
				'b' => '',
				'c' => '',
				'd' => '.resta{content:"è €"}',
			),
			array_column( $dev['globals'], 'css', 'id' ),
			'a sheet that is not UTF-8 keeps no CSS, the one next to it stays'
		);
	}

	/**
	 * One wrong byte anywhere in the document was enough for every string to be repaired, the valid ones too, with the
	 * encodings of mb_detect_order(): with UTF-7 first, `+AEA-import` (plain ASCII, which the filter rightly leaves)
	 * became `@import`. The save still goes through, and the wrong byte becomes U+FFFD without mbstring: the valid CSS
	 * next to it is kept byte for byte.
	 */
	public function test_a_wrong_byte_elsewhere_does_not_change_valid_css(): void {
		$this->set_option( 'xb_styles', array() );
		$this->set_option( 'xb_global_styles', array() );
		wp_set_current_user( $this->builder() );
		$head  = $this->request( 'GET', '/styles' )->get_data()['head'];
		$sheet = '+AEA-import "https://altro.example/a.css";';
		$order = mb_detect_order();
		$this->assertTrue( mb_detect_order( array( 'UTF-7', 'UTF-8' ) ) );
		try {
			$saved = $this->save_styles(
				array( array( 'id' => 's', 'selector' => ".card\xFF", 'css' => 'padding:0' ) ),
				array( array( 'id' => 'a', 'css' => $sheet ), array( 'id' => 'd', 'css' => '.resta{content:"è €"}' ) )
			);
			$dev   = $this->request( 'GET', '/styles' )->get_data();
		} finally {
			mb_detect_order( array() === $order ? 'auto' : $order );
		}
		$this->assertStringNotContainsString( '@import', (string) json_encode( $dev['globals'], JSON_INVALID_UTF8_SUBSTITUTE ), 'the development document' );
		$this->assertSame( 200, $saved->get_status(), 'text that is not UTF-8 does not refuse the save' );
		$this->assertNotSame( $head, $dev['head'], 'the head moved' );
		$this->assertSame( array( 'a' => $sheet, 'd' => '.resta{content:"è €"}' ), array_column( $dev['globals'], 'css', 'id' ), 'valid CSS, byte for byte' );
		$this->assertSame( array( 's' => ".card\u{FFFD}" ), array_column( $dev['styles'], 'selector', 'id' ), 'the wrong byte is U+FFFD' );
		$this->assertSame( array( 's' => '' ), array_column( $dev['styles'], 'css', 'id' ), 'and that entry keeps no CSS' );
	}

	/**
	 * The store alone, past every filter: in a version that is not trusted a wrong byte is never dropped and never
	 * read as another encoding, whatever mbstring is set to, in values and in keys. No name is put together, and the
	 * commit returned holds what was written.
	 *
	 * @param non-empty-list<non-falsy-string> $detect
	 */
	#[DataProvider( 'mbstring_settings' )]
	public function test_a_version_that_is_not_trusted_is_repaired_without_mbstring( int|string $substitute, array $detect ): void {
		global $wpdb;
		$store      = Plugin::instance()->versioning->store();
		$payload    = array(
			'content'   => "<scr\xFFipt>alert(1)</scr\xFFipt><p title=\"\xE0\">\xF0\x9F\x98</p>",
			'globals'   => array(
				array( 'id' => 'a', 'css' => "@imp\xFFort \"https://altro.example/a.css\";" ),
				array( 'id' => 'b', 'css' => ".a{background:u\xFFrl(https://altro.example/a.png)}" ),
				array( 'id' => 'c', 'css' => '+AEA-import "https://altro.example/a.css";' ),
				array( 'id' => 'd', 'css' => '.resta{content:"è €"}' ),
			),
			"chiave\xFF" => 'x',
		);
		$expected   = array(
			'content'         => "<scr\u{FFFD}ipt>alert(1)</scr\u{FFFD}ipt><p title=\"\u{FFFD}\">\u{FFFD}</p>",
			'globals'         => array(
				array( 'id' => 'a', 'css' => "@imp\u{FFFD}ort \"https://altro.example/a.css\";" ),
				array( 'id' => 'b', 'css' => ".a{background:u\u{FFFD}rl(https://altro.example/a.png)}" ),
				array( 'id' => 'c', 'css' => '+AEA-import "https://altro.example/a.css";' ),
				array( 'id' => 'd', 'css' => '.resta{content:"è €"}' ),
			),
			"chiave\u{FFFD}" => 'x',
		);
		$before     = array( mb_substitute_character(), mb_detect_order() );
		mb_substitute_character( $substitute );
		$this->assertTrue( mb_detect_order( $detect ) );
		try {
			$commit = $store->commit( 'xb-test-p2b', Store::DEV, $payload, null, null, 'test', false );
		} finally {
			mb_substitute_character( $before[0] );
			mb_detect_order( array() === $before[1] ? 'auto' : $before[1] );
		}
		$json = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . Schema::table( 'commits' ) . ' WHERE id = %s', $commit['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( '@import', 'url(', '<script' ) as $name ) {
			$this->assertStringNotContainsString( $name, $json, 'put together in what was written' );
		}
		$kept = $store->get( $commit['id'] );
		$this->assertIsArray( $kept );
		$this->assertFalse( $kept['trusted'] );
		$this->assertSame( $expected, $kept['payload'], 'each wrong sequence is one U+FFFD and the rest is the same' );
		$this->assertSame( $kept['payload'], $commit['payload'], 'the commit returned holds what was written' );
	}

	/**
	 * What is left of the refusal (Store::commit(), where the second json_encode() or the json_decode() after it
	 * fails): a version that is not trusted and cannot be written in JSON for a reason other than UTF-8 is refused
	 * with 400, nothing is kept and the head stays where it was. Written through wp_json_encode() it would be an
	 * empty payload, a version that no one can open.
	 *
	 * @param array<string, mixed> $extra
	 */
	#[DataProvider( 'versions_that_cannot_be_written' )]
	public function test_a_version_that_is_not_trusted_and_not_json_is_refused( array $extra, string $reason ): void {
		global $wpdb;
		$id         = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Originale' ) ) ) ) ) ) );
		$doc        = Documents::for_post( $id );
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( $doc );
		try {
			$versioning->save_dev( $doc, array_merge( $copy['payload'], $extra ), (string) $copy['head'], (string) $copy['base'], 'test', false, true );
			$this->fail( 'a version that cannot be written in JSON was kept' );
		} catch ( VersioningException $e ) {
			$this->assertSame( 400, $e->status );
			$this->assertStringContainsString( 'non si può scrivere in JSON', $e->getMessage() );
			$this->assertStringContainsString( $reason, $e->getMessage(), 'the reason is the one of the call that failed' );
		}
		$this->assertSame( $copy['head'], $versioning->store()->head( $doc, Store::DEV ), 'the head did not move' );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'commits' ) . " WHERE doc = %s AND message = 'test'", $doc ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function versions_that_cannot_be_written(): array {
		// 512 levels in all with the payload itself: json_encode() writes them with JSON_INVALID_UTF8_SUBSTITUTE, and
		// json_decode() cannot read them back with its depth of 512.
		$deep = "x\xFF";
		for ( $level = 0; $level < 511; ++$level ) {
			$deep = array( $deep );
		}
		return array(
			'INF'                          => array( array( 'number' => INF ), 'Inf and NaN cannot be JSON encoded' ),
			'a wrong byte 512 levels down' => array( array( 'deep' => $deep ), 'Maximum stack depth exceeded' ),
		);
	}

	/** @return array<string, array{int|string, non-empty-list<non-falsy-string>}> */
	public static function mbstring_settings(): array {
		return array(
			'as PHP comes'            => array( 63, array( 'ASCII', 'UTF-8' ) ),
			'substitute none'         => array( 'none', array( 'ASCII', 'UTF-8' ) ),
			'UTF-7 first'             => array( 63, array( 'UTF-7', 'UTF-8' ) ),
			'none and UTF-7 together' => array( 'none', array( 'UTF-7', 'UTF-8' ) ),
		);
	}

	/**
	 * Through save_dev(), as an import or an agent calls it, with a byte in a field that the REST route of the styles
	 * does not keep: the save is never refused for text that is not UTF-8 (an import of many documents does not stop
	 * half way). A version that is not trusted gets U+FFFD; who has unfiltered_html saves a trusted version, which
	 * WP repairs as it always did.
	 */
	public function test_text_that_is_not_utf8_never_refuses_a_save(): void {
		$this->set_option( 'xb_styles', array() );
		$this->set_option( 'xb_global_styles', array() );
		$versioning = Plugin::instance()->versioning;
		$payload    = array(
			'styles'  => array(),
			'globals' => array( array( 'id' => 'a', 'name' => "Titolo \xE8", 'css' => '.a{gap:0}' ) ),
		);
		// What _wp_json_convert_string() does with it, on this bench.
		$detected   = mb_detect_encoding( "Titolo \xE8", mb_detect_order(), true );
		$wp         = mb_convert_encoding( "Titolo \xE8", 'UTF-8', false === $detected ? 'UTF-8' : $detected );
		$this->assertNotSame( "Titolo \u{FFFD}", $wp, 'this bench repairs with mbstring in another way' );
		// The author, an import by the author, an import by an administrator: none is trusted.
		foreach ( array( array( $this->builder(), false ), array( $this->builder(), true ), array( 1, true ) ) as $case ) {
			wp_set_current_user( $case[0] );
			$copy   = $versioning->working_copy( Documents::STYLES );
			$commit = $versioning->save_dev( Documents::STYLES, $payload, (string) $copy['head'], (string) $copy['base'], 'test', false, $case[1] );
			$this->assertFalse( $commit['trusted'] );
			$this->assertSame( $commit['id'], $versioning->store()->head( Documents::STYLES, Store::DEV ), 'the head moved' );
			$kept = $versioning->store()->get( $commit['id'] );
			$this->assertIsArray( $kept );
			$this->assertSame( array( array( 'id' => 'a', 'name' => "Titolo \u{FFFD}", 'css' => '.a{gap:0}' ) ), $kept['payload']['globals'] );
			$this->assertSame( $kept['payload'], $commit['payload'], 'the commit returned holds what was written' );
		}
		wp_set_current_user( 1 );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$copy   = $versioning->working_copy( Documents::STYLES );
		$commit = $versioning->save_dev( Documents::STYLES, $payload, (string) $copy['head'], (string) $copy['base'], 'test' );
		$this->assertTrue( $commit['trusted'] );
		$kept = $versioning->store()->get( $commit['id'] );
		$this->assertIsArray( $kept );
		$this->assertSame( array( array( 'id' => 'a', 'name' => $wp, 'css' => '.a{gap:0}' ) ), $kept['payload']['globals'], 'a trusted version is repaired by WP, as before' );
	}
}
