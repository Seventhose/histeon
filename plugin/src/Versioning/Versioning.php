<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;
use XB\Remote\PageProfileAdapter;
use XB\Scripts\Scripts;
use XB\Security\MarkupPolicy;

/**
 * Commits, branches and releases — docs/spec/versioning.md.
 *
 * - dev holds the builder's work; prod is what visitors see (the live post_content/options).
 * - Saving in the builder commits on dev. Publishing applies dev to the live site and records a release,
 *   all inside one database transaction.
 * - Client edits made in Gutenberg become prod commits; their texts are merged into dev by uid.
 * - Rollback restores a previous release as a new release (history is never rewritten).
 */
final class Versioning {

	public const OPTION_CURRENT = 'xb_current_release';

	public function __construct( private readonly Store $store ) {}

	public function store(): Store {
		return $this->store;
	}

	/**
	 * Starts tracking a document: its current live state becomes the first commit of both branches.
	 *
	 * @return string The prod head.
	 */
	public function ensure( string $doc ): string {
		$prod = $this->store->head( $doc, Store::PROD );
		if ( null !== $prod ) {
			if ( null === $this->store->head( $doc, Store::DEV ) ) {
				$this->store->move( $doc, Store::DEV, $prod );
			}
			return $prod;
		}
		$commit = $this->store->commit( $doc, Store::PROD, Documents::read( $doc ), null, null, __( 'Versione iniziale', 'xb' ) );
		$this->store->move( $doc, Store::PROD, $commit['id'] );
		$this->store->move( $doc, Store::DEV, $commit['id'] );
		return $commit['id'];
	}

	/**
	 * What the builder edits: the dev payload with the client's newer prod texts merged in.
	 *
	 * @return array{payload: array<string, mixed>, head: string, base: string, merged: int, conflicts: list<array{uid: string, dev: string, prod: string}>}
	 */
	public function working_copy( string $doc ): array {
		$prod_id = $this->ensure( $doc );
		$dev_id  = (string) $this->store->head( $doc, Store::DEV );
		$dev     = $this->must( $dev_id );
		$payload = $dev['payload'];
		$merge   = array(
			'merged'    => 0,
			'conflicts' => array(),
		);
		$base_id = $dev['base'] ?? $dev['id'];
		if ( $dev_id !== $prod_id && $base_id !== $prod_id && is_string( $payload['content'] ?? null ) ) {
			$merge              = $this->merge_content( $payload, $base_id, $prod_id );
			$payload['content'] = $merge['content'];
		}
		return array(
			'payload'   => $payload,
			'head'      => $dev_id,
			'base'      => $prod_id,
			'merged'    => $merge['merged'],
			'conflicts' => $merge['conflicts'],
		);
	}

	/**
	 * Saves the builder's work as a dev commit.
	 *
	 * @param array<string, mixed> $payload
	 * @param string               $expected_head Dev head the builder loaded (optimistic lock).
	 * @param string               $base          Prod head the builder merged against.
	 * @param bool                 $inherit_trust For rewrites the author did not review (selector rename): the
	 *                                            commit stays untrusted when the dev head it replaces is (ADR 0007).
	 * @param bool                 $untrusted     Content written by someone else without unfiltered_html (imports,
	 *                                            ADR 0008): filtered and recorded as untrusted, whoever saves it.
	 * @return array{id: string, doc: string, branch: string, parent: ?string, base: ?string, payload: array<string, mixed>, hash: string, author: int, trusted: bool, message: string, created: string}
	 */
	public function save_dev( string $doc, array $payload, string $expected_head, string $base, string $message = '', bool $inherit_trust = false, bool $untrusted = false, bool $agent = false ): array {
		$this->ensure( $doc );
		$head = $this->store->head( $doc, Store::DEV );
		if ( $head !== $expected_head ) {
			throw new VersioningException( __( 'Il documento è stato salvato altrove dopo che lo hai aperto. Ricarica prima di salvare.', 'xb' ), 409 );
		}
		$prod_payload = $this->must( (string) $this->store->head( $doc, Store::PROD ) )['payload'];
		$dev_head     = $this->store->get( $head );
		// An AI agent writes as an author without unfiltered_html, whoever it works for: what it writes may come
		// from instructions planted in content it read (docs/security/threat-model.md T6). Trusted nodes already
		// in the document stay, as for any restricted author; the commit is untrusted, so the preview blocks
		// scripts and the release filters it again.
		$restricted = $untrusted || $agent || ! current_user_can( 'unfiltered_html' );
		if ( is_string( $payload['content'] ?? null ) && $restricted ) {
			// An import references nothing: none of its markup was ever approved by a trusted author.
			$references = $untrusted ? array() : array( (string) ( $prod_payload['content'] ?? '' ) );
			if ( ! $untrusted && null !== $dev_head && $dev_head['trusted'] ) {
				$references[] = (string) ( $dev_head['payload']['content'] ?? '' );
			}
			$payload['content'] = Plugin::instance()->sanitizer->restricted_content( $payload['content'], ...$references );
		}
		if ( Documents::STYLES === $doc && $restricted ) {
			$payload = Documents::restricted_styles( $payload );
		}
		if ( array_key_exists( 'script', $payload ) && ( $payload['script'] ?? '' ) !== ( $prod_payload['script'] ?? '' ) && $restricted ) {
			throw new VersioningException( __( 'Solo chi ha il permesso unfiltered_html può modificare il JavaScript.', 'xb' ), 403 );
		}
		// The site's own scripts (lista della spesa 148) run on every page: like a document's JavaScript, only who has
		// unfiltered_html changes them, and never an import, an agent or a transfer (T1, T6).
		if ( Documents::SETTINGS === $doc && array_key_exists( \XB\Scripts\SiteScripts::KEY, $payload ) && $restricted ) {
			$known = null === $dev_head ? ( $prod_payload[ \XB\Scripts\SiteScripts::KEY ] ?? array() ) : ( $dev_head['payload'][ \XB\Scripts\SiteScripts::KEY ] ?? array() );
			if ( $payload[ \XB\Scripts\SiteScripts::KEY ] !== $known ) {
				throw new VersioningException( __( 'Solo chi ha il permesso unfiltered_html può cambiare gli script del sito.', 'xb' ), 403 );
			}
		}
		if ( is_string( $payload['script'] ?? null ) && strlen( $payload['script'] ) > Scripts::MAX_BYTES ) {
			throw new VersioningException( __( 'Il JavaScript supera la dimensione massima.', 'xb' ), 413 );
		}
		if ( Documents::SETTINGS === $doc ) {
			// What the dev version already holds passes; a change needs an administrator (Documents::admin_only).
			$before = null === $dev_head ? $prod_payload : $dev_head['payload'];
			foreach ( array( 'content', 'publicFields' ) as $key ) {
				if ( array_key_exists( $key, $payload ) ) {
					Documents::admin_only( $key, $payload[ $key ], $before[ $key ] ?? ( 'content' === $key ? \XB\Content\ContentTypes::live() : (array) get_option( \XB\Fields\Fields::OPTION, array() ) ) );
				}
			}
		}
		$trusted = $untrusted || $agent || ( $inherit_trust && null !== $dev_head && ! $dev_head['trusted'] ) ? false : null;
		$commit  = $this->store->transaction(
			function () use ( $doc, $payload, $head, $base, $message, $trusted ): array {
				$commit = $this->store->commit( $doc, Store::DEV, $payload, $head, $base, '' === $message ? __( 'Salvataggio dal builder', 'xb' ) : $message, $trusted );
				$this->store->move_if( $doc, Store::DEV, $head, $commit['id'] );
				$this->store->audit( 'save', $doc, array( 'commit' => $commit['id'], 'trusted' => $commit['trusted'] ) );
				return $commit;
			}
		);
		if ( $agent ) {
			// The restore point before the agent (lista della spesa 158): the head this commit replaced.
			\XB\Ai\AgentSession::record( $doc, (string) $head );
		}
		return $commit;
	}

	/**
	 * Brings an earlier version of a document back as a new dev commit (nothing goes live until a release).
	 * It goes through save_dev() like any save: an untrusted version stays untrusted and is filtered again, and
	 * JavaScript still needs unfiltered_html.
	 *
	 * @return array{id: string, doc: string, branch: string, parent: ?string, base: ?string, payload: array<string, mixed>, hash: string, author: int, trusted: bool, message: string, created: string}
	 */
	public function revert( string $doc, string $commit_id ): array {
		$commit = $this->store->get( $commit_id );
		if ( null === $commit || $commit['doc'] !== $doc ) {
			throw new VersioningException( __( 'Versione inesistente per questo documento.', 'xb' ), 404 );
		}
		$copy   = $this->working_copy( $doc );
		$result = $this->save_dev(
			$doc,
			$commit['payload'],
			$copy['head'],
			$copy['base'],
			/* translators: %s: date and time of the restored version */
			sprintf( __( 'Ripristinata la versione del %s', 'xb' ), get_date_from_gmt( $commit['created'], 'd/m/Y H:i' ) ),
			false,
			! $commit['trusted']
		);
		$this->store->audit( 'revert', $doc, array( 'from' => $commit_id, 'commit' => $result['id'] ) );
		return $result;
	}

	/**
	 * Documents whose dev head differs from prod.
	 *
	 * @return list<array{doc: string, label: string, sent: bool}>
	 */
	public function changes(): array {
		$out     = array();
		$changed = array();
		foreach ( $this->store->heads() as $head ) {
			if ( $head['dev'] === $head['prod'] ) {
				continue;
			}
			// Two commits can hold the same document: a change made on the live site and then undone there (an
			// export to Etch and its restore) leaves prod with a new commit equal to dev. Nothing to publish. Equal
			// fingerprints are checked on the payloads (the fingerprint leaves out the host of URLs); different ones
			// mean a different document. The database compares the bytes (voce 179: no document read into PHP for
			// it); only two payloads with the same fingerprint and different bytes are read, to compare what they hold.
			if ( null !== $head['prod'] && $head['dev_hash'] === $head['prod_hash'] && ( true === $head['same_bytes'] || ( false === $head['same_bytes'] && $this->same_payload( $head['dev'], $head['prod'] ) ) ) ) {
				continue;
			}
			$changed[] = $head;
		}
		// The titles of the pages, in one query instead of one per page.
		$ids = array_filter( array_map( static fn ( array $h ): int => (int) Documents::post_id( $h['doc'] ), $changed ) );
		if ( array() !== $ids ) {
			_prime_post_caches( array_values( $ids ), false, false );
		}
		foreach ( $changed as $head ) {
			$out[] = array(
				'doc'   => $head['doc'],
				'label' => Documents::label( $head['doc'] ),
				'sent'  => PageProfileAdapter::is_send( $head['message'] ),
			);
		}
		// Media changes from Histeon CMS waiting for a release (M6): not documents, but a release can be made for
		// them alone.
		if ( Documents::waiting_media() > 0 ) {
			$out[] = array(
				'doc'   => Documents::MEDIA,
				'label' => Documents::label( Documents::MEDIA ),
				'sent'  => true,
			);
		}
		// Likewise the records, terms and products a development send queued: a send that changed only them (a new
		// price) has no document to publish, and still needs a release to go online.
		if ( Documents::waiting_contents() > 0 ) {
			$out[] = array(
				'doc'   => Documents::CONTENTS,
				'label' => Documents::label( Documents::CONTENTS ),
				'sent'  => true,
			);
		}
		return $out;
	}

	/**
	 * Publishes dev → prod for $docs (default: every changed document) as release $version.
	 *
	 * $checks: the outcome of the page checks the release goes out with, as the builder's Publish window sends it.
	 * Kept with the release in the audit log and as ReleaseChecks::OPTION only when ReleaseChecks::clean() accepts
	 * it; a reminder, never a condition of publishing. Without one (wp-admin, a file, Histeon CMS) nothing is
	 * written and the last outcome stays as it was.
	 *
	 * @param list<string>|null $docs
	 * @return array{id: int, version: string, docs: list<string>, merged: int, conflicts: int}
	 */
	public function publish( ?string $version, string $notes, ?array $docs = null, mixed $checks = null ): array {
		if ( ! current_user_can( 'xb_publish' ) ) {
			throw new VersioningException( __( 'Serve il permesso xb_publish per pubblicare.', 'xb' ), 403 );
		}
		$version   = $this->next_version( $version );
		$selection = $this->selection( $docs );
		$docs      = $selection['docs'];
		if ( array() === $docs && ! $selection['media'] && ! $selection['contents'] ) {
			throw new VersioningException( __( 'Non ci sono modifiche da pubblicare.', 'xb' ), 400 );
		}

		$this->store->assert_transactional();
		$merged    = 0;
		$conflicts = 0;
		$result    = $this->atomically(
			$docs,
			function () use ( $docs, $version, $notes, &$merged, &$conflicts ): int {
				foreach ( $docs as $doc ) {
					$dev_id  = (string) $this->store->head( $doc, Store::DEV );
					$prod_id = (string) $this->store->head( $doc, Store::PROD );
					$dev     = $this->must( $dev_id );
					$payload = $dev['payload'];
					$base_id = $dev['base'] ?? $dev_id;
					if ( $base_id !== $prod_id && is_string( $payload['content'] ?? null ) ) {
						$merge              = $this->merge_content( $payload, $base_id, $prod_id );
						$payload['content'] = $merge['content'];
						$merged            += $merge['merged'];
						$conflicts         += count( $merge['conflicts'] );
					}
					Documents::apply( $doc, $payload, $dev['trusted'] );
					$live   = Documents::read( $doc );
					$commit = $this->store->commit( $doc, Store::PROD, $live, $prod_id, null, sprintf( /* translators: %s: release version */ __( 'Release %s', 'xb' ), $version ) );
					$this->store->move( $doc, Store::PROD, $commit['id'] );
					// After publishing, dev continues from what is live.
					$this->store->move( $doc, Store::DEV, $commit['id'] );
				}
				return $this->record_release( $version, $notes, null );
			}
		);
		$checks = ReleaseChecks::clean( $checks );
		$this->after_release( $result, $docs, 'publish', $checks );
		if ( null !== $checks ) {
			ReleaseChecks::remember( $version, $checks );
		}
		return array(
			'id'        => $result,
			'version'   => $version,
			'docs'      => $docs,
			'merged'    => $merged,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * What a release of $docs (null: every change) would publish: the changed documents among them, plus the new
	 * components they use, and whether waiting media and queued contents were chosen. publish() and the scheduled
	 * release (Scheduled) use the same choice.
	 *
	 * @param list<string>|null $docs
	 * @return array{docs: list<string>, media: bool, contents: bool}
	 */
	public function selection( ?array $docs ): array {
		$changed = array_column( $this->changes(), 'doc' );
		$wanted  = null === $docs ? $changed : array_values( array_intersect( $docs, $changed ) );
		// Waiting media and queued contents are no document: any release puts them online (apply_pending_media,
		// ContentProfileAdapter::apply_queue), and they may be all a release is made for (M6). Only media whose send is
		// complete are counted (changes()).
		$out = array_values( array_diff( $wanted, array( Documents::MEDIA, Documents::CONTENTS ) ) );
		if ( null !== $docs && array() !== $out ) {
			$out = $this->with_new_components( $out, $changed );
		}
		return array(
			'docs'     => $out,
			'media'    => in_array( Documents::MEDIA, $wanted, true ),
			'contents' => in_array( Documents::CONTENTS, $wanted, true ),
		);
	}

	/**
	 * How many texts the client changed on the live site that $docs change too: the conflicts publish() would
	 * resolve for the builder (§4). Computed without writing anything (the scheduled release, §11.4 b).
	 *
	 * @param list<string> $docs
	 */
	public function conflicts( array $docs ): int {
		$count = 0;
		foreach ( $docs as $doc ) {
			$dev_id  = $this->store->head( $doc, Store::DEV );
			$prod_id = $this->store->head( $doc, Store::PROD );
			if ( null === $dev_id || null === $prod_id ) {
				continue;
			}
			$dev     = $this->must( $dev_id );
			$base_id = $dev['base'] ?? $dev_id;
			if ( $base_id !== $prod_id && is_string( $dev['payload']['content'] ?? null ) ) {
				$count += count( $this->merge_content( $dev['payload'], $base_id, $prod_id )['conflicts'] );
			}
		}
		return $count;
	}

	/**
	 * The documents to release plus the components they use that were never live (created in development, so
	 * their live post is still empty): released alone, a page would show them empty. Followed through components
	 * that use other new components.
	 *
	 * @param list<string> $docs
	 * @param list<string> $changed
	 * @return list<string>
	 */
	private function with_new_components( array $docs, array $changed ): array {
		$out   = array_fill_keys( $docs, true );
		$queue = $docs;
		while ( array() !== $queue ) {
			$dev     = $this->store->get( (string) $this->store->head( (string) array_shift( $queue ), Store::DEV ) );
			$content = $dev['payload']['content'] ?? null;
			if ( ! is_string( $content ) ) {
				continue;
			}
			foreach ( \XB\Render\ComponentRegistry::refs_in( parse_blocks( $content ) ) as $ref ) {
				$owner = \XB\Render\ComponentRegistry::owner( $ref );
				$doc   = null === $owner || 'wp_block' !== get_post_type( $owner ) ? null : Documents::for_post( $owner );
				if ( null === $doc || isset( $out[ $doc ] ) || ! in_array( $doc, $changed, true ) ) {
					continue;
				}
				$prod = $this->store->get( (string) $this->store->head( $doc, Store::PROD ) );
				if ( '' === trim( (string) ( $prod['payload']['content'] ?? '' ) ) ) {
					$out[ $doc ] = true;
					$queue[]     = $doc;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Restores release $id as a new release. With $keep_content, texts edited since then are kept.
	 *
	 * @return array{id: int, version: string, docs: list<string>}
	 */
	public function restore( int $id, bool $keep_content = true, ?string $version = null ): array {
		if ( ! current_user_can( 'xb_publish' ) ) {
			throw new VersioningException( __( 'Serve il permesso xb_publish per ripristinare.', 'xb' ), 403 );
		}
		$release = $this->store->get_release( $id );
		if ( null === $release ) {
			throw new VersioningException( __( 'Release inesistente.', 'xb' ), 404 );
		}
		if ( ! hash_equals( $release['hash'], hash( 'sha256', Store::encode( $release['items'] ) ) ) ) {
			throw new VersioningException( __( 'La release è stata alterata: ripristino rifiutato.', 'xb' ), 409 );
		}
		$version = $this->next_version( $version );
		$docs    = array();
		$new_id  = $this->atomically(
			array_keys( $release['items'] ),
			function () use ( $release, $keep_content, $version, &$docs ): int {
				foreach ( $release['items'] as $doc => $commit_id ) {
					if ( ! Documents::valid( $doc ) ) {
						continue; // The post was deleted since: nothing to restore it into.
					}
					$payload = $this->must( $commit_id )['payload'];
					$prod_id = (string) $this->store->head( $doc, Store::PROD );
					if ( $keep_content && is_string( $payload['content'] ?? null ) ) {
						$current            = $this->must( $prod_id )['payload'];
						$payload['content'] = \XB\Blocks::serialize( Merge::keep_texts( parse_blocks( $payload['content'] ), parse_blocks( (string) ( $current['content'] ?? '' ) ) ) );
					}
					Documents::apply( $doc, $payload, $this->must( $commit_id )['trusted'] );
					$commit = $this->store->commit( $doc, Store::PROD, Documents::read( $doc ), $prod_id, null, sprintf( /* translators: %s: release version */ __( 'Ripristino della release %s', 'xb' ), $release['version'] ) );
					$this->store->move( $doc, Store::PROD, $commit['id'] );
					$this->store->move( $doc, Store::DEV, $commit['id'] );
					$docs[] = $doc;
				}
				return $this->record_release( $version, sprintf( /* translators: %s: release version */ __( 'Ripristino della release %s', 'xb' ), $release['version'] ), $release['id'] );
			}
		);
		$this->after_release( $new_id, $docs, 'restore' );
		return array(
			'id'      => $new_id,
			'version' => $version,
			'docs'    => $docs,
		);
	}

	private function same_payload( ?string $a, ?string $b ): bool {
		if ( null === $a || null === $b ) {
			return false;
		}
		$first  = $this->store->get( $a );
		$second = $this->store->get( $b );
		return null !== $first && null !== $second && $first['payload'] === $second['payload'];
	}

	/** Records a client edit (Gutenberg, REST, WP-CLI) of a tracked document as a prod commit. */
	public function record_live_edit( int $post_id ): void {
		$this->record_live( Documents::for_post( $post_id ) );
	}

	/** Records a change made to the live site outside a release (e.g. settings saved directly). */
	public function record_live( string $doc ): void {
		if ( Documents::applying() || null === $this->store->head( $doc, Store::PROD ) ) {
			return;
		}
		$live = Documents::read( $doc );
		$prod = (string) $this->store->head( $doc, Store::PROD );
		if ( $this->must( $prod )['payload'] === $live ) {
			return;
		}
		$this->store->transaction(
			function () use ( $doc, $live, $prod ): void {
				$commit = $this->store->commit( $doc, Store::PROD, $live, $prod, null, __( 'Modifica dei contenuti fuori dal builder', 'xb' ) );
				$this->store->move( $doc, Store::PROD, $commit['id'] );
			}
		);
	}

	public function next_version( ?string $requested ): string {
		if ( null !== $requested && '' !== $requested ) {
			if ( 1 !== preg_match( '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $requested ) ) {
				throw new VersioningException( __( 'Versione non valida: usa il formato 1.2.3.', 'xb' ), 400 );
			}
			if ( $this->store->version_exists( $requested ) ) {
				throw new VersioningException( __( 'Esiste già una release con questa versione.', 'xb' ), 409 );
			}
			// The version of the scheduled release is reserved until it goes out or is cancelled (§11.4 d).
			if ( Scheduled::reserved() === $requested ) {
				throw new VersioningException( __( 'Questa versione è riservata alla release programmata.', 'xb' ), 409 );
			}
			return $requested;
		}
		$reserved = Scheduled::reserved();
		$taken    = fn ( string $v ): bool => $v === $reserved || $this->store->version_exists( $v );
		$latest   = $this->store->latest_version();
		if ( null === $latest || 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $latest, $m ) ) {
			return $taken( '1.0.0' ) ? '1.1.0' : '1.0.0';
		}
		$minor = (int) $m[2] + 1;
		while ( $taken( sprintf( '%d.%d.0', (int) $m[1], $minor ) ) ) {
			++$minor;
		}
		return sprintf( '%d.%d.0', (int) $m[1], $minor );
	}

	// ------------------------------------------------------------------ internals

	/**
	 * Runs a release in one transaction. If it fails, the database rolls back, and the in-memory and
	 * persistent object caches are cleared for everything the release touched, so no stale copy of the
	 * aborted change survives.
	 *
	 * @param list<string>   $docs
	 * @param callable(): int $fn
	 */
	private function atomically( array $docs, callable $fn ): int {
		try {
			return (int) $this->store->locked( 'release', fn (): mixed => $this->store->transaction( $fn ) );
		} catch ( \Throwable $e ) {
			foreach ( $docs as $doc ) {
				$post_id = Documents::post_id( $doc );
				if ( null !== $post_id ) {
					clean_post_cache( $post_id );
					wp_cache_delete( $post_id, 'post_meta' );
				}
			}
			wp_cache_delete( 'alloptions', 'options' );
			foreach ( array( \XB\Styles\StyleRegistry::OPTION_STYLES, \XB\Styles\StyleRegistry::OPTION_GLOBAL, \XB\Framework\Frameworks::OPTION, self::OPTION_CURRENT ) as $option ) {
				wp_cache_delete( $option, 'options' );
			}
			Plugin::instance()->components->forget();
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{content: string, merged: int, conflicts: list<array{uid: string, dev: string, prod: string}>}
	 */
	private function merge_content( array $payload, string $base_id, string $prod_id ): array {
		$base = $this->store->get( $base_id );
		$prod = $this->must( $prod_id );
		// Nothing of its own in development: the newer live version is the working copy, new blocks included.
		if ( ( $base['payload']['content'] ?? null ) === $payload['content'] && is_string( $prod['payload']['content'] ?? null ) ) {
			return array(
				// In the form Histeon writes: Gutenberg saves a blank line between blocks.
				'content'   => \XB\Blocks::serialize( array_filter( parse_blocks( $prod['payload']['content'] ), static fn ( array $block ): bool => null !== $block['blockName'] || '' !== trim( (string) $block['innerHTML'] ) ) ),
				'merged'    => 0,
				'conflicts' => array(),
			);
		}
		$result = Merge::three_way(
			parse_blocks( (string) $payload['content'] ),
			parse_blocks( (string) ( $base['payload']['content'] ?? '' ) ),
			parse_blocks( (string) ( $prod['payload']['content'] ?? '' ) )
		);
		return array(
			'content'   => \XB\Blocks::serialize( $result['blocks'] ),
			'merged'    => $result['merged'],
			'conflicts' => $result['conflicts'],
		);
	}

	private function record_release( string $version, string $notes, ?int $restored_from ): int {
		$items = array();
		foreach ( $this->store->docs( Store::PROD ) as $doc ) {
			$items[ $doc ] = (string) $this->store->head( $doc, Store::PROD );
		}
		$id = $this->store->release( $version, $notes, $items, $restored_from );
		update_option( self::OPTION_CURRENT, $id, false );
		return $id;
	}

	/**
	 * @param list<string>                                                      $docs
	 * @param array{errors: int, warnings: int, rules: array<string, int>}|null $checks
	 */
	private function after_release( int $id, array $docs, string $action, ?array $checks = null ): void {
		$this->store->audit( $action, 'release:' . $id, null === $checks ? array( 'docs' => $docs ) : array( 'docs' => $docs, 'checks' => $checks ) );
		// A release by hand, from Histeon Publish or a restore that touches the scheduled one cancels it (§11.4 d, g).
		Scheduled::superseded( $id, $docs, $action );
		/**
		 * A release was published or restored and is now live.
		 *
		 * @param int          $id   Release id.
		 * @param list<string> $docs Documents that changed (post:{id}, styles, settings).
		 */
		do_action( 'xb_release_published', $id, $docs );
	}

	/** @return array{id: string, doc: string, branch: string, parent: ?string, base: ?string, payload: array<string, mixed>, hash: string, author: int, trusted: bool, message: string, created: string} */
	private function must( string $id ): array {
		$commit = $this->store->get( $id );
		if ( null === $commit ) {
			throw new VersioningException( sprintf( /* translators: %s: commit id */ __( 'Commit mancante: %s', 'xb' ), $id ) );
		}
		return $commit;
	}
}
