<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Templates\Templates;

/**
 * Release export and import (`.xbrelease`) — docs/spec/versioning.md §8.
 *
 * Export: the documents of a published release, keyed by what identifies them on any site (post type + slug,
 * component key, template slug), plus styles and settings, with a SHA-256 of the content for integrity.
 * Import: strict validation, the source site's address rewritten to this one, documents found (or created)
 * here, everything written as dev commits with the importing user's trust (threat model T12). Nothing goes
 * live until a release is published.
 */
final class Transfer {

	public const FORMAT     = 'xb-release';
	public const VERSION    = 1;
	public const MAX_BYTES  = 20 * 1024 * 1024;
	private const MAX_DOCS  = 2000;
	private const MAX_FIELD = 4 * 1024 * 1024;
	private const POST_TYPES = array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part' );

	public function __construct( private readonly Versioning $versioning ) {}

	public static function instance(): self {
		return new self( Plugin::instance()->versioning );
	}

	/**
	 * The key of a page or a template in the file: its slug. A draft has none yet, and a file with an empty key
	 * could not be imported anywhere: the slug WP would give it from its title, or `<type>-<id>`.
	 */
	private static function key_of( \WP_Post $post ): string {
		if ( '' !== $post->post_name ) {
			return $post->post_name;
		}
		$slug = sanitize_title( $post->post_title );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_\/-]{0,199}$/', $slug ) ? $slug : $post->post_type . '-' . $post->ID;
	}

	/** @return array<string, mixed> */
	public function export( int $release_id ): array {
		$store   = $this->versioning->store();
		$release = $store->get_release( $release_id );
		if ( null === $release ) {
			throw new VersioningException( __( 'Versione inesistente.', 'xb' ), 404 );
		}
		$documents = array();
		foreach ( $release['items'] as $doc => $commit_id ) {
			$commit = $store->get( $commit_id );
			if ( null === $commit ) {
				continue;
			}
			$payload = $commit['payload'];
			if ( Documents::STYLES === $doc || Documents::SETTINGS === $doc ) {
				$documents[] = array(
					'kind'    => $doc,
					'payload' => $payload,
				);
				continue;
			}
			$post = get_post( (int) Documents::post_id( $doc ) );
			if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
				continue;
			}
			$documents[] = array(
				'kind'    => 'post',
				'type'    => $post->post_type,
				'key'     => 'wp_block' === $post->post_type ? (string) ( $payload['ref'] ?? '' ) : self::key_of( $post ),
				'title'   => $post->post_title,
				'payload' => $payload,
			);
		}
		// Signed with the site's key (P8, docs/spec/signing.md): the importing site knows where it comes from.
		return Signing::sign(
			array(
				'format'    => self::FORMAT,
				'version'   => self::VERSION,
				'generator' => 'Histeon ' . XB_VERSION,
				'site'      => home_url( '/' ),
				'created'   => gmdate( 'c' ),
				'release'   => array(
					'version' => $release['version'],
					'notes'   => $release['notes'],
				),
				'documents' => $documents,
				'hash'      => self::hash( $documents ),
			)
		);
	}

	/** @param array<mixed> $documents */
	private static function hash( array $documents ): string {
		return hash( 'sha256', (string) wp_json_encode( $documents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Dry run (write false) or dev commits (write true). The signature (P8) is checked in both; writing follows the
	 * rules of docs/spec/signing.md: `$options['trust']` trusts a new key, `$options['allowUnsigned']` accepts a file
	 * without a signature, `$options['verified']` says the caller already knows the source (a site transfer, the
	 * staging link).
	 *
	 * @param mixed                $data    Decoded `.xbrelease`.
	 * @param array<string, mixed> $options
	 * @return array{release: string, site: string, documents: list<array{label: string, target: string, action: string}>, issues: list<string>, written: bool, signature: array{status: string, fingerprint: string, site: string}}
	 */
	public function import( mixed $data, bool $write, array $options = array() ): array {
		$file   = self::validate( $data );
		$check  = empty( $options['verified'] ) && is_array( $data ) ? Signing::check( $data ) : null;
		if ( $write && null !== $check ) {
			Signing::accept( $check, $options );
		}
		$issues = array();
		$rows   = array();
		$from   = untrailingslashit( $file['site'] );
		$to     = untrailingslashit( home_url( '/' ) );
		foreach ( $file['documents'] as $document ) {
			$payload = $from !== $to ? self::rewrite( $document['payload'], $from, $to ) : $document['payload'];
			if ( 'post' !== $document['kind'] ) {
				$rows[] = $this->import_global( $document['kind'], $payload, $write, $issues );
				continue;
			}
			$rows[] = $this->import_post( $document, $payload, $write, $issues );
		}
		if ( $write ) {
			$this->versioning->store()->audit( 'import_release', 'file', array( 'release' => $file['release']['version'], 'site' => $file['site'], 'docs' => count( $rows ) ) );
		}
		return array(
			'release'   => $file['release']['version'],
			'site'      => $file['site'],
			'documents' => $rows,
			'issues'    => $issues,
			'written'   => $write,
			'signature' => null === $check ? array( 'status' => 'verified', 'fingerprint' => '', 'site' => '' ) : array_intersect_key( $check, array_flip( array( 'status', 'fingerprint', 'site' ) ) ),
		);
	}

	/**
	 * Strict shape check: anything unexpected refuses the whole file.
	 *
	 * @return array{site: string, release: array{version: string, notes: string}, documents: list<array{kind: string, type?: string, key?: string, title?: string, payload: array<string, mixed>}>}
	 */
	public static function validate( mixed $data ): array {
		$fail = static fn ( string $why ): \InvalidArgumentException => new \InvalidArgumentException( sprintf( __( 'File di release non valido: %s.', 'xb' ), $why ) );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			throw $fail( __( 'formato sconosciuto', 'xb' ) );
		}
		if ( self::VERSION !== ( $data['version'] ?? null ) ) {
			throw $fail( __( 'versione del formato non supportata', 'xb' ) );
		}
		$documents = $data['documents'] ?? null;
		if ( ! is_array( $documents ) || ! array_is_list( $documents ) || count( $documents ) > self::MAX_DOCS ) {
			throw $fail( __( 'elenco dei documenti', 'xb' ) );
		}
		if ( ! is_string( $data['hash'] ?? null ) || ! hash_equals( self::hash( $documents ), $data['hash'] ) ) {
			throw $fail( __( 'contenuto alterato o incompleto (hash)', 'xb' ) );
		}
		$site = is_string( $data['site'] ?? null ) ? $data['site'] : '';
		if ( ! in_array( wp_parse_url( $site, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			throw $fail( __( 'sito di origine', 'xb' ) );
		}
		$release = is_array( $data['release'] ?? null ) ? $data['release'] : array();
		$clean   = array();
		foreach ( $documents as $i => $document ) {
			$kind = is_array( $document ) ? ( $document['kind'] ?? null ) : null;
			if ( ! is_array( $document ) || ! in_array( $kind, array( 'post', Documents::STYLES, Documents::SETTINGS ), true ) || ! is_array( $document['payload'] ?? null ) ) {
				throw $fail( sprintf( /* translators: %d: position of the document in the file */ __( 'documento %d', 'xb' ), $i ) );
			}
			foreach ( $document['payload'] as $field => $value ) {
				if ( ! is_string( $field ) || ( is_string( $value ) && strlen( $value ) > self::MAX_FIELD ) ) {
					throw $fail( sprintf( /* translators: %d: position of the document in the file */ __( 'documento %d, campo troppo grande', 'xb' ), $i ) );
				}
			}
			if ( 'post' === $kind ) {
				if ( ! in_array( $document['type'] ?? null, self::POST_TYPES, true ) || ! is_string( $document['key'] ?? null ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_\/-]{0,199}$/', $document['key'] ) || ! is_string( $document['payload']['content'] ?? null ) ) {
					throw $fail( sprintf( /* translators: %d: position of the document in the file */ __( 'documento %d (tipo, chiave o contenuto)', 'xb' ), $i ) );
				}
			}
			$clean[] = array(
				'kind'    => (string) $kind,
				'type'    => is_string( $document['type'] ?? null ) ? $document['type'] : '',
				'key'     => is_string( $document['key'] ?? null ) ? $document['key'] : '',
				'title'   => is_string( $document['title'] ?? null ) ? mb_substr( $document['title'], 0, 200 ) : '',
				'payload' => $document['payload'],
			);
		}
		return array(
			'site'      => $site,
			'release'   => array(
				'version' => is_string( $release['version'] ?? null ) ? mb_substr( $release['version'], 0, 20 ) : '',
				'notes'   => is_string( $release['notes'] ?? null ) ? mb_substr( $release['notes'], 0, 2000 ) : '',
			),
			'documents' => $clean,
		);
	}

	/**
	 * The source site's address becomes this one, in every string (plain and JSON-escaped).
	 *
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function rewrite( array $value, string $from, string $to ): array {
		$pairs = array(
			$from                        => $to,
			str_replace( '/', '\\/', $from ) => str_replace( '/', '\\/', $to ),
		);
		array_walk_recursive(
			$value,
			static function ( mixed &$item ) use ( $pairs ): void {
				if ( is_string( $item ) ) {
					$item = strtr( $item, $pairs );
				}
			}
		);
		return $value;
	}

	/**
	 * @param array{kind: string, type?: string, key?: string, title?: string, payload: array<string, mixed>} $document
	 * @param array<string, mixed>                                                                            $payload
	 * @param list<string>                                                                                    $issues
	 * @return array{label: string, target: string, action: string}
	 */
	private function import_post( array $document, array $payload, bool $write, array &$issues ): array {
		$type   = (string) ( $document['type'] ?? '' );
		$key    = (string) ( $document['key'] ?? '' );
		$label  = sprintf( /* translators: 1: post type, 2: title or key */ __( '%s «%s»', 'xb' ), $type, '' !== ( $document['title'] ?? '' ) ? $document['title'] : $key );
		$target = $this->find( $type, $key );
		$action = null === $target ? 'crea' : 'aggiorna';
		if ( null !== $target && ! current_user_can( 'edit_post', $target ) ) {
			$issues[] = sprintf( /* translators: %s: document label */ __( 'Non puoi modificare %s: saltato', 'xb' ), $label );
			return array( 'label' => $label, 'target' => 'post:' . $target, 'action' => 'saltato' );
		}
		if ( null === $target && ! current_user_can( 'wp_template' === $type || 'wp_template_part' === $type ? 'edit_theme_options' : ( get_post_type_object( $type )?->cap->create_posts ?? 'do_not_allow' ) ) ) {
			$issues[] = sprintf( /* translators: %s: document label */ __( 'Non puoi creare %s: saltato', 'xb' ), $label );
			return array( 'label' => $label, 'target' => '', 'action' => 'saltato' );
		}
		if ( ! $write ) {
			return array( 'label' => $label, 'target' => null === $target ? '' : 'post:' . $target, 'action' => $action );
		}
		$target ??= $this->create( $type, $key, (string) ( $document['title'] ?? $key ), $issues );
		if ( null === $target ) {
			return array( 'label' => $label, 'target' => '', 'action' => 'errore' );
		}
		$doc  = Documents::for_post( $target );
		$copy = $this->versioning->working_copy( $doc );
		$next = array_intersect_key( $payload, array_flip( array( 'content', 'script', 'ref', 'schema', \XB\Styles\PageLook::KEY ) ) ) + $copy['payload'];
		if ( 'wp_block' !== $type ) {
			unset( $next['ref'], $next['schema'] );
		}
		if ( array_key_exists( \XB\Styles\PageLook::KEY, $next ) ) {
			// The page's own look (voce 186): the closed grammar, or nothing of it.
			try {
				$next[ \XB\Styles\PageLook::KEY ] = \XB\Styles\PageLook::validate( $next[ \XB\Styles\PageLook::KEY ] );
			} catch ( \InvalidArgumentException $e ) {
				$issues[] = sprintf( '%s: %s', $label, $e->getMessage() );
				unset( $next[ \XB\Styles\PageLook::KEY ] );
			}
			if ( array() === ( $next[ \XB\Styles\PageLook::KEY ] ?? null ) || ! in_array( $type, \XB\Styles\PageLook::TYPES, true ) ) {
				unset( $next[ \XB\Styles\PageLook::KEY ] );
			}
		}
		try {
			$this->versioning->save_dev( $doc, $next, $copy['head'], $copy['base'], sprintf( __( 'Importazione della release %s', 'xb' ), $key ) );
		} catch ( VersioningException $e ) {
			$issues[] = sprintf( '%s: %s', $label, $e->getMessage() );
			return array( 'label' => $label, 'target' => $doc, 'action' => 'errore' );
		}
		return array( 'label' => $label, 'target' => $doc, 'action' => $action );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param list<string>         $issues
	 * @return array{label: string, target: string, action: string}
	 */
	private function import_global( string $doc, array $payload, bool $write, array &$issues ): array {
		$label = Documents::label( $doc );
		$copy  = $this->versioning->working_copy( $doc );
		if ( Documents::SETTINGS === $doc ) {
			$next = $copy['payload'];
			if ( is_string( $payload['framework'] ?? null ) ) {
				$provider = Plugin::instance()->frameworks->all()[ $payload['framework'] ] ?? null;
				if ( null !== $provider && $provider->available() ) {
					$next['framework'] = $payload['framework'];
				} else {
					$issues[] = sprintf( /* translators: %s: framework name */ __( 'Framework "%s" non disponibile qui: resta quello attuale', 'xb' ), $payload['framework'] );
				}
			}
			if ( array_key_exists( 'reset', $payload ) ) {
				$next['reset'] = (bool) $payload['reset'];
			}
			if ( array_key_exists( 'presets', $payload ) ) {
				try {
					$next['presets'] = \XB\Render\Queries::validate( $payload['presets'] );
				} catch ( \InvalidArgumentException $e ) {
					$issues[] = sprintf( /* translators: %s: error message */ __( 'Query non importate: %s', 'xb' ), $e->getMessage() );
				}
			}
			if ( array_key_exists( 'loopTypes', $payload ) ) {
				$next['loopTypes'] = \XB\Render\Queries::validate_types( $payload['loopTypes'] );
			}
			if ( array_key_exists( 'loopTaxonomies', $payload ) ) {
				$next['loopTaxonomies'] = \XB\Render\Queries::validate_taxonomies( $payload['loopTaxonomies'] );
			}
			if ( array_key_exists( 'queries', $payload ) ) {
				try {
					$next['queries'] = \XB\Styles\NamedQueries::validate( $payload['queries'] );
				} catch ( \InvalidArgumentException $e ) {
					$issues[] = sprintf( /* translators: %s: error message */ __( 'Media query con nome non importate: %s', 'xb' ), $e->getMessage() );
				}
			}
			if ( array_key_exists( 'facets', $payload ) ) {
				try {
					$next['facets'] = \XB\Render\Facets::validate( $payload['facets'], array_map( 'strval', array_keys( is_array( $next['presets'] ?? null ) ? $next['presets'] : array() ) ) );
				} catch ( \InvalidArgumentException $e ) {
					$issues[] = sprintf( /* translators: %s: error message */ __( 'Filtri non importati: %s', 'xb' ), $e->getMessage() );
				}
			}
			$taken = array_map( 'strval', array_keys( is_array( $next['presets'] ?? null ) ? $next['presets'] : array() ) );
			$keep  = array(
				'sources' => array( __( 'Sorgenti dati', 'xb' ), static fn ( mixed $v ): array => \XB\Render\Sources::validate( $v, $taken ), false ),
				'tokens'  => array( __( 'Token del design system', 'xb' ), static fn ( mixed $v ): array => \XB\Framework\TokenOverrides::validate( $v ), false ),
				'design'  => array( __( 'Parametri di Histeon CSS', 'xb' ), static fn ( mixed $v ): array => \XB\Framework\HistosCss\Design::changes( \XB\Framework\HistosCss\Design::validate( $v ) ), false ),
				// Content types and public fields change what WordPress registers: administrators only, as on publish.
				'content'      => array( __( 'Tipi di contenuto', 'xb' ), static fn ( mixed $v ): array => \XB\Content\ContentTypes::validate( $v ), true ),
				'publicFields' => array( __( 'Campi pubblici', 'xb' ), static fn ( mixed $v ): array => \XB\Fields\Fields::validate( $v ), true ),
			);
			foreach ( $keep as $key => [ $what, $validate, $admin ] ) {
				if ( ! array_key_exists( $key, $payload ) ) {
					continue;
				}
				if ( $admin && ! current_user_can( 'manage_options' ) ) {
					$issues[] = sprintf( /* translators: %s: what was not imported, e.g. "Content types" */ __( '%s: solo un amministratore li importa, restano quelli attuali', 'xb' ), $what );
					continue;
				}
				try {
					$next[ $key ] = $validate( $payload[ $key ] );
				} catch ( \InvalidArgumentException $e ) {
					$issues[] = sprintf( /* translators: 1: what was not imported, e.g. "Data sources", 2: error message */ __( '%s non importati: %s', 'xb' ), $what, $e->getMessage() );
				}
			}
		} else {
			$next = array(
				'styles'  => is_array( $payload['styles'] ?? null ) ? array_values( $payload['styles'] ) : array(),
				'globals' => is_array( $payload['globals'] ?? null ) ? array_values( $payload['globals'] ) : array(),
			);
		}
		if ( $write ) {
			try {
				$this->versioning->save_dev( $doc, $next, $copy['head'], $copy['base'], __( 'Importazione di una release', 'xb' ) );
			} catch ( VersioningException $e ) {
				$issues[] = sprintf( '%s: %s', $label, $e->getMessage() );
				return array( 'label' => $label, 'target' => $doc, 'action' => 'errore' );
			}
		}
		return array( 'label' => $label, 'target' => $doc, 'action' => 'aggiorna' );
	}

	/** The post on this site for a key: slug (pages, posts), component key, template of the active theme. */
	private function find( string $type, string $key ): ?int {
		if ( 'wp_block' === $type ) {
			return ComponentRegistry::owner( $key );
		}
		if ( 'wp_template' === $type || 'wp_template_part' === $type ) {
			$template = get_block_template( get_stylesheet() . '//' . $key, $type );
			return $template instanceof \WP_Block_Template && ! empty( $template->wp_id ) ? (int) $template->wp_id : null;
		}
		$found = get_posts(
			array(
				'post_type'   => $type,
				'name'        => $key,
				'post_status' => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		return $found ? (int) $found[0] : null;
	}

	/**
	 * A post the release needs and this site lacks. New pages and posts are drafts: they go live when someone
	 * publishes them; components are published (they render inside pages); templates come from the theme file
	 * when there is one.
	 *
	 * @param list<string> $issues
	 */
	private function create( string $type, string $key, string $title, array &$issues ): ?int {
		if ( 'wp_template' === $type || 'wp_template_part' === $type ) {
			try {
				return Templates::materialize( $type, get_stylesheet() . '//' . $key );
			} catch ( \RuntimeException ) {
				$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_name' => $key, 'post_title' => $title ), true );
				if ( ! is_wp_error( $id ) ) {
					wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
					return (int) $id;
				}
				$issues[] = sprintf( /* translators: 1: template key, 2: error message */ __( 'Template %s non creato: %s', 'xb' ), $key, $id->get_error_message() );
				return null;
			}
		}
		$id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'wp_block' === $type ? 'publish' : 'draft',
				'post_name'   => 'wp_block' === $type ? sanitize_title( $key ) : $key,
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$issues[] = sprintf( /* translators: 1: post type, 2: key, 3: error message */ __( '%s %s non creato: %s', 'xb' ), $type, $key, $id->get_error_message() );
			return null;
		}
		if ( 'wp_block' === $type ) {
			// Keyed at once, so a second import before the release finds it instead of creating another.
			update_post_meta( (int) $id, ComponentRegistry::META_KEY, $key );
			Plugin::instance()->components->forget();
		} else {
			$issues[] = sprintf( /* translators: 1: "Page" or "Post", 2: title */ __( '%s «%s» creata come bozza: pubblicala quando la release è online', 'xb' ), 'page' === $type ? __( 'Pagina', 'xb' ) : __( 'Articolo', 'xb' ), $title );
		}
		return (int) $id;
	}
}
