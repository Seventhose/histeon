<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Content\ContentTypes;
use XB\Export\Etch\Tokens;
use XB\Fonts\LocalFonts;
use XB\Framework\Reset;
use XB\Plugin;
use XB\Styles\CssCompiler;
use XB\Styles\NamedQueries;
use XB\Styles\StyleRegistry;
use XB\Templates\Templates;

/**
 * The site's own theme — docs/spec/export-core.md §13 (doc 25 of Histeon Publish, §9 and §11 step 4): a child of
 * Fondamenta, `<sito>-tema`, made from this site and for this site only. It holds what Fondamenta cannot hold for
 * every site: the site's sheet (tokens, framework classes, reset, local fonts, global sheets and selector styles,
 * as Histeon prints them), its `theme.json`, and its templates and parts converted to Fondamenta's blocks, with the
 * templates Histeon CMS assigns to content types and pages.
 *
 * Nothing is written into WordPress: build() returns the files, zip() writes them in an archive at a path the
 * caller chose. The theme is installed by hand (Appearance → Themes → Upload), as Histeon never writes PHP files.
 */
final class SiteTheme {

	public const PARENT = 'fondamenta';

	/** @var list<string> What could not be carried, for the report. */
	public array $notes = array();
	/** @var list<array{id: int, title: string, template: string}> Pages that need `_wp_page_template` (written in F1). */
	public array $pages = array();

	/** @param Fields\Adapter|null $fields The field plugin the site's fields go to (§15): its groups go in the theme. */
	public function __construct( private readonly ?Fields\Adapter $fields = null ) {
	}

	/**
	 * The theme's files, by path inside its folder.
	 *
	 * @return array{slug: string, name: string, files: array<string, string>}
	 */
	public function build(): array {
		if ( ! Report::can_read() ) {
			throw new \RuntimeException( __( 'Serve un amministratore con il permesso xb_build per generare il tema del sito.', 'xb' ) );
		}
		if ( ! Prova::ready() ) {
			throw new \RuntimeException( __( 'Per convertire template e parti servono i blocchi di Fondamenta: attiva Fondamenta 0.2 o successiva.', 'xb' ) );
		}
		$name    = trim( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$base    = sanitize_title( '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$slug    = ( '' !== $base ? $base : 'sito' ) . '-tema';
		$version = '1.0.' . gmdate( 'YmdHi' );
		$files   = array();
		$custom  = array();
		$parts   = array();
		foreach ( $this->templates() as $template ) {
			$markup = $this->convert( $template );
			$folder                                         = 'wp_template_part' === $template['type'] ? 'parts' : 'templates';
			$files[ $folder . '/' . $template['slug'] . '.html' ] = $markup;
			if ( 'wp_template_part' === $template['type'] ) {
				$parts[] = array(
					'name'  => $template['slug'],
					'title' => $template['title'],
					'area'  => '' !== $template['area'] ? $template['area'] : 'uncategorized',
				);
			} elseif ( $template['custom'] ) {
				$custom[] = array(
					'name'      => $template['slug'],
					'title'     => $template['title'],
					'postTypes' => array( 'page', 'post' ),
				);
			}
		}
		$files['assets/sito.css'] = $this->sheet();
		$scripts                  = $this->scripts();
		foreach ( $scripts['files'] as $file => $code ) {
			$files[ $file ] = $code;
		}
		$files['theme.json']      = (string) wp_json_encode( $this->theme_json( $custom, $parts ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		$files['style.css']       = $this->style( $name, $slug, $version );
		$facets                   = \XB\Render\Facets::portable();
		if ( array() !== $facets ) {
			$files['facets.json'] = (string) wp_json_encode( $facets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		if ( null !== $this->fields ) {
			$content = \XB\Content\ContentTypes::live();
			foreach ( $this->fields->theme_files( $content ) as $file => $code ) {
				$files[ $file ] = $code;
			}
			array_push( $this->notes, ...$this->fields->notes( $content ) );
		}
		$files['functions.php']   = $this->functions( $slug, $version, $scripts['documents'], $scripts['site'] ) . ( array() === $facets ? '' : self::FACETS ) . ( null === $this->fields ? '' : $this->fields->functions() );
		$files['README.md']       = $this->readme( $name ) . ( null === $this->fields ? '' : "\n" . sprintf( /* translators: %s: plugin name */ __( 'I gruppi di campi sono per %s: installalo e attivalo, li legge da questo tema.', 'xb' ), $this->fields->name() ) . "\n" );
		ksort( $files );
		return array(
			'slug'  => $slug,
			'name'  => $name,
			'files' => $files,
		);
	}

	/**
	 * The theme as a ZIP at $path: `<slug>/…`.
	 *
	 * @return array{slug: string, name: string, files: array<string, string>}
	 */
	public function zip( string $path ): array {
		$theme = $this->build();
		$zip   = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( __( 'Impossibile scrivere il file.', 'xb' ) );
		}
		foreach ( $theme['files'] as $file => $content ) {
			$zip->addFromString( $theme['slug'] . '/' . $file, $content );
		}
		$zip->close();
		return $theme;
	}

	/**
	 * Templates and parts of the active theme, the content-type templates Histeon CMS makes (`single-<tipo>`,
	 * `archive-<tipo>`), and the pages they are assigned to.
	 *
	 * @return list<array{type: string, slug: string, title: string, area: string, custom: bool, content: string, post: int}>
	 */
	private function templates(): array {
		$out  = array();
		$seen = array();
		foreach ( Templates::all() as $entry ) {
			$template = get_block_template( $entry['id'], 'wp_template_part' === $entry['type'] ? 'wp_template_part' : 'wp_template' );
			if ( ! $template instanceof \WP_Block_Template ) {
				continue;
			}
			$seen[ $entry['type'] . ':' . $entry['slug'] ] = true;
			$out[] = array(
				'type'    => $entry['type'],
				'slug'    => $entry['slug'],
				'title'   => $entry['title'],
				'area'    => (string) ( $template->area ?? '' ),
				'custom'  => ! empty( $template->is_custom ) && ! $this->in_hierarchy( $entry['slug'] ),
				'content' => (string) $template->content,
				'post'    => (int) ( $template->wp_id ?? 0 ),
			);
		}
		// The templates of content types: Histeon CMS answers these slugs with the template the project assigned.
		$slugs = array();
		foreach ( array_keys( ContentTypes::live()['types'] ) as $type ) {
			$slugs[] = 'single-' . $type;
			$slugs[] = 'archive-' . $type;
		}
		foreach ( array() === $slugs ? array() : get_block_templates( array( 'slug__in' => $slugs ), 'wp_template' ) as $template ) {
			if ( isset( $seen[ 'wp_template:' . $template->slug ] ) ) {
				continue;
			}
			$out[] = array(
				'type'    => 'wp_template',
				'slug'    => (string) $template->slug,
				'title'   => (string) $template->slug,
				'area'    => '',
				'custom'  => false,
				'content' => (string) $template->content,
				'post'    => (int) ( $template->wp_id ?? 0 ),
			);
		}
		// Pages Histeon CMS shows with one of its templates: in WordPress the page names its template.
		foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_xb_page_profile_template_ref', 'suppress_filters' => true ) ) as $page ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- pages with a Histeon template only.
			$ref      = (string) get_post_meta( $page->ID, '_xb_page_profile_template_ref', true );
			$template = get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'numberposts' => 1, 'meta_key' => '_xb_page_profile_ref', 'meta_value' => $ref, 'suppress_filters' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			// A page shown with `page`, the template WordPress picks by itself, needs nothing.
			if ( isset( $template[0] ) && ! $this->in_hierarchy( $template[0]->post_name ) ) {
				$this->pages[] = array(
					'id'       => $page->ID,
					'title'    => get_the_title( $page ),
					'template' => $template[0]->post_name,
				);
			}
		}
		return $out;
	}

	/**
	 * The pages Histeon CMS shows with a template of its own, and that template (`_wp_page_template` after the exit),
	 * without building the theme.
	 *
	 * @return list<array{id: int, title: string, template: string}>
	 */
	public function page_templates(): array {
		$this->pages = array();
		$this->templates();
		return $this->pages;
	}

	/** Whether a slug is one WordPress picks by itself (index, single-post, archive-…): not a template to choose. */
	private function in_hierarchy( string $slug ): bool {
		return 1 === preg_match( '/^(index|home|front-page|singular|single|page|archive|author|category|tag|taxonomy|date|search|404|attachment|privacy-policy)(-.+)?$/', $slug );
	}

	/**
	 * A template or part converted to Fondamenta's blocks, in the context the builder shows it in.
	 *
	 * @param array{type: string, slug: string, title: string, area: string, custom: bool, content: string, post: int} $template
	 */
	private function convert( array $template ): string {
		if ( ! str_contains( $template['content'], '<!-- wp:xb/' ) ) {
			return $template['content'];
		}
		$post = $template['post'] > 0 ? get_post( $template['post'] ) : null;
		if ( ! $post instanceof \WP_Post ) {
			// A template that is only a file of the theme (Fondamenta's own): the same conversion, from its content.
			$post = new \WP_Post(
				(object) array(
					'ID'           => 0,
					'post_type'    => $template['type'],
					'post_name'    => $template['slug'],
					'post_title'   => $template['title'],
					'post_content' => $template['content'],
					'post_status'  => 'publish',
					'post_author'  => 0,
					'filter'       => 'raw',
				)
			);
		}
		$done = Prova::convert( $post, true );
		foreach ( $done['notes'] as $note ) {
			/* translators: 1: template, 2: what, 3: reason */
			$this->notes[] = sprintf( __( '%1$s: %2$s, %3$s', 'xb' ), $template['slug'], $note['cosa'], $note['reason'] );
		}
		return \XB\Blocks::serialize( $done['blocks'] );
	}

	/** The site's sheet: what Histeon prints on its pages, whole, in the order it prints it. */
	private function sheet(): string {
		$css = '/* ' . sprintf( /* translators: %s: date */ __( 'Foglio del sito, generato il %s', 'xb' ), gmdate( 'Y-m-d H:i' ) ) . " */\n";
		if ( Reset::enabled() ) {
			$css .= (string) file_get_contents( dirname( XB_FILE ) . '/assets/reset/xb-reset.css' ) . "\n";
		}
		$issues = array();
		foreach ( ( new Tokens( Tokens::HISTOS ) )->sheets( '', $issues ) as $sheet ) {
			$css .= $sheet . "\n";
		}
		$framework = Plugin::instance()->frameworks->active()->id();
		if ( 'xb-tokens' !== $framework ) {
			/* translators: %s: CSS framework */
			$this->notes[] = sprintf( __( 'Il framework «%s» è un plugin a parte: resta installato e porta il suo CSS.', 'xb' ), $framework );
		}
		$css     .= LocalFonts::css();
		$registry = new StyleRegistry();
		$own      = '';
		foreach ( $registry->globals( true, true ) as $global ) {
			$own .= CssCompiler::sanitize( (string) $global['css'] ) . "\n";
		}
		foreach ( $registry->entries( true, true ) as $entry ) {
			$own .= CssCompiler::compile( (string) $entry['selector'], (string) $entry['css'] ) . "\n";
		}
		return $css . CssCompiler::sanitize( NamedQueries::resolve( $own ) ) . "\n";
	}

	/**
	 * Fondamenta's settings, with the design system's presets in the editor's menus.
	 *
	 * @param list<array<string, mixed>> $custom
	 * @param list<array<string, mixed>> $parts
	 * @return array<string, mixed>
	 */
	private function theme_json( array $custom, array $parts ): array {
		$parent = json_decode( (string) file_get_contents( get_theme_root() . '/' . self::PARENT . '/theme.json' ), true );
		$json   = is_array( $parent ) ? $parent : array( 'version' => 3 );
		$tokens = 'xb-tokens' === Plugin::instance()->frameworks->active()->id();
		if ( $tokens ) {
			// The presets keep their slugs (the classes in the contents name them), not the builder's name.
			$named = static fn ( array $presets ): array => array_map( static fn ( array $p ): array => array( 'name' => preg_replace( '/^Histeon /', '', (string) $p['name'] ) ) + $p, $presets );
			$json['settings']['color']['palette']        = $named( \XB\Framework\HistosCss\ThemeJson::palette() );
			$json['settings']['typography']['fontSizes'] = $named( \XB\Framework\HistosCss\ThemeJson::font_sizes() );
			$json['settings']['spacing']['spacingSizes'] = $named( \XB\Framework\HistosCss\ThemeJson::spacing_sizes() );
			$json['settings']['shadow']['presets']       = $named( \XB\Framework\HistosCss\ThemeJson::shadows() );
		}
		$families = self::font_families( $tokens );
		if ( array() !== $families ) {
			$json['settings']['typography']['fontFamilies'] = $families;
		}
		$json['templateParts'] = $parts;
		if ( array() !== $custom ) {
			$json['customTemplates'] = $custom;
		}
		return $json;
	}

	/**
	 * The fonts in the editor's menus: the design's text, heading and code stacks, then every local family that
	 * does not head one of them. Their `@font-face` rules are in the site sheet, in the pages and in the editor.
	 *
	 * @return list<array{name: string, slug: string, fontFamily: string}>
	 */
	public static function font_families( bool $tokens ): array {
		$out   = array();
		$heads = array();
		$first = static fn ( string $stack ): string => strtolower( trim( (string) strtok( $stack, ',' ), " \"'" ) );
		if ( $tokens ) {
			$design = \XB\Framework\HistosCss\SiteDesign::current();
			$stacks = array(
				'testo'  => array( __( 'Testo', 'xb' ), $design['font_body'] ?? '' ),
				'titoli' => array( __( 'Titoli', 'xb' ), $design['font_heading'] ?? '' ),
				'codice' => array( __( 'Codice', 'xb' ), $design['font_mono'] ?? '' ),
			);
			foreach ( $stacks as $slug => [ $label, $stack ] ) {
				$stack = is_string( $stack ) ? trim( $stack ) : '';
				if ( '' === $stack ) {
					continue;
				}
				if ( 'titoli' === $slug && isset( $out[0] ) && 'testo' === $out[0]['slug'] && $stack === $out[0]['fontFamily'] ) {
					// One font for text and headings: one entry that says so.
					$out[0]['name'] = __( 'Testo e titoli', 'xb' );
					continue;
				}
				if ( in_array( $stack, array_column( $out, 'fontFamily' ), true ) ) {
					continue;
				}
				$out[]   = array(
					'name'       => $label,
					'slug'       => $slug,
					'fontFamily' => $stack,
				);
				$heads[] = $first( $stack );
			}
		}
		foreach ( LocalFonts::families() as $family ) {
			if ( in_array( strtolower( $family ), $heads, true ) ) {
				continue;
			}
			$out[] = array(
				'name'       => $family,
				'slug'       => sanitize_title( $family ),
				'fontFamily' => '"' . $family . '"',
			);
		}
		return $out;
	}

	private function style( string $name, string $slug, string $version ): string {
		$title = '' !== $name ? $name : $slug;
		/* translators: %s: site name */
		$description = sprintf( __( 'Il tema di %s: il suo foglio, i suoi template e le sue parti, sopra Fondamenta.', 'xb' ), $title );
		return "/*\nTheme Name: " . str_replace( array( "\n", '*/' ), ' ', $title ) . "\nTemplate: " . self::PARENT . "\nDescription: " . str_replace( array( "\n", '*/' ), ' ', $description ) . "\nVersion: " . $version . "\nRequires at least: 6.9\nRequires PHP: 8.3\nLicense: GPL-3.0-or-later\nText Domain: " . $slug . "\n*/\n";
	}

	/**
	 * The scripts the builder prints: each document's own (a page's on that page; a template's, a part's or a
	 * component's on every page, as they run wherever those are used), and the site's (libraries and files, with
	 * where and how).
	 *
	 * @return array{files: array<string, string>, documents: list<array{file: string, pagine: list<int>, template: list<string>, parti: list<string>}>, site: list<array<string, mixed>>}
	 */
	private function scripts(): array {
		$out = array(
			'files'     => array(),
			'documents' => array(),
			'site'      => array(),
		);
		$ids = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => \XB\Scripts\Scripts::META, 'suppress_filters' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- documents with a script only.
		foreach ( get_posts( array( 'post_type' => array( 'wp_block', 'wp_template', 'wp_template_part' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => \XB\Scripts\Scripts::META, 'suppress_filters' => true ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$ids[] = $id;
		}
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$code = trim( \XB\Scripts\Scripts::get( $id ) );
			if ( '' === $code ) {
				continue;
			}
			$file                  = 'assets/js/documento-' . $id . '.js';
			$out['files'][ $file ] = $code . "\n";
			$out['documents'][]    = array( 'file' => $file ) + $this->where( $id );
		}
		$site = get_option( \XB\Scripts\SiteScripts::OPTION, array() );
		foreach ( is_array( $site ) ? $site : array() as $script ) {
			if ( is_array( $script ) && is_string( $script['src'] ?? null ) && '' !== $script['src'] ) {
				$out['site'][] = array(
					'id'        => (string) ( $script['id'] ?? count( $out['site'] ) ),
					'src'       => (string) $script['src'],
					'scope'     => (string) ( $script['scope'] ?? 'site' ),
					'position'  => 'head' === ( $script['position'] ?? '' ) ? 'head' : 'footer',
					'strategy'  => (string) ( $script['strategy'] ?? '' ),
					'integrity' => (string) ( $script['integrity'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Where a document is shown, as Histeon prints its script there: a content on its own page, a template or a
	 * part where it is used, a component in every content, template or part that uses it (through other components
	 * too).
	 *
	 * @return array{pagine: list<int>, template: list<string>, parti: list<string>}
	 */
	private function where( int $id ): array {
		$out   = array(
			'pagine'   => array(),
			'template' => array(),
			'parti'    => array(),
		);
		$queue = array( $id );
		$seen  = array();
		while ( array() !== $queue ) {
			$doc = (int) array_pop( $queue );
			if ( isset( $seen[ $doc ] ) ) {
				continue;
			}
			$seen[ $doc ] = true;
			$post         = get_post( $doc );
			$type         = $post instanceof \WP_Post ? $post->post_type : '';
			$name         = $post instanceof \WP_Post ? $post->post_name : '';
			if ( 'wp_template' === $type ) {
				$out['template'][] = $name;
			} elseif ( 'wp_template_part' === $type ) {
				$out['parti'][] = $name;
			} elseif ( 'wp_block' === $type ) {
				$key = (string) get_post_meta( $doc, \XB\Render\ComponentRegistry::META_KEY, true );
				foreach ( \XB\Render\ComponentUsage::usages( $key, 2000 ) as $usage ) {
					$queue[] = $usage['id'];
				}
			} elseif ( '' !== $type ) {
				$out['pagine'][] = $doc;
			}
		}
		return $out;
	}

	/**
	 * @param list<array{file: string, pagine: list<int>, template: list<string>, parti: list<string>}> $documents
	 * @param list<array<string, mixed>>           $site
	 */
	private function functions( string $slug, string $version, array $documents, array $site ): string {
		$handle = var_export( $slug . '-sito', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a PHP literal for the generated file.
		$ver    = var_export( $version, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$out    = "<?php\n/**\n * The site's sheet, on its pages and in the block editor, and the site's scripts. Generated: change it here,\n * in assets/sito.css or in assets/js.\n *\n * @package " . $slug . "\n */\n\ndeclare(strict_types=1);\n\n"
			. "add_action(\n\t'wp_enqueue_scripts',\n\tstatic function (): void {\n\t\twp_enqueue_style( " . $handle . ", get_stylesheet_directory_uri() . '/assets/sito.css', array(), " . $ver . " );\n\t}\n);\n\n"
			. "add_action(\n\t'after_setup_theme',\n\tstatic function (): void {\n\t\tadd_theme_support( 'editor-styles' );\n\t\tadd_editor_style( 'assets/sito.css' );\n\t}\n);\n";
		if ( array() !== $documents ) {
			$out .= "\n// The parts this page shows, for their scripts.\nadd_filter(\n\t'render_block',\n\tstatic function ( string \$html, array \$block ): string {\n\t\tif ( 'core/template-part' === ( \$block['blockName'] ?? null ) && is_string( \$block['attrs']['slug'] ?? null ) ) {\n\t\t\t\$GLOBALS['sito_parti'][] = \$block['attrs']['slug'];\n\t\t}\n\t\treturn \$html;\n\t},\n\t10,\n\t2\n);\n\n"
				. "// The documents' own scripts, as modules after the content, where the document is shown: its page, its template,\n// its part, or (for a component) whatever uses it.\nadd_action(\n\t'wp_footer',\n\tstatic function (): void {\n"
				. "\t\t\$template = (string) substr( (string) strrchr( '/' . ( \$GLOBALS['_wp_current_template_id'] ?? '' ), '/' ), 1 );\n"
				. "\t\t\$parts    = (array) ( \$GLOBALS['sito_parti'] ?? array() );\n"
				. "\t\tforeach ( " . var_export( $documents, true ) . " as \$script ) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				. "\t\t\t\$here = ( is_singular() && in_array( get_queried_object_id(), \$script['pagine'], true ) ) || in_array( \$template, \$script['template'], true ) || array() !== array_intersect( \$parts, \$script['parti'] );\n"
				. "\t\t\tif ( \$here ) {\n"
				. "\t\t\t\twp_print_script_tag( array( 'type' => 'module', 'src' => get_stylesheet_directory_uri() . '/' . \$script['file'] . '?ver=' . " . $ver . " ) );\n\t\t\t}\n\t\t}\n\t},\n\t20\n);\n";
		}
		if ( array() !== $site ) {
			$out .= "\n// The site's scripts: every page, or the pages of one content type; in the head or after the content.\nforeach ( array( 'head' => 'wp_head', 'footer' => 'wp_footer' ) as \$position => \$hook ) {\n\tadd_action(\n\t\t\$hook,\n\t\tstatic function () use ( \$position ): void {\n\t\t\tforeach ( " . var_export( $site, true ) . " as \$script ) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				. "\t\t\t\t\$type = substr( \$script['scope'], 5 );\n"
				. "\t\t\t\tif ( \$script['position'] !== \$position || ( 'site' !== \$script['scope'] && ! is_singular( \$type ) && ! is_post_type_archive( \$type ) ) ) {\n\t\t\t\t\tcontinue;\n\t\t\t\t}\n"
				. "\t\t\t\t\$attrs = array( 'id' => 'sito-script-' . \$script['id'], 'src' => \$script['src'] );\n"
				. "\t\t\t\tif ( 'module' === \$script['strategy'] ) {\n\t\t\t\t\t\$attrs['type'] = 'module';\n\t\t\t\t} elseif ( in_array( \$script['strategy'], array( 'defer', 'async' ), true ) ) {\n\t\t\t\t\t\$attrs[ \$script['strategy'] ] = true;\n\t\t\t\t}\n"
				. "\t\t\t\tif ( '' !== \$script['integrity'] ) {\n\t\t\t\t\t\$attrs['integrity']   = \$script['integrity'];\n\t\t\t\t\t\$attrs['crossorigin'] = 'anonymous';\n\t\t\t\t}\n"
				. "\t\t\t\twp_print_script_tag( \$attrs );\n\t\t\t}\n\t\t},\n\t\t20\n\t);\n}\n";
		}
		return $out;
	}

	/** In functions.php: the definitions of the site's filtered lists, for Fondamenta's filters (inc/filtri.php). */
	private const FACETS = "\n// The filtered lists of the site (search, terms, fields, sorting, pages), for Fondamenta's filters.\nadd_filter(\n\t'fondamenta_facets',\n\tstatic function ( array \$facets ): array {\n\t\t\$own = json_decode( (string) file_get_contents( __DIR__ . '/facets.json' ), true );\n\t\treturn is_array( \$own ) ? \$own + \$facets : \$facets;\n\t}\n);\n";

	private function readme( string $name ): string {
		/* translators: %s: site name */
		$out = '# ' . sprintf( __( 'Il tema di %s', 'xb' ), $name ) . "\n\n" . __( 'Tema figlio di Fondamenta, generato dal sito. Contiene il foglio del sito (assets/sito.css), theme.json, i template e le parti convertiti nei blocchi di Fondamenta.', 'xb' ) . "\n";
		if ( array() !== $this->pages ) {
			$out .= "\n" . __( 'Pagine che usano un template proprio (la conversione dei contenuti lo scriverà in ognuna):', 'xb' ) . "\n\n";
			foreach ( $this->pages as $page ) {
				$out .= '- ' . $page['title'] . ' → `' . $page['template'] . "`\n";
			}
		}
		if ( array() !== $this->notes ) {
			$out .= "\n" . __( 'Cosa non è stato portato:', 'xb' ) . "\n\n";
			foreach ( array_unique( $this->notes ) as $note ) {
				$out .= '- ' . $note . "\n";
			}
		}
		return $out;
	}
}
