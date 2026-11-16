<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Updates;

/**
 * Updates without licences — docs/spec/updates.md. Off unless an administrator gives a GitHub repository and the
 * release public key (or wp-config.php sets XB_UPDATE_REPOSITORY / XB_UPDATE_PUBLIC_KEY). Then WordPress offers
 * the latest GitHub release like any update, and before installing it Histeon downloads the ZIP and its .minisig
 * and checks the signature: a release not signed by that key is never installed. Nothing about the site is sent:
 * one anonymous request to GitHub's public API, at most every 12 hours.
 */
final class Updater {

	public const OPTION    = 'xb_updates';
	public const CACHE     = 'xb_updates_latest';
	private const API      = 'https://api.github.com/repos/';
	private const MAX_ZIP  = 60 * 1024 * 1024;
	private const PLUGIN   = 'xb/xb.php';

	public static function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offer' ) );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 4 );
	}

	/**
	 * The settings, with wp-config.php winning.
	 *
	 * @return array{enabled: bool, repository: string, key: string}
	 */
	public static function settings(): array {
		$saved = self::stored();
		$repo  = defined( 'XB_UPDATE_REPOSITORY' ) ? (string) XB_UPDATE_REPOSITORY : (string) ( $saved['repository'] ?? '' );
		$key   = defined( 'XB_UPDATE_PUBLIC_KEY' ) ? (string) XB_UPDATE_PUBLIC_KEY : (string) ( $saved['key'] ?? '' );
		return array(
			'enabled'    => ( defined( 'XB_UPDATE_REPOSITORY' ) || ! empty( $saved['enabled'] ) ) && self::valid_repository( $repo ) && '' !== $key,
			'repository' => $repo,
			'key'        => $key,
		);
	}

	/**
	 * The saved choice. It decides which code the whole installation may receive, so it lives at network level (on a
	 * single site that is the ordinary option table) and only who may update plugins network-wide can change it.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$saved = get_site_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	public static function can_configure(): bool {
		return is_multisite() ? current_user_can( 'manage_network_plugins' ) : current_user_can( 'update_plugins' );
	}

	public static function valid_repository( string $repo ): bool {
		return 1 === preg_match( '#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo );
	}

	/**
	 * Saves what the administrator chose; the key must be a minisign public key.
	 *
	 * @throws \InvalidArgumentException With a message for the administrator.
	 */
	public static function save( bool $enabled, string $repository, string $key ): void {
		$repository = trim( $repository );
		$key        = trim( $key );
		if ( '' !== $repository && ! self::valid_repository( $repository ) ) {
			throw new \InvalidArgumentException( __( 'Repository GitHub non valido: scrivi proprietario/nome.', 'xb' ) );
		}
		if ( '' !== $key ) {
			Minisign::public_key( $key );
		}
		if ( $enabled && ( '' === $repository || '' === $key ) ) {
			throw new \InvalidArgumentException( __( 'Per accendere gli aggiornamenti servono il repository e la chiave pubblica.', 'xb' ) );
		}
		update_site_option(
			self::OPTION,
			array(
				'enabled'    => $enabled,
				'repository' => $repository,
				'key'        => $key,
			)
		);
		delete_site_transient( self::CACHE );
	}

	/**
	 * The newest release on GitHub that has a Histeon ZIP and its signature, cached 12 hours.
	 *
	 * @return array{version: string, zip: string, sig: string, url: string}|null
	 */
	public static function latest(): ?array {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['repository'] ?? '' ) === $settings['repository'] ) {
			$release = $cached['release'] ?? null;
			if ( ! is_array( $release ) ) {
				return null;
			}
			$fields = array();
			foreach ( array( 'version', 'zip', 'sig', 'url' ) as $field ) {
				if ( ! is_string( $release[ $field ] ?? null ) ) {
					return null;
				}
				$fields[ $field ] = $release[ $field ];
			}
			return array(
				'version' => $fields['version'],
				'zip'     => $fields['zip'],
				'sig'     => $fields['sig'],
				'url'     => $fields['url'],
			);
		}
		$response = wp_safe_remote_get(
			self::API . $settings['repository'] . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Histeon-updater' ),
			)
		);
		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}
		set_site_transient( self::CACHE, array( 'repository' => $settings['repository'], 'release' => $release ), 12 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * A GitHub release as Histeon needs it: the version from the tag, the plugin ZIP and its .minisig, both downloaded
	 * from github.com over HTTPS. Null when anything is missing.
	 *
	 * @return array{version: string, zip: string, sig: string, url: string}|null
	 */
	public static function parse( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^v?(\d+\.\d+\.\d+)$/', (string) ( $data['tag_name'] ?? '' ), $m ) ) {
			return null;
		}
		$assets = array();
		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $asset ) {
			$name = is_array( $asset ) ? (string) ( $asset['name'] ?? '' ) : '';
			$url  = is_array( $asset ) ? (string) ( $asset['browser_download_url'] ?? '' ) : '';
			if ( str_starts_with( $url, 'https://github.com/' ) ) {
				$assets[ $name ] = $url;
			}
		}
		foreach ( $assets as $name => $url ) {
			if ( 1 === preg_match( '/^xb-' . preg_quote( $m[1], '/' ) . '(?:-[0-9a-f]{7})?\.zip$/', $name ) && isset( $assets[ $name . '.minisig' ] ) ) {
				$page = (string) ( $data['html_url'] ?? '' );
				return array(
					'version' => $m[1],
					'zip'     => $url,
					'sig'     => $assets[ $name . '.minisig' ],
					'url'     => str_starts_with( $page, 'https://github.com/' ) ? $page : '',
				);
			}
		}
		return null;
	}

	/**
	 * WordPress's update check: a newer signed release becomes an update of Histeon.
	 *
	 * @param mixed $transient
	 */
	public static function offer( mixed $transient ): mixed {
		if ( ! $transient instanceof \stdClass ) {
			return $transient;
		}
		$release = self::latest();
		if ( null === $release || ! version_compare( $release['version'], XB_VERSION, '>' ) ) {
			return $transient;
		}
		$transient->response                 = is_array( $transient->response ?? null ) ? $transient->response : array();
		$transient->response[ self::PLUGIN ] = (object) array(
			'slug'        => 'xb',
			'plugin'      => self::PLUGIN,
			'new_version' => $release['version'],
			'package'     => $release['zip'],
			'url'         => $release['url'],
		);
		return $transient;
	}

	/**
	 * Before WordPress installs the Histeon ZIP: download it and its signature, and verify. A failed check stops the
	 * update with the reason; anything that is not our package is left to WordPress. An update of this plugin whose
	 * package is no longer the latest signed release (the release changed, the signature disappeared, updates were
	 * switched off since the offer) is refused, never installed unchecked.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 */
	public static function download( mixed $reply, mixed $package, mixed $upgrader = null, mixed $hook_extra = array() ): mixed {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$release = self::latest();
		$ours    = is_array( $hook_extra ) && self::PLUGIN === ( $hook_extra['plugin'] ?? null );
		if ( null === $release || $package !== $release['zip'] ) {
			return $ours
				? new \WP_Error( 'xb_update_signature', __( 'Aggiornamento di Histeon fermato: il pacchetto non è più l’ultima release firmata. Controlla di nuovo gli aggiornamenti.', 'xb' ) )
				: $reply;
		}
		$zip = download_url( $package, 120 );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		try {
			if ( (int) filesize( $zip ) > self::MAX_ZIP ) {
				throw new \InvalidArgumentException( __( 'Pacchetto troppo grande.', 'xb' ) );
			}
			$sig = wp_safe_remote_get( $release['sig'], array( 'timeout' => 20, 'limit_response_size' => 4096 ) );
			if ( is_wp_error( $sig ) || 200 !== wp_remote_retrieve_response_code( $sig ) ) {
				throw new \InvalidArgumentException( __( 'Firma della release non scaricata.', 'xb' ) );
			}
			$comment = Minisign::verify( $zip, wp_remote_retrieve_body( $sig ), self::settings()['key'] );
			// The signed file name is the release asset's name, exactly, and the plugin's full package of this
			// version: another signed build of the same version (one without modules, another commit) whose
			// name merely contains the version is refused.
			$signed = preg_match( '/(?:^|\t)file:([^\t]+)/', $comment, $m ) ? $m[1] : '';
			$asset  = rawurldecode( basename( (string) wp_parse_url( $package, PHP_URL_PATH ) ) );
			if ( '' === $signed || $signed !== $asset || 1 !== preg_match( '/^xb-' . preg_quote( (string) $release['version'], '/' ) . '(?:-[0-9a-f]{7,40})?\.zip$/', $signed ) ) {
				throw new \InvalidArgumentException( __( 'La firma è di un altro pacchetto.', 'xb' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			wp_delete_file( $zip );
			/* translators: %s: reason */
			return new \WP_Error( 'xb_update_signature', sprintf( __( 'Aggiornamento di Histeon fermato: %s', 'xb' ), $e->getMessage() ) );
		}
		return $zip;
	}
}
