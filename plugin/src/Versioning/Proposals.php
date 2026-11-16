<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;

/**
 * Proposals for a client (lista della spesa 160): named snapshots of what is in development, each with its signed
 * preview link, side by side in a comparison link; the one chosen goes back into development and becomes the next
 * release. A proposal is a map document → commit, taken from the dev heads of the changed documents: nothing is
 * copied, and the version model stays one development line and the live site. Its preview shows the live site with
 * the proposal's documents; any other development work stays out of it.
 *
 * @phpstan-type Proposal array{id: string, name: string, created: int, author: int, post: int, items: array<string, string>}
 */
final class Proposals {

	public const OPTION = 'xb_proposals';
	public const MAX    = 20;

	/** @return array<string, Proposal> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $p ) {
			if ( is_array( $p ) && 1 === preg_match( '/^[0-9a-f]{12}$/', (string) $id ) && is_array( $p['items'] ?? null ) ) {
				$out[ (string) $id ] = array(
					'id'      => (string) $id,
					'name'    => (string) ( $p['name'] ?? '' ),
					'created' => (int) ( $p['created'] ?? 0 ),
					'author'  => (int) ( $p['author'] ?? 0 ),
					'post'    => (int) ( $p['post'] ?? 0 ),
					'items'   => array_filter( array_map( 'strval', $p['items'] ), static fn ( string $c ): bool => 1 === preg_match( '/^[0-9a-f]{32}$/', $c ) ),
				);
			}
		}
		return $out;
	}

	/** @return Proposal|null */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Saves what is in development now as a proposal, opened on $post (a page of the site, the home by default).
	 *
	 * @return Proposal
	 */
	public static function create( string $name, int $post ): array {
		if ( ! current_user_can( 'xb_build' ) ) {
			throw new VersioningException( __( 'Serve il permesso di costruire con Histeon.', 'xb' ), 403 );
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			throw new VersioningException( __( 'Dai un nome alla proposta, per esempio «Proposta A».', 'xb' ), 400 );
		}
		$all = self::all();
		if ( count( $all ) >= self::MAX ) {
			throw new VersioningException( sprintf( /* translators: %d: number */ __( 'Al massimo %d proposte: elimina quelle che non servono più.', 'xb' ), self::MAX ), 400 );
		}
		$versioning = Plugin::instance()->versioning;
		$items      = array();
		foreach ( $versioning->changes() as $change ) {
			if ( Documents::MEDIA === $change['doc'] || Documents::CONTENTS === $change['doc'] ) {
				continue;
			}
			$head = $versioning->store()->head( $change['doc'], Store::DEV );
			if ( null !== $head ) {
				$items[ $change['doc'] ] = $head;
			}
		}
		if ( array() === $items ) {
			throw new VersioningException( __( 'Non c’è niente in sviluppo da proporre: la proposta sarebbe uguale al sito online.', 'xb' ), 400 );
		}
		$post = $post > 0 && current_user_can( 'edit_post', $post ) ? $post : (int) get_option( 'page_on_front' );
		if ( $post <= 0 ) {
			// No home page: the first page or post of the proposal.
			foreach ( array_keys( $items ) as $doc ) {
				$candidate = (int) Documents::post_id( $doc );
				if ( $candidate > 0 && in_array( get_post_type( $candidate ), array( 'page', 'post' ), true ) ) {
					$post = $candidate;
					break;
				}
			}
		}
		if ( $post <= 0 ) {
			throw new VersioningException( __( 'Scegli la pagina su cui aprire la proposta.', 'xb' ), 400 );
		}
		$id   = bin2hex( random_bytes( 6 ) );
		$all[ $id ] = array(
			'id'      => $id,
			'name'    => mb_substr( $name, 0, 80 ),
			'created' => time(),
			'author'  => get_current_user_id(),
			'post'    => $post,
			'items'   => $items,
		);
		update_option( self::OPTION, $all, false );
		$versioning->store()->audit( 'proposal', $id, array( 'name' => $name, 'docs' => array_keys( $items ) ) );
		return $all[ $id ];
	}

	public static function delete( string $id ): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			throw new VersioningException( __( 'Serve il permesso di costruire con Histeon.', 'xb' ), 403 );
		}
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * The chosen proposal becomes what is in development: its documents go back to the proposal's versions, and the
	 * other documents in development go back to what is online, so the next release is exactly the proposal. Each
	 * as a new dev commit (Versioning::revert): what development held stays in the history.
	 *
	 * @return array{restored: list<string>, failed: array<string, string>}
	 */
	public static function choose( string $id ): array {
		$proposal = self::get( $id );
		if ( null === $proposal ) {
			throw new VersioningException( __( 'Proposta inesistente.', 'xb' ), 404 );
		}
		if ( ! current_user_can( 'xb_build' ) ) {
			throw new VersioningException( __( 'Serve il permesso di costruire con Histeon.', 'xb' ), 403 );
		}
		$versioning = Plugin::instance()->versioning;
		$store      = $versioning->store();
		$targets    = $proposal['items'];
		foreach ( $versioning->changes() as $change ) {
			$doc = $change['doc'];
			if ( ! isset( $targets[ $doc ] ) && Documents::MEDIA !== $doc && Documents::CONTENTS !== $doc ) {
				$prod = $store->head( $doc, Store::PROD );
				if ( null !== $prod ) {
					$targets[ $doc ] = $prod;
				}
			}
		}
		$restored = array();
		$failed   = array();
		foreach ( $targets as $doc => $commit ) {
			$post = Documents::post_id( $doc );
			if ( null !== $post && ! current_user_can( 'edit_post', $post ) ) {
				$failed[ $doc ] = __( 'non puoi modificarlo', 'xb' );
				continue;
			}
			if ( $store->head( $doc, Store::DEV ) === $commit ) {
				$restored[] = $doc;
				continue;
			}
			try {
				$versioning->revert( $doc, $commit );
				$restored[] = $doc;
			} catch ( VersioningException $e ) {
				$failed[ $doc ] = $e->getMessage();
			}
		}
		$store->audit( 'proposal-chosen', $id, array( 'restored' => $restored, 'failed' => array_keys( $failed ) ) );
		return array( 'restored' => $restored, 'failed' => $failed );
	}

	/**
	 * The signed preview link of a proposal.
	 *
	 * @param Proposal $proposal
	 */
	public static function url( array $proposal ): string {
		$post = $proposal['post'];
		$link = $post > 0 ? get_permalink( $post ) : home_url( '/' );
		return add_query_arg( Preview::PARAM, Preview::token( $post, Preview::PROPOSAL_TTL, $proposal['id'] ), is_string( $link ) ? $link : home_url( '/' ) );
	}

	/**
	 * The signed link that shows two proposals side by side.
	 *
	 * @param Proposal $a
	 * @param Proposal $b
	 */
	public static function compare_url( array $a, array $b ): string {
		return add_query_arg( Preview::COMPARE, Preview::token( $a['post'], Preview::PROPOSAL_TTL, $a['id'] ) . '~' . Preview::token( $b['post'], Preview::PROPOSAL_TTL, $b['id'] ), home_url( '/' ) );
	}
}
