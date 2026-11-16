<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Rest\Controller;

/** The slots a component offers to Gutenberg (lista della spesa 134): every xb/slot, once, in document order. */
final class ComponentSlotsTest extends TestCase {

	public function test_finds_every_slot_once_in_order_at_any_depth(): void {
		$blocks = array(
			array(
				'blockName'   => 'xb/element',
				'attrs'       => array(),
				'innerBlocks' => array(
					array( 'blockName' => 'xb/slot', 'attrs' => array( 'name' => 'titolo' ), 'innerBlocks' => array() ),
					array(
						'blockName'   => 'xb/element',
						'attrs'       => array(),
						'innerBlocks' => array(
							array( 'blockName' => 'xb/slot', 'attrs' => array(), 'innerBlocks' => array() ),
							array( 'blockName' => 'xb/slot', 'attrs' => array( 'name' => 'titolo' ), 'innerBlocks' => array() ),
						),
					),
				),
			),
			'not a block',
		);
		$this->assertSame( array( 'titolo', 'default' ), Controller::slot_names( $blocks ) );
		$this->assertSame( array(), Controller::slot_names( array() ) );
	}
}
