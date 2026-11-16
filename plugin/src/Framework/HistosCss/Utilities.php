<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * Histeon CSS's classes — docs/spec/histos-css.md §3. One definition for the stylesheet (assets/framework/histos.css, written
 * by bin/build-histos-css.php) and for the class list the builder completes. Classes and custom properties only:
 * nothing styles a bare element, so turning Histeon CSS on changes no page until its classes are used. Values are
 * always tokens, so a site's own parameters restyle everything without rebuilding this sheet.
 */
final class Utilities {

	/** Space steps of the spacing utilities, plus none. */
	private const STEPS = array( '0', '3xs', '2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '4xl' );

	/** Button variants: [background, text, hover background, border]. */
	/** The first buttons' names, kept: [background, text, hover background, border]. */
	private const BUTTONS = array(
		'outline' => array( 'transparent', 'var(--xb-primary-700)', 'var(--xb-primary-50)', 'currentColor' ),
		'ghost'   => array( 'transparent', 'var(--xb-primary-700)', 'var(--xb-surface-2)', 'transparent' ),
	);

	/**
	 * The rules, in order: [selector, declarations] or [at-rule, list of rules].
	 *
	 * @return list<array{0: string, 1: string|list<array{0: string, 1: string}>}>
	 */
	public static function rules(): array {
		$r   = array();
		$gap = 'var(--xb-grid-gap, var(--xb-space-m))';

		// Containers and sections.
		$r[] = array( '.xb-container', 'box-sizing:border-box;inline-size:100%;max-inline-size:calc(var(--xb-width-content) + 2 * var(--xb-gutter));margin-inline:auto;padding-inline:var(--xb-gutter)' );
		$r[] = array( '.xb-container-wide', 'box-sizing:border-box;inline-size:100%;max-inline-size:calc(var(--xb-width-wide) + 2 * var(--xb-gutter));margin-inline:auto;padding-inline:var(--xb-gutter)' );
		$r[] = array( '.xb-container-prose', 'box-sizing:border-box;inline-size:100%;max-inline-size:calc(var(--xb-width-prose) + 2 * var(--xb-gutter));margin-inline:auto;padding-inline:var(--xb-gutter)' );
		$r[] = array( '.xb-section', 'padding-block:var(--xb-section-pad, var(--xb-section-m))' );
		foreach ( array( 's', 'l', 'xl' ) as $size ) {
			$r[] = array( '.xb-section-' . $size, 'padding-block:var(--xb-section-' . $size . ')' );
		}
		$r[] = array( '.xb-section-none', 'padding-block:0' );

		// Layout.
		$r[] = array( '.xb-stack', 'display:flex;flex-direction:column;gap:var(--xb-stack-gap, var(--xb-content-gap, var(--xb-space-m)))' );
		$r[] = array( '.xb-stack-s', 'display:flex;flex-direction:column;gap:var(--xb-space-s)' );
		$r[] = array( '.xb-stack-l', 'display:flex;flex-direction:column;gap:var(--xb-space-l)' );
		$r[] = array( '.xb-cluster', 'display:flex;flex-wrap:wrap;align-items:center;gap:var(--xb-cluster-gap, var(--xb-space-s))' );
		$r[] = array( '.xb-flow > * + *', 'margin-block-start:var(--xb-flow-space, 1em)' );
		$r[] = array( '.xb-center', 'margin-inline:auto' );
		$r[] = array( '.xb-grid', 'display:grid;gap:' . $gap );
		// At most N columns, each never narrower than --xb-grid-min: fewer columns on small screens, no breakpoints.
		for ( $n = 2; $n <= 6; $n++ ) {
			$r[] = array( '.xb-grid-' . $n, 'display:grid;gap:' . $gap . ';grid-template-columns:repeat(auto-fit, minmax(min(100%, max(var(--xb-grid-min, 14rem), calc((100% - ' . ( $n - 1 ) . ' * ' . $gap . ') / ' . $n . '))), 1fr))' );
		}
		// Two columns in a ratio from 48rem; one column below.
		foreach ( array( '1-2' => '1fr 2fr', '2-1' => '2fr 1fr', '1-3' => '1fr 3fr', '3-1' => '3fr 1fr' ) as $ratio => $columns ) {
			$r[] = array( '.xb-grid-' . $ratio, 'display:grid;gap:' . $gap );
			$r[] = array( '@media (min-width: 48rem)', array( array( '.xb-grid-' . $ratio, 'grid-template-columns:' . $columns ) ) );
		}
		$r[] = array( '.xb-grid-auto', 'display:grid;gap:' . $gap . ';grid-template-columns:repeat(var(--xb-grid-fill, auto-fit), minmax(min(100%, var(--xb-grid-auto-min, var(--xb-grid-min, 14rem))), 1fr))' );
		// The content grid (histos-publish doc 19 §5.3.4): children in the content column, .xb-wide and .xb-full wider,
		// the gutter always kept at the sides.
		$side = 'calc((var(--xb-width-wide) - var(--xb-width-content)) / 2)';
		$r[]  = array( '.xb-content-grid', 'display:grid;row-gap:var(--xb-container-gap, var(--xb-space-l));grid-template-columns:[full-start] minmax(var(--xb-gutter), 1fr) [wide-start] minmax(0, ' . $side . ') [content-start] min(100% - 2 * var(--xb-gutter), var(--xb-width-content)) [content-end] minmax(0, ' . $side . ') [wide-end] minmax(var(--xb-gutter), 1fr) [full-end]' );
		$r[]  = array( '.xb-content-grid > *', 'grid-column:content;min-inline-size:0' );
		$r[]  = array( '.xb-content-grid > .xb-wide', 'grid-column:wide' );
		$r[]  = array( '.xb-content-grid > .xb-full', 'grid-column:full' );
		$r[]  = array( '.xb-content-grid > .xb-narrow', 'inline-size:100%;max-inline-size:var(--xb-width-prose);justify-self:center' );

		// Spacing.
		foreach ( self::STEPS as $step ) {
			$value = '0' === $step ? '0' : 'var(--xb-space-' . $step . ')';
			$r[]   = array( '.xb-p-' . $step, 'padding:' . $value );
			$r[]   = array( '.xb-px-' . $step, 'padding-inline:' . $value );
			$r[]   = array( '.xb-py-' . $step, 'padding-block:' . $value );
			$r[]   = array( '.xb-mt-' . $step, 'margin-block-start:' . $value );
			$r[]   = array( '.xb-mb-' . $step, 'margin-block-end:' . $value );
			$r[]   = array( '.xb-gap-' . $step, 'gap:' . $value );
		}

		// Text.
		foreach ( array_keys( Tokens::TEXT ) as $size ) {
			$r[] = array( '.xb-text-' . $size, 'font-size:var(--xb-text-' . $size . ')' );
		}
		foreach ( array_keys( Tokens::HEADINGS ) as $heading ) {
			$r[] = array( '.xb-' . $heading, 'font-family:var(--xb-' . $heading . '-font, var(--xb-heading-font));font-size:var(--xb-' . $heading . ');font-weight:var(--xb-' . $heading . '-weight);line-height:var(--xb-' . $heading . '-leading);letter-spacing:var(--xb-' . $heading . '-tracking, var(--xb-heading-tracking));text-transform:var(--xb-' . $heading . '-transform, var(--xb-heading-transform));text-wrap:var(--xb-' . $heading . '-wrap, var(--xb-heading-wrap));max-inline-size:var(--xb-' . $heading . '-measure, var(--xb-heading-measure));color:var(--xb-' . $heading . '-color, var(--xb-heading))' );
		}
		foreach ( array( 'body', 'heading', 'mono' ) as $font ) {
			$r[] = array( '.xb-font-' . $font, 'font-family:var(--xb-font-' . $font . ')' );
		}
		foreach ( array( 400, 500, 600, 700 ) as $weight ) {
			$r[] = array( '.xb-weight-' . $weight, 'font-weight:' . $weight );
		}
		foreach ( array( 'start', 'center', 'end' ) as $align ) {
			$r[] = array( '.xb-align-' . $align, 'text-align:' . $align );
		}
		$r[] = array( '.xb-balance', 'text-wrap:balance' );
		$r[] = array( '.xb-measure', 'max-inline-size:var(--xb-width-prose)' );
		$r[] = array( '.xb-muted', 'color:var(--xb-text-muted)' );

		// Content written in the editor: headings, links, lists, quotes, code, images.
		$r[] = array( '.xb-prose', 'max-inline-size:var(--xb-width-prose);color:var(--xb-text);line-height:var(--xb-leading-normal)' );
		$r[] = array( '.xb-prose > * + *', 'margin-block-start:var(--xb-flow-text, 1em)' );
		$r[] = array( '.xb-prose > :is(h1, h2, h3, h4, h5, h6) + *', 'margin-block-start:var(--xb-flow-after, var(--xb-flow-text, 1em))' );
		$r[] = array( '.xb-prose :is(h1, h2, h3, h4, h5, h6)', 'font-family:var(--xb-heading-font);letter-spacing:var(--xb-heading-tracking);text-transform:var(--xb-heading-transform);text-wrap:var(--xb-heading-wrap);color:var(--xb-heading)' );
		$r[] = array( '.xb-prose > :is(h1, h2, h3, h4, h5, h6)', 'margin-block-start:var(--xb-flow-heading, 1.6em)' );
		$r[] = array( '.xb-prose li + li, .xb-prose li > :is(ul, ol)', 'margin-block-start:var(--xb-flow-list, 0)' );
		foreach ( array_keys( Tokens::HEADINGS ) as $heading ) {
			$r[] = array( '.xb-prose ' . $heading, 'font-family:var(--xb-' . $heading . '-font, var(--xb-heading-font));font-size:var(--xb-' . $heading . ');font-weight:var(--xb-' . $heading . '-weight);line-height:var(--xb-' . $heading . '-leading);text-transform:var(--xb-' . $heading . '-transform, var(--xb-heading-transform));color:var(--xb-' . $heading . '-color, var(--xb-heading))' );
		}
		$r[] = array( '.xb-prose a', 'color:var(--xb-link);text-decoration-line:var(--xb-link-line, underline);text-decoration-thickness:var(--xb-link-thickness, auto);text-underline-offset:var(--xb-link-offset, 0.2em)' );
		$r[] = array( '.xb-prose a:hover', 'color:var(--xb-link-hover);text-decoration-line:var(--xb-link-line-hover, underline)' );
		$r[] = array( '.xb-prose :is(ul, ol)', 'padding-inline-start:1.4em' );
		$r[] = array( '.xb-prose blockquote', 'margin-inline:0;padding-inline-start:1em;border-inline-start:4px solid var(--xb-primary-300);color:var(--xb-text-muted)' );
		$r[] = array( '.xb-prose code', 'font-family:var(--xb-font-mono);font-size:0.9em;padding:0.1em 0.3em;border-radius:var(--xb-radius-s);background:var(--xb-surface-2)' );
		$r[] = array( '.xb-prose img', 'max-inline-size:100%;block-size:auto;border-radius:var(--xb-radius-m)' );

		// Colours.
		foreach ( Design::FAMILIES as $family ) {
			$r[] = array( '.xb-color-' . $family, 'color:var(--xb-' . $family . ')' );
			$r[] = array( '.xb-bg-' . $family, 'background-color:var(--xb-' . $family . ');color:var(--xb-on-' . $family . ')' );
			$r[] = array( '.xb-border-' . $family, 'border-color:var(--xb-' . $family . ')' );
			foreach ( Tokens::ALPHAS as $alpha ) {
				$r[] = array( '.xb-bg-' . $family . '-a' . $alpha, 'background-color:var(--xb-' . $family . '-a' . $alpha . ')' );
			}
			foreach ( array_keys( Color::SHADES ) as $shade ) {
				$r[] = array( '.xb-color-' . $family . '-' . $shade, 'color:var(--xb-' . $family . '-' . $shade . ')' );
				$r[] = array( '.xb-bg-' . $family . '-' . $shade, 'background-color:var(--xb-' . $family . '-' . $shade . ');color:var(' . ( $shade <= 500 ? '--xb-black' : '--xb-white' ) . ')' );
			}
		}
		$r[] = array( '.xb-bg-surface', 'background-color:var(--xb-surface);color:var(--xb-text)' );
		$r[] = array( '.xb-bg-surface-2', 'background-color:var(--xb-surface-2);color:var(--xb-text)' );
		$r[] = array( '.xb-scheme-light', '--xb-bg:var(--xb-white);--xb-surface:var(--xb-neutral-50);--xb-surface-2:var(--xb-neutral-100);--xb-text:var(--xb-neutral-900);--xb-text-muted:var(--xb-neutral-700);--xb-heading:var(--xb-neutral-950);--xb-border:var(--xb-neutral-200);--xb-link:var(--xb-primary-700);--xb-link-hover:var(--xb-primary-800);background-color:var(--xb-bg);color:var(--xb-text)' );
		$r[] = array( '.xb-scheme-dark', '--xb-bg:var(--xb-neutral-950);--xb-surface:var(--xb-neutral-900);--xb-surface-2:var(--xb-neutral-800);--xb-text:var(--xb-neutral-100);--xb-text-muted:var(--xb-neutral-300);--xb-heading:var(--xb-white);--xb-border:var(--xb-neutral-700);--xb-link:var(--xb-primary-300);--xb-link-hover:var(--xb-primary-200);background-color:var(--xb-bg);color:var(--xb-text)' );

		// Shapes and effects.
		foreach ( array( 's', 'm', 'l', 'xl', 'full' ) as $size ) {
			$r[] = array( '.xb-rounded-' . $size, 'border-radius:var(--xb-radius-' . $size . ')' );
		}
		foreach ( array( 's', 'm', 'l', 'xl', '2xl' ) as $size ) {
			$r[] = array( '.xb-shadow-' . $size, 'box-shadow:var(--xb-shadow-' . $size . ')' );
		}
		foreach ( array( 's', 'm', 'l' ) as $size ) {
			$r[] = array( '.xb-text-shadow-' . $size, 'text-shadow:var(--xb-text-shadow-' . $size . ')' );
			$r[] = array( '.xb-drop-shadow-' . $size, 'filter:var(--xb-drop-shadow-' . $size . ')' );
		}
		$r[] = array( '.xb-border', 'border:var(--xb-border-width) var(--xb-border-style, solid) var(--xb-border-color, var(--xb-border))' );
		$r[] = array( '.xb-divider', 'block-size:0;margin-block:var(--xb-divider-space, var(--xb-space-m));border:0;border-block-start:var(--xb-divider-width, 1px) var(--xb-divider-style, solid) var(--xb-divider-color, var(--xb-border))' );

		// Components. A variant works alone (class="xb-btn-primary") or with the base (class="xb-btn xb-btn-primary").
		// No Histeon CSS class contains "--": frameworks match [class*="btn--"] or [class*="grid--"], and a site moving from
		// one keeps those rules (compatibility sheet) while it starts using Histeon CSS's classes.
		// Every family has its button, filled, outline, light and dark (histos-publish doc 19 §5.3.6); the look comes
		// from the --xb-btn-* tokens of the panel.
		$variants = array();
		foreach ( Design::FAMILIES as $family ) {
			$variants[ $family ]              = array( 'var(--xb-' . $family . ')', 'var(--xb-on-' . $family . ')', 'var(--xb-' . $family . '-hover)', 'transparent' );
			$variants[ $family . '-outline' ] = array( 'transparent', 'var(--xb-' . $family . '-700)', 'var(--xb-' . $family . '-a10)', 'currentColor' );
			$variants[ $family . '-light' ]   = array( 'var(--xb-' . $family . '-100)', 'var(--xb-' . $family . '-900)', 'var(--xb-' . $family . '-200)', 'transparent' );
			$variants[ $family . '-dark' ]    = array( 'var(--xb-' . $family . '-800)', 'var(--xb-white)', 'var(--xb-' . $family . '-900)', 'transparent' );
		}
		$variants += self::BUTTONS;
		$buttons   = array_merge( array( '.xb-btn' ), array_map( static fn ( string $v ): string => '.xb-btn-' . $v, array_keys( $variants ) ) );
		$r[]       = array( ':where(' . implode( ', ', $buttons ) . ')', 'display:inline-flex;align-items:center;justify-content:center;gap:0.5em;min-inline-size:var(--xb-btn-min, 0);padding:var(--xb-btn-pad-y, 0.75em) var(--xb-btn-pad-x, 1.4em);border:var(--xb-btn-border, var(--xb-border-width)) solid transparent;border-radius:var(--xb-btn-radius, var(--xb-radius-m));background-color:var(--xb-primary);color:var(--xb-on-primary);font:inherit;font-size:var(--xb-btn-size, inherit);font-weight:var(--xb-btn-weight, 600);line-height:var(--xb-btn-leading, 1.2);letter-spacing:var(--xb-btn-tracking, 0);text-transform:var(--xb-btn-case, none);text-decoration:none;cursor:pointer;transition:background-color var(--xb-transition), color var(--xb-transition), border-color var(--xb-transition), box-shadow var(--xb-transition)' );
		$r[]       = array( ':where(' . implode( ', ', $buttons ) . '):hover', 'background-color:var(--xb-primary-hover, var(--xb-primary-700))' );
		$r[]       = array( ':where(' . implode( ', ', $buttons ) . '):focus-visible', 'outline:2px solid var(--xb-focus);outline-offset:2px' );
		foreach ( $variants as $variant => [ $bg, $fg, $hover, $border ] ) {
			$r[] = array( '.xb-btn-' . $variant, 'background-color:' . $bg . ';color:' . $fg . ';border-color:' . $border );
			$r[] = array( '.xb-btn-' . $variant . ':hover', 'background-color:' . $hover );
		}
		foreach ( array( 'xs' => array( '0.35em 0.8em', 'xs' ), 's' => array( '0.5em 1em', 's' ), 'l' => array( '1em 1.8em', 'l' ), 'xl' => array( '1.2em 2.2em', 'xl' ) ) as $size => [ $padding, $text ] ) {
			$r[] = array( '.xb-btn-' . $size, 'padding:' . $padding . ';font-size:var(--xb-text-' . $text . ')' );
		}
		$r[] = array( '.xb-card', 'padding:var(--xb-card-pad, var(--xb-space-l));border:var(--xb-card-border, var(--xb-border-width) solid var(--xb-border));border-radius:var(--xb-card-radius, var(--xb-radius-l));background-color:var(--xb-card-bg, var(--xb-surface));color:var(--xb-text);box-shadow:var(--xb-card-shadow, var(--xb-shadow-s))' );
		// A card's media at its edges, its body with the card's gap (histos-publish doc 19 §5.3.7).
		$r[] = array( '.xb-card-media', 'display:block;margin:calc(-1 * var(--xb-card-pad, var(--xb-space-l))) calc(-1 * var(--xb-card-pad, var(--xb-space-l))) var(--xb-card-gap, var(--xb-space-m));aspect-ratio:var(--xb-card-ratio, 16 / 9);overflow:hidden;border-start-start-radius:var(--xb-card-radius, var(--xb-radius-l));border-start-end-radius:var(--xb-card-radius, var(--xb-radius-l))' );
		$r[] = array( '.xb-card-media :is(img, video, svg)', 'display:block;inline-size:100%;block-size:100%;object-fit:cover' );
		$r[] = array( '.xb-card-title', 'margin:0;font-family:var(--xb-heading-font);font-size:var(--xb-card-title, var(--xb-h4));font-weight:var(--xb-heading-weight);line-height:var(--xb-heading-leading);color:var(--xb-heading)' );
		$r[] = array( '.xb-card-body', 'display:flex;flex-direction:column;gap:var(--xb-card-gap, var(--xb-space-m))' );
		$r[] = array( '.xb-card-body > :last-child:is(.xb-btn, [class^="xb-btn-"], [class*=" xb-btn-"])', 'margin-block-start:auto;align-self:start' );
		$r[] = array( '.xb-radius-inner', 'border-radius:var(--xb-radius-inner, var(--xb-radius-m))' );
		// Icons (§5.3.8): any inline SVG or icon font glyph.
		$r[] = array( '.xb-icon', 'display:inline-block;flex:none;inline-size:var(--xb-icon-m, 1.25em);block-size:var(--xb-icon-m, 1.25em);color:var(--xb-icon-color, currentColor);stroke-width:var(--xb-icon-stroke, 2);vertical-align:middle' );
		foreach ( array( 'xs', 's', 'l', 'xl', '2xl' ) as $size ) {
			$r[] = array( '.xb-icon-' . $size, 'inline-size:var(--xb-icon-' . $size . ');block-size:var(--xb-icon-' . $size . ')' );
		}
		$r[] = array( '.xb-icon-boxed', 'display:inline-grid;place-items:center;box-sizing:content-box;padding:var(--xb-icon-box-pad, var(--xb-space-xs));border-radius:var(--xb-icon-box-radius, var(--xb-radius-full));background-color:var(--xb-icon-box-bg, var(--xb-primary-100));color:var(--xb-primary-800)' );
		$r[] = array( '.xb-list-icon', 'list-style:none;padding-inline-start:0' );
		$r[] = array( '.xb-list-icon > li', 'display:flex;gap:var(--xb-list-icon-gap, var(--xb-space-xs));align-items:baseline' );
		$check = 'var(--xb-list-icon-mask, url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E%3Cpath d=%27M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z%27/%3E%3C/svg%3E"))';
		$r[]   = array( '.xb-list-icon > li::before', 'content:"";flex:none;inline-size:1em;block-size:1em;translate:0 0.15em;background-color:var(--xb-list-icon-color, var(--xb-primary));-webkit-mask:' . $check . ' center / contain no-repeat;mask:' . $check . ' center / contain no-repeat' );
		$r[] = array( '.xb-badge', 'display:inline-flex;inline-size:fit-content;align-items:center;padding:0.15em 0.6em;border-radius:var(--xb-radius-full);background-color:var(--xb-primary-100);color:var(--xb-primary-800);font-size:var(--xb-text-xs);font-weight:600;line-height:1.4' );
		$r[] = array( '.xb-link', 'color:var(--xb-link);text-decoration-line:var(--xb-link-line, underline);text-decoration-thickness:var(--xb-link-thickness, auto);text-underline-offset:var(--xb-link-offset, 0.2em);transition:color var(--xb-transition)' );
		$r[] = array( '.xb-link:hover', 'color:var(--xb-link-hover);text-decoration-line:var(--xb-link-line-hover, underline)' );

		// Forms: fields that look like the rest of the design.
		$r[] = array( '.xb-field', 'display:grid;gap:var(--xb-space-2xs)' );
		$r[] = array( '.xb-label', 'font-weight:var(--xb-label-weight, 600);color:var(--xb-label-color, var(--xb-heading))' );
		$r[] = array( '.xb-input', 'box-sizing:border-box;inline-size:100%;padding:var(--xb-field-pad-y, 0.65em) var(--xb-field-pad-x, 0.9em);border:var(--xb-field-border, var(--xb-border-width)) solid var(--xb-border);border-radius:var(--xb-field-radius, var(--xb-radius-m));background-color:var(--xb-field-bg, var(--xb-bg));color:var(--xb-text);font:inherit;line-height:1.4;transition:border-color var(--xb-transition), box-shadow var(--xb-transition)' );
		$r[] = array( '.xb-input:focus', 'outline:none;border-color:var(--xb-field-focus, var(--xb-focus));box-shadow:0 0 0 var(--xb-field-focus-ring, 3px) var(--xb-primary-a30)' );
		$r[] = array( '.xb-input::placeholder', 'color:var(--xb-field-placeholder, var(--xb-text-muted))' );
		$r[] = array( '.xb-help', 'font-size:var(--xb-help-size, var(--xb-text-xs));color:var(--xb-help-color, var(--xb-text-muted))' );
		$r[] = array( '.xb-input[aria-invalid="true"]', 'border-color:var(--xb-field-invalid, var(--xb-danger));box-shadow:0 0 0 var(--xb-field-focus-ring, 3px) var(--xb-danger-a20)' );
		$r[] = array( '.xb-columns', 'columns:var(--xb-columns-min, 20rem);column-gap:var(--xb-columns-gap, var(--xb-space-l));column-rule:var(--xb-columns-rule, none)' );

		// Visibility by screen width: s below 48rem, m from 48 to 64rem, l from 64rem.
		$r[] = array( '@media (max-width: 47.99rem)', array( array( '.xb-hide-s', 'display:none !important' ) ) );
		$r[] = array( '@media (min-width: 48rem) and (max-width: 63.99rem)', array( array( '.xb-hide-m', 'display:none !important' ) ) );
		$r[] = array( '@media (min-width: 64rem)', array( array( '.xb-hide-l', 'display:none !important' ) ) );

		// Accessibility and helpers.
		$hidden = 'position:absolute !important;inline-size:1px;block-size:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0, 0, 0, 0);white-space:nowrap;border:0';
		$r[]    = array( '.xb-visually-hidden', $hidden );
		$r[]    = array( '.xb-skip-link:not(:focus)', $hidden );
		$r[]    = array( '.xb-skip-link:focus', 'position:fixed;inset-block-start:1rem;inset-inline-start:1rem;z-index:100000;padding:0.75em 1em;border-radius:var(--xb-radius-m);background-color:var(--xb-bg);color:var(--xb-text);box-shadow:var(--xb-shadow-m)' );
		$r[]    = array( '.xb-clickable-parent', 'position:relative' );
		$r[]    = array( '.xb-clickable-parent a::after', 'content:"";position:absolute;inset:0' );
		$r[]    = array( '.xb-cover', 'inline-size:100%;block-size:100%;object-fit:cover' );
		foreach ( array( 'square' => '1 / 1', 'video' => '16 / 9', 'photo' => '4 / 3' ) as $name => $ratio ) {
			$r[] = array( '.xb-aspect-' . $name, 'aspect-ratio:' . $ratio );
		}
		$r[] = array( '.xb-overlay', 'position:relative;isolation:isolate' );
		$r[] = array( '.xb-overlay::before', 'content:"";position:absolute;inset:0;z-index:-1;background:var(--xb-overlay, oklch(0% 0 0 / 0.5));mix-blend-mode:var(--xb-overlay-blend, normal)' );
		$r[] = array( '.xb-overlay-gradient', 'position:relative;isolation:isolate' );
		$r[] = array( '.xb-overlay-gradient::before', 'content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(to bottom, transparent, var(--xb-overlay, oklch(0% 0 0 / 0.5)));mix-blend-mode:var(--xb-overlay-blend, normal)' );
		foreach ( array( 2, 3 ) as $n ) {
			$r[] = array( '.xb-overlay-' . $n, 'position:relative;isolation:isolate' );
			$r[] = array( '.xb-overlay-' . $n . '::before', 'content:"";position:absolute;inset:0;z-index:-1;background:var(--xb-overlay-' . $n . ', oklch(0% 0 0 / 0.5));mix-blend-mode:var(--xb-overlay-' . $n . '-blend, normal)' );
		}
		// The site's own surfaces (histos-publish doc 19 §5.3.7), set in the panel; unset, they are the surface.
		foreach ( array( 1, 2, 3 ) as $n ) {
			$r[] = array( '.xb-surface-' . $n, 'background-color:var(--xb-surface-' . $n . '-bg, var(--xb-surface));background-image:var(--xb-surface-' . $n . '-image, none);color:var(--xb-surface-' . $n . '-text, var(--xb-text));border:var(--xb-surface-' . $n . '-border, none);box-shadow:var(--xb-surface-' . $n . '-shadow, none)' );
		}

		// Motion (histos-publish doc 19 §5.3.11): CSS only. Entering and leaving follow the scroll where the browser has
		// scroll-driven animations; elsewhere, and for whoever asks for less motion, the elements stay as they are.
		$from = array(
			'fade'   => 'opacity:0',
			'up'     => 'opacity:0;translate:0 var(--xb-enter-distance, 2rem)',
			'down'   => 'opacity:0;translate:0 calc(-1 * var(--xb-enter-distance, 2rem))',
			'left'   => 'opacity:0;translate:calc(-1 * var(--xb-enter-distance, 2rem)) 0',
			'right'  => 'opacity:0;translate:var(--xb-enter-distance, 2rem) 0',
			'grow'   => 'opacity:0;scale:var(--xb-enter-scale, 0.85)',
			'shrink' => 'opacity:0;scale:var(--xb-enter-scale-out, 1.15)',
			'blur'   => 'opacity:0;filter:blur(var(--xb-enter-blur, 8px))',
		);
		$scroll = array();
		foreach ( $from as $name => $state ) {
			$r[]      = array( '@keyframes xb-enter-' . $name, array( array( 'from', $state ) ) );
			$r[]      = array( '@keyframes xb-exit-' . $name, array( array( 'to', $state ) ) );
			$scroll[] = array( '.xb-enter-' . $name, 'animation:xb-enter-' . $name . ' var(--xb-enter-ease, ease-out) both;animation-timeline:view();animation-range:entry 0% cover var(--xb-enter-range, 30%)' );
			$scroll[] = array( '.xb-exit-' . $name, 'animation:xb-exit-' . $name . ' var(--xb-enter-ease, ease-out) both;animation-timeline:view();animation-range:exit 0% exit var(--xb-exit-range, 100%)' );
		}
		$r[]   = array( '@supports (animation-timeline: view())', $scroll );
		$hover = 'transition:translate var(--xb-hover-duration, 150ms) ease, scale var(--xb-hover-duration, 150ms) ease, box-shadow var(--xb-hover-duration, 150ms) ease, filter var(--xb-hover-duration, 150ms) ease, opacity var(--xb-hover-duration, 150ms) ease, background-size var(--xb-hover-duration, 150ms) ease';
		foreach ( array( 'lift' => 'translate:0 calc(-1 * var(--xb-hover-lift, 4px))', 'shadow' => 'box-shadow:var(--xb-shadow-l)', 'glow' => 'box-shadow:0 0 0 4px var(--xb-primary-a30), 0 0 24px var(--xb-primary-a40)', 'scale' => 'scale:var(--xb-hover-scale, 1.04)', 'bright' => 'filter:brightness(var(--xb-hover-bright, 1.08))', 'fade' => 'opacity:var(--xb-hover-fade, 0.8)' ) as $name => $state ) {
			$r[] = array( '.xb-hover-' . $name, $hover );
			$r[] = array( '.xb-hover-' . $name . ':hover', $state );
		}
		foreach ( array( '' => '0 100%', '-center' => '50% 100%', '-right' => '100% 100%' ) as $suffix => $origin ) {
			$r[] = array( '.xb-hover-underline' . $suffix, 'background:linear-gradient(currentColor, currentColor) no-repeat ' . $origin . ' / 0 2px;text-decoration:none;' . $hover );
			$r[] = array( '.xb-hover-underline' . $suffix . ':is(:hover, :focus-visible)', 'background-size:100% 2px' );
		}
		$r[] = array( '@media (prefers-reduced-motion: reduce)', array( array( ':where(' . implode( ', ', $buttons ) . '), .xb-link, [class*="xb-hover-"]', 'transition:none' ), array( '[class*="xb-enter-"], [class*="xb-exit-"]', 'animation:none' ) ) );
		return $r;
	}

	/**
	 * The sheet without the groups of classes switched off in the panel (classes_*, histos-publish doc 19 §5.3.12):
	 * a rule goes when every class it names belongs to a group switched off; @keyframes by their name. Works on the
	 * generated sheet line by line, as Histeon Publish does (filterHistosClasses), so both give the same bytes.
	 *
	 * @param array<string, string|int|float> $design
	 */
	public static function filtered( string $css, array $design ): string {
		$off = array_map( static fn ( string $pattern ): string => '/' . $pattern . '/', self::off_patterns( $design ) );
		if ( array() === $off ) {
			return $css;
		}
		$gone  = static function ( string $selector ) use ( $off ): bool {
			if ( 1 === preg_match( '/^@keyframes\s+([a-z0-9-]+)/', $selector, $k ) ) {
				$names = array( $k[1] );
			} else {
				preg_match_all( '/\.(xb-[a-z0-9-]+)/', $selector, $m );
				$names = $m[1];
			}
			if ( array() === $names ) {
				return false;
			}
			foreach ( $names as $name ) {
				$matched = false;
				foreach ( $off as $pattern ) {
					$matched = $matched || 1 === preg_match( $pattern, $name );
				}
				if ( ! $matched ) {
					return false;
				}
			}
			return true;
		};
		$lines = explode( "\n", $css );
		$out   = array();
		for ( $i = 0, $n = count( $lines ); $i < $n; $i++ ) {
			$line = $lines[ $i ];
			if ( str_starts_with( $line, '@' ) && str_ends_with( $line, '{' ) ) {
				$block = array();
				for ( $i++; $i < $n && '}' !== $lines[ $i ]; $i++ ) {
					$block[] = $lines[ $i ];
				}
				if ( str_starts_with( $line, '@keyframes' ) ) {
					if ( ! $gone( $line ) ) {
						array_push( $out, $line, ...$block );
						$out[] = '}';
					}
					continue;
				}
				$kept = array_values( array_filter( $block, static fn ( string $inner ): bool => ! $gone( (string) strstr( $inner, ' { ', true ) ) ) );
				if ( array() !== $kept ) {
					array_push( $out, $line, ...$kept );
					$out[] = '}';
				}
				continue;
			}
			$selector = strstr( $line, ' { ', true );
			if ( false === $selector || ! $gone( $selector ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * What the panel switches off: the groups of classes, the buttons of a family, a variant of every family.
	 *
	 * @param array<string, string|int|float> $design
	 * @return list<string> Patterns on class names.
	 */
	public static function off_patterns( array $design ): array {
		$d   = $design + Design::DEFAULTS;
		$off = array();
		foreach ( DesignData::CLASS_GROUPS as $group => $pattern ) {
			if ( 0 === (int) $d[ 'classes_' . $group ] ) {
				$off[] = $pattern;
			}
		}
		$families = implode( '|', Design::FAMILIES );
		foreach ( Design::FAMILIES as $family ) {
			if ( 0 === (int) $d[ 'btn_' . $family . '_on' ] ) {
				$off[] = '^xb-btn-' . $family . '(?:-outline|-light|-dark)?$';
			}
		}
		foreach ( array( 'outline', 'light', 'dark' ) as $variant ) {
			if ( 0 === (int) $d[ 'btn_' . $variant . '_on' ] ) {
				$off[] = '^xb-btn-(?:' . $families . ')-' . $variant . '$';
			}
		}
		return $off;
	}

	/**
	 * Whether a class belongs to something switched off.
	 *
	 * @param array<string, string|int|float> $design
	 */
	public static function class_off( string $name, array $design ): bool {
		foreach ( self::off_patterns( $design ) as $pattern ) {
			if ( 1 === preg_match( '/' . $pattern . '/', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The class names, grouped like the builder lists them.
	 *
	 * @return array<string, list<string>>
	 */
	public static function classes(): array {
		$groups = array(
			'layout'  => '/^xb-(container|section|stack|cluster|flow|center|grid)/',
			'spacing' => '/^xb-(p|px|py|mt|mb|gap)-/',
			'text'    => '/^xb-(text-|h[1-6]$|font-|weight-|align-|balance|measure|muted|prose)/',
			'color'   => '/^xb-(color-|bg-|border-[a-z]|scheme-)/',
			'visibility' => '/^xb-hide-/',
			'shape'   => '/^xb-(rounded-|shadow-|border$)/',
			'ui'      => '/^xb-(btn|card|badge|link)/',
			'forms'   => '/^xb-(field|label|input)/',
			'helpers' => '/./',
		);
		$out = array_fill_keys( array_keys( $groups ), array() );
		$all = array();
		$walk = static function ( array $rules ) use ( &$walk, &$all ): void {
			foreach ( $rules as [ $selector, $body ] ) {
				if ( is_array( $body ) ) {
					$walk( $body );
					continue;
				}
				preg_match_all( '/\.(xb-[a-z0-9-]+)/', $selector, $m );
				foreach ( $m[1] as $name ) {
					$all[ $name ] = true;
				}
			}
		};
		$walk( self::rules() );
		foreach ( array_keys( $all ) as $name ) {
			foreach ( $groups as $group => $pattern ) {
				if ( 1 === preg_match( $pattern, $name ) ) {
					$out[ $group ][] = $name;
					break;
				}
			}
		}
		return array_filter( $out );
	}

	/** assets/framework/histos.css, as bin/build-histos-css.php writes it. */
	public static function stylesheet(): string {
		$css = "/*\n * Histeon CSS — Histeon's free CSS framework (GPL-3.0-or-later). docs/spec/histos-css.md\n * Classes and custom properties only; values come from the --xb-* tokens (xb-tokens.css).\n * Generated by plugin/bin/build-histos-css.php from src/Framework/HistosCss/Utilities.php: do not edit by hand.\n */\n";
		foreach ( array_merge( self::rules(), self::article_blocks() ) as [ $selector, $body ] ) {
			if ( is_array( $body ) ) {
				$css .= $selector . " {\n";
				foreach ( $body as [ $inner, $declarations ] ) {
					$css .= "\t" . $inner . ' { ' . $declarations . " }\n";
				}
				$css .= "}\n";
				continue;
			}
			$css .= $selector . ' { ' . $body . " }\n";
		}
		return $css;
	}

	/**
	 * The components of an article (assets/framework/article-components.json): they read on any site, in its tokens.
	 *
	 * @return list<array{0: string, 1: string|list<array{0: string, 1: string}>}>
	 */
	private static function article_blocks(): array {
		return array(
			array( '.xb-art-riquadro, .xb-art-libro, .xb-art-autore, .xb-art-evento, .xb-art-leggi', 'margin-block:1.6em; padding:1em 1.25em; border-radius:var(--xb-radius-m, 8px); background:var(--xb-neutral-a10, rgb(0 0 0 / 5%))' ),
			array( '.xb-art-riquadro', 'border-inline-start:4px solid var(--xb-primary, currentColor)' ),
			array( '.xb-art-riquadro-avviso', 'border-inline-start-color:#c27a00' ),
			array( '.xb-art-riquadro-approfondimento', 'border-inline-start-color:var(--xb-neutral-500, #777)' ),
			array( '.xb-art-riquadro p, .xb-art-libro p, .xb-art-autore p, .xb-art-evento p, .xb-art-leggi p', 'margin:0' ),
			array( '.xb-art-titolo', 'font-weight:700' ),
			array( '.xb-art-testo', 'white-space:pre-line' ),
			array( '.xb-art-riquadro .xb-art-titolo + .xb-art-testo, .xb-art-autore .xb-art-titolo + .xb-art-testo', 'margin-block-start:.35em' ),
			array( '.xb-art-citazione', 'margin:2em 0; text-align:center' ),
			array( '.xb-art-citazione blockquote', 'margin:0; padding:0; border:0; font-size:1.5em; line-height:1.3; font-style:italic; white-space:pre-line' ),
			array( '.xb-art-citazione figcaption', 'margin-block-start:.6em; font-size:.9em; opacity:.75' ),
			array( '.xb-art-libro, .xb-art-autore', 'display:flex; gap:1.25em; align-items:flex-start' ),
			array( '.xb-art-libro img', 'flex:0 0 7.5rem; width:7.5rem; height:auto; border-radius:3px; box-shadow:0 2px 10px rgb(0 0 0 / 18%)' ),
			array( '.xb-art-autore img', 'flex:0 0 5rem; width:5rem; height:5rem; object-fit:cover; border-radius:50%' ),
			array( '.xb-art-corpo', 'display:grid; gap:.25em; min-width:0' ),
			array( '.xb-art-autore-nome, .xb-art-editore', 'font-size:.92em; opacity:.8' ),
			array( '.xb-art-link', 'margin-block-start:.4em; font-weight:600' ),
			array( '.xb-art-documento, .xb-art-video, .xb-art-audio, .xb-art-mappa', 'display:flex; align-items:center; gap:.6em; margin-block:1.2em; padding:.75em 1em; border:1px solid var(--xb-neutral-a30, rgb(0 0 0 / 18%)); border-radius:var(--xb-radius-m, 8px); font-weight:600; text-decoration:none' ),
			array( '.xb-art-documento::before', 'content:"↓"' ),
			array( '.xb-art-fonte', 'margin-block:2em 0; font-size:.92em; opacity:.85' ),
			array( '.xb-art-video::before, .xb-art-audio::before', 'content:"▶"' ),
			array( '.xb-art-mappa::before', 'content:"⌖"' ),
			array( '.xb-art-azione', 'margin-block:1.6em' ),
			array( '.xb-art-pulsante', 'display:inline-block; padding:.7em 1.3em; border-radius:var(--xb-radius-m, 8px); background:var(--xb-primary, #333); color:var(--xb-on-primary, #fff); font-weight:600; text-decoration:none' ),
			array( '.xb-art-evento', 'display:grid; gap:.25em' ),
			array( '.xb-art-data', 'font-weight:600' ),
			array( '.xb-art-luogo', 'opacity:.8' ),
			array( '.xb-art-confronto', 'display:grid; grid-template-columns:1fr 1fr; gap:.6em; margin:1.6em 0' ),
			array( '.xb-art-confronto img', 'width:100%; height:auto; border-radius:4px' ),
			array( '.xb-art-confronto figcaption', 'grid-column:1 / -1; font-size:.9em; opacity:.75' ),
			array( '.xb-art-domande', 'margin-block:1.6em' ),
			array( '.xb-art-domande details', 'padding:.7em 0; border-block-end:1px solid var(--xb-neutral-a30, rgb(0 0 0 / 18%))' ),
			array( '.xb-art-domande summary', 'font-weight:600; cursor:pointer' ),
			array( '.xb-art-domande p', 'margin:.5em 0 0; white-space:pre-line' ),
			array( '.xb-art-leggi ul', 'margin:.4em 0 0; padding-inline-start:1.2em' ),
			array( '.xb-art-cronologia', 'margin-block:1.6em; padding:0; list-style:none; border-inline-start:2px solid var(--xb-neutral-a30, rgb(0 0 0 / 18%))' ),
			array( '.xb-art-cronologia li', 'padding-block:.4em; padding-inline-start:1em' ),
			array( '.xb-art-cronologia .xb-art-data', 'display:block' ),
			array( '.xb-art-indice', 'margin-block:1.6em; padding:1em 1.25em; border:1px solid var(--xb-neutral-a30, rgb(0 0 0 / 18%)); border-radius:var(--xb-radius-m, 8px)' ),
			array( '.xb-art-indice p', 'margin:0' ),
			array( '.xb-art-indice ol', 'margin:.4em 0 0; padding-inline-start:1.2em' ),
			array( '.xb-art-bibliografia', 'margin-block:1.6em; padding-inline-start:1.4em; font-size:.92em' ),
			array( '.xb-art-galleria', 'display:grid; grid-template-columns:repeat(auto-fit, minmax(10rem, 1fr)); gap:.6em; margin-block:1.6em' ),
			array( '.xb-art-galleria img', 'width:100%; aspect-ratio:1; object-fit:cover; border-radius:4px' ),
			array( '.xb-art-foto', 'margin:0' ),
			array( '.xb-art-foto figcaption', 'margin-block-start:.3em; font-size:.85em; opacity:.8' ),
			array( '.xb-art-note', 'margin-block-start:2.5em; padding-block-start:1em; padding-inline-start:1.4em; border-block-start:1px solid var(--xb-neutral-a30, rgb(0 0 0 / 18%)); font-size:.9em' ),
			array( '@media (max-width: 34em)', array( array( '.xb-art-libro, .xb-art-autore', 'flex-direction:column' ) ) ),
		);
	}
}
