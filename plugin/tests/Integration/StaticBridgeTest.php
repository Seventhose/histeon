<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\StaticSite\StaticBridge;

/** XB's answers to Static Bridge — docs/integration/static-bridge.md. */
final class StaticBridgeTest extends IntegrationTestCase {

	/** @return array<string, mixed> */
	private function uses( string $ref ): array {
		return $this->block( 'xb/component', array( 'ref' => $ref ), array() );
	}

	public function test_pages_that_show_a_component_directly_or_through_another(): void {
		$card  = $this->component( 'xbt-card', array( $this->el( 'p', array(), array( $this->text( 'card' ) ) ) ) );
		$this->component( 'xbt-list', array( $this->el( 'div', array(), array( $this->uses( 'xbt-card' ) ) ) ) );
		$one   = $this->post( array( 'post_type' => 'page', 'post_title' => 'Uno', 'post_content' => $this->markup( array( $this->uses( 'xbt-list' ) ) ) ) );
		$two   = $this->post( array( 'post_type' => 'page', 'post_title' => 'Due', 'post_content' => $this->markup( array( $this->el( 'section', array(), array( $this->uses( 'xbt-card' ) ) ) ) ) ) );
		$this->post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Bozza', 'post_content' => $this->markup( array( $this->uses( 'xbt-card' ) ) ) ) );
		$this->post( array( 'post_type' => 'page', 'post_title' => 'Altra', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( '"ref":"xbt-card" scritto nel testo' ) ) ) ) ) ) );

		$urls = StaticBridge::dependents( null, get_post( $card ) );
		$this->assertIsArray( $urls );
		sort( $urls );
		$expected = array( (string) get_permalink( $one ), (string) get_permalink( $two ) );
		sort( $expected );
		$this->assertSame( $expected, $urls, 'pages using it, also through another component; never drafts or text that only looks like it' );

		$this->assertSame( array( 'x' ), StaticBridge::dependents( array( 'x' ), get_post( $card ) ), 'an earlier answer is kept' );
		$this->assertNull( StaticBridge::dependents( null, get_post( $one ) ), 'not a component' );
		$plain = $this->post( array( 'post_type' => 'wp_block', 'post_title' => 'Blocco riutilizzabile', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertNull( StaticBridge::dependents( null, get_post( $plain ) ), 'a core reusable block: unknown, refresh everything' );
	}

	public function test_a_component_in_a_template_refreshes_everything(): void {
		$card = $this->component( 'xbt-card2', array( $this->el( 'p', array(), array( $this->text( 'card' ) ) ) ) );
		$this->post( array( 'post_type' => 'wp_template_part', 'post_name' => 'xbt-header', 'post_title' => 'Header', 'post_content' => $this->markup( array( $this->uses( 'xbt-card2' ) ) ) ) );
		$this->assertNull( StaticBridge::dependents( null, get_post( $card ) ) );
	}

	public function test_pages_that_list_content(): void {
		$this->component( 'xbt-news', array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts' ), 'as' => 'item' ), array( $this->el( 'p', array(), array( $this->text( '{item.title}' ) ) ) ) ) ) );
		$nested = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'div', array(), array( $this->uses( 'xbt-news' ) ) ) ) ) ) );
		$data   = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( '{data.posts[0].title}' ) ) ) ) ) ) );
		$plain  = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'metadata.x {this.title}' ) ) ) ) ) ) );
		$this->assertTrue( StaticBridge::has_list( false, get_post( $nested ) ), 'a loop inside a component' );
		$this->assertTrue( StaticBridge::has_list( false, get_post( $data ) ), 'the data root' );
		$this->assertFalse( StaticBridge::has_list( false, get_post( $plain ) ) );
		$facets = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( '{facets.eventi.total}' ) ) ) ) ) ) );
		$this->assertTrue( StaticBridge::has_list( false, get_post( $facets ) ), 'the facets root' );
	}

	/** Lists that are not Histeon loops: Etch's (imported sites) and the core Query Loop. */
	public function test_lists_of_etch_and_of_the_core_count_too(): void {
		$page = fn ( string $content ): mixed => get_post( $this->post( array( 'post_type' => 'page', 'post_content' => $content ) ) );
		$etch = '<!-- wp:etch/element {"tag":"section"} --><!-- wp:etch/loop {"loopId":"l1","itemId":"item"} --><!-- wp:etch/text {"content":"{item.title}"} /--><!-- /wp:etch/loop --><!-- /wp:etch/element -->';
		$core = '<!-- wp:group --><div class="wp-block-group"><!-- wp:query {"queryId":1,"query":{"perPage":3,"postType":"post"}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query --></div><!-- /wp:group -->';
		$this->assertTrue( StaticBridge::has_list( false, $page( $etch ) ), 'an Etch loop, nested in an element' );
		$this->assertTrue( StaticBridge::has_list( false, $page( $core ) ), 'a core Query Loop, nested in a group' );
		$this->assertFalse( StaticBridge::has_list( false, $page( '<!-- wp:query-title {"type":"archive"} /--><!-- wp:paragraph --><p>wp:query</p><!-- /wp:paragraph -->' ) ), 'neither an archive title nor the words' );

		// In a reusable block or an Etch component (both are wp_block posts referenced by id).
		$etch_component = $this->post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => $etch ) );
		$reusable       = $this->post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => $core ) );
		$this->assertTrue( StaticBridge::has_list( false, $page( '<!-- wp:etch/component {"ref":' . $etch_component . '} /-->' ) ), 'an Etch component with a loop' );
		$this->assertTrue( StaticBridge::has_list( false, $page( '<!-- wp:block {"ref":' . $reusable . '} /-->' ) ), 'a reusable block with a query' );
		$plain = $this->post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertFalse( StaticBridge::has_list( false, $page( '<!-- wp:block {"ref":' . $plain . '} /-->' ) ) );
		$page_ref = $this->post( array( 'post_type' => 'page', 'post_content' => $core ) );
		$this->assertFalse( StaticBridge::has_list( false, $page( '<!-- wp:block {"ref":' . $page_ref . '} /-->' ) ), 'only wp_block posts are followed' );
	}

	public function test_the_status_of_static_bridge_is_read_with_care(): void {
		// Once the bundled module has been loaded in this process (ModulesStaticBridgeTest), it answers for real.
		if ( ! has_filter( 'static_bridge_status' ) ) {
			$this->assertNull( StaticBridge::status(), 'not active: no status' );
			$this->assertFalse( StaticBridge::keeps_up() );
		}

		$answer = static fn(): array => array(
			'version'      => '2.0.25',
			'serving'      => true,
			'incremental'  => true,
			'pages'        => 71,
			'published_at' => 1700000000,
			'pending'      => '3',
			'export'       => 'idle',
			'last'         => array( 'message' => '<script>x</script>Pagina rigenerata', 'time' => 'oggi', 'error' => false ),
			'admin_url'    => admin_url( 'admin.php?page=static-bridge&tab=generate' ),
		);
		add_filter( 'static_bridge_status', $answer );
		try {
			$status = StaticBridge::status();
			$this->assertNotNull( $status );
			$this->assertSame( 71, $status['pages'] );
			$this->assertSame( 3, $status['pending'] );
			$this->assertSame( 'Pagina rigenerata', $status['last']['message'] ?? null, 'no markup or script survives' );
			$this->assertTrue( str_starts_with( $status['admin_url'], admin_url() ) );
			$this->assertTrue( StaticBridge::keeps_up() );

			wp_set_current_user( 1 );
			ob_start();
			\XB\Admin\ReleasesScreen::render();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'serve il sito statico', $html );
			$this->assertStringContainsString( 'pagine statiche', $html );
			$this->assertStringNotContainsString( '<script>x', $html );
		} finally {
			remove_filter( 'static_bridge_status', $answer );
		}

		$hostile = static fn(): array => array(
			'serving'   => 'yes',
			'pages'     => -5,
			'export'    => 'rm -rf',
			'last'      => 'not an array',
			'admin_url' => 'https://evil.example/wp-admin/',
		);
		add_filter( 'static_bridge_status', $hostile );
		try {
			$status = StaticBridge::status();
			$this->assertNotNull( $status );
			$this->assertFalse( $status['serving'], 'only a real true counts' );
			$this->assertSame( 0, $status['pages'] );
			$this->assertSame( 'idle', $status['export'] );
			$this->assertNull( $status['last'] );
			$this->assertSame( '', $status['admin_url'], 'never a link to another site' );
			$this->assertFalse( StaticBridge::keeps_up() );
		} finally {
			remove_filter( 'static_bridge_status', $hostile );
		}
	}
}
