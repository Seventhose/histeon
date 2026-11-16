<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * The framework chosen for the site (option `xb_framework`) and the alias layer — docs/spec/framework.md.
 */
final class Frameworks {

	public const OPTION = 'xb_framework';

	/** @var array<string, Provider> */
	private array $providers = array();

	public function __construct() {
		foreach ( array( new CoreFrameworkProvider(), new XbTokensProvider(), new AcssProvider(), new NoneProvider() ) as $provider ) {
			$this->providers[ $provider->id() ] = $provider;
		}
	}

	/** @return array<string, Provider> */
	public function all(): array {
		return $this->providers;
	}

	public function chosen(): bool {
		return is_string( get_option( self::OPTION ) );
	}

	/** The selected provider; falls back to XB Tokens when the selection is missing or no longer installed. */
	public function active(): Provider {
		$id       = get_option( self::OPTION );
		$provider = is_string( $id ) ? ( $this->providers[ $id ] ?? null ) : null;
		return null !== $provider && $provider->available() ? $provider : $this->providers['xb-tokens'];
	}

	public function select( string $id ): bool {
		$provider = $this->providers[ $id ] ?? null;
		if ( null === $provider || ! $provider->available() ) {
			return false;
		}
		update_option( self::OPTION, $id );
		return true;
	}

	/**
	 * `:root{--xb-space-m:var(--space-m);…}` for the semantic tokens the active framework can back.
	 * XB components written with --xb-* survive a framework switch unchanged.
	 */
	public function alias_css(): string {
		$provider = $this->active();
		$aliases  = $provider->aliases();
		if ( array() === $aliases ) {
			return '';
		}
		$known = array();
		foreach ( $provider->variables() as $names ) {
			foreach ( $names as $name ) {
				$known[ $name ] = true;
			}
		}
		$rules = '';
		foreach ( $aliases as $token => $variable ) {
			if ( isset( $known[ $variable ] ) ) {
				$rules .= '--' . $token . ':var(--' . $variable . ');';
			}
		}
		return '' === $rules ? '' : ':root{' . $rules . '}';
	}

	/** Frontend: XB Tokens is ours to enqueue; third-party frameworks enqueue themselves. */
	public function enqueue(): void {
		$provider = $this->active();
		if ( 'xb-tokens' === $provider->id() ) {
			$design = HistosCss\Module::active() ? HistosCss\SiteDesign::current() : null;
			if ( null !== $design && 1 === (int) $design['tokens_inline'] ) {
				// The panel's «Token in linea» (histos-publish doc 19 §5.3.12): one request less, in the page's own head.
				wp_register_style( 'xb-tokens', false, array(), XB_VERSION );
				wp_enqueue_style( 'xb-tokens' );
				wp_add_inline_style( 'xb-tokens', HistosCss\Tokens::sheet( $design ) );
			} else {
				wp_enqueue_style( 'xb-tokens', XbTokensProvider::url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is in the URL.
			}
			// The site's design parameters (docs/spec/histos-css.md: only the tokens that differ), then its own token
			// values (docs/spec/design-system.md), right after the defaults.
			$own = ( HistosCss\Module::active() ? HistosCss\Tokens::css_changes( HistosCss\SiteDesign::current() ) : '' ) . TokenOverrides::css();
			if ( '' !== $own ) {
				wp_add_inline_style( 'xb-tokens', $own );
			}
			// Histeon CSS's classes: on demand (ADR 0006), a page receives only the rules of the classes it uses.
			if ( HistosCss\Module::active() ) {
				wp_enqueue_style( 'xb-histos-css', XbTokensProvider::classes_url(), array( 'xb-tokens' ), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is in the URL.
			}
		}
		$aliases = $this->alias_css();
		if ( '' !== $aliases ) {
			wp_register_style( 'xb-aliases', false, array(), XB_VERSION );
			wp_add_inline_style( 'xb-aliases', $aliases );
			wp_enqueue_style( 'xb-aliases' );
		}
	}
}
