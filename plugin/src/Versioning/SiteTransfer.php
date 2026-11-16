<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;
use XB\Render\ComponentRegistry;

/**
 * The whole site in one file (`.xbsite`), to move it to another installation — docs/spec/site-transfer.md.
 *
 * A ZIP with `site.json` (documents, styles, settings, the data of every content) and `media/` (the files the
 * contents use). Export reads what visitors see (the live version). Import is a dry run first, then: media through
 * WordPress's own sideload (its file type checks), Histeon documents through the release import (Transfer: dev
 * commits with the importer's trust), other contents through wp_insert_post (WordPress's filters for the importer's
 * role). Nothing goes live unless an administrator chooses «Importa e pubblica».
 */
final class SiteTransfer {

	public const FORMAT  = 'xb-site';
	public const VERSION = 1;
	/** Extension of the file people move around. */
	public const EXTENSION = 'xbsite';

	private const MAX_ENTRIES  = 20000;
	private const MAX_MANIFEST = 64 * 1024 * 1024;
	private const MAX_MEDIA    = 256 * 1024 * 1024;
	private const MAX_POSTS    = 5000;
	/** Post meta: the site a content was imported from (its home URL), so that its removal there can follow (P6). */
	public const ORIGIN_META   = '_xb_transfer_origin';
	/** Contents a complete transfer may propose to trash, at most. */
	private const MAX_GONE     = 500;
	/** Post types moved with the site: Histeon's documents, WordPress's navigation, and the content types Histeon defines. */
	private const BASE_TYPES = array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
	private const STATUSES   = array( 'publish', 'draft', 'private', 'future', 'pending' );
	/** The keys a release document takes (Transfer); other slugs (percent-encoded ones) move as plain contents. */
	private const DOC_KEY = '/^[a-z0-9][a-z0-9_\/-]{0,199}$/';

	public function __construct( private readonly Versioning $versioning ) {}

	public static function instance(): self {
		return new self( Plugin::instance()->versioning );
	}

	/** @return list<string> */
	public static function post_types(): array {
		$own = array_keys( \XB\Content\ContentTypes::live()['types'] );
		return array_values( array_unique( array_merge( self::BASE_TYPES, array_map( 'strval', $own ) ) ) );
	}

	// ------------------------------------------------------------------ export

	/**
	 * Writes the `.xbsite` file at `$path`: the whole site, or only some documents (`$only`, document ids such as
	 * `post:12` or `styles`) with the media they use — what a release changed, sent to production (Remote\Sender).
	 *
	 * @param list<string>|null $only
	 * @return array{posts: int, media: int, bytes: int}
	 */
	public function export( string $path, ?array $only = null ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => self::STATUSES,
				'posts_per_page' => self::MAX_POSTS,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$documents = array();
		$contents  = array();
		$keys      = array();
		foreach ( $posts as $post ) {
			$keys[ $post->ID ] = $this->key( $post );
		}
		foreach ( $posts as $post ) {
			$key = $keys[ $post->ID ];
			$doc = Documents::for_post( $post->ID );
			if ( '' === $key || ( null !== $only && ! in_array( $doc, $only, true ) ) ) {
				continue;
			}
			$prod    = $this->versioning->store()->head( $doc, Store::PROD );
			$commit  = null === $prod ? null : $this->versioning->store()->get( $prod );
			$payload = null !== $commit ? $commit['payload'] : array( 'content' => $post->post_content );
			$histos  = ( null !== $commit || \XB\Spec::has_xb_blocks( $post->post_content ) ) && 1 === preg_match( self::DOC_KEY, $key ) && is_string( $payload['content'] ?? null );
			if ( $histos && in_array( $post->post_type, array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part' ), true ) ) {
				if ( 'wp_block' === $post->post_type ) {
					$payload['ref'] = $key;
				}
				$documents[] = array(
					'kind'    => 'post',
					'type'    => $post->post_type,
					'key'     => $key,
					'title'   => $post->post_title,
					'payload' => $payload,
				);
			}
			$contents[] = array(
				'type'      => $post->post_type,
				'key'       => $key,
				'histos'    => $histos,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'date'      => $post->post_date_gmt,
				'excerpt'   => $post->post_excerpt,
				'order'     => (int) $post->menu_order,
				'parent'    => $post->post_parent > 0 ? (string) ( $keys[ $post->post_parent ] ?? '' ) : '',
				'content'   => $histos ? '' : (string) ( $payload['content'] ?? $post->post_content ),
				'thumbnail' => (int) get_post_thumbnail_id( $post ),
				'terms'     => $this->terms( $post ),
			);
		}
		foreach ( array( Documents::STYLES, Documents::SETTINGS ) as $doc ) {
			if ( null !== $only && ! in_array( $doc, $only, true ) ) {
				continue;
			}
			$prod   = $this->versioning->store()->head( $doc, Store::PROD );
			$commit = null === $prod ? null : $this->versioning->store()->get( $prod );
			if ( null !== $commit ) {
				$documents[] = array(
					'kind'    => $doc,
					'payload' => $commit['payload'],
				);
			}
		}
		$media = $this->media( $documents, $contents );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( __( 'File di esportazione non creato.', 'xb' ) );
		}
		$bytes = 0;
		foreach ( $media as $i => $item ) {
			$file = get_attached_file( $item['id'] );
			if ( ! is_string( $file ) || ! is_readable( $file ) ) {
				unset( $media[ $i ] );
				continue;
			}
			$bytes += (int) filesize( $file );
			$zip->addFile( $file, $item['file'] );
			// Its hash in the manifest: the signature over the manifest covers the media too (P8).
			$media[ $i ]['sha256'] = (string) hash_file( 'sha256', $file );
		}
		$manifest = array(
			'format'    => self::FORMAT,
			'version'   => self::VERSION,
			'generator' => 'Histeon ' . XB_VERSION,
			'site'      => home_url( '/' ),
			'uploads'   => (string) wp_upload_dir()['baseurl'],
			'created'   => gmdate( 'c' ),
			'documents' => $documents,
			'contents'  => $contents,
			'media'     => array_values( $media ),
			// The whole site, not a part or a list cut at MAX_POSTS: what is missing was removed here.
			'complete'  => null === $only && count( $posts ) < self::MAX_POSTS,
		);
		$manifest['hash'] = self::hash( $manifest );
		$manifest         = Signing::sign( $manifest );
		$zip->addFromString( 'site.json', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();
		$this->versioning->store()->audit( 'export_site', 'site', array( 'posts' => count( $contents ), 'media' => count( $media ) ) );
		return array(
			'posts' => count( $contents ),
			'media' => count( $media ),
			'bytes' => $bytes,
		);
	}

	/** What identifies a content on any site: the component key, the template slug, the slug. */
	private function key( \WP_Post $post ): string {
		if ( 'wp_block' === $post->post_type ) {
			$ref = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
			return '' !== $ref ? $ref : sanitize_title( $post->post_name );
		}
		return '' !== $post->post_name ? $post->post_name : 'senza-slug-' . $post->ID;
	}

	/** @return array<string, list<array{slug: string, name: string}>> */
	private function terms( \WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'wp_theme', 'wp_template_part_area', 'post_format' ), true ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) && array() !== $terms ) {
				$out[ $taxonomy ] = array_map( static fn ( \WP_Term $t ): array => array( 'slug' => $t->slug, 'name' => $t->name ), array_values( $terms ) );
			}
		}
		return $out;
	}

	/**
	 * The attachments the contents use: every uploads address in them (any size of an image) and the featured images.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @param list<array<string, mixed>> $contents
	 * @return array<int, array{id: int, file: string, url: string, mime: string, alt: string, title: string, caption: string, variants: list<string>}>
	 */
	private function media( array $documents, array $contents ): array {
		$base = (string) wp_upload_dir()['baseurl'];
		$text = (string) wp_json_encode( array( $documents, $contents ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		preg_match_all( '#' . preg_quote( $base, '#' ) . '/[^\s"\'()<>\\\\,]+?\.[a-z0-9]{2,5}\b#i', $text, $m );
		$found = array();
		foreach ( array_unique( $m[0] ) as $url ) {
			$id = self::attachment_for( $url );
			if ( $id > 0 ) {
				$found[ $id ][] = $url;
			}
		}
		foreach ( $contents as $content ) {
			$thumb = (int) ( $content['thumbnail'] ?? 0 );
			if ( $thumb > 0 ) {
				$found[ $thumb ] ??= array();
			}
		}
		$out = array();
		foreach ( $found as $id => $urls ) {
			$post = get_post( $id );
			$file = get_attached_file( $id );
			if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! is_string( $file ) || (int) @filesize( $file ) > self::MAX_MEDIA ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$out[ $id ] = array(
				'id'       => $id,
				'file'     => 'media/' . $id . '-' . sanitize_file_name( basename( $file ) ),
				'url'      => (string) wp_get_attachment_url( $id ),
				'mime'     => (string) $post->post_mime_type,
				'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title'    => $post->post_title,
				'caption'  => $post->post_excerpt,
				'variants' => array_values( array_unique( $urls ) ),
			);
		}
		return $out;
	}

	/** The attachment of an uploads address, also for a resized copy (`-300x200`) or a scaled original. */
	public static function attachment_for( string $url ): int {
		$candidates = array( $url, (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url ) );
		$candidates[] = (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', end( $candidates ) );
		foreach ( array_unique( $candidates ) as $candidate ) {
			$id = attachment_url_to_postid( $candidate );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/** @param array<string, mixed> $manifest */
	private static function hash( array $manifest ): string {
		unset( $manifest['hash'], $manifest['signature'] );
		return hash( 'sha256', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	// ------------------------------------------------------------------ import

	/**
	 * Reads and checks a `.xbsite` file: only `site.json` and files under `media/`, nothing outside, sizes bounded,
	 * the manifest whole (hash) and of the expected shape.
	 *
	 * @return array{manifest: array<string, mixed>, files: array<string, int>}
	 */
	public static function read( string $path ): array {
		$fail = static fn ( string $why ): \InvalidArgumentException => new \InvalidArgumentException( sprintf( __( 'File del sito non valido: %s.', 'xb' ), $why ) );
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw $fail( __( 'non è un archivio leggibile', 'xb' ) );
		}
		try {
			if ( $zip->numFiles > self::MAX_ENTRIES ) {
				throw $fail( __( 'troppi file', 'xb' ) );
			}
			$files = array();
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				$name = is_array( $stat ) ? (string) $stat['name'] : '';
				if ( 'site.json' !== $name && 1 !== preg_match( '#^media/[0-9]+-[A-Za-z0-9._-]{1,200}$#', $name ) ) {
					throw $fail( sprintf( /* translators: %s: file name inside the archive */ __( 'contiene un file fuori posto (%s)', 'xb' ), mb_substr( $name, 0, 80 ) ) );
				}
				$files[ $name ] = (int) $stat['size'];
			}
			if ( ! isset( $files['site.json'] ) || $files['site.json'] > self::MAX_MANIFEST ) {
				throw $fail( __( 'manca site.json, o è troppo grande', 'xb' ) );
			}
			$manifest = json_decode( (string) $zip->getFromName( 'site.json' ), true );
		} finally {
			$zip->close();
		}
		if ( ! is_array( $manifest ) || self::FORMAT !== ( $manifest['format'] ?? null ) || self::VERSION !== ( $manifest['version'] ?? null ) ) {
			throw $fail( __( 'formato o versione sconosciuti', 'xb' ) );
		}
		if ( ! is_string( $manifest['hash'] ?? null ) || ! hash_equals( self::hash( $manifest ), $manifest['hash'] ) ) {
			throw $fail( __( 'contenuto alterato o incompleto (hash)', 'xb' ) );
		}
		foreach ( array( 'documents', 'contents', 'media' ) as $list ) {
			if ( ! is_array( $manifest[ $list ] ?? null ) || ! array_is_list( $manifest[ $list ] ) ) {
				throw $fail( $list );
			}
		}
		if ( count( $manifest['contents'] ) > self::MAX_POSTS ) {
			throw $fail( __( 'troppi contenuti', 'xb' ) );
		}
		foreach ( array( 'site', 'uploads' ) as $url ) {
			if ( ! is_string( $manifest[ $url ] ?? null ) || ! in_array( wp_parse_url( $manifest[ $url ], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				throw $fail( $url );
			}
		}
		foreach ( $manifest['media'] as $i => $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['id'] ?? null ) || ! is_string( $item['file'] ?? null ) || ! isset( $files[ $item['file'] ] ) || $files[ $item['file'] ] > self::MAX_MEDIA || ! is_string( $item['url'] ?? null ) ) {
				throw $fail( "media $i" );
			}
			// A signed manifest covers its media through their hashes: each one must have it (P8).
			$sha = $item['sha256'] ?? null;
			if ( ( null !== $sha || isset( $manifest['signature'] ) ) && ( ! is_string( $sha ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) ) {
				throw $fail( "media $i (sha256)" );
			}
		}
		foreach ( $manifest['contents'] as $i => $content ) {
			// The type is checked on import (one this site does not register yet is skipped, not a broken file).
			if ( ! is_array( $content ) || ! is_string( $content['type'] ?? null ) || 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/', $content['type'] ) || ! is_string( $content['key'] ?? null ) || 1 !== preg_match( '/^[a-z0-9%][a-z0-9%_\/-]{0,199}$/', $content['key'] ) || ! in_array( $content['status'] ?? null, self::STATUSES, true ) ) {
				throw $fail( sprintf( /* translators: %d: position of the content in the file */ __( 'contenuto %d (tipo, chiave o stato)', 'xb' ), $i ) );
			}
		}
		return array(
			'manifest' => $manifest,
			'files'    => $files,
		);
	}

	/**
	 * The media files of an archive against the hashes of its manifest: a file swapped after the export is refused.
	 *
	 * @param array<string, mixed> $manifest
	 */
	private static function verify_media( string $path, array $manifest ): void {
		$with = array_filter( $manifest['media'], static fn ( mixed $item ): bool => is_array( $item ) && is_string( $item['sha256'] ?? null ) );
		if ( array() === $with ) {
			return;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new \InvalidArgumentException( __( 'File del sito non leggibile.', 'xb' ) );
		}
		try {
			foreach ( $with as $item ) {
				$bytes = $zip->getFromName( (string) $item['file'] );
				if ( ! is_string( $bytes ) || ! hash_equals( (string) $item['sha256'], hash( 'sha256', $bytes ) ) ) {
					/* translators: %s: file name inside the archive */
					throw new \InvalidArgumentException( sprintf( __( 'File del sito non valido: il media %s non è quello indicato nel file (hash).', 'xb' ), basename( (string) $item['file'] ) ) );
				}
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Dry run (`$write` false) or the import. `$publish`: administrators who can publish put it all online at once
	 * (a release of what was imported, and the contents' original status).
	 *
	 * @param array<string, mixed> $options `trust`, `allowUnsigned`, `verified` (docs/spec/signing.md); `origin`: the
	 *                                      address the package must come from (a staging link's bound origin, voce 173).
	 * @return array{site: string, media: int, documents: list<array{label: string, target: string, action: string}>, contents: list<array{label: string, action: string}>, issues: list<string>, written: bool, published: string, deletions: list<string>, deleted: int, signature: array{status: string, fingerprint: string, site: string}}
	 */
	public function import( string $path, bool $write, bool $publish = false, bool $delete = false, array $options = array() ): array {
		$read     = self::read( $path );
		$manifest = $read['manifest'];
		// A staging link knows where its packages come from: the site a package names is not taken on its word, or
		// it could stamp contents with another origin and list (or trash) what arrived from there.
		$origin = is_string( $options['origin'] ?? null ) ? untrailingslashit( $options['origin'] ) : '';
		if ( '' !== $origin && $origin !== untrailingslashit( (string) $manifest['site'] ) ) {
			/* translators: 1: address named in the package, 2: address the link is paired with */
			throw new \InvalidArgumentException( sprintf( __( 'Il pacchetto viene da %1$s, ma il collegamento è abbinato a %2$s: per un altro staging crea un nuovo codice sul sito di produzione.', 'xb' ), mb_substr( untrailingslashit( (string) $manifest['site'] ), 0, 200 ), $origin ) );
		}
		// Who signed it (P8), and whether each media file is the one the manifest names.
		$check = empty( $options['verified'] ) ? Signing::check( $manifest ) : null;
		self::verify_media( $path, $manifest );
		if ( $write && null !== $check ) {
			Signing::accept( $check, $options );
		}
		$issues   = array();
		$from     = '' !== $origin ? $origin : untrailingslashit( (string) $manifest['site'] );
		$to       = untrailingslashit( home_url( '/' ) );
		$publish  = $publish && $write && current_user_can( 'manage_options' ) && current_user_can( 'xb_publish' );

		// Media first: their new addresses go into the contents.
		$urls   = array();
		$ids    = array();
		$media  = 0;
		$upload = current_user_can( 'upload_files' );
		if ( ! $upload && array() !== $manifest['media'] ) {
			$issues[] = __( 'Non puoi caricare file: i media non vengono importati e i contenuti puntano ancora a quelli del sito di origine.', 'xb' );
		}
		foreach ( $upload ? $manifest['media'] : array() as $item ) {
			$type = wp_check_filetype( basename( $item['file'] ) );
			if ( false === $type['type'] ) {
				$issues[] = sprintf( /* translators: %s: file name */ __( 'Media %s: tipo di file non ammesso qui, saltato', 'xb' ), basename( $item['file'] ) );
				continue;
			}
			++$media;
			if ( ! $write ) {
				continue;
			}
			$new = $this->import_media( $path, $item, (string) $manifest['site'], $issues );
			if ( $new > 0 ) {
				$ids[ $item['id'] ] = $new;
				$urls              += self::url_map( $item, $new );
			}
		}

		$rewrite = static function ( mixed $value ) use ( $urls, $from, $to ): mixed {
			$pairs = $urls + ( $from !== $to ? array( $from => $to ) : array() );
			$pairs += array_combine( array_map( static fn ( string $k ): string => str_replace( '/', '\\/', $k ), array_keys( $pairs ) ), array_map( static fn ( string $v ): string => str_replace( '/', '\\/', $v ), array_values( $pairs ) ) );
			if ( is_string( $value ) ) {
				return strtr( $value, $pairs );
			}
			if ( is_array( $value ) ) {
				array_walk_recursive(
					$value,
					static function ( mixed &$item ) use ( $pairs ): void {
						if ( is_string( $item ) ) {
							$item = strtr( $item, $pairs );
						}
					}
				);
			}
			return $value;
		};

		// Histeon documents, through the release import (validation, trust, dev commits).
		$documents = $rewrite( $manifest['documents'] );
		$release   = array(
			'format'    => Transfer::FORMAT,
			'version'   => Transfer::VERSION,
			'site'      => home_url( '/' ),
			'release'   => array( 'version' => 'sito', 'notes' => '' ),
			'documents' => $documents,
		);
		$release['hash'] = hash( 'sha256', (string) wp_json_encode( $documents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$report          = Transfer::instance()->import( $release, $write, array( 'verified' => true ) );
		// Drops the release import's "created as a draft" notes (matched in the site's language): the summary below says it.
		$drafted         = '/^' . str_replace( '%s', '.*', preg_quote( __( '%s «%s» creata come bozza: pubblicala quando la release è online', 'xb' ), '/' ) ) . '$/s';
		$issues          = array_merge( $issues, array_values( array_filter( $report['issues'], static fn ( string $i ): bool => 1 !== preg_match( $drafted, $i ) ) ) );

		// Every content: its data, and its body when it is not a Histeon document.
		$rows     = array();
		$by_key   = array();
		$statuses = array();
		foreach ( $manifest['contents'] as $content ) {
			$row    = $this->import_content( $content, $rewrite, $ids, $write, $publish, $issues );
			$rows[] = array(
				'label'  => $row['label'],
				'action' => $row['action'],
			);
			if ( $row['id'] > 0 ) {
				$by_key[ $content['type'] . ':' . $content['key'] ] = $row['id'];
				$statuses[ $row['id'] ]                             = (string) $content['status'];
				update_post_meta( $row['id'], self::ORIGIN_META, $from );
			} elseif ( ( $row['origin'] ?? 0 ) > 0 ) {
				update_post_meta( (int) $row['origin'], self::ORIGIN_META, $from );
			}
		}
		// Removed on the source site: contents that came from it and are not in its complete package (P6).
		$gone    = true === ( $manifest['complete'] ?? null ) ? $this->gone( $manifest, $from ) : array();
		$deleted = 0;
		if ( $write && $delete ) {
			foreach ( $gone as $id => $label ) {
				if ( current_user_can( 'delete_post', $id ) && false !== wp_trash_post( $id ) ) {
					++$deleted;
				} else {
					$issues[] = sprintf( /* translators: %s: content label */ __( '%s non spostato nel Cestino: permessi o contenuto protetto', 'xb' ), $label );
				}
			}
			if ( $deleted > 0 ) {
				$this->versioning->store()->audit( 'import_site_trash', 'file', array( 'site' => (string) $manifest['site'], 'trashed' => $deleted ) );
			}
		}
		if ( $write ) {
			foreach ( $manifest['contents'] as $content ) {
				$id     = $by_key[ $content['type'] . ':' . $content['key'] ] ?? 0;
				$parent = (string) ( $content['parent'] ?? '' );
				if ( $id > 0 && '' !== $parent && isset( $by_key[ $content['type'] . ':' . $parent ] ) ) {
					wp_update_post( array( 'ID' => $id, 'post_parent' => $by_key[ $content['type'] . ':' . $parent ] ) );
				}
			}
		}

		$published = '';
		if ( $publish ) {
			$docs = array_values( array_filter( array_column( $report['documents'], 'target' ), static fn ( string $d ): bool => '' !== $d ) );
			try {
				$done      = $this->versioning->publish( null, __( 'Trasloco del sito', 'xb' ), $docs );
				$published = $done['version'];
				foreach ( $statuses as $id => $status ) {
					if ( get_post_status( $id ) !== $status && current_user_can( 'publish_post', $id ) ) {
						wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
					}
				}
			} catch ( VersioningException $e ) {
				$issues[] = sprintf( /* translators: %s: error message */ __( 'Pubblicazione non riuscita: %s. Tutto è in sviluppo: pubblica una release.', 'xb' ), $e->getMessage() );
			}
		} elseif ( $write ) {
			$issues[] = __( 'Tutto è in sviluppo: i contenuti nuovi sono bozze. Pubblica una release e poi le pagine, oppure importa di nuovo con «Importa e pubblica».', 'xb' );
		}
		if ( $write ) {
			$this->versioning->store()->audit( 'import_site', 'file', array( 'site' => (string) $manifest['site'], 'posts' => count( $rows ), 'media' => count( $ids ), 'published' => $published ) );
		}
		return array(
			'site'      => (string) $manifest['site'],
			'media'     => $write ? count( $ids ) : $media,
			'documents' => $report['documents'],
			'contents'  => $rows,
			'issues'    => array_values( array_unique( $issues ) ),
			'written'   => $write,
			'published' => $published,
			'deletions' => array_values( $gone ),
			'deleted'   => $deleted,
			'signature' => null === $check ? array( 'status' => 'verified', 'fingerprint' => '', 'site' => '' ) : array_intersect_key( $check, array_flip( array( 'status', 'fingerprint', 'site' ) ) ),
		);
	}

	/**
	 * Contents imported from `$from` (ORIGIN_META) that its complete package no longer has: removed there. Only
	 * those: what was written on this site, or came from elsewhere, is never proposed.
	 *
	 * @param array<string, mixed> $manifest
	 * @return array<int, string> Post id → label.
	 */
	private function gone( array $manifest, string $from ): array {
		$present = array();
		foreach ( $manifest['contents'] as $content ) {
			$present[ (string) $content['type'] . ':' . (string) $content['key'] ] = true;
		}
		$posts = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => self::STATUSES,
				'numberposts'      => self::MAX_GONE,
				'meta_key'         => self::ORIGIN_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- imported contents only.
				'meta_value'       => $from, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$key = $this->key( $post );
			if ( '' !== $key && ! isset( $present[ $post->post_type . ':' . $key ] ) ) {
				$out[ $post->ID ] = sprintf( /* translators: 1: content type, 2: title or key */ __( '%s «%s»', 'xb' ), $post->post_type, '' !== $post->post_title ? $post->post_title : $key );
			}
		}
		return $out;
	}

	/**
	 * One attachment from the archive, through WordPress's sideload (its file type checks). An attachment already
	 * imported from the same source is reused, so importing twice does not duplicate media.
	 *
	 * @param array<string, mixed> $item
	 * @param list<string>         $issues
	 */
	private function import_media( string $path, array $item, string $site, array &$issues ): int {
		$source = $site . '#' . (int) $item['id'];
		$found  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_xb_import_source', 'meta_value' => $source, 'numberposts' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return (int) $found[0];
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( basename( (string) $item['file'] ) );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return 0;
		}
		$in  = $zip->getStream( (string) $item['file'] );
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $in || false === $out ) {
			$zip->close();
			return 0;
		}
		stream_copy_to_stream( $in, $out, self::MAX_MEDIA + 1 );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();
		$name = (string) preg_replace( '/^\d+-/', '', basename( (string) $item['file'] ) );
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, (string) ( $item['title'] ?? '' ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			$issues[] = sprintf( /* translators: 1: file name, 2: error message */ __( 'Media %s non importato: %s', 'xb' ), $name, $id->get_error_message() );
			return 0;
		}
		update_post_meta( $id, '_xb_import_source', $source );
		if ( '' !== (string) ( $item['alt'] ?? '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $item['alt'] ) );
		}
		if ( '' !== (string) ( $item['caption'] ?? '' ) ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => wp_kses_post( (string) $item['caption'] ) ) );
		}
		return (int) $id;
	}

	/**
	 * Old addresses → new ones for an imported attachment: the original, and each resized copy the contents used
	 * (the same size here if WordPress made it, else the original).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, string>
	 */
	public static function url_map( array $item, int $id ): array {
		$new = (string) wp_get_attachment_url( $id );
		$map   = array( (string) $item['url'] => $new );
		/** @var mixed $meta False for a file WordPress keeps no metadata for. */
		$meta  = wp_get_attachment_metadata( $id );
		$sizes = is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		foreach ( is_array( $item['variants'] ?? null ) ? $item['variants'] : array() as $variant ) {
			if ( ! is_string( $variant ) || isset( $map[ $variant ] ) ) {
				continue;
			}
			$target = $new;
			if ( 1 === preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', $variant, $m ) ) {
				foreach ( $sizes as $size ) {
					if ( is_array( $size ) && (int) ( $size['width'] ?? 0 ) === (int) $m[1] && (int) ( $size['height'] ?? 0 ) === (int) $m[2] && is_string( $size['file'] ?? null ) ) {
						$target = dirname( $new ) . '/' . $size['file'];
						break;
					}
				}
			}
			$map[ $variant ] = $target;
		}
		return $map;
	}

	/**
	 * One content's data (and body, for one that is not a Histeon document).
	 *
	 * @param array<string, mixed> $content
	 * @param array<int, int>      $ids     Old attachment id → new.
	 * @param list<string>         $issues
	 * @return array{label: string, action: string, id: int, origin?: int}
	 */
	private function import_content( array $content, callable $rewrite, array $ids, bool $write, bool $publish, array &$issues ): array {
		$type  = (string) $content['type'];
		$key   = (string) $content['key'];
		$title = is_string( $content['title'] ?? null ) ? mb_substr( $content['title'], 0, 200 ) : $key;
		$label = sprintf( /* translators: 1: content type, 2: title or key */ __( '%s «%s»', 'xb' ), $type, '' !== $title ? $title : $key );
		$object = in_array( $type, self::post_types(), true ) ? get_post_type_object( $type ) : null;
		if ( null === $object ) {
			$issues[] = sprintf( /* translators: %s: content type */ __( 'Il tipo di contenuto «%s» non è registrato qui: dopo aver pubblicato le impostazioni, importa di nuovo il file per i suoi contenuti', 'xb' ), $type );
			return array( 'label' => $label, 'action' => 'saltato', 'id' => 0 );
		}
		$id = $this->find( $type, $key );
		if ( null !== $id && ! current_user_can( 'edit_post', $id ) ) {
			$issues[] = sprintf( /* translators: %s: content label */ __( 'Non puoi modificare %s: saltato', 'xb' ), $label );
			return array( 'label' => $label, 'action' => 'saltato', 'id' => 0 );
		}
		$theme = in_array( $type, array( 'wp_template', 'wp_template_part' ), true );
		if ( null === $id && ! current_user_can( $theme ? 'edit_theme_options' : $object->cap->create_posts ) ) {
			$issues[] = sprintf( /* translators: %s: content label */ __( 'Non puoi creare %s: saltato', 'xb' ), $label );
			return array( 'label' => $label, 'action' => 'saltato', 'id' => 0 );
		}
		$action = null === $id ? 'crea' : 'aggiorna';
		// Without the right to publish (a staging link without it, an importer without xb_publish), nothing that
		// is online changes: its title, text, date, terms and image stay. A Histeon document still gets its
		// development commit through the release import above, to publish later.
		if ( null !== $id && ! $publish && ! in_array( (string) get_post_status( $id ), array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			// The same content still records where it came from (the proposals of what the source removed).
			if ( empty( $content['histos'] ) ) {
				$issues[] = sprintf( /* translators: %s: content label */ __( '%s è online: senza il permesso di pubblicare non viene modificato', 'xb' ), $label );
				return array( 'label' => $label, 'action' => 'saltato', 'id' => 0, 'origin' => $write ? $id : 0 );
			}
			return array( 'label' => $label, 'action' => 'aggiorna', 'id' => 0, 'origin' => $write ? $id : 0 );
		}
		if ( ! $write ) {
			return array( 'label' => $label, 'action' => $action, 'id' => 0 );
		}
		$data = array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_excerpt' => is_string( $content['excerpt'] ?? null ) ? (string) $rewrite( $content['excerpt'] ) : '',
			'menu_order'   => (int) ( $content['order'] ?? 0 ),
		);
		if ( is_string( $content['date'] ?? null ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $content['date'] ) && '0000-00-00 00:00:00' !== $content['date'] ) {
			$data['post_date_gmt'] = $content['date'];
			$data['post_date']     = get_date_from_gmt( $content['date'] );
		}
		if ( empty( $content['histos'] ) && is_string( $content['content'] ?? null ) ) {
			// Through WordPress's filters for the importer's role (kses without unfiltered_html).
			$data['post_content'] = (string) $rewrite( $content['content'] );
		}
		if ( null === $id ) {
			// A Histeon document the release import just created, or a new content: a draft until published.
			$id = $this->find( $type, $key );
		}
		if ( null === $id ) {
			$data['post_status'] = 'wp_block' === $type || $theme ? 'publish' : 'draft';
			$data['post_name']   = $key;
			$new                 = wp_insert_post( wp_slash( $data ), true );
			if ( is_wp_error( $new ) ) {
				$issues[] = sprintf( /* translators: 1: content label, 2: error message */ __( '%s non creato: %s', 'xb' ), $label, $new->get_error_message() );
				return array( 'label' => $label, 'action' => 'errore', 'id' => 0 );
			}
			$id = (int) $new;
			if ( $theme ) {
				wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
			}
		} else {
			$data['ID'] = $id;
			wp_update_post( wp_slash( $data ) );
		}
		$thumb = (int) ( $content['thumbnail'] ?? 0 );
		if ( $thumb > 0 && isset( $ids[ $thumb ] ) ) {
			set_post_thumbnail( $id, $ids[ $thumb ] );
		}
		foreach ( is_array( $content['terms'] ?? null ) ? $content['terms'] : array() as $taxonomy => $terms ) {
			if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
				continue;
			}
			$tax   = get_taxonomy( $taxonomy );
			$slugs = array();
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) || ! is_string( $term['slug'] ?? null ) ) {
					continue;
				}
				$slug = sanitize_title( $term['slug'] );
				if ( ! term_exists( $slug, $taxonomy ) ) {
					if ( false === $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
						continue;
					}
					wp_insert_term( sanitize_text_field( (string) ( $term['name'] ?? $slug ) ), $taxonomy, array( 'slug' => $slug ) );
				}
				$slugs[] = $slug;
			}
			wp_set_object_terms( $id, $slugs, $taxonomy );
		}
		return array( 'label' => $label, 'action' => $action, 'id' => $id );
	}

	private function find( string $type, string $key ): ?int {
		if ( 'wp_block' === $type && null !== ComponentRegistry::owner( $key ) ) {
			return ComponentRegistry::owner( $key );
		}
		if ( 'wp_template' === $type || 'wp_template_part' === $type ) {
			$template = get_block_template( get_stylesheet() . '//' . $key, $type );
			return $template instanceof \WP_Block_Template && ! empty( $template->wp_id ) ? (int) $template->wp_id : null;
		}
		// Plain synced patterns have no component key: their slug.
		$found = get_posts( array( 'post_type' => $type, 'name' => $key, 'post_status' => self::STATUSES, 'numberposts' => 1, 'fields' => 'ids' ) );
		return $found ? (int) $found[0] : null;
	}
}
