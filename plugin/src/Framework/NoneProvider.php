<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/** No framework: only the styles written in XB. */
final class NoneProvider implements Provider {

	public function id(): string {
		return 'none';
	}

	public function label(): string {
		return __( 'Nessuno', 'xb' );
	}

	public function available(): bool {
		return true;
	}

	public function note(): string {
		return '';
	}

	public function variables(): array {
		return array();
	}

	public function classes(): array {
		return array();
	}

	public function stylesheets(): array {
		return array();
	}

	public function aliases(): array {
		return array();
	}
}
