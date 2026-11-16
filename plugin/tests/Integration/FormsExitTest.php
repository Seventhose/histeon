<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Converter;
use XB\Export\Core\Forms\Adapters;
use XB\Export\Core\Forms\Reader;
use XB\Export\Core\Prova;

/**
 * Histeon's own forms leaving Histeon for a form plugin (docs/spec/export-core.md §16, doc 25 §11 step 6): what the
 * form holds, read from its markup; the form as the plugin imports it; the messages received; the plugin's block in
 * the form's place in the converted page.
 */
final class FormsExitTest extends IntegrationTestCase {

	protected function tearDown(): void {
		Converter::$forms = array();
		parent::tearDown();
	}

	/** @return list<array<string, mixed>> The manual's example, with a choice, a group of options and a box. */
	private function form(): array {
		$label = fn ( string $text, array $control ): array => $this->el( 'label', array(), array( $this->text( $text . ' ' ), $control ) );
		return array(
			$this->el(
				'form',
				array( 'method' => 'post', 'action' => '/xb-form', 'class' => 'contatti' ),
				array(
					$this->el( 'input', array( 'type' => 'hidden', 'name' => 'xb_form', 'value' => 'contatti' ) ),
					$this->el( 'input', array( 'type' => 'hidden', 'name' => 'xb_redirect', 'value' => '/grazie/' ) ),
					$label( 'Nome', $this->el( 'input', array( 'name' => 'nome', 'required' => '' ) ) ),
					$label( 'Email', $this->el( 'input', array( 'name' => 'email', 'type' => 'email', 'required' => '', 'placeholder' => 'tu@esempio.test' ) ) ),
					$this->el( 'label', array( 'for' => 'f-tema' ), array( $this->text( 'Argomento' ) ) ),
					$this->el( 'select', array( 'id' => 'f-tema', 'name' => 'tema' ), array( $this->el( 'option', array( 'value' => 'libri' ), array( $this->text( 'Libri' ) ) ), $this->el( 'option', array( 'value' => 'scuole' ), array( $this->text( 'Scuole' ) ) ) ) ),
					$this->el(
						'fieldset',
						array(),
						array(
							$this->el( 'legend', array(), array( $this->text( 'Come ci hai conosciuto' ) ) ),
							$label( 'Amici', $this->el( 'input', array( 'type' => 'radio', 'name' => 'fonte', 'value' => 'amici' ) ) ),
							$label( 'Scuola', $this->el( 'input', array( 'type' => 'radio', 'name' => 'fonte', 'value' => 'scuola' ) ) ),
						)
					),
					$label( 'Messaggio', $this->el( 'textarea', array( 'name' => 'messaggio' ) ) ),
					$label( 'Accetto la privacy', $this->el( 'input', array( 'type' => 'checkbox', 'name' => 'privacy', 'value' => 'si', 'required' => '' ) ) ),
					$this->el( 'div', array( 'class' => 'cf-turnstile', 'data-sitekey' => 'x' ) ),
					$this->el( 'button', array(), array( $this->text( 'Invia il messaggio' ) ) ),
				)
			),
		);
	}

	public function test_a_form_is_read_from_its_markup(): void {
		$form = Reader::read( $this->form()[0] );
		$this->assertSame( 'contatti', $form['name'] );
		$this->assertSame( '/grazie/', $form['redirect'] );
		$this->assertSame( 'Invia il messaggio', $form['submit'] );
		$this->assertTrue( $form['turnstile'] );
		$fields = array_column( $form['fields'], null, 'name' );
		$this->assertSame( array( 'nome', 'email', 'tema', 'fonte', 'messaggio', 'privacy' ), array_keys( $fields ) );
		$this->assertSame( array( 'text', 'Nome', true ), array( $fields['nome']['type'], $fields['nome']['label'], $fields['nome']['required'] ) );
		$this->assertSame( array( 'email', 'tu@esempio.test' ), array( $fields['email']['type'], $fields['email']['placeholder'] ) );
		$this->assertSame( 'Argomento', $fields['tema']['label'] );
		$this->assertSame( array( array( 'value' => 'libri', 'label' => 'Libri' ), array( 'value' => 'scuole', 'label' => 'Scuole' ) ), $fields['tema']['options'] );
		$this->assertSame( array( 'radio', 'Come ci hai conosciuto' ), array( $fields['fonte']['type'], $fields['fonte']['label'] ) );
		$this->assertSame( array( 'amici', 'scuola' ), array_column( $fields['fonte']['options'], 'value' ) );
		$this->assertSame( array( 'checkbox', 'Accetto la privacy', true ), array( $fields['privacy']['type'], $fields['privacy']['label'], $fields['privacy']['required'] ) );
		$this->assertFalse( $fields['messaggio']['required'] );
	}

	public function test_fluent_forms_import_and_messages(): void {
		$adapter = Adapters::get( 'fluentforms' );
		$this->assertNotNull( $adapter );
		$form   = Reader::read( $this->form()[0] );
		$export = $adapter->export( $form );
		$fields = array_column( $export['form_fields']['fields'], null, 'uniqElKey' );
		$names  = array_map( static fn ( array $f ): string => (string) $f['attributes']['name'], $export['form_fields']['fields'] );
		$this->assertSame( array( 'nome', 'email', 'tema', 'fonte', 'messaggio', 'privacy' ), $names );
		$elements = array_combine( $names, array_column( $export['form_fields']['fields'], 'element' ) );
		$this->assertSame( array( 'input_text', 'input_email', 'select', 'input_radio', 'textarea', 'input_checkbox' ), array_values( $elements ) );
		$this->assertCount( 6, $fields );
		$by_name = array_combine( $names, $export['form_fields']['fields'] );
		$this->assertTrue( $by_name['email']['settings']['validation_rules']['required']['value'] );
		$this->assertSame( 'Invia il messaggio', $export['form_fields']['submitButton']['settings']['button_ui']['text'] );
		$settings = array_column( $export['metas'], 'value', 'meta_key' );
		$this->assertSame( 'customUrl', $settings['formSettings']['confirmation']['redirectTo'] );
		$this->assertSame( home_url( '/grazie/' ), $settings['formSettings']['confirmation']['customUrl'] );
		$this->assertSame( '{wp.admin_email}', $settings['notifications']['sendTo']['email'] );
		$this->assertStringContainsString( 'Turnstile', implode( ' ', $adapter->notes( $form ) ) );

		// The messages Histeon kept, read back field by field.
		foreach ( array( 'contatti — 2026-10-01 10:00' => "nome: Anna\nemail: anna@esempio.test\nmessaggio: Ciao &amp; grazie\n", 'altro — 2026-10-02 10:00' => "nome: Bo\n" ) as $title => $text ) {
			$this->track( (int) wp_insert_post( array( 'post_type' => \XB\Forms\Forms::POST_TYPE, 'post_status' => 'private', 'post_title' => $title, 'post_content' => $text ) ) );
		}
		$messages = Adapters::messages_of( 'contatti' );
		$this->assertCount( 1, $messages );
		$this->assertSame( array( 'nome' => 'Anna', 'email' => 'anna@esempio.test', 'messaggio' => 'Ciao & grazie' ), $messages[0]['fields'] );
	}

	public function test_contact_form_7_and_forminator_exports(): void {
		$form = Reader::read( $this->form()[0] );
		$cf7  = Adapters::get( 'cf7' );
		$this->assertNotNull( $cf7 );
		$text = (string) $cf7->export( $form )['form'];
		$this->assertStringContainsString( "<label> Nome\n    [text* nome] </label>", $text );
		$this->assertStringContainsString( '[email* email placeholder "tu@esempio.test"]', $text );
		$this->assertStringContainsString( '[select tema "Libri|libri" "Scuole|scuole"]', $text );
		$this->assertStringContainsString( '[radio fonte use_label_element default:1 "Amici|amici" "Scuola|scuola"]', $text );
		$this->assertStringContainsString( '[checkbox* privacy use_label_element "Accetto la privacy|si"]', $text );
		$this->assertStringContainsString( '[submit "Invia il messaggio"]', $text );
		$this->assertSame( '[_site_admin_email]', $cf7->export( $form )['mail']['recipient'] );
		$this->assertStringContainsString( '/grazie/', implode( ' ', $cf7->notes( $form ) ) );

		$fmn = Adapters::get( 'forminator' );
		$this->assertNotNull( $fmn );
		$export = $fmn->export( $form );
		$fields = array_map( static fn ( array $w ): array => $w['fields'][0], $export['wrappers'] );
		$this->assertSame( array( 'text-1', 'email-1', 'select-1', 'radio-1', 'textarea-1', 'checkbox-1' ), array_column( $fields, 'element_id' ) );
		$this->assertSame( 'true', $fields[1]['required'] );
		$this->assertSame( array( 'amici', 'scuola' ), array_column( $fields[3]['options'], 'value' ) );
		$this->assertSame( 'behaviour-redirect', $export['settings']['submission-behaviour'] );
		$this->assertSame( home_url( '/grazie/' ), $export['settings']['redirect-url'] );
	}

	public function test_jetformbuilder_ninja_forms_and_sureforms_exports(): void {
		$form = Reader::read( $this->form()[0] );
		$jfb  = Adapters::get( 'jetformbuilder' );
		$this->assertNotNull( $jfb );
		$export = $jfb->export( $form );
		$this->assertStringContainsString( '<!-- wp:jet-forms/text-field {"name":"email","label":"Email","required":true,"placeholder":"tu@esempio.test","field_type":"email"} /-->', (string) $export['content'] );
		$this->assertStringContainsString( '"field_options":[{"label":"Amici","value":"amici"}', (string) $export['content'] );
		$this->assertSame( array( 'send_email', 'save_record', 'redirect_to_page' ), array_column( $export['actions'], 'type' ) );

		$nf = Adapters::get( 'ninjaforms' );
		$this->assertNotNull( $nf );
		$export = $nf->export( $form );
		$this->assertSame( array( 'textbox', 'email', 'listselect', 'listradio', 'textarea', 'checkbox', 'submit' ), array_column( $export['fields'], 'type' ) );
		$this->assertSame( 'nome', $export['fields'][0]['key'] );
		$this->assertSame( array( 'save', 'email', 'redirect' ), array_column( $export['actions'], 'type' ) );
		$this->assertSame( array( 'formID' => 7 ), $nf->block( 7 )['attrs'] );

		$srfm = Adapters::get( 'sureforms' );
		$this->assertNotNull( $srfm );
		$export = $srfm->export( $form );
		$this->assertStringContainsString( '<!-- wp:srfm/email {', (string) $export['content'] );
		$this->assertStringContainsString( '"singleSelection":true', (string) $export['content'] );
		$this->assertSame( 'custom url', $export['meta']['_srfm_form_confirmation'][0]['confirmation_type'] );
		$this->assertStringContainsString( 'tema', implode( ' ', $srfm->notes( $form ) ) );
	}

	public function test_the_plugins_block_takes_the_forms_place(): void {
		if ( ! Prova::ready() ) {
			$this->markTestSkipped( 'Serve Fondamenta 0.2 attiva nel WordPress di sviluppo.' );
		}
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'section', array( 'class' => 'scrivici' ), array( $this->el( 'h2', array(), array( $this->text( 'Scrivici' ) ) ), ...$this->form() ) ) ) ),
			)
		);
		$post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertContains( $page, Reader::all()['contatti']['documents'] );
		// Not made yet: the form stays as Histeon drew it, and the report says it will not send.
		$before = \XB\Blocks::serialize( Prova::convert( $post )['blocks'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $before );
		// Made in Fluent Forms (here, as if): its block in the form's place.
		$adapter = Adapters::get( 'fluentforms' );
		$this->assertNotNull( $adapter );
		Converter::$forms = array( 'contatti' => $adapter->block( 42 ) );
		$after            = \XB\Blocks::serialize( Prova::convert( $post )['blocks'] );
		$this->assertStringContainsString( '<!-- wp:fluentfom/guten-block {"formId":"42"} /-->', $after );
		$this->assertStringNotContainsString( 'xb-form', $after );
		$this->assertStringContainsString( 'Scrivici', $after );
	}
}
