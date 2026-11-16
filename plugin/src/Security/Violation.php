<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

/**
 * A single reason why a piece of markup cannot be saved as-is.
 * Codes are stable (used by the editor to highlight and by tests); messages are user-facing.
 */
final class Violation {

	public const TAG_INVALID        = 'tag.invalid';
	public const TAG_FORBIDDEN      = 'tag.forbidden';
	public const TAG_TRUSTED_ONLY   = 'tag.trusted_only';
	public const TAG_NOT_ALLOWED    = 'tag.not_allowed';
	public const ATTR_INVALID       = 'attr.invalid';
	public const ATTR_TRUSTED_ONLY  = 'attr.trusted_only';
	public const ATTR_UNSAFE_URL    = 'attr.unsafe_url';

	public function __construct(
		public readonly string $code,
		public readonly string $subject,
		public readonly string $message,
	) {}
}
