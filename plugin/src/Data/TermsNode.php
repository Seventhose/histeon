<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/** `this.terms.<taxonomy>`: terms of any public taxonomy. */
final class TermsNode implements Node {

	public function __construct( private readonly \WP_Post $post ) {}

	public function get( string $key ): mixed {
		return TermNode::list_for_post( $this->post, $key );
	}
}
