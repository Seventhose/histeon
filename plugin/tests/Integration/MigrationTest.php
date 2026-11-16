<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\Frameworks;
use XB\Framework\Migration;
use XB\Plugin;

/** Needs both Core Framework and Automatic.css in the dev WordPress (skipped otherwise, e.g. in CI). */
final class MigrationTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$all = Plugin::instance()->frameworks->all();
		if ( ! $all['acss']->available() || ! $all['core-framework']->available() || array() === $all['core-framework']->classes() ) {
			$this->markTestSkipped( 'Servono Core Framework e Automatic.css nel WordPress di sviluppo.' );
		}
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'acss' ) );
		Plugin::instance()->versioning->ensure( 'settings' );
		Plugin::instance()->versioning->record_live( 'settings' );
		// The dev version of the settings may hold another framework (left by earlier work on the dev site): the
		// baseline release below publishes it, so it starts from ACSS too.
		$copy = Plugin::instance()->versioning->working_copy( 'settings' );
		if ( 'acss' !== ( $copy['payload']['framework'] ?? null ) ) {
			Plugin::instance()->versioning->save_dev( 'settings', array( 'framework' => 'acss' ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		}
	}

	public function test_acss_to_core_is_prepared_in_dev_published_and_reversible(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->el(
							'div',
							array(
								'class' => 'bg--light {x} keep-me',
								'style' => 'padding: var(--space-xxl); color: var(--primary-ultra-light)',
							),
							array( $this->text( 'ACSS' ) ),
							'migr0001'
						),
					)
				),
			)
		);
		// Baseline release with the page under versioning (a small dev change to publish).
		$this->assertSame( 200, $this->save_dev( $id, str_replace( '"ACSS"', '"ACSS v0"', $this->content( $id ) ) )->get_status() );
		$baseline = $this->publish( null, 'baseline' );
		$this->assertSame( 200, $baseline->get_status() );
		$v0     = (int) $baseline->get_data()['id'];
		$before = $this->content( $id );

		$plan = Migration::instance()->plan( 'core-framework' );
		$row  = array_values( array_filter( $plan['docs'], static fn ( array $r ): bool => 'post:' . $id === $r['doc'] ) )[0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( 1, $row['classes'] );
		$this->assertSame( 1, $row['variables'] );
		$this->assertContains( '--primary-ultra-light', $plan['unmapped'], 'names without a counterpart are reported' );
		$this->assertSame( $before, $this->content( $id ), 'a plan changes nothing' );

		Migration::instance()->prepare( 'core-framework' );
		$this->assertSame( $before, $this->content( $id ), 'prepared in dev, not live' );
		$this->assertSame( 'acss', Plugin::instance()->frameworks->active()->id() );

		$this->assertSame( 200, $this->publish( null, 'migrazione' )->get_status() );
		$attributes = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes'] ?? array();
		$this->assertSame( 'bg-light {x} keep-me', $attributes['class'] ?? null, 'interpolations and unknown classes are kept' );
		$this->assertSame( 'padding: var(--space-2xl); color: var(--primary-ultra-light)', $attributes['style'] ?? null );
		$this->assertSame( 'core-framework', Plugin::instance()->frameworks->active()->id() );

		$this->assertSame( 200, $this->request( 'POST', "/releases/$v0/restore", array( 'keepContent' => false ) )->get_status() );
		$this->assertSame( 'acss', Plugin::instance()->frameworks->active()->id(), 'rollback restores the framework too' );
		$this->assertSame( 'bg--light {x} keep-me', parse_blocks( $this->content( $id ) )[0]['attrs']['attributes']['class'] ?? null );
	}

	public function test_the_way_back_core_framework_to_acss(): void {
		$this->assertTrue( Plugin::instance()->frameworks->select( 'core-framework' ) );
		$copy = Plugin::instance()->versioning->working_copy( 'settings' );
		Plugin::instance()->versioning->save_dev( 'settings', array( 'framework' => 'core-framework' ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'class' => 'bg-light keep-me', 'style' => 'padding: var(--space-2xl)' ), array( $this->text( 'Core' ) ), 'migr0002' ) ) ),
			)
		);
		$this->assertSame( 200, $this->save_dev( $id, str_replace( '"Core"', '"Core v0"', $this->content( $id ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( null, 'baseline core' )->get_status() );
		$this->assertSame( 'core-framework', Plugin::instance()->frameworks->active()->id() );

		Migration::instance()->prepare( 'acss' );
		$this->assertSame( 200, $this->publish( null, 'ritorno ad ACSS' )->get_status() );
		$this->assertSame( 'acss', Plugin::instance()->frameworks->active()->id() );
		$attributes = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes'] ?? array();
		$this->assertSame( 'bg--light keep-me', $attributes['class'] ?? null );
		$this->assertSame( 'padding: var(--space-xxl)', $attributes['style'] ?? null );
	}
}
