<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/** Public fields exposed by a page context; protected Histeon fields never fall through to generic post meta. */
final class PageMetaNode implements Node {

	public function __construct( private readonly \WP_Post|\WP_Term $object ) {}

	public function get( string $key ): mixed {
		$value = ( new FieldsNode( $this->object ) )->get( $key );
		if ( null !== $value || ! $this->object instanceof \WP_Post ) {
			return $value;
		}
		return ( new MetaNode( $this->object ) )->get( $key );
	}
}
