<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

/**
 * A provider that also reads options pages (D5, docs/spec/content.md §4): `site.options.<page>.<field>` in any
 * page, read-only. Optional, like ObjectProvider.
 */
interface OptionsProvider {

	/**
	 * The options pages: slug => where their values are stored (the plugin's own id for them).
	 *
	 * @return array<string, string>
	 */
	public function options_pages(): array;

	/**
	 * The fields of an options page, by name; each carries at least `id`, `name` and `rest`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function options_fields( string $page ): array;

	/** @param array<string, mixed> $field One of options_fields(). */
	public function options_value( string $page, array $field ): mixed;
}
