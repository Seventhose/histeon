<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Deploy;

final class DeployException extends \RuntimeException {

	public function __construct( string $message, public readonly int $status = 500 ) {
		parent::__construct( $message );
	}
}
