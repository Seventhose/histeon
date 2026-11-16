<?php
/**
 * Signatures of third-party classes XB detects at runtime (static analysis only).
 *
 * @package XB
 */

namespace CoreFramework;

class Helper {
	/**
	 * @param array<string, mixed> $options
	 * @return array<mixed>
	 */
	public function getVariables( array $options = array() ): array {
		return array();
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<mixed>
	 */
	public function getClassNames( array $options = array() ): array {
		return array();
	}
}
