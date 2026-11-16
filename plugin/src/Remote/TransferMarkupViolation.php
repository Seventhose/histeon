<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

/**
 * A violation of histos-transfer-markup-v1 (TransferMarkup). `reason` is one of TransferMarkup::REASONS and is
 * compared exactly by the shared fixture tests on both sides; the message is for people and never compared.
 * Extends DomainException, so every caller that refuses a profile on a DomainException keeps doing so.
 */
final class TransferMarkupViolation extends \DomainException {

	public function __construct( public readonly string $reason, string $message, ?\Throwable $previous = null ) {
		if ( ! in_array( $reason, TransferMarkup::REASONS, true ) ) {
			throw new \LogicException( 'Unknown transfer markup reason: ' . $reason );
		}
		parent::__construct( $message, 0, $previous );
	}
}
