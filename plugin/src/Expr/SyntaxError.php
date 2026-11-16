<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/** Invalid expression source. Messages match packages/dsl (shared vectors in packages/spec/vectors). */
final class SyntaxError extends \RuntimeException {

	public function __construct( string $message, public readonly int $offset ) {
		parent::__construct( $message );
	}
}
