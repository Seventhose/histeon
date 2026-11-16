<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * Lazily resolved data object exposed to expressions (`this`, `site`, `post.author`, …).
 * Implementations decide which keys exist; unknown keys return null (expressions are null-safe).
 */
interface Node {

	public function get( string $key ): mixed;
}
