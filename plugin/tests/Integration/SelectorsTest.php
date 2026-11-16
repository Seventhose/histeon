<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Store;

/** Selector manager: usage, rename as dev commits, delete — docs/spec/styles.md §4, ADR 0007. */
final class SelectorsTest extends IntegrationTestCase {

	/** A builder without unfiltered_html. */
	private function restricted_builder(): int {
		$id   = $this->user( 'author' );
		$user = get_userdata( $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		return $id;
	}

	/**
	 * Adds style entries (and optionally a global sheet) to the dev styles, as the CSS panel does.
	 *
	 * @param list<array{id: string, selector: string, css: string}> $entries
	 */
	private function add_styles( array $entries, ?string $global = null ): void {
		$current = $this->request( 'GET', '/styles' )->get_data();
		$this->assertIsArray( $current );
		$globals = $current['globals'];
		if ( null !== $global ) {
			$globals[] = array(
				'id'  => 'zq-global',
				'css' => $global,
			);
		}
		$response = $this->request(
			'POST',
			'/styles',
			array(
				'styles'  => array_merge( $current['styles'], $entries ),
				'globals' => $globals,
				'head'    => $current['head'],
				'base'    => $current['base'],
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/** @return array<string, mixed> */
	private function row( string $selector ): array {
		$response = $this->request( 'GET', '/selectors' );
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data()['selectors'] as $row ) {
			if ( $row['selector'] === $selector ) {
				return $row;
			}
		}
		$this->fail( "Selettore $selector non elencato" );
	}

	private function rename( string $from, string $to, bool $dry = false ): \WP_REST_Response {
		return $this->request(
			'POST',
			'/selectors/rename',
			array(
				'from'   => $from,
				'to'     => $to,
				'dryRun' => $dry,
			)
		);
	}

	/** @return array<string, mixed> */
	private function dev_payload( string $doc ): array {
		$store = Plugin::instance()->versioning->store();
		$head  = $store->get( $store->head( $doc, Store::DEV ) );
		$this->assertNotNull( $head );
		return $head['payload'];
	}

	private function dev_trusted( string $doc ): bool {
		$store = Plugin::instance()->versioning->store();
		$head  = $store->get( $store->head( $doc, Store::DEV ) );
		$this->assertNotNull( $head );
		return $head['trusted'];
	}

	/** @return list<string> Class attributes of the dev content, in document order. */
	private function dev_classes( int $post_id ): array {
		$out  = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$out ): void {
			foreach ( $blocks as $block ) {
				if ( is_string( $block['attrs']['attributes']['class'] ?? null ) ) {
					$out[] = $block['attrs']['attributes']['class'];
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( (string) ( $this->dev_payload( 'post:' . $post_id )['content'] ?? '' ) ) );
		return $out;
	}

	public function test_routes_require_xb_build(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/selectors' )->get_status() );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', '/selectors' )->get_status() );
		$this->assertSame( 403, $this->rename( 'a', 'b' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/selectors/delete', array( 'ids' => array( 'x' ) ) )->get_status() );
	}

	public function test_a_class_used_by_scripts_goes_in_the_safelist_from_the_selector_manager(): void {
		update_option( \XB\Styles\PageStyles::SAFELIST, '.is-open', false );
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-tab',
					'selector' => '.zqtab-on',
					'css'      => 'color: red',
				),
			)
		);
		$row = static fn ( array $list ): array => array_values( array_filter( $list['selectors'], static fn ( array $r ): bool => '.zqtab-on' === $r['selector'] ) )[0];
		$this->assertTrue( $row( $this->request( 'GET', '/selectors' )->get_data() )['unused'] );

		$on = $this->request( 'POST', '/selectors/safelist', array( 'name' => '.zqtab-on', 'on' => true ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertSame( array( '.is-open', '.zqtab-on' ), $on->get_data()['safelist'] );
		$listed = $row( $this->request( 'GET', '/selectors' )->get_data() );
		$this->assertTrue( $listed['safelisted'] );
		$this->assertFalse( $listed['unused'] );

		$off = $this->request( 'POST', '/selectors/safelist', array( 'name' => '.zqtab-on', 'on' => false ) );
		$this->assertSame( array( '.is-open' ), $off->get_data()['safelist'] );
		$this->assertSame( '.is-open', get_option( \XB\Styles\PageStyles::SAFELIST ) );

		// Only a class or an id, and only for who may change the settings.
		$this->assertSame( 400, $this->request( 'POST', '/selectors/safelist', array( 'name' => 'div > p', 'on' => true ) )->get_status() );
		wp_set_current_user( $this->restricted_builder() );
		$this->assertSame( 403, $this->request( 'POST', '/selectors/safelist', array( 'name' => '.zqtab-on', 'on' => true ) )->get_status() );
	}

	public function test_usage_counts_bem_children_and_finds_unused_selectors(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-card',
					'selector' => '.zqcard',
					'css'      => 'gap: 1rem; &__title { font-weight: 700 }',
				),
				array(
					'id'       => 'zq-orphan',
					'selector' => '.zqorphan',
					'css'      => 'color: red',
				),
				array(
					'id'       => 'zq-js',
					'selector' => '.zqjs-open',
					'css'      => 'display: block',
				),
				array(
					'id'       => 'zq-h1',
					'selector' => 'h1',
					'css'      => 'margin: 0',
				),
				array(
					'id'       => 'zq-variant',
					'selector' => '.zqbtn--primary',
					'css'      => 'color: blue',
				),
			)
		);
		$this->set_option( 'xb_css_safelist', '.zqjs-*' );
		// Only the hoisted child is on the page: the .zqcard entry is still in use.
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pagina ZQ',
				'post_content' => $this->markup( array( $this->el( 'h2', array( 'class' => 'zqcard__title zqbtn--{props.kind}' ), array( $this->text( 'T' ) ) ) ) ),
			)
		);

		$card = $this->row( '.zqcard' );
		$this->assertSame( 'zqcard', $card['rename'] );
		$this->assertSame( array( 'post:' . $page ), array_column( $card['docs'], 'doc' ) );
		$this->assertSame( 'Pagina ZQ', $card['docs'][0]['label'] );
		$this->assertFalse( $card['unused'] );
		$this->assertTrue( $this->row( '.zqorphan' )['unused'] );
		$this->assertTrue( $this->row( '.zqjs-open' )['safelisted'], 'classes added by scripts are in the safelist' );
		$this->assertFalse( $this->row( '.zqjs-open' )['unused'] );
		$this->assertTrue( $this->row( 'h1' )['always'] );
		$this->assertNull( $this->row( 'h1' )['rename'] );
		$this->assertFalse( $this->row( '.zqbtn--primary' )['unused'], 'a class completed by an interpolation counts by its prefix' );

		// A builder who cannot edit the page sees it only as a count.
		wp_set_current_user( $this->restricted_builder() );
		$card = $this->row( '.zqcard' );
		$this->assertSame( array(), $card['docs'] );
		$this->assertSame( 1, $card['locked'] );
		$this->assertFalse( $card['unused'] );
	}

	public function test_rename_is_prepared_in_dev_and_goes_live_with_a_release(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-card',
					'selector' => '.zqcard',
					'css'      => "padding: 1rem;\n&__title { font-weight: 700 }\n&:hover .zqcard__icon { color: red }\nbackground: url(img/zqcard.png);",
				),
				array(
					'id'       => 'zq-card-dark',
					'selector' => '.zqcard--dark, :is(.zqcard--night)',
					'css'      => 'background: #000',
				),
				array(
					'id'       => 'zq-other',
					'selector' => '.zqcardigan [class*="zqcard"]',
					'css'      => 'content: ".zqcard"',
				),
			),
			"/* .zqcard */ .zqcard:hover { color: blue } .x { background: url(a.zqcard.png) } @media (width > 1px) { .zqcard { gap: 1px } }"
		);
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->el(
							'article',
							array( 'class' => 'zqcard zqcard--dark {props.extra} zqcard--{props.variant} zqcardigan' ),
							array( $this->el( 'h2', array( 'class' => 'zqcard__title' ), array( $this->text( 'Titolo' ) ), 'zqtitle1' ) ),
							'zqcard01'
						),
					)
				),
			)
		);
		$live_before   = $this->content( $page );
		$styles_before = get_option( 'xb_styles' );

		$plan = $this->rename( 'zqcard', 'zqtile', true );
		$this->assertSame( 200, $plan->get_status() );
		$data = $plan->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( array( 'post:' . $page ), array_column( $data['docs'], 'doc' ) );
		$this->assertSame( 4, $data['docs'][0]['classes'] );
		$this->assertNull( Plugin::instance()->versioning->store()->head( 'post:' . $page, Store::DEV ), 'a dry run writes nothing, not even tracking' );

		$done = $this->rename( 'zqcard', 'zqtile' );
		$this->assertSame( 200, $done->get_status(), (string) wp_json_encode( $done->get_data() ) );
		$this->assertSame( array( 'zqtile zqtile--dark {props.extra} zqtile--{props.variant} zqcardigan', 'zqtile__title' ), $this->dev_classes( $page ) );
		$this->assertSame( $live_before, $this->content( $page ), 'prepared in dev, not live' );
		$this->assertSame( $styles_before, get_option( 'xb_styles' ) );

		$styles = array_column( $this->dev_payload( 'styles' )['styles'], null, 'id' );
		$this->assertSame( '.zqtile', $styles['zq-card']['selector'] );
		$this->assertSame( "padding: 1rem;\n&__title { font-weight: 700 }\n&:hover .zqtile__icon { color: red }\nbackground: url(img/zqcard.png);", $styles['zq-card']['css'], 'declarations and url() stay as written' );
		$this->assertSame( '.zqtile--dark, :is(.zqtile--night)', $styles['zq-card-dark']['selector'] );
		$this->assertSame( '.zqcardigan [class*="zqcard"]', $styles['zq-other']['selector'], 'other classes and attribute selectors are kept' );
		$this->assertSame( 'content: ".zqcard"', $styles['zq-other']['css'] );
		$globals = array_column( $this->dev_payload( 'styles' )['globals'], 'css', 'id' );
		$this->assertSame( '/* .zqcard */ .zqtile:hover { color: blue } .x { background: url(a.zqcard.png) } @media (width > 1px) { .zqtile { gap: 1px } }', $globals['zq-global'] );

		$this->assertSame( 200, $this->publish( null, 'rinomina' )->get_status() );
		$this->assertSame( 'zqtile zqtile--dark {props.extra} zqtile--{props.variant} zqcardigan', parse_blocks( $this->content( $page ) )[0]['attrs']['attributes']['class'] ?? null );
		$this->assertContains( '.zqtile', array_column( (array) get_option( 'xb_styles' ), 'selector' ) );

		$audit = Plugin::instance()->versioning->store()->audit_log( 5 );
		$this->assertContains( 'rename_selector', array_column( $audit, 'action' ) );
	}

	public function test_rename_rewrites_the_free_html_occurrences_chosen_one_by_one(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-free',
					'selector' => '.zqfree',
					'css'      => 'color: red',
				),
			)
		);
		$paragraph = '<!-- wp:paragraph {"className":"zqfree"} --><p class="zqfree">Libero</p><!-- /wp:paragraph -->';
		$page      = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->el( 'section', array( 'class' => 'zqfree' ) ),
						$this->block( 'xb/raw', array( 'content' => '<div class="zqfree zqfree__x other">x</div><span class=\'zqfree--on\'>y</span>' ) ),
					)
				) . $paragraph,
			)
		);
		$preview = $this->rename( 'zqfree', 'zqopen', true )->get_data();
		$this->assertTrue( $preview['htmlAllowed'] );
		$mine = array_values( array_filter( $preview['html'], static fn ( array $o ): bool => 'post:' . $page === $o['doc'] ) );
		$this->assertSame(
			array(
				array( 'class="zqfree zqfree__x other"', 'class="zqopen zqopen__x other"' ),
				array( "class='zqfree--on'", "class='zqopen--on'" ),
				array( 'className="zqfree"', 'className="zqopen"' ),
			),
			array_map( static fn ( array $o ): array => array( $o['before'], $o['after'] ), $mine )
		);

		// A stale preview is refused, nothing is written.
		$stale = $this->request( 'POST', '/selectors/rename', array( 'from' => 'zqfree', 'to' => 'zqopen', 'html' => array( array( 'id' => $mine[0]['id'], 'before' => 'class="other"' ) ) ) );
		$this->assertSame( 409, $stale->get_status() );

		// Only the first {#raw} attribute and the paragraph are chosen: the second {#raw} attribute stays.
		$done = $this->request(
			'POST',
			'/selectors/rename',
			array(
				'from' => 'zqfree',
				'to'   => 'zqopen',
				'html' => array(
					array( 'id' => $mine[0]['id'], 'before' => $mine[0]['before'] ),
					array( 'id' => $mine[2]['id'], 'before' => $mine[2]['before'] ),
				),
			)
		);
		$this->assertSame( 200, $done->get_status(), (string) wp_json_encode( $done->get_data() ) );
		$content = (string) $this->dev_payload( 'post:' . $page )['content'];
		$this->assertSame( '<div class="zqopen zqopen__x other">x</div><span class=\'zqfree--on\'>y</span>', parse_blocks( $content )[1]['attrs']['content'] ?? null );
		$this->assertStringContainsString( '<!-- wp:paragraph {"className":"zqopen"} --><p class="zqopen">Libero</p><!-- /wp:paragraph -->', $content );
		$this->assertSame( array( 'zqopen' ), $this->dev_classes( $page ) );

		// Free HTML is rewritten only by who may write it unfiltered.
		wp_set_current_user( $this->restricted_builder() );
		$refused = $this->request( 'POST', '/selectors/rename', array( 'from' => 'zqopen', 'to' => 'zqfree', 'html' => array( array( 'id' => 'post:' . $page . '|0', 'before' => 'x' ) ) ) );
		$this->assertSame( 403, $refused->get_status() );
	}

	public function test_rename_refuses_conflicts_and_unknown_names(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-a',
					'selector' => '.zqalpha',
					'css'      => 'color: red',
				),
			)
		);
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'class' => 'zqalpha zqbeta' ) ) ) ),
			)
		);
		$plan = $this->rename( 'zqalpha', 'zqbeta', true )->get_data();
		$this->assertFalse( $plan['ok'] );
		$this->assertSame( array( 'zqbeta' ), $plan['conflicts'] );
		$this->assertSame( 409, $this->rename( 'zqalpha', 'zqbeta' )->get_status() );
		$this->assertSame( 404, $this->rename( 'zqnobody', 'zqsomeone' )->get_status() );
		$this->assertSame( 400, $this->rename( 'zqalpha', 'bad name' )->get_status() );
		$this->assertSame( 400, $this->rename( 'zqalpha', 'zqalpha__child' )->get_status(), 'a BEM child of itself' );
	}

	public function test_rename_refused_when_a_document_the_user_cannot_edit_uses_the_class(): void {
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'class' => 'zqlocked' ) ) ) ),
			)
		);
		wp_set_current_user( $this->restricted_builder() );
		$plan = $this->rename( 'zqlocked', 'zqopen', true )->get_data();
		$this->assertSame( 1, $plan['locked'] );
		$this->assertSame( array(), $plan['docs'], 'no titles of documents the user cannot edit' );
		$this->assertSame( 409, $this->rename( 'zqlocked', 'zqopen' )->get_status() );
	}

	public function test_untrusted_work_stays_untrusted_after_a_rename(): void {
		$author = $this->restricted_builder();
		$page   = $this->post( array( 'post_author' => $author ) );
		wp_set_current_user( $author );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'section', array( 'class' => 'zqauthor' ), array(), 'zqsect01' ) ) ) )->get_status() );
		$this->assertFalse( $this->dev_trusted( 'post:' . $page ) );

		// An administrator renames: the author's work is rewritten, not adopted.
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->rename( 'zqauthor', 'zqwriter' )->get_status() );
		$this->assertSame( array( 'zqwriter' ), $this->dev_classes( $page ) );
		$this->assertFalse( $this->dev_trusted( 'post:' . $page ), 'the commit replacing an untrusted head stays untrusted' );

		// Trusted work is re-checked as restricted when the commit will be untrusted: an element that needs
		// unfiltered_html blocks the rename instead of being stripped later.
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'button', array( 'class' => 'zqwriter', 'onclick' => 'go()' ), array(), 'zqbtn001' ) ) ) )->get_status() );
		$this->assertTrue( $this->dev_trusted( 'post:' . $page ) );
		wp_set_current_user( $author );
		$plan = $this->rename( 'zqwriter', 'zqauthor', true )->get_data();
		$this->assertSame( array( 'post:' . $page ), array_column( $plan['blocked'], 'doc' ) );
		$this->assertFalse( $plan['ok'] );
		$this->assertSame( 409, $this->rename( 'zqwriter', 'zqauthor' )->get_status() );
		$this->assertSame( array( 'zqwriter' ), $this->dev_classes( $page ), 'nothing written' );
		$this->assertStringContainsString( 'go()', (string) $this->dev_payload( 'post:' . $page )['content'] );
	}

	public function test_untrusted_styles_stay_untrusted(): void {
		$author = $this->restricted_builder();
		wp_set_current_user( $author );
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-style',
					'selector' => '.zqstyled',
					'css'      => 'color: red',
				),
			)
		);
		$this->assertFalse( $this->dev_trusted( 'styles' ) );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->rename( 'zqstyled', 'zqpainted' )->get_status() );
		$this->assertContains( '.zqpainted', array_column( $this->dev_payload( 'styles' )['styles'], 'selector' ) );
		$this->assertFalse( $this->dev_trusted( 'styles' ) );
	}

	public function test_only_unused_selectors_can_be_deleted(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-used',
					'selector' => '.zqused',
					'css'      => 'color: red',
				),
				array(
					'id'       => 'zq-unused',
					'selector' => '.zqunused',
					'css'      => 'color: blue',
				),
			)
		);
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'class' => 'zqused' ) ) ) ),
			)
		);
		$live = get_option( 'xb_styles' );
		$this->assertSame( 409, $this->request( 'POST', '/selectors/delete', array( 'ids' => array( 'zq-unused', 'zq-used' ) ) )->get_status() );
		$this->assertContains( 'zq-unused', array_column( $this->dev_payload( 'styles' )['styles'], 'id' ), 'all or nothing' );
		$this->assertSame( 404, $this->request( 'POST', '/selectors/delete', array( 'ids' => array( 'zq-missing' ) ) )->get_status() );

		$done = $this->request( 'POST', '/selectors/delete', array( 'ids' => array( 'zq-unused' ) ) );
		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( array( '.zqunused' ), $done->get_data()['deleted'] );
		$ids = array_column( $this->dev_payload( 'styles' )['styles'], 'id' );
		$this->assertNotContains( 'zq-unused', $ids );
		$this->assertContains( 'zq-used', $ids );
		$this->assertSame( $live, get_option( 'xb_styles' ), 'deleted in dev only' );
	}

	public function test_classes_of_the_global_sheets_are_listed_with_their_usage_and_rules(): void {
		$this->add_styles(
			array(
				array(
					'id'       => 'zq-own',
					'selector' => '.zqown',
					'css'      => 'color: red',
				),
			),
			'.zqsheet { color: red } @media (width > 600px) { .zqsheet .zqinner, .zqown { gap: 1px } } .zqlone:hover { color: blue }'
		);
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'class' => 'zqsheet' ) ) ) ),
			)
		);
		$sheet = $this->row( '.zqsheet' );
		$this->assertSame( 'zq-global', $sheet['sheet'] );
		$this->assertSame( 2, $sheet['rules'] );
		$this->assertStringContainsString( '@media (width > 600px) {', $sheet['css'] );
		$this->assertFalse( $sheet['unused'] );
		$this->assertSame( 'zqsheet', $sheet['rename'] );
		$this->assertTrue( $this->row( '.zqlone' )['unused'] );

		$own = array_values( array_filter( $this->request( 'GET', '/selectors' )->get_data()['selectors'], static fn ( array $r ): bool => '.zqown' === $r['selector'] ) );
		$this->assertCount( 1, $own, 'a class with its own entry is listed once' );
		$this->assertArrayNotHasKey( 'sheet', $own[0] );

		$this->assertSame( 409, $this->request( 'POST', '/selectors/delete', array( 'ids' => array( 'sheet:zqlone' ) ) )->get_status(), 'sheet classes are edited in the sheet' );
	}
}
