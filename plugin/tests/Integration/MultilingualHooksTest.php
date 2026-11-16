<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;

/**
 * The neutral hooks a language plugin uses (docs/spec/multilingual.md): which post defines a component, the
 * canvas render around a document, the language bar of the builder. No language plugin is needed here.
 */
final class MultilingualHooksTest extends IntegrationTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'xb_component_post' );
		remove_all_filters( 'xb_builder_languages' );
		remove_all_actions( 'xb_render_document' );
		remove_all_actions( 'xb_render_document_done' );
		parent::tearDown();
	}

	public function test_a_published_translation_replaces_the_component(): void {
		$this->component( 'site/hero', array( $this->text( 'Benvenuti' ) ) );
		$english = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_content' => $this->markup( array( $this->text( 'Welcome' ) ) ),
			)
		);
		add_filter( 'xb_component_post', static fn ( int $owner ): int => $english );
		\XB\Plugin::instance()->components->forget();
		$this->assertSame( 'Welcome', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'site/hero' ) ) ) ) );
	}

	public function test_anything_but_a_published_block_keeps_the_original(): void {
		$this->component( 'site/hero', array( $this->text( 'Benvenuti' ) ) );
		$draft = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->text( 'Bozza' ) ) ),
			)
		);
		$page  = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->text( 'Pagina' ) ) ),
			)
		);
		$block = array( $this->block( 'xb/component', array( 'ref' => 'site/hero' ) ) );
		foreach ( array( $draft, $page, -5, 'abc' ) as $answer ) {
			remove_all_filters( 'xb_component_post' );
			add_filter( 'xb_component_post', static fn (): mixed => $answer );
			\XB\Plugin::instance()->components->forget();
			$this->assertSame( 'Benvenuti', $this->render( $block ), 'answer: ' . var_export( $answer, true ) );
		}
	}

	public function test_the_canvas_render_is_wrapped_by_the_document_hooks(): void {
		$page  = $this->post( array( 'post_type' => 'page' ) );
		$calls = array();
		add_action( 'xb_render_document', static function ( ?\WP_Post $target ) use ( &$calls ): void {
			$calls[] = 'start:' . ( $target ? $target->ID : 0 );
		} );
		add_action( 'xb_render_document_done', static function ( ?\WP_Post $target ) use ( &$calls ): void {
			$calls[] = 'done:' . ( $target ? $target->ID : 0 );
		} );
		$response = $this->request(
			'POST',
			'/render',
			array(
				'content' => $this->markup( array( $this->text( 'Ciao' ) ) ),
				'post_id' => $page,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'start:' . $page, 'done:' . $page ), $calls );
	}

	public function test_the_language_bar_keeps_only_safe_values(): void {
		$page = $this->post( array( 'post_type' => 'page' ) );
		$none = BuilderScreen::languages( $page );
		$this->assertNull( $none, 'no language plugin: no bar' );
		add_filter(
			'xb_builder_languages',
			static fn (): array => array(
				'current' => 'it"><script>',
				'items'   => array(
					array( 'code' => 'it', 'label' => 'Italiano', 'state' => 'current', 'url' => admin_url( 'admin.php?action=xb_builder&post=1' ) ),
					array( 'code' => 'en', 'label' => '<b>English</b>', 'state' => 'publish', 'url' => 'https://evil.example/admin.php' ),
					array( 'code' => 'de', 'state' => 'bogus', 'url' => '' ),
					array( 'code' => 'fr', 'state' => 'missing', 'url' => 'javascript:alert(1)' ),
					'not an item',
				),
				'source'  => array( 'Testo originale', '', 42, str_repeat( 'a', 500 ) ),
			)
		);
		$bar = BuilderScreen::languages( $page );
		$this->assertIsArray( $bar );
		$this->assertSame( 'itscript', $bar['current'] );
		$this->assertSame( array( 'it', 'en', 'fr' ), array_column( $bar['items'], 'code' ) );
		$this->assertSame( 'English', $bar['items'][1]['label'] );
		$this->assertSame( '', $bar['items'][1]['url'], 'links leave the site admin: dropped' );
		$this->assertSame( '', $bar['items'][2]['url'] );
		$this->assertSame( 0, strpos( $bar['items'][0]['url'], admin_url() ) );
		$this->assertSame( array( 'Testo originale', str_repeat( 'a', 300 ) ), $bar['source'] );
	}
}
