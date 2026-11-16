<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/** Runtime error while evaluating an expression (unknown modifier, wrong argument type, …). */
final class EvalError extends \RuntimeException {}
