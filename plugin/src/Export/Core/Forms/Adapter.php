<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

/**
 * A form plugin Histeon's forms go to when the site leaves Histeon — docs/spec/export-core.md §16, doc 25 of Histeon
 * Publish §10 and §11 step 6. A form is made in the plugin from its definition (Reader), the plugin's block takes the
 * form's place in the converted page, and the messages already received become the plugin's entries.
 *
 * @phpstan-import-type Definition from Reader
 */
interface Adapter {

	/** Short name, as `--form=<id>` takes it. */
	public function id(): string;

	/** The plugin's name, for people. */
	public function name(): string;

	/** Whether the plugin is active here (making forms and entries needs it). */
	public function available(): bool;

	/**
	 * What the plugin will not do as Histeon did, for the report.
	 *
	 * @param Definition $form
	 * @return list<string>
	 */
	public function notes( array $form ): array;

	/**
	 * The form as the plugin imports it (shown by `--forms`; nothing is written).
	 *
	 * @param Definition $form
	 * @return array<string, mixed>
	 */
	public function export( array $form ): array;

	/**
	 * Makes the form in the plugin (writes); returns its ID.
	 *
	 * @param Definition $form
	 */
	public function create( array $form ): int;

	/**
	 * The plugin's block that shows the form, in the shape parse_blocks() gives.
	 *
	 * @return array<string, mixed>
	 */
	public function block( int $id ): array;

	/**
	 * Makes messages Histeon received entries of the plugin's form (writes); returns how many.
	 *
	 * @param list<array{fields: array<string, string>, date: string}> $messages
	 */
	public function messages( int $id, array $messages ): int;
}
