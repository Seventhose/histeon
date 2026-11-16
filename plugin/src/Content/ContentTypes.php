<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Content;

/**
 * Content Hub — docs/spec/content.md. Content types, taxonomies and fields an administrator defines in the
 * builder. The definitions live in the `settings` document (versions, preview, releases); WordPress registers
 * what the live release holds, so a type exists on the site once it is published.
 *
 * Everything here is data an administrator wrote: it is checked against fixed lists (no free-form argument
 * reaches register_post_type), and names that WordPress or another plugin already uses are refused.
 *
 * @phpstan-type SubFieldDef array{name: string, label: string, type: string, options: list<array{value: string, label: string}>, sub?: list<array{name: string, label: string, type: string, options: list<array{value: string, label: string}>}>}
 * @phpstan-type FieldDef array{name: string, label: string, type: string, help: string, options: list<array{value: string, label: string}>, postType: string, sub: list<SubFieldDef>, required: bool, public: bool, multiple: bool, radio?: bool, group?: string}
 * @phpstan-type TypeDef array{singular: string, plural: string, public: bool, archive: bool, slug: string, hierarchical: bool, icon: string, supports: list<string>, capability: string}
 * @phpstan-type TaxonomyDef array{singular: string, plural: string, public: bool, hierarchical: bool, slug: string, types: list<string>}
 * @phpstan-type GroupDef array{label: string, all: bool, types: list<string>, except: list<string>, fields: list<FieldDef>}
 * @phpstan-type Definitions array{types: array<string, TypeDef>, taxonomies: array<string, TaxonomyDef>, fields: array<string, list<FieldDef>>, groups?: array<string, GroupDef>}
 */
final class ContentTypes {

	public const OPTION         = 'xb_content';
	private const REWRITE       = 'xb_content_rewrite';
	public const MAX_TYPES      = 30;
	public const MAX_TAXONOMIES = 30;
	public const MAX_FIELDS     = 50;
	/** Groups of fields shared by several types (doc 26 voce 213). */
	public const MAX_GROUPS     = 20;
	private const MAX_OPTIONS   = 100;
	private const MAX_LABEL     = 60;
	private const MAX_HELP      = 200;
	private const MAX_TEXT      = 10000;
	public const MAX_SUB        = 20;
	public const MAX_ROWS       = 200;
	/** Rows of a repeater inside a row (D6): fewer, since each outer row has its own. */
	public const MAX_INNER_ROWS = 50;

	public const FIELD_TYPES = array( 'text', 'textarea', 'rich-text', 'number', 'integer', 'decimal', 'url', 'email', 'date', 'datetime', 'time', 'toggle', 'select', 'image', 'file', 'post', 'repeater' );
	/** What a repeater row may hold: one value each, or one more repeater (two levels at most, D6); never a relation. */
	public const SUB_TYPES = array( 'text', 'textarea', 'number', 'date', 'time', 'toggle', 'select', 'url', 'image', 'file', 'repeater' );
	public const SUPPORTS    = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'revisions', 'page-attributes' );

	/** Names WordPress uses for its own types, query variables and routes: never a new type or taxonomy. */
	private const RESERVED = array(
		'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
		'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family',
		'wp_font_face', 'action', 'author', 'order', 'theme', 'type', 'fields', 'category', 'post_tag', 'nav_menu',
		'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'attachment_id', 'calendar',
		'cat', 'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page', 'comments_popup', 'custom',
		'customize_messenger_channel', 'customized', 'cpage', 'day', 'debug', 'embed', 'error', 'exact', 'feed', 'hour',
		'link', 'm', 'minute', 'monthnum', 'more', 'name', 'nopaging', 'offset', 'p', 'page_id', 'paged', 'pagename', 'pb', 'perm',
		'post__in', 'post__not_in', 'post_mime_type', 'post_status', 'post_type', 'posts', 'posts_per_archive_page',
		'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static', 'status', 'subpost',
		'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in', 'taxonomy', 'tb',
		'term', 'terms', 'title', 'withcomments', 'withoutcomments', 'year', 'users', 'user', 'site', 'data', 'url', 'this',
		'item', 'main', 'xb', 'xb_submission',
	);

	/** @var Definitions|null */
	private static ?array $live = null;

	/**
	 * The live definitions (what WordPress registers).
	 *
	 * @return Definitions
	 */
	public static function live(): array {
		if ( null === self::$live ) {
			try {
				self::$live = self::validate( get_option( self::OPTION, array() ), false );
			} catch ( \InvalidArgumentException ) {
				// A stored value that no longer validates (a name taken since) registers nothing rather than half.
				self::$live = self::empty();
			}
		}
		return self::$live;
	}

	/** @return Definitions */
	public static function empty(): array {
		return array(
			'types'      => array(),
			'taxonomies' => array(),
			'fields'     => array(),
			'groups'     => array(),
		);
	}

	/** Test helper and after a release: read the option again. */
	public static function forget(): void {
		self::$live = null;
	}

	/**
	 * The fields an administrator defined for a post type.
	 *
	 * @return list<FieldDef>
	 */
	public static function fields_for( string $post_type ): array {
		return self::live()['fields'][ $post_type ] ?? array();
	}

	/** Whether a type or taxonomy is one XB registered. */
	public static function owns( string $name ): bool {
		$live = self::live();
		return isset( $live['types'][ $name ] ) || isset( $live['taxonomies'][ $name ] );
	}

	// ------------------------------------------------------------------ validation

	/**
	 * Checks definitions written in the builder; returns them normalized.
	 *
	 * @param bool $check_taken Refuse names another plugin registered (off when registering what is already live).
	 * @return Definitions
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $content, bool $check_taken = true ): array {
		if ( ! is_array( $content ) ) {
			throw new \InvalidArgumentException( __( 'Definizioni dei contenuti non valide.', 'xb' ) );
		}
		$types      = is_array( $content['types'] ?? null ) ? $content['types'] : array();
		$taxonomies = is_array( $content['taxonomies'] ?? null ) ? $content['taxonomies'] : array();
		$fields     = is_array( $content['fields'] ?? null ) ? $content['fields'] : array();
		if ( count( $types ) > self::MAX_TYPES || count( $taxonomies ) > self::MAX_TAXONOMIES ) {
			throw new \InvalidArgumentException( __( 'Troppi tipi di contenuto o tassonomie.', 'xb' ) );
		}

		$out = self::empty();
		foreach ( $types as $slug => $type ) {
			$slug = (string) $slug;
			self::check_name( $slug, 20, $check_taken, 'post_type' );
			$out['types'][ $slug ] = self::type( $slug, $type );
		}
		foreach ( $taxonomies as $slug => $taxonomy ) {
			$slug = (string) $slug;
			self::check_name( $slug, 32, $check_taken, 'taxonomy' );
			if ( isset( $out['types'][ $slug ] ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il nome "%s" è usato sia da un tipo sia da una tassonomia.', 'xb' ), $slug ) );
			}
			$out['taxonomies'][ $slug ] = self::taxonomy( $slug, $taxonomy, array_keys( $out['types'] ) );
		}
		foreach ( $fields as $post_type => $list ) {
			$post_type = (string) $post_type;
			if ( ! self::fields_target_allowed( $post_type, $out ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'I campi si possono aggiungere ai tipi e alle tassonomie creati qui, ad articoli, pagine, categorie, tag e utenti: "%s" non è tra questi.', 'xb' ), mb_substr( $post_type, 0, 40 ) ) );
			}
			if ( ! is_array( $list ) || count( $list ) > self::MAX_FIELDS ) {
				throw new \InvalidArgumentException( sprintf( __( 'Troppi campi per "%s".', 'xb' ), $post_type ) );
			}
			$names = array();
			foreach ( array_values( $list ) as $field ) {
				if ( is_array( $field ) && isset( $field['group'] ) ) {
					continue; // A field of a group comes back from the group below, never as the type's own.
				}
				$field = self::field( $field, $post_type, array_keys( $out['types'] ), $check_taken );
				if ( in_array( $field['name'], $names, true ) ) {
					throw new \InvalidArgumentException( sprintf( __( 'Il campo "%1$s" compare due volte in "%2$s".', 'xb' ), $field['name'], $post_type ) );
				}
				$names[]                         = $field['name'];
				$out['fields'][ $post_type ][] = $field;
			}
		}
		$groups = is_array( $content['groups'] ?? null ) ? $content['groups'] : array();
		if ( count( $groups ) > self::MAX_GROUPS ) {
			throw new \InvalidArgumentException( __( 'Troppi gruppi di campi.', 'xb' ) );
		}
		foreach ( $groups as $name => $group ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) || ! is_array( $group ) ) {
				/* translators: %s: group name */
				throw new \InvalidArgumentException( sprintf( __( 'Gruppo di campi non valido: "%s".', 'xb' ), mb_substr( $name, 0, 40 ) ) );
			}
			$out = self::group( $name, $group, $out, $check_taken );
		}
		ksort( $out['types'] );
		ksort( $out['taxonomies'] );
		ksort( $out['fields'] );
		return $out;
	}

	/**
	 * A group of fields (doc 26 voce 213, docs/spec/content.md «Gruppi di campi»): the same fields for the types it
	 * names, or for every type but those it leaves out (`all` + `except`). The fields are written into each type's
	 * list, marked with the group; a field of the type with the same name wins. Types are the contents created
	 * here, posts and pages; a type created later gets the group's fields when the definitions are saved.
	 *
	 * @param array<mixed> $group
	 * @param Definitions  $out
	 * @return Definitions
	 */
	private static function group( string $name, array $group, array $out, bool $check_taken ): array {
		$targets = array_merge( array_keys( $out['types'] ), array( 'post', 'page' ) );
		$pick    = static fn ( string $key ): array => array_values( array_intersect( $targets, array_map( 'strval', is_array( $group[ $key ] ?? null ) ? $group[ $key ] : array() ) ) );
		$all     = true === ( $group['all'] ?? false );
		$types   = $all ? array() : $pick( 'types' );
		$except  = $all ? $pick( 'except' ) : array();
		$list    = is_array( $group['fields'] ?? null ) ? array_values( $group['fields'] ) : array();
		if ( count( $list ) > self::MAX_FIELDS ) {
			/* translators: %s: group label */
			throw new \InvalidArgumentException( sprintf( __( 'Troppi campi nel gruppo "%s".', 'xb' ), $name ) );
		}
		$fields = array();
		$names  = array();
		foreach ( $list as $field ) {
			$field = self::field( $field, 'post', array_keys( $out['types'] ), false );
			if ( in_array( $field['name'], $names, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il campo "%1$s" compare due volte in "%2$s".', 'xb' ), $field['name'], $name ) );
			}
			$names[]  = $field['name'];
			$fields[] = $field;
		}
		$out['groups'][ $name ] = array(
			'label'  => self::label( $group['label'] ?? '', $name ),
			'all'    => $all,
			'types'  => $types,
			'except' => $except,
			'fields' => $fields,
		);
		foreach ( $all ? array_diff( $targets, $except ) : $types as $type ) {
			$own = array_column( $out['fields'][ $type ] ?? array(), 'name' );
			foreach ( $list as $i => $raw ) {
				if ( in_array( $fields[ $i ]['name'], $own, true ) ) {
					continue;
				}
				// Checked again for the type: a relation to itself, a meta key another plugin registered there.
				$out['fields'][ $type ][] = self::field( $raw, $type, array_keys( $out['types'] ), $check_taken ) + array( 'group' => $name );
				$own[]                    = $fields[ $i ]['name'];
			}
			if ( count( $out['fields'][ $type ] ?? array() ) > self::MAX_FIELDS ) {
				/* translators: %s: content type */
				throw new \InvalidArgumentException( sprintf( __( 'Troppi campi per "%s".', 'xb' ), $type ) );
			}
		}
		return $out;
	}

	/**
	 * Where fields can go: a type created here, posts and pages (`evento`, `post`); the terms of a taxonomy created
	 * here, of categories and tags (`term:luogo`, `term:category`); users (`user`, their public profile).
	 *
	 * @param Definitions $defs
	 */
	private static function fields_target_allowed( string $key, array $defs ): bool {
		if ( 'user' === $key ) {
			return true;
		}
		if ( str_starts_with( $key, 'term:' ) ) {
			$taxonomy = substr( $key, 5 );
			return isset( $defs['taxonomies'][ $taxonomy ] ) || in_array( $taxonomy, array( 'category', 'post_tag' ), true );
		}
		return isset( $defs['types'][ $key ] ) || in_array( $key, array( 'post', 'page' ), true );
	}

	/**
	 * What a fields key names: the meta type WordPress stores the values in and the subtype.
	 *
	 * @return array{0: 'post'|'term'|'user', 1: string}
	 */
	public static function target( string $key ): array {
		if ( 'user' === $key ) {
			return array( 'user', '' );
		}
		if ( str_starts_with( $key, 'term:' ) ) {
			return array( 'term', substr( $key, 5 ) );
		}
		return array( 'post', $key );
	}

	/**
	 * Fields of the terms of a taxonomy.
	 *
	 * @return list<FieldDef>
	 */
	public static function fields_for_term( string $taxonomy ): array {
		return self::live()['fields'][ 'term:' . $taxonomy ] ?? array();
	}

	/**
	 * Fields of users (their public profile).
	 *
	 * @return list<FieldDef>
	 */
	public static function fields_for_user(): array {
		return self::live()['fields']['user'] ?? array();
	}

	private static function check_name( string $slug, int $max, bool $check_taken, string $kind ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,' . ( $max - 1 ) . '}$/', $slug ) || in_array( $slug, self::RESERVED, true ) || str_starts_with( $slug, 'wp_' ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Nome non valido o riservato: "%s" (lettere minuscole, cifre, - e _; al massimo %d caratteri).', 'xb' ), mb_substr( $slug, 0, 40 ), $max ) );
		}
		if ( ! $check_taken ) {
			return;
		}
		// A name another plugin registered stays theirs; one XB registered itself can be redefined.
		$taken = 'post_type' === $kind ? post_type_exists( $slug ) || taxonomy_exists( $slug ) : taxonomy_exists( $slug ) || post_type_exists( $slug );
		if ( $taken && ! self::owns( $slug ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Il nome "%s" è già usato da WordPress o da un altro plugin.', 'xb' ), $slug ) );
		}
	}

	/** @return TypeDef */
	private static function type( string $slug, mixed $type ): array {
		if ( ! is_array( $type ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Tipo di contenuto "%s" non valido.', 'xb' ), $slug ) );
		}
		$supports = array_values( array_intersect( self::SUPPORTS, is_array( $type['supports'] ?? null ) ? $type['supports'] : array( 'title', 'editor', 'thumbnail' ) ) );
		$icon     = is_string( $type['icon'] ?? null ) && 1 === preg_match( '/^dashicons-[a-z0-9-]{1,40}$/', $type['icon'] ) ? $type['icon'] : 'dashicons-admin-post';
		return array(
			'singular'     => self::label( $type['singular'] ?? '', $slug ),
			'plural'       => self::label( $type['plural'] ?? '', $slug ),
			'public'       => (bool) ( $type['public'] ?? true ),
			'archive'      => (bool) ( $type['archive'] ?? true ),
			'slug'         => self::slug( $type['slug'] ?? '', $slug ),
			'hierarchical' => (bool) ( $type['hierarchical'] ?? false ),
			'icon'         => $icon,
			'supports'     => $supports,
			'capability'   => 'page' === ( $type['capability'] ?? 'post' ) ? 'page' : 'post',
		);
	}

	/**
	 * @param list<string> $own_types
	 * @return TaxonomyDef
	 */
	private static function taxonomy( string $slug, mixed $taxonomy, array $own_types ): array {
		if ( ! is_array( $taxonomy ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Tassonomia "%s" non valida.', 'xb' ), $slug ) );
		}
		$types = array();
		foreach ( is_array( $taxonomy['types'] ?? null ) ? $taxonomy['types'] : array() as $type ) {
			if ( is_string( $type ) && ( in_array( $type, $own_types, true ) || in_array( $type, array( 'post', 'page' ), true ) ) ) {
				$types[] = $type;
			}
		}
		return array(
			'singular'     => self::label( $taxonomy['singular'] ?? '', $slug ),
			'plural'       => self::label( $taxonomy['plural'] ?? '', $slug ),
			'public'       => (bool) ( $taxonomy['public'] ?? true ),
			'hierarchical' => (bool) ( $taxonomy['hierarchical'] ?? true ),
			'slug'         => self::slug( $taxonomy['slug'] ?? '', $slug ),
			'types'        => array_values( array_unique( $types ) ),
		);
	}

	/**
	 * @param list<string> $own_types
	 * @return FieldDef
	 */
	private static function field( mixed $field, string $post_type, array $own_types, bool $check_taken ): array {
		if ( ! is_array( $field ) ) {
			throw new \InvalidArgumentException( __( 'Campo non valido.', 'xb' ) );
		}
		$name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Nome di campo non valido: "%s" (lettere minuscole, cifre e _, senza _ iniziale).', 'xb' ), mb_substr( $name, 0, 40 ) ) );
		}
		[ $meta_type, $subtype ] = self::target( $post_type );
		if ( $check_taken ) {
			// A meta key another plugin registered for this type (or for every type) belongs to that plugin.
			$registered = ( '' === $subtype ? array() : get_registered_meta_keys( $meta_type, $subtype ) ) + get_registered_meta_keys( $meta_type );
			if ( isset( $registered[ $name ] ) && ! self::owns_field( $post_type, $name ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il campo "%1$s" è già registrato da un altro plugin per "%2$s".', 'xb' ), $name, $post_type ) );
			}
		}
		$type = is_string( $field['type'] ?? null ) && in_array( $field['type'], self::FIELD_TYPES, true ) ? $field['type'] : '';
		if ( '' === $type ) {
			throw new \InvalidArgumentException( sprintf( __( 'Tipo non valido per il campo "%s".', 'xb' ), $name ) );
		}
		if ( 'repeater' === $type && 'post' !== $meta_type ) {
			throw new \InvalidArgumentException( sprintf( __( 'Il campo "%s": il ripetitore si usa solo nei contenuti, non in termini o utenti.', 'xb' ), $name ) );
		}
		$multiple = true === ( $field['multiple'] ?? false );
		if ( $multiple && 'repeater' === $type ) {
			throw new \InvalidArgumentException( sprintf( __( 'Il campo ripetitore "%s" contiene già più righe e non può essere multiplo.', 'xb' ), $name ) );
		}
		$options = 'select' === $type ? self::options( $field, $name ) : array();
		$sub     = 'repeater' === $type ? self::sub_fields( $field, $name ) : array();
		$target = '';
		if ( 'post' === $type ) {
			$target = is_string( $field['postType'] ?? null ) ? $field['postType'] : '';
			// When registering what is live, other plugins' types may not exist yet: only the name is checked.
			$known = in_array( $target, $own_types, true ) || ( $check_taken ? post_type_exists( $target ) && \XB\Render\LoopPresets::listable( $target ) : 1 === preg_match( '/^[a-z0-9_-]{1,20}$/', $target ) );
			if ( ! $known ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il campo "%s" collega un contenuto: scegli un tipo di contenuto pubblico.', 'xb' ), $name ) );
			}
		}
		return array(
			'name'     => $name,
			'label'    => self::label( $field['label'] ?? '', $name ),
			'type'     => $type,
			'help'     => mb_substr( sanitize_text_field( is_string( $field['help'] ?? null ) ? $field['help'] : '' ), 0, self::MAX_HELP ),
			'options'  => $options,
			'postType' => $target,
			'sub'      => $sub,
			'required' => true === ( $field['required'] ?? false ),
			'public'   => ! array_key_exists( 'public', $field ) || true === $field['public'],
			'multiple' => $multiple,
		) + ( 'select' === $type && ! $multiple && true === ( $field['radio'] ?? false ) ? array( 'radio' => true ) : array() );
	}

	/**
	 * The options of a choice.
	 *
	 * @param array<mixed> $field
	 * @return list<array{value: string, label: string}>
	 */
	private static function options( array $field, string $name ): array {
		$options = array();
		foreach ( is_array( $field['options'] ?? null ) ? array_slice( $field['options'], 0, self::MAX_OPTIONS ) : array() as $option ) {
			$value = is_array( $option ) ? ( $option['value'] ?? '' ) : $option;
			if ( ! is_string( $value ) || '' === $value || mb_strlen( $value ) > 200 || ! mb_check_encoding( $value, 'UTF-8' ) || sanitize_text_field( $value ) !== $value ) {
				throw new \InvalidArgumentException( sprintf( __( 'Opzione non valida nel campo "%s": inserisci testo UTF-8 entro 200 caratteri.', 'xb' ), $name ) );
			}
			foreach ( $options as $existing ) {
				if ( $existing['value'] === $value ) {
					throw new \InvalidArgumentException( sprintf( __( 'Il campo "%s" contiene due opzioni uguali.', 'xb' ), $name ) );
				}
			}
			$options[] = array(
				'value' => $value,
				'label' => self::label( is_array( $option ) ? ( $option['label'] ?? '' ) : '', sanitize_text_field( $value ) ),
			);
		}
		if ( array() === $options ) {
			throw new \InvalidArgumentException( sprintf( __( 'Il campo "%s" è una scelta: aggiungi almeno un\'opzione.', 'xb' ), $name ) );
		}
		return $options;
	}

	/**
	 * The fields of each row of a repeater: scalar types only, names unique in the row.
	 *
	 * @param array<mixed> $field
	 * @return list<SubFieldDef>
	 */
	private static function sub_fields( array $field, string $name, int $depth = 1 ): array {
		$list = is_array( $field['sub'] ?? null ) ? array_values( $field['sub'] ) : array();
		if ( array() === $list || count( $list ) > self::MAX_SUB ) {
			throw new \InvalidArgumentException( sprintf( __( 'Il ripetitore "%1$s" deve avere da 1 a %2$d campi.', 'xb' ), $name, self::MAX_SUB ) );
		}
		$out = array();
		foreach ( $list as $sub ) {
			$sub_name = is_array( $sub ) && is_string( $sub['name'] ?? null ) ? $sub['name'] : '';
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $sub_name ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Nome non valido in "%1$s": "%2$s" (lettere minuscole, cifre e _, senza _ iniziale).', 'xb' ), $name, mb_substr( $sub_name, 0, 40 ) ) );
			}
			if ( in_array( $sub_name, array_column( $out, 'name' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il campo "%1$s" compare due volte in "%2$s".', 'xb' ), $sub_name, $name ) );
			}
			/** @var array<mixed> $sub */
			$type = is_string( $sub['type'] ?? null ) && in_array( $sub['type'], self::SUB_TYPES, true ) ? $sub['type'] : '';
			if ( '' === $type ) {
				throw new \InvalidArgumentException( sprintf( __( 'Tipo non valido per "%1$s" in "%2$s": in un ripetitore vanno testo, numero, data, sì/no, scelta, indirizzo web e immagine.', 'xb' ), $sub_name, $name ) );
			}
			if ( 'repeater' === $type && $depth >= 2 ) {
				throw new \InvalidArgumentException( sprintf( __( 'Il ripetitore "%1$s" in "%2$s" sarebbe al terzo livello: i ripetitori ne hanno al più due.', 'xb' ), $sub_name, $name ) );
			}
			$entry = array(
				'name'    => $sub_name,
				'label'   => self::label( $sub['label'] ?? '', $sub_name ),
				'type'    => $type,
				'options' => 'select' === $type ? self::options( $sub, "$name.$sub_name" ) : array(),
			);
			if ( 'repeater' === $type ) {
				$entry['sub'] = self::sub_fields( $sub, "$name.$sub_name", $depth + 1 );
			}
			$out[] = $entry;
		}
		return $out;
	}

	private static function owns_field( string $post_type, string $name ): bool {
		foreach ( self::fields_for( $post_type ) as $field ) {
			if ( $field['name'] === $name ) {
				return true;
			}
		}
		return false;
	}

	private static function label( mixed $label, string $fallback ): string {
		$label = is_string( $label ) ? trim( sanitize_text_field( $label ) ) : '';
		return mb_substr( '' === $label ? $fallback : $label, 0, self::MAX_LABEL );
	}

	private static function slug( mixed $slug, string $fallback ): string {
		$slug = is_string( $slug ) ? sanitize_title( $slug ) : '';
		$slug = '' === $slug ? str_replace( '_', '-', $fallback ) : $slug;
		return substr( $slug, 0, 60 );
	}

	// ------------------------------------------------------------------ registration

	/** On init: registers the live types, taxonomies and fields. */
	public static function register(): void {
		$live = self::live();
		foreach ( $live['types'] as $slug => $type ) {
			if ( '' === $slug || strtolower( $slug ) !== $slug || post_type_exists( $slug ) ) {
				continue; // Registered meanwhile by someone else: theirs wins, nothing is overwritten.
			}
			$supports = $type['supports'];
			if ( array() !== ( $live['fields'][ $slug ] ?? array() ) ) {
				$supports[] = 'custom-fields'; // Fields are edited through the REST API of the block editor.
			}
			register_post_type(
				$slug,
				array(
					'labels'                => self::type_labels( $type ),
					'public'                => $type['public'],
					'publicly_queryable'    => $type['public'],
					'exclude_from_search'   => ! $type['public'],
					'show_in_nav_menus'     => $type['public'],
					'show_ui'               => true,
					'show_in_menu'          => true,
					'show_in_rest'          => true,
					'rest_controller_class' => $type['public'] ? \WP_REST_Posts_Controller::class : PrivateTypeController::class,
					'has_archive'           => $type['public'] && $type['archive'] ? $type['slug'] : false,
					'rewrite'               => $type['public'] ? array(
						'slug'       => $type['slug'],
						'with_front' => false,
					) : false,
					'query_var'             => $type['public'],
					'hierarchical'          => $type['hierarchical'],
					'menu_icon'             => $type['icon'],
					'supports'              => $supports,
					'capability_type'       => $type['capability'],
					'map_meta_cap'          => true,
					'delete_with_user'      => false,
				)
			);
			add_filter( 'rest_pre_insert_' . $slug, array( self::class, 'validate_rest_required_fields' ), 10, 2 );
		}
		// Types without pages of their own still feed loops: that is what an administrator creates them for.
		$hidden = array_keys( array_filter( $live['types'], static fn ( array $t ): bool => ! $t['public'] ) );
		if ( array() !== $hidden ) {
			add_filter( 'xb_loop_post_types', static fn ( mixed $types ): array => array_merge( (array) $types, $hidden ) );
			add_action( 'pre_get_posts', array( self::class, 'term_archives_list_hidden_types' ) );
		}
		foreach ( $live['taxonomies'] as $slug => $taxonomy ) {
			if ( taxonomy_exists( $slug ) ) {
				continue;
			}
			register_taxonomy(
				$slug,
				$taxonomy['types'],
				array(
					'labels'            => self::taxonomy_labels( $taxonomy ),
					'public'            => $taxonomy['public'],
					'publicly_queryable' => $taxonomy['public'],
					'show_ui'           => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'show_in_nav_menus' => $taxonomy['public'],
					'hierarchical'      => $taxonomy['hierarchical'],
					'query_var'         => $taxonomy['public'],
					'rewrite'           => $taxonomy['public'] ? array(
						'slug'         => $taxonomy['slug'],
						'with_front'   => false,
						'hierarchical' => $taxonomy['hierarchical'],
					) : false,
				)
			);
		}
		foreach ( $live['fields'] as $key => $fields ) {
			[ $meta_type, $subtype ] = self::target( (string) $key );
			foreach ( $fields as $field ) {
				if ( 'post' === $meta_type ) {
					self::register_field( $subtype, $field );
				} else {
					self::register_object_field( $meta_type, $subtype, $field );
				}
			}
		}
	}

	/**
	 * The archive of a public taxonomy lists the items of a type without pages of its own too. WordPress fills such an
	 * archive with the types that search includes, and a type without pages is out of search: its items vanished
	 * from the archive (a photo gallery by year came out empty) while the term still counted them. A query that
	 * names its types is left as it is.
	 */
	public static function term_archives_list_hidden_types( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $query->is_tax() || ! empty( $query->get( 'post_type' ) ) ) {
			return;
		}
		$taxonomies = array_keys( $query->tax_query->queried_terms ?? array() );
		$hidden     = array_keys( array_filter( self::live()['types'], static fn ( array $t ): bool => ! $t['public'] ) );
		$extra      = array_values( array_filter( $hidden, static fn ( string $type ): bool => post_type_exists( $type ) && array() !== array_intersect( $taxonomies, get_object_taxonomies( $type ) ) ) );
		if ( array() === $extra ) {
			return;
		}
		// What WordPress would list (WP_Query::get_posts), plus those types.
		$types = $extra;
		foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $type ) {
			$object_taxonomies = 'attachment' === $type ? get_taxonomies_for_attachments() : get_object_taxonomies( $type );
			if ( array() !== array_intersect( $taxonomies, $object_taxonomies ) ) {
				$types[] = $type;
			}
		}
		$types = array_values( array_unique( $types ) );
		sort( $types );
		$query->set( 'post_type', $types );
	}

	/**
	 * A field of terms or users. Terms of a public taxonomy show it in the REST API like the term itself; user
	 * fields stay out of it (the profile form edits them, pages read them only on public profiles).
	 *
	 * @param 'term'|'user' $meta_type
	 * @param FieldDef      $field
	 */
	private static function register_object_field( string $meta_type, string $taxonomy, array $field ): void {
		$registered = ( '' === $taxonomy ? array() : get_registered_meta_keys( $meta_type, $taxonomy ) ) + get_registered_meta_keys( $meta_type );
		if ( isset( $registered[ $field['name'] ] ) ) {
			return;
		}
		$schema = self::schema( $field );
		$public = 'term' === $meta_type && ( get_taxonomy( $taxonomy )->public ?? false );
		if ( empty( $field['public'] ) ) {
			// As for post fields: a private field is listed in the edit context only, never in the view context
			// anonymous visitors get.
			$schema['context'] = array( 'edit' );
		}
		$args   = array(
			'type'              => $schema['type'],
			'single'            => true,
			'description'       => $field['label'],
			'show_in_rest'      => $public ? array( 'schema' => $schema ) : false,
			'sanitize_callback' => static fn ( mixed $value ): mixed => self::sanitize_value( $field, $value ),
			'auth_callback'     => 'term' === $meta_type
				? static fn ( bool $allowed, string $key, int $term_id ): bool => current_user_can( 'edit_term', $term_id )
				: static fn ( bool $allowed, string $key, int $user_id ): bool => current_user_can( 'edit_user', $user_id ),
		);
		if ( 'term' === $meta_type ) {
			register_term_meta( $taxonomy, $field['name'], $args );
		} else {
			register_meta( 'user', $field['name'], $args );
		}
	}

	/** @param FieldDef $field */
	private static function register_field( string $post_type, array $field ): void {
		$registered = get_registered_meta_keys( 'post', $post_type ) + get_registered_meta_keys( 'post' );
		if ( isset( $registered[ $field['name'] ] ) ) {
			return;
		}
		$schema = self::schema( $field );
		if ( ! $field['public'] ) {
			// A private field is in REST for the editor's writes only: it is listed in edit context (authorised
			// readers), never in the view context anonymous visitors get.
			$schema['context'] = array( 'edit' );
		}
		$args   = array(
			'type'              => $schema['type'],
			'single'            => true,
			'description'       => $field['label'],
			'show_in_rest'      => array( 'schema' => $schema ),
			'sanitize_callback' => static fn ( mixed $value ): mixed => self::sanitize_value( $field, $value ),
			'auth_callback'     => static fn ( bool $allowed, string $key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
		);
		if ( 'repeater' === $field['type'] ) {
			$args['default'] = array(); // Rows not written yet: an empty list, never ''.
		}
		register_post_meta( $post_type, $field['name'], $args );
	}

	/**
	 * The REST schema of a field: a repeater is an array of rows, each an object with one property per sub field.
	 *
	 * @param FieldDef|SubFieldDef $field
	 * @return array<string, mixed>&array{type: string}
	 */
	private static function schema( array $field ): array {
		if ( ! empty( $field['multiple'] ) && 'repeater' !== $field['type'] ) {
			$single = $field;
			$single['multiple'] = false;
			return array( 'type' => 'array', 'maxItems' => 1000, 'items' => self::schema( $single ) );
		}
		if ( 'repeater' === $field['type'] && isset( $field['sub'] ) ) {
			$properties = array();
			foreach ( $field['sub'] as $sub ) {
				// In a row an empty number is null: the row keeps its other values.
				$properties[ $sub['name'] ] = in_array( $sub['type'], array( 'number', 'decimal', 'integer' ), true ) ? array( 'type' => array( 'number', 'integer', 'null' ) ) : self::schema( $sub );
				if ( 'repeater' === $sub['type'] ) {
					$properties[ $sub['name'] ]['maxItems'] = self::MAX_INNER_ROWS;
				}
			}
			return array(
				'type'     => 'array',
				'maxItems' => self::MAX_ROWS,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
			);
		}
		return match ( $field['type'] ) {
			'number', 'decimal' => array( 'type' => 'number' ),
			'integer' => array( 'type' => 'integer' ),
			'toggle' => array( 'type' => 'boolean' ),
			'image', 'file', 'post' => array( 'type' => 'integer' ),
			'time' => array(
				'type'    => 'string',
				'pattern' => '^((?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?)?$',
			),
			'select' => array(
				'type' => 'string',
				'enum' => array_merge( array( '' ), array_column( $field['options'], 'value' ) ),
			),
			default => array( 'type' => 'string' ),
		};
	}

	/**
	 * A value as a field stores it; anything that is not one becomes empty.
	 *
	 * @param FieldDef $field
	 */
	public static function sanitize_value( array $field, mixed $value ): mixed {
		if ( ! empty( $field['multiple'] ) ) {
			if ( ! is_array( $value ) || count( $value ) > 1000 ) {
				return array();
			}
			$single = $field;
			$single['multiple'] = false;
			return array_map( static fn ( mixed $item ): mixed => self::sanitize_value( $single, $item ), array_values( $value ) );
		}
		if ( 'repeater' === $field['type'] ) {
			return self::sanitize_rows( $field['sub'], $value, self::MAX_ROWS );
		}
		$text = is_scalar( $value ) ? (string) $value : '';
		switch ( $field['type'] ) {
			case 'rich-text':
				return mb_substr( wp_kses_post( $text ), 0, self::MAX_TEXT );
			case 'number':
			case 'decimal':
				return is_numeric( $value ) ? $value + 0 : '';
			case 'integer':
				return is_numeric( $value ) && (float) $value === (float) (int) $value ? (int) $value : '';
			case 'toggle':
				return in_array( $value, array( true, 1, '1', 'true' ), true );
			case 'image':
				$id = absint( $value );
				return $id && wp_attachment_is_image( $id ) ? $id : 0;
			case 'post':
				$id = absint( $value );
				return $id && get_post_type( $id ) === $field['postType'] ? $id : 0;
			case 'file':
				$id = absint( $value );
				return $id && 'attachment' === get_post_type( $id ) ? $id : 0;
			case 'time':
				// `HH:MM` or `HH:MM:SS` (doc 26 voce 211), as written.
				return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $text ) ? $text : '';
			case 'url':
				return mb_substr( esc_url_raw( $text, array( 'http', 'https' ) ), 0, 2000 );
			case 'email':
				return sanitize_email( $text );
			case 'date':
				return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) && checkdate( (int) substr( $text, 5, 2 ), (int) substr( $text, 8, 2 ), (int) substr( $text, 0, 4 ) ) ? $text : '';
			case 'datetime':
				if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,9})?(?:Z|[+-](?:0\d|1\d|2[0-3]):[0-5]\d)$/', $text ) ) {
					return '';
				}
				if ( ! checkdate( (int) substr( $text, 5, 2 ), (int) substr( $text, 8, 2 ), (int) substr( $text, 0, 4 ) ) ) {
					return '';
				}
				try {
					new \DateTimeImmutable( $text );
					return $text;
				} catch ( \Throwable ) {
					return '';
				}
			case 'select':
				return in_array( $text, array_column( $field['options'], 'value' ), true ) ? $text : '';
			case 'textarea':
				return mb_substr( sanitize_textarea_field( $text ), 0, self::MAX_TEXT );
			default:
				return mb_substr( sanitize_text_field( $text ), 0, self::MAX_TEXT );
		}
	}

	/**
	 * Refuses to publish a record with an empty required field through REST (the block editor). WordPress hands
	 * the prepared post as a stdClass; a draft may stay incomplete.
	 */
	public static function validate_rest_required_fields( mixed $prepared, \WP_REST_Request $request ): mixed {
		if ( ! is_object( $prepared ) || is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$id     = (int) ( $prepared->ID ?? 0 );
		$type   = (string) ( $prepared->post_type ?? ( $id > 0 ? get_post_type( $id ) : '' ) );
		$status = (string) ( $prepared->post_status ?? ( $id > 0 ? get_post_status( $id ) : 'draft' ) );
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return $prepared;
		}
		$meta = $request->get_param( 'meta' );
		foreach ( self::fields_for( $type ) as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			$value = is_array( $meta ) && array_key_exists( $field['name'], $meta )
				? $meta[ $field['name'] ]
				: ( $id > 0 ? get_post_meta( $id, $field['name'], true ) : null );
			if ( null === $value || '' === $value || array() === $value ) {
				return new \WP_Error( 'xb_required_content_field', sprintf( /* translators: %s: field label */ __( 'Completa il campo obbligatorio "%s" prima di pubblicare.', 'xb' ), $field['label'] ), array( 'status' => 400 ) );
			}
		}
		return $prepared;
	}

	/**
	 * The rows of a repeater: at most MAX_ROWS, each with exactly its sub fields, each sanitized by its type.
	 *
	 * @param list<SubFieldDef> $subs
	 * @return list<array<string, mixed>>
	 */
	private static function sanitize_rows( array $subs, mixed $value, int $max ): array {
		$rows = array();
		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, $max ) : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out = array();
			foreach ( $subs as $sub ) {
				$raw = $row[ $sub['name'] ] ?? null;
				if ( 'repeater' === $sub['type'] ) {
					// A repeater in the row (D6): its own rows, fewer of them.
					$out[ $sub['name'] ] = self::sanitize_rows( $sub['sub'] ?? array(), $raw, self::MAX_INNER_ROWS );
					continue;
				}
				$clean               = self::sanitize_value( $sub + array( 'help' => '', 'postType' => '', 'sub' => array(), 'required' => false, 'public' => true, 'multiple' => false ), $raw );
				$out[ $sub['name'] ] = in_array( $sub['type'], array( 'number', 'decimal', 'integer' ), true ) && '' === $clean ? null : $clean;
			}
			$rows[] = $out;
		}
		return $rows;
	}

	/**
	 * After registration: rewrite rules change when public types or taxonomies (or their slugs) do. Flushed once
	 * per change, never on every request.
	 */
	public static function maybe_flush(): void {
		$live = self::live();
		$sign = md5(
			(string) wp_json_encode(
				array(
					array_map( static fn ( array $t ): array => array( $t['public'], $t['archive'], $t['slug'], $t['hierarchical'] ), $live['types'] ),
					array_map( static fn ( array $t ): array => array( $t['public'], $t['slug'], $t['hierarchical'] ), $live['taxonomies'] ),
				)
			)
		);
		if ( get_option( self::REWRITE ) !== $sign ) {
			update_option( self::REWRITE, $sign, true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * @param TypeDef $type
	 * @return array<string, string>
	 */
	private static function type_labels( array $type ): array {
		return array(
			'name'               => $type['plural'],
			'singular_name'      => $type['singular'],
			'menu_name'          => $type['plural'],
			'all_items'          => $type['plural'],
			/* translators: %s: content type, singular */
			'add_new_item'       => sprintf( __( 'Aggiungi %s', 'xb' ), $type['singular'] ),
			/* translators: %s: content type, singular */
			'edit_item'          => sprintf( __( 'Modifica %s', 'xb' ), $type['singular'] ),
			/* translators: %s: content type, singular */
			'new_item'           => sprintf( __( 'Nuovo: %s', 'xb' ), $type['singular'] ),
			/* translators: %s: content type, singular */
			'view_item'          => sprintf( __( 'Visualizza %s', 'xb' ), $type['singular'] ),
			/* translators: %s: content type, plural */
			'search_items'       => sprintf( __( 'Cerca in %s', 'xb' ), $type['plural'] ),
			'not_found'          => __( 'Nessun contenuto trovato.', 'xb' ),
			'not_found_in_trash' => __( 'Nessun contenuto nel cestino.', 'xb' ),
		);
	}

	/**
	 * @param TaxonomyDef $taxonomy
	 * @return array<string, string>
	 */
	private static function taxonomy_labels( array $taxonomy ): array {
		return array(
			'name'          => $taxonomy['plural'],
			'singular_name' => $taxonomy['singular'],
			'menu_name'     => $taxonomy['plural'],
			'all_items'     => $taxonomy['plural'],
			/* translators: %s: taxonomy, singular */
			'add_new_item'  => sprintf( __( 'Aggiungi %s', 'xb' ), $taxonomy['singular'] ),
			/* translators: %s: taxonomy, singular */
			'edit_item'     => sprintf( __( 'Modifica %s', 'xb' ), $taxonomy['singular'] ),
			/* translators: %s: taxonomy, plural */
			'search_items'  => sprintf( __( 'Cerca in %s', 'xb' ), $taxonomy['plural'] ),
			'not_found'     => __( 'Nessun termine trovato.', 'xb' ),
		);
	}
}
