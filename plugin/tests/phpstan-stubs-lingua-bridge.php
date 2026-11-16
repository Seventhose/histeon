<?php
/**
 * Lingua Bridge, only what Histeon calls when it turns the module on or off (ADR 0015). The real code is the
 * release ZIP pinned by modules.json.
 */

namespace LinguaBridge;

final class Storage {
	public static function install(): void {}

	public static function is_available(): bool {
		return true;
	}
}

final class Languages {
	/** @return array<string, mixed> */
	public static function settings(): array {
		return array();
	}
}

final class Router {
	public static function sync( bool $enabled ): string {
		return '';
	}
}

final class Routing {
	public static function deactivate(): void {}
}
