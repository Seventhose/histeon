<?php

declare(strict_types=1);

namespace {
	// Unit tests run without WordPress: translations are the text itself.
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			unset( $domain );
			return $text;
		}
	}
}

namespace XB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use XB\Framework\HistosCss\Color;
	use XB\Framework\HistosCss\Design;
	use XB\Framework\HistosCss\Scale;
	use XB\Framework\HistosCss\Tokens;
	use XB\Framework\HistosCss\Utilities;

	/** Histeon CSS: colours, fluid scales, parameters, tokens and classes — docs/spec/histos-css.md. */
	final class HistosCssTest extends TestCase {

		public function test_colours_go_to_oklch_and_back_unchanged(): void {
			foreach ( array( '#2271b1', '#000000', '#ffffff', '#e5533d', '#0a7d32', '#777777' ) as $hex ) {
				$color = Color::parse( $hex );
				$this->assertNotNull( $color, $hex );
				[ $r, $g, $b ] = $color->to_srgb();
				$this->assertSame( $hex, sprintf( '#%02x%02x%02x', (int) round( $r * 255 ), (int) round( $g * 255 ), (int) round( $b * 255 ) ) );
			}
			$this->assertSame( 'oklch(55% 0.2 265)', Color::parse( 'oklch(55% 0.2 265)' )?->css() );
			$this->assertSame( 'oklch(55% 0.2 265)', Color::parse( 'oklch(0.55 0.2 265deg)' )?->css() );
			$this->assertSame( Color::parse( '#abc' )?->css(), Color::parse( '#aabbcc' )?->css() );
			$this->assertSame( Color::parse( 'rgb(34, 113, 177)' )?->css(), Color::parse( '#2271b1' )?->css() );
			$this->assertSame( 'oklch(100% 0 0)', Color::parse( '#ffffff' )?->css(), 'a grey has no chroma and no hue' );
		}

		public function test_the_palette_says_how_readable_it_is(): void {
			$pairs = Tokens::contrast( Design::DEFAULTS );
			$this->assertCount( 10 + count( Design::FAMILIES ) - 1, $pairs, 'the tertiary family is off by default' );
			foreach ( $pairs as $pair ) {
				$this->assertTrue( $pair['ok'], $pair['text'] . ' on ' . $pair['bg'] . ': ' . $pair['ratio'] );
			}
			$grey = Tokens::contrast( Design::validate( array( 'accent' => 'oklch(58% 0 0)' ) ) );
			$bad  = array_values( array_filter( $grey, static fn ( array $p ): bool => ! $p['ok'] ) );
			$this->assertSame( array( 'xb-on-accent' ), array_column( $bad, 'text' ), 'a mid grey: neither white nor dark text reaches 4.5:1' );
			$this->assertLessThan( 4.5, $bad[0]['ratio'] );
		}

		public function test_unreadable_colours_are_refused(): void {
			foreach ( array( 'red', '#12', 'url(x)', 'oklch(50% 0.1 1);}body{', 'rgb(1,2)', '', 'var(--x)' ) as $bad ) {
				$this->assertNull( Color::parse( $bad ), $bad );
			}
		}

		public function test_shades_run_from_light_to_dark_and_text_reads_on_every_colour(): void {
			$primary = Color::parse( '#2271b1' );
			$this->assertNotNull( $primary );
			$previous = 1.1;
			foreach ( array_keys( Color::SHADES ) as $step ) {
				$shade = $primary->shade( $step );
				$this->assertLessThan( $previous, $shade->l, (string) $step );
				$this->assertEqualsWithDelta( $primary->h, $shade->h, 0.01, 'the hue stays' );
				$previous = $shade->l;
			}
			foreach ( array( '#2271b1', '#ffd54f', '#111111', '#f5f5f5', '#e5533d' ) as $hex ) {
				$color = Color::parse( $hex );
				$this->assertNotNull( $color );
				$this->assertGreaterThanOrEqual( 4.5, Color::contrast( $color, $color->on() ), $hex . ': text on it meets WCAG AA' );
			}
			$this->assertEqualsWithDelta( 21.0, Color::contrast( Color::parse( '#000' ) ?? $primary, Color::parse( '#fff' ) ?? $primary ), 0.01 );
		}

		public function test_fluid_steps_reach_their_sizes_at_the_two_viewports(): void {
			$this->assertSame( 'clamp(1rem, 0.9583rem + 0.1852vw, 1.125rem)', Scale::fluid( 16, 18, 360, 1440 ) );
			$this->assertSame( '1rem', Scale::fluid( 16, 16, 360, 1440 ), 'no growth: a plain size' );
			$this->assertSame( 'clamp(1rem, 1.6667rem - 0.7407vw, 1.5rem)', Scale::fluid( 24, 16, 360, 1440 ), 'a step that shrinks on large screens' );
			[ $min, $max ] = Scale::step( 2, 16, 18, 1.2, 1.25 );
			$this->assertEqualsWithDelta( 23.04, $min, 0.001 );
			$this->assertEqualsWithDelta( 28.125, $max, 0.001 );
		}

		public function test_parameters_are_checked_and_completed(): void {
			$this->assertSame( Design::DEFAULTS, Design::validate( null ) );
			$design = Design::validate( array( 'primary' => '#2271b1', 'text_max' => '19' ) );
			$this->assertSame( Color::parse( '#2271b1' )?->css(), $design['primary'] );
			$this->assertSame( 19.0, $design['text_max'] );
			$this->assertSame( 16, $design['text_min'], 'the rest from the defaults' );
			$this->assertSame( array( 'primary' => $design['primary'], 'text_max' => 19.0 ), Design::changes( $design ) );
			$this->assertSame( array(), Design::changes( Design::DEFAULTS ) );
		}

		public function test_parameters_that_could_inject_css_are_refused(): void {
			$attempts = array(
				array( 'font_body' => 'Inter; } body { display:none' ),
				array( 'font_body' => 'Inter</style><script>alert(1)</script>' ),
				array( 'font_body' => '"Inter' ),
				array( 'font_body' => 'url(https://evil.example/x)' ),
				array( 'primary' => 'red;}' ),
				array( 'text_min' => '16px;}' ),
				array( 'text_min' => 999 ),
				array( 'viewport_min' => 1200, 'viewport_max' => 1000 ),
				array( 'unknown' => 1 ),
				'not an object',
			);
			foreach ( $attempts as $attempt ) {
				try {
					Design::validate( $attempt );
					$this->fail( 'accepted: ' . wp_json_encode_fallback( $attempt ) );
				} catch ( \InvalidArgumentException ) {
					$this->addToAssertionCount( 1 );
				}
			}
			$this->assertTrue( Design::font_stack( '"Source Sans 3", Inter, system-ui, sans-serif' ) );
			$this->assertTrue( Design::font_stack( "'Playfair Display', serif" ) );
		}

		public function test_tokens_keep_the_old_names_and_add_the_full_scales(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			// The 25 tokens of the first Histeon Tokens are still there, so other frameworks' alias layers still match.
			foreach ( array( 'xb-space-xs', 'xb-space-s', 'xb-space-m', 'xb-space-l', 'xb-space-xl', 'xb-space-xxl', 'xb-text-s', 'xb-text-m', 'xb-text-l', 'xb-text-xl', 'xb-text-xxl', 'xb-primary', 'xb-primary-light', 'xb-primary-dark', 'xb-secondary', 'xb-bg', 'xb-surface', 'xb-text', 'xb-heading', 'xb-radius-s', 'xb-radius-m', 'xb-radius-l', 'xb-shadow-s', 'xb-shadow-m', 'xb-shadow-l' ) as $name ) {
				$this->assertArrayHasKey( $name, $tokens, $name );
			}
			foreach ( array_diff( Design::FAMILIES, array( 'tertiary' ) ) as $family ) {
				$this->assertArrayHasKey( 'xb-' . $family . '-50', $tokens );
				$this->assertArrayHasKey( 'xb-' . $family . '-950', $tokens );
				$this->assertArrayHasKey( 'xb-on-' . $family, $tokens );
			}
			$this->assertSame( '0.5rem', $tokens['xb-radius-m'] );
			$this->assertSame( 'color-mix(in oklch, var(--xb-accent) 30%, transparent)', $tokens['xb-accent-a30'], 'transparencies follow the colour' );
			// The defaults' tokens are in the stylesheet already: only the rules the defaults switch on are left.
			$this->assertStringNotContainsString( ':root{', Tokens::css_changes( Design::DEFAULTS ) );
			$changed = Tokens::css_changes( Design::validate( array( 'primary' => '#e5533d', 'radius' => 4 ) ) );
			$this->assertStringContainsString( '--xb-primary:' . Color::parse( '#e5533d' )?->css() . ';', $changed );
			$this->assertStringContainsString( '--xb-radius-m:0.25rem;', $changed );
			$this->assertStringNotContainsString( '--xb-space-m', $changed, 'only what changes' );
			$this->assertSame( 1, preg_match( '/^:root\{[^{}<>]*\}/', $changed ), 'the tokens first, in one rule' );
			$this->assertStringNotContainsString( '<', $changed );
		}

		public function test_classes_are_ours_and_style_no_bare_element(): void {
			$css = Utilities::stylesheet();
			preg_match_all( '/^([^{\n\t]+)\{/m', $css, $m );
			foreach ( $m[1] as $selector ) {
				$selector = trim( $selector );
				if ( str_starts_with( $selector, '@' ) ) {
					continue;
				}
				// The selector list at depth 0 (commas inside :is() and :where() belong to one selector).
				foreach ( preg_split( '/,(?![^()]*\))/', $selector ) ?: array() as $part ) {
					$this->assertMatchesRegularExpression( '/\.xb-[a-z0-9-]+/', $part, 'every selector hangs on a Histeon CSS class: ' . $part );
				}
			}
			$classes = array_merge( ...array_values( Utilities::classes() ) );
			foreach ( array( 'xb-container', 'xb-section', 'xb-grid-3', 'xb-grid-1-2', 'xb-p-m', 'xb-text-l', 'xb-h2', 'xb-bg-primary-700', 'xb-bg-primary-a20', 'xb-btn-primary', 'xb-visually-hidden', 'xb-scheme-dark', 'xb-hide-s', 'xb-input', 'xb-label' ) as $name ) {
				$this->assertContains( $name, $classes, $name );
			}
			foreach ( $classes as $name ) {
				$this->assertMatchesRegularExpression( '/^xb-[a-z0-9-]+$/', $name );
				$this->assertStringNotContainsString( '--', $name, 'other frameworks match [class*="x--"]: ' . $name );
			}
		}

		public function test_recipes_are_clean_and_use_existing_tokens(): void {
			$recipes = \XB\Framework\HistosCss\Recipes::all();
			$this->assertGreaterThan( 30, count( $recipes ) );
			$this->assertSame( count( $recipes ), count( \XB\Framework\AcssToCore::clean_recipes( $recipes ) ), 'every recipe passes the same check as an imported one' );
			$tokens = Tokens::compute( Design::DEFAULTS );
			foreach ( $recipes as $name => $css ) {
				preg_match_all( '/var\(--(xb-[a-z0-9-]+)/', $css, $m );
				foreach ( $m[1] as $token ) {
					if ( ! in_array( $token, array( 'xb-grid-gap', 'xb-grid-min', 'xb-flow-space', 'xb-overlay' ), true ) ) {
						$this->assertArrayHasKey( $token, $tokens, "@$name uses --$token" );
					}
				}
			}
		}

		public function test_the_recipes_panel_groups_every_recipe_and_etch_names_point_at_one(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/editor/src/lib/recipes.ts' );
			$groups = substr( $source, (int) strpos( $source, 'export const CSS_GROUPS' ), (int) strpos( $source, 'export const ETCH_ALIASES' ) - (int) strpos( $source, 'export const CSS_GROUPS' ) );
			preg_match_all( "/names: \\[([^\\]]*)\\]/", $groups, $lists );
			$grouped = array();
			foreach ( $lists[1] as $list ) {
				preg_match_all( "/'([a-z0-9-]+)'/", $list, $names );
				$grouped = array_merge( $grouped, $names[1] );
			}
			$recipes = array_keys( \XB\Framework\HistosCss\Recipes::all() );
			sort( $recipes );
			$listed = array_values( array_unique( $grouped ) );
			sort( $listed );
			$this->assertSame( $recipes, $listed, 'the panel shows every Histeon recipe, and only those (editor/src/lib/recipes.ts)' );
			$aliases = substr( $source, (int) strpos( $source, 'export const ETCH_ALIASES' ), (int) strpos( $source, 'export function resolveRecipe' ) - (int) strpos( $source, 'export const ETCH_ALIASES' ) );
			preg_match_all( "/: '([a-z0-9-]+)',/", $aliases, $targets );
			$this->assertNotEmpty( $targets[1] );
			foreach ( $targets[1] as $target ) {
				$this->assertContains( $target, $recipes, "an Etch name points at @$target" );
			}
		}

		public function test_the_shipped_stylesheets_are_up_to_date(): void {
			$root = dirname( __DIR__, 2 );
			$this->assertSame( Tokens::stylesheet(), file_get_contents( $root . '/assets/tokens/xb-tokens.css' ), 'run php bin/build-histos-css.php' );
			$this->assertSame( Utilities::stylesheet(), file_get_contents( $root . '/assets/framework/histos.css' ), 'run php bin/build-histos-css.php' );
		}

		public function test_colour_options_switch_families_and_unify_lightness(): void {
			$off = Tokens::compute( Design::DEFAULTS );
			$this->assertArrayNotHasKey( 'xb-tertiary', $off, 'a family switched off prints nothing' );
			$this->assertSame( 'var(--xb-primary-600)', $off['xb-primary-hover'] );
			$on = Tokens::compute( Design::validate( array( 'tertiary_on' => true, 'tertiary' => '#7c3aed', 'warning_on' => 0, 'hover_shade' => 700, 'brand_lightness_on' => 1, 'brand_lightness' => 0.5 ) ) );
			$this->assertArrayHasKey( 'xb-tertiary-500', $on );
			$this->assertArrayNotHasKey( 'xb-warning', $on );
			$this->assertSame( 'var(--xb-accent-700)', $on['xb-accent-hover'] );
			foreach ( array( 'primary', 'secondary', 'tertiary', 'accent', 'neutral' ) as $family ) {
				$this->assertStringStartsWith( 'oklch(50% ', $on[ 'xb-' . $family ], $family . ': one lightness for the brand' );
			}
			$this->assertSame( $off['xb-success'], $on['xb-success'], 'the states keep theirs' );
			foreach ( array( array( 'tertiary_on' => 2 ), array( 'hover_shade' => '650' ), array( 'scheme' => 'auto' ), array( 'role_bg' => 'primary-650' ), array( 'role_bg' => 'var(--x)' ), array( 'primary_dark' => 'red;}' ), array( 'brand_lightness' => 0.9 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( (string) json_encode( $bad ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_roles_and_automatic_contrast(): void {
			$tokens = Tokens::compute( Design::validate( array( 'role_bg' => 'neutral-500', 'role_link' => 'accent-600' ) ) );
			$this->assertSame( 'var(--xb-neutral-500)', $tokens['xb-bg'] );
			$this->assertSame( 'var(--xb-accent-600)', $tokens['xb-link'], 'a role chosen in the panel stays as chosen' );
			$this->assertNotSame( 'var(--xb-neutral-900)', $tokens['xb-text'], 'automatic text moves to a shade that reads' );
			foreach ( Tokens::contrast( Design::validate( array( 'role_bg' => 'neutral-500' ) ) ) as $pair ) {
				if ( 'xb-bg' === $pair['bg'] && 'xb-link' !== $pair['text'] ) {
					$this->assertTrue( $pair['ok'], $pair['text'] . ': ' . $pair['ratio'] );
				}
			}
			$manual = Tokens::compute( Design::validate( array( 'role_bg' => 'neutral-500', 'auto_contrast' => 0 ) ) );
			$this->assertSame( 'var(--xb-neutral-900)', $manual['xb-text'], 'without the automatic relationships nothing moves' );
			$this->assertSame( 'var(--xb-white)', Tokens::compute( Design::validate( array( 'role_bg' => 'secondary', 'secondary_on' => 0 ) ) )['xb-bg'], 'a role on a family switched off falls back' );
		}

		public function test_the_dark_theme_from_one_switch_reads_everywhere(): void {
			$this->assertStringNotContainsString( 'xb-scheme-dark', Tokens::css_changes( Design::DEFAULTS ) );
			foreach ( array( 'light', 'dark', 'system' ) as $scheme ) {
				$design = Design::validate( array( 'dark_on' => 1, 'scheme' => $scheme ) );
				$css    = Tokens::css_changes( $design );
				$this->assertStringContainsString( ':root .xb-scheme-dark,:root.xb-scheme-dark{', $css );
				$this->assertSame( 'dark' === $scheme, str_contains( $css, '}:root:not(.xb-scheme-light){' ) );
				$this->assertSame( 'system' === $scheme, str_contains( $css, '@media (prefers-color-scheme:dark){:root:not(.xb-scheme-light){' ) );
				$this->assertStringNotContainsString( '<', $css );
				$dark = array_filter( Tokens::contrast( $design ), static fn ( array $p ): bool => 'dark' === ( $p['scheme'] ?? '' ) );
				$this->assertCount( 10 + 8, $dark );
				foreach ( $dark as $pair ) {
					$this->assertTrue( $pair['ok'], 'dark: ' . $pair['text'] . ' on ' . $pair['bg'] . ': ' . $pair['ratio'] );
				}
			}
			$own = Tokens::dark( Design::validate( array( 'dark_on' => 1, 'primary_dark' => 'oklch(75% 0.12 265)' ) ) );
			$this->assertSame( 'oklch(75% 0.12 265)', $own['xb-primary'] );
			$this->assertSame( 'var(--xb-primary-300)', $own['xb-primary-hover'] );
			$this->assertSame( 'var(--xb-neutral-950)', $own['xb-bg'] );
		}

		public function test_typography_styles_every_heading_of_a_level(): void {
			$defaults = Tokens::compute( Design::DEFAULTS );
			$this->assertStringStartsWith( 'clamp(', $defaults['xb-h1'], 'the headings have their own scale by default (doc 19)' );
			$this->assertSame( 'var(--xb-text-4xl)', Tokens::compute( Design::validate( array( 'heading_scale_on' => 0 ) ) )['xb-h1'], 'without it they follow the text' );
			$this->assertSame( 'var(--xb-heading-weight)', $defaults['xb-h2-weight'] );
			$this->assertSame( '700', $defaults['xb-heading-weight'] );
			$this->assertSame( '1.6', $defaults['xb-leading-normal'] );
			$this->assertArrayNotHasKey( 'xb-font-accent', $defaults );
			$this->assertStringNotContainsString( ':where(h1', Tokens::css_changes( Design::DEFAULTS ), 'no style on bare headings by default' );

			$design = Design::validate( array( 'heading_scale_on' => 1, 'headings_on' => 1, 'h2_weight' => 300, 'heading_tracking' => -0.02, 'root_size' => 87.5, 'text_on' => 1, 'font_accent' => '', 'font_display' => 'Georgia, serif', 'heading_font' => 'display' ) );
			$tokens = Tokens::compute( $design );
			$this->assertStringStartsWith( 'clamp(', $tokens['xb-h1'] );
			$this->assertSame( '300', $tokens['xb-h2-weight'] );
			$this->assertSame( 'var(--xb-heading-weight)', $tokens['xb-h3-weight'] );
			$this->assertSame( '-0.02em', $tokens['xb-heading-tracking'] );
			$this->assertSame( 'var(--xb-font-display, var(--xb-font-heading))', $tokens['xb-heading-font'] );
			$this->assertSame( 'Georgia, serif', $tokens['xb-font-display'] );
			$css = Tokens::css_changes( $design );
			$this->assertStringContainsString( 'html{font-size:87.5%}', $css );
			$this->assertStringContainsString( ':where(h2){text-wrap:var(--xb-h2-wrap, var(--xb-heading-wrap));letter-spacing:var(--xb-h2-tracking, var(--xb-heading-tracking));max-inline-size:var(--xb-h2-measure, var(--xb-heading-measure));font-family:var(--xb-h2-font);font-size:var(--xb-h2);font-weight:var(--xb-h2-weight);line-height:var(--xb-h2-leading);text-transform:var(--xb-h2-transform);color:var(--xb-h2-color)}', $css );
			// A step's own size and a level's own colour, font and case.
			$own = Tokens::compute( Design::validate( array( 'size_text_m_min' => 17, 'size_text_m_max' => 19, 'size_h1_min' => 44, 'size_h1_max' => 72, 'h2_color' => 'primary', 'h3_font' => 'mono', 'h4_case' => 'uppercase' ) ) );
			$this->assertSame( 'clamp(1.0625rem, 1.0208rem + 0.1852vw, 1.1875rem)', $own['xb-text-m'] );
			$this->assertStringStartsWith( 'clamp(2.75rem,', $own['xb-h1'] );
			$this->assertSame( 'var(--xb-primary)', $own['xb-h2-color'] );
			$this->assertSame( 'var(--xb-font-mono)', $own['xb-h3-font'] );
			$this->assertSame( 'uppercase', $own['xb-h4-transform'] );
			$this->assertSame( 'var(--xb-heading)', $own['xb-h5-color'], 'auto: as all the headings' );
			$half = Tokens::compute( Design::validate( array( 'size_text_m_min' => 17 ) ) );
			$this->assertSame( Tokens::compute( Design::DEFAULTS )['xb-text-m'], $half['xb-text-m'], 'both sizes, or the scale' );
			$this->assertStringContainsString( ':where(body){', $css );
			$this->assertStringContainsString( 'font-weight:var(--xb-h2-weight)', Utilities::stylesheet(), 'the .xb-h2 class follows the level too' );
			foreach ( array( array( 'heading_case' => 'small-caps' ), array( 'heading_font' => 'serif' ), array( 'font_accent' => 'x;}' ), array( 'font_body' => '' ), array( 'h1_weight' => 1000 ), array( 'root_size' => 50 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( (string) json_encode( $bad ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_space_and_layout_keep_the_defaults_and_add_the_content_grid(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( 'var(--xb-space-2xl)', $tokens['xb-section-m'], 'multipliers at 1: the sections as before' );
			$this->assertSame( 'var(--xb-section-m)', $tokens['xb-section-pad'] );
			$this->assertSame( 'var(--xb-space-m)', $tokens['xb-gutter'] );
			$this->assertSame( 'var(--xb-space-m)', $tokens['xb-grid-gap'] );
			$this->assertSame( '14rem', $tokens['xb-grid-min'], 'the auto grid keeps its 14rem' );
			$this->assertSame( 'var(--xb-header-height)', $tokens['xb-sticky-top'] );

			$design = Design::validate( array( 'section_multiplier_min' => 0.5, 'section_multiplier_max' => 2, 'section_padding' => 'xl', 'gutter_min' => 16, 'gutter_max' => 40, 'grid_fill' => 'auto-fill', 'content_gap' => 'l', 'scroll_offset_on' => 1 ) );
			$tokens = Tokens::compute( $design );
			$this->assertStringStartsWith( 'clamp(', $tokens['xb-section-m'] );
			$this->assertSame( 'var(--xb-section-xl)', $tokens['xb-section-pad'] );
			$this->assertSame( 'clamp(1rem, 0.5rem + 2.2222vw, 2.5rem)', $tokens['xb-gutter'] );
			$this->assertSame( 'var(--xb-space-l)', $tokens['xb-content-gap'] );
			$this->assertStringContainsString( 'html{scroll-padding-top:var(--xb-header-height)}', Tokens::css_changes( $design ) );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( '.xb-content-grid > .xb-full { grid-column:full }', $sheet );
			$this->assertStringContainsString( 'repeat(var(--xb-grid-fill, auto-fit),', $sheet );
			foreach ( array( array( 'section_padding' => 'none' ), array( 'grid_gap' => '5xl' ), array( 'grid_fill' => 'fit' ), array( 'header_min' => 10 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( (string) json_encode( $bad ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_buttons_and_links_come_from_the_panel(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '0.75em', $tokens['xb-btn-pad-y'] );
			$this->assertSame( 'var(--xb-radius-m)', $tokens['xb-btn-radius'] );
			$this->assertSame( '600', $tokens['xb-btn-weight'] );
			$this->assertSame( 'var(--xb-border-width)', $tokens['xb-btn-border'] );
			$this->assertSame( '1px', $tokens['xb-border-width'] );
			$this->assertSame( 'underline', $tokens['xb-link-line'] );
			$this->assertSame( '0.125rem', $tokens['xb-radius-xs'] );
			$sheet = Utilities::stylesheet();
			foreach ( array( '.xb-btn-danger ', '.xb-btn-tertiary-outline ', '.xb-btn-info-light ', '.xb-btn-neutral-dark ', '.xb-btn-xl ', '.xb-btn-outline ', '.xb-btn-ghost ', '.xb-text-shadow-m ', '.xb-drop-shadow-l ', '.xb-shadow-2xl ' ) as $class ) {
				$this->assertStringContainsString( "\n" . $class . '{', $sheet, $class );
			}
			$this->assertStringContainsString( 'border-radius:var(--xb-btn-radius, var(--xb-radius-m))', $sheet );
			$this->assertStringContainsString( '.xb-btn-primary:hover { background-color:var(--xb-primary-hover) }', $sheet );

			$design = Design::validate( array( 'btn_radius' => 'full', 'btn_size' => 's', 'btn_border' => 2, 'btn_full_mobile' => 1, 'btn_full_below' => 600, 'link_underline' => 'hover', 'links_on' => 1, 'border_style' => 'dashed' ) );
			$tokens = Tokens::compute( $design );
			$this->assertSame( 'var(--xb-radius-full)', $tokens['xb-btn-radius'] );
			$this->assertSame( 'var(--xb-text-s)', $tokens['xb-btn-size'] );
			$this->assertSame( '2px', $tokens['xb-btn-border'] );
			$this->assertSame( 'none', $tokens['xb-link-line'] );
			$this->assertSame( 'underline', $tokens['xb-link-line-hover'] );
			$css = Tokens::css_changes( $design );
			$this->assertStringContainsString( '@media (max-width:600px){:where(.xb-btn,[class^="xb-btn-"],[class*=" xb-btn-"]){inline-size:100%}}', $css );
			$this->assertStringContainsString( ':where(a:not([class])){color:var(--xb-link);', $css );
			foreach ( array( array( 'btn_radius' => '50%' ), array( 'btn_size' => '2xl' ), array( 'border_style' => 'groove' ), array( 'link_underline' => 'yes' ), array( 'btn_full_below' => 100 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( (string) json_encode( $bad ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_forms_and_details_from_the_panel(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '0.65em', $tokens['xb-field-pad-y'] );
			$this->assertSame( 'var(--xb-radius-m)', $tokens['xb-field-radius'] );
			$this->assertSame( 'var(--xb-bg)', $tokens['xb-field-bg'] );
			$this->assertSame( '3px', $tokens['xb-field-focus-ring'] );
			$this->assertSame( 'none', $tokens['xb-columns-rule'] );
			// On by default, as doc 19 says: focus ring, smooth scrolling, reduced motion, anchors below the header.
			$defaults = Tokens::css_changes( Design::DEFAULTS );
			$this->assertStringContainsString( ':where(:focus-visible){', $defaults );
			$this->assertStringContainsString( 'scroll-behavior:smooth', $defaults );
			$this->assertStringContainsString( '@media (prefers-reduced-motion:reduce){', $defaults );
			$this->assertStringContainsString( 'html{scroll-padding-top:var(--xb-header-height)}', $defaults );
			$this->assertStringNotContainsString( 'fluentform', $defaults, 'form styles stay off' );
			$this->assertSame( '', Tokens::css_changes( Design::validate( array( 'focus_on' => 0, 'smooth_scroll' => 0, 'reduce_motion' => 0, 'scroll_offset_on' => 0 ) ) ) );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( 'border-radius:var(--xb-field-radius, var(--xb-radius-m))', $sheet, 'xb-input follows the field tokens' );
			$this->assertStringContainsString( "\n.xb-columns {", $sheet );

			$design = Design::validate( array( 'forms_on' => 1, 'field_radius' => 'full', 'field_bg' => 'surface', 'focus_on' => 1, 'reduce_motion' => 1, 'selection_on' => 1, 'quotes_on' => 1, 'smooth_scroll' => 1, 'columns_rule' => 1 ) );
			$css    = Tokens::css_changes( $design );
			$this->assertStringContainsString( 'html .fluentform .ff-el-form-control{', $css, 'Fluent Forms takes the field tokens, over its own sheet' );
			$this->assertStringNotContainsString( ':where(.fluentform', $css, 'in :where() its own sheet would win' );
			$this->assertStringContainsString( 'html .fluentform .ff-btn-submit{', $css );
			$this->assertStringContainsString( ':where(:focus-visible){outline:var(--xb-focus-width) var(--xb-focus-style) var(--xb-focus)', $css );
			$this->assertStringContainsString( '@media (prefers-reduced-motion:reduce){', $css );
			$this->assertStringContainsString( '@media (prefers-reduced-motion:no-preference){html{scroll-behavior:smooth}}', $css );
			$this->assertStringContainsString( '::selection{', $css );
			$this->assertStringContainsString( ':where(blockquote){', $css );
			$this->assertSame( 'var(--xb-border-width) solid var(--xb-border)', Tokens::compute( $design )['xb-columns-rule'] );
			$this->assertStringNotContainsString( '<', $css );
			foreach ( array( array( 'field_bg' => 'red' ), array( 'field_radius' => 'xl' ), array( 'selection_shade' => '900' ), array( 'focus_width' => 0 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( (string) json_encode( $bad ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_motion_follows_the_scroll_and_stops_for_less_motion(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '30%', $tokens['xb-enter-range'] );
			$this->assertSame( 'var(--xb-space-l)', $tokens['xb-enter-distance'] );
			$this->assertSame( '150ms', $tokens['xb-hover-duration'] );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( "@keyframes xb-enter-up {\n\tfrom { opacity:0;translate:0 var(--xb-enter-distance, 2rem) }", $sheet );
			$this->assertStringContainsString( "@supports (animation-timeline: view()) {\n\t.xb-enter-fade {", $sheet, 'only where the browser follows the scroll' );
			$this->assertStringContainsString( "[class*=\"xb-enter-\"], [class*=\"xb-exit-\"] { animation:none }", $sheet );
			$this->assertStringContainsString( '.xb-hover-lift:hover { translate:0 calc(-1 * var(--xb-hover-lift, 4px)) }', $sheet );
			$changed = Tokens::compute( Design::validate( array( 'enter_range' => 50, 'enter_ease' => 'linear', 'hover_scale' => 1.1 ) ) );
			$this->assertSame( '50%', $changed['xb-enter-range'] );
			$this->assertSame( 'linear', $changed['xb-enter-ease'] );
			$this->assertSame( '1.1', $changed['xb-hover-scale'] );
			$this->expectException( \InvalidArgumentException::class );
			Design::validate( array( 'enter_ease' => 'cubic-bezier(0,0,1,1)' ) );
		}

		public function test_cards_and_icons_from_tokens(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( 'var(--xb-space-l)', $tokens['xb-card-pad'] );
			$this->assertSame( 'var(--xb-shadow-s)', $tokens['xb-card-shadow'] );
			$this->assertSame( '16 / 9', $tokens['xb-card-ratio'] );
			$this->assertSame( '1.25em', $tokens['xb-icon-m'] );
			$this->assertSame( 'currentColor', $tokens['xb-icon-color'] );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( '.xb-card { padding:var(--xb-card-pad, var(--xb-space-l));', $sheet, 'the card as before by default' );
			foreach ( array( '.xb-card-media ', '.xb-card-body ', '.xb-icon ', '.xb-icon-2xl ', '.xb-icon-boxed ', '.xb-list-icon > li::before ', '.xb-radius-inner ' ) as $class ) {
				$this->assertStringContainsString( "\n" . $class . '{', $sheet, $class );
			}
			$changed = Tokens::compute( Design::validate( array( 'card_shadow' => 'none', 'card_border' => 0, 'card_ratio' => '1/1', 'icon_color' => 'accent' ) ) );
			$this->assertSame( 'none', $changed['xb-card-shadow'] );
			$this->assertSame( 'none', $changed['xb-card-border'] );
			$this->assertSame( '1 / 1', $changed['xb-card-ratio'] );
			$this->assertSame( 'var(--xb-accent)', $changed['xb-icon-color'] );
			$this->expectException( \InvalidArgumentException::class );
			Design::validate( array( 'card_ratio' => '21/9' ) );
		}

		public function test_groups_of_variables_switched_off_are_not_printed(): void {
			$this->assertFalse( Tokens::groups_off( Design::DEFAULTS ) );
			$design = Design::validate( array( 'print_alphas' => 0, 'print_motion' => 0, 'print_buttons' => 0, 'dark_on' => 1 ) );
			$this->assertTrue( Tokens::groups_off( $design ) );
			$sheet = Tokens::sheet( $design );
			$this->assertStringStartsWith( ':root{--xb-text-xs:', $sheet );
			$this->assertStringNotContainsString( '--xb-primary-a10:', $sheet );
			$this->assertStringNotContainsString( '--xb-enter-range:', $sheet );
			$this->assertStringNotContainsString( '--xb-btn-radius:', $sheet );
			$this->assertStringContainsString( '--xb-primary-hover:', $sheet, 'a family hover is a colour, not motion' );
			$this->assertStringContainsString( '--xb-link-hover:', $sheet, 'the link roles are colours' );
			$this->assertStringContainsString( '--xb-shadow-m:', $sheet );
			$changes = Tokens::css_changes( $design );
			$this->assertStringNotContainsString( '-a10:', $changes, 'not even in the dark theme' );
			$this->assertStringContainsString( '.xb-scheme-dark', $changes );
		}

		public function test_forms_in_full_from_the_panel(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( 'var(--xb-danger)', $tokens['xb-field-invalid'] );
			$this->assertSame( 'var(--xb-text-muted)', $tokens['xb-field-placeholder'] );
			$this->assertSame( '1.1em', $tokens['xb-check-size'] );
			$design = Design::validate( array( 'forms_on' => 1, 'field_invalid' => 'warning', 'check_style' => 'switch', 'field_size' => 's', 'help_color' => 'text' ) );
			$tokens = Tokens::compute( $design );
			$this->assertSame( 'var(--xb-warning)', $tokens['xb-field-invalid'] );
			$this->assertSame( 'var(--xb-text-s)', $tokens['xb-field-size'] );
			$css = Tokens::css_changes( $design );
			$this->assertStringContainsString( '::placeholder{color:var(--xb-field-placeholder)', $css );
			$this->assertStringContainsString( ':where(select),html .fluentform select.ff-el-form-control{appearance:none', $css );
			$this->assertStringContainsString( ':where(fieldset){', $css );
			$this->assertStringContainsString( ':where(input[type=checkbox]){appearance:none', $css, 'the switch style' );
			$this->assertStringNotContainsString( 'appearance:none;position:relative', Tokens::css_changes( Design::validate( array( 'forms_on' => 1 ) ) ) );
			$this->expectException( \InvalidArgumentException::class );
			Design::validate( array( 'check_style' => 'toggle' ) );
		}

		public function test_overlay_and_surfaces_from_the_panel(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( 'oklch(0% 0 0 / 0.5)', $tokens['xb-overlay'], 'the overlay as before' );
			$this->assertArrayNotHasKey( 'xb-surface-1-bg', $tokens );
			$tokens = Tokens::compute( Design::validate( array( 'overlay_color' => 'primary', 'overlay_opacity' => 0.35, 'role_named_1' => 'primary-900', 'role_named_2' => 'accent-100', 'surface_1_shadow' => 'm', 'surface_2_border' => 1 ) ) );
			$this->assertSame( 'color-mix(in oklch, var(--xb-primary) 35%, transparent)', $tokens['xb-overlay'] );
			$this->assertSame( 'var(--xb-primary-900)', $tokens['xb-surface-1-bg'] );
			$this->assertSame( 'var(--xb-white)', $tokens['xb-surface-1-text'], 'light text on a dark surface' );
			$this->assertSame( 'var(--xb-black)', $tokens['xb-surface-2-text'], 'dark text on a light surface' );
			$this->assertSame( 'var(--xb-shadow-m)', $tokens['xb-surface-1-shadow'] );
			$this->assertSame( 'var(--xb-border-width) var(--xb-border-style) var(--xb-border)', $tokens['xb-surface-2-border'] );
			$this->assertStringContainsString( "\n.xb-surface-3 {", Utilities::stylesheet() );
			$this->expectException( \InvalidArgumentException::class );
			Design::validate( array( 'role_named_1' => 'red' ) );
		}

		public function test_text_spacing_and_boxed_layout(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '1em', $tokens['xb-flow-text'] );
			$this->assertSame( '1.6em', $tokens['xb-flow-heading'], 'the prose spacing as before' );
			$this->assertSame( 'var(--xb-flow-text)', $tokens['xb-flow-after'] );
			$this->assertStringNotContainsString( 'max-inline-size:var(--xb-boxed-width)', Tokens::css_changes( Design::DEFAULTS ) );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( '.xb-prose > :is(h1, h2, h3, h4, h5, h6) { margin-block-start:var(--xb-flow-heading, 1.6em) }', $sheet );
			$design = Design::validate( array( 'flow_after' => 0.5, 'boxed_on' => 1, 'boxed_bg' => 'neutral-900' ) );
			$this->assertSame( '0.5em', Tokens::compute( $design )['xb-flow-after'] );
			$this->assertStringContainsString( 'body{box-sizing:border-box;max-inline-size:var(--xb-boxed-width)', Tokens::css_changes( $design ) );
		}

		public function test_radius_ratio_dividers_and_grid_max(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( array( '0.125rem', '0.25rem', '1rem', '1.5rem' ), array( $tokens['xb-radius-xs'], $tokens['xb-radius-s'], $tokens['xb-radius-l'], $tokens['xb-radius-xl'] ), 'the radii as before' );
			$this->assertSame( 'var(--xb-grid-min)', $tokens['xb-grid-auto-min'] );
			$this->assertSame( '1px', $tokens['xb-divider-width'] );
			$tokens = Tokens::compute( Design::validate( array( 'radius' => 8, 'radius_ratio' => 2, 'grid_max' => 3, 'divider_style' => 'dashed' ) ) );
			$this->assertSame( array( '0.125rem', '0.25rem', '1rem', '2rem' ), array( $tokens['xb-radius-xs'], $tokens['xb-radius-s'], $tokens['xb-radius-l'], $tokens['xb-radius-xl'] ) );
			$this->assertSame( 'max(var(--xb-grid-min), calc((100% - 2 * var(--xb-grid-gap)) / 3))', $tokens['xb-grid-auto-min'] );
			$this->assertSame( 'dashed', $tokens['xb-divider-style'] );
			$this->assertStringContainsString( "\n.xb-divider {", Utilities::stylesheet() );
		}

		public function test_groups_of_classes_filter_the_sheet(): void {
			$sheet = Utilities::stylesheet();
			$this->assertSame( $sheet, Utilities::filtered( $sheet, Design::DEFAULTS ) );
			$cut = Utilities::filtered( $sheet, Design::validate( array( 'classes_buttons' => 0, 'classes_motion' => 0, 'classes_colors' => 0 ) ) );
			$this->assertLessThan( strlen( $sheet ) * 0.75, strlen( $cut ) );
			$this->assertStringNotContainsString( "\n.xb-btn-xl {", $cut );
			$this->assertStringNotContainsString( "\n.xb-bg-primary {", $cut );
			$this->assertStringNotContainsString( '@keyframes xb-exit-blur', $cut );
			$this->assertStringContainsString( "\n.xb-border {", $cut, 'xb-border is a shape, not a colour utility' );
			$this->assertStringContainsString( '.xb-card-body > :last-child:is(.xb-btn', $cut, 'a rule naming a class still on stays' );
			$this->assertSame( substr_count( $cut, '{' ), substr_count( $cut, '}' ), 'the blocks stay balanced' );
		}

		public function test_dark_hover_shade_and_submit_family(): void {
			$this->assertSame( 'var(--xb-primary-300)', Tokens::dark( Design::validate( array( 'dark_on' => 1 ) ) )['xb-primary-hover'] );
			$this->assertSame( 'var(--xb-primary-200)', Tokens::dark( Design::validate( array( 'dark_on' => 1, 'dark_hover_shade' => '200' ) ) )['xb-primary-hover'] );
			$css = Tokens::css_changes( Design::validate( array( 'forms_on' => 1, 'form_submit' => 'accent' ) ) );
			$this->assertStringContainsString( 'html .fluentform .ff-btn-submit{padding:var(--xb-btn-pad-y) var(--xb-btn-pad-x);border:0;border-radius:var(--xb-btn-radius);background-color:var(--xb-accent);color:var(--xb-on-accent)', $css );
		}

		public function test_the_preview_of_a_group_switched_off_empties_its_tokens(): void {
			$design = Design::validate( array( 'print_motion' => 0 ) );
			$css    = Tokens::preview_css( $design );
			$this->assertStringContainsString( '--xb-enter-range:initial;', $css );
			$this->assertStringNotContainsString( '--xb-primary:initial;', $css );
			$this->assertSame( Tokens::css_changes( Design::DEFAULTS ), Tokens::preview_css( Design::DEFAULTS ) );
		}

		public function test_the_export_gives_the_printed_tokens_light_and_dark(): void {
			$off = Tokens::export( Design::DEFAULTS );
			$this->assertNull( $off['dark'], 'no dark file without the dark theme' );
			$this->assertSame( Tokens::printed( Tokens::compute( Design::DEFAULTS ), Design::DEFAULTS ), $off['light'] );

			$design = Design::validate( array( 'dark_on' => 1, 'print_motion' => 0 ) );
			$both   = Tokens::export( $design );
			$this->assertArrayNotHasKey( 'xb-enter-range', $both['light'], 'a group switched off is not exported' );
			$this->assertSame( array_keys( $both['light'] ), array_keys( (array) $both['dark'] ), 'the same tokens, in the same order' );
			$this->assertNotNull( $both['dark'], 'a dark file with the dark theme' );
			$this->assertSame( Tokens::dark( $design )['xb-bg'], $both['dark']['xb-bg'] );
			$this->assertSame( $both['light']['xb-space-m'], $both['dark']['xb-space-m'] );
		}

		public function test_a_text_step_has_its_own_style_only_when_set(): void {
			$this->assertStringNotContainsString( '.xb-text-', Tokens::css_changes( Design::DEFAULTS ) );
			$css = Tokens::css_changes( Design::validate( array( 'text_l_leading' => 1.3, 'text_l_tracking' => -0.01, 'text_l_measure' => 40, 'text_l_case' => 'uppercase', 'text_xs_wrap' => 'pretty' ) ) );
			$this->assertStringContainsString( ':where(.xb-text-l){line-height:1.3;letter-spacing:-0.01em;max-inline-size:40ch;text-transform:uppercase;}', $css );
			$this->assertStringContainsString( ':where(.xb-text-xs){text-wrap:pretty;}', $css );
			$this->expectException( \InvalidArgumentException::class );
			Design::validate( array( 'text_l_case' => 'x;}' ) );
		}

		public function test_shadows_are_edited_by_step(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '0 4px 12px oklch(0% 0 0 / 0.1)', $tokens['xb-shadow-m'] );
			$this->assertSame( 'drop-shadow(0 2px 6px oklch(0% 0 0 / 0.3))', $tokens['xb-drop-shadow-m'] );
			$tokens = Tokens::compute( Design::validate( array( 'shadow_m_y' => 8, 'shadow_m_blur' => 20, 'shadow_m_opacity' => 0.2, 'tshadow_l_blur' => 3 ) ) );
			$this->assertSame( '0 8px 20px oklch(0% 0 0 / 0.2)', $tokens['xb-shadow-m'] );
			$this->assertSame( 'drop-shadow(0 4px 3px oklch(0% 0 0 / 0.4))', $tokens['xb-drop-shadow-l'] );
		}

		public function test_button_families_and_variants_switch_off(): void {
			$sheet = Utilities::stylesheet();
			$cut   = Utilities::filtered( $sheet, Design::validate( array( 'btn_info_on' => 0, 'btn_dark_on' => 0 ) ) );
			$this->assertStringNotContainsString( "\n.xb-btn-info {", $cut );
			$this->assertStringNotContainsString( "\n.xb-btn-info-outline {", $cut );
			$this->assertStringNotContainsString( "\n.xb-btn-primary-dark {", $cut );
			$this->assertStringContainsString( "\n.xb-btn-primary {", $cut );
			$this->assertStringContainsString( "\n.xb-btn-outline {", $cut, 'the first names stay' );
			$this->assertTrue( Utilities::class_off( 'xb-btn-info-light', Design::validate( array( 'btn_info_on' => 0 ) ) ) );
			$this->assertFalse( Utilities::class_off( 'xb-btn-primary-light', Design::validate( array( 'btn_info_on' => 0 ) ) ) );
		}

		public function test_the_list_symbol_is_a_token_only_when_chosen(): void {
			$this->assertArrayNotHasKey( 'xb-list-icon-mask', Tokens::compute( Design::DEFAULTS ) );
			$this->assertStringContainsString( 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z', Tokens::compute( Design::validate( array( 'list_icon_symbol' => 'plus' ) ) )['xb-list-icon-mask'] );
			$this->assertStringContainsString( 'var(--xb-list-icon-mask, url(', Utilities::stylesheet() );
		}

		public function test_external_links_need_the_domain_and_refuse_anything_else(): void {
			$this->assertStringNotContainsString( '[href^="http"]', Tokens::css_changes( Design::validate( array( 'external_on' => 1 ) ) ), 'without the domain every absolute link would be external' );
			$css = Tokens::css_changes( Design::validate( array( 'external_on' => 1, 'external_host' => 'esempio.it', 'external_label' => '(altro sito)' ) ) );
			$this->assertStringContainsString( '[href^="https://www.esempio.it"]', $css );
			$this->assertStringContainsString( '::after{content:"↗";content:"↗" / "(altro sito)";', $css );
			$css = Tokens::css_changes( Design::validate( array( 'external_on' => 1, 'external_host' => 'esempio.it', 'external_place' => 'before', 'external_symbol' => 'none' ) ) );
			$this->assertStringContainsString( '::before{content:"";content:"" / "(sito esterno)";margin-inline-end:0.2em}', $css );
			foreach ( array( array( 'external_host' => 'a.it"]{}' ), array( 'external_host' => "a.it\nb" ), array( 'external_host' => 'A.IT' ), array( 'external_label' => 'a"b' ), array( 'external_label' => 'a\\b' ), array( 'external_host' => 5 ) ) as $bad ) {
				try {
					Design::validate( $bad );
					$this->fail( 'accepted: ' . wp_json_encode_fallback( $bad ) );
				} catch ( \InvalidArgumentException ) {
					$this->addToAssertionCount( 1 );
				}
			}
		}

		public function test_text_spacing_and_the_section_grid_stay_off_by_default(): void {
			$this->assertStringNotContainsString( ':where(main', Tokens::css_changes( Design::DEFAULTS ) );
			$this->assertStringNotContainsString( ':where(section', Tokens::css_changes( Design::DEFAULTS ) );
			$this->assertStringContainsString( ':where(main,[role=main]) :where(h1,h2,h3,h4,h5,h6) + :where(*){margin-block-start:var(--xb-flow-after)}', Tokens::css_changes( Design::validate( array( 'flow_scope' => 'content' ) ) ) );
			$this->assertStringContainsString( ':where(section,.xb-section) > :where(.xb-full){grid-column:full}', Tokens::css_changes( Design::validate( array( 'section_grid_on' => 1 ) ) ) );
		}

		public function test_automatic_text_reads_on_the_surfaces_too(): void {
			$design = Design::validate( array( 'role_surface' => 'neutral-300' ) );
			$pairs  = array();
			foreach ( Tokens::contrast( $design ) as $pair ) {
				$pairs[ $pair['text'] . '/' . $pair['bg'] ] = $pair['ok'];
			}
			$this->assertTrue( $pairs['xb-text-muted/xb-surface'] );
			$this->assertTrue( $pairs['xb-link/xb-surface'] );
			$this->assertSame( 'var(--xb-primary-800)', Tokens::compute( $design )['xb-link'], 'the nearest shade that reads on both' );
			$this->assertSame( 'var(--xb-primary-700)', Tokens::compute( Design::DEFAULTS )['xb-link'], 'nothing moves with the default surfaces' );
		}

		public function test_a_surface_can_be_a_gradient_with_text_that_reads_on_both_ends(): void {
			$this->assertArrayNotHasKey( 'xb-surface-1-image', Tokens::compute( Design::validate( array( 'role_named_1' => 'primary-900' ) ) ) );
			$tokens = Tokens::compute( Design::validate( array( 'role_named_1' => 'primary-900', 'role_named_1_to' => 'primary-700', 'surface_1_angle' => 90 ) ) );
			$this->assertSame( 'linear-gradient(90deg, var(--xb-primary-900), var(--xb-primary-700))', $tokens['xb-surface-1-image'] );
			$this->assertSame( 'var(--xb-white)', $tokens['xb-surface-1-text'] );
			$this->assertSame( 'var(--xb-black)', Tokens::compute( Design::validate( array( 'role_named_2' => 'neutral-50', 'role_named_2_to' => 'neutral-500' ) ) )['xb-surface-2-text'], 'the lighter end decides' );
			$this->assertStringContainsString( 'background-image:var(--xb-surface-1-image, none)', Utilities::stylesheet() );
		}

		public function test_the_hover_of_light_and_dark_buttons(): void {
			$this->assertStringNotContainsString( ':hover{background-color:var(--xb-primary-', Tokens::css_changes( Design::DEFAULTS ) );
			$css = Tokens::css_changes( Design::validate( array( 'btn_light_hover' => '300', 'btn_dark_hover' => '950' ) ) );
			$this->assertStringContainsString( 'html .xb-btn-primary-light:hover{background-color:var(--xb-primary-300)}', $css );
			$this->assertStringContainsString( 'html .xb-btn-info-dark:hover{background-color:var(--xb-info-950)}', $css );
		}

		public function test_a_surface_can_have_a_name_of_its_own(): void {
			$css = Tokens::css_changes( Design::validate( array( 'role_named_2' => 'primary-100', 'surface_2_name' => 'hero-dark' ) ) );
			$this->assertStringContainsString( ':where(.xb-surface-hero-dark){background-color:var(--xb-surface-2-bg, var(--xb-surface));', $css );
			foreach ( array( 'Hero', '1a', 'a--b', 'a-', 'a b', 'a{}', str_repeat( 'a', 31 ) ) as $bad ) {
				try {
					Design::validate( array( 'surface_1_name' => $bad ) );
					$this->fail( 'accepted: ' . $bad );
				} catch ( \InvalidArgumentException ) {
					$this->addToAssertionCount( 1 );
				}
			}
		}

		public function test_the_effects_have_parameters(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '1.15', $tokens['xb-enter-scale-out'] );
			$tokens = Tokens::compute( Design::validate( array( 'enter_scale' => 0.7, 'enter_blur' => 20, 'exit_range' => 60, 'hover_bright' => 1.3, 'hover_fade' => 0.5 ) ) );
			$this->assertSame( '1.3', $tokens['xb-enter-scale-out'] );
			$this->assertSame( '20px', $tokens['xb-enter-blur'] );
			$this->assertSame( '60%', $tokens['xb-exit-range'] );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( 'scale:var(--xb-enter-scale, 0.85)', $sheet );
			$this->assertStringContainsString( 'filter:brightness(var(--xb-hover-bright, 1.08))', $sheet );
			$this->assertStringContainsString( 'exit var(--xb-exit-range, 100%)', $sheet );
		}

		public function test_the_smaller_options_of_doc_19(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '150ms ease', $tokens['xb-transition'] );
			$this->assertArrayNotHasKey( 'xb-h1-tracking', $tokens );
			$tokens = Tokens::compute( Design::validate( array( 'transition_ms' => 300, 'border_color' => 'primary', 'divider_color' => 'neutral-300', 'selection_text' => 'on-primary', 'h1_tracking' => -0.02, 'h2_measure' => 30, 'icon_box_radius' => 'none', 'quote_italic' => 1 ) ) );
			$this->assertSame( '300ms ease', $tokens['xb-transition'] );
			$this->assertSame( 'var(--xb-primary)', $tokens['xb-border-color'] );
			$this->assertSame( 'var(--xb-neutral-300)', $tokens['xb-divider-color'] );
			$this->assertSame( 'var(--xb-on-primary)', $tokens['xb-selection-text'] );
			$this->assertSame( '-0.02em', $tokens['xb-h1-tracking'] );
			$this->assertSame( '30ch', $tokens['xb-h2-measure'] );
			$this->assertSame( '0', $tokens['xb-icon-box-radius'] );
			$this->assertSame( 'italic', $tokens['xb-quote-style'] );
			$css = Tokens::css_changes( Design::validate( array( 'headings_on' => 1, 'heading_italic' => 1, 'focus_style' => 'dashed', 'quotes_on' => 1 ) ) );
			$this->assertStringContainsString( 'outline:var(--xb-focus-width) var(--xb-focus-style) var(--xb-focus)', $css );
			$this->assertStringContainsString( 'font-style:var(--xb-heading-style)', $css );
			$this->assertStringContainsString( 'border-inline-start:var(--xb-quote-width) solid var(--xb-quote-color)', $css );
			$this->assertStringContainsString( 'var(--xb-divider-color, var(--xb-border))', Utilities::stylesheet() );
		}

		public function test_icon_sizes_card_title_named_overlays_and_wrapping_by_level(): void {
			$tokens = Tokens::compute( Design::DEFAULTS );
			$this->assertSame( '1.25em', $tokens['xb-icon-m'] );
			$this->assertSame( 'var(--xb-h4)', $tokens['xb-card-title'] );
			$this->assertSame( 'oklch(0% 0 0 / 0.5)', $tokens['xb-overlay-2'] );
			$this->assertArrayNotHasKey( 'xb-h1-wrap', $tokens );
			$tokens = Tokens::compute( Design::validate( array( 'icon_m' => 2.5, 'card_title' => 'h2', 'overlay_3_color' => 'primary', 'overlay_3_opacity' => 0.4, 'overlay_3_blend' => 'multiply', 'h1_wrap' => 'pretty' ) ) );
			$this->assertSame( '2.5em', $tokens['xb-icon-m'] );
			$this->assertSame( 'var(--xb-h2)', $tokens['xb-card-title'] );
			$this->assertSame( 'color-mix(in oklch, var(--xb-primary) 40%, transparent)', $tokens['xb-overlay-3'] );
			$this->assertSame( 'multiply', $tokens['xb-overlay-3-blend'] );
			$this->assertSame( 'pretty', $tokens['xb-h1-wrap'] );
			$sheet = Utilities::stylesheet();
			$this->assertStringContainsString( "\n.xb-overlay-2::before {", $sheet );
			$this->assertStringContainsString( "\n.xb-card-title {", $sheet );
		}

		public function test_the_step_bar_of_multi_page_forms(): void {
			$tokens = Tokens::compute( Design::validate( array( 'step_height' => 12, 'step_color' => 'accent', 'step_track' => 'surface-2' ) ) );
			$this->assertSame( '12px', $tokens['xb-step-height'] );
			$this->assertSame( 'var(--xb-accent)', $tokens['xb-step-color'] );
			$this->assertSame( 'var(--xb-surface-2)', $tokens['xb-step-track'] );
			$this->assertStringContainsString( 'html .fluentform .ff-el-progress-bar{background-color:var(--xb-step-color, var(--xb-primary))}', Tokens::css_changes( Design::validate( array( 'forms_on' => 1 ) ) ) );
			$this->assertStringNotContainsString( 'ff-el-progress', Tokens::css_changes( Design::DEFAULTS ) );
		}
	}

	/** A readable dump of a test value (no WordPress here). */
	function wp_json_encode_fallback( mixed $value ): string {
		return (string) json_encode( $value );
	}
}
