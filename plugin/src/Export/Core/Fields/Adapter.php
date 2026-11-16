<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Fields;

/**
 * A field plugin the site's fields go to when it leaves Histeon — docs/spec/export-core.md §15, doc 25 of Histeon
 * Publish §10 and §11 step 5. Simple values are post meta for every plugin and stay where they are; what differs is
 * where the field groups are declared (files of the site theme) and how some values are stored (dates, yes/no,
 * repeaters). Nothing here writes to the site: the values to write are returned, and written only by the conversion.
 *
 * @phpstan-type Fields list<array<string, mixed>>
 */
interface Adapter {

	/** Short name, as `--campi=<id>` takes it. */
	public function id(): string;

	/** The plugin's name, for people. */
	public function name(): string;

	/**
	 * What the plugin cannot take as it is, for the report: a repeater in a free version without repeaters…
	 *
	 * @param array{types: array<string, mixed>, taxonomies: array<string, mixed>, fields: array<string, Fields>} $content ContentTypes::live().
	 * @return list<string>
	 */
	public function notes( array $content ): array;

	/**
	 * Files of the site theme that declare the field groups, by path in the theme.
	 *
	 * @param array{types: array<string, mixed>, taxonomies: array<string, mixed>, fields: array<string, Fields>} $content
	 * @return array<string, string>
	 */
	public function theme_files( array $content ): array;

	/** PHP for the site theme's functions.php that hands the groups to the plugin; '' when the plugin finds them alone. */
	public function functions(): string;

	/**
	 * The meta to write for one object's values, from Histeon's form to the plugin's, and the meta keys to remove.
	 *
	 * @param string                   $target The key of the fields in ContentTypes::live()['fields']: a type, `term:<tassonomia>`, `user`.
	 * @param Fields                     $fields Those fields.
	 * @param callable(string): mixed    $read   Reads one meta of the object as stored now.
	 * @param list<string>               $keys   Every meta key the object has now (for rows left from before).
	 * @return array{set: array<string, mixed>, delete: list<string>, rows?: array<string, list<mixed>>} `rows`: meta
	 *         written as one row per value (a list some plugins keep that way).
	 */
	public function values( string $target, array $fields, callable $read, array $keys ): array;
}
