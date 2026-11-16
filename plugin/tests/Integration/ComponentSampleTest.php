<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/**
 * A component opened by itself in the builder shows sample content (E5): each prop has its sample, its default,
 * or a value made from its type, so the canvas is not a white page. Pages and the saved component are untouched.
 */
final class ComponentSampleTest extends IntegrationTestCase {

	/** @return list<array<string, mixed>> */
	private function card(): array {
		return array(
			$this->el(
				'article',
				array( 'class' => 'card' ),
				array(
					$this->el( 'h2', array(), array( $this->text( '{props.mainTitle}' ) ) ),
					$this->el( 'p', array( 'class' => 'n' ), array( $this->text( '{props.count}' ) ) ),
					$this->el( 'p', array( 'class' => 's' ), array( $this->text( '{props.sub}' ) ) ),
					$this->el( 'p', array( 'class' => 'k' ), array( $this->text( '{props.kind}' ) ) ),
					$this->el( 'img', array( 'src' => '{props.photo}', 'alt' => '' ) ),
					$this->block(
						'xb/if',
						array(),
						array( $this->block( 'xb/branch', array( 'test' => 'props.show' ), array( $this->el( 'em', array(), array( $this->text( 'visibile' ) ) ) ) ) )
					),
				)
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function props(): array {
		return array(
			'mainTitle' => array( 'type' => 'string' ),
			'count'     => array(
				'type'    => 'number',
				'default' => 5,
			),
			'sub'       => array(
				'type'    => 'string',
				'default' => 'Default',
				'sample'  => 'Esempio',
			),
			'kind'      => array(
				'type'    => 'enum',
				'options' => array( 'primo', 'secondo' ),
			),
			'photo'     => array( 'type' => 'image' ),
			'show'      => array( 'type' => 'boolean' ),
		);
	}

	public function test_a_component_is_drawn_with_sample_props(): void {
		$id   = $this->component( 'demo/card', $this->card(), $this->props() );
		$html = (string) $this->request(
			'POST',
			'/render',
			array(
				'post_id' => $id,
				'content' => $this->markup( $this->card() ),
			)
		)->get_data()['html'];

		$this->assertStringContainsString( 'Main title</h2>', $html, 'no value: the prop name, in words' );
		$this->assertMatchesRegularExpression( '/class="n"[^>]*>5</', $html, 'the default' );
		$this->assertMatchesRegularExpression( '/class="s"[^>]*>Esempio</', $html, 'the sample beats the default' );
		$this->assertMatchesRegularExpression( '/class="k"[^>]*>primo</', $html, 'the first option' );
		$this->assertStringContainsString( 'assets/samples/image.svg', $html, 'an image placeholder' );
		$this->assertStringContainsString( 'visibile', $html, 'a boolean shows what it guards' );
	}

	public function test_the_schema_being_edited_is_used_and_validated(): void {
		$id    = $this->component( 'demo/card2', $this->card(), $this->props() );
		$props = $this->props();

		$props['sub']['sample'] = 'Non ancora salvato';
		$html                   = (string) $this->request(
			'POST',
			'/render',
			array(
				'post_id' => $id,
				'content' => $this->markup( $this->card() ),
				'schema'  => (string) wp_json_encode( array( 'props' => $props ) ),
			)
		)->get_data()['html'];
		$this->assertMatchesRegularExpression( '/class="s"[^>]*>Non ancora salvato</', $html );

		// A schema that would not save is not used: the stored one is.
		$html = (string) $this->request(
			'POST',
			'/render',
			array(
				'post_id' => $id,
				'content' => $this->markup( $this->card() ),
				'schema'  => '{"props":{"bad name":{"type":"string"}}}',
			)
		)->get_data()['html'];
		$this->assertMatchesRegularExpression( '/class="s"[^>]*>Esempio</', $html );
	}

	public function test_samples_are_saved_with_the_schema_and_never_reach_pages(): void {
		$id = $this->component( 'demo/card3', $this->card(), $this->props() );
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->card() ), array( 'schema' => array( 'props' => $this->props() ) ) )->get_status() );
		$doc = json_decode( (string) wp_json_encode( $this->request( 'GET', "/documents/$id" )->get_data() ), true );
		$this->assertSame( 'Esempio', $doc['schema']['props']['sub']['sample'] ?? null );

		// Saved in development only, the sample is what the builder draws with (the published schema has none).
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->card() ), array( 'schema' => array( 'props' => array( 'sub' => array( 'type' => 'string', 'sample' => 'Solo in sviluppo' ) ) ) ) )->get_status() );
		$html = (string) $this->request(
			'POST',
			'/render',
			array(
				'post_id' => $id,
				'content' => $this->markup( $this->card() ),
			)
		)->get_data()['html'];
		$this->assertMatchesRegularExpression( '/class="s"[^>]*>Solo in sviluppo</', $html );
		$this->assertSame( 400, $this->save_dev( $id, $this->markup( $this->card() ), array( 'schema' => array( 'props' => array( 'sub' => array( 'type' => 'string', 'sample' => array( 'x' ) ) ) ) ) )->get_status() );

		// A page that uses the component gets the defaults, never the samples.
		$page = $this->markup(
			array(
				$this->block(
					'xb/component',
					array(
						'uid' => 'cmpsmp01',
						'ref' => 'demo/card3',
					)
				),
			)
		);
		$html = (string) $this->request( 'POST', '/render', array( 'content' => $page ) )->get_data()['html'];
		$this->assertMatchesRegularExpression( '/class="s"[^>]*>Default</', $html );
		$this->assertStringNotContainsString( 'Esempio', $html );
		$this->assertStringNotContainsString( 'Main title', $html );
	}

	/** Without props the schema still reads {"props": {}} in JSON: an empty list ([]) made the builder lose the first prop added. */
	public function test_an_empty_schema_is_an_object_in_json(): void {
		$id   = $this->component( 'demo/vuoto', $this->card(), array() );
		$doc  = $this->request( 'GET', "/documents/$id" )->get_data();
		$json = (string) wp_json_encode( $doc['schema'] );
		$this->assertSame( '{"props":{}}', $json );
		$saved = $this->save_dev( $id, $this->markup( $this->card() ), array( 'schema' => array( 'props' => array() ) ) )->get_data();
		$this->assertSame( '{"props":{}}', (string) wp_json_encode( $saved['schema'] ) );
	}
}
