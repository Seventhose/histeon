<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;
use XB\Integrations\FluentForms;

/** Fluent Forms inside Histeon (docs/spec/fluent-forms.md); skipped where Fluent Forms is not active. */
final class FluentFormsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! FluentForms::available() ) {
			$this->markTestSkipped( 'Fluent Forms non è attivo su questo sito di prova.' );
		}
	}

	public function test_the_builder_lists_the_forms_for_those_who_may_edit_them(): void {
		$response = $this->request( 'GET', '/integrations/fluentforms' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['forms'] );
		$this->assertIsInt( $data['forms'][0]['id'] );
		$this->assertStringContainsString( 'page=fluent_forms&route=editor&form_id=999999999&xb_frame=1', $data['editUrl'] );
		$this->assertStringEndsWith( '#add=1', $data['newUrl'] );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', '/integrations/fluentforms' )->get_status() );
	}

	public function test_a_form_block_renders_on_the_canvas_and_the_editor_is_framed_only_for_them(): void {
		$id   = FluentForms::forms()[0]['id'];
		$html = (string) $this->request( 'POST', '/render', array( 'post_id' => 0, 'content' => '<!-- wp:fluentfom/guten-block {"formId":"' . $id . '"} /-->' ) )->get_data()['html'];
		$this->assertStringContainsString( 'fluentform', $html, 'Fluent Forms renders its own form' );

		$_GET = array( 'page' => FluentForms::PAGE, 'xb_frame' => '1' );
		ob_start();
		BuilderScreen::framed_dashboard();
		$this->assertStringContainsString( 'xb-framed', (string) ob_get_clean() );
		wp_set_current_user( $this->user( 'editor' ) );
		ob_start();
		BuilderScreen::framed_dashboard();
		$this->assertSame( '', (string) ob_get_clean(), 'no frame for someone who cannot edit forms' );
		$_GET = array();
	}
}
