<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Content\ContentTypes;
use XB\Content\ObjectFieldForms;
use XB\Fields\Fields;
use XB\Fields\JetEngineFields;
use XB\Fields\XbFields;
use XB\Render\Queries;

/** Fields of terms and users — docs/spec/content.md §4 and §6. */
final class ObjectFieldsTest extends IntegrationTestCase {

	private const DEFINITIONS = array(
		'fields' => array(
			'term:category' => array(
				array( 'name' => 'xbt_colore', 'label' => 'Colore', 'type' => 'select', 'options' => array( array( 'value' => 'rosso', 'label' => 'Rosso' ), array( 'value' => 'blu', 'label' => 'Blu' ) ) ),
				array( 'name' => 'xbt_ordine', 'label' => 'Ordine', 'type' => 'number' ),
				array( 'name' => 'xbt_nota', 'label' => 'Nota interna', 'type' => 'text', 'public' => false ),
			),
			'user'          => array(
				array( 'name' => 'xbt_ruolo', 'label' => 'Ruolo in redazione', 'type' => 'text' ),
			),
		),
	);

	/** @var list<int> */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( ContentTypes::OPTION, get_option( ContentTypes::OPTION ) );
		$this->set_option( Fields::OPTION, get_option( Fields::OPTION ) );
		update_option( ContentTypes::OPTION, ContentTypes::validate( self::DEFINITIONS ) );
		ContentTypes::forget();
		ContentTypes::register();
	}

	protected function tearDown(): void {
		unregister_term_meta( 'category', 'xbt_colore' );
		unregister_term_meta( 'category', 'xbt_ordine' );
		unregister_term_meta( 'category', 'xbt_nota' );
		unregister_meta_key( 'user', 'xbt_ruolo' );
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, 'category' );
		}
		unset( $_POST['_xb_fields_nonce'], $_POST['xb_fields'] );
		Fields::use( null );
		parent::tearDown();
		ContentTypes::forget();
	}

	private function term( string $name ): int {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 4, false ), 'category' );
		$this->assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	private function term_object( int $id ): \WP_Term {
		$term = get_term( $id, 'category' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		return $term;
	}

	public function test_term_and_user_fields_are_validated_by_target(): void {
		$cases = array(
			'unknown taxonomy'     => array( 'fields' => array( 'term:nope' => array() ) ),
			'repeater on a term'   => array( 'fields' => array( 'term:category' => array( array( 'name' => 'x', 'type' => 'repeater', 'sub' => array( array( 'name' => 'a', 'type' => 'text' ) ) ) ) ) ),
			'repeater on users'    => array( 'fields' => array( 'user' => array( array( 'name' => 'x', 'type' => 'repeater', 'sub' => array( array( 'name' => 'a', 'type' => 'text' ) ) ) ) ) ),
			'protected user field' => array( 'fields' => array( 'user' => array( array( 'name' => '_x', 'type' => 'text' ) ) ) ),
		);
		foreach ( $cases as $label => $content ) {
			try {
				ContentTypes::validate( $content );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->assertArrayHasKey( 'xbt_colore', get_registered_meta_keys( 'term', 'category' ) );
		$this->assertArrayHasKey( 'xbt_ruolo', get_registered_meta_keys( 'user' ) );
		$this->assertFalse( get_registered_meta_keys( 'user' )['xbt_ruolo']['show_in_rest'], 'user fields stay out of the REST API' );
	}

	public function test_term_fields_in_loops_and_on_posts(): void {
		$term = $this->term( 'Narrativa' );
		update_term_meta( $term, 'xbt_colore', 'verde' );
		$this->assertSame( '', get_term_meta( $term, 'xbt_colore', true ), 'only one of the options' );
		update_term_meta( $term, 'xbt_colore', 'rosso' );
		update_term_meta( $term, 'xbt_ordine', '3' );
		$post = $this->post( array( 'post_title' => 'Con categoria', 'post_category' => array( $term ) ) );
		$this->go_to( $post );
		wp_set_current_user( 0 );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.categories[0].fields.xbt_colore}|{this.categories[0].fields.xbt_ordine}' ) ) ) ) );
		$this->assertStringContainsString( 'rosso|3', $html );
		wp_set_current_user( 1 );

		$rows = Queries::fields( new \XB\Data\TermNode( $this->term_object( $term ) ) );
		$this->assertContains( 'fields.xbt_colore', array_column( $rows, 'path' ) );
	}

	/** A private field of a public taxonomy's terms is never in what an anonymous visitor reads over REST. */
	public function test_private_term_fields_stay_out_of_the_public_rest_view(): void {
		$term = $this->term( 'Riservata' );
		update_term_meta( $term, 'xbt_nota', 'solo per la redazione' );
		update_term_meta( $term, 'xbt_ordine', 3 );
		// The terms controller's own pipeline (prepare, then the context filter), with a fresh schema.
		$read = static function ( string $context ) use ( $term ): array {
			$request = new \WP_REST_Request( 'GET', '/wp/v2/categories/' . $term );
			$request->set_param( 'context', $context );
			$term_object = get_term( $term, 'category' );
			if ( ! $term_object instanceof \WP_Term ) {
				return array();
			}
			return (array) ( new \WP_REST_Terms_Controller( 'category' ) )->prepare_item_for_response( $term_object, $request )->get_data()['meta'];
		};
		$view = $read( 'view' );
		$this->assertArrayHasKey( 'xbt_ordine', $view );
		$this->assertArrayNotHasKey( 'xbt_nota', $view, 'what an anonymous visitor reads' );
		$this->assertSame( 'solo per la redazione', $read( 'edit' )['xbt_nota'] ?? null, 'editors still see it in edit context' );
	}

	public function test_author_fields_on_public_profiles_never_the_visitor(): void {
		$author = $this->user( 'author' );
		update_user_meta( $author, 'xbt_ruolo', 'Caporedattrice <b>' );
		$post = $this->post( array( 'post_title' => 'Firmato', 'post_author' => $author ) );
		$this->go_to( $post );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.author.fields.xbt_ruolo}|[{user.fields.xbt_ruolo}]' ) ) ) ) );
		$this->assertStringContainsString( 'Caporedattrice|[]', $html, 'sanitized as text; the visitor has no fields' );
	}

	public function test_jetengine_term_fields_need_an_administrator(): void {
		$term = $this->term( 'Saggi' );
		update_term_meta( $term, 'sigla', 'SG' );
		Fields::use( array( new XbFields(), new JetEngineFields( array( 'category' => array( array( 'name' => 'sigla', 'type' => 'text', 'object_type' => 'field' ) ) ) ) ) );
		$node = new \XB\Data\TermNode( $this->term_object( $term ) );
		$this->assertNull( $node->get( 'fields' )->get( 'sigla' ) );
		update_option( Fields::OPTION, array( 'jetengine:category:sigla' ) );
		$node = new \XB\Data\TermNode( $this->term_object( $term ) );
		$this->assertSame( 'SG', $node->get( 'jetengine' )->get( 'sigla' ) );
	}

	public function test_admin_forms_save_with_nonce_and_permission_only(): void {
		$term = $this->term( 'Poesia' );
		$_POST['xb_fields'] = array( 'xbt_colore' => 'blu', 'xbt_ordine' => '7' );
		ObjectFieldForms::hook_taxonomies(); // The fields of this test did not exist at init.

		do_action( 'edited_category', $term ); // No nonce: nothing.
		$this->assertSame( '', get_term_meta( $term, 'xbt_colore', true ) );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$_POST['_xb_fields_nonce'] = wp_create_nonce( 'xb_fields_term' );
		do_action( 'edited_category', $term );
		$this->assertSame( '', get_term_meta( $term, 'xbt_colore', true ), 'a subscriber cannot edit terms' );

		wp_set_current_user( 1 );
		$_POST['_xb_fields_nonce'] = wp_create_nonce( 'xb_fields_term' );
		do_action( 'edited_category', $term );
		$this->assertSame( 'blu', get_term_meta( $term, 'xbt_colore', true ) );
		$this->assertSame( '7', (string) get_term_meta( $term, 'xbt_ordine', true ) );

		// A user edits their own profile field; another user's needs edit_user.
		$author = $this->user( 'author' );
		$other  = $this->user( 'author' );
		wp_set_current_user( $author );
		$_POST['_xb_fields_nonce'] = wp_create_nonce( 'xb_fields_user' );
		$_POST['xb_fields']        = array( 'xbt_ruolo' => 'Redattore' );
		ObjectFieldForms::save_user( $author );
		ObjectFieldForms::save_user( $other );
		$this->assertSame( 'Redattore', get_user_meta( $author, 'xbt_ruolo', true ) );
		$this->assertSame( '', get_user_meta( $other, 'xbt_ruolo', true ) );
	}
}
