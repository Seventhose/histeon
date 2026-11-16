<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Content\ContentTypes;
use XB\Fields\Fields;
use XB\Fields\JetEngineFields;
use XB\Fields\MetaBoxFields;
use XB\Fields\XbFields;
use XB\Render\Queries;
use XB\Versioning\Documents;
use XB\Versioning\Preview;
use XB\Versioning\VersioningException;

/** Content Hub (types, taxonomies, fields) and custom fields of other plugins — docs/spec/content.md. */
final class ContentFieldsTest extends IntegrationTestCase {

	private const DEFINITIONS = array(
		'types'      => array(
			'xbt_evento'       => array(
				'singular' => 'Evento',
				'plural'   => 'Eventi',
				'slug'     => 'eventi',
				'supports' => array( 'title', 'editor', 'thumbnail', 'bogus' ),
				'icon'     => 'dashicons-calendar"><script>',
			),
			'xbt_riservato' => array(
				'singular' => 'Testimonianza',
				'plural'   => 'Testimonianze',
				'public'   => false,
			),
		),
		'taxonomies' => array(
			'xbt_luogo' => array(
				'singular' => 'Luogo',
				'plural'   => 'Luoghi',
				'types'    => array( 'xbt_evento', 'nope' ),
			),
		),
		'fields'     => array(
			'xbt_evento' => array(
				array( 'name' => 'prezzo', 'label' => 'Prezzo', 'type' => 'number' ),
				array( 'name' => 'quando', 'label' => '<b>Quando</b>', 'type' => 'date' ),
				array(
					'name'    => 'formato',
					'label'   => 'Formato',
					'type'    => 'select',
					'options' => array( array( 'value' => 'online', 'label' => 'Online' ), array( 'value' => 'presenza', 'label' => 'In presenza' ) ),
				),
				array( 'name' => 'sito', 'label' => 'Sito', 'type' => 'url' ),
				array( 'name' => 'gratis', 'label' => 'Gratis', 'type' => 'toggle' ),
			),
		),
	);

	private const REPEATER = array(
		'name'  => 'orari',
		'label' => 'Orari',
		'type'  => 'repeater',
		'sub'   => array(
			array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ),
			array( 'name' => 'ore', 'label' => 'Ore', 'type' => 'number' ),
			array( 'name' => 'aperto', 'label' => 'Aperto', 'type' => 'toggle' ),
			array(
				'name'    => 'turno',
				'label'   => 'Turno',
				'type'    => 'select',
				'options' => array( array( 'value' => 'mattina', 'label' => 'Mattina' ), array( 'value' => 'sera', 'label' => 'Sera' ) ),
			),
			array( 'name' => 'quando', 'label' => 'Quando', 'type' => 'date' ),
			array( 'name' => 'sito', 'label' => 'Sito', 'type' => 'url' ),
			array( 'name' => 'foto', 'label' => 'Foto', 'type' => 'image' ),
		),
	);

	protected function tearDown(): void {
		if ( function_exists( 'rwmb_get_registry' ) ) {
			// The test meta box leaves the Meta Box registry (it has no way to remove one).
			$registry = rwmb_get_registry( 'meta_box' );
			$data     = new \ReflectionProperty( $registry, 'data' );
			$boxes    = (array) $data->getValue( $registry );
			unset( $boxes['xbt_mb'] );
			$data->setValue( $registry, $boxes );
		}
		unregister_post_meta( 'post', 'xbt_mb_pubblico' );
		// Doc 26 voci 211 and 213 register more types (and a group's fields on posts): out again for the next tests.
		foreach ( array( 'luogo', 'email' ) as $key ) {
			unregister_post_meta( 'post', $key );
		}
		foreach ( array( 'xbt_evento', 'xbt_riservato', 'xbt_corso', 'xbt_lezione', 'xbt_mostra' ) as $type ) {
			foreach ( array( 'prezzo', 'quando', 'formato', 'sito', 'gratis', 'orari', 'inizio', 'programma', 'livello', 'giorni', 'foto', 'luogo', 'email' ) as $key ) {
				unregister_post_meta( $type, $key );
			}
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
		if ( taxonomy_exists( 'xbt_luogo' ) ) {
			unregister_taxonomy( 'xbt_luogo' );
		}
		Fields::use( null );
		parent::tearDown(); // Restores the options (set_option), then the live definitions are read again.
		ContentTypes::forget();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( ContentTypes::OPTION, get_option( ContentTypes::OPTION ) );
		$this->set_option( Fields::OPTION, get_option( Fields::OPTION ) );
	}

	/** @param array<string, mixed> $definitions */
	private function install( array $definitions = self::DEFINITIONS ): void {
		update_option( ContentTypes::OPTION, ContentTypes::validate( $definitions ) );
		ContentTypes::forget();
		ContentTypes::register();
	}

	public function test_definitions_are_validated(): void {
		$ok = ContentTypes::validate( self::DEFINITIONS );
		$this->assertSame( array( 'title', 'editor', 'thumbnail' ), $ok['types']['xbt_evento']['supports'], 'unknown supports dropped' );
		$this->assertSame( 'dashicons-admin-post', $ok['types']['xbt_evento']['icon'], 'only a dashicon name' );
		$this->assertSame( array( 'xbt_evento' ), $ok['taxonomies']['xbt_luogo']['types'], 'only known types' );
		$this->assertSame( 'Quando', $ok['fields']['xbt_evento'][1]['label'], 'labels are plain text' );

		$field = static fn ( array $f ): array => array( 'fields' => array( 'post' => array( $f + array( 'label' => 'x' ) ) ) );
		$cases = array(
			'core type'          => array( 'types' => array( 'page' => array() ) ),
			'query var'          => array( 'types' => array( 'year' => array() ) ),
			'wp_ prefix'         => array( 'types' => array( 'wp_evento' => array() ) ),
			'uppercase'          => array( 'types' => array( 'Evento' => array() ) ),
			'too long'           => array( 'types' => array( str_repeat( 'a', 21 ) => array() ) ),
			'taken by a plugin'  => array( 'taxonomies' => array( 'wp_pattern_category' => array() ) ),
			'type and taxonomy'  => array( 'types' => array( 'tipo1' => array() ), 'taxonomies' => array( 'tipo1' => array() ) ),
			'fields of others'   => array( 'fields' => array( 'attachment' => array() ) ),
			'protected field'    => $field( array( 'name' => '_segreto', 'type' => 'text' ) ),
			'unknown field type' => $field( array( 'name' => 'x', 'type' => 'html' ) ),
			'select no options'  => $field( array( 'name' => 'x', 'type' => 'select' ) ),
			'bad option'         => $field( array( 'name' => 'x', 'type' => 'select', 'options' => array( '<b>' ) ) ),
			'relation to nowhere' => $field( array( 'name' => 'x', 'type' => 'post', 'postType' => 'wp_template' ) ),
			'field of a plugin'  => $field( array( 'name' => '_xb_script', 'type' => 'text' ) ),
		);
		foreach ( $cases as $label => $content ) {
			try {
				ContentTypes::validate( $content );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$twice = array( 'fields' => array( 'post' => array( array( 'name' => 'a', 'type' => 'text' ), array( 'name' => 'a', 'type' => 'text' ) ) ) );
		$this->expectException( \InvalidArgumentException::class );
		ContentTypes::validate( $twice );
	}

	public function test_live_definitions_are_registered_with_safe_fields(): void {
		$this->install();
		$this->assertTrue( post_type_exists( 'xbt_evento' ) );
		$this->assertTrue( taxonomy_exists( 'xbt_luogo' ) );
		$this->assertTrue( is_post_type_viewable( 'xbt_evento' ) );
		$this->assertFalse( is_post_type_viewable( 'xbt_riservato' ) );
		$this->assertTrue( \XB\Render\LoopPresets::listable( 'xbt_riservato' ), 'types without pages still feed loops' );
		$this->assertTrue( post_type_supports( 'xbt_evento', 'custom-fields' ), 'fields need the REST meta of the block editor' );

		$id = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Concerto' ) );
		update_post_meta( $id, 'prezzo', '12.5abc' );
		update_post_meta( $id, 'formato', 'teatro' );
		update_post_meta( $id, 'sito', 'javascript:alert(1)' );
		update_post_meta( $id, 'quando', '2026-02-30' );
		update_post_meta( $id, 'gratis', 'yes' );
		$this->assertSame( '', get_post_meta( $id, 'prezzo', true ), 'numbers only' );
		$this->assertSame( '', get_post_meta( $id, 'formato', true ), 'only one of the options' );
		$this->assertSame( '', get_post_meta( $id, 'sito', true ), 'web addresses only' );
		$this->assertSame( '', get_post_meta( $id, 'quando', true ), 'real dates only' );

		update_post_meta( $id, 'prezzo', '12.5' );
		update_post_meta( $id, 'formato', 'online' );
		update_post_meta( $id, 'sito', 'https://example.org/x' );
		update_post_meta( $id, 'quando', '2026-03-01' );
		update_post_meta( $id, 'gratis', '1' );
		$this->go_to( $id );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.fields.prezzo}|{this.fields.formato}|{this.fields.sito}|{this.fields.quando}|{this.fields.gratis}|{this.meta.prezzo}|[{this.fields.nessuno}]' ) ) ) ) );
		$this->assertStringContainsString( '12.5|online|https://example.org/x|2026-03-01|true|12.5|[]', $html );
	}

	public function test_portable_field_flags_values_and_private_visibility_are_preserved(): void {
		$definitions = self::DEFINITIONS;
		$definitions['fields']['xbt_evento'] = array(
			array( 'name' => 'etichette', 'label' => 'Etichette', 'type' => 'text', 'required' => true, 'multiple' => true ),
			array( 'name' => 'nota_privata', 'label' => 'Nota privata', 'type' => 'text', 'public' => false ),
			array( 'name' => 'descrizione_html', 'label' => 'Descrizione', 'type' => 'rich-text' ),
			array( 'name' => 'inizio', 'label' => 'Inizio', 'type' => 'datetime' ),
			array( 'name' => 'codice', 'label' => 'Codice', 'type' => 'integer' ),
			array( 'name' => 'prezzo', 'label' => 'Prezzo', 'type' => 'decimal' ),
		);
		$this->install( $definitions );
		$fields = ContentTypes::fields_for( 'xbt_evento' );
		$this->assertTrue( $fields[0]['required'] );
		$this->assertTrue( $fields[0]['multiple'] );
		$this->assertFalse( $fields[1]['public'] );
		$this->assertSame( array( 'uno', 'due' ), ContentTypes::sanitize_value( $fields[0], array( 'uno', 'due' ) ) );
		$this->assertSame( '', ContentTypes::sanitize_value( $fields[4], '1.5' ), 'an integer field rejects decimals' );
		$this->assertSame( '2026-10-23T14:30:00+02:00', ContentTypes::sanitize_value( $fields[3], '2026-10-23T14:30:00+02:00' ) );
		$this->assertSame( '', ContentTypes::sanitize_value( $fields[3], '2026-02-30T14:30:00Z' ) );

		$id = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Portabile' ) );
		update_post_meta( $id, 'etichette', array( 'uno', 'due' ) );
		update_post_meta( $id, 'nota_privata', 'non pubblicare' );
		update_post_meta( $id, 'prezzo', '12.5' );
		update_post_meta( $id, 'inizio', '2026-10-23T14:30:00+02:00' );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$provider = new XbFields();
		$available = $provider->fields_for( $post );
		$this->assertArrayHasKey( 'etichette', $available );
		$this->assertArrayNotHasKey( 'nota_privata', $available );
		$this->assertSame( array( 'uno', 'due' ), $provider->value( $post, $available['etichette'] ) );
		$this->assertFalse( Fields::is_public( $provider, $fields[1] + array( 'id' => 'xb:xbt_evento:nota_privata' ) ) );
		$this->go_to( $id );
		$rendered = $this->render( array( $this->text( '{props.page.title}|{props.page.meta.prezzo}|{props.page.meta.nota_privata}|{this.meta.nota_privata}' ) ) );
		$this->assertSame( 'Portabile|12.5||', $rendered, 'portable page context resolves public fields and never falls through to a registered private field' );
		// A Histeon date and time keeps its zone, as Histeon Publish shows it; .date() gives the site's time (UTC here).
		$this->assertSame( '2026-10-23T14:30:00+02:00', $provider->value( $post, $available['inizio'] ) );
		$this->assertSame( '2026-10-23T14:30:00+02:00|23/10/2026 12:30', $this->render( array( $this->text( '{props.page.meta.inizio}|{props.page.meta.inizio.date("d/m/Y H:i")}' ) ) ) );

		// Through the real REST route, as the block editor saves: publishing needs the required field.
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$GLOBALS['wp_rest_server'] = null; // Routes of the types registered above.
		$rest_base = get_post_type_object( 'xbt_evento' )->rest_base ?? 'xbt_evento';
		$rest_base = is_string( $rest_base ) && '' !== $rest_base ? $rest_base : 'xbt_evento';
		$publish   = new \WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $id );
		$publish->set_param( 'status', 'publish' );
		$publish->set_param( 'meta', array( 'etichette' => array() ) );
		$response = rest_do_request( $publish );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'xb_required_content_field', $response->get_data()['code'] ?? null );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$draft = new \WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $id );
		$draft->set_param( 'meta', array( 'etichette' => array() ) );
		$this->assertSame( 200, rest_do_request( $draft )->get_status(), 'a draft may stay incomplete' );

		$complete = new \WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $id );
		$complete->set_param( 'status', 'publish' );
		$complete->set_param( 'meta', array( 'etichette' => array( 'uno' ) ) );
		$this->assertSame( 200, rest_do_request( $complete )->get_status() );
		$this->assertSame( 'publish', get_post_status( $id ) );

		// A private field is never in what an anonymous visitor reads; an editor still sees it in edit context.
		$editor = get_current_user_id();
		wp_set_current_user( 0 );
		$anonymous = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/' . $rest_base . '/' . $id ) );
		$this->assertSame( 200, $anonymous->get_status() );
		$this->assertArrayHasKey( 'etichette', $anonymous->get_data()['meta'] );
		$this->assertArrayNotHasKey( 'nota_privata', $anonymous->get_data()['meta'] );
		wp_set_current_user( $editor );
		$edit = new \WP_REST_Request( 'GET', '/wp/v2/' . $rest_base . '/' . $id );
		$edit->set_param( 'context', 'edit' );
		$this->assertSame( 'non pubblicare', rest_do_request( $edit )->get_data()['meta']['nota_privata'] ?? null );
		// The posts controller leaves its edit-context password filter behind: not for the next tests.
		remove_all_filters( 'post_password_required' );
	}

	public function test_portable_page_context_uses_archive_identity_instead_of_the_first_record(): void {
		$this->install();
		$first = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Primo evento', 'post_status' => 'publish' ) );
		$this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Secondo evento', 'post_status' => 'publish' ) );
		$query = new \WP_Query( array( 'post_type' => 'xbt_evento', 'post_status' => 'publish', 'posts_per_page' => 2 ) );
		$query->is_post_type_archive = true;
		$query->is_archive           = true;
		$query->query_vars['post_type'] = 'xbt_evento';
		$GLOBALS['wp_query'] = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['post'] = get_post( $first );

		$rendered = $this->render( array( $this->text( '{props.page.title}|{props.page.slug}|{this.title}' ) ) );
		$this->assertSame( 'Eventi|xbt_evento|Primo evento', $rendered );
	}

	public function test_types_without_pages_are_not_listed_by_the_rest_api_to_visitors(): void {
		$this->install();
		$this->post( array( 'post_type' => 'xbt_riservato', 'post_title' => 'Riservata' ) );
		$GLOBALS['wp_rest_server'] = null; // Routes of the types registered above.
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/xbt_riservato' ) )->get_status() );
		wp_set_current_user( 1 );
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/xbt_riservato' ) )->get_status() );
	}

	public function test_the_archive_of_a_public_taxonomy_lists_a_type_without_pages(): void {
		$definitions                                   = self::DEFINITIONS;
		$definitions['taxonomies']['xbt_luogo']['types'] = array( 'xbt_evento', 'xbt_riservato' );
		$this->install( $definitions );
		$term    = wp_insert_term( 'Milano ' . wp_generate_password( 8, false ), 'xbt_luogo' );
		$evento  = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Evento' ) );
		$foto    = $this->post( array( 'post_type' => 'xbt_riservato', 'post_title' => 'Foto' ) );
		$altrove = $this->post( array( 'post_type' => 'xbt_riservato', 'post_title' => 'Altrove' ) );
		$this->assertIsArray( $term );
		$slug = get_term( (int) $term['term_id'] )->slug ?? '';
		wp_set_object_terms( $evento, array( (int) $term['term_id'] ), 'xbt_luogo' );
		wp_set_object_terms( $foto, array( (int) $term['term_id'] ), 'xbt_luogo' );
		$main = static function ( array $vars ): \WP_Query {
			$GLOBALS['wp_the_query'] = new \WP_Query();
			$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
			$GLOBALS['wp_the_query']->query( $vars );
			return $GLOBALS['wp_the_query'];
		};
		$ids = static fn ( \WP_Query $query ): array => array_map( static fn ( \WP_Post|int $post ): int => $post instanceof \WP_Post ? $post->ID : $post, $query->posts );
		$archive = $main( array( 'xbt_luogo' => $slug ) );
		$this->assertTrue( $archive->is_tax() );
		$this->assertEqualsCanonicalizing( array( $evento, $foto ), $ids( $archive ) );
		// A query that names its types, and any other query, are left as they are.
		$this->assertSame( array( $evento ), $ids( $main( array( 'xbt_luogo' => $slug, 'post_type' => 'xbt_evento' ) ) ) );
		$this->assertNotContains( $foto, $ids( new \WP_Query( array( 'xbt_luogo' => $slug ) ) ) );
		$this->assertNotContains( $foto, $ids( $main( array( 's' => 'Foto' ) ) ) );
		$this->assertNotContains( $altrove, $ids( $archive ) );
	}

	public function test_saved_in_dev_by_administrators_only_and_published(): void {
		$get = $this->request( 'GET', '/content' );
		$this->assertSame( 200, $get->get_status() );
		$data = $get->get_data();
		$posts = array_values( array_filter( $data['postTypes'], static fn ( array $t ): bool => 'post' === $t['name'] ) )[0] ?? array();
		$this->assertSame( 'wp/v2/posts', $posts['rest'] ?? null, 'the table of contents edits through the core REST route' );
		$save = $this->request(
			'POST',
			'/content',
			array(
				'content'      => self::DEFINITIONS,
				'publicFields' => array( 'acf:field_abc' ),
				'head'         => $data['head'],
				'base'         => $data['base'],
			)
		);
		$this->assertSame( 200, $save->get_status(), (string) wp_json_encode( $save->get_data() ) );
		$this->assertArrayNotHasKey( 'xbt_evento', ContentTypes::live()['types'], 'nothing is live before a release' );
		$this->assertSame( array( 'xbt_evento', 'xbt_riservato' ), array_keys( (array) $this->request( 'GET', '/content' )->get_data()['content']['types'] ) );

		// A builder who is not an administrator: may read, may not change types or public fields.
		$builder = $this->user( 'editor' );
		$user    = get_user_by( 'id', $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		$this->assertSame( 200, $this->request( 'GET', '/content' )->get_status() );
		$data = $this->request( 'GET', '/content' )->get_data();
		$this->assertSame( 403, $this->request( 'POST', '/content', array( 'content' => array(), 'publicFields' => array(), 'head' => $data['head'], 'base' => $data['base'] ) )->get_status() );
		$copy = Plugin()->versioning->working_copy( Documents::SETTINGS );
		try {
			Plugin()->versioning->save_dev( Documents::SETTINGS, array( 'content' => array() ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
			$this->fail( 'a non-administrator changed the content types through another save' );
		} catch ( VersioningException $e ) {
			$this->assertSame( 403, $e->status );
		}
		// Saving other settings keeps the types an administrator wrote: allowed.
		Plugin()->versioning->save_dev( Documents::SETTINGS, array( 'reset' => true ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		try {
			Documents::apply( Documents::SETTINGS, array( 'publicFields' => array( 'acf:field_xyz' ) ) );
			$this->fail( 'a non-administrator published public fields' );
		} catch ( VersioningException $e ) {
			$this->assertSame( 403, $e->status );
		}

		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->publish( array( Documents::SETTINGS ) )->get_status() );
		$this->assertSame( array( 'xbt_evento', 'xbt_riservato' ), array_keys( ContentTypes::live()['types'] ) );
		$this->assertSame( array( 'acf:field_abc' ), get_option( Fields::OPTION ) );
	}

	public function test_jetengine_fields_are_read_only_when_public(): void {
		$related = $this->post( array( 'post_title' => 'Sala grande' ) );
		$draft   = $this->post( array( 'post_title' => 'Bozza', 'post_status' => 'draft' ) );
		$image   = $this->image();
		$id      = $this->post( array( 'post_title' => 'Con campi' ) );
		update_post_meta( $id, 'prezzo', '15' );
		update_post_meta( $id, 'note_interne', 'non pubblicare' );
		update_post_meta( $id, 'sale', array( $related, $draft ) );
		update_post_meta( $id, 'foto', (string) $image );
		update_post_meta( $id, 'gratis', 'true' );
		update_post_meta( $id, 'tipi', array( 'a' => 'true', 'b' => 'false', 'c' => 'true' ) );
		update_post_meta( $id, 'orari', array( 'item-0' => array( 'giorno' => 'Lunedì <i>', 'ore' => '9' ), 'item-1' => array( 'giorno' => 'Martedì', 'ore' => '10' ) ) );
		update_post_meta( $id, 'quando', '1767225600' );
		Fields::use(
			array(
				new \XB\Fields\XbFields(),
				new JetEngineFields(
					array(
						'post' => array(
							array( 'name' => 'prezzo', 'title' => 'Prezzo', 'type' => 'number', 'object_type' => 'field' ),
							array( 'name' => 'note_interne', 'title' => 'Note', 'type' => 'textarea', 'object_type' => 'field' ),
							array( 'name' => 'sale', 'type' => 'posts', 'is_multiple' => true, 'object_type' => 'field' ),
							array( 'name' => 'foto', 'type' => 'media', 'value_format' => 'id', 'object_type' => 'field' ),
							array( 'name' => 'gratis', 'type' => 'switcher', 'object_type' => 'field', 'show_in_rest' => true ),
							array( 'name' => 'tipi', 'type' => 'checkbox', 'object_type' => 'field' ),
							array( 'name' => 'quando', 'type' => 'date', 'is_timestamp' => true, 'object_type' => 'field' ),
							array(
								'name'            => 'orari',
								'type'            => 'repeater',
								'object_type'     => 'field',
								'repeater-fields' => array( array( 'name' => 'giorno', 'type' => 'text' ), array( 'name' => 'ore', 'type' => 'number' ) ),
							),
							array( 'name' => 'tab1', 'type' => 'tab', 'object_type' => 'tab' ),
						),
					)
				),
			)
		);
		$this->go_to( $id );
		$template = '{this.fields.prezzo}|[{this.fields.note_interne}]|{this.fields.gratis}';
		$this->assertStringContainsString( '[]|true', $this->render( array( $this->el( 'p', array(), array( $this->text( $template ) ) ) ) ), 'only fields published in REST before an administrator chooses' );

		update_option( Fields::OPTION, array( 'jetengine:post:prezzo', 'jetengine:post:sale', 'jetengine:post:foto', 'jetengine:post:tipi', 'jetengine:post:orari', 'jetengine:post:quando' ) );
		wp_set_current_user( 0 );
		$html = $this->render(
			array(
				$this->el( 'p', array( 'class' => 'a' ), array( $this->text( '{this.jetengine.prezzo}|[{this.fields.note_interne}]|{this.fields.sale}|{this.fields.tipi}|{this.fields.quando}' ) ) ),
				$this->el( 'p', array( 'class' => 'b' ), array( $this->text( '{this.fields.foto.width}x{this.fields.foto.height}' ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.orari' ), 'as' => 'o' ), array( $this->el( 'li', array(), array( $this->text( '{o.giorno} {o.ore}' ) ) ) ) ),
			)
		);
		$this->assertStringContainsString( '15|[]|Sala grande|a, c|2026-01-01T00:00:00</p>', $html, 'drafts are never read through a relation' );
		$this->assertStringContainsString( '1x1', $html );
		$this->assertStringContainsString( '<li>Lunedì &lt;i&gt; 9</li>', $html, 'values are data, escaped' );

		wp_set_current_user( 1 );
		$rows = Queries::fields( \XB\Data\PostNode::for_post( $id ) ?? $this->fail( 'post' ) );
		$this->assertContains( 'fields.prezzo', array_column( $rows, 'path' ) );
		$this->assertContains( 'fields.foto.url', array_column( $rows, 'path' ) );
		$this->assertNotContains( 'fields.note_interne', array_column( $rows, 'path' ) );
	}

	public function test_acf_and_scf_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF o SCF non attivo nel WordPress di sviluppo.' );
		}
		acf_add_local_field_group(
			array(
				'key'      => 'group_xb_test',
				'title'    => 'Test XB',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array(
					array( 'key' => 'field_xb_sottotitolo', 'name' => 'sottotitolo', 'label' => 'Sottotitolo', 'type' => 'text' ),
					array( 'key' => 'field_xb_password', 'name' => 'codice', 'label' => 'Codice', 'type' => 'password' ),
					array( 'key' => 'field_xb_corpo', 'name' => 'corpo', 'label' => 'Corpo', 'type' => 'wysiwyg' ),
					array( 'key' => 'field_xb_data', 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'date_picker' ),
					array( 'key' => 'field_xb_link', 'name' => 'collegamento', 'label' => 'Link', 'type' => 'link' ),
					array( 'key' => 'field_xb_rel', 'name' => 'correlati', 'label' => 'Correlati', 'type' => 'relationship' ),
					array(
						'key'        => 'field_xb_righe',
						'name'       => 'righe',
						'label'      => 'Righe',
						'type'       => 'repeater',
						'sub_fields' => array( array( 'key' => 'field_xb_riga_testo', 'name' => 'testo', 'label' => 'Testo', 'type' => 'text' ) ),
					),
				),
			)
		);
		$related = $this->post( array( 'post_title' => 'Pubblico' ) );
		$draft   = $this->post( array( 'post_title' => 'Bozza', 'post_status' => 'draft' ) );
		$id      = $this->post( array( 'post_title' => 'Con ACF' ) );
		update_field( 'sottotitolo', 'Il sottotitolo', $id );
		update_field( 'codice', 'segreto', $id );
		update_field( 'corpo', '<p>Uno [shortcode]</p><script>x</script>', $id );
		update_field( 'giorno', '20260115', $id );
		update_field( 'collegamento', array( 'url' => 'javascript:alert(1)', 'title' => 'x' ), $id );
		update_field( 'correlati', array( $related, $draft ), $id );
		update_field( 'righe', array( array( 'testo' => 'R1' ), array( 'testo' => 'R2' ) ), $id );

		$this->go_to( $id );
		$template = '[{this.fields.sottotitolo}]';
		$this->assertStringContainsString( '[]', $this->render( array( $this->el( 'p', array(), array( $this->text( $template ) ) ) ) ), 'not public until an administrator says so' );

		update_option( Fields::OPTION, array( 'acf:field_xb_sottotitolo', 'acf:field_xb_password', 'acf:field_xb_corpo', 'acf:field_xb_data', 'acf:field_xb_link', 'acf:field_xb_rel', 'acf:field_xb_righe' ) );
		wp_set_current_user( 0 );
		$html = $this->render(
			array(
				$this->el( 'p', array(), array( $this->text( '{this.acf.sottotitolo}|[{this.fields.codice}]|{this.fields.corpo}|{this.fields.giorno}|[{this.fields.collegamento.url}]|{this.fields.correlati}' ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.righe' ), 'as' => 'r' ), array( $this->el( 'li', array(), array( $this->text( '{r.testo}' ) ) ) ) ),
			)
		);
		$this->assertStringContainsString( 'Il sottotitolo|[]|Uno [shortcode]|2026-01-15|[]|Pubblico</p>', $html, 'no passwords, no markup, no unsafe links, no drafts' );
		$this->assertStringContainsString( '<li>R1</li><li>R2</li>', $html );
		wp_set_current_user( 1 );

		$described = array_merge( ...array_column( Fields::describe(), 'fields' ) );
		$this->assertContains( 'acf:field_xb_righe', array_column( $described, 'id' ) );
	}

	/** D6: a repeater inside a row (two levels), defined, sanitized, written through REST and read with nested loops. */
	public function test_a_repeater_in_a_repeater_row(): void {
		$tappe                                 = array(
			'name'  => 'tappe',
			'label' => 'Tappe',
			'type'  => 'repeater',
			'sub'   => array(
				array( 'name' => 'luogo', 'label' => 'Luogo', 'type' => 'text' ),
				array(
					'name'  => 'orari',
					'label' => 'Orari',
					'type'  => 'repeater',
					'sub'   => array(
						array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ),
						array( 'name' => 'ore', 'label' => 'Ore', 'type' => 'number' ),
					),
				),
			),
		);
		$definitions                           = self::DEFINITIONS;
		$definitions['fields']['xbt_evento'][] = $tappe;
		$valid                                 = ContentTypes::validate( $definitions );
		$defined                               = array_column( $valid['fields']['xbt_evento'], null, 'name' )['tappe'];
		$this->assertSame( 'repeater', $defined['sub'][1]['type'] );
		$this->assertSame( array( 'giorno', 'ore' ), array_column( $defined['sub'][1]['sub'] ?? array(), 'name' ) );
		$this->install( $definitions );
		$schema = get_registered_meta_keys( 'post', 'xbt_evento' )['tappe']['show_in_rest']['schema'];
		$inner  = $schema['items']['properties']['orari'];
		$this->assertSame( 'array', $inner['type'] );
		$this->assertSame( ContentTypes::MAX_INNER_ROWS, $inner['maxItems'] );
		$this->assertFalse( $inner['items']['additionalProperties'] );

		$id = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Con tappe' ) );
		update_post_meta(
			$id,
			'tappe',
			array(
				array( 'luogo' => 'Roma', 'orari' => array_merge( array( array( 'giorno' => 'Lun <b>', 'ore' => '9', 'x' => 'via' ), 'non una riga' ), array_fill( 0, 80, array( 'giorno' => 'Ancora' ) ) ) ),
				array( 'luogo' => 'Milano', 'orari' => 'non una lista' ),
			)
		);
		$stored = (array) get_post_meta( $id, 'tappe', true );
		$this->assertSame( array( 'giorno' => 'Lun', 'ore' => 9 ), $stored[0]['orari'][0], 'inner rows sanitized by their own fields' );
		$this->assertCount( ContentTypes::MAX_INNER_ROWS - 1, $stored[0]['orari'], 'at most MAX_INNER_ROWS inner rows, what is not a row dropped' );
		$this->assertSame( array(), $stored[1]['orari'] );

		$GLOBALS['wp_rest_server'] = null;
		$request                   = new \WP_REST_Request( 'POST', "/wp/v2/xbt_evento/$id" );
		$request->set_body_params( array( 'meta' => array( 'tappe' => array( array( 'luogo' => 'Napoli', 'orari' => array( array( 'giorno' => 'Mar', 'ore' => 10, 'script' => 'x' ) ) ) ) ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status(), 'unknown keys in an inner row' );
		$request->set_body_params( array( 'meta' => array( 'tappe' => array( array( 'luogo' => 'Napoli', 'orari' => array( array( 'giorno' => 'Mar', 'ore' => 10 ), array( 'giorno' => 'Mer', 'ore' => null ) ) ) ) ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		wp_set_current_user( 0 );
		$this->go_to( $id );
		$html = $this->render(
			array(
				$this->block(
					'xb/loop',
					array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.tappe' ), 'as' => 't' ),
					array( $this->el( 'section', array(), array( $this->text( '{t.luogo}:' ), $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 't.orari' ), 'as' => 'o' ), array( $this->text( '[{o.giorno} {o.ore}]' ) ) ) ) ) )
				),
			)
		);
		$this->assertStringContainsString( '<section>Napoli:[Mar 10][Mer ]</section>', $html );
	}

	public function test_repeater_rows_are_validated_sanitized_and_read_with_a_loop(): void {
		$repeater = static fn ( array $f ): array => array( 'fields' => array( 'post' => array( $f + array( 'name' => 'righe', 'type' => 'repeater', 'label' => 'x' ) ) ) );
		$cases    = array(
			'no sub fields'        => $repeater( array( 'sub' => array() ) ),
			'relation in a row'    => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'post', 'postType' => 'page' ) ) ) ),
			'repeater in a row without its fields' => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'repeater' ) ) ) ),
			'three levels'         => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'repeater', 'sub' => array( array( 'name' => 'b', 'type' => 'repeater', 'sub' => array( array( 'name' => 'c', 'type' => 'text' ) ) ) ) ) ) ) ),
			'email in a row'       => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'email' ) ) ) ),
			'protected sub name'   => $repeater( array( 'sub' => array( array( 'name' => '_a', 'type' => 'text' ) ) ) ),
			'sub name twice'       => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'text' ), array( 'name' => 'a', 'type' => 'number' ) ) ) ),
			'choice no options'    => $repeater( array( 'sub' => array( array( 'name' => 'a', 'type' => 'select' ) ) ) ),
			'too many sub fields'  => $repeater( array( 'sub' => array_map( static fn ( int $i ): array => array( 'name' => "c$i", 'type' => 'text' ), range( 1, ContentTypes::MAX_SUB + 1 ) ) ) ),
		);
		foreach ( $cases as $label => $content ) {
			try {
				ContentTypes::validate( $content );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$ok = ContentTypes::validate( $repeater( array( 'sub' => array( array( 'name' => 'a', 'label' => '<i>A</i>', 'type' => 'text', 'options' => array( 'x' ), 'postType' => 'page' ) ) ) ) );
		$this->assertSame( array( array( 'name' => 'a', 'label' => 'A', 'type' => 'text', 'options' => array() ) ), $ok['fields']['post'][0]['sub'], 'only what a sub field of its type has' );
		$this->assertSame( array(), ContentTypes::validate( self::DEFINITIONS )['fields']['xbt_evento'][0]['sub'], 'other fields have no sub fields' );

		$definitions                           = self::DEFINITIONS;
		$definitions['fields']['xbt_evento'][] = self::REPEATER;
		$this->install( $definitions );
		$meta = get_registered_meta_keys( 'post', 'xbt_evento' )['orari'] ?? null;
		$this->assertIsArray( $meta );
		$schema = $meta['show_in_rest']['schema'];
		$this->assertSame( 'array', $meta['type'] );
		$this->assertSame( ContentTypes::MAX_ROWS, $schema['maxItems'] );
		$this->assertSame( 'object', $schema['items']['type'] );
		$this->assertFalse( $schema['items']['additionalProperties'] );
		$this->assertSame( array( 'giorno', 'ore', 'aperto', 'turno', 'quando', 'sito', 'foto' ), array_keys( $schema['items']['properties'] ) );
		$this->assertSame( array( '', 'mattina', 'sera' ), $schema['items']['properties']['turno']['enum'] );

		$image = $this->image();
		$page  = $this->post( array( 'post_type' => 'page', 'post_title' => 'Non è un\'immagine' ) );
		$id    = $this->post( array( 'post_type' => 'xbt_evento', 'post_title' => 'Con orari' ) );
		$this->assertSame( array(), get_metadata( 'post', $id, 'orari', true ), 'no rows yet: an empty list' );
		$rows = array(
			array( 'giorno' => 'Lunedì <i>x</i>', 'ore' => '9.5', 'aperto' => '1', 'turno' => 'mattina', 'quando' => '2026-03-01', 'sito' => 'https://example.org', 'foto' => (string) $image, 'extra' => 'via' ),
			'non una riga',
			array( 'giorno' => array( 'x' ), 'ore' => 'molte', 'aperto' => 'no', 'turno' => 'notte', 'quando' => '2026-02-30', 'sito' => 'javascript:alert(1)', 'foto' => $page ),
		);
		update_post_meta( $id, 'orari', array_merge( $rows, array_fill( 0, 250, array( 'giorno' => 'Ancora' ) ) ) );
		$stored = get_post_meta( $id, 'orari', true );
		$this->assertIsArray( $stored );
		$this->assertCount( ContentTypes::MAX_ROWS - 1, $stored, 'at most MAX_ROWS rows, rows that are not rows dropped' );
		$this->assertSame( array( 'giorno' => 'Lunedì x', 'ore' => 9.5, 'aperto' => true, 'turno' => 'mattina', 'quando' => '2026-03-01', 'sito' => 'https://example.org', 'foto' => $image ), $stored[0], 'each sub field sanitized by its type, unknown keys dropped' );
		$this->assertSame( array( 'giorno' => '', 'ore' => null, 'aperto' => false, 'turno' => '', 'quando' => '', 'sito' => '', 'foto' => 0 ), $stored[1] );

		// Through the REST API of the block editor: rows with other keys are refused, edit_post is required.
		$GLOBALS['wp_rest_server'] = null;
		$write                     = static function ( array $orari ) use ( $id ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', "/wp/v2/xbt_evento/$id" );
			$request->set_body_params( array( 'meta' => array( 'orari' => $orari ) ) );
			return rest_do_request( $request );
		};
		wp_set_current_user( 1 );
		$this->assertSame( 400, $write( array( array( 'giorno' => 'Lunedì', 'script' => 'x' ) ) )->get_status(), 'unknown keys in a row' );
		$this->assertSame( 400, $write( array( array( 'turno' => 'notte' ) ) )->get_status(), 'a choice outside its options' );
		$response = $write( array( array( 'giorno' => 'Martedì', 'ore' => null, 'aperto' => false, 'turno' => 'sera', 'quando' => '', 'sito' => '', 'foto' => 0 ), array( 'giorno' => 'Mercoledì', 'ore' => 10 ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 'Martedì', 'Mercoledì' ), array_column( (array) get_post_meta( $id, 'orari', true ), 'giorno' ) );
		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertContains( $write( array() )->get_status(), array( 401, 403 ) );

		wp_set_current_user( 0 );
		update_post_meta( $id, 'orari', array( array( 'giorno' => 'Lunedì <b>', 'ore' => 9, 'foto' => $image ), array( 'giorno' => 'Martedì', 'aperto' => true ) ) );
		$this->go_to( $id );
		$html = $this->render(
			array(
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.orari' ), 'as' => 'r' ), array( $this->el( 'li', array(), array( $this->text( '{r.giorno}|{r.ore}|{r.aperto}|{r.foto.width}' ) ) ) ) ),
			)
		);
		$this->assertStringContainsString( '<li>Lunedì|9|false|1</li><li>Martedì||true|</li>', $html, 'rows by sub field name, values converted by type' );
	}

	public function test_meta_box_fields_are_read_only_when_public(): void {
		if ( ! function_exists( 'rwmb_get_registry' ) ) {
			$this->markTestSkipped( 'Meta Box non attivo nel WordPress di sviluppo.' );
		}
		Fields::use( null ); // Providers read the meta boxes registered here.
		$box = rwmb_get_registry( 'meta_box' )->make(
			array(
				'id'         => 'xbt_mb',
				'title'      => 'Test XB',
				'post_types' => array( 'post' ),
				'fields'     => array(
					array( 'id' => 'xbt_mb_sottotitolo', 'name' => 'Sottotitolo', 'type' => 'text' ),
					array( 'id' => 'xbt_mb_codice', 'name' => 'Codice', 'type' => 'password' ),
					array( 'id' => 'xbt_mb_nascosto', 'type' => 'hidden' ),
					array( 'id' => '_xbt_mb_interno', 'type' => 'text' ),
					array( 'id' => 'xbt_mb_corpo', 'type' => 'wysiwyg' ),
					array( 'id' => 'xbt_mb_giorno', 'type' => 'date', 'timestamp' => true ),
					array( 'id' => 'xbt_mb_gratis', 'type' => 'checkbox' ),
					array( 'id' => 'xbt_mb_sito', 'type' => 'url' ),
					array( 'id' => 'xbt_mb_correlati', 'type' => 'post', 'post_type' => 'post', 'multiple' => true ),
					array( 'id' => 'xbt_mb_foto', 'type' => 'single_image' ),
					array( 'id' => 'xbt_mb_galleria', 'type' => 'image_advanced' ),
					array( 'id' => 'xbt_mb_tappe', 'type' => 'text', 'clone' => true ),
					array( 'id' => 'xbt_mb_pubblico', 'type' => 'text' ),
					array( 'type' => 'heading', 'name' => 'Titoletto' ),
				),
			)
		);
		$box->register_fields();
		register_post_meta( 'post', 'xbt_mb_pubblico', array( 'single' => true, 'type' => 'string', 'show_in_rest' => true ) );

		$related = $this->post( array( 'post_title' => 'Pubblico' ) );
		$draft   = $this->post( array( 'post_title' => 'Bozza', 'post_status' => 'draft' ) );
		$image   = $this->image();
		$id      = $this->post( array( 'post_title' => 'Con Meta Box' ) );
		update_post_meta( $id, 'xbt_mb_sottotitolo', 'Il sottotitolo' );
		update_post_meta( $id, 'xbt_mb_codice', 'segreto' );
		update_post_meta( $id, 'xbt_mb_nascosto', 'interno' );
		update_post_meta( $id, '_xbt_mb_interno', 'interno' );
		update_post_meta( $id, 'xbt_mb_corpo', '<p>Uno [shortcode]</p><script>x</script>' );
		update_post_meta( $id, 'xbt_mb_giorno', '1767225600' );
		update_post_meta( $id, 'xbt_mb_gratis', '1' );
		update_post_meta( $id, 'xbt_mb_sito', 'javascript:alert(1)' );
		add_post_meta( $id, 'xbt_mb_correlati', $related ); // A multiple field: one meta row per value.
		add_post_meta( $id, 'xbt_mb_correlati', $draft );
		update_post_meta( $id, 'xbt_mb_foto', $image );
		add_post_meta( $id, 'xbt_mb_galleria', $image );
		update_post_meta( $id, 'xbt_mb_tappe', array( 'Roma <i>', 'Napoli' ) );
		update_post_meta( $id, 'xbt_mb_pubblico', 'Già in REST' );

		$this->go_to( $id );
		$template = array( $this->el( 'p', array(), array( $this->text( '[{this.fields.xbt_mb_sottotitolo}]|{this.metabox.xbt_mb_pubblico}' ) ) ) );
		$this->assertStringContainsString( '[]|Già in REST', $this->render( $template ), 'only fields already in REST before an administrator chooses' );

		$ids = array_map( static fn ( string $f ): string => "metabox:xbt_mb:$f", array( 'xbt_mb_sottotitolo', 'xbt_mb_codice', 'xbt_mb_nascosto', 'xbt_mb_corpo', 'xbt_mb_giorno', 'xbt_mb_gratis', 'xbt_mb_sito', 'xbt_mb_correlati', 'xbt_mb_foto', 'xbt_mb_galleria', 'xbt_mb_tappe' ) );
		update_option( Fields::OPTION, array_merge( $ids, array( 'metabox:xbt_mb:_xbt_mb_interno' ) ) );
		wp_set_current_user( 0 );
		$html = $this->render(
			array(
				$this->el( 'p', array( 'class' => 'a' ), array( $this->text( '{this.metabox.xbt_mb_sottotitolo}|[{this.fields.xbt_mb_codice}]|[{this.fields.xbt_mb_nascosto}]|[{this.fields._xbt_mb_interno}]|{this.fields.xbt_mb_corpo}|{this.fields.xbt_mb_giorno}|{this.fields.xbt_mb_gratis}|[{this.fields.xbt_mb_sito}]|{this.fields.xbt_mb_correlati}' ) ) ),
				$this->el( 'p', array( 'class' => 'b' ), array( $this->text( '{this.fields.xbt_mb_foto.width}x{this.fields.xbt_mb_foto.height}' ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.xbt_mb_galleria' ), 'as' => 'g' ), array( $this->el( 'i', array(), array( $this->text( '{g.width}' ) ) ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.xbt_mb_tappe' ), 'as' => 't' ), array( $this->el( 'li', array(), array( $this->text( '{t}' ) ) ) ) ),
			)
		);
		$this->assertStringContainsString( 'Il sottotitolo|[]|[]|[]|Uno [shortcode]|2026-01-01T00:00:00|true|[]|Pubblico</p>', $html, 'no passwords, hidden inputs or protected keys, no markup, no unsafe links, no drafts' );
		$this->assertStringContainsString( '1x1', $html );
		$this->assertStringContainsString( '<i>1</i>', $html );
		$this->assertStringContainsString( '<li>Roma &lt;i&gt;</li><li>Napoli</li>', $html, 'cloned values are a list, escaped' );

		wp_set_current_user( 1 );
		$described = array_column( array_merge( ...array_column( Fields::describe(), 'fields' ) ), null, 'id' );
		$this->assertArrayHasKey( 'metabox:xbt_mb:xbt_mb_sottotitolo', $described );
		$this->assertArrayNotHasKey( 'metabox:xbt_mb:_xbt_mb_interno', $described, 'protected keys are not listed' );
		$this->assertFalse( $described['metabox:xbt_mb:xbt_mb_codice']['readable'] );
		$this->assertFalse( $described['metabox:xbt_mb:xbt_mb_nascosto']['supported'] );
		$this->assertTrue( $described['metabox:xbt_mb:xbt_mb_pubblico']['rest'] );
		$this->assertSame( 'Sottotitolo', $described['metabox:xbt_mb:xbt_mb_sottotitolo']['label'] );
		$this->assertCount( 12, array_filter( $described, static fn ( array $f ): bool => 'metabox' === $f['provider'] && 'xbt_mb' === $f['group'] ), 'layout fields and protected keys are not listed' );
		$this->assertInstanceOf( MetaBoxFields::class, Fields::provider( 'metabox' ) );
	}

	/** Doc 26 voce 211: a time, a file, a choice as radio buttons, a choice with several values, a gallery. */
	public function test_time_file_radio_and_several_values(): void {
		$definitions = array(
			'types'  => array( 'xbt_corso' => array( 'singular' => 'Corso', 'plural' => 'Corsi' ) ),
			'fields' => array(
				'xbt_corso' => array(
					array( 'name' => 'inizio', 'label' => 'Inizio', 'type' => 'time' ),
					array( 'name' => 'programma', 'label' => 'Programma', 'type' => 'file' ),
					array( 'name' => 'livello', 'label' => 'Livello', 'type' => 'select', 'radio' => true, 'options' => array( array( 'value' => 'base', 'label' => 'Base' ), array( 'value' => 'avanzato', 'label' => 'Avanzato' ) ) ),
					array( 'name' => 'giorni', 'label' => 'Giorni', 'type' => 'select', 'multiple' => true, 'radio' => true, 'options' => array( array( 'value' => 'lun', 'label' => 'Lunedì' ), array( 'value' => 'mer', 'label' => 'Mercoledì' ) ) ),
					array( 'name' => 'foto', 'label' => 'Foto', 'type' => 'image', 'multiple' => true ),
				),
			),
		);
		$valid = ContentTypes::validate( $definitions );
		$this->assertTrue( $valid['fields']['xbt_corso'][2]['radio'] ?? false );
		$this->assertArrayNotHasKey( 'radio', $valid['fields']['xbt_corso'][3], 'several values are checkboxes, never radio buttons' );
		$this->install( $definitions );

		$id   = $this->post( array( 'post_type' => 'xbt_corso', 'post_title' => 'Ceramica' ) );
		$file = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'programma', 'post_status' => 'inherit' ), 'programma.pdf' );
		$img  = $this->image();
		update_post_meta( $id, 'inizio', '25:00' );
		$this->assertSame( '', get_post_meta( $id, 'inizio', true ), 'a real time only' );
		update_post_meta( $id, 'programma', $id );
		$this->assertSame( 0, (int) get_post_meta( $id, 'programma', true ), 'a file of the library only' );

		update_post_meta( $id, 'inizio', '18:30' );
		update_post_meta( $id, 'programma', $file );
		update_post_meta( $id, 'livello', 'base' );
		update_post_meta( $id, 'giorni', array( 'lun', 'domenica', 'mer' ) );
		update_post_meta( $id, 'foto', array( $img, $img ) );
		$this->assertSame( array( 'lun', '', 'mer' ), get_post_meta( $id, 'giorni', true ) );
		$this->go_to( $id );
		$html = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.fields.inizio}|{this.fields.programma.mime}|{this.fields.livello}|{this.fields.giorni.join(",")}|{this.fields.foto.length()}' ) ) ) ) );
		$this->assertStringContainsString( '18:30|application/pdf|base|lun,,mer|2', $html );
		wp_delete_attachment( $file, true );
	}

	/** Doc 26 voce 213: the same fields for several types, or for every type but some; the type's own field wins. */
	public function test_groups_of_fields_go_to_their_types(): void {
		$definitions = array(
			'types'  => array(
				'xbt_lezione'  => array( 'singular' => 'Corso', 'plural' => 'Corsi' ),
				'xbt_mostra' => array( 'singular' => 'Evento', 'plural' => 'Eventi' ),
			),
			'fields' => array(
				'xbt_mostra' => array(
					array( 'name' => 'luogo', 'label' => 'Luogo dell\'evento', 'type' => 'text' ),
					array( 'name' => 'sede', 'label' => 'Vecchia copia', 'type' => 'text', 'group' => 'contatti' ),
				),
			),
			'groups' => array(
				'contatti' => array(
					'label'  => 'Contatti',
					'all'    => true,
					'except' => array( 'page', 'nessuno' ),
					'fields' => array(
						array( 'name' => 'luogo', 'label' => 'Luogo', 'type' => 'text' ),
						array( 'name' => 'email', 'label' => 'Email', 'type' => 'email' ),
					),
				),
				'prezzi'   => array(
					'label'  => 'Prezzi',
					'types'  => array( 'xbt_lezione' ),
					'fields' => array( array( 'name' => 'prezzo', 'label' => 'Prezzo', 'type' => 'number' ) ),
				),
			),
		);
		$out   = ContentTypes::validate( $definitions );
		$names = static fn ( string $type ): array => array_column( $out['fields'][ $type ] ?? array(), 'name' );
		$this->assertSame( array( 'luogo', 'email' ), $names( 'xbt_mostra' ), 'the type\'s own luogo wins; the old copy of a group field is not the type\'s' );
		$this->assertSame( 'Luogo dell\'evento', $out['fields']['xbt_mostra'][0]['label'] );
		$this->assertSame( 'contatti', $out['fields']['xbt_mostra'][1]['group'] ?? null );
		$this->assertSame( array( 'luogo', 'email', 'prezzo' ), $names( 'xbt_lezione' ) );
		$this->assertSame( array( 'luogo', 'email' ), $names( 'post' ) );
		$this->assertSame( array(), $names( 'page' ), 'every type but the pages' );
		$this->assertSame( array( 'page' ), $out['groups']['contatti']['except'] ?? null, 'only known types' );

		// Saved again as the builder sends it back (fields with their group mark): the same result.
		$this->assertSame( $out, ContentTypes::validate( $out ) );

		$this->install( $definitions );
		$id = $this->post( array( 'post_type' => 'xbt_lezione', 'post_title' => 'Ceramica' ) );
		update_post_meta( $id, 'email', 'corsi@example.org' );
		update_post_meta( $id, 'prezzo', '40' );
		$this->go_to( $id );
		$this->assertStringContainsString( 'corsi@example.org|40', $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.fields.email}|{this.fields.prezzo}' ) ) ) ) ) );
	}

	public function test_preview_reads_the_dev_list_of_public_fields(): void {
		Fields::use( array( new JetEngineFields( array( 'post' => array( array( 'name' => 'sigla', 'type' => 'text', 'object_type' => 'field' ) ) ) ) ) );
		$id = $this->post( array( 'post_title' => 'Anteprima' ) );
		update_post_meta( $id, 'sigla', 'XB-1' );
		$copy = Plugin()->versioning->working_copy( Documents::SETTINGS );
		Plugin()->versioning->save_dev( Documents::SETTINGS, array( 'publicFields' => array( 'jetengine:post:sigla' ) ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		$this->go_to( $id );
		$template = array( $this->el( 'p', array(), array( $this->text( '[{this.fields.sigla}]' ) ) ) );
		$this->assertStringContainsString( '[]', $this->render( $template ) );
		Preview::use_dev();
		$this->assertStringContainsString( '[XB-1]', $this->render( $template ) );
	}

	private function image(): int {
		$upload = wp_upload_bits( 'xb-field-' . wp_generate_password( 6, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'pixel', 'post_status' => 'inherit' ), $upload['file'] );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$this->track( $id );
		return $id;
	}
}

function Plugin(): \XB\Plugin { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- test shorthand.
	return \XB\Plugin::instance();
}
