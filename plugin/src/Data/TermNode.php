<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/** A term of a public taxonomy. */
final class TermNode implements Node, \Stringable {

	public function __construct( private readonly \WP_Term $term ) {}

	/** @return list<TermNode> */
	public static function list_for_post( \WP_Post $post, string $taxonomy ): array {
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object || ! $taxonomy_object->public ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );
		return is_array( $terms ) ? array_values( array_map( static fn ( \WP_Term $t ): self => new self( $t ), $terms ) ) : array();
	}

	public function get( string $key ): mixed {
		return match ( $key ) {
			'id' => $this->term->term_id,
			'name', 'label' => $this->term->name, // `label`: Histeon Publish's name.
			'slug' => $this->term->slug,
			'description' => $this->term->description,
			'count' => $this->term->count,
			'taxonomy' => $this->term->taxonomy,
			'fields' => new FieldsNode( $this->term ),
			'acf', 'jetengine', 'metabox' => new FieldsNode( $this->term, $key ),
			'url' => ( static function ( \WP_Term $term ): ?string {
				$link = get_term_link( $term );
				return is_string( $link ) ? $link : null;
			} )( $this->term ),
			default => null,
		};
	}

	public function __toString(): string {
		return $this->term->name;
	}
}
