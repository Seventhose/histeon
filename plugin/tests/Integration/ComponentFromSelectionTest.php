<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Documents;

/**
 * A component made from a selection: POST /components with the selection's markup. Nothing goes online until a
 * release (S1): the builder and agents through the connector alike only save in development.
 */
final class ComponentFromSelectionTest extends IntegrationTestCase {

	/** The component's markup in development. */
	private function dev( int $id ): string {
		return (string) ( Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) )['payload']['content'] ?? '' );
	}

	public function test_markup_becomes_the_component_and_is_filtered_for_restricted_authors(): void {
		$markup = $this->markup( array( $this->el( 'article', array( 'class' => 'card' ), array( $this->el( 'h2', array(), array( $this->text( '{this.title}' ) ) ) ) ) ) );
		$res    = $this->request( 'POST', '/components', array( 'ref' => 'xbt-sel-card', 'title' => 'Scheda', 'content' => $markup ) );
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$id = (int) $res->get_data()['id'];
		$this->track( $id );
		$this->assertStringContainsString( 'card', $this->dev( $id ) );
		$this->assertStringContainsString( '{this.title}', $this->dev( $id ) );
		$this->assertStringContainsString( 'card', (string) $res->get_data()['content'], 'the builder gets the dev version back' );
		$this->assertSame( '', $this->content( $id ), 'nothing of it is live yet' );
		$this->assertContains( Documents::for_post( $id ), array_column( Plugin::instance()->versioning->changes(), 'doc' ), 'a change waiting for the release' );

		$this->assertSame( 400, $this->request( 'POST', '/components', array( 'ref' => 'xbt-sel-bad', 'title' => 'x', 'content' => '<p>non XB</p>' ) )->get_status() );

		// An editor without unfiltered_html: the script never reaches the component.
		$editor = $this->user( 'editor' );
		$user   = get_user_by( 'id', $editor );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		add_filter( 'map_meta_cap', $deny = static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );
		try {
			wp_set_current_user( $editor );
			$evil = $this->markup( array( $this->el( 'div', array( 'onclick' => 'alert(1)' ), array( $this->el( 'script', array(), array( $this->text( 'alert(2)' ) ) ) ) ) ) );
			$res  = $this->request( 'POST', '/components', array( 'ref' => 'xbt-sel-evil', 'title' => 'x', 'content' => $evil ) );
			$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
			$id = (int) $res->get_data()['id'];
			$this->track( $id );
			$saved = $this->dev( $id );
			$this->assertStringNotContainsString( 'onclick', $saved );
			$this->assertStringNotContainsString( 'alert(2)', $saved );
			$this->assertSame( '', $this->content( $id ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	/** The canvas draws the new component from development; the live page gets it with the release of the page. */
	public function test_a_new_component_goes_online_with_the_first_page_that_uses_it(): void {
		$res = $this->request( 'POST', '/components', array( 'ref' => 'xbt-sel-news', 'title' => 'Novità', 'content' => $this->markup( array( $this->el( 'p', array( 'class' => 'novita' ), array( $this->text( 'Novità del componente' ) ) ) ) ) ) );
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$component = (int) $res->get_data()['id'];
		$this->track( $component );
		$instance = $this->markup( array( $this->block( 'xb/component', array( 'ref' => 'xbt-sel-news' ), array() ) ) );

		$canvas = (string) $this->request( 'POST', '/render', array( 'content' => $instance ) )->get_data()['html'];
		$this->assertStringContainsString( 'Novità del componente', $canvas, 'the canvas uses the dev version' );

		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $this->markup( array( $this->text( 'prima' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $instance )->get_status() );
		// What a visitor gets: no dev preview, nothing cached from the canvas.
		\XB\Versioning\Preview::reset();
		Plugin::instance()->components->forget();
		$this->assertStringNotContainsString( 'Novità', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'xbt-sel-news' ), array() ) ) ), 'not live before the release' );

		// Releasing only the page also releases the never-published component it uses.
		$release = Plugin::instance()->versioning->publish( null, 'Pagina con componente nuovo', array( Documents::for_post( $page ) ) );
		$this->assertContains( Documents::for_post( $component ), $release['docs'] );
		$this->assertStringContainsString( 'Novità del componente', $this->content( $component ) );
		Plugin::instance()->components->forget();
		$this->assertStringContainsString( 'Novità del componente', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'xbt-sel-news' ), array() ) ) ) );
	}
}
