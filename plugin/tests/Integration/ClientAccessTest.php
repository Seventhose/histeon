<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;
use XB\Admin\ClientAccessScreen;
use XB\Capabilities;
use XB\Plugin;
use XB\Security\ClientAccess;
use XB\Styles\PageLook;
use XB\Versioning\Documents;

/** Each client's permissions and the page's own look (voce 186; docs/spec/client-mode.md). */
final class ClientAccessTest extends IntegrationTestCase {

	private function page_markup( string $text ): string {
		return $this->markup(
			array(
				$this->el( 'h1', array( 'class' => 'title' ), array( $this->text( $text, 'tx000001' ) ), 'el000001' ),
			)
		);
	}

	private function page( string $text = 'Titolo' ): int {
		return $this->post( array( 'post_type' => 'page', 'post_content' => $this->page_markup( $text ) ) );
	}

	private function client(): int {
		Capabilities::grant();
		return $this->user( Capabilities::CLIENT_ROLE );
	}

	private function as( int $user ): void {
		wp_set_current_user( 0 );
		wp_set_current_user( $user );
	}

	/** @param array<string, mixed> $settings */
	private function grant( int $client, array $settings ): void {
		$me = get_current_user_id();
		$this->as( 1 );
		ClientAccess::update( $client, $settings );
		$this->as( $me );
	}

	/**
	 * Saves through the builder API with the heads the document reports.
	 *
	 * @param array<string, mixed> $extra
	 */
	private function save( int $page, ?string $content = null, array $extra = array() ): \WP_REST_Response {
		$doc = $this->request( 'GET', '/documents/' . $page )->get_data();
		$this->assertIsArray( $doc );
		return $this->request( 'POST', '/documents/' . $page, array( 'content' => $content ?? (string) $doc['content'], 'head' => (string) $doc['head'], 'base' => (string) $doc['base'] ) + $extra );
	}

	/** @return list<array{selector: string, css: string}> */
	private static function look( string $css, string $selector = '.title' ): array {
		return array(
			array(
				'selector' => $selector,
				'css'      => $css,
			),
		);
	}

	public function test_nothing_is_gained_with_the_update(): void {
		$page   = $this->page();
		$client = $this->client();
		$this->as( $client );
		$this->assertSame( ClientAccess::defaults(), ClientAccess::settings( $client ) );
		$this->assertSame( 'content', ClientAccess::level( $page ) );
		$this->assertSame( array(), ClientAccess::groups( $page ) );
		$this->assertFalse( Capabilities::content_props() );
		$this->assertTrue( current_user_can( 'edit_post', $page ), 'WordPress editing as before' );

		$doc = $this->request( 'GET', '/documents/' . $page );
		$this->assertSame( 200, $doc->get_status() );
		$this->assertSame( array( 'level' => 'content', 'groups' => array() ), $doc->get_data()['access'] );
		$this->assertSame( 403, $this->save( $page, null, array( 'styles' => self::look( 'color: var(--primary);' ) ) )->get_status(), 'no page look by default' );
		$this->assertSame( 200, $this->save( $page, $this->page_markup( 'Nuovo' ) )->get_status(), 'texts as before' );
	}

	public function test_the_page_look_stays_on_the_page_and_in_the_groups_given(): void {
		$page   = $this->page();
		$other  = $this->page( 'Altra' );
		$client = $this->client();
		$this->grant(
			$client,
			array(
				'look'   => true,
				'groups' => array( 'text' ),
			)
		);
		$shared = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload'];
		$this->as( $client );
		$this->assertSame( 'look', ClientAccess::level( $page ) );
		$this->assertSame( array( 'text' ), ClientAccess::groups( $page ) );

		$css = "color: var(--primary);\n@media (--tablet) {\n  &:hover {\n    color: var(--secondary);\n  }\n}\n";
		$ok  = $this->save( $page, null, array( 'styles' => self::look( $css ) ) );
		$this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertSame( self::look( $css ), $ok->get_data()['pageStyles'] );

		// Outside the page's scope, or outside the grammar: refused before anything is written.
		foreach (
			array(
				'descendant selector' => self::look( 'color: var(--primary);', '.title .x' ),
				'tag selector'        => self::look( 'color: var(--primary);', 'body' ),
				'nested selector'     => self::look( '& .x { color: var(--primary); }' ),
				'other property'      => self::look( 'content: "x";' ),
				'url'                 => self::look( 'background: url(https://evil.test/x.png);' ),
				'free value'          => self::look( 'color: red;' ),
				'important'           => self::look( 'color: var(--primary) !important;' ),
				'import'              => self::look( '@import url(x.css);' ),
				'other media'         => self::look( '@media screen { color: var(--primary); }' ),
				'closing brace'       => self::look( 'color: var(--primary); } body { color: var(--primary)' ),
				'comment'             => self::look( '/* x */ color: var(--primary);' ),
				'state in state'      => self::look( '&:hover { &:active { color: var(--primary); } }' ),
			) as $what => $styles
		) {
			$this->assertSame( 400, $this->save( $page, null, array( 'styles' => $styles ) )->get_status(), $what );
		}
		$this->assertSame( 403, $this->save( $page, null, array( 'styles' => self::look( $css . "padding: var(--space-m);\n" ) ) )->get_status(), 'a group not given (space)' );
		$this->assertSame( 403, $this->request( 'POST', '/styles', array( 'styles' => array(), 'globals' => array(), 'head' => 'x', 'base' => 'x' ) )->get_status(), 'never the site styles' );
		$this->assertSame( $shared, Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload'], 'the shared classes did not change' );

		// A text saved later keeps the page's look; another page has none.
		$text = $this->save( $page, $this->page_markup( 'Dopo' ) );
		$this->assertSame( 200, $text->get_status() );
		$this->assertSame( self::look( $css ), $text->get_data()['pageStyles'] );
		$this->assertSame( array(), $this->request( 'GET', '/documents/' . $other )->get_data()['pageStyles'] );

		// In development until a release: then on that page only, under its class.
		$this->assertSame( array(), PageLook::stored( $page ) );
		$this->as( 1 );
		$this->assertSame( 200, $this->publish( array( Documents::for_post( $page ) ) )->get_status() );
		$this->assertSame( self::look( $css ), PageLook::stored( $page ) );
		$this->assertSame( self::look( $css ), Documents::read( Documents::for_post( $page ) )[ PageLook::KEY ] );

		$this->go_to( $page );
		$this->assertContains( 'xb-page-' . $page, PageLook::body_class( array() ) );
		ob_start();
		PageLook::print();
		$printed = (string) ob_get_clean();
		$this->assertStringContainsString( '.xb-page-' . $page . ' .title{color: var(--primary);', $printed );
		$this->assertStringNotContainsString( '--tablet', $printed, 'named widths resolved' );
		$this->go_to( $other );
		ob_start();
		PageLook::print();
		$this->assertSame( '', (string) ob_get_clean(), 'not on another page' );
	}

	public function test_pages_and_levels(): void {
		$look    = $this->page( 'A' );
		$view    = $this->page( 'B' );
		$content = $this->page( 'C' );
		$none    = $this->page( 'D' );
		$client  = $this->client();
		$this->grant(
			$client,
			array(
				'look'   => true,
				'groups' => array( 'text', 'space' ),
				'pages'  => 'list',
				'levels' => array(
					$look    => 'look',
					$view    => 'view',
					$content => 'content',
				),
			)
		);
		$this->as( $client );
		$style = array( 'styles' => self::look( 'padding: var(--space-m);' ) );

		// Not listed: nothing, in the builder and in WordPress.
		$this->assertSame( 'none', ClientAccess::level( $none ) );
		$this->assertFalse( BuilderScreen::can_open( $none ) );
		$this->assertFalse( current_user_can( 'edit_post', $none ) );
		$this->assertFalse( current_user_can( 'edit_page', $none ), 'the type\'s own name for it too' );
		$this->assertSame( 403, $this->request( 'GET', '/documents/' . $none )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/documents/' . $none, array( 'content' => $this->page_markup( 'x' ), 'head' => str_repeat( 'a', 32 ), 'base' => str_repeat( 'a', 32 ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/render', array( 'post_id' => $none, 'content' => $this->page_markup( 'D' ) ) )->get_status() );
		$rest = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $none );
		$rest->set_param( 'title', 'Nuovo titolo' );
		$this->assertSame( 403, rest_do_request( $rest )->get_status(), 'the block editor cannot save it either' );

		// Seen only: opens in the builder, draws, saves nothing; not editable in WordPress.
		$this->assertTrue( BuilderScreen::can_open( $view ) );
		$this->assertFalse( current_user_can( 'edit_post', $view ) );
		$doc = $this->request( 'GET', '/documents/' . $view );
		$this->assertSame( 200, $doc->get_status() );
		$this->assertSame( 'view', $doc->get_data()['access']['level'] );
		$this->assertSame( 200, $this->request( 'POST', '/render', array( 'post_id' => $view, 'content' => $this->page_markup( 'B' ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/documents/' . $view, array( 'content' => $this->page_markup( 'Altro' ), 'head' => (string) $doc->get_data()['head'], 'base' => (string) $doc->get_data()['base'] ) )->get_status() );
		$rest = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $view );
		$rest->set_param( 'title', 'Nuovo titolo' );
		$this->assertSame( 403, rest_do_request( $rest )->get_status() );

		// Texts and pictures: texts yes, look no.
		$this->assertSame( 200, $this->save( $content, $this->page_markup( 'C2' ) )->get_status() );
		$this->assertSame( 403, $this->save( $content, null, $style )->get_status() );
		$this->assertTrue( current_user_can( 'edit_post', $content ) );

		// Also look: in the groups given.
		$this->assertSame( 200, $this->save( $look, null, $style )->get_status() );

		// The level «look» needs the switch: without it, texts only.
		$this->grant(
			$client,
			array(
				'look'   => false,
				'groups' => array( 'text', 'space' ),
				'pages'  => 'list',
				'levels' => array( $look => 'look' ),
			)
		);
		$this->as( $client );
		$this->assertSame( 'content', ClientAccess::level( $look ) );
		$this->assertSame( 403, $this->save( $look, null, array( 'styles' => self::look( 'padding: var(--space-l);' ) ) )->get_status() );
	}

	public function test_only_who_builds_sets_permissions_and_with_closed_lists(): void {
		$client  = $this->client();
		$other   = $this->user( Capabilities::CLIENT_ROLE );
		$page    = $this->page();
		$builder = $this->user( 'editor' );
		$user    = get_user_by( 'id', $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );

		$this->as( $client );
		$this->assertFalse( ClientAccess::can_manage( $client ), 'not his own' );
		$this->assertFalse( ClientAccess::can_manage( $other ), 'not another client' );
		foreach ( array( $client, $other ) as $target ) {
			try {
				ClientAccess::update( $target, array( 'look' => true, 'groups' => PageLook::GROUPS ) );
				$this->fail( 'a client changed permissions' );
			} catch ( \RuntimeException ) {
				$this->assertSame( ClientAccess::defaults(), ClientAccess::settings( $target ) );
			}
		}

		$this->as( $builder );
		$this->assertTrue( ClientAccess::can_manage( $client ) );
		$this->assertFalse( ClientAccess::can_manage( 1 ), 'never an administrator or a builder' );
		$saved = ClientAccess::update( $client, ClientAccessScreen::from_form( array( 'props' => '1', 'look' => '1', 'groups' => array( 'text', 'look' ), 'pages' => 'list', 'levels' => array( (string) $page => 'look' ) ) ) );
		$this->assertSame( array( 'props' => true, 'look' => true, 'groups' => array( 'text', 'look' ), 'pages' => 'list', 'levels' => array( $page => 'look' ) ), $saved );
		$audit = Plugin::instance()->versioning->store()->audit_log( 1 );
		$this->assertSame( 'client_access', $audit[0]['action'] ?? null );

		foreach (
			array(
				'level'  => array( 'pages' => 'list', 'levels' => array( $page => 'admin' ) ),
				'group'  => array( 'groups' => array( 'all' ) ),
				'pages'  => array( 'pages' => 'some' ),
				'flag'   => array( 'props' => 'yes' ),
				'id'     => array( 'levels' => array( 'x' => 'view' ) ),
				'format' => 'look',
			) as $what => $raw
		) {
			try {
				ClientAccess::update( $client, $raw );
				$this->fail( $what );
			} catch ( \InvalidArgumentException ) {
				$this->assertSame( $saved, ClientAccess::settings( $client ), $what . ': nothing stored' );
			}
		}
		$media = $this->post( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$this->expectException( \InvalidArgumentException::class );
		ClientAccess::update( $client, array( 'pages' => 'list', 'levels' => array( $media => 'view' ) ) );
	}

	public function test_the_screen_shows_the_form_only_to_who_may_manage(): void {
		$client = $this->client();
		$page   = $this->page( 'Contatti' );
		$this->as( 1 );
		$_GET['user'] = (string) $client;
		try {
			ob_start();
			ClientAccessScreen::render();
			$html = (string) ob_get_clean();
		} finally {
			unset( $_GET['user'] );
		}
		$this->assertStringContainsString( 'name="levels[' . $page . ']"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="text"', $html );
		$this->assertTrue( ClientAccess::can_manage( $client ) );
		$this->as( $client );
		$this->assertFalse( ClientAccess::can_manage( $client ) );
		$this->assertFalse( current_user_can( 'xb_build' ) || current_user_can( 'manage_options' ), 'the screen\'s own check refuses him' );
	}

	public function test_props_from_the_role_or_from_the_permissions(): void {
		$client = $this->client();
		$this->as( $client );
		$this->assertFalse( Capabilities::content_props() );
		$this->grant( $client, array( 'props' => true ) );
		$this->assertTrue( Capabilities::content_props() );
		$this->grant( $client, array( 'props' => false ) );
		$this->assertFalse( Capabilities::content_props() );
		get_role( Capabilities::CLIENT_ROLE )?->add_cap( Capabilities::EDIT_PROPS );
		try {
			$this->as( $client );
			$this->assertTrue( Capabilities::content_props(), 'xb_edit_props from the role still works' );
		} finally {
			get_role( Capabilities::CLIENT_ROLE )?->remove_cap( Capabilities::EDIT_PROPS );
		}
	}

	public function test_a_release_applies_only_the_grammar(): void {
		$page = $this->page();
		$this->expectException( \XB\Versioning\VersioningException::class );
		Documents::apply(
			Documents::for_post( $page ),
			array(
				'content' => $this->page_markup( 'x' ),
				'script'  => '',
				'styles'  => self::look( 'background: url(https://evil.test/a.png);' ),
			)
		);
	}
}
