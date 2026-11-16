<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Templates;

/**
 * FSE templates and template parts as builder documents — docs/spec/templates.md.
 *
 * A template applies to many URLs, so the builder previews it with a sample: a post of the type the template
 * is for (`single-mec-events` → an event), or a list (archives, search, 404) rendered as the main query.
 * Templates that exist only as theme files are written to the database first, as the Site Editor does when
 * a template is customized; from then on they are ordinary versioned documents.
 */
final class Templates {

	public const TYPES   = array( 'wp_template', 'wp_template_part' );
	private const MANAGED_META = '_xb_histos_managed_template';
	private const SAMPLES = 20;

	/** @phpstan-assert-if-true =\WP_Post $post */
	public static function is_template( mixed $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, self::TYPES, true );
	}

	/**
	 * The post type a template shows one of, or null when it shows a list (or nothing specific).
	 * `single-{type}`, `single` → post, `page`/`page-*`/`front-page` → page, `singular` → post.
	 */
	public static function sample_type( \WP_Post $template ): ?string {
		$slug = (string) $template->post_name;
		if ( 'wp_template_part' === $template->post_type ) {
			return 'page'; // A part has no post of its own: any page shows it in context.
		}
		if ( str_starts_with( $slug, 'single-' ) ) {
			// `single-mec-events`, `single-post-hello`: the longest prefix that is a post type.
			$parts = explode( '-', substr( $slug, 7 ) );
			for ( $n = count( $parts ); $n > 0; $n-- ) {
				$type = implode( '-', array_slice( $parts, 0, $n ) );
				if ( post_type_exists( $type ) ) {
					return $type;
				}
			}
		}
		return match ( true ) {
			in_array( $slug, array( 'single', 'singular' ), true ) => 'post',
			'front-page' === $slug, 'page' === $slug, str_starts_with( $slug, 'page-' ) => 'page',
			default => null,
		};
	}

	/**
	 * Posts to preview a template with: recent, published, readable by the current user.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function samples( \WP_Post $template ): array {
		$type = self::sample_type( $template );
		if ( null === $type ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'numberposts'      => self::SAMPLES,
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$out[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ) ?: sprintf( '#%d', $post->ID ),
				);
			}
		}
		return $out;
	}

	/** A sample the user may use: one of the template's own samples, else the first one. */
	public static function sample( \WP_Post $template, int $requested ): ?\WP_Post {
		$ids = array_column( self::samples( $template ), 'id' );
		$id  = in_array( $requested, $ids, true ) ? $requested : ( $ids[0] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		return $post instanceof \WP_Post ? $post : null;
	}

	/** Where the template can be seen on the site: the sample's URL, or the list it renders. */
	public static function url( \WP_Post $template, int $sample = 0 ): string {
		$post = self::sample( $template, $sample );
		if ( null !== $post ) {
			return (string) get_permalink( $post );
		}
		$slug = (string) $template->post_name;
		if ( 1 === preg_match( '/^archive-([a-z0-9_-]+)$/', $slug, $m ) ) {
			$link = get_post_type_archive_link( $m[1] );
			return is_string( $link ) ? $link : home_url( '/' );
		}
		return match ( $slug ) {
			'search' => add_query_arg( 's', 'a', home_url( '/' ) ),
			'404'    => home_url( '/xb-anteprima-404/' ),
			default  => home_url( '/' ),
		};
	}

	/**
	 * Runs $callback with the globals a template expects: the sample as the current post, or a list as the
	 * main query. Everything is restored afterwards.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function with_context( \WP_Post $template, int $sample, callable $callback ): mixed {
		global $post, $wp_query, $wp_the_query;
		$saved = array( $post, $wp_query, $wp_the_query );
		$item  = self::sample( $template, $sample );
		if ( null !== $item ) {
			$query = new \WP_Query(
				array(
					'p'         => $item->ID,
					'post_type' => $item->post_type,
				)
			);
		} else {
			$query = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => (int) get_option( 'posts_per_page', 10 ),
				)
			);
		}
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$post         = $item ?? ( $query->posts[0] ?? null ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $post instanceof \WP_Post ) {
			setup_postdata( $post );
		}
		try {
			return $callback();
		} finally {
			[ $post, $wp_query, $wp_the_query ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			wp_reset_postdata();
		}
	}

	/**
	 * Templates and parts of the active theme, with the database post when there is one.
	 *
	 * @return list<array{type: string, id: string, slug: string, title: string, source: string, post: int, group: string, groupLabel: string, groupOrder: int, description: string}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$group = self::classify( $type, (string) $template->slug, (string) ( $template->area ?? '' ), ! empty( $template->is_custom ) );
				$out[] = array(
					'type'        => $type,
					'id'          => (string) $template->id,
					'slug'        => (string) $template->slug,
					'title'       => '' !== (string) $template->title ? (string) $template->title : (string) $template->slug,
					'source'      => (string) $template->source,
					'post'        => (int) ( $template->wp_id ?? 0 ),
					'group'       => $group['key'],
					'groupLabel'  => $group['label'],
					'groupOrder'  => $group['order'],
					'description' => '' !== (string) $template->description ? (string) $template->description : $group['hint'],
				);
			}
		}
		usort( $out, static fn ( array $a, array $b ): int => array( $a['groupOrder'], $a['group'], $a['title'] ) <=> array( $b['groupOrder'], $b['group'], $b['title'] ) );
		return $out;
	}

	/**
	 * What a template is for, following WordPress's template hierarchy: the group it is shown in (site-wide,
	 * pages, posts, archives, one group per content type, taxonomies, custom templates, and parts by area)
	 * and a one-line hint of where it applies.
	 *
	 * @return array{key: string, label: string, order: int, hint: string}
	 */
	public static function classify( string $type, string $slug, string $area = '', bool $custom = false ): array {
		$group = static fn ( string $key, string $label, int $order, string $hint ): array => array(
			'key'   => $key,
			'label' => $label,
			'order' => $order,
			'hint'  => $hint,
		);
		if ( 'wp_template_part' === $type ) {
			return match ( $area ) {
				'header' => $group( 'part:header', __( 'Intestazioni', 'xb' ), 80, __( 'Parte in cima alle pagine.', 'xb' ) ),
				'footer' => $group( 'part:footer', __( 'Piè di pagina', 'xb' ), 81, __( 'Parte in fondo alle pagine.', 'xb' ) ),
				default  => $group( 'part:other', __( 'Altre parti', 'xb' ), 82, __( 'Parte riutilizzata dai template.', 'xb' ) ),
			};
		}
		$site = array(
			'front-page'     => __( 'La pagina iniziale del sito.', 'xb' ),
			'home'           => __( 'L\'elenco degli ultimi articoli (o la home, se non c\'è una pagina iniziale).', 'xb' ),
			'index'          => __( 'Il modello di riserva: vale dove nessun altro template è più preciso.', 'xb' ),
			'404'            => __( 'Quando l\'indirizzo non corrisponde a nessun contenuto.', 'xb' ),
			'search'         => __( 'I risultati della ricerca.', 'xb' ),
			'privacy-policy' => __( 'La pagina della privacy.', 'xb' ),
			'blank'          => __( 'Una pagina vuota, senza intestazione né piè di pagina.', 'xb' ),
		);
		if ( isset( $site[ $slug ] ) ) {
			return $group( 'site', __( 'Generali', 'xb' ), 10, $site[ $slug ] );
		}
		if ( 'page' === $slug || str_starts_with( $slug, 'page-' ) ) {
			return $group( 'pages', __( 'Pagine', 'xb' ), 20, 'page' === $slug ? __( 'Ogni pagina.', 'xb' ) : __( 'Una pagina precisa.', 'xb' ) );
		}
		if ( in_array( $slug, array( 'single', 'singular', 'attachment', 'embed' ), true ) || 'single-post' === $slug || str_starts_with( $slug, 'single-post-' ) ) {
			return $group( 'posts', __( 'Articoli', 'xb' ), 30, 'singular' === $slug ? __( 'Ogni articolo e pagina, se non c\'è un template più preciso.', 'xb' ) : __( 'Un singolo articolo.', 'xb' ) );
		}
		foreach ( array( 'single-', 'archive-' ) as $prefix ) {
			$cpt = str_starts_with( $slug, $prefix ) ? self::longest( substr( $slug, strlen( $prefix ) ), 'post_type_exists' ) : null;
			if ( null !== $cpt && ! in_array( $cpt, array( 'post', 'page' ), true ) ) {
				$object = get_post_type_object( $cpt );
				$name   = null !== $object ? (string) $object->labels->name : $cpt;
				return $group( 'type:' . $cpt, $name, 50, 'single-' === $prefix ? sprintf( /* translators: %s: content type */ __( 'Un singolo elemento di «%s».', 'xb' ), $name ) : sprintf( /* translators: %s: content type */ __( 'L\'elenco di «%s».', 'xb' ), $name ) );
			}
		}
		if ( in_array( $slug, array( 'archive', 'date', 'author' ), true ) || str_starts_with( $slug, 'author-' ) || 'archive-post' === $slug ) {
			return $group( 'archives', __( 'Archivi', 'xb' ), 40, __( 'Elenchi di articoli: per data, per autore, generali.', 'xb' ) );
		}
		if ( preg_match( '/^(category|tag|taxonomy)(-|$)/', $slug ) ) {
			return $group( 'taxonomies', __( 'Categorie, tag e tassonomie', 'xb' ), 60, __( 'Gli articoli di una categoria, di un tag o di un termine.', 'xb' ) );
		}
		return $group( 'custom', $custom ? __( 'Modelli personalizzati', 'xb' ) : __( 'Altri template', 'xb' ), 70, $custom ? __( 'Da assegnare a pagine o articoli dal loro editor.', 'xb' ) : '' );
	}

	/** The longest leading part of `a-b-c` (by dashes) for which $exists is true, or null. */
	private static function longest( string $rest, callable $exists ): ?string {
		$parts = explode( '-', $rest );
		for ( $n = count( $parts ); $n > 0; $n-- ) {
			$candidate = implode( '-', array_slice( $parts, 0, $n ) );
			if ( $exists( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * The database post of a template, creating it from the theme file when needed.
	 *
	 * @throws \RuntimeException When the template does not exist or cannot be written.
	 */
	public static function materialize( string $type, string $id ): int {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \RuntimeException( __( 'Tipo di template non valido.', 'xb' ) );
		}
		$template = get_block_template( $id, $type );
		if ( ! $template instanceof \WP_Block_Template || $template->theme !== get_stylesheet() ) {
			throw new \RuntimeException( __( 'Template inesistente nel tema attivo.', 'xb' ) );
		}
		if ( ! empty( $template->wp_id ) ) {
			update_post_meta( (int) $template->wp_id, self::MANAGED_META, '1' );
			return (int) $template->wp_id;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_name'    => $template->slug,
				'post_title'   => '' !== (string) $template->title ? (string) $template->title : $template->slug,
				'post_excerpt' => (string) $template->description,
				'post_content' => wp_slash( (string) $template->content ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}
		wp_set_post_terms( $post_id, array( get_stylesheet() ), 'wp_theme' );
		update_post_meta( $post_id, self::MANAGED_META, '1' );
		if ( 'wp_template_part' === $type && is_string( $template->area ?? null ) && '' !== $template->area ) {
			wp_set_post_terms( $post_id, array( $template->area ), 'wp_template_part_area' );
		}
		return (int) $post_id;
	}

	/**
	 * Slugs worth suggesting for a new template, from WordPress's template hierarchy and this site's content types
	 * and taxonomies, minus those the theme already has.
	 *
	 * @return list<string>
	 */
	public static function suggestions(): array {
		$slugs = array( 'index', 'home', 'front-page', 'page', 'single', 'singular', 'archive', 'search', '404', 'author', 'date', 'category', 'tag', 'attachment', 'privacy-policy' );
		foreach ( get_post_types( array( 'public' => true, '_builtin' => false ) ) as $type ) {
			$slugs[] = 'single-' . $type;
			if ( get_post_type_object( $type )?->has_archive ) {
				$slugs[] = 'archive-' . $type;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true, '_builtin' => false ) ) as $taxonomy ) {
			$slugs[] = 'taxonomy-' . $taxonomy;
		}
		$taken = array_column( self::all(), 'slug' );
		return array_values( array_diff( array_unique( $slugs ), $taken ) );
	}

	/**
	 * A new template or template part of the active theme, in the database, opened in the builder afterwards.
	 * A slug of the template hierarchy (`single-event`) applies by itself; any other is a custom template,
	 * assigned to pages and posts from their editor, as WordPress's Site Editor does.
	 *
	 * @param string $from A template of the same type to copy (`theme//slug`), or '' for an empty one.
	 * @throws \InvalidArgumentException With a message for the user.
	 */
	public static function create( string $type, string $slug, string $title, string $area = 'uncategorized', string $from = '' ): int {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( __( 'Tipo di template non valido.', 'xb' ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug ) ) {
			throw new \InvalidArgumentException( __( 'Il nome tecnico usa solo lettere minuscole, cifre e trattini (per esempio single-evento).', 'xb' ) );
		}
		if ( get_block_template( get_stylesheet() . '//' . $slug, $type ) instanceof \WP_Block_Template ) {
			throw new \InvalidArgumentException( __( 'Esiste già un template con questo nome tecnico: aprilo dall\'elenco.', 'xb' ) );
		}
		$content = '';
		if ( '' !== $from ) {
			$source = get_block_template( $from, $type );
			if ( ! $source instanceof \WP_Block_Template || $source->theme !== get_stylesheet() ) {
				throw new \InvalidArgumentException( __( 'Il template da copiare non esiste nel tema attivo.', 'xb' ) );
			}
			$content = (string) $source->content;
		}
		$title   = '' !== trim( $title ) ? mb_substr( sanitize_text_field( $title ), 0, 120 ) : $slug;
		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \InvalidArgumentException( $post_id->get_error_message() );
		}
		wp_set_post_terms( $post_id, array( get_stylesheet() ), 'wp_theme' );
		update_post_meta( $post_id, self::MANAGED_META, '1' );
		if ( 'wp_template_part' === $type ) {
			$area = in_array( $area, array( 'header', 'footer', 'uncategorized' ), true ) ? $area : 'uncategorized';
			wp_set_post_terms( $post_id, array( $area ), 'wp_template_part_area' );
		} elseif ( 'custom' !== self::classify( $type, $slug )['key'] ) {
			// A slug of the hierarchy: WordPress applies it by itself, it is not offered as a custom template.
			update_post_meta( $post_id, 'is_wp_suggestion', true );
		}
		\XB\Plugin::instance()->versioning->store()->audit( 'create_template', 'post:' . $post_id, array( 'type' => $type, 'slug' => $slug, 'from' => $from ) );
		return (int) $post_id;
	}

	/** The database template WordPress resolved for the current front-end request, if any. */
	public static function current(): ?\WP_Post {
		global $_wp_current_template_id;
		if ( ! is_string( $_wp_current_template_id ) || '' === $_wp_current_template_id ) {
			return null;
		}
		$template = get_block_template( $_wp_current_template_id, 'wp_template' );
		$post     = $template instanceof \WP_Block_Template && ! empty( $template->wp_id ) ? get_post( (int) $template->wp_id ) : null;
		return $post instanceof \WP_Post ? $post : null;
	}
}
