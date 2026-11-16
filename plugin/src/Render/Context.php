<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Data\DataNode;
use XB\Data\FacetsNode;
use XB\Data\PageContextNode;
use XB\Data\PostNode;
use XB\Data\SiteNode;
use XB\Data\UrlNode;
use XB\Data\UserNode;

/**
 * Immutable render context: expression scope, slot fills of the enclosing component and the component stack.
 * Components see only the global roots plus their props and slots (no variables leak in from the caller);
 * slot fills keep the caller's context.
 */
final class Context {

	/** Roots visible everywhere, including inside components. */
	public const GLOBAL_ROOTS = array( 'this', 'site', 'user', 'url', 'data', 'facets' );

	/**
	 * @param array<string, mixed>                                                      $scope
	 * @param array<string, array{blocks: list<array<string, mixed>>, context: Context}> $slots
	 * @param list<string>                                                              $stack    Component refs being rendered.
	 * @param bool                                                                      $annotate Builder preview: mark elements with data-xb-uid.
	 * @param string|null                                                               $owner    Inside a component, the uid of the instance (what the builder selects).
	 */
	public function __construct(
		public readonly array $scope,
		public readonly array $slots = array(),
		public readonly array $stack = array(),
		public readonly bool $annotate = false,
		public readonly ?string $owner = null,
	) {}

	public static function from_globals( bool $annotate = false ): self {
		$page = PageContextNode::from_globals();
		return new self(
			array(
				'this' => PostNode::for_post( get_post() ),
				'props' => array( 'page' => $page ),
				// Histeon Publish calls the same context `page` (docs/spec/templates.md §5): its templates work as imported.
				'page' => $page,
				'site' => new SiteNode(),
				'user' => UserNode::visitor(),
				'url'  => new UrlNode(),
				'data'   => new DataNode(),
				'facets' => new FacetsNode(),
			),
			array(),
			array(),
			$annotate
		);
	}

	/** @param array<string, mixed> $vars */
	public function with( array $vars ): self {
		return new self( array_merge( $this->scope, $vars ), $this->slots, $this->stack, $this->annotate, $this->owner );
	}

	/**
	 * A component sees the global roots and `page`, as in Histeon Publish, where every component reads the page it is
	 * on; a template part also gets `props.page` (docs/spec/templates.md §5), unless it declares a prop of that name.
	 *
	 * @param array<string, mixed>                                                      $props
	 * @param array<string, array{blocks: list<array<string, mixed>>, context: Context}> $slots
	 * @param list<string>                                                               $declared_slots
	 */
	public function for_component( string $ref, array $props, array $slots, string $instance_uid = '', array $declared_slots = array(), bool $part = false ): self {
		$meta = array();
		foreach ( $declared_slots as $name ) {
			$meta[ $name ] = array( 'empty' => ! isset( $slots[ $name ] ) || array() === $slots[ $name ]['blocks'] );
		}
		foreach ( $slots as $name => $fill ) {
			$meta[ $name ] = array( 'empty' => array() === $fill['blocks'] );
		}
		$scope = array_intersect_key( $this->scope, array_flip( array( ...self::GLOBAL_ROOTS, 'page' ) ) );
		if ( $part && ! array_key_exists( 'page', $props ) && array_key_exists( 'page', $this->scope ) ) {
			$props['page'] = $this->scope['page'];
		}
		return new self(
			$scope + array(
				'props' => $props,
				'slots' => $meta,
			),
			$slots,
			array_merge( $this->stack, array( $ref ) ),
			$this->annotate,
			$this->owner ?? ( '' === $instance_uid ? null : $instance_uid )
		);
	}
}
