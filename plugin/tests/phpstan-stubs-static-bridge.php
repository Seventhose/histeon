<?php
/**
 * Static Bridge, only what Histeon calls when it turns the module on or off (ADR 0015). The real code is the
 * release ZIP pinned by modules.json.
 */

final class Static_Bridge_Plugin {
	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed> ok, errors, warnings (its own shape: read defensively).
	 */
	public static function activation_preflight( $context = array() ): array {
		return array( 'ok' => true, 'errors' => array(), 'warnings' => array() );
	}

	public static function activate(): void {}

	public static function deactivate(): void {}
}
