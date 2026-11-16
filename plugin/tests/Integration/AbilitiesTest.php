<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Ai\HtmlImport;
use XB\Ai\Operations;
use XB\Versioning\Merge;
use XB\Versioning\ReleaseChecks;

/** XB abilities for AI agents, called the way an MCP adapter calls them. */
final class AbilitiesTest extends IntegrationTestCase {

	private function ability( string $name ): \WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( \WP_Ability::class, $ability, $name );
		return $ability;
	}

	/** @param array<string, mixed> $input */
	private function call( string $name, array $input ): mixed {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, $name );
		return $ability->execute( $input );
	}

	private function page(): int {
		return $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'section', array( 'class' => 'hero' ), array( $this->el( 'h1', array(), array( $this->text( 'Titolo', 'abtext01' ) ), 'abhead01' ) ), 'absect01' ) ) ),
			)
		);
	}

	/** I7: remote agents read the same manual, documentation and brief as the local connector. */
	public function test_remote_agents_get_the_manual_the_docs_and_the_brief(): void {
		$manual = $this->call( 'xb/manual', array() );
		$this->assertSame( (string) file_get_contents( dirname( __DIR__, 2 ) . '/connector/HISTOS-AI.md' ), $manual['text'], 'the manual of the connector, unchanged' );

		$index = $this->call( 'xb/docs', array() );
		$this->assertSame( 'indice', $index['key'] );
		$style = $this->call( 'xb/docs', array( 'topic' => 'stile' ) );
		$this->assertSame( 'stile', $style['key'] );
		$this->assertNotSame( '', $style['text'] );
		// The short path for small models and the map every other path starts from reach remote agents too.
		$this->assertStringContainsString( 'leggi `rapido`, poi solo la ricetta', $index['text'] );
		$this->assertStringContainsString( 'Prima di dire «fatto»', $this->call( 'xb/docs', array( 'topic' => 'rapido' ) )['text'] );
		$this->assertSame( 'ecosistema', $this->call( 'xb/docs', array( 'topic' => 'ecosistema' ) )['key'] );
		$this->assertStringContainsString( 'Argomento sconosciuto: segreti', $this->call( 'xb/docs', array( 'topic' => 'segreti' ) )['text'] );

		// A loop query in the dev settings, as the builder's /queries route reports it.
		$queries = static function ( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
			if ( '/xb/v1/queries' === $request->get_route() && $response instanceof \WP_REST_Response ) {
				$data            = (array) $response->get_data();
				$data['presets'] = array( 'ab_prossimi' => array( 'type' => 'posts', 'args' => array( 'category' => '$luogo' ) ) );
				$response->set_data( $data );
			}
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $queries, 10, 3 );
		try {
			$brief = $this->call( 'xb/brief', array() );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $queries, 10 );
		}
		$this->assertNull( $brief['document'] );
		$this->assertContains( array( 'name' => 'ab_prossimi', 'type' => 'posts', 'params' => array( 'luogo' ) ), $brief['data']['loopQueries'], 'the dev loop queries, with their parameters' );
		$this->assertArrayHasKey( 'mobile', (array) $brief['mediaQueries'] );
		$this->assertIsArray( $brief['blocks'] );

		wp_set_current_user( $this->user( 'subscriber' ) );
		foreach ( array( 'xb/manual', 'xb/docs', 'xb/brief' ) as $name ) {
			$this->assertFalse( $this->ability( $name )->check_permissions( array() ), $name );
		}
	}

	public function test_abilities_are_registered_public_and_annotated(): void {
		foreach ( array( 'xb/list-documents', 'xb/get-document', 'xb/render-document', 'xb/apply-operations', 'xb/set-style', 'xb/get-framework', 'xb/list-changes', 'xb/preview-url', 'xb/publish-release', 'xb/manual', 'xb/docs', 'xb/brief', 'xb/last-checks' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertTrue( (bool) ( $ability->get_meta()['public'] ?? false ), $name );
		}
		$this->assertFalse( $this->ability( 'xb/publish-release' )->get_meta()['annotations']['destructive'], 'it only prepares: a person publishes' );
		$this->assertTrue( $this->ability( 'xb/get-document' )->get_meta()['annotations']['readonly'] );
	}

	/** V4.1 and V6.3: the outcome of the checks of the last release, for agents and for other plugins, read-only. */
	public function test_last_checks_are_read_only_for_builders(): void {
		$this->assertStringContainsString( 'leggi `xb/last-checks`', $this->call( 'xb/manual', array() )['text'], 'the manual tells agents to read it before asking to publish' );
		$before = get_option( ReleaseChecks::OPTION );
		try {
			delete_option( ReleaseChecks::OPTION );
			$this->assertTrue( function_exists( 'xb_last_checks' ) );
			$this->assertNull( xb_last_checks() );
			$this->assertSame( array( 'checks' => null ), $this->call( 'xb/last-checks', array() ) );

			$stored = array(
				'version'  => '2.3.4',
				'at'       => '2026-11-13T08:00:00+00:00',
				'errors'   => 1,
				'warnings' => 2,
				'rules'    => array(
					'img-alt'   => 1,
					'noindex'   => 2,
					'<made-up>' => 3,
				),
				'pages'    => array(
					'checked' => 6,
					'total'   => 9,
				),
			);
			update_option( ReleaseChecks::OPTION, $stored, false );
			$expected = array(
				'version'  => '2.3.4',
				'at'       => '2026-11-13T08:00:00+00:00',
				'errors'   => 1,
				'warnings' => 2,
				'rules'    => array(
					'img-alt' => 1,
					'noindex' => 2,
				),
				'pages'    => array(
					'checked' => 6,
					'total'   => 9,
				),
			);
			$this->assertSame( $expected, xb_last_checks(), 'checked again as it is read' );
			$this->assertSame( array( 'checks' => $expected ), $this->call( 'xb/last-checks', array() ) );
			$this->assertSame( $stored, get_option( ReleaseChecks::OPTION ), 'reading writes nothing' );

			update_option( ReleaseChecks::OPTION, array_merge( $stored, array( 'errors' => 'nessuno' ) ), false );
			$this->assertNull( xb_last_checks() );

			$ability = $this->ability( 'xb/last-checks' );
			$this->assertTrue( (bool) ( $ability->get_meta()['public'] ?? false ) );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
			$this->assertTrue( $ability->check_permissions( array() ) );
			wp_set_current_user( $this->user( 'subscriber' ) );
			$this->assertFalse( $ability->check_permissions( array() ), 'only who has xb_build' );
		} finally {
			false === $before ? delete_option( ReleaseChecks::OPTION ) : update_option( ReleaseChecks::OPTION, $before, false );
		}
	}

	public function test_agent_edits_land_in_dev_only(): void {
		$id  = $this->page();
		$doc = $this->call( 'xb/get-document', array( 'id' => $id ) );
		$this->assertIsArray( $doc );
		$this->assertSame( 'element', $doc['tree'][0]['type'] );

		$result = $this->call(
			'xb/apply-operations',
			array(
				'id'         => $id,
				'head'       => $doc['head'],
				'operations' => array(
					array( 'op' => 'set_text', 'uid' => 'abtext01', 'content' => 'Titolo scritto dall\'agente' ),
					array( 'op' => 'set_attribute', 'uid' => 'absect01', 'name' => 'data-tone', 'value' => 'calm' ),
					array( 'op' => 'insert_html', 'parent' => 'absect01', 'html' => '<p class="lead">Ciao <strong>{site.name}</strong></p><img src="/x.png" alt="">' ),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 3, $result['applied'] );
		$html = $this->call( 'xb/render-document', array( 'id' => $id ) );
		$this->assertStringContainsString( '<h1>Titolo scritto dall&#039;agente</h1>', $html['html'] );
		$this->assertStringContainsString( '<p class="lead">', $html['html'] );
		$this->assertStringContainsString( '<strong>XB Dev</strong>', $html['html'] );
		$this->assertSame( 'Titolo', Merge::texts( parse_blocks( $this->content( $id ) ) )['abtext01'], 'the live page is untouched' );
		$this->assertContains( 'post:' . $id, array_column( (array) $this->call( 'xb/list-changes', array() ), 'doc' ) );

		$stale = $this->call( 'xb/apply-operations', array( 'id' => $id, 'head' => $doc['head'], 'operations' => array( array( 'op' => 'remove', 'uid' => 'abhead01' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $stale );
		$this->assertSame( 409, $stale->get_error_data()['status'] ?? null );
	}

	public function test_invalid_batches_change_nothing(): void {
		$id     = $this->page();
		$before = $this->call( 'xb/get-document', array( 'id' => $id ) );
		$result = $this->call(
			'xb/apply-operations',
			array(
				'id'         => $id,
				'operations' => array(
					array( 'op' => 'set_text', 'uid' => 'abtext01', 'content' => 'cambiato' ),
					array( 'op' => 'set_attribute', 'uid' => 'absect01', 'name' => 'xb:label', 'value' => 'x' ),
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Operazione 1', $result->get_error_message() );
		$this->assertSame( $before['head'], $this->call( 'xb/get-document', array( 'id' => $id ) )['head'] );
	}

	public function test_restricted_agents_get_the_same_sanitizer(): void {
		$author = $this->user( 'author' );
		$user   = get_userdata( $author );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$id = $this->post(
			array(
				'post_author'  => $author,
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->el( 'div', array(), array(), 'abdiv001' ) ) ),
			)
		);
		$result = $this->call(
			'xb/apply-operations',
			array(
				'id'         => $id,
				'operations' => array(
					array( 'op' => 'insert_html', 'parent' => 'abdiv001', 'html' => '<img src=x onerror=alert(1)><script>alert(2)</script><a href="javascript:alert(3)">x</a>' ),
					array( 'op' => 'set_attribute', 'uid' => 'abdiv001', 'name' => 'onclick', 'value' => 'alert(4)' ),
				),
			)
		);
		$this->assertIsArray( $result );
		$html = $this->call( 'xb/render-document', array( 'id' => $id ) )['html'];
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'onclick', $html );

		// Other people's pages and publishing stay out of reach.
		$this->assertFalse( $this->ability( 'xb/get-document' )->check_permissions( array( 'id' => $this->page() ) ) );
		$this->assertFalse( $this->ability( 'xb/publish-release' )->check_permissions( array( 'notes' => 'x', 'confirm' => true ) ) );
	}

	/**
	 * An agent working for an administrator writes as an author without unfiltered_html: instructions planted in
	 * content it read cannot make it save script, and its commit is untrusted (the preview blocks scripts, the
	 * release filters it again). What the administrator already wrote stays.
	 */
	public function test_an_administrators_agent_writes_as_a_restricted_author(): void {
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$id     = $this->page();
		$result = $this->call(
			'xb/apply-operations',
			array(
				'id'         => $id,
				'operations' => array(
					array( 'op' => 'insert_html', 'parent' => 'absect01', 'html' => '<img src=x onerror=alert(1)><script>alert(2)</script><p>Testo</p>' ),
					array( 'op' => 'set_attribute', 'uid' => 'absect01', 'name' => 'onclick', 'value' => 'alert(4)' ),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$html = $this->call( 'xb/render-document', array( 'id' => $id ) )['html'];
		$this->assertStringContainsString( 'Testo', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringContainsString( 'Titolo', $html, 'what was there stays' );
		$commit = \XB\Plugin::instance()->versioning->store()->get( (string) $result['head'] );
		$this->assertIsArray( $commit );
		$this->assertFalse( (bool) $commit['trusted'], 'untrusted: the preview blocks scripts, the release filters it again' );
	}

	/** Notes 17 and 18 of the 3 November audit: the agent's CSS has the builder's ceiling; its rendering leaves no development view behind. */
	public function test_agent_styles_have_a_ceiling_and_rendering_leaves_no_dev_view(): void {
		// Each call is small (its schema), but many of them add up: the whole style sheet has the ceiling.
		$versioning = \XB\Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( \XB\Versioning\Documents::STYLES );
		$styles     = is_array( $copy['payload']['styles'] ?? null ) ? array_values( $copy['payload']['styles'] ) : array();
		$styles[]   = array( 'id' => 'xb-grande-abcdef', 'selector' => '.xb-grande', 'css' => 'color:red;/*' . str_repeat( 'x', \XB\Rest\Controller::MAX_CSS_BYTES - 10000 ) . '*/' );
		$versioning->save_dev( \XB\Versioning\Documents::STYLES, array( 'styles' => $styles ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'test' );
		$answer = $this->call( 'xb/set-style', array( 'selector' => '.xb-ancora', 'css' => 'color:blue;/*' . str_repeat( 'y', 15000 ) . '*/' ) );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertStringContainsString( 'dimensione massima', $answer->get_error_message() );
		$id = $this->page();
		$this->assertFalse( \XB\Versioning\Preview::active() );
		$this->call( 'xb/render-document', array( 'id' => $id ) );
		$this->assertFalse( \XB\Versioning\Preview::active(), 'the request goes on as published' );
	}

	public function test_an_agent_never_publishes_a_person_does(): void {
		$id = $this->page();
		$this->call( 'xb/apply-operations', array( 'id' => $id, 'operations' => array( array( 'op' => 'set_text', 'uid' => 'abtext01', 'content' => 'Nuovo' ) ) ) );
		foreach ( array( false, true ) as $confirm ) {
			$answer = $this->call( 'xb/publish-release', array( 'notes' => 'agente', 'confirm' => $confirm ) );
			$this->assertIsArray( $answer );
			$this->assertSame( 'approval-required', $answer['status'] );
			$this->assertContains( 'post:' . $id, array_column( $answer['changes'], 'doc' ) );
			$this->assertSame( 'Titolo', Merge::texts( parse_blocks( $this->content( $id ) ) )['abtext01'], 'still not live, even with confirm=true' );
		}
		// A publisher who cannot edit that page does not learn its title or that it changed.
		$publisher = $this->user( 'author' );
		( new \WP_User( $publisher ) )->add_cap( 'xb_build' );
		( new \WP_User( $publisher ) )->add_cap( 'xb_publish' );
		wp_set_current_user( $publisher );
		$this->assertNotContains( 'post:' . $id, array_column( \XB\Ai\Abilities::publish( array() )['changes'], 'doc' ) );
	}

	/** Note 19 of the 3 November audit: remove and move find only Histeon nodes, as every other operation. */
	public function test_operations_never_take_a_foreign_block(): void {
		$blocks = parse_blocks( '<!-- wp:core/paragraph {"uid":"forei001"} --><p>altro plugin</p><!-- /wp:core/paragraph -->' );
		foreach ( array( array( 'op' => 'remove', 'uid' => 'forei001' ), array( 'op' => 'move', 'uid' => 'forei001', 'parent' => null, 'index' => 0 ) ) as $op ) {
			try {
				Operations::apply( $blocks, array( $op ) );
				$this->fail( $op['op'] . ' took a foreign block' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'uid', $e->getMessage() );
			}
		}
	}

	public function test_html_import_and_tree_operations(): void {
		$blocks = HtmlImport::to_blocks( '<ul class="list"><li>uno<li>due</ul><!-- note --><p>a <em>b</em> {x.y}</p><br>' );
		$tree   = Operations::outline( $blocks );
		$this->assertSame( array( 'ul', 'p', 'br' ), array_column( $tree, 'tag' ) );
		$this->assertCount( 2, $tree[0]['children'], 'implicitly closed <li> are siblings' );
		$this->assertSame( '{x.y}', $tree[1]['children'][2]['content'] ?? null, 'expressions survive' );

		$ul    = $tree[0]['uid'];
		$li2   = $tree[0]['children'][1]['uid'];
		$moved = Operations::outline( Operations::apply( $blocks, array( array( 'op' => 'move', 'uid' => $li2, 'parent' => null, 'index' => 0 ) ) ) );
		$this->assertSame( $li2, $moved[0]['uid'] );
		$this->expectException( \InvalidArgumentException::class );
		Operations::apply( $blocks, array( array( 'op' => 'move', 'uid' => $ul, 'parent' => $tree[0]['children'][0]['uid'] ) ) );
	}
}
