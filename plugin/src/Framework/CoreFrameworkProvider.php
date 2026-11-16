<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/** Core Framework (MIT, coreframework.com), when the plugin is installed and a preset has been saved. */
final class CoreFrameworkProvider implements Provider {

	public function id(): string {
		return 'core-framework';
	}

	public function label(): string {
		return 'Core Framework';
	}

	public function available(): bool {
		return class_exists( '\\CoreFramework\\Helper' );
	}

	public function note(): string {
		if ( ! $this->available() ) {
			return __( 'Installa e attiva il plugin gratuito Core Framework.', 'xb' );
		}
		return array() === $this->variables() ? __( 'Apri Core Framework nell\'amministrazione e salva un preset: finché non lo fai il framework è vuoto.', 'xb' ) : '';
	}

	public function variables(): array {
		return $this->read( 'getVariables' );
	}

	public function classes(): array {
		return $this->read( 'getClassNames' );
	}

	public function stylesheets(): array {
		$uploads = wp_upload_dir();
		$file    = $uploads['basedir'] . '/core-framework/css/core_framework.css';
		if ( ! is_readable( $file ) || 0 === filesize( $file ) ) {
			return array();
		}
		return array( $uploads['baseurl'] . '/core-framework/css/core_framework.css?ver=' . (string) filemtime( $file ) );
	}

	public function aliases(): array {
		return array(
			'xb-space-xs'      => 'space-xs',
			'xb-space-s'       => 'space-s',
			'xb-space-m'       => 'space-m',
			'xb-space-l'       => 'space-l',
			'xb-space-xl'      => 'space-xl',
			'xb-space-xxl'     => 'space-2xl',
			'xb-text-s'        => 'text-s',
			'xb-text-m'        => 'text-m',
			'xb-text-l'        => 'text-l',
			'xb-text-xl'       => 'text-xl',
			'xb-text-xxl'      => 'text-2xl',
			'xb-primary'       => 'primary',
			'xb-primary-light' => 'primary-l-2',
			'xb-primary-dark'  => 'primary-d-2',
			'xb-secondary'     => 'secondary',
			'xb-bg'            => 'bg-body',
			'xb-surface'       => 'bg-surface',
			'xb-text'          => 'text-body',
			'xb-heading'       => 'text-title',
			'xb-radius-s'      => 'radius-s',
			'xb-radius-m'      => 'radius-m',
			'xb-radius-l'      => 'radius-l',
			'xb-shadow-s'      => 'shadow-s',
			'xb-shadow-m'      => 'shadow-m',
			'xb-shadow-l'      => 'shadow-l',
		);
	}

	/** @return array<string, list<string>> */
	private function read( string $method ): array {
		if ( ! $this->available() ) {
			return array();
		}
		try {
			$helper = new \CoreFramework\Helper();
			$data   = $helper->{$method}( array( 'group_by_category' => true ) );
		} catch ( \Throwable ) {
			return array();
		}
		return Lists::grouped( $data );
	}
}
