<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Documents;

/** Lista della spesa 157: a prop added in development (a variant) is offered to the pages before the release. */
final class ComponentVariantsTest extends IntegrationTestCase {

	public function test_the_component_list_reads_the_schema_in_development(): void {
		$id  = $this->component( 'e2e/variante', array( $this->el( 'div', array( 'class' => 'card' ) ) ), array( 'titolo' => array( 'type' => 'string' ) ) );
		$doc = Documents::for_post( $id );
		$find = fn (): array => array_values( array_filter( (array) $this->request( 'GET', '/components' )->get_data(), static fn ( array $c ): bool => $c['id'] === $id ) )[0];
		$this->assertSame( array( 'titolo' ), array_keys( $find()['props'] ) );

		$versioning        = Plugin::instance()->versioning;
		$copy              = $versioning->working_copy( $doc );
		$payload           = $copy['payload'];
		$payload['schema'] = array( 'props' => array( 'titolo' => array( 'type' => 'string' ), 'scura' => array( 'type' => 'boolean', 'default' => false, 'label' => 'Scura' ) ) );
		$versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'variante' );
		$this->assertSame( array( 'titolo', 'scura' ), array_keys( $find()['props'] ), 'the development schema' );
		$this->assertSame( array( 'type' => 'boolean', 'default' => false, 'label' => 'Scura' ), $find()['props']['scura'] );
	}
}
