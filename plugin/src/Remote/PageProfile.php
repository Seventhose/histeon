<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

/**
 * The executable, intentionally narrow `histos-static-page-v1` profile for `.xbsite` v2.
 * This class contains no WordPress calls so both the ZIP and canonical-hash contract can be
 * exercised without a live site. WordPress reads/writes are handled by PageProfileAdapter.
 */
final class PageProfile {

	/** Documents' JavaScript (`_xb_script`) in a transfer: required exactly when one carries some. */
	public const SCRIPT_CAPABILITY = 'documents:script:v1';
	public const PROFILE = 'histos-static-page-v1';
	public const PROJECT_PROFILE = 'histos-static-project-v1';
	public const TEMPLATE_PROJECT_PROFILE = 'histos-static-project-v2';
	public const RELATIONAL_PROJECT_PROFILE = 'histos-static-project-v3';
	/** Posts and template roles (docs/spec/cms-bridge.md §12). */
	public const POSTS_PROJECT_PROFILE = 'histos-static-project-v4';
	/** Template roles: the WordPress template slug each one becomes (docs/spec/cms-bridge.md §12). */
	public const TEMPLATE_ROLES = array( 'front-page', 'home', 'index', 'single', 'page', 'archive', 'category', 'tag', 'search', '404' );
	/** Taxonomies of WordPress itself: they exist on every site and belong to posts only. */
	public const NATIVE_TAXONOMIES = array( 'category', 'post_tag' );
	public const MAX_ARCHIVE_BYTES = 536870912;
	public const MAX_ENTRIES = 10000;
	/** Contents of the site named in a manifest as left out of it. */
	public const MAX_LEFT_OUT = 200;
	/**
	 * Leaf conditions of one query filter, across its groups (audit note 85): four levels of 32 would make a million.
	 * The same limit in Histeon Publish (CMS_MAX_FILTER_LEAVES).
	 */
	public const MAX_FILTER_LEAVES = 256;
	public const ENGINE_COMMIT = '9bc212050403dd056ac21edfcff398b514e4a3c1';

	/**
	 * Preserve schema-defined empty JSON objects after associative JSON decoding.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function normalize_payload_for_canonicalization( string $kind, array $payload ): array {
		if ( in_array( $kind, array( 'component', 'template-part', 'template' ), true ) && is_array( $payload['schema'] ?? null ) && array() === ( $payload['schema']['props'] ?? null ) ) {
			$payload['schema']['props'] = new \stdClass();
		}
		if ( 'document' === $kind && 'post' === ( $payload['documentType'] ?? null ) && array() === ( $payload['terms'] ?? null ) ) {
			$payload['terms'] = new \stdClass();
		}
		if ( 'content-record' === $kind ) {
			foreach ( array( 'values', 'terms' ) as $key ) {
				if ( array() === ( $payload[ $key ] ?? null ) ) {
					$payload[ $key ] = new \stdClass();
				}
			}
		}
		if ( 'styles' === $kind ) {
			foreach ( array( 'design', 'tokenOverrides', 'queries' ) as $key ) {
				if ( array_key_exists( $key, $payload ) && array() === $payload[ $key ] ) {
					$payload[ $key ] = new \stdClass();
				}
			}
		}
		return $payload;
	}

	/** Serialize a finite IEEE-754 number using the ECMAScript/JCS notation rules. */
	private static function canonical_number( float $value ): string {
		if ( ! is_finite( $value ) ) {
			throw new \InvalidArgumentException( 'JCS does not allow NaN or infinity.' );
		}
		if ( 0.0 === $value ) {
			return '0';
		}

		// PHP's shortest-roundtrip float digits are useful here, but its notation
		// thresholds and exponent spelling differ from ECMAScript. Normalize the
		// digits below instead of using json_encode's representation as the wire form.
		$previous_precision = ini_get( 'serialize_precision' );
		if ( false === $previous_precision || false === ini_set( 'serialize_precision', '-1' ) ) {
			throw new \RuntimeException( 'Unable to select shortest-roundtrip float serialization for JCS.' );
		}
		try {
			$encoded = json_encode( $value, JSON_THROW_ON_ERROR );
		} finally {
			ini_set( 'serialize_precision', $previous_precision );
		}

		if ( 1 !== preg_match( '/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $encoded, $match ) ) {
			throw new \RuntimeException( 'PHP produced an unexpected shortest-roundtrip number.' );
		}
		$negative = '-' === $match[1];
		$integer  = $match[2];
		$fraction = $match[3] ?? '';
		$exponent = isset( $match[4] ) ? (int) $match[4] : 0;
		$digits   = $integer . $fraction;
		$decimal  = strlen( $integer ) + $exponent;
		$without_leading_zeroes = ltrim( $digits, '0' );
		$decimal -= strlen( $digits ) - strlen( $without_leading_zeroes );
		$digits = rtrim( $without_leading_zeroes, '0' );
		if ( '' === $digits ) {
			return '0';
		}

		$sign = $negative ? '-' : '';
		$scientific_exponent = $decimal - 1;
		// ECMAScript uses decimal notation for 1e-6 <= |n| < 1e21.
		if ( $scientific_exponent >= -6 && $scientific_exponent < 21 ) {
			if ( $decimal <= 0 ) {
				return $sign . '0.' . str_repeat( '0', -$decimal ) . $digits;
			}
			if ( $decimal >= strlen( $digits ) ) {
				return $sign . $digits . str_repeat( '0', $decimal - strlen( $digits ) );
			}
			return $sign . substr( $digits, 0, $decimal ) . '.' . substr( $digits, $decimal );
		}

		$mantissa = $digits[0] . ( strlen( $digits ) > 1 ? '.' . substr( $digits, 1 ) : '' );
		return $sign . $mantissa . 'e' . ( $scientific_exponent >= 0 ? '+' : '' ) . $scientific_exponent;
	}

	/** RFC 8785 JCS for JSON values, including ECMAScript-compatible IEEE-754 numbers. */
	public static function canonical( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( is_string( $value ) ) {
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			return $json;
		}
		if ( is_int( $value ) ) {
			if ( abs( $value ) > 9007199254740991 ) {
				throw new \InvalidArgumentException( 'JCS integer outside the safe range.' );
			}
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return self::canonical_number( $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
			if ( array() === $value ) {
				return '{}';
			}
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'JCS accepts only JSON values.' );
		}
		if ( is_array( $value['payload'] ?? null ) ) {
			if ( 'tombstone' === ( $value['kind'] ?? null ) && is_string( $value['payload']['entityKind'] ?? null ) && is_array( $value['payload']['previousPayload'] ?? null ) ) {
				$value['payload']['previousPayload'] = self::normalize_payload_for_canonicalization( $value['payload']['entityKind'], $value['payload']['previousPayload'] );
			} elseif ( is_string( $value['kind'] ?? null ) ) {
				$value['payload'] = self::normalize_payload_for_canonicalization( $value['kind'], $value['payload'] );
			}
		}
		if ( array_is_list( $value ) ) {
			return '[' . implode( ',', array_map( self::canonical(...), $value ) ) . ']';
		}
		$keys = array_keys( $value );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
				throw new \InvalidArgumentException( 'JCS object keys must be strings.' );
			}
		}
		usort(
			$keys,
			static function ( string $left, string $right ): int {
				return strcmp( mb_convert_encoding( $left, 'UTF-16BE', 'UTF-8' ), mb_convert_encoding( $right, 'UTF-16BE', 'UTF-8' ) );
			}
		);
		$entries = array();
		foreach ( $keys as $key ) {
			$entries[] = self::canonical( $key ) . ':' . self::canonical( $value[ $key ] );
		}
		return '{' . implode( ',', $entries ) . '}';
	}

	/** @param array<string, mixed> $manifest */
	public static function manifest_hash( array $manifest ): string {
		unset( $manifest['manifestHash'] );
		return hash( 'sha256', self::canonical( $manifest ) );
	}

	/** @param array<string, mixed> $entity */
	public static function revision_hash( array $entity ): string {
		$payload = $entity['payload'];
		// WordPress 7.1 writes a backslash in block attributes as \u005c, earlier versions and Publish as \\: the same document.
		if ( is_array( $payload ) && is_string( $payload['content'] ?? null ) ) {
			$payload['content'] = str_replace( '\u005c', '\\\\', $payload['content'] );
		}
		return hash( 'sha256', self::canonical( array( 'kind' => $entity['kind'], 'schemaVersion' => $entity['schemaVersion'], 'payload' => $payload ) ) );
	}

	/** The profile an archive declares, from what it contains (docs/spec/cms-bridge.md §3, §12). */
	public static function expected_profile( bool $posts_or_roles, bool $relational, bool $templates, bool $components ): string {
		if ( $posts_or_roles ) {
			return self::POSTS_PROJECT_PROFILE;
		}
		return $relational ? self::RELATIONAL_PROJECT_PROFILE : ( $templates ? self::TEMPLATE_PROJECT_PROFILE : ( $components ? self::PROJECT_PROFILE : self::PROFILE ) );
	}

	/**
	 * A post document (docs/spec/cms-bridge.md §12): the page fields plus date, excerpt and terms.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function validate_post_payload( array $payload ): void {
		self::assert_keys( $payload, array( 'documentType', 'title', 'slug', 'status', 'content', 'script', 'publishedAt', 'excerpt', 'terms' ), array( 'templateRef', 'image' ) );
		// Doc 23 item 119: the featured image, an image asset of the profile (checked with the assets below).
		if ( array_key_exists( 'image', $payload ) && ! self::is_uuid( $payload['image'] ) ) {
			throw new \InvalidArgumentException( 'Invalid post image.' );
		}
		$slug = $payload['slug'] ?? null;
		if ( ! is_string( $payload['title'] ?? null ) || strlen( $payload['title'] ) > 500 || ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 200 || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) || ! in_array( $payload['status'] ?? null, array( 'draft', 'published' ), true ) || ! is_string( $payload['content'] ?? null ) || strlen( $payload['content'] ) > 4194304 || ! self::valid_script( $payload['script'] ?? null ) || ! is_string( $payload['excerpt'] ?? null ) || strlen( $payload['excerpt'] ) > 20000 || isset( $payload['templateRef'] ) && ( ! is_string( $payload['templateRef'] ) || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\/[a-z][a-z0-9]*(?:-[a-z0-9]+)*)?$/D', $payload['templateRef'] ) ) ) {
			throw new \InvalidArgumentException( 'Invalid static post payload.' );
		}
		$published = 'published' === $payload['status'];
		if ( $published ? ! self::valid_utc_second( $payload['publishedAt'] ) : null !== $payload['publishedAt'] ) {
			throw new \InvalidArgumentException( 'A published post needs its UTC publication date, a draft none.' );
		}
		$terms = $payload['terms'];
		if ( ! is_array( $terms ) || array() !== $terms && array_is_list( $terms ) || count( $terms ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid post taxonomy assignment.' );
		}
		foreach ( $terms as $taxonomy_key => $term_ids ) {
			if ( ! is_string( $taxonomy_key ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $taxonomy_key ) || ! is_array( $term_ids ) || ! array_is_list( $term_ids ) || count( $term_ids ) > 100 || count( array_unique( $term_ids, SORT_REGULAR ) ) !== count( $term_ids ) ) {
				throw new \InvalidArgumentException( 'Invalid post taxonomy assignment.' );
			}
			foreach ( $term_ids as $term_id ) {
				if ( ! self::is_uuid( $term_id ) ) {
					throw new \InvalidArgumentException( 'Invalid post term identity.' );
				}
			}
		}
	}

	/** `AAAA-MM-GGThh:mm:ssZ`, a real instant written exactly as UTC prints it. */
	public static function valid_utc_second( mixed $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d\\TH:i:s\\Z' ) === $value;
	}

	/** @param array<string, mixed> $entity */
	private static function validate_tombstone( array $entity ): void {
		$payload = $entity['payload'];
		$live_kinds = array( 'document', 'component', 'template-part', 'template', 'styles', 'asset', 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' );
		if ( ! isset( $entity['baseHash'] ) || ! is_string( $payload['entityKind'] ?? null ) || ! in_array( $payload['entityKind'], $live_kinds, true ) || ! is_string( $payload['deletedAt'] ?? null ) || ! is_string( $payload['label'] ?? null ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 500 || ! is_string( $payload['previousRevisionHash'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $payload['previousRevisionHash'] ) || ! is_array( $payload['previousPayload'] ?? null ) || array_is_list( $payload['previousPayload'] ) ) {
			throw new \InvalidArgumentException( 'Invalid transfer tombstone.' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s.v\\Z', $payload['deletedAt'], new \DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof \DateTimeImmutable || $date->format( 'Y-m-d\\TH:i:s.v\\Z' ) !== $payload['deletedAt'] ) {
			throw new \InvalidArgumentException( 'Invalid canonical tombstone deletion date.' );
		}
		$previous = array(
			'id' => $entity['id'],
			'kind' => $payload['entityKind'],
			'schemaVersion' => 1,
			'revisionHash' => $payload['previousRevisionHash'],
			'payload' => $payload['previousPayload'],
		);
		if ( ! hash_equals( $payload['previousRevisionHash'], self::revision_hash( $previous ) ) ) {
			throw new \InvalidArgumentException( 'Tombstone snapshot revision hash is invalid.' );
		}
		$previous_payload = $previous['payload'];
		switch ( $previous['kind'] ) {
			case 'document':
				self::assert_keys( $previous_payload, array( 'documentType', 'title', 'slug', 'status', 'content', 'script' ), array( 'templateRef' ) );
				if ( 'page' !== ( $previous_payload['documentType'] ?? null ) || ! is_string( $previous_payload['title'] ?? null ) || strlen( $previous_payload['title'] ) > 500 || ! is_string( $previous_payload['slug'] ?? null ) || strlen( $previous_payload['slug'] ) > 200 || ! in_array( $previous_payload['status'] ?? null, array( 'draft', 'published' ), true ) || ! is_string( $previous_payload['content'] ?? null ) || strlen( $previous_payload['content'] ) > 4194304 || ! self::valid_script( $previous_payload['script'] ?? null ) ) {
					throw new \InvalidArgumentException( 'Invalid document tombstone snapshot.' );
				}
				break;
			case 'component':
			case 'template-part':
			case 'template':
				$optional = 'template-part' === $previous['kind'] ? array( 'area', 'status' ) : array( 'status' );
				self::assert_keys( $previous_payload, array( 'title', 'ref', 'schema', 'content', 'script' ), $optional );
				if ( isset( $previous_payload['status'] ) && ! in_array( $previous_payload['status'], array( 'draft', 'published' ), true ) ) {
					throw new \InvalidArgumentException( 'Invalid reusable tombstone status.' );
				}
				if ( ! is_string( $previous_payload['title'] ?? null ) || '' === trim( $previous_payload['title'] ) || strlen( $previous_payload['title'] ) > 160 || ! is_string( $previous_payload['ref'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:\\/[a-z][a-z0-9]*(?:-[a-z0-9]*)*)?$/D', $previous_payload['ref'] ) || ! is_array( $previous_payload['schema'] ?? null ) || ! is_array( $previous_payload['schema']['props'] ?? null ) || count( $previous_payload['schema']['props'] ) > 64 || ! is_string( $previous_payload['content'] ?? null ) || strlen( $previous_payload['content'] ) > 4194304 || ! self::valid_script( $previous_payload['script'] ?? null ) || 'template-part' === $previous['kind'] && ! in_array( $previous_payload['area'] ?? null, array( 'header', 'footer', 'uncategorized' ), true ) ) {
					throw new \InvalidArgumentException( 'Invalid Histeon reusable tombstone snapshot.' );
				}
				break;
			case 'styles':
				self::assert_keys( $previous_payload, array( 'styles', 'globals', 'compiledCss' ), array( 'design', 'tokenOverrides', 'queries' ) );
				if ( ! is_array( $previous_payload['styles'] ?? null ) || ! array_is_list( $previous_payload['styles'] ) || ! is_array( $previous_payload['globals'] ?? null ) || ! array_is_list( $previous_payload['globals'] ) || ! is_string( $previous_payload['compiledCss'] ?? null ) || strlen( $previous_payload['compiledCss'] ) > 4194304 ) {
					throw new \InvalidArgumentException( 'Invalid styles tombstone snapshot.' );
				}
				break;
			case 'asset':
				self::assert_keys( $previous_payload, array( 'sha256', 'mediaType', 'bytes', 'path', 'alt', 'title', 'caption' ), array( 'width', 'height' ) );
				if ( ! is_string( $previous_payload['path'] ?? null ) || 1 !== preg_match( '#^media/[0-9a-f]{64}$#D', $previous_payload['path'] ) || ! is_string( $previous_payload['sha256'] ?? null ) || ! hash_equals( substr( $previous_payload['path'], 6 ), $previous_payload['sha256'] ) || ! is_int( $previous_payload['bytes'] ?? null ) || $previous_payload['bytes'] < 0 || ! is_string( $previous_payload['mediaType'] ?? null ) || ! is_string( $previous_payload['alt'] ?? null ) || ! is_string( $previous_payload['title'] ?? null ) || ! is_string( $previous_payload['caption'] ?? null ) ) {
					throw new \InvalidArgumentException( 'Invalid asset tombstone snapshot.' );
				}
				break;
			default:
				self::validate_relational_payload( (string) $previous['kind'], $previous_payload );
		}
	}

	/**
	 * Validate and normalize provenance attached to user-supplied framework CSS.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function validate_framework_source( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid external CSS provenance.' );
		}
		self::assert_keys( $value, array( 'kind', 'frameworkId', 'name', 'rightsConfirmed' ), array( 'version', 'license', 'sourceUrl', 'preset', 'configuration' ) );
		if ( 'user-css' !== ( $value['kind'] ?? null ) || ! in_array( $value['frameworkId'] ?? null, array( 'core-framework', 'automatic-css', 'custom' ), true ) || ! is_string( $value['name'] ?? null ) || '' === trim( $value['name'] ) || strlen( $value['name'] ) > 120 || true !== ( $value['rightsConfirmed'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid external CSS provenance.' );
		}
		$out = array(
			'kind'           => 'user-css',
			'frameworkId'    => $value['frameworkId'],
			'name'           => trim( $value['name'] ),
			'rightsConfirmed'=> true,
		);
		foreach ( array( 'version' => 80, 'license' => 160 ) as $key => $limit ) {
			if ( ! array_key_exists( $key, $value ) || '' === $value[ $key ] ) {
				continue;
			}
			if ( ! is_string( $value[ $key ] ) || strlen( $value[ $key ] ) > $limit ) {
				throw new \InvalidArgumentException( 'Invalid external CSS provenance.' );
			}
			$out[ $key ] = trim( $value[ $key ] );
		}
		if ( array_key_exists( 'sourceUrl', $value ) ) {
			$url = $value['sourceUrl'];
			$parts = is_string( $url ) ? parse_url( $url ) : false;
			if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 || 1 !== preg_match( '/\A[\x21-\x7E]+\z/', $url ) || str_contains( $url, '\\' ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				throw new \InvalidArgumentException( 'Invalid external CSS source URL.' );
			}
			$out['sourceUrl'] = $url;
		}
		if ( array_key_exists( 'preset', $value ) ) {
			$preset = $value['preset'];
			if ( 'core-framework' !== $value['frameworkId'] || ! is_array( $preset ) || array_is_list( $preset ) ) {
				throw new \InvalidArgumentException( 'Invalid Core Framework preset.' );
			}
			self::assert_keys( $preset, array( 'format', 'data' ), array() );
			$format = $preset['format'] ?? null;
			$data   = $preset['data'] ?? null;
			if ( ! in_array( $format, array( 'core-lz-utf16-v1', 'core-json-v1' ), true ) || ! is_string( $data ) || '' === $data || strlen( $data ) > 1048576 || 1 !== preg_match( '//u', $data ) ) {
				throw new \InvalidArgumentException( 'Invalid Core Framework preset.' );
			}
			if ( 'core-lz-utf16-v1' === $format ) {
				if ( 1 === preg_match( '/[^\x20-\x{801f}]/u', $data ) ) {
					throw new \InvalidArgumentException( 'Invalid compressed Core Framework preset.' );
				}
			} else {
				$decoded = json_decode( $data );
				if ( ! $decoded instanceof \stdClass
					|| ! is_string( $decoded->id ?? null ) || '' === trim( $decoded->id )
					|| ! is_string( $decoded->name ?? null ) || '' === trim( $decoded->name )
					|| ! is_string( $decoded->description ?? null ) || ! is_string( $decoded->date ?? null )
					|| ! ( property_exists( $decoded, 'groups' ) || property_exists( $decoded, 'styleSheetData' ) || property_exists( $decoded, 'modulesData' ) || property_exists( $decoded, 'cssString' ) )
					|| ( property_exists( $decoded, 'cssString' ) && ! is_string( $decoded->cssString ) )
				) {
					throw new \InvalidArgumentException( 'Invalid Core Framework preset JSON.' );
				}
			}
			$out['preset'] = array( 'format' => $format, 'data' => $data );
		}
		if ( array_key_exists( 'configuration', $value ) ) {
			$configuration = $value['configuration'];
			if ( ! is_array( $configuration ) || array_is_list( $configuration ) ) {
				throw new \InvalidArgumentException( 'Invalid external framework configuration.' );
			}
			self::assert_keys( $configuration, array( 'format', 'data' ), array() );
			$data = $configuration['data'] ?? null;
			if ( 'opaque-utf8-v1' !== ( $configuration['format'] ?? null ) || ! is_string( $data ) || '' === $data || strlen( $data ) > 1048576 || str_contains( $data, "\0" ) || 1 !== preg_match( '//u', $data ) ) {
				throw new \InvalidArgumentException( 'Invalid external framework configuration.' );
			}
			$out['configuration'] = array( 'format' => 'opaque-utf8-v1', 'data' => $data );
		}
		return $out;
	}

	/**
	 * Whether the bytes carry the signature of the declared media type. Only the types WordPress receives from a
	 * profile: raster images and web fonts (never SVG, HTML or scripts).
	 */
	public static function bytes_match_media_type( string $bytes, string $type ): bool {
		return match ( $type ) {
			'image/png'  => str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ),
			'image/jpeg' => str_starts_with( $bytes, "\xFF\xD8\xFF" ),
			'image/gif'  => str_starts_with( $bytes, 'GIF87a' ) || str_starts_with( $bytes, 'GIF89a' ),
			'image/webp' => str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ),
			'image/avif' => 'ftyp' === substr( $bytes, 4, 4 ) && in_array( substr( $bytes, 8, 4 ), array( 'avif', 'avis' ), true ),
			'font/woff'  => str_starts_with( $bytes, 'wOFF' ),
			'font/woff2' => str_starts_with( $bytes, 'wOF2' ),
			'font/otf'   => str_starts_with( $bytes, 'OTTO' ),
			'font/ttf'   => str_starts_with( $bytes, "\x00\x01\x00\x00" ) || str_starts_with( $bytes, 'true' ),
			'application/pdf' => str_starts_with( $bytes, '%PDF-' ),
			default      => false,
		};
	}

	/**
	 * Validate a parsed profile plus the exact bytes declared by its manifest.
	 *
	 * @param array<string, mixed>        $manifest
	 * @param list<array<string, mixed>>  $entities
	 * @param list<array<string, mixed>>  $references
	 * @param array<string, string>       $files
	 */
	public static function validate( array $manifest, array $entities, array $references, array $files ): void {
		$profile_name = $manifest['profile'] ?? null;
		if ( 'xb-site' !== ( $manifest['format'] ?? null ) || 2 !== ( $manifest['version'] ?? null ) || ! in_array( $profile_name, array( self::PROFILE, self::PROJECT_PROFILE, self::TEMPLATE_PROJECT_PROFILE, self::RELATIONAL_PROJECT_PROFILE, self::POSTS_PROJECT_PROFILE ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported transfer format or page profile.' );
		}
		$generator = $manifest['generator'] ?? null;
		$project   = $manifest['project'] ?? null;
		$selection = $manifest['selection'] ?? null;
		if ( ! is_array( $generator ) || 'Histos CMS' !== ( $generator['name'] ?? null ) || ! is_string( $generator['version'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{40}$/D', (string) ( $generator['engineCommit'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( 'Invalid transfer generator.' );
		}
		self::assert_keys( $generator, array( 'name', 'version', 'engineCommit' ) );
		if ( ! is_array( $project ) || ! self::is_uuid( $project['id'] ?? null ) || ! is_string( $project['name'] ?? null ) || '' === trim( $project['name'] ) || strlen( $project['name'] ) > 160 ) {
			throw new \InvalidArgumentException( 'Invalid transfer project.' );
		}
		self::assert_keys( $project, array( 'id', 'name' ), array( 'baseUrl', 'defaultLocale' ) );
		if ( isset( $project['baseUrl'] ) && ( ! is_string( $project['baseUrl'] ) || 1 !== preg_match( '#^https?://#', $project['baseUrl'] ) || strlen( $project['baseUrl'] ) > 2048 ) ) {
			throw new \InvalidArgumentException( 'Invalid transfer base URL.' );
		}
		if ( isset( $project['defaultLocale'] ) && ( ! is_string( $project['defaultLocale'] ) || strlen( $project['defaultLocale'] ) > 64 || 1 !== preg_match( '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $project['defaultLocale'] ) || self::canonical_locale( $project['defaultLocale'] ) !== $project['defaultLocale'] ) ) {
			throw new \InvalidArgumentException( 'Invalid transfer default locale.' );
		}
		if ( ! is_array( $selection ) || ! in_array( $selection['kind'] ?? null, array( 'workspace-head', 'release' ), true ) || ! self::is_uuid( $selection['snapshot'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid transfer selection.' );
		}
		self::assert_keys( $selection, array( 'kind', 'snapshot' ) );
		self::assert_keys( $manifest, array( 'format', 'version', 'generator', 'project', 'selection', 'requiredCapabilities', 'profile', 'files', 'manifestHash' ), array( 'leftOut' ) );
		$left_outs = $manifest['leftOut'] ?? array();
		if ( ! is_array( $left_outs ) || count( $left_outs ) > self::MAX_LEFT_OUT ) {
			throw new \InvalidArgumentException( 'Transfer manifest lists an invalid content left out.' );
		}
		foreach ( $left_outs as $left_out ) {
			if ( ! is_array( $left_out ) || ! in_array( $left_out['kind'] ?? null, array( 'page', 'post', 'component', 'template-part', 'template', 'query' ), true ) || ! is_string( $left_out['title'] ?? null ) || ! is_string( $left_out['reason'] ?? null ) || ( array_key_exists( 'blocks', $left_out ) && true !== $left_out['blocks'] ) ) {
				throw new \InvalidArgumentException( 'Transfer manifest lists an invalid content left out.' );
			}
			self::assert_keys( $left_out, array( 'kind', 'title', 'reason' ), array( 'blocks' ) );
		}
		$required = $manifest['requiredCapabilities'] ?? null;
		$base_capabilities = array( 'documents:page:v1', 'styles:global:v1', 'media:asset:v1' );
		if ( ! is_array( $required ) || ! array_is_list( $required ) || array() === $required ) {
			throw new \InvalidArgumentException( 'Invalid required capabilities.' );
		}
		foreach ( $required as $capability ) {
			if ( ! is_string( $capability ) || 1 !== preg_match( '/^[a-z][a-z0-9-]*:[a-zA-Z0-9._-]+:v[1-9][0-9]*$/D', $capability ) ) {
				throw new \InvalidArgumentException( 'Invalid required capability.' );
			}
		}
		if ( count( array_unique( $required ) ) !== count( $required ) || array_diff( $base_capabilities, $required ) ) {
			throw new \InvalidArgumentException( 'Unsupported required capability set.' );
		}
		$declared = $manifest['files'] ?? null;
		if ( ! is_array( $declared ) || ! array_is_list( $declared ) || count( $declared ) < 2 || count( $declared ) > self::MAX_ENTRIES ) {
			throw new \InvalidArgumentException( 'Invalid transfer file list.' );
		}
		$declared_by_path = array();
		foreach ( $declared as $file ) {
			if ( ! is_array( $file ) || ! is_string( $file['path'] ?? null ) || ! is_int( $file['bytes'] ?? null ) || $file['bytes'] < 0 || ! is_string( $file['sha256'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $file['sha256'] ) ) {
				throw new \InvalidArgumentException( 'Invalid transfer file entry.' );
			}
			$path = $file['path'];
			self::assert_keys( $file, array( 'path', 'bytes', 'sha256' ), array( 'mediaType' ) );
			if ( $file['bytes'] > self::MAX_ARCHIVE_BYTES || isset( $file['mediaType'] ) && ( ! is_string( $file['mediaType'] ) || strlen( $file['mediaType'] ) > 128 ) ) {
				throw new \InvalidArgumentException( 'Transfer file metadata is outside limits.' );
			}
			if ( isset( $declared_by_path[ $path ] ) || ! in_array( $path, array( 'entities.ndjson', 'references.ndjson' ), true ) && 1 !== preg_match( '#^media/[0-9a-f]{64}$#D', $path ) ) {
				throw new \InvalidArgumentException( 'Duplicate or unsupported transfer file path.' );
			}
			// A media file is named by its bytes: the name and the declared hash are the same (the bytes are checked below).
			if ( str_starts_with( $path, 'media/' ) && ! hash_equals( substr( $path, 6 ), $file['sha256'] ) ) {
				throw new \InvalidArgumentException( 'Transfer media file is not named by its hash: ' . $path );
			}
			$declared_by_path[ $path ] = $file;
		}
		if ( ! isset( $declared_by_path['entities.ndjson'], $declared_by_path['references.ndjson'] ) || count( $files ) !== count( $declared_by_path ) ) {
			throw new \InvalidArgumentException( 'Transfer file set is incomplete or contains undeclared files.' );
		}
		foreach ( $declared_by_path as $path => $file ) {
			$bytes = $files[ $path ] ?? null;
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $file['bytes'] || ! hash_equals( $file['sha256'], hash( 'sha256', $bytes ) ) ) {
				throw new \InvalidArgumentException( 'Transfer file integrity check failed: ' . $path );
			}
		}
		if ( count( $entities ) > self::MAX_ENTRIES || count( $references ) > 100000 ) {
			throw new \InvalidArgumentException( 'The transfer profile exceeds the entity or reference limit.' );
		}
		if ( ! is_string( $manifest['manifestHash'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $manifest['manifestHash'] ) || ! hash_equals( self::manifest_hash( $manifest ), $manifest['manifestHash'] ) ) {
			throw new \InvalidArgumentException( 'Transfer manifest hash is invalid.' );
		}
		$ids        = array();
		$documents  = array();
		$assets     = array();
		$asset_media_types = array();
		$style_rules = array();
		$components = array();
		$component_refs = array();
		$templates = array();
		$template_refs = array();
		$page_count = 0;
		$post_count = 0;
		$roles = array();
		$style_count = 0;
		foreach ( $entities as $entity ) {
			if ( ! self::is_uuid( $entity['id'] ?? null ) || isset( $ids[ $entity['id'] ] ) || 1 !== ( $entity['schemaVersion'] ?? null ) || ! is_string( $entity['revisionHash'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $entity['revisionHash'] ) || ! is_array( $entity['payload'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Invalid or duplicate transfer entity.' );
			}
			self::assert_keys( $entity, array( 'id', 'kind', 'schemaVersion', 'revisionHash', 'payload' ), array( 'baseHash' ) );
			if ( isset( $entity['baseHash'] ) && ( ! is_string( $entity['baseHash'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $entity['baseHash'] ) ) ) {
				throw new \InvalidArgumentException( 'Invalid transfer base hash.' );
			}
			$ids[ $entity['id'] ] = true;
			if ( ! hash_equals( self::revision_hash( $entity ), $entity['revisionHash'] ) ) {
				throw new \InvalidArgumentException( 'Transfer entity revision hash is invalid.' );
			}
			if ( 'tombstone' === ( $entity['kind'] ?? null ) ) {
				self::validate_tombstone( $entity );
				continue;
			}
			$payload = $entity['payload'];
			switch ( $entity['kind'] ?? null ) {
				case 'document':
					$documents[ $entity['id'] ] = true;
					if ( 'post' === ( $payload['documentType'] ?? null ) ) {
						++$post_count;
						self::validate_post_payload( $payload );
						break;
					}
					++$page_count;
					self::assert_keys( $payload, array( 'documentType', 'title', 'slug', 'status', 'content', 'script' ), array( 'templateRef' ) );
					if ( 'page' !== ( $payload['documentType'] ?? null ) || ! is_string( $payload['title'] ?? null ) || strlen( $payload['title'] ) > 500 || ! is_string( $payload['slug'] ?? null ) || strlen( $payload['slug'] ) > 200 || ! in_array( $payload['status'] ?? null, array( 'draft', 'published' ), true ) || ! is_string( $payload['content'] ?? null ) || strlen( $payload['content'] ) > 4194304 || ! self::valid_script( $payload['script'] ?? null ) || isset( $payload['templateRef'] ) && ( ! is_string( $payload['templateRef'] ) || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\/[a-z][a-z0-9]*(?:-[a-z0-9]+)*)?$/D', $payload['templateRef'] ) ) ) {
						throw new \InvalidArgumentException( 'Invalid static page payload.' );
					}
					break;
				case 'component':
				case 'template-part':
				case 'template':
					$documents[ $entity['id'] ] = true;
					$optional = 'template-part' === $entity['kind'] ? array( 'area', 'status' ) : ( 'template' === $entity['kind'] ? array( 'status', 'role' ) : array( 'status' ) );
					self::assert_keys( $payload, array( 'title', 'ref', 'schema', 'content', 'script' ), $optional );
					if ( isset( $payload['status'] ) && ! in_array( $payload['status'], array( 'draft', 'published' ), true ) ) {
						throw new \InvalidArgumentException( 'Invalid Histeon reusable status.' );
					}
					$ref = $payload['ref'] ?? null;
					if ( ! is_string( $payload['title'] ?? null ) || '' === trim( $payload['title'] ) || strlen( $payload['title'] ) > 160 || ! is_string( $ref ) || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:\/[a-z][a-z0-9]*(?:-[a-z0-9]*)*)?$/D', $ref ) || isset( $component_refs[ $ref ] ) || ! is_array( $payload['schema'] ?? null ) || ! self::valid_script( $payload['script'] ?? null ) || ! is_string( $payload['content'] ?? null ) || strlen( $payload['content'] ) > 4194304 ) {
						throw new \InvalidArgumentException( 'Invalid Histeon component payload.' );
					}
					self::assert_keys( $payload['schema'], array( 'props' ) );
					if ( ! is_array( $payload['schema']['props'] ) || count( $payload['schema']['props'] ) > 64 ) {
						throw new \InvalidArgumentException( 'Invalid Histeon component schema.' );
					}
					if ( 'template-part' === $entity['kind'] && ! in_array( $payload['area'] ?? null, array( 'header', 'footer', 'uncategorized' ), true ) ) {
						throw new \InvalidArgumentException( 'Invalid Histeon template part area.' );
					}
					foreach ( $payload['schema']['props'] as $name => $definition ) {
						if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $name ) || ! is_array( $definition ) || ! is_string( $definition['type'] ?? null ) || ! in_array( $definition['type'], array( 'string', 'number', 'boolean', 'url', 'image', 'rich-text', 'enum', 'array', 'object' ), true ) ) {
							throw new \InvalidArgumentException( 'Invalid Histeon component prop.' );
						}
						self::assert_keys( $definition, array( 'type' ), array( 'default', 'label', 'options' ) );
						if ( isset( $definition['label'] ) && ( ! is_string( $definition['label'] ) || strlen( $definition['label'] ) > 120 ) || isset( $definition['options'] ) && ( ! is_array( $definition['options'] ) || ! array_is_list( $definition['options'] ) || count( $definition['options'] ) > 100 ) ) {
							throw new \InvalidArgumentException( 'Invalid Histeon component prop metadata.' );
						}
						if ( isset( $definition['options'] ) && array_filter( $definition['options'], static fn ( mixed $option ): bool => ! is_string( $option ) || strlen( $option ) > 200 ) ) {
							throw new \InvalidArgumentException( 'Invalid Histeon component options.' );
						}
						if ( 'enum' === $definition['type'] && ( empty( $definition['options'] ) || isset( $definition['default'] ) && ! in_array( $definition['default'], $definition['options'], true ) ) ) {
							throw new \InvalidArgumentException( 'Invalid Histeon component enum definition.' );
						}
						if ( array_key_exists( 'default', $definition ) && ! self::valid_prop_default( $definition['type'], $definition['default'] ) ) {
							throw new \InvalidArgumentException( 'Invalid Histeon component prop default.' );
						}
					}
					if ( 'template' === $entity['kind'] ) {
						if ( isset( $template_refs[ $ref ] ) ) {
							throw new \InvalidArgumentException( 'Duplicate Histeon template reference.' );
						}
						if ( array_key_exists( 'role', $payload ) ) {
							if ( ! is_string( $payload['role'] ) || ! in_array( $payload['role'], self::TEMPLATE_ROLES, true ) || isset( $roles[ $payload['role'] ] ) ) {
								throw new \InvalidArgumentException( 'Invalid or duplicate Histeon template role.' );
							}
							$roles[ $payload['role'] ] = true;
						}
						if ( ! self::has_default_slot( $payload['content'] ) ) {
							throw new \InvalidArgumentException( 'A Histeon page template must contain the default slot.' );
						}
						$templates[ $entity['id'] ] = $ref;
						$template_refs[ $ref ] = $entity['id'];
					} else {
						$components[ $entity['id'] ] = $ref;
						$component_refs[ $ref ]      = $entity['id'];
					}
					break;
				case 'styles':
					++$style_count;
					self::assert_keys( $payload, array( 'styles', 'globals', 'compiledCss' ), array( 'design', 'tokenOverrides', 'queries' ) );
					if ( ! is_array( $payload['styles'] ?? null ) || ! array_is_list( $payload['styles'] ) || ! is_array( $payload['globals'] ?? null ) || ! array_is_list( $payload['globals'] ) || ! is_string( $payload['compiledCss'] ?? null ) || strlen( $payload['compiledCss'] ) > 4194304 ) {
						throw new \InvalidArgumentException( 'Invalid global styles payload.' );
					}
					try {
						if ( array_key_exists( 'design', $payload ) ) {
							\XB\Framework\HistosCss\Design::validate( $payload['design'] );
						}
						if ( array_key_exists( 'tokenOverrides', $payload ) ) {
							\XB\Framework\TokenOverrides::validate( $payload['tokenOverrides'] );
						}
						if ( array_key_exists( 'queries', $payload ) ) {
							\XB\Styles\NamedQueries::validate( $payload['queries'] );
						}
					} catch ( \InvalidArgumentException $error ) {
						throw new \InvalidArgumentException( 'Invalid Histeon design profile: ' . $error->getMessage(), 0, $error );
					}
					foreach ( $payload['styles'] as $entry ) {
						if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || strlen( $entry['id'] ) > 128 || ! is_string( $entry['selector'] ?? null ) || ! \XB\Styles\CssCompiler::valid_selector( $entry['selector'] ) || ! is_string( $entry['css'] ?? null ) || strlen( $entry['css'] ) > 1048576 ) {
							throw new \InvalidArgumentException( 'Invalid style entry.' );
						}
						self::assert_keys( $entry, array( 'id', 'selector', 'css' ), array( 'assets' ) );
						$style_rules[] = array( 'css' => $entry['css'], 'assets' => $entry['assets'] ?? array() );
					}
					foreach ( $payload['globals'] as $entry ) {
						if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || strlen( $entry['id'] ) > 128 || ! is_string( $entry['css'] ?? null ) || strlen( $entry['css'] ) > 1048576 ) {
							throw new \InvalidArgumentException( 'Invalid global style entry.' );
						}
						self::assert_keys( $entry, array( 'id', 'css' ), array( 'source', 'assets' ) );
						if ( isset( $entry['source'] ) ) {
							self::validate_framework_source( $entry['source'] );
						}
						$style_rules[] = array( 'css' => $entry['css'], 'assets' => $entry['assets'] ?? array() );
					}
					break;
				case 'asset':
					$payload_path = $payload['path'] ?? null;
					if ( ! is_string( $payload_path ) || 1 !== preg_match( '#^media/[0-9a-f]{64}$#D', $payload_path ) || ! isset( $declared_by_path[ $payload_path ] ) || ! is_string( $payload['sha256'] ?? null ) || ! hash_equals( substr( $payload_path, 6 ), $payload['sha256'] ) || ! is_int( $payload['bytes'] ?? null ) || $payload['bytes'] !== $declared_by_path[ $payload_path ]['bytes'] || ! is_string( $payload['mediaType'] ?? null ) || strlen( $payload['mediaType'] ) > 128 || ! is_string( $payload['alt'] ?? null ) || strlen( $payload['alt'] ) > 1000 || ! is_string( $payload['title'] ?? null ) || strlen( $payload['title'] ) > 500 || ! is_string( $payload['caption'] ?? null ) || strlen( $payload['caption'] ) > 4000 ) {
						throw new \InvalidArgumentException( 'Invalid media asset payload.' );
					}
					self::assert_keys( $payload, array( 'sha256', 'mediaType', 'bytes', 'path', 'alt', 'title', 'caption' ), array( 'width', 'height' ) );
					foreach ( array( 'width', 'height' ) as $dimension ) {
						if ( isset( $payload[ $dimension ] ) && ( ! is_int( $payload[ $dimension ] ) || $payload[ $dimension ] < 0 ) ) {
							throw new \InvalidArgumentException( 'Invalid media dimensions.' );
						}
					}
					// The type is checked on the bytes, not only on what the manifest says (docs/spec/cms-bridge.md §5).
					if ( ! self::bytes_match_media_type( (string) ( $files[ $payload_path ] ?? '' ), $payload['mediaType'] ) ) {
						throw new \InvalidArgumentException( 'A media file is not of its declared type, or its type is not supported.' );
					}
					$assets[ $entity['id'] ] = true;
					$asset_media_types[ $entity['id'] ] = $payload['mediaType'];
					break;
				case 'content-type':
				case 'content-record':
				case 'taxonomy':
				case 'term':
				case 'query-preset':
				case 'query-source':
					try {
						self::validate_relational_payload( (string) $entity['kind'], $payload );
					} catch ( \InvalidArgumentException $error ) {
						throw new \InvalidArgumentException( self::refusal_at( $entity, $error->getMessage() ), 0, $error );
					}
					break;
				default:
					throw new \InvalidArgumentException( 'Unsupported entity kind in static page profile.' );
			}
		}
		foreach ( $style_rules as $rule ) {
			\XB\Styles\CssAssets::validate_profile( $rule['css'], $rule['assets'], $assets, $asset_media_types );
		}
		$component_count = count( $components );
		$uses_templates = count( $templates ) > 0 || count( array_filter( $entities, static fn ( array $entity ): bool => 'template-part' === $entity['kind'] ) ) > 0;
		$content_counts = array();
		foreach ( array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ) as $kind ) {
			$content_counts[ $kind ] = count( array_filter( $entities, static fn ( array $entity ): bool => $kind === $entity['kind'] ) );
		}
		self::validate_relational_graph( $entities );
		ProductProfile::validate( $entities );
		$has_relational_content = array_sum( $content_counts ) > 0;
			$expected_capabilities = $base_capabilities;
			if ( $component_count > 0 ) {
				$expected_capabilities[] = 'components:definition:v1';
			}
		if ( $uses_templates ) {
			$expected_capabilities[] = 'templates:definition:v1';
		}
		if ( $content_counts['content-type'] > 0 ) {
			$expected_capabilities[] = 'content:types:v1';
		}
		if ( $content_counts['content-record'] > 0 ) {
			$expected_capabilities[] = 'content:records:v1';
		}
		if ( $content_counts['taxonomy'] > 0 || $content_counts['term'] > 0 ) {
			$expected_capabilities[] = 'content:taxonomies:v1';
		}
		if ( $content_counts['query-preset'] > 0 || $content_counts['query-source'] > 0 ) {
			$expected_capabilities[] = 'content:queries:v1';
		}
		if ( $post_count > 0 ) {
			$expected_capabilities[] = 'documents:post:v1';
		}
		if ( array() !== $roles ) {
			$expected_capabilities[] = 'templates:roles:v1';
		}
		if ( ProductProfile::uses_products( $entities ) ) {
			$expected_capabilities[] = ProductProfile::CAPABILITY;
		}
		if ( self::uses_scripts( $entities ) ) {
			$expected_capabilities[] = self::SCRIPT_CAPABILITY;
		}
		$expected_profile = self::expected_profile( $post_count > 0 || array() !== $roles, $has_relational_content, $uses_templates, $component_count > 0 );
		if ( $expected_profile !== $profile_name || count( array_diff( $required, $expected_capabilities ) ) || count( array_diff( $expected_capabilities, $required ) ) || $page_count < 1 || 1 !== $style_count ) {
			throw new \InvalidArgumentException( 'The static page profile requires at least one page and exactly one style snapshot.' );
		}
		foreach ( $entities as $entity ) {
			$image = 'document' === $entity['kind'] ? ( $entity['payload']['image'] ?? null ) : null;
			if ( null !== $image && ( ! isset( $assets[ $image ] ) || ! str_starts_with( (string) ( $asset_media_types[ $image ] ?? '' ), 'image/' ) ) ) {
				throw new \InvalidArgumentException( 'A post image is not an image asset of the transfer profile.' );
			}
		}
		$template_refs = array_flip( array_values( $templates ) );
		foreach ( $entities as $entity ) {
			if ( 'document' === $entity['kind'] && isset( $entity['payload']['templateRef'] ) && ! isset( $template_refs[ $entity['payload']['templateRef'] ] ) ) {
				throw new \InvalidArgumentException( 'A page references a template outside the transfer profile.' );
			}
		}
		foreach ( $references as $reference ) {
			if ( ! self::is_uuid( $reference['ownerId'] ?? null ) || ! self::is_uuid( $reference['targetId'] ?? null ) || ! is_string( $reference['blockUid'] ?? null ) || 1 !== preg_match( '/^[a-z0-9]{8,16}$/D', $reference['blockUid'] ) || ! isset( $documents[ $reference['ownerId'] ] ) ) {
				throw new \InvalidArgumentException( 'Invalid or unresolved document reference.' );
			}
			self::assert_keys( $reference, array( 'ownerId', 'blockUid', 'path', 'targetId', 'role' ), array( 'index' ) );
			if ( 'media' === ( $reference['role'] ?? null ) ) {
				// A src reference has no index; a srcset reference names the candidate it stands for (transfer-markup-v1).
				$src_reference    = 'attrs.attributes.src' === $reference['path'] && ! array_key_exists( 'index', $reference );
				$href_reference   = 'attrs.attributes.href' === $reference['path'] && ! array_key_exists( 'index', $reference );
				$srcset_reference = 'attrs.attributes.srcset' === $reference['path'] && is_int( $reference['index'] ?? null ) && $reference['index'] >= 0;
				$prop_reference   = 1 === preg_match( '/^attrs\.props\.([A-Za-z_$][A-Za-z0-9_$]*)$/D', $reference['path'], $prop ) && ! array_key_exists( 'index', $reference );
				if ( ! $src_reference && ! $href_reference && ! $srcset_reference && ! $prop_reference || ! isset( $assets[ $reference['targetId'] ] ) ) {
					throw new \InvalidArgumentException( 'Invalid or unresolved media reference.' );
				}
				$content = self::entity_content( $entities, $reference['ownerId'] );
				if ( ! self::content_has_reference( $content, $reference['blockUid'], $reference['targetId'], $srcset_reference ? $reference['index'] : null, $prop_reference ? $prop[1] : null, $href_reference ) ) {
					throw new \InvalidArgumentException( 'Media reference does not match the document content.' );
				}
			} elseif ( 'document' === ( $reference['role'] ?? null ) ) {
		if ( 'attrs.ref' !== $reference['path'] || ! isset( $components[ $reference['targetId'] ] ) ) {
					throw new \InvalidArgumentException( 'Invalid or unresolved component reference.' );
				}
				$content = self::entity_content( $entities, $reference['ownerId'] );
				if ( ! self::content_has_component_reference( $content, $reference['blockUid'], $components[ $reference['targetId'] ] ) ) {
					throw new \InvalidArgumentException( 'Component reference does not match the document content.' );
				}
			} else {
				throw new \InvalidArgumentException( 'Unsupported reference role.' );
			}
			if ( isset( $reference['index'] ) && ( ! is_int( $reference['index'] ) || $reference['index'] < 0 ) ) {
				throw new \InvalidArgumentException( 'Invalid reference index.' );
			}
		}
		$reference_slots = array();
		foreach ( $references as $reference ) {
			$slot = $reference['ownerId'] . "\0" . $reference['blockUid'] . "\0" . $reference['path'] . "\0" . ( $reference['index'] ?? '' );
			if ( isset( $reference_slots[ $slot ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate reference slot.' );
			}
			$reference_slots[ $slot ] = $reference['targetId'];
		}
		foreach ( $entities as $entity ) {
			if ( ! in_array( $entity['kind'], array( 'document', 'component', 'template-part', 'template' ), true ) ) {
				continue;
			}
			foreach ( self::content_references( $entity['payload']['content'], $component_refs ) as $content_reference ) {
				$slot = $entity['id'] . "\0" . $content_reference['uid'] . "\0" . $content_reference['path'] . "\0" . ( $content_reference['index'] ?? '' );
				if ( ! isset( $reference_slots[ $slot ] ) || $reference_slots[ $slot ] !== $content_reference['targetId'] ) {
					throw new \InvalidArgumentException( 'A document URI is missing its exact structured reference.' );
				}
			}
		}
		self::assert_acyclic_component_references( $entities, $component_refs );
	}

	/**
	 * The orders a query takes besides a field of its type (doc 23 item 232): Publish's (prototype-import.ts,
	 * RECORD_ORDERS and POST_ORDERS). Any other name is a field, checked against its type in the graph.
	 */
	public const QUERY_ORDERS = array( 'order', 'publishedAt', 'title', 'slug', 'createdAt', 'updatedAt' );

	/** The field types a query cannot order by: Publish's rule (content-model.ts), the same in WordPress. */
	public const UNSORTABLE_FIELD_TYPES = array( 'relation', 'asset', 'rich-text' );

	/**
	 * Validate the portable CMS payloads before WordPress code sees any of their values.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function validate_relational_payload( string $kind, array $payload ): void {
		switch ( $kind ) {
			case 'content-type':
				self::assert_keys( $payload, array( 'key', 'label', 'fields' ), array( 'singleTemplateRef', 'archiveTemplateRef', 'public' ) );
				// Doc 23 item 122: only `false` is written (a type without pages of its own), and then with no templates.
				if ( array_key_exists( 'public', $payload ) && ( false !== $payload['public'] || isset( $payload['singleTemplateRef'] ) || isset( $payload['archiveTemplateRef'] ) ) ) {
					throw new \InvalidArgumentException( 'Invalid portable content type visibility.' );
				}
				if ( ! is_string( $payload['key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['key'] ) || in_array( $payload['key'], array( 'post', 'page' ), true ) || ! is_string( $payload['label'] ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 120 || ! is_array( $payload['fields'] ) || ! array_is_list( $payload['fields'] ) || count( $payload['fields'] ) > 64 ) {
					throw new \InvalidArgumentException( 'Invalid portable content type.' );
				}
				foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $template_key ) {
					if ( isset( $payload[ $template_key ] ) && ( ! is_string( $payload[ $template_key ] ) || strlen( $payload[ $template_key ] ) > 128 || 1 !== preg_match( '#^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:/[a-z][a-z0-9]*(?:-[a-z0-9]*)*)?$#D', $payload[ $template_key ] ) ) ) {
						throw new \InvalidArgumentException( 'Invalid portable content type template reference.' );
					}
				}
				$seen = array();
				foreach ( $payload['fields'] as $field ) {
					if ( ! is_array( $field ) ) {
						throw new \InvalidArgumentException( 'Invalid portable content field.' );
					}
					self::assert_keys( $field, array( 'key', 'label', 'type', 'required', 'public', 'multiple' ), array( 'options', 'targetType' ) );
				if ( ! is_string( $field['key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,62}$/D', $field['key'] ) || in_array( $field['key'], array( 'id', 'typeId', 'typeKey', 'slug', 'locale', 'title', 'status', 'publishedAt', 'excerpt', 'order', 'updatedAt', 'fields', 'terms', 'meta', 'url' ), true ) || isset( $seen[ $field['key'] ] ) || ! is_string( $field['label'] ) || '' === trim( $field['label'] ) || strlen( $field['label'] ) > 120 || ! in_array( $field['type'], array( 'text', 'rich-text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'enum', 'url', 'asset', 'relation' ), true ) || ! is_bool( $field['required'] ) || ! is_bool( $field['public'] ) || ! is_bool( $field['multiple'] ) ) {
						throw new \InvalidArgumentException( 'Invalid or duplicate portable content field.' );
					}
					$seen[ $field['key'] ] = true;
					if ( 'enum' === $field['type'] ) {
						if ( ! is_array( $field['options'] ?? null ) || ! array_is_list( $field['options'] ) || array() === $field['options'] || count( $field['options'] ) > 100 || count( array_unique( $field['options'] ) ) !== count( $field['options'] ) ) {
							throw new \InvalidArgumentException( 'Invalid portable enum field options.' );
						}
						foreach ( $field['options'] as $option ) {
							if ( ! is_string( $option ) || '' === $option || strlen( $option ) > 200 ) {
								throw new \InvalidArgumentException( 'Invalid portable enum field option.' );
							}
						}
					} elseif ( isset( $field['options'] ) ) {
						throw new \InvalidArgumentException( 'Options are valid only for enum fields.' );
					}
					if ( 'relation' === $field['type'] ? ! is_string( $field['targetType'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $field['targetType'] ) : isset( $field['targetType'] ) ) {
						throw new \InvalidArgumentException( 'Invalid portable relation field target.' );
					}
				}
				break;
			case 'content-record':
				self::assert_keys( $payload, array( 'typeKey', 'slug', 'locale', 'title', 'status', 'publishedAt', 'excerpt', 'order', 'values', 'terms' ) );
				if ( ! is_string( $payload['typeKey'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['typeKey'] ) || ! is_string( $payload['slug'] ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $payload['slug'] ) || strlen( $payload['slug'] ) > 63 || ! is_string( $payload['locale'] ) || strlen( $payload['locale'] ) > 64 || 1 !== preg_match( '/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $payload['locale'] ) || ! is_string( $payload['title'] ) || '' === trim( $payload['title'] ) || strlen( $payload['title'] ) > 240 || ! in_array( $payload['status'], array( 'draft', 'published', 'archived' ), true ) || ( null !== $payload['publishedAt'] && ( ! is_string( $payload['publishedAt'] ) || strlen( $payload['publishedAt'] ) > 40 || false === strtotime( $payload['publishedAt'] ) ) ) || ( 'published' === $payload['status'] ) !== ( null !== $payload['publishedAt'] ) || ! is_string( $payload['excerpt'] ) || strlen( $payload['excerpt'] ) > 20000 || ! is_int( $payload['order'] ) || abs( $payload['order'] ) > 9007199254740991 || ! is_array( $payload['values'] ) || array_is_list( $payload['values'] ) && array() !== $payload['values'] || ! is_array( $payload['terms'] ) ) {
					throw new \InvalidArgumentException( 'Invalid portable content record.' );
				}
				foreach ( $payload['terms'] as $taxonomy_key => $term_ids ) {
					if ( ! is_string( $taxonomy_key ) || ! is_array( $term_ids ) || ! array_is_list( $term_ids ) || count( $term_ids ) > 100 || count( array_unique( $term_ids ) ) !== count( $term_ids ) ) {
						throw new \InvalidArgumentException( 'Invalid portable record taxonomy assignment.' );
					}
					foreach ( $term_ids as $term_id ) {
						if ( ! self::is_uuid( $term_id ) ) {
							throw new \InvalidArgumentException( 'Invalid portable record term identity.' );
						}
					}
				}
				break;
			case 'taxonomy':
				self::assert_keys( $payload, array( 'key', 'label', 'types' ) );
				if ( ! is_string( $payload['key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['key'] ) || ! is_string( $payload['label'] ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 120 || ! is_array( $payload['types'] ) || ! array_is_list( $payload['types'] ) || count( array_unique( $payload['types'] ) ) !== count( $payload['types'] ) ) {
					throw new \InvalidArgumentException( 'Invalid portable taxonomy.' );
				}
				foreach ( $payload['types'] as $type_key ) {
					if ( ! is_string( $type_key ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $type_key ) ) {
						throw new \InvalidArgumentException( 'Invalid portable taxonomy content type.' );
					}
				}
				break;
			case 'term':
				self::assert_keys( $payload, array( 'taxonomyKey', 'slug', 'label', 'parentId' ) );
				if ( ! is_string( $payload['taxonomyKey'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['taxonomyKey'] ) || ! is_string( $payload['slug'] ) || '' === $payload['slug'] || strlen( $payload['slug'] ) > 63 || ! is_string( $payload['label'] ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 200 || ( null !== $payload['parentId'] && ! self::is_uuid( $payload['parentId'] ) ) ) {
					throw new \InvalidArgumentException( 'Invalid portable taxonomy term.' );
				}
				break;
			case 'query-preset':
				self::assert_keys( $payload, array( 'key', 'label', 'typeKey', 'limit', 'orderBy', 'order' ), array( 'limitParam', 'offset', 'offsetParam', 'filter' ) );
				if ( ! is_string( $payload['key'] ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $payload['key'] ) || in_array( $payload['key'], array( 'posts', 'terms', 'main', 'users' ), true ) || ! is_string( $payload['label'] ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 120 || ! is_string( $payload['typeKey'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['typeKey'] ) || ! is_int( $payload['limit'] ) || $payload['limit'] < 1 || $payload['limit'] > 100 || ! is_string( $payload['orderBy'] ) || ! in_array( $payload['orderBy'], self::QUERY_ORDERS, true ) && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,62}$/D', $payload['orderBy'] ) || ! in_array( $payload['order'], array( 'asc', 'desc' ), true ) || isset( $payload['limitParam'] ) && ( ! is_string( $payload['limitParam'] ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $payload['limitParam'] ) ) || isset( $payload['offset'] ) && ( ! is_int( $payload['offset'] ) || $payload['offset'] < 0 || $payload['offset'] > 1000000 ) || isset( $payload['offsetParam'] ) && ( ! is_string( $payload['offsetParam'] ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $payload['offsetParam'] ) ) ) {
					throw new \InvalidArgumentException( 'Invalid portable query preset.' );
				}
				if ( isset( $payload['filter'] ) && ( ! is_array( $payload['filter'] ) || array_is_list( $payload['filter'] ) ) ) {
					throw new \InvalidArgumentException( 'Invalid portable query filter.' );
				}
				break;
			case 'query-source':
				self::assert_keys( $payload, array( 'name', 'data' ) );
				if ( ! is_string( $payload['name'] ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $payload['name'] ) || in_array( $payload['name'], array( 'posts', 'terms', 'main', 'users' ), true ) || ! self::is_json_value( $payload['data'] ) || strlen( self::canonical( $payload['data'] ) ) > 262144 ) {
					throw new \InvalidArgumentException( 'Invalid portable query source.' );
				}
				break;
		}
	}

	/**
	 * Resolve every relation in the CMS graph before accepting the archive. A refusal says, in the site's language,
	 * which object of the package it is about and why (doc 23 item 233).
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	private static function validate_relational_graph( array $entities ): void {
		$at = null;
		try {
			self::validate_relational_graph_at( $entities, $at );
		} catch ( \InvalidArgumentException $error ) {
			throw new \InvalidArgumentException( self::refusal_at( $at, $error->getMessage() ), 0, $error );
		}
	}

	/**
	 * The graph check itself; `$at` is the object it is reading, for the message of a refusal.
	 *
	 * @param list<array<string, mixed>>  $entities
	 * @param array<string, mixed>|null   $at
	 */
	private static function validate_relational_graph_at( array $entities, ?array &$at ): void {
		$types = $taxonomies = $terms = $records = $templates = array();
		$assets = array();
		$query_names = array();
		$term_slugs = array();
		$source_bytes = 0;
		foreach ( $entities as $entity ) {
			$at = $entity;
			switch ( $entity['kind'] ) {
				case 'content-type':
					$key = $entity['payload']['key'];
					if ( isset( $types[ $key ] ) || isset( $taxonomies[ $key ] ) ) { throw new \InvalidArgumentException( 'Portable type and taxonomy keys must be unique.' ); }
					$types[ $key ] = $entity;
					break;
				case 'taxonomy':
					$key = $entity['payload']['key'];
					if ( isset( $types[ $key ] ) || isset( $taxonomies[ $key ] ) ) { throw new \InvalidArgumentException( 'Portable type and taxonomy keys must be unique.' ); }
					$taxonomies[ $key ] = $entity;
					break;
				case 'term': $terms[ $entity['id'] ] = $entity; break;
				case 'content-record': $records[ $entity['id'] ] = $entity; break;
				case 'asset': $assets[ $entity['id'] ] = true; break;
				case 'template':
					$ref = $entity['payload']['ref'];
					if ( isset( $templates[ $ref ] ) ) { throw new \InvalidArgumentException( 'Portable template references must be unique.' ); }
					$templates[ $ref ] = true;
					break;
				case 'query-preset':
					$key = $entity['payload']['key'];
					if ( isset( $query_names[ $key ] ) ) { throw new \InvalidArgumentException( 'A query and a data source cannot share a name.' ); }
					$query_names[ $key ] = true;
					break;
				case 'query-source':
					$key = $entity['payload']['name'];
					if ( isset( $query_names[ $key ] ) ) { throw new \InvalidArgumentException( 'A query and a data source cannot share a name.' ); }
					$query_names[ $key ] = true;
					$bytes = strlen( self::canonical( $entity['payload']['data'] ) );
					$source_bytes += $bytes;
					if ( $bytes > 262144 || $source_bytes > 1048576 ) { throw new \InvalidArgumentException( 'Portable data sources exceed the shared size limit.' ); }
			}
		}
		$at = null;
		if ( count( $types ) > 30 || count( $taxonomies ) > 30 || count( $query_names ) > 178 ) {
			throw new \InvalidArgumentException( 'Portable CMS profile exceeds the shared type or taxonomy limit.' );
		}
		$type_keys = array_keys( $types );
		$taxonomy_keys = array_keys( $taxonomies );
		$term_slug_keys = array();
		foreach ( $entities as $entity ) {
			$at      = $entity;
			$payload = $entity['payload'];
			if ( 'content-type' === $entity['kind'] ) {
				foreach ( array( 'singleTemplateRef', 'archiveTemplateRef' ) as $template_key ) {
					if ( isset( $payload[ $template_key ] ) && ! isset( $templates[ $payload[ $template_key ] ] ) ) {
						throw new \InvalidArgumentException( 'A portable content type references a template outside the profile.' );
					}
				}
				foreach ( $payload['fields'] as $field ) {
					if ( 'relation' === $field['type'] && ! isset( $types[ $field['targetType'] ] ) ) {
						throw new \InvalidArgumentException( 'A portable relation targets a content type outside the profile.' );
					}
				}
			}
			if ( 'taxonomy' === $entity['kind'] ) {
				foreach ( $payload['types'] as $type_key ) {
					if ( ! isset( $types[ $type_key ] ) && 'post' !== $type_key ) {
						throw new \InvalidArgumentException( 'A portable taxonomy references a content type outside the profile.' );
					}
				}
				if ( in_array( $payload['key'], self::NATIVE_TAXONOMIES, true ) && array( 'post' ) !== $payload['types'] ) {
					throw new \InvalidArgumentException( 'WordPress categories and tags belong to posts only.' );
				}
			}
			if ( 'document' === $entity['kind'] && 'post' === ( $payload['documentType'] ?? null ) ) {
				foreach ( $payload['terms'] as $taxonomy_key => $term_ids ) {
					$taxonomy = $taxonomies[ $taxonomy_key ] ?? null;
					if ( null === $taxonomy || ! in_array( 'post', $taxonomy['payload']['types'], true ) ) {
						throw new \InvalidArgumentException( 'A post taxonomy is not enabled for posts.' );
					}
					foreach ( $term_ids as $term_id ) {
						if ( ! isset( $terms[ $term_id ] ) || $terms[ $term_id ]['payload']['taxonomyKey'] !== $taxonomy_key ) {
							throw new \InvalidArgumentException( 'A post references a term outside its taxonomy.' );
						}
					}
				}
			}
			if ( 'term' === $entity['kind'] ) {
				if ( ! isset( $taxonomies[ $payload['taxonomyKey'] ] ) ) {
					throw new \InvalidArgumentException( 'A portable term references a taxonomy outside the profile.' );
				}
				$term_key = $payload['taxonomyKey'] . "\0" . $payload['slug'];
				if ( isset( $term_slug_keys[ $term_key ] ) ) { throw new \InvalidArgumentException( 'Duplicate portable taxonomy term slug.' ); }
				$term_slug_keys[ $term_key ] = true;
				if ( is_string( $payload['parentId'] ) && ( ! isset( $terms[ $payload['parentId'] ] ) || $terms[ $payload['parentId'] ]['payload']['taxonomyKey'] !== $payload['taxonomyKey'] ) ) {
					throw new \InvalidArgumentException( 'A portable term parent is missing or belongs to another taxonomy.' );
				}
			}
			if ( 'content-record' === $entity['kind'] ) {
				$type = $types[ $payload['typeKey'] ] ?? null;
				if ( null === $type ) {
					throw new \InvalidArgumentException( 'A portable content record references a type outside the profile.' );
				}
				$fields = array_column( $type['payload']['fields'], null, 'key' );
				foreach ( $fields as $key => $field ) {
					if ( ! empty( $field['required'] ) && ( ! array_key_exists( $key, $payload['values'] ) || null === $payload['values'][ $key ] || '' === $payload['values'][ $key ] || array() === $payload['values'][ $key ] ) ) {
						throw new \InvalidArgumentException( 'A portable record omits a required field.' );
					}
				}
				foreach ( $payload['values'] as $key => $value ) {
					$field = $fields[ $key ] ?? null;
					if ( null === $field ) {
						throw new \InvalidArgumentException( 'A portable content record contains an undeclared field.' );
					}
					$values = $field['multiple'] ? ( is_array( $value ) && array_is_list( $value ) ? $value : null ) : ( is_array( $value ) ? null : array( $value ) );
					if ( null === $values || count( $values ) > 1000 ) {
						throw new \InvalidArgumentException( 'A portable content field has the wrong cardinality.' );
					}
					foreach ( $values as $item ) {
						if ( null === $item || '' === $item ) {
							if ( ! empty( $field['required'] ) ) { throw new \InvalidArgumentException( 'A required portable content field is empty.' ); }
							continue;
						}
						self::validate_relational_value( $field, $item, $records, $assets, 'published' === $payload['status'] );
					}
				}
				foreach ( $payload['terms'] as $taxonomy_key => $term_ids ) {
					$taxonomy = $taxonomies[ $taxonomy_key ] ?? null;
					if ( null === $taxonomy || ! in_array( $payload['typeKey'], $taxonomy['payload']['types'], true ) ) {
						throw new \InvalidArgumentException( 'A portable record taxonomy is not enabled for its content type.' );
					}
					foreach ( $term_ids as $term_id ) {
						if ( ! isset( $terms[ $term_id ] ) || $terms[ $term_id ]['payload']['taxonomyKey'] !== $taxonomy_key ) {
							throw new \InvalidArgumentException( 'A portable record references a term outside its taxonomy.' );
						}
					}
				}
			}
			// Doc 23 item 232: a query ordered by a field orders by one of its type, public and with one value, as in
			// Publish; a field with more values has no first one WordPress could sort by.
			if ( 'query-preset' === $entity['kind'] && ! in_array( $payload['orderBy'], self::QUERY_ORDERS, true ) ) {
				$order_type  = $types[ $payload['typeKey'] ] ?? null;
				$order_field = null === $order_type ? null : ( array_column( $order_type['payload']['fields'], null, 'key' )[ $payload['orderBy'] ] ?? null );
				if ( null === $order_field || true !== $order_field['public'] || true === $order_field['multiple'] || in_array( $order_field['type'], self::UNSORTABLE_FIELD_TYPES, true ) ) {
					throw new \InvalidArgumentException( 'A portable query orders by a field its type does not have, or that cannot order it.' );
				}
			}
			if ( 'query-preset' === $entity['kind'] && isset( $payload['filter'] ) ) {
				// Posts are a type with no fields of their own: only taxonomy conditions apply to them.
				$filter_types = 'post' === $payload['typeKey'] ? array_merge( $types, array( 'post' => array( 'payload' => array( 'fields' => array() ) ) ) ) : $types;
				$type = $filter_types[ $payload['typeKey'] ] ?? null;
				if ( null === $type ) { throw new \InvalidArgumentException( 'A query preset references a content type outside the profile.' ); }
				self::validate_relational_filter( $payload['filter'], $filter_types, $payload['typeKey'], $taxonomies, $terms, $records, 0 );
			}
		}
		$seen = array();
		foreach ( $records as $record ) {
			$at = $record;
			$key = $record['payload']['typeKey'] . "\0" . strtolower( $record['payload']['locale'] ) . "\0" . $record['payload']['slug'];
			if ( isset( $seen[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate portable content type, locale and slug.' );
			}
			$seen[ $key ] = true;
		}
		foreach ( $terms as $term ) {
			$at = $term;
			$chain = array( $term['id'] => true );
			$parent_id = $term['payload']['parentId'];
			while ( is_string( $parent_id ) ) {
				if ( isset( $chain[ $parent_id ] ) ) { throw new \InvalidArgumentException( 'Portable taxonomy terms cannot contain a parent cycle.' ); }
				$chain[ $parent_id ] = true;
				$parent_id = $terms[ $parent_id ]['payload']['parentId'] ?? null;
			}
		}
		foreach ( $records as $record ) {
			$at = $record;
			$type = $types[ $record['payload']['typeKey'] ];
			foreach ( $type['payload']['fields'] as $field ) {
				if ( 'relation' !== $field['type'] || 'published' !== $record['payload']['status'] ) { continue; }
				$value = $record['payload']['values'][ $field['key'] ] ?? null;
				$targets = ! empty( $field['multiple'] ) && is_array( $value ) ? $value : array( $value );
				foreach ( $targets as $target_id ) {
					if ( is_string( $target_id ) && ( $records[ $target_id ]['payload']['status'] ?? null ) !== 'published' ) {
						throw new \InvalidArgumentException( 'A published record cannot refer to an unpublished record.' );
					}
				}
			}
		}
	}

	/**
	 * A refusal of the CMS part of a package as the person reads it (doc 23 item 233): the object, by its kind and
	 * name ("Query «prossimi_concerti»"), and the reason in the site's language, with what is allowed. A reason
	 * this list does not know keeps its own words.
	 *
	 * @param array<string, mixed>|null $entity
	 */
	public static function refusal_at( ?array $entity, string $message ): string {
		$payload = is_array( $entity['payload'] ?? null ) ? $entity['payload'] : array();
		$reason  = 'Invalid portable query preset.' === $message ? self::query_preset_problem( $payload ) : ( self::relational_reasons()[ $message ] ?? $message );
		if ( 'A portable query orders by a field its type does not have, or that cannot order it.' === $message ) {
			/* translators: 1: field key, 2: content type key */
			$reason = sprintf( __( 'orderBy «%1$s» non ordina il tipo %2$s: serve un campo del tipo, pubblico, con un solo valore e non relation, asset o rich-text.', 'xb' ), (string) ( $payload['orderBy'] ?? '' ), (string) ( $payload['typeKey'] ?? '' ) );
		}
		if ( null === $entity ) {
			return $reason;
		}
		$kinds = array(
			'content-type'   => __( 'Tipo', 'xb' ),
			'content-record' => __( 'Record', 'xb' ),
			'taxonomy'       => __( 'Tassonomia', 'xb' ),
			'term'           => __( 'Termine', 'xb' ),
			'query-preset'   => __( 'Query', 'xb' ),
			'query-source'   => __( 'Sorgente dati', 'xb' ),
			'document'       => __( 'Documento', 'xb' ),
		);
		$name = match ( true ) {
			is_string( $payload['typeKey'] ?? null ) && is_string( $payload['slug'] ?? null ) => $payload['typeKey'] . '/' . $payload['slug'],
			is_string( $payload['taxonomyKey'] ?? null ) && is_string( $payload['slug'] ?? null ) => $payload['taxonomyKey'] . '/' . $payload['slug'],
			is_string( $payload['key'] ?? null ) => $payload['key'],
			is_string( $payload['name'] ?? null ) => $payload['name'],
			is_string( $payload['slug'] ?? null ) => $payload['slug'],
			default => '',
		};
		/* translators: 1: kind of object (Query, Tipo…), 2: its name, 3: the reason */
		return sprintf( __( '%1$s «%2$s» non si può importare: %3$s', 'xb' ), $kinds[ (string) ( $entity['kind'] ?? '' ) ] ?? (string) ( $entity['kind'] ?? '' ), mb_substr( $name, 0, 120 ), $reason );
	}

	/**
	 * What is wrong with a query, property by property, in the order Publish checks them.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function query_preset_problem( array $payload ): string {
		$name = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';
		if ( ! is_string( $payload['key'] ?? null ) || 1 !== preg_match( $name, $payload['key'] ) || in_array( $payload['key'], array( 'posts', 'terms', 'main', 'users' ), true ) ) {
			return __( 'la chiave è fatta di lettere, cifre e _, e non può essere posts, terms, main o users.', 'xb' );
		}
		if ( ! is_string( $payload['label'] ?? null ) || '' === trim( $payload['label'] ) || strlen( $payload['label'] ) > 120 ) {
			return __( 'manca l\'etichetta, o supera 120 caratteri.', 'xb' );
		}
		if ( ! is_string( $payload['typeKey'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,62}$/D', $payload['typeKey'] ) ) {
			return __( 'il tipo non è una chiave valida.', 'xb' );
		}
		if ( ! is_int( $payload['limit'] ?? null ) || $payload['limit'] < 1 || $payload['limit'] > 100 ) {
			return __( 'limit è un numero intero da 1 a 100.', 'xb' );
		}
		if ( ! is_string( $payload['orderBy'] ?? null ) || ! in_array( $payload['orderBy'], self::QUERY_ORDERS, true ) && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,62}$/D', $payload['orderBy'] ) ) {
			/* translators: %s: the allowed orders */
			return sprintf( __( 'orderBy è uno fra %s, oppure la chiave di un campo del tipo.', 'xb' ), implode( ', ', self::QUERY_ORDERS ) );
		}
		if ( ! in_array( $payload['order'] ?? null, array( 'asc', 'desc' ), true ) ) {
			return __( 'order è asc o desc, in minuscolo.', 'xb' );
		}
		if ( isset( $payload['offset'] ) && ( ! is_int( $payload['offset'] ) || $payload['offset'] < 0 || $payload['offset'] > 1000000 ) ) {
			return __( 'offset è un numero intero da 0 a 1.000.000.', 'xb' );
		}
		return __( 'limitParam, offsetParam o le altre chiavi della query non sono valide.', 'xb' );
	}

	/**
	 * The reasons of the CMS checks in the site's language, by the words of the check.
	 *
	 * @return array<string, string>
	 */
	private static function relational_reasons(): array {
		$required = __( 'manca un campo obbligatorio.', 'xb' );
		$term     = __( 'usa un termine che non è della sua tassonomia.', 'xb' );
		$terms    = __( 'i termini assegnati non sono validi.', 'xb' );
		$type     = __( 'il suo tipo non è nel pacchetto.', 'xb' );
		$taxonomy = __( 'chiave, etichetta o tipi della tassonomia non sono validi.', 'xb' );
		$options  = __( 'le opzioni di un campo enum vanno da 1 a 100, tutte diverse, ognuna fino a 200 caratteri.', 'xb' );
		return array(
			'A portable content field has the wrong cardinality.'               => __( 'un campo ha un solo valore dove ne servono più, o il contrario.', 'xb' ),
			'A portable content field value does not match its declared type.'  => __( 'un campo ha un valore del tipo sbagliato.', 'xb' ),
			'A portable content record contains an undeclared field.'           => __( 'ha un campo che il suo tipo non dichiara.', 'xb' ),
			'A portable content record references a type outside the profile.' => $type,
			'A portable content type references a template outside the profile.' => __( 'usa un template che non è nel pacchetto.', 'xb' ),
			'A portable record omits a required field.'                         => $required,
			'A required portable content field is empty.'                       => $required,
			'A portable record references a term outside its taxonomy.'         => $term,
			'A post references a term outside its taxonomy.'                    => $term,
			'A portable record taxonomy is not enabled for its content type.'   => __( 'usa una tassonomia che non è collegata al suo tipo.', 'xb' ),
			'A post taxonomy is not enabled for posts.'                         => __( 'usa una tassonomia che non è collegata agli articoli.', 'xb' ),
			'A portable relation targets a content type outside the profile.'   => __( 'un campo relation punta a un tipo che non è nel pacchetto.', 'xb' ),
			'A portable taxonomy references a content type outside the profile.' => __( 'è collegata a un tipo che non è nel pacchetto.', 'xb' ),
			'A portable term parent is missing or belongs to another taxonomy.' => __( 'il termine genitore manca o è di un\'altra tassonomia.', 'xb' ),
			'A portable term references a taxonomy outside the profile.'        => __( 'la sua tassonomia non è nel pacchetto.', 'xb' ),
			'A published record cannot refer to an unpublished record.'         => __( 'è pubblicato, ma collega un record che non lo è.', 'xb' ),
			'A query and a data source cannot share a name.'                    => __( 'una query e una sorgente dati hanno lo stesso nome.', 'xb' ),
			'A query preset references a content type outside the profile.'    => $type,
			'Duplicate portable content type, locale and slug.'                 => __( 'due record dello stesso tipo e della stessa lingua hanno lo stesso slug.', 'xb' ),
			'Duplicate portable taxonomy term slug.'                            => __( 'due termini della stessa tassonomia hanno lo stesso slug.', 'xb' ),
			'Invalid or duplicate portable content field.'                      => __( 'un campo ha una chiave non valida, riservata o ripetuta, un tipo sconosciuto, o manca la sua etichetta.', 'xb' ),
			'Invalid portable content field.'                                   => __( 'un campo non è scritto come oggetto.', 'xb' ),
			'Invalid portable content record.'                                  => __( 'tipo, slug, lingua, titolo, stato, data, riassunto, ordine o valori non sono validi.', 'xb' ),
			'Invalid portable content type.'                                    => __( 'chiave, etichetta o campi non sono validi: la chiave non può essere post o page, e i campi sono al più 64.', 'xb' ),
			'Invalid portable content type template reference.'                 => __( 'il riferimento a un template non è valido.', 'xb' ),
			'Invalid portable content type visibility.'                         => __( 'un tipo senza pagine proprie non ha template.', 'xb' ),
			'Invalid portable enum field option.'                               => $options,
			'Invalid portable enum field options.'                              => $options,
			'Invalid portable query filter.'                                    => __( 'il filtro non è scritto come oggetto.', 'xb' ),
			'Invalid portable query source.'                                    => __( 'il nome o i dati della sorgente non sono validi (al più 256 KB).', 'xb' ),
			'Invalid portable record taxonomy assignment.'                      => $terms,
			'Invalid portable record term identity.'                            => $terms,
			'Invalid portable relation field target.'                           => __( 'un campo relation non dice a quale tipo punta.', 'xb' ),
			'Invalid portable taxonomy.'                                        => $taxonomy,
			'Invalid portable taxonomy content type.'                           => $taxonomy,
			'Invalid portable taxonomy term.'                                   => __( 'tassonomia, slug, etichetta o genitore del termine non sono validi.', 'xb' ),
			'Options are valid only for enum fields.'                           => __( 'solo un campo enum ha opzioni.', 'xb' ),
			'Portable CMS profile exceeds the shared type or taxonomy limit.'   => __( 'il pacchetto supera il limite di 30 tipi, 30 tassonomie o 178 query e sorgenti.', 'xb' ),
			'Portable data sources exceed the shared size limit.'               => __( 'le sorgenti dati superano 256 KB l\'una o 1 MB in tutto.', 'xb' ),
			'Portable taxonomy terms cannot contain a parent cycle.'            => __( 'i termini formano un giro di genitori.', 'xb' ),
			'Portable template references must be unique.'                      => __( 'due template hanno lo stesso riferimento.', 'xb' ),
			'Portable type and taxonomy keys must be unique.'                   => __( 'due tipi o tassonomie hanno la stessa chiave.', 'xb' ),
			'WordPress categories and tags belong to posts only.'               => __( 'le categorie e i tag di WordPress valgono solo per gli articoli.', 'xb' ),
		);
	}

	/** Validate a portable field value using the same types and bounds as Histeon CMS. */
	/**
	 * @param array<string, mixed>                $field
	 * @param array<string, array<string, mixed>> $records
	 * @param array<string, true>                 $assets
	 */
	private static function validate_relational_value( array $field, mixed $item, array $records, array $assets, bool $published_record ): void {
		$type = $field['type'];
		$valid = match ( $type ) {
			'text', 'rich-text' => is_string( $item ) && strlen( $item ) <= 1000000,
			'integer' => is_int( $item ) && abs( $item ) <= 9007199254740991,
			'decimal' => ( is_int( $item ) || is_float( $item ) ) && is_finite( (float) $item ),
			'boolean' => is_bool( $item ),
			'date' => self::valid_iso_date( $item ),
			'datetime' => self::valid_iso_datetime( $item ),
			'enum' => is_string( $item ) && in_array( $item, $field['options'], true ),
			'url' => is_string( $item ) && strlen( $item ) <= 2048 && ( str_starts_with( $item, '/' ) && ! str_starts_with( $item, '//' ) || 1 === preg_match( '#^https?://#i', $item ) ),
			'asset' => is_string( $item ) && isset( $assets[ $item ] ),
			'relation' => is_string( $item ) && isset( $records[ $item ] ) && $records[ $item ]['payload']['typeKey'] === $field['targetType'] && ( ! $published_record || 'published' === $records[ $item ]['payload']['status'] ),
			default => false,
		};
		if ( ! $valid ) { throw new \InvalidArgumentException( 'A portable content field value does not match its declared type.' ); }
	}

	/**
	 * The leaf conditions of a query filter, counted until the limit is passed (then limit + 1); groups beyond the
	 * depth of 4 count as leaves (the validation refuses them anyway).
	 */
	public static function filter_leaves( mixed $filter, int $limit = self::MAX_FILTER_LEAVES, int $depth = 0 ): int {
		if ( $depth > 4 || ! is_array( $filter ) || ! ( isset( $filter['and'] ) || isset( $filter['or'] ) ) ) {
			return 1;
		}
		$group = $filter['and'] ?? $filter['or'];
		if ( ! is_array( $group ) ) {
			return 1;
		}
		$total = 0;
		foreach ( $group as $child ) {
			$total += self::filter_leaves( $child, $limit - $total, $depth + 1 );
			if ( $total > $limit ) {
				return $total;
			}
		}
		return $total;
	}

	private static function valid_iso_date( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) );
	}

	private static function valid_iso_datetime( mixed $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,9})?(?:Z|[+-](?:0\d|1\d|2[0-3]):[0-5]\d)$/D', $value ) || ! self::valid_iso_date( substr( $value, 0, 10 ) ) ) { return false; }
		try { new \DateTimeImmutable( $value ); return true; } catch ( \Throwable ) { return false; }
	}

	/**
	 * @param array<string, array<string, mixed>> $types
	 * @param array<string, array<string, mixed>> $taxonomies
	 * @param array<string, array<string, mixed>> $terms
	 * @param array<string, array<string, mixed>> $records
	 */
	private static function validate_relational_filter( mixed $filter, array $types, string $type_key, array $taxonomies, array $terms, array $records, int $depth ): void {
		if ( ! is_array( $filter ) || array_is_list( $filter ) || $depth > 4 ) { throw new \InvalidArgumentException( 'A portable query filter is invalid or too deeply nested.' ); }
		if ( 0 === $depth && self::filter_leaves( $filter ) > self::MAX_FILTER_LEAVES ) { throw new \InvalidArgumentException( 'A portable query filter has more than ' . self::MAX_FILTER_LEAVES . ' conditions in all.' ); }
		if ( 1 === count( $filter ) && ( isset( $filter['and'] ) || isset( $filter['or'] ) ) ) {
			$group = $filter['and'] ?? $filter['or'];
			if ( ! is_array( $group ) || ! array_is_list( $group ) || array() === $group || count( $group ) > 32 ) { throw new \InvalidArgumentException( 'A portable query filter group must contain 1 to 32 conditions.' ); }
			foreach ( $group as $child ) { self::validate_relational_filter( $child, $types, $type_key, $taxonomies, $terms, $records, $depth + 1 ); }
			return;
		}
		if ( isset( $filter['taxonomyKey'] ) ) {
			$taxonomy = is_string( $filter['taxonomyKey'] ) ? ( $taxonomies[ $filter['taxonomyKey'] ] ?? null ) : null;
			$ids = $filter['termIds'] ?? null;
			if ( array_diff( array_keys( $filter ), array( 'taxonomyKey', 'termIds', 'operator' ) ) || null === $taxonomy || ! in_array( $type_key, $taxonomy['payload']['types'], true ) || ! is_array( $ids ) || ! array_is_list( $ids ) || array() === $ids || count( $ids ) > 100 || ! in_array( $filter['operator'] ?? null, array( 'IN', 'NOT IN', 'AND' ), true ) ) { throw new \InvalidArgumentException( 'A portable taxonomy query filter is invalid.' ); }
			foreach ( $ids as $id ) { if ( ! is_string( $id ) || ! isset( $terms[ $id ] ) || $terms[ $id ]['payload']['taxonomyKey'] !== $filter['taxonomyKey'] ) { throw new \InvalidArgumentException( 'A portable taxonomy query filter points outside its taxonomy.' ); } }
			return;
		}
	$field = null;
	$field_path = is_string( $filter['field'] ?? null ) ? explode( '.', $filter['field'] ) : array();
	$current_type_key = $type_key;
	if ( array() !== $field_path && count( $field_path ) <= 4 && count( array_filter( $field_path, static fn ( string $key ): bool => 1 !== preg_match( '/^[a-z][a-z0-9_]{0,62}$/D', $key ) ) ) === 0 ) {
		foreach ( $field_path as $index => $field_key ) {
			$current_type = $types[ $current_type_key ] ?? null;
			$candidate = null;
			foreach ( $current_type['payload']['fields'] ?? array() as $current_field ) { if ( $current_field['key'] === $field_key ) { $candidate = $current_field; break; } }
			if ( null === $candidate || empty( $candidate['public'] ) ) { $field = null; break; }
			if ( $index < count( $field_path ) - 1 ) {
				if ( 'relation' !== $candidate['type'] || ! is_string( $candidate['targetType'] ?? null ) || ! isset( $types[ $candidate['targetType'] ] ) ) { $field = null; break; }
				$current_type_key = $candidate['targetType'];
			} else { $field = $candidate; }
		}
	}
		$operator = $filter['op'] ?? null;
		if ( array_diff( array_keys( $filter ), array( 'field', 'op', 'value' ) ) || null === $field || empty( $field['public'] ) || ! in_array( $operator, array( 'eq', 'ne', 'in', 'lt', 'lte', 'gt', 'gte', 'contains', 'exists' ), true ) || ( 'exists' === $operator && array_key_exists( 'value', $filter ) ) || ( 'exists' !== $operator && ! array_key_exists( 'value', $filter ) ) ) { throw new \InvalidArgumentException( 'A portable query field filter is invalid or private.' ); }
		if ( 'relation' === $field['type'] ) {
			if ( 'exists' === $operator ) { return; }
			$target_id = $filter['value'] ?? null;
			$target = is_string( $target_id ) ? ( $records[ $target_id ] ?? null ) : null;
			if ( 'eq' !== $operator || ! empty( $field['multiple'] ) || ! is_string( $target_id ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $target_id ) || null === $target || $target['payload']['typeKey'] !== $field['targetType'] || 'published' !== $target['payload']['status'] ) { throw new \InvalidArgumentException( 'A portable query relation filter must reference a published record of the declared target type.' ); }
			return;
		}
		if ( ! empty( $field['multiple'] ) || in_array( $field['type'], array( 'asset', 'rich-text' ), true ) ) { throw new \InvalidArgumentException( 'A portable query field filter is invalid or private.' ); }
		if ( 'exists' === $operator ) {
			return;
		}
		$values = 'in' === $operator ? $filter['value'] : array( $filter['value'] ?? null );
		if ( 'in' === $operator && ( ! is_array( $values ) || ! array_is_list( $values ) || array() === $values || count( $values ) > 100 ) ) { throw new \InvalidArgumentException( 'A portable query in-filter must contain 1 to 100 values.' ); }
		foreach ( $values as $value ) {
			$valid = in_array( $field['type'], array( 'integer', 'decimal' ), true ) ? ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) : ( 'boolean' === $field['type'] ? is_bool( $value ) : is_string( $value ) && strlen( $value ) <= 4096 );
			if ( ! $valid || 'enum' === $field['type'] && ! in_array( $value, $field['options'], true ) ) { throw new \InvalidArgumentException( 'A portable query filter value does not match its field type.' ); }
		}
	}

	private static function is_json_value( mixed $value, int $depth = 0 ): bool {
		if ( $depth > 24 ) {
			return false;
		}
		if ( null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) && is_finite( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( count( $value ) > 1000 ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( $key, array( '__proto__', 'prototype', 'constructor' ), true ) || ! self::is_json_value( $item, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	private static function canonical_locale( string $locale ): string {
		$parts = explode( '-', $locale );
		foreach ( $parts as $index => &$part ) {
			$part = 0 === $index ? strtolower( $part ) : ( 4 === strlen( $part ) && ctype_alpha( $part ) ? ucfirst( strtolower( $part ) ) : ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ? strtoupper( $part ) : strtolower( $part ) ) );
		}
		unset( $part );
		return implode( '-', $parts );
	}

	/**
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, string>       $component_refs
	 */
	private static function assert_acyclic_component_references( array $entities, array $component_refs ): void {
		$graph     = array();
		foreach ( $entities as $entity ) {
			if ( ! in_array( $entity['kind'] ?? null, array( 'component', 'template-part' ), true ) ) {
				continue;
			}
			$owner = (string) $entity['id'];
			$graph[ $owner ] = array();
			foreach ( self::content_references( (string) $entity['payload']['content'], $component_refs ) as $reference ) {
				if ( 'attrs.ref' === $reference['path'] ) {
					$graph[ $owner ][] = $reference['targetId'];
				}
			}
		}
		$visiting = array();
		$visited  = array();
		$visit    = static function ( string $id ) use ( &$visit, &$visiting, &$visited, $graph ): void {
			if ( isset( $visited[ $id ] ) ) {
				return;
			}
			if ( isset( $visiting[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Histeon component and template-part references cannot be recursive.' );
			}
			$visiting[ $id ] = true;
			foreach ( $graph[ $id ] ?? array() as $target ) {
				$visit( $target );
			}
			unset( $visiting[ $id ] );
			$visited[ $id ] = true;
		};
		foreach ( array_keys( $graph ) as $id ) {
			$visit( $id );
		}
	}

	private static function valid_prop_default( string $type, mixed $value ): bool {
		return match ( $type ) {
			'string', 'url', 'image', 'rich-text', 'enum' => is_string( $value ),
			'number' => ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ) && array_is_list( $value ),
			'object' => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
			default => false,
		};
	}

	/** @param list<array<string, mixed>> $entities */
	private static function entity_content( array $entities, string $id ): string {
		foreach ( $entities as $entity ) {
			if ( $id === ( $entity['id'] ?? null ) && in_array( $entity['kind'] ?? null, array( 'document', 'component', 'template-part', 'template' ), true ) ) {
				return (string) ( $entity['payload']['content'] ?? '' );
			}
		}
		return '';
	}

	/** Whether the block's `src` (no index), the `srcset` candidate at `$index`, the prop `$prop` or, with `$href`, the link of an `a` is the media identity of the target. */
	private static function content_has_reference( string $content, string $block_uid, string $target_id, ?int $index = null, ?string $prop = null, bool $href = false ): bool {
		foreach ( self::content_block_attributes( $content ) as $attrs ) {
			if ( ( $attrs['uid'] ?? null ) !== $block_uid ) {
				continue;
			}
			if ( $href ) {
				if ( 'a' === ( $attrs['tag'] ?? null ) && ( $attrs['attributes']['href'] ?? null ) === 'histos-media://' . $target_id ) {
					return true;
				}
				continue;
			}
			if ( null !== $prop ) {
				if ( ( $attrs['props'][ $prop ] ?? null ) === 'histos-media://' . $target_id ) {
					return true;
				}
				continue;
			}
			if ( null === $index && ( $attrs['attributes']['src'] ?? null ) === 'histos-media://' . $target_id ) {
				return true;
			}
			$srcset     = $attrs['attributes']['srcset'] ?? null;
			$candidates = null !== $index && is_string( $srcset ) ? TransferMarkup::parse_srcset( $srcset ) : null;
			if ( null !== $candidates && ( $candidates[ $index ]['url'] ?? null ) === 'histos-media://' . $target_id ) {
				return true;
			}
		}
		return false;
	}

	private static function content_has_component_reference( string $content, string $block_uid, string $target_ref ): bool {
		foreach ( self::content_block_attributes( $content ) as $attrs ) {
			if ( ( $attrs['uid'] ?? null ) === $block_uid && ( $attrs['ref'] ?? null ) === $target_ref ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every media identity and component reference written in the content, each of which needs its exact structured
	 * reference: `src`, each `srcset` candidate of `img` and `source` (with its index), and `ref`.
	 *
	 * @param array<string, string> $component_refs
	 * @return list<array{uid: string, targetId: string, path: string, index?: int}>
	 */
	private static function content_references( string $content, array $component_refs ): array {
		$references = array();
		foreach ( self::content_block_attributes( $content ) as $attrs ) {
			$source = $attrs['attributes']['src'] ?? null;
			if ( is_string( $source ) && str_starts_with( $source, 'histos-media://' ) ) {
				$uid    = $attrs['uid'] ?? null;
				$target = substr( $source, strlen( 'histos-media://' ) );
				if ( ! is_string( $uid ) || ! self::is_uuid( $target ) ) {
					throw new \InvalidArgumentException( 'Page media URI has an invalid block UID or target identity.' );
				}
				$references[] = array( 'uid' => $uid, 'targetId' => $target, 'path' => 'attrs.attributes.src' );
			}
			$link = $attrs['attributes']['href'] ?? null;
			if ( 'a' === ( $attrs['tag'] ?? null ) && is_string( $link ) && str_starts_with( $link, 'histos-media://' ) ) {
				$uid    = $attrs['uid'] ?? null;
				$target = substr( $link, strlen( 'histos-media://' ) );
				if ( ! is_string( $uid ) || ! self::is_uuid( $target ) ) {
					throw new \InvalidArgumentException( 'Page media link has an invalid block UID or target identity.' );
				}
				$references[] = array( 'uid' => $uid, 'targetId' => $target, 'path' => 'attrs.attributes.href' );
			}
			foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $prop => $value ) {
				if ( is_string( $value ) && str_starts_with( $value, 'histos-media://' ) ) {
					$uid    = $attrs['uid'] ?? null;
					$target = substr( $value, strlen( 'histos-media://' ) );
					if ( ! is_string( $uid ) || ! self::is_uuid( $target ) ) {
						throw new \InvalidArgumentException( 'Page media URI has an invalid block UID or target identity.' );
					}
					$references[] = array( 'uid' => $uid, 'targetId' => $target, 'path' => 'attrs.props.' . $prop );
				}
			}
			$srcset = $attrs['attributes']['srcset'] ?? null;
			if ( in_array( $attrs['tag'] ?? null, array( 'img', 'source' ), true ) && is_string( $srcset ) && str_contains( $srcset, 'histos-media://' ) ) {
				$uid        = $attrs['uid'] ?? null;
				$candidates = TransferMarkup::parse_srcset( $srcset );
				if ( ! is_string( $uid ) || null === $candidates ) {
					throw new \InvalidArgumentException( 'Page media srcset has an invalid block UID or is not a portable candidate list.' );
				}
				foreach ( $candidates as $index => $candidate ) {
					if ( ! str_starts_with( $candidate['url'], 'histos-media://' ) ) {
						continue;
					}
					$target = substr( $candidate['url'], strlen( 'histos-media://' ) );
					if ( ! self::is_uuid( $target ) ) {
						throw new \InvalidArgumentException( 'Page media srcset has an invalid target identity.' );
					}
					$references[] = array( 'uid' => $uid, 'targetId' => $target, 'path' => 'attrs.attributes.srcset', 'index' => $index );
				}
			}
			$ref = $attrs['ref'] ?? null;
			if ( is_string( $ref ) ) {
				$uid = $attrs['uid'] ?? null;
				if ( ! is_string( $uid ) || '' === $ref || ! isset( $component_refs[ $ref ] ) ) {
					throw new \InvalidArgumentException( 'Component reference has an invalid block UID or ref.' );
				}
				$references[] = array( 'uid' => $uid, 'targetId' => $component_refs[ $ref ], 'path' => 'attrs.ref' );
			}
		}
		return $references;
	}

	/** @return list<array<string, mixed>> */
	private static function content_block_attributes( string $content ): array {
		if ( false === preg_match_all( '/<!--\s*wp:xb\/(?:element|text|component)\s+(.+?)\s*\/?-->/s', $content, $matches ) ) {
			return array();
		}
		$blocks = array();
		foreach ( $matches[1] as $json ) {
			$json = trim( $json );
			if ( str_ends_with( $json, '/' ) ) {
				$json = rtrim( substr( $json, 0, -1 ) );
			}
			$attrs = json_decode( $json, true );
			if ( is_array( $attrs ) ) {
				$blocks[] = $attrs;
			}
		}
		return $blocks;
	}

	/**
	 * A document's JavaScript in a transfer (doc 18 §6.6 of Histeon Publish): text within Scripts::MAX_BYTES and
	 * without NUL. A non-empty one travels only under SCRIPT_CAPABILITY, and the import stores it only for a user with
	 * unfiltered_html (PageProfileAdapter).
	 */
	private static function valid_script( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= \XB\Scripts\Scripts::MAX_BYTES && ! str_contains( $value, "\0" );
	}

	/**
	 * Whether any entity of the transfer (tombstones aside) carries JavaScript.
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	public static function uses_scripts( array $entities ): bool {
		foreach ( $entities as $entity ) {
			if ( 'tombstone' !== ( $entity['kind'] ?? null ) && is_array( $entity['payload'] ?? null ) && is_string( $entity['payload']['script'] ?? null ) && '' !== $entity['payload']['script'] ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a template's content has the page's default slot, which every transferred template needs. */
	public static function has_default_slot( string $content ): bool {
		return 1 === preg_match( '/<!--\s*wp:xb\/slot\s+\{[^}]*"name"\s*:\s*"default"[^}]*\}\s*\/-->/', $content );
	}

	/**
	 * @param array<string, mixed> $object
	 * @param list<string>         $required
	 * @param list<string>         $optional
	 */
	private static function assert_keys( array $object, array $required, array $optional = array() ): void {
		$keys = array_keys( $object );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $object ) ) {
				throw new \InvalidArgumentException( 'Missing profile field: ' . $key );
			}
		}
		if ( array_diff( $keys, array_merge( $required, $optional ) ) ) {
			throw new \InvalidArgumentException( 'Unknown profile field.' );
		}
	}

	private static function is_uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
	}

	/**
	 * @param array<string, mixed>       $manifest
	 * @param list<array<string, mixed>> $entities
	 * @param list<array<string, mixed>> $references
	 * @param array<string, string>      $files
	 */
	public static function archive( array $manifest, array $entities, array $references, array $files ): string {
		self::validate( $manifest, $entities, $references, $files );
		$temporary = tempnam( sys_get_temp_dir(), 'xb-page-profile-' );
		if ( false === $temporary ) {
			throw new \RuntimeException( 'Cannot create a temporary transfer archive.' );
		}
		self::forget_at_shutdown( $temporary );
		$zip  = new \ZipArchive();
		$open = false;
		try {
			$open = true === $zip->open( $temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
			if ( ! $open ) {
				throw new \RuntimeException( 'Cannot open the temporary transfer archive.' );
			}
			$zip->addFromString( 'site.json', self::canonical( $manifest ) );
			$zip->addFromString( 'entities.ndjson', array() === $entities ? '' : implode( "\n", array_map( self::canonical(...), $entities ) ) . "\n" );
			$zip->addFromString( 'references.ndjson', array() === $references ? '' : implode( "\n", array_map( self::canonical(...), $references ) ) . "\n" );
			ksort( $files, SORT_STRING );
			foreach ( $files as $path => $bytes ) {
				if ( in_array( $path, array( 'entities.ndjson', 'references.ndjson' ), true ) ) {
					continue;
				}
				$zip->addFromString( $path, $bytes );
			}
			$open = false;
			if ( ! $zip->close() ) {
				throw new \RuntimeException( 'Cannot write the temporary transfer archive.' );
			}
			$bytes = file_get_contents( $temporary );
			if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_ARCHIVE_BYTES ) {
				throw new \RuntimeException( 'Transfer archive exceeds the size limit.' );
			}
			return $bytes;
		} finally {
			if ( $open ) {
				$zip->close();
			}
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- own temporary file.
		}
	}

	/**
	 * A request that dies of memory or time skips `finally`: the copy of the archive (a whole project) is removed at
	 * shutdown all the same.
	 */
	private static function forget_at_shutdown( string $temporary ): void {
		register_shutdown_function(
			static function () use ( $temporary ): void {
				if ( is_file( $temporary ) ) {
					@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- own temporary file.
				}
			}
		);
	}

	/**
	 * Parse a ZIP archive and reject path traversal, duplicate entries, symlinks and undeclared bytes.
	 *
	 * @return array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>}
	 */
	public static function decode( string $bytes ): array {
		if ( strlen( $bytes ) > self::MAX_ARCHIVE_BYTES ) {
			throw new \InvalidArgumentException( 'Transfer archive exceeds the size limit.' );
		}
		$temporary = tempnam( sys_get_temp_dir(), 'xb-page-profile-' );
		if ( false !== $temporary ) {
			self::forget_at_shutdown( $temporary );
		}
		if ( false === $temporary || false === file_put_contents( $temporary, $bytes ) ) {
			throw new \RuntimeException( 'Cannot stage the transfer archive.' );
		}
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			$opened = true === $zip->open( $temporary, \ZipArchive::RDONLY );
			if ( ! $opened || $zip->numFiles > self::MAX_ENTRIES ) {
				throw new \InvalidArgumentException( 'Transfer ZIP is unreadable or contains too many entries.' );
			}
			$files = array();
			$seen  = array();
			$total = 0;
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( ! is_array( $stat ) ) {
					throw new \InvalidArgumentException( 'Transfer ZIP entry is unreadable.' );
				}
				$name = (string) $stat['name'];
				if ( '' === $name || str_contains( $name, '\\' ) || str_starts_with( $name, '/' ) || str_ends_with( $name, '/' ) || array_intersect( explode( '/', $name ), array( '', '.', '..' ) ) || 1 !== preg_match( '#^(?:site\.json|entities\.ndjson|references\.ndjson|media/[0-9a-f]{64})$#D', $name ) ) {
					throw new \InvalidArgumentException( 'Transfer ZIP contains an unsafe or unsupported path.' );
				}
				$folded = strtolower( $name );
				if ( isset( $seen[ $folded ] ) ) {
					throw new \InvalidArgumentException( 'Transfer ZIP contains duplicate paths.' );
				}
				$seen[ $folded ] = true;
				$opsys = 0;
				$attrs = 0;
				if ( $zip->getExternalAttributesIndex( $index, $opsys, $attrs ) && 0120000 === ( ( $attrs >> 16 ) & 0170000 ) ) {
					throw new \InvalidArgumentException( 'Transfer ZIP contains a symbolic link.' );
				}
				$size = $stat['size'];
				$compressed = $stat['comp_size'];
				if ( $size < 0 || $size > self::MAX_ARCHIVE_BYTES || $total + $size > self::MAX_ARCHIVE_BYTES || $size > 1048576 && ( 0 === $compressed || $size / $compressed > 1000 ) ) {
					throw new \InvalidArgumentException( 'Transfer ZIP contains an oversized or highly compressed file.' );
				}
				$content = $zip->getFromIndex( $index );
				if ( ! is_string( $content ) || strlen( $content ) !== $size ) {
					throw new \InvalidArgumentException( 'Transfer ZIP entry is unreadable.' );
				}
				$total += $size;
				$files[ $name ] = $content;
			}
		} finally {
			// Closing a ZIP that never opened throws a ValueError, which would hide the real refusal.
			if ( $opened ) {
				$zip->close();
			}
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- own temporary file.
		}
		$manifest = self::decode_json( $files['site.json'] ?? '' );
		if ( ! is_array( $manifest ) || self::canonical( $manifest ) !== ( $files['site.json'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Transfer manifest is not canonical JSON.' );
		}
		$entities   = self::decode_ndjson( $files['entities.ndjson'] ?? '', true );
		$references = self::decode_ndjson( $files['references.ndjson'] ?? '', false );
		$declared_files = $files;
		unset( $declared_files['site.json'] );
		self::validate( $manifest, $entities, $references, $declared_files );
		$media_files = array_diff_key( $files, array( 'site.json' => true, 'entities.ndjson' => true, 'references.ndjson' => true ) );
		return array( 'manifest' => $manifest, 'entities' => $entities, 'references' => $references, 'files' => $media_files );
	}

	private static function decode_json( string $bytes ): mixed {
		try {
			return json_decode( $bytes, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $e ) {
			throw new \InvalidArgumentException( 'Transfer archive contains invalid JSON.', 0, $e );
		}
	}

	/** @return list<array<string, mixed>> */
	private static function decode_ndjson( string $bytes, bool $required ): array {
		if ( '' === $bytes ) {
			if ( $required ) {
				throw new \InvalidArgumentException( 'Required NDJSON file is empty.' );
			}
			return array();
		}
		if ( "\n" !== substr( $bytes, -1 ) || str_contains( $bytes, "\r" ) ) {
			throw new \InvalidArgumentException( 'NDJSON must use canonical UTF-8 lines ending with LF.' );
		}
		$rows = array();
		foreach ( explode( "\n", substr( $bytes, 0, -1 ) ) as $line ) {
			$value = self::decode_json( $line );
			if ( ! is_array( $value ) || self::canonical( $value ) !== $line ) {
				throw new \InvalidArgumentException( 'NDJSON contains a non-canonical or non-object row.' );
			}
			$rows[] = $value;
		}
		return $rows;
	}
}
