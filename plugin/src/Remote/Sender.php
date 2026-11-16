<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Plugin;
use XB\Versioning\SiteTransfer;
use XB\Versioning\Versioning;

/**
 * Staging side (docs/spec/remote.md): packs what is live here, as a `.xbsite` file, and sends it signed to the
 * production site of the pairing code — first as a dry run, then for real. What production does with it (in
 * development, or published) is production's choice, set when it created the code.
 */
final class Sender {

	public const SCOPE_RELEASE = 'release';
	public const SCOPE_SITE    = 'site';

	/**
	 * What the release that is live here changed (what «the latest release» sends). A release stores every live
	 * document; the changed ones are those whose version differs from the release before (all of them for the
	 * first one).
	 *
	 * @return list<string>
	 */
	public static function release_docs(): array {
		$store   = Plugin::instance()->versioning->store();
		$id      = (int) get_option( Versioning::OPTION_CURRENT, 0 );
		$release = $id > 0 ? $store->get_release( $id ) : null;
		if ( null === $release ) {
			return array();
		}
		$before = null;
		foreach ( $store->releases( 200 ) as $row ) {
			if ( $row['id'] < $id ) {
				$before = $store->get_release( $row['id'] );
				break;
			}
		}
		$previous = null === $before ? array() : $before['items'];
		return array_map( 'strval', array_keys( array_filter( $release['items'], static fn ( string $commit, string $doc ): bool => ( $previous[ $doc ] ?? null ) !== $commit, ARRAY_FILTER_USE_BOTH ) ) );
	}

	/**
	 * Confirms the pairing to production, naming this site's address: production binds it to the link and accepts
	 * packages only from it (doc 23, voce 173).
	 *
	 * @throws \RuntimeException With a message for the administrator; code 409 when production refuses the address.
	 */
	public static function pair(): void {
		$link = Link::outbound();
		if ( null === $link ) {
			throw new \RuntimeException( __( 'Nessun sito di produzione collegato.', 'xb' ) );
		}
		$route    = Receiver::route( Receiver::PAIR );
		$body     = (string) wp_json_encode( array( 'site' => untrailingslashit( home_url( '/' ) ) ), JSON_UNESCAPED_SLASHES );
		$response = wp_remote_post(
			$link['url'] . '/?rest_route=' . $route,
			array(
				'body'                => $body,
				'headers'             => array( 'Content-Type' => 'application/json' ) + Link::headers( $link['secret'], 'POST', $route, $body ),
				'timeout'             => 30,
				'redirection'         => 0,
				'reject_unsafe_urls'  => ! Link::local(),
				'limit_response_size' => 64 * 1024,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			throw new \RuntimeException( sprintf( __( 'Il sito di produzione non risponde: %s', 'xb' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? mb_substr( wp_strip_all_tags( $data['message'] ), 0, 300 ) : (string) $code;
			throw new \RuntimeException( $message, $code );
		}
	}

	/**
	 * Sends this site's live state to production.
	 *
	 * @return array{site: string, media: int, documents: list<array{label: string, target: string, action: string}>, contents: list<array{label: string, action: string}>, issues: list<string>, written: bool, published: string, canPublish: bool, deletions: list<string>, deleted: int}
	 * @throws \RuntimeException With a message for the administrator.
	 */
	public static function send( string $scope, bool $write, bool $delete = false ): array {
		$link = Link::outbound();
		if ( null === $link ) {
			throw new \RuntimeException( __( 'Nessun sito di produzione collegato.', 'xb' ) );
		}
		$only = null;
		if ( self::SCOPE_RELEASE === $scope ) {
			$only = self::release_docs();
			if ( array() === $only ) {
				throw new \RuntimeException( __( 'Non c\'è ancora una release online da inviare.', 'xb' ) );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = wp_tempnam( 'xb-send' );
		try {
			SiteTransfer::instance()->export( $file, $only );
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own temp file.
		} finally {
			wp_delete_file( $file );
		}
		if ( strlen( $body ) > Link::MAX_BODY ) {
			throw new \RuntimeException( __( 'Il pacchetto è troppo grande per un invio: usa il trasloco del sito.', 'xb' ) );
		}
		// Trashing what staging removed only with the whole site, and only when asked (P6).
		$delete   = $delete && $write && self::SCOPE_SITE === $scope;
		$route    = Receiver::route( $write ? ( $delete ? Receiver::IMPORT_DELETE : Receiver::IMPORT ) : Receiver::PLAN );
		$response = wp_remote_post(
			$link['url'] . '/?rest_route=' . $route,
			array(
				'body'               => $body,
				'headers'            => array( 'Content-Type' => 'application/zip' ) + Link::headers( $link['secret'], 'POST', $route, $body ),
				'timeout'            => 300,
				'redirection'        => 0,
				'reject_unsafe_urls' => ! Link::local(),
				'limit_response_size' => 4 * 1024 * 1024,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			throw new \RuntimeException( sprintf( __( 'Il sito di produzione non risponde: %s', 'xb' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? mb_substr( wp_strip_all_tags( $data['message'] ), 0, 300 ) : (string) $code;
			/* translators: %s: error message from production */
			throw new \RuntimeException( sprintf( __( 'Il sito di produzione ha rifiutato l\'invio: %s', 'xb' ), $message ) );
		}
		$report = self::report( $data );
		if ( $write ) {
			Plugin::instance()->versioning->store()->audit( 'remote_send', 'remote', array( 'to' => $link['url'], 'scope' => $scope, 'contents' => count( $report['contents'] ), 'published' => $report['published'] ) );
		}
		return $report;
	}

	/**
	 * Production's answer, reduced to the expected shape (strings, short lists): it is printed on this site.
	 *
	 * @param array<mixed> $data
	 * @return array{site: string, media: int, documents: list<array{label: string, target: string, action: string}>, contents: list<array{label: string, action: string}>, issues: list<string>, written: bool, published: string, canPublish: bool, deletions: list<string>, deleted: int}
	 */
	public static function report( array $data ): array {
		$text = static fn ( array $row, string $key, int $max ): string => mb_substr( is_string( $row[ $key ] ?? null ) ? $row[ $key ] : '', 0, $max );
		$list = static fn ( mixed $value ): array => array_values( array_filter( is_array( $value ) ? array_slice( $value, 0, 2000 ) : array(), 'is_array' ) );
		$documents = array_map( static fn ( array $row ): array => array( 'label' => $text( $row, 'label', 300 ), 'target' => $text( $row, 'target', 64 ), 'action' => $text( $row, 'action', 20 ) ), $list( $data['documents'] ?? null ) );
		$contents  = array_map( static fn ( array $row ): array => array( 'label' => $text( $row, 'label', 300 ), 'action' => $text( $row, 'action', 20 ) ), $list( $data['contents'] ?? null ) );
		return array(
			'site'       => mb_substr( is_string( $data['site'] ?? null ) ? $data['site'] : '', 0, 300 ),
			'media'      => is_int( $data['media'] ?? null ) ? $data['media'] : 0,
			'documents'  => $documents,
			'contents'   => $contents,
			'issues'     => array_values( array_map( static fn ( mixed $i ): string => mb_substr( is_string( $i ) ? $i : '', 0, 500 ), is_array( $data['issues'] ?? null ) ? array_slice( $data['issues'], 0, 200 ) : array() ) ),
			'written'    => true === ( $data['written'] ?? null ),
			'published'  => mb_substr( is_string( $data['published'] ?? null ) ? $data['published'] : '', 0, 20 ),
			'canPublish' => true === ( $data['canPublish'] ?? null ),
			'deletions'  => array_values( array_map( static fn ( mixed $i ): string => mb_substr( is_string( $i ) ? $i : '', 0, 300 ), is_array( $data['deletions'] ?? null ) ? array_slice( $data['deletions'], 0, 500 ) : array() ) ),
			'deleted'    => is_int( $data['deleted'] ?? null ) ? $data['deleted'] : 0,
		);
	}
}
