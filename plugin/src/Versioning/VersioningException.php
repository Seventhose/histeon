<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/** A versioning operation could not complete; when raised inside a transaction, nothing was changed. */
final class VersioningException extends \RuntimeException {

	public function __construct( string $message, public readonly int $status = 500 ) {
		parent::__construct( $message );
	}
}
