<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

final class RestTest extends IntegrationTestCase {

	private function page(): int {
		return $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array( 'class' => 'x' ), array( $this->text( 'Ciao' ) ), 'restp001' ) ) ),
			)
		);
	}

	/** A builder without unfiltered_html: the case the API must not trust. */
	private function restricted_builder(): int {
		$id   = $this->user( 'author' );
		$user = get_userdata( $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		return $id;
	}

	public function test_documents_require_xb_build_and_edit_post(): void {
		$id = $this->page();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', "/documents/$id" )->get_status() );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', "/documents/$id" )->get_status(), 'editors lack xb_build' );
		wp_set_current_user( $this->restricted_builder() );
		$this->assertSame( 403, $this->request( 'GET', "/documents/$id" )->get_status(), 'xb_build alone does not grant edit_post on others\' pages' );
		wp_set_current_user( 1 );
		$response = $this->request( 'GET', "/documents/$id" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'restp001', (string) $response->get_data()['content'] );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
	}

	public function test_save_commits_to_dev_detects_conflicts_and_sanitizes(): void {
		$builder = $this->restricted_builder();
		wp_set_current_user( $builder );
		$id      = $this->post(
			array(
				'post_author'  => $builder,
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->text( 'v1' ) ) ),
			)
		);
		$loaded  = $this->request( 'GET', "/documents/$id" )->get_data();
		$content = $this->markup( array( $this->el( 'a', array( 'href' => 'javascript:alert(1)', 'onclick' => 'x' ), array( $this->text( 'v2' ) ) ) ) );

		$saved = $this->request(
			'POST',
			"/documents/$id",
			array(
				'content' => $content,
				'head'    => $loaded['head'],
				'base'    => $loaded['base'],
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$dev = (string) $saved->get_data()['content'];
		$this->assertStringContainsString( 'v2', $dev );
		$this->assertStringNotContainsString( 'javascript:', $dev, 'dev commits are sanitized with the author\'s permissions' );
		$this->assertStringNotContainsString( 'onclick', $dev );
		$this->assertStringContainsString( 'v1', $this->content( $id ), 'the live page changes only on publish' );
		$this->assertFalse( $saved->get_data()['published'] );

		$stale = $this->request(
			'POST',
			"/documents/$id",
			array(
				'content' => $content,
				'head'    => $loaded['head'],
				'base'    => $loaded['base'],
			)
		);
		$this->assertSame( 409, $stale->get_status() );
	}

	public function test_render_annotates_and_never_shows_unstorable_markup(): void {
		$this->component( 'rest/box', array( $this->el( 'div', array( 'class' => 'box' ), array( $this->block( 'xb/slot', array( 'name' => 'default' ) ) ) ) ) );
		$content = $this->markup(
			array(
				$this->el( 'section', array( 'onclick' => 'alert(1)' ), array(), 'secuid01' ),
				$this->block(
					'xb/component',
					array(
						'uid' => 'compuid1',
						'ref' => 'rest/box',
					),
					array( $this->block( 'xb/slot-fill', array( 'name' => 'default' ), array( $this->el( 'em', array(), array( $this->text( 'dentro' ) ), 'filluid1' ) ) ) )
				),
			)
		);

		$response = $this->request( 'POST', '/render', array( 'content' => $content ) );
		$this->assertSame( 200, $response->get_status() );
		$html = (string) $response->get_data()['html'];
		$this->assertStringContainsString( 'data-xb-uid="secuid01"', $html );
		$this->assertStringContainsString( '<div class="box" data-xb-uid="compuid1">', $html, 'elements of a component select the instance' );
		$this->assertStringContainsString( '<em data-xb-uid="filluid1">dentro</em>', $html, 'slot content keeps its own uids' );
		$this->assertStringContainsString( 'onclick', $html, 'administrators preview what they can store' );

		wp_set_current_user( $this->restricted_builder() );
		$html = (string) $this->request( 'POST', '/render', array( 'content' => $content ) )->get_data()['html'];
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	/** The canvas gets what it draws with: the site-wide CSS once per hash, the stored styles only when asked. */
	public function test_render_sends_only_what_the_canvas_needs(): void {
		$content = serialize_block( $this->el( 'p', array(), array( $this->text( 'x' ) ), 'rendcss1' ) );
		$first   = $this->request( 'POST', '/render', array( 'content' => $content ) )->get_data();
		$this->assertArrayNotHasKey( 'css', $first, 'the canvas compiles the stored styles itself' );
		$this->assertIsString( $first['globalCss'] );
		$this->assertSame( md5( $first['globalCss'] ), $first['globalHash'] );

		$again = $this->request( 'POST', '/render', array( 'content' => $content, 'global_hash' => $first['globalHash'] ) )->get_data();
		$this->assertArrayNotHasKey( 'globalCss', $again, 'unchanged: not sent again' );
		$this->assertSame( $first['globalHash'], $again['globalHash'] );
		$this->assertStringContainsString( 'data-xb-uid="rendcss1"', (string) $again['html'] );

		$stale = $this->request( 'POST', '/render', array( 'content' => $content, 'global_hash' => md5( 'older' ) ) )->get_data();
		$this->assertSame( $first['globalCss'], $stale['globalCss'] );

		$api = $this->request( 'POST', '/render', array( 'content' => $content, 'css' => true ) )->get_data();
		\XB\Versioning\Preview::use_dev();
		try {
			$this->assertSame( \XB\Plugin::instance()->styles->all_css(), $api['css'], 'the JS API render() still gets the dev styles' );
		} finally {
			\XB\Versioning\Preview::reset();
		}
	}

	/** V15: a save answers without the content when it stored exactly what was sent. */
	public function test_a_lean_save_repeats_the_content_only_when_it_changed(): void {
		$author  = $this->restricted_builder();
		$page    = $this->post( array( 'post_type' => 'post', 'post_author' => $author, 'post_content' => $this->markup( array( $this->text( 'prima' ) ) ) ) );
		$content = $this->markup( array( $this->el( 'p', array( 'onclick' => 'alert(1)' ), array( $this->text( 'dopo' ) ), 'leanp001' ) ) );
		$lean    = $this->save_dev( $page, $content, array( 'lean' => true ) );
		$this->assertSame( 200, $lean->get_status() );
		$this->assertArrayNotHasKey( 'content', $lean->get_data(), 'stored as sent' );
		$this->assertTrue( $lean->get_data()['unchanged'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $lean->get_data()['head'] );
		$this->assertArrayHasKey( 'content', $this->save_dev( $page, $content . ' ' )->get_data(), 'without lean, as before' );

		// Filtered for an author without unfiltered_html: the stored content comes back.
		wp_set_current_user( $author );
		$evil     = $this->markup( array( $this->el( 'p', array( 'onmouseover' => 'alert(2)' ), array( $this->text( 'mio' ) ), 'leanp002' ) ) );
		$filtered = $this->save_dev( $page, $evil, array( 'lean' => true ) )->get_data();
		$this->assertArrayHasKey( 'content', $filtered );
		$this->assertStringNotContainsString( 'onmouseover', (string) $filtered['content'] );
	}

	public function test_styles_are_validated(): void {
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$heads = $this->request( 'GET', '/styles' )->get_data();
		$ok    = $this->request(
			'POST',
			'/styles',
			array(
				'head'   => $heads['head'],
				'base'   => $heads['base'],
				'styles' => array(
					array(
						'id'       => 'card',
						'selector' => '.card',
						'css'      => 'padding:1rem',
					),
				),
			)
		);
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( '.card', $ok->get_data()['styles'][0]['selector'] );

		$bad = $this->request(
			'POST',
			'/styles',
			array(
				'head'   => $heads['head'],
				'base'   => $heads['base'],
				'styles' => array(
					array(
						'id'       => 'x',
						'selector' => '.a{} body',
						'css'      => '',
					),
				),
			)
		);
		$this->assertSame( 400, $bad->get_status() );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/styles', array( 'styles' => array(), 'head' => $heads['head'], 'base' => $heads['base'] ) )->get_status() );
	}

	public function test_builder_style_saves_keep_css_media_mappings_and_framework_provenance(): void {
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$heads = $this->request( 'GET', '/styles' )->get_data();
		$mapping = array( 'reference' => '../media/hero.png?v=2', 'assetId' => '550e8400-e29b-41d4-a716-446655440101' );
		$source = array( 'kind' => 'user-css', 'frameworkId' => 'core-framework', 'name' => 'Core Framework', 'rightsConfirmed' => true );
		$saved = $this->request(
			'POST',
			'/styles',
			array(
				'head' => $heads['head'],
				'base' => $heads['base'],
				'styles' => array( array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(../media/hero.png?v=2)', 'assets' => array( $mapping ) ) ),
				'globals' => array( array( 'id' => 'framework', 'css' => '@font-face{src:url(brand.woff2)}', 'assets' => array( array( 'reference' => 'brand.woff2', 'assetId' => '550e8400-e29b-41d4-a716-446655440102' ) ), 'source' => $source ) ),
			)
		);
		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );

		// Older editor payloads omit these metadata fields; a CSS edit must not erase the round-trip links.
		$heads = $this->request( 'GET', '/styles' )->get_data();
		$saved = $this->request(
			'POST',
			'/styles',
			array(
				'head' => $heads['head'],
				'base' => $heads['base'],
				'styles' => array( array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(../media/hero.png?v=2);background-size:cover' ) ),
				'globals' => array( array( 'id' => 'framework', 'css' => '@font-face{src:url(brand.woff2);font-display:swap}' ) ),
			)
		);
		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$this->assertSame( array( $mapping ), $saved->get_data()['styles'][0]['assets'] );
		$this->assertSame( 'Core Framework', $saved->get_data()['globals'][0]['source']['name'] );
		$this->assertSame( '550e8400-e29b-41d4-a716-446655440102', $saved->get_data()['globals'][0]['assets'][0]['assetId'] );
	}

	public function test_components_are_listed_for_autocomplete(): void {
		$this->component( 'rest/listed', array( $this->text( 'x' ) ), array( 'title' => array( 'type' => 'string' ) ) );
		$refs = array_column( (array) $this->request( 'GET', '/components' )->get_data(), 'ref' );
		$this->assertContains( 'rest/listed', $refs );
	}

	public function test_builder_screen_requires_permissions(): void {
		$id = $this->page();
		$this->assertTrue( \XB\Admin\BuilderScreen::can_open( $id ) );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertFalse( \XB\Admin\BuilderScreen::can_open( $id ) );
	}

	/** Note 40 of the 3 November audit: REST publishes only the documents the publisher can edit, and never names the others. */
	public function test_publish_skips_documents_the_publisher_cannot_edit(): void {
		wp_set_current_user( 1 );
		$id = $this->page();
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( array( $this->el( 'p', array( 'class' => 'x' ), array( $this->text( 'Nascosta' ) ), 'restp001' ) ) ) )->get_status() );
		$publisher = $this->restricted_builder();
		( new \WP_User( $publisher ) )->add_cap( 'xb_publish' );
		wp_set_current_user( $publisher );
		$named = $this->publish( array( 'post:' . $id ) );
		$this->assertSame( 400, $named->get_status(), 'named explicitly: nothing to publish' );
		$this->assertStringNotContainsString( (string) $id, (string) wp_json_encode( $named->get_data() ) );
		$own = $this->post( array( 'post_author' => $publisher, 'post_content' => $this->markup( array( $this->text( 'v1' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $own, $this->markup( array( $this->text( 'Mia' ) ) ) )->get_status() );
		$this->assertSame( 200, $this->publish()->get_status(), 'publish everything: what the publisher sees' );
		$this->assertStringContainsString( 'Mia', $this->content( $own ) );
		$this->assertStringNotContainsString( 'Nascosta', $this->content( $id ) );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$this->assertStringContainsString( 'Nascosta', $this->content( $id ) );
	}
}
