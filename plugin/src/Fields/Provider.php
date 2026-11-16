<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * A plugin whose custom fields pages can read (`this.fields.nome`) — docs/spec/content.md §4.
 * Providers only read: they never write values or definitions of the plugin they adapt.
 *
 * @phpstan-type FieldInfo array{id: string, provider: string, name: string, label: string, type: string, group: string, groupLabel: string, postTypes: list<string>, rest: bool, supported: bool, sub: list<array{name: string, label: string, type: string}>}
 */
interface Provider {

	/** Short name used in field ids (`acf:field_abc`) and in `this.<id>.nome`. */
	public function id(): string;

	public function label(): string;

	public function available(): bool;

	/**
	 * Every field the plugin defines for posts, for the builder's list.
	 *
	 * @return list<FieldInfo>
	 */
	public function describe(): array;

	/**
	 * The fields that apply to this post, by name. Each carries at least `id` and `rest` (public in the plugin's
	 * own REST settings), plus whatever value() needs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields_for( \WP_Post $post ): array;

	/**
	 * The value of a field of the post, as XB data (scalars, lists, arrays of scalars, nodes).
	 *
	 * @param array<string, mixed> $field One of fields_for().
	 */
	public function value( \WP_Post $post, array $field ): mixed;
}
