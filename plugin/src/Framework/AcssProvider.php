<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * Automatic.css 4, when the site owner has installed it (a commercial plugin: XB neither bundles it nor
 * touches its license). Reads the inventory ACSS keeps of the classes and variables it generated.
 */
final class AcssProvider implements Provider {

	public function id(): string {
		return 'acss';
	}

	public function label(): string {
		return 'Automatic.css';
	}

	public function available(): bool {
		return class_exists( '\\Automatic_CSS\\API' );
	}

	public function note(): string {
		return $this->available() ? '' : __( 'Plugin di terze parti: compare qui se lo installi e lo attivi tu.', 'xb' );
	}

	public function variables(): array {
		return array( 'acss' => $this->inventory( 'variables' ) );
	}

	public function classes(): array {
		return array( 'acss' => $this->inventory( 'classes' ) );
	}

	public function stylesheets(): array {
		$uploads = wp_upload_dir();
		$out     = array();
		foreach ( array( 'automatic-variables.css', 'automatic.css', 'automatic-tokens.css', 'automatic-custom-css.css' ) as $file ) {
			$path = $uploads['basedir'] . '/automatic-css/' . $file;
			if ( is_readable( $path ) && filesize( $path ) > 0 ) {
				$out[] = $uploads['baseurl'] . '/automatic-css/' . $file . '?ver=' . (string) filemtime( $path );
			}
		}
		return $out;
	}

	public function aliases(): array {
		return array(
			'xb-space-xs'      => 'space-xs',
			'xb-space-s'       => 'space-s',
			'xb-space-m'       => 'space-m',
			'xb-space-l'       => 'space-l',
			'xb-space-xl'      => 'space-xl',
			'xb-space-xxl'     => 'space-xxl',
			'xb-text-s'        => 'text-s',
			'xb-text-m'        => 'text-m',
			'xb-text-l'        => 'text-l',
			'xb-text-xl'       => 'text-xl',
			'xb-text-xxl'      => 'text-xxl',
			'xb-primary'       => 'primary',
			'xb-primary-light' => 'primary-light',
			'xb-primary-dark'  => 'primary-dark',
			'xb-secondary'     => 'secondary',
			'xb-bg'            => 'body-bg-color',
			'xb-surface'       => 'bg-light',
			'xb-text'          => 'text-dark',
			'xb-heading'       => 'heading-color',
			'xb-radius-s'      => 'radius-s',
			'xb-radius-m'      => 'radius-m',
			'xb-radius-l'      => 'radius-l',
			'xb-shadow-s'      => 'box-shadow-1',
			'xb-shadow-m'      => 'box-shadow-2',
			'xb-shadow-l'      => 'box-shadow-3',
		);
	}

	/** @return list<string> */
	private function inventory( string $key ): array {
		if ( ! $this->available() ) {
			return array();
		}
		$inventory = get_option( 'automatic_css_generated_inventory' );
		return is_array( $inventory ) ? Lists::names( $inventory[ $key ] ?? array() ) : array();
	}
}
