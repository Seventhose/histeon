<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * A CSS framework XB can build with — docs/spec/framework.md.
 * XB never bundles a third-party framework and never handles license keys: providers only detect a
 * framework the site owner installed and read what it exposes.
 */
interface Provider {

	public function id(): string;

	public function label(): string;

	/** Installed and usable on this site. */
	public function available(): bool;

	/** Why the provider is unavailable (shown in the settings), or ''. */
	public function note(): string;

	/**
	 * Custom property names without the leading `--`, grouped by category.
	 *
	 * @return array<string, list<string>>
	 */
	public function variables(): array;

	/**
	 * Utility and component classes, grouped by category.
	 *
	 * @return array<string, list<string>>
	 */
	public function classes(): array;

	/**
	 * Stylesheets the canvas must load to look like the site (the plugin enqueues them on the frontend itself).
	 *
	 * @return list<string>
	 */
	public function stylesheets(): array;

	/**
	 * Semantic XB token → framework variable (both without `--`), for the alias layer.
	 *
	 * @return array<string, string>
	 */
	public function aliases(): array;
}
