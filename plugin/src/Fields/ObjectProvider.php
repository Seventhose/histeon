<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * A provider that also reads fields of terms and users (docs/spec/content.md §4): `item.fields.x` in a loop of
 * terms, `this.author.fields.x` on a public profile. Optional, so providers added by other plugins through
 * `xb_field_providers` keep working with posts only.
 */
interface ObjectProvider {

	/**
	 * The fields of a term (`$kind` 'term', `$subtype` its taxonomy) or of a user (`$kind` 'user'), by name. Each
	 * carries at least `id`, `name` and `rest`, like Provider::fields_for().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function object_fields( string $kind, int $id, string $subtype ): array;

	/** @param array<string, mixed> $field One of object_fields(). */
	public function object_value( string $kind, int $id, array $field ): mixed;
}
