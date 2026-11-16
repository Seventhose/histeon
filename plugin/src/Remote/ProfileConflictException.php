<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

/** A conflict stops a CMS import before anything on the site changes (docs/spec/cms-bridge.md §5). */
final class ProfileConflictException extends \RuntimeException {
	/** @param list<array<string, mixed>> $conflicts */
	public function __construct( public readonly array $conflicts ) {
		parent::__construct( __( 'Alcune modifiche sono in conflitto con quelle già fatte su questo sito.', 'xb' ) );
	}
}
