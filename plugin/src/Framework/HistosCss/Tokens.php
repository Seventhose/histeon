<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

/**
 * Every Histeon CSS token, computed from the design parameters — docs/spec/histos-css.md §2. The names keep the --xb-*
 * semantic layer (components written with them work with every framework); the values come from a few inputs.
 * xb-tokens.css holds the values of the defaults; a site with its own parameters gets, inline, only the tokens
 * that differ.
 */
final class Tokens {

	/** Type steps (… −1, 0 = m, +1 …). */
	public const TEXT = DesignData::TEXT_STEPS;

	/** Space steps. */
	public const SPACE = DesignData::SPACE_STEPS;

	/** Transparency steps of every colour family (percent of the colour). */
	public const ALPHAS = DesignData::ALPHAS;

	/** Headings: the type step each one takes. */
	public const HEADINGS = DesignData::HEADINGS;

	/**
	 * Token name (without `--`) => value, in a fixed order.
	 *
	 * @param array<string, string|int|float> $design Validated parameters (Design::validate).
	 * @return array<string, string>
	 */
	public static function compute( array $design ): array {
		$d   = $design + Design::DEFAULTS;
		$vw0 = (float) $d['viewport_min'];
		$vw1 = (float) $d['viewport_max'];
		$out = array();

		foreach ( self::TEXT as $name => $step ) {
			[ $min, $max ]              = self::own_size( $d, 'text_' . $name ) ?? Scale::step( $step, (float) $d['text_min'], (float) $d['text_max'], (float) $d['text_ratio_min'], (float) $d['text_ratio_max'] );
			$out[ 'xb-text-' . $name ] = Scale::fluid( $min, $max, $vw0, $vw1 );
		}
		$out['xb-text-xxl'] = 'var(--xb-text-2xl)';
		foreach ( self::HEADINGS as $heading => $step ) {
			$own = self::own_size( $d, $heading );
			if ( null !== $own ) {
				$out[ 'xb-' . $heading ] = Scale::fluid( $own[0], $own[1], $vw0, $vw1 );
			} elseif ( 1 === (int) $d['heading_scale_on'] ) {
				// Their own scale: h6 is its step 0, h1 its step 5.
				[ $min, $max ]          = Scale::step( 6 - (int) substr( $heading, 1 ), (float) $d['heading_min'], (float) $d['heading_max'], (float) $d['heading_ratio_min'], (float) $d['heading_ratio_max'] );
				$out[ 'xb-' . $heading ] = Scale::fluid( $min, $max, $vw0, $vw1 );
			} else {
				$out[ 'xb-' . $heading ] = 'var(--xb-text-' . $step . ')';
			}
		}

		foreach ( self::SPACE as $name => $step ) {
			[ $min, $max ]               = Scale::step( $step, (float) $d['space_min'], (float) $d['space_max'], (float) $d['space_ratio_min'], (float) $d['space_ratio_max'] );
			$out[ 'xb-space-' . $name ] = Scale::fluid( $min, $max, $vw0, $vw1 );
		}
		$out['xb-space-xxl'] = 'var(--xb-space-2xl)';
		// Sections: the space steps xl … 4xl, times the multiplier of the small and of the large screen.
		$mult_min = (float) $d['section_multiplier_min'];
		$mult_max = (float) $d['section_multiplier_max'];
		foreach ( array( 's' => 'xl', 'm' => '2xl', 'l' => '3xl', 'xl' => '4xl' ) as $size => $space ) {
			if ( abs( $mult_min - 1.0 ) < 1e-9 && abs( $mult_max - 1.0 ) < 1e-9 ) {
				$out[ 'xb-section-' . $size ] = 'var(--xb-space-' . $space . ')';
				continue;
			}
			[ $min, $max ]                 = Scale::step( self::SPACE[ $space ], (float) $d['space_min'], (float) $d['space_max'], (float) $d['space_ratio_min'], (float) $d['space_ratio_max'] );
			$out[ 'xb-section-' . $size ] = Scale::fluid( $min * $mult_min, $max * $mult_max, $vw0, $vw1 );
		}
		$out['xb-section-pad'] = 'var(--xb-section-' . $d['section_padding'] . ')';
		$out['xb-gutter']      = (float) $d['gutter_min'] > 0 && (float) $d['gutter_max'] > 0 ? Scale::fluid( (float) $d['gutter_min'], (float) $d['gutter_max'], $vw0, $vw1 ) : 'var(--xb-space-m)';
		foreach ( array( 'container', 'content', 'grid' ) as $gap ) {
			$out[ 'xb-' . $gap . '-gap' ] = 'var(--xb-space-' . $d[ $gap . '_gap' ] . ')';
		}
		$out['xb-grid-min']  = Scale::number( (float) $d['grid_min'] ) . 'rem';
		$out['xb-grid-fill'] = (string) $d['grid_fill'];
		$max                     = (int) $d['grid_max'];
		$out['xb-grid-auto-min'] = $max > 0 ? 'max(var(--xb-grid-min), calc((100% - ' . ( $max - 1 ) . ' * var(--xb-grid-gap)) / ' . $max . '))' : 'var(--xb-grid-min)';

		$bases = self::bases( $d );
		foreach ( $bases as $family => $color ) {
			$out[ 'xb-' . $family ] = $color->css();
			foreach ( array_keys( Color::SHADES ) as $shade ) {
				$out[ 'xb-' . $family . '-' . $shade ] = $color->shade( $shade )->css();
			}
			$out[ 'xb-on-' . $family ] = $color->on()->css();
			// Transparencies: the colour over whatever is behind it (overlays, tints, borders).
			foreach ( self::ALPHAS as $alpha ) {
				$out[ 'xb-' . $family . '-a' . $alpha ] = 'color-mix(in oklch, var(--xb-' . $family . ') ' . $alpha . '%, transparent)';
			}
			$out[ 'xb-' . $family . '-hover' ] = 'var(--xb-' . $family . '-' . $d['hover_shade'] . ')';
		}
		// Kept from the first Histeon Tokens: a light and a dark variant of the two brand colours.
		$out['xb-primary-light']   = 'var(--xb-primary-100)';
		$out['xb-primary-dark']    = 'var(--xb-primary-800)';
		$out['xb-secondary-light'] = 'var(--xb-secondary-100)';
		$out['xb-secondary-dark']  = 'var(--xb-secondary-800)';

		// Semantic colours: the roles, from the panel or automatic (a dark theme points them elsewhere: dark()).
		$out['xb-white'] = 'oklch(99.5% 0 0)';
		$out['xb-black'] = 'oklch(15% 0 0)';
		foreach ( self::roles( $d, $bases, $bases, false ) as $role => $value ) {
			$out[ 'xb-' . $role ] = $value;
		}

		$radius                = (float) $d['radius'];
		// With a ratio, the steps around the medium radius follow it; at 0, the first steps (¼, ½, 2, 3).
		$ratio                 = (float) $d['radius_ratio'];
		$steps                 = $ratio >= 1 ? array( $radius / ( $ratio * $ratio ), $radius / $ratio, $radius * $ratio, $radius * $ratio * $ratio ) : array( $radius / 4, $radius / 2, $radius * 2, $radius * 3 );
		$out['xb-radius-xs']   = Scale::rem( $steps[0] );
		$out['xb-radius-s']    = Scale::rem( $steps[1] );
		$out['xb-radius-m']    = Scale::rem( $radius );
		$out['xb-radius-l']    = Scale::rem( $steps[2] );
		$out['xb-radius-xl']   = Scale::rem( $steps[3] );
		$out['xb-radius-full'] = '9999px';
		foreach ( array( 's', 'm', 'l', 'xl', '2xl' ) as $size ) {
			$out[ 'xb-shadow-' . $size ] = self::shadow( $d, 'shadow_' . $size );
		}
		foreach ( array( 's', 'm', 'l' ) as $size ) {
			$shadow                          = self::shadow( $d, 'tshadow_' . $size );
			$out[ 'xb-text-shadow-' . $size ] = $shadow;
			$out[ 'xb-drop-shadow-' . $size ] = 'drop-shadow(' . $shadow . ')';
		}
		$out['xb-border-width'] = Scale::number( (float) $d['border_width'] ) . 'px';
		$out['xb-border-style'] = (string) $d['border_style'];
		$out['xb-border-color'] = 'var(--xb-' . $d['border_color'] . ')';
		$out['xb-divider-width'] = Scale::number( (float) $d['divider_width'] ) . 'px';
		$out['xb-divider-style'] = (string) $d['divider_style'];
		$out['xb-divider-space'] = 'var(--xb-space-' . $d['divider_space'] . ')';
		$out['xb-divider-color'] = 'var(--xb-' . $d['divider_color'] . ')';
		$out['xb-transition']   = Scale::number( (float) $d['transition_ms'] ) . 'ms ease';
		// Buttons and links (histos-publish doc 19 §5.3.6): the classes read these tokens.
		$out['xb-btn-pad-y']    = Scale::number( (float) $d['btn_pad_y'] ) . 'em';
		$out['xb-btn-pad-x']    = Scale::number( (float) $d['btn_pad_x'] ) . 'em';
		$out['xb-btn-radius']   = 'none' === $d['btn_radius'] ? '0' : 'var(--xb-radius-' . $d['btn_radius'] . ')';
		$out['xb-btn-weight']   = Scale::number( (float) $d['btn_weight'] );
		$out['xb-btn-leading']  = Scale::number( (float) $d['btn_leading'] );
		$out['xb-btn-size']     = 'inherit' === $d['btn_size'] ? 'inherit' : 'var(--xb-text-' . $d['btn_size'] . ')';
		$out['xb-btn-case']     = (string) $d['btn_case'];
		$out['xb-btn-tracking'] = Scale::number( (float) $d['btn_tracking'] ) . 'em';
		$out['xb-btn-border']   = (float) $d['btn_border'] > 0 ? Scale::number( (float) $d['btn_border'] ) . 'px' : 'var(--xb-border-width)';
		$out['xb-btn-min']      = (float) $d['btn_min'] > 0 ? Scale::number( (float) $d['btn_min'] ) . 'rem' : '0';
		$out['xb-link-line']       = 'always' === $d['link_underline'] ? 'underline' : 'none';
		$out['xb-link-line-hover'] = 'never' === $d['link_underline'] ? 'none' : 'underline';
		$out['xb-link-thickness']  = (float) $d['link_thickness'] > 0 ? Scale::number( (float) $d['link_thickness'] ) . 'px' : 'auto';
		$out['xb-link-offset']     = Scale::number( (float) $d['link_offset'] ) . 'em';
		// Forms and details (histos-publish doc 19 §5.3.9-10).
		$out['xb-field-pad-y']      = Scale::number( (float) $d['field_pad_y'] ) . 'em';
		$out['xb-field-pad-x']      = Scale::number( (float) $d['field_pad_x'] ) . 'em';
		$out['xb-field-radius']     = 'none' === $d['field_radius'] ? '0' : 'var(--xb-radius-' . $d['field_radius'] . ')';
		$out['xb-field-bg']         = 'var(--xb-' . $d['field_bg'] . ')';
		$out['xb-field-border']     = (float) $d['field_border'] > 0 ? Scale::number( (float) $d['field_border'] ) . 'px' : 'var(--xb-border-width)';
		$out['xb-field-focus-ring'] = Scale::number( (float) $d['field_focus_ring'] ) . 'px';
		$out['xb-field-accent']     = 'var(--xb-primary)';
		$out['xb-field-invalid']    = 'var(--xb-' . $d['field_invalid'] . ')';
		$out['xb-field-valid']      = 'var(--xb-' . $d['field_valid'] . ')';
		$out['xb-label-weight']     = Scale::number( (float) $d['label_weight'] );
		$out['xb-field-size']        = 'inherit' === $d['field_size'] ? 'inherit' : 'var(--xb-text-' . $d['field_size'] . ')';
		$out['xb-field-placeholder'] = 'var(--xb-' . $d['field_placeholder'] . ')';
		$out['xb-label-size']        = 'inherit' === $d['label_size'] ? 'inherit' : 'var(--xb-text-' . $d['label_size'] . ')';
		$out['xb-help-size']         = 'var(--xb-text-' . $d['help_size'] . ')';
		$out['xb-help-color']        = 'var(--xb-' . $d['help_color'] . ')';
		$out['xb-check-size']        = Scale::number( (float) $d['check_size'] ) . 'em';
		$out['xb-field-gap']         = 'var(--xb-space-' . $d['field_gap'] . ')';
		$out['xb-step-height']       = Scale::number( (float) $d['step_height'] ) . 'px';
		$out['xb-step-color']        = 'var(--xb-' . $d['step_color'] . ')';
		$out['xb-step-track']        = 'var(--xb-' . $d['step_track'] . ')';
		$out['xb-field-focus']       = 'var(--xb-' . $d['field_focus_color'] . ')';
		$out['xb-label-color']       = 'var(--xb-' . $d['label_color'] . ')';
		$out['xb-legend-size']       = 'inherit' === $d['legend_size'] ? 'inherit' : 'var(--xb-text-' . $d['legend_size'] . ')';
		$out['xb-focus-width']      = Scale::number( (float) $d['focus_width'] ) . 'px';
		$out['xb-focus-offset']     = Scale::number( (float) $d['focus_offset'] ) . 'px';
		$out['xb-focus-style']      = (string) $d['focus_style'];
		$out['xb-selection-bg']     = 'var(--xb-primary-' . $d['selection_shade'] . ')';
		$out['xb-selection-text']   = 'var(--xb-' . $d['selection_text'] . ')';
		$out['xb-columns-min']      = Scale::number( (float) $d['columns_min'] ) . 'rem';
		$out['xb-columns-gap']      = 'var(--xb-space-' . $d['columns_gap'] . ')';
		$out['xb-columns-rule']     = 1 === (int) $d['columns_rule'] ? 'var(--xb-border-width) solid var(--xb-border)' : 'none';
		$out['xb-quote-width']      = Scale::number( (float) $d['quote_width'] ) . 'px';
		$out['xb-quote-color']      = 'var(--xb-' . $d['quote_color'] . ')';
		$out['xb-quote-pad']        = 'var(--xb-space-' . $d['quote_pad'] . ')';
		$out['xb-quote-style']      = 1 === (int) $d['quote_italic'] ? 'italic' : 'normal';
		// Cards and icons (histos-publish doc 19 §5.3.7-8).
		$out['xb-card-pad']    = 'var(--xb-space-' . $d['card_pad'] . ')';
		$out['xb-card-gap']    = 'var(--xb-space-' . $d['card_gap'] . ')';
		$out['xb-card-radius'] = 'var(--xb-radius-' . $d['card_radius'] . ')';
		$out['xb-card-bg']     = 'var(--xb-' . $d['card_bg'] . ')';
		$out['xb-card-shadow'] = 'none' === $d['card_shadow'] ? 'none' : 'var(--xb-shadow-' . $d['card_shadow'] . ')';
		$out['xb-card-border'] = 1 === (int) $d['card_border'] ? 'var(--xb-border-width) var(--xb-border-style) var(--xb-border)' : 'none';
		$out['xb-card-ratio']  = str_replace( '/', ' / ', (string) $d['card_ratio'] );
		$out['xb-card-title']  = 'var(--xb-' . $d['card_title'] . ')';
		$out['xb-radius-inner'] = 'max(0px, calc(var(--xb-card-radius) - var(--xb-card-pad)))';
		foreach ( array( 'xs', 's', 'm', 'l', 'xl', '2xl' ) as $size ) {
			$out[ 'xb-icon-' . $size ] = Scale::number( (float) $d[ 'icon_' . $size ] ) . 'em';
		}
		$out['xb-icon-color']       = 'currentColor' === $d['icon_color'] ? 'currentColor' : 'var(--xb-' . $d['icon_color'] . ')';
		$out['xb-icon-stroke']      = Scale::number( (float) $d['icon_stroke'] );
		$out['xb-icon-box-bg']      = 'var(--xb-primary-' . $d['icon_box_shade'] . ')';
		$out['xb-icon-box-pad']     = 'var(--xb-space-' . $d['icon_box_pad'] . ')';
		$out['xb-icon-box-radius']  = 'none' === $d['icon_box_radius'] ? '0' : 'var(--xb-radius-' . $d['icon_box_radius'] . ')';
		$out['xb-list-icon-color']  = 'var(--xb-' . $d['list_icon_color'] . ')';
		$out['xb-list-icon-gap']    = 'var(--xb-space-' . $d['list_icon_gap'] . ')';
		if ( 'check' !== $d['list_icon_symbol'] ) {
			$out['xb-list-icon-mask'] = 'url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E%3Cpath d=%27' . DesignData::LIST_ICONS[ (string) $d['list_icon_symbol'] ] . '%27/%3E%3C/svg%3E")';
		}
		// Overlay and the site's surfaces (§5.3.7): a surface's text is white or black, whichever reads more on it.
		$out['xb-overlay']       = 'black' === $d['overlay_color'] ? 'oklch(0% 0 0 / ' . Scale::number( (float) $d['overlay_opacity'] ) . ')' : 'color-mix(in oklch, var(--xb-' . $d['overlay_color'] . ') ' . Scale::number( (float) $d['overlay_opacity'] * 100 ) . '%, transparent)';
		$out['xb-overlay-blend'] = (string) $d['overlay_blend'];
		foreach ( array( 2, 3 ) as $n ) {
			$color                              = (string) $d[ 'overlay_' . $n . '_color' ];
			$opacity                            = (float) $d[ 'overlay_' . $n . '_opacity' ];
			$out[ 'xb-overlay-' . $n ]          = 'black' === $color ? 'oklch(0% 0 0 / ' . Scale::number( $opacity ) . ')' : 'color-mix(in oklch, var(--xb-' . $color . ') ' . Scale::number( $opacity * 100 ) . '%, transparent)';
			$out[ 'xb-overlay-' . $n . '-blend' ] = (string) $d[ 'overlay_' . $n . '_blend' ];
		}
		foreach ( array( 1, 2, 3 ) as $n ) {
			$ref = (string) $d[ 'role_named_' . $n ];
			$bg  = 'auto' === $ref ? null : self::ref_color( $ref, $bases, $bases );
			if ( null === $bg ) {
				continue;
			}
			// With a second colour the surface is a gradient: its text must read on both ends.
			$to    = (string) $d[ 'role_named_' . $n . '_to' ];
			$end   = 'auto' === $to ? null : self::ref_color( $to, $bases, $bases );
			$ends  = null === $end ? array( $bg ) : array( $bg, $end );
			$white = min( array_map( static fn ( Color $c ): float => Color::contrast( Color::oklch( 0.995, 0.0, 0.0 ), $c ), $ends ) ) >= min( array_map( static fn ( Color $c ): float => Color::contrast( Color::oklch( 0.15, 0.0, 0.0 ), $c ), $ends ) );
			$out[ 'xb-surface-' . $n . '-bg' ]     = 'var(--xb-' . $ref . ')';
			$out[ 'xb-surface-' . $n . '-text' ]   = $white ? 'var(--xb-white)' : 'var(--xb-black)';
			$out[ 'xb-surface-' . $n . '-border' ] = 1 === (int) $d[ 'surface_' . $n . '_border' ] ? 'var(--xb-border-width) var(--xb-border-style) var(--xb-border)' : 'none';
			$out[ 'xb-surface-' . $n . '-shadow' ] = 'none' === $d[ 'surface_' . $n . '_shadow' ] ? 'none' : 'var(--xb-shadow-' . $d[ 'surface_' . $n . '_shadow' ] . ')';
			if ( null !== $end ) {
				$out[ 'xb-surface-' . $n . '-image' ] = 'linear-gradient(' . Scale::number( (float) $d[ 'surface_' . $n . '_angle' ] ) . 'deg, var(--xb-' . $ref . '), var(--xb-' . $to . '))';
			}
		}
		// Motion and effects (histos-publish doc 19 §5.3.11): the xb-enter-*, xb-exit-* and xb-hover-* classes read them.
		$out['xb-enter-range']    = Scale::number( (float) $d['enter_range'] ) . '%';
		$out['xb-enter-distance'] = 'var(--xb-space-' . $d['enter_distance'] . ')';
		$out['xb-enter-ease']     = (string) $d['enter_ease'];
		$out['xb-hover-duration'] = Scale::number( (float) $d['hover_duration'] ) . 'ms';
		$out['xb-hover-lift']     = Scale::number( (float) $d['hover_lift'] ) . 'px';
		$out['xb-hover-scale']    = Scale::number( (float) $d['hover_scale'] );
		$out['xb-enter-scale']    = Scale::number( (float) $d['enter_scale'] );
		$out['xb-enter-scale-out'] = Scale::number( 2 - (float) $d['enter_scale'] );
		$out['xb-enter-blur']     = Scale::number( (float) $d['enter_blur'] ) . 'px';
		$out['xb-exit-range']     = Scale::number( (float) $d['exit_range'] ) . '%';
		$out['xb-hover-bright']   = Scale::number( (float) $d['hover_bright'] );
		$out['xb-hover-fade']     = Scale::number( (float) $d['hover_fade'] );

		$out['xb-width-content'] = Scale::number( (float) $d['width_content'] ) . 'rem';
		$out['xb-width-wide']    = Scale::number( (float) $d['width_wide'] ) . 'rem';
		$out['xb-width-prose']   = Scale::number( (float) $d['width_prose'] ) . 'ch';
		$out['xb-width-min']     = Scale::rem( (float) $d['width_min'] );
		$out['xb-header-height'] = Scale::fluid( (float) $d['header_min'], (float) $d['header_max'], $vw0, $vw1 );
		$out['xb-sticky-top']    = 'var(--xb-header-height)';
		// Text spacing in xb-prose and the boxed layout (histos-publish doc 19 §5.3.3-4).
		$out['xb-flow-text']     = Scale::number( (float) $d['flow_text'] ) . 'em';
		$out['xb-flow-heading']  = Scale::number( (float) $d['flow_heading'] ) . 'em';
		$out['xb-flow-after']    = (float) $d['flow_after'] > 0 ? Scale::number( (float) $d['flow_after'] ) . 'em' : 'var(--xb-flow-text)';
		$out['xb-flow-list']     = Scale::number( (float) $d['flow_list'] ) . 'em';
		$out['xb-boxed-width']   = Scale::number( (float) $d['boxed_width'] ) . 'rem';
		$out['xb-boxed-bg']      = 'var(--xb-' . $d['boxed_bg'] . ')';
		$out['xb-boxed-shadow']  = 'none' === $d['boxed_shadow'] ? 'none' : 'var(--xb-shadow-' . $d['boxed_shadow'] . ')';

		$out['xb-font-body']    = (string) $d['font_body'];
		$out['xb-font-heading'] = (string) $d['font_heading'];
		$out['xb-font-mono']    = (string) $d['font_mono'];
		foreach ( DesignData::OPTIONAL_FONTS as $font ) {
			if ( '' !== (string) $d[ $font ] ) {
				$out[ 'xb-' . str_replace( '_', '-', $font ) ] = (string) $d[ $font ];
			}
		}
		$out['xb-leading-tight']  = '1.15';
		$out['xb-leading-normal'] = Scale::number( (float) $d['text_leading'] );
		$out['xb-leading-loose']  = '1.8';
		$out['xb-text-wrap']      = (string) $d['text_wrap'];
		// The headings' style (histos-publish doc 19 §5.3.2): all of them, then each level (0 = as all).
		$role                        = (string) $d['heading_font'];
		$out['xb-heading-font']      = in_array( $role, array( 'accent', 'display' ), true ) ? 'var(--xb-font-' . $role . ', var(--xb-font-heading))' : 'var(--xb-font-' . $role . ')';
		$out['xb-heading-weight']    = Scale::number( (float) $d['heading_weight'] );
		$out['xb-heading-leading']   = Scale::number( (float) $d['heading_leading'] );
		$out['xb-heading-tracking']  = Scale::number( (float) $d['heading_tracking'] ) . 'em';
		$out['xb-heading-transform'] = (string) $d['heading_case'];
		$out['xb-heading-wrap']      = (string) $d['heading_wrap'];
		$out['xb-heading-measure']   = (float) $d['heading_measure'] > 0 ? Scale::number( (float) $d['heading_measure'] ) . 'ch' : 'none';
		$out['xb-heading-style']     = 1 === (int) $d['heading_italic'] ? 'italic' : 'normal';
		foreach ( array_keys( self::HEADINGS ) as $heading ) {
			$weight                              = (float) $d[ $heading . '_weight' ];
			$leading                             = (float) $d[ $heading . '_leading' ];
			$out[ 'xb-' . $heading . '-weight' ]  = $weight > 0 ? Scale::number( $weight ) : 'var(--xb-heading-weight)';
			$out[ 'xb-' . $heading . '-leading' ] = $leading > 0 ? Scale::number( $leading ) : 'var(--xb-heading-leading)';
			$color                                 = (string) $d[ $heading . '_color' ];
			$font                                  = (string) $d[ $heading . '_font' ];
			$case                                  = (string) $d[ $heading . '_case' ];
			$out[ 'xb-' . $heading . '-color' ]     = 'auto' === $color ? 'var(--xb-heading)' : 'var(--xb-' . $color . ')';
			$out[ 'xb-' . $heading . '-font' ]      = 'auto' === $font ? 'var(--xb-heading-font)' : ( in_array( $font, array( 'accent', 'display' ), true ) ? 'var(--xb-font-' . $font . ', var(--xb-font-heading))' : 'var(--xb-font-' . $font . ')' );
			$out[ 'xb-' . $heading . '-transform' ] = 'auto' === $case ? 'var(--xb-heading-transform)' : $case;
			// Printed only when set: the rules fall back to all the headings'.
			if ( 'auto' !== $d[ $heading . '_wrap' ] ) {
				$out[ 'xb-' . $heading . '-wrap' ] = (string) $d[ $heading . '_wrap' ];
			}
			if ( 0.0 !== (float) $d[ $heading . '_tracking' ] ) {
				$out[ 'xb-' . $heading . '-tracking' ] = Scale::number( (float) $d[ $heading . '_tracking' ] ) . 'em';
			}
			if ( 0.0 !== (float) $d[ $heading . '_measure' ] ) {
				$out[ 'xb-' . $heading . '-measure' ] = Scale::number( (float) $d[ $heading . '_measure' ] ) . 'ch';
			}
		}

		return $out;
	}

	/** Text and background pairs every site uses: [text token, background token]. */
	private const READABLE = array(
		array( 'xb-text', 'xb-bg' ),
		array( 'xb-heading', 'xb-bg' ),
		array( 'xb-text-muted', 'xb-bg' ),
		array( 'xb-text', 'xb-surface' ),
		array( 'xb-text', 'xb-surface-2' ),
		array( 'xb-heading', 'xb-surface' ),
		array( 'xb-text-muted', 'xb-surface' ),
		array( 'xb-text-muted', 'xb-surface-2' ),
		array( 'xb-link', 'xb-bg' ),
		array( 'xb-link', 'xb-surface' ),
	);

	/**
	 * How readable the palette is (WCAG 2 contrast), from the tokens themselves: the semantic pairs above, and the
	 * text colour Histeon CSS puts on each family (--xb-on-*). Normal text needs 4.5:1.
	 *
	 * @param array<string, string|int|float> $design
	 * @return list<array{text: string, bg: string, ratio: float, ok: bool, scheme?: string}>
	 */
	public static function contrast( array $design ): array {
		$tokens  = self::compute( $design );
		$resolve = static function ( string $name ) use ( $tokens ): ?Color {
			for ( $hops = 0, $value = $tokens[ $name ] ?? ''; $hops < 5; $hops++ ) {
				if ( 1 !== preg_match( '/^var\(--([a-z0-9-]+)\)$/', $value, $m ) ) {
					return Color::parse( $value );
				}
				$value = $tokens[ $m[1] ] ?? '';
			}
			return null;
		};
		$pairs = self::READABLE;
		foreach ( array_keys( self::bases( $design + Design::DEFAULTS ) ) as $family ) {
			$pairs[] = array( 'xb-on-' . $family, 'xb-' . $family );
		}
		$out = self::pairs( $pairs, $resolve );
		if ( 1 === (int) ( $design + Design::DEFAULTS )['dark_on'] ) {
			// The dark theme: the same pairs with its roles and family colours.
			$dark   = self::dark( $design ) + $tokens;
			$active = array_keys( self::bases( $design + Design::DEFAULTS ) );
			$pairs  = self::READABLE;
			foreach ( $active as $family ) {
				$pairs[] = array( 'xb-on-' . $family, 'xb-' . $family );
			}
			$in_dark = static function ( string $name ) use ( $dark ): ?Color {
				for ( $hops = 0, $value = $dark[ $name ] ?? ''; $hops < 5; $hops++ ) {
					if ( 1 !== preg_match( '/^var\(--([a-z0-9-]+)\)$/', $value, $m ) ) {
						return Color::parse( $value );
					}
					$value = $dark[ $m[1] ] ?? '';
				}
				return null;
			};
			foreach ( self::pairs( $pairs, $in_dark ) as $pair ) {
				$out[] = $pair + array( 'scheme' => 'dark' );
			}
		}
		return $out;
	}

	/**
	 * The tokens of a site whose parameters differ from the defaults: only those that change, as `:root{…}`.
	 * '' when nothing changes. Values come from validated parameters: no character can close the rule.
	 *
	 * @param array<string, string|int|float> $design
	 */
	public static function css_changes( array $design ): string {
		$defaults = self::compute( Design::DEFAULTS );
		$rules    = '';
		foreach ( self::printed( self::compute( $design ), $design ) as $name => $value ) {
			if ( ( $defaults[ $name ] ?? null ) !== $value ) {
				$rules .= '--' . $name . ':' . $value . ';';
			}
		}
		$css = '' === $rules ? '' : ':root{' . $rules . '}';
		$d   = $design + Design::DEFAULTS;
		if ( 1 !== (int) $d['dark_on'] ) {
			return $css . self::element_rules( $d );
		}
		// The dark theme: always for the sections marked dark; for the whole site with the scheme «dark» or «system».
		$dark  = self::printed( self::dark( $d ), $d );
		$light = self::declarations( array_intersect_key( self::compute( $d ), $dark ) );
		$dark  = self::declarations( $dark );
		$page  = 'background-color:var(--xb-bg);color:var(--xb-text);';
		$css  .= ':root .xb-scheme-dark,:root.xb-scheme-dark{' . $dark . 'color-scheme:dark}:root .xb-scheme-light,:root.xb-scheme-light{' . $light . 'color-scheme:light}';
		$site  = ':root:not(.xb-scheme-light){' . $dark . $page . 'color-scheme:dark}';
		if ( 'dark' === $d['scheme'] ) {
			$css .= $site;
		} elseif ( 'system' === $d['scheme'] ) {
			$css .= ':root{color-scheme:light dark}@media (prefers-color-scheme:dark){' . $site . '}';
		}
		return $css . self::element_rules( $d );
	}

	/**
	 * The panel's live preview: css_changes() and, for the groups switched off, every token set to `initial`: over the
	 * sheet the page already has, a `var()` of it takes its fallback, as on a site that does not print it.
	 *
	 * @param array<string, string|int|float> $design
	 */
	public static function preview_css( array $design ): string {
		$tokens = self::compute( $design );
		$rules  = '';
		foreach ( array_keys( array_diff_key( $tokens, self::printed( $tokens, $design ) ) ) as $name ) {
			$rules .= '--' . $name . ':initial;';
		}
		return self::css_changes( $design ) . ( '' === $rules ? '' : ':root{' . $rules . '}' );
	}

	/**
	 * A shadow from the panel: its distance, blur and opacity.
	 *
	 * @param array<string, string|int|float> $d
	 */
	private static function shadow( array $d, string $name ): string {
		return '0 ' . Scale::number( (float) $d[ $name . '_y' ] ) . 'px ' . Scale::number( (float) $d[ $name . '_blur' ] ) . 'px oklch(0% 0 0 / ' . Scale::number( (float) $d[ $name . '_opacity' ] ) . ')';
	}

	/**
	 * A step's own size from the panel (small and large screen, px), or null when it follows its scale.
	 *
	 * @param array<string, string|int|float> $d
	 * @return array{0: float, 1: float}|null
	 */
	private static function own_size( array $d, string $step ): ?array {
		$min = (float) $d[ 'size_' . $step . '_min' ];
		$max = (float) $d[ 'size_' . $step . '_max' ];
		return $min > 0 && $max > 0 ? array( $min, $max ) : null;
	}

	/**
	 * The tokens a site prints: without the groups switched off in the panel (print_*, histos-publish doc 19 §5.3.12).
	 * The classes that read them fall back to their own values, or do nothing.
	 *
	 * @param array<string, string>           $tokens
	 * @param array<string, string|int|float> $design
	 * @return array<string, string>
	 */
	public static function printed( array $tokens, array $design ): array {
		$off = self::off_patterns( $design );
		if ( array() === $off ) {
			return $tokens;
		}
		return array_filter(
			$tokens,
			static function ( string $name ) use ( $off ): bool {
				foreach ( $off as $pattern ) {
					if ( 1 === preg_match( '/' . $pattern . '/', $name ) ) {
						return false;
					}
				}
				return true;
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/** @param array<string, string|int|float> $design */
	public static function groups_off( array $design ): bool {
		return array() !== self::off_patterns( $design );
	}

	/**
	 * The whole `:root{…}` of a site with groups switched off, in place of the shipped xb-tokens.css.
	 *
	 * @param array<string, string|int|float> $design
	 */
	public static function sheet( array $design ): string {
		return ':root{' . self::declarations( self::printed( self::compute( $design ), $design ) ) . '}';
	}

	/**
	 * The tokens a site prints, for the panel's «Export tokens (DTCG)»: the light theme and, with the dark theme on,
	 * the same tokens with its colours in place (null when it is off). Names keep their `xb-` prefix; the editor
	 * turns them into DTCG (editor/src/histos-css/dtcg.ts).
	 *
	 * @param array<string, string|int|float> $design
	 * @return array{light: array<string, string>, dark: array<string, string>|null}
	 */
	public static function export( array $design ): array {
		$d     = $design + Design::DEFAULTS;
		$light = self::printed( self::compute( $d ), $d );
		$dark  = 1 === (int) $d['dark_on'] ? array_replace( $light, self::printed( self::dark( $d ), $d ) ) : null;
		return array(
			'light' => $light,
			'dark'  => $dark,
		);
	}

	/**
	 * @param array<string, string|int|float> $design
	 * @return list<string>
	 */
	private static function off_patterns( array $design ): array {
		$d   = $design + Design::DEFAULTS;
		$off = array();
		foreach ( DesignData::PRINT_GROUPS as $group => $pattern ) {
			if ( 0 === (int) $d[ 'print_' . $group ] ) {
				$off[] = $pattern;
			}
		}
		return $off;
	}

	/**
	 * The rules on bare elements the panel switches on: the root size, the scroll offset under the header, the default style of the headings h1-h6 and of
	 * the text. In `:where()`, so any class of the site wins over them. Values are tokens or validated numbers.
	 *
	 * @param array<string, string|int|float> $d Parameters with the defaults.
	 */
	private static function element_rules( array $d ): string {
		$css = abs( (float) $d['root_size'] - 100.0 ) > 1e-9 ? 'html{font-size:' . Scale::number( (float) $d['root_size'] ) . '%}' : '';
		if ( 1 === (int) $d['boxed_on'] ) {
			$css .= 'html{background-color:var(--xb-boxed-bg)}body{box-sizing:border-box;max-inline-size:var(--xb-boxed-width);min-block-size:100vh;margin-inline:auto;background-color:var(--xb-bg);box-shadow:var(--xb-boxed-shadow)}';
		}
		if ( 1 === (int) $d['scroll_offset_on'] ) {
			// Anchors land below a fixed header.
			$css .= 'html{scroll-padding-top:var(--xb-header-height)}';
		}
		if ( 1 === (int) $d['focus_on'] ) {
			$css .= ':where(:focus-visible){outline:var(--xb-focus-width) var(--xb-focus-style) var(--xb-focus);outline-offset:var(--xb-focus-offset)}';
		}
		if ( 1 === (int) $d['smooth_scroll'] ) {
			$css .= '@media (prefers-reduced-motion:no-preference){html{scroll-behavior:smooth}}';
		}
		if ( 1 === (int) $d['reduce_motion'] ) {
			$css .= '@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:0.01ms !important;animation-iteration-count:1 !important;transition-duration:0.01ms !important}html,body{scroll-behavior:auto !important}}';
		}
		if ( 1 === (int) $d['selection_on'] ) {
			$css .= '::selection{background-color:var(--xb-selection-bg);color:var(--xb-selection-text)}';
		}
		if ( 1 === (int) $d['quotes_on'] ) {
			$css .= ':where(blockquote){margin-inline:0;padding-inline-start:var(--xb-quote-pad);border-inline-start:var(--xb-quote-width) solid var(--xb-quote-color);color:var(--xb-text-muted);font-style:var(--xb-quote-style)}:where(blockquote cite){display:block;margin-block-start:var(--xb-space-xs);font-size:var(--xb-text-s);font-style:normal}';
		}
		if ( 1 === (int) $d['forms_on'] ) {
			// Bare fields take the field tokens in `:where()`. Fluent Forms' sheet weighs two classes and more, and comes
			// later: its markup needs `html` in front to take them.
			$field = ':where(input:not([type=checkbox],[type=radio],[type=submit],[type=button],[type=reset],[type=range],[type=color],[type=file],[type=hidden]),select,textarea),html .fluentform .ff-el-form-control';
			$css  .= $field . '{box-sizing:border-box;padding:var(--xb-field-pad-y) var(--xb-field-pad-x);border:var(--xb-field-border) solid var(--xb-border);border-radius:var(--xb-field-radius);background-color:var(--xb-field-bg);color:var(--xb-text);font:inherit}';
			$css  .= ':where(input,select,textarea):focus,html .fluentform .ff-el-form-control:focus{outline:none;border-color:var(--xb-field-focus);box-shadow:0 0 0 var(--xb-field-focus-ring) var(--xb-primary-a30)}';
			$css  .= ':where(input[type=checkbox],input[type=radio]){accent-color:var(--xb-field-accent)}';
			$css  .= ':where(label),html .fluentform .ff-el-input--label label{font-weight:var(--xb-label-weight);color:var(--xb-label-color)}';
			$css  .= ':where([aria-invalid="true"]),html .fluentform .ff-el-is-error .ff-el-form-control{border-color:var(--xb-field-invalid)}html .fluentform .error,html .fluentform .text-danger{color:var(--xb-field-invalid)}';
			$css  .= ':where(input,textarea)::placeholder,html .fluentform .ff-el-form-control::placeholder{color:var(--xb-field-placeholder);opacity:1}:where(input:not([type=checkbox],[type=radio]),select,textarea),html .fluentform .ff-el-form-control{font-size:var(--xb-field-size)}:where(label),html .fluentform .ff-el-input--label label{font-size:var(--xb-label-size)}:where(.xb-help),html .fluentform .ff-el-help-message{font-size:var(--xb-help-size);color:var(--xb-help-color)}html .fluentform .ff-el-is-valid .ff-el-form-control{border-color:var(--xb-field-valid)}:where(input[type=checkbox],input[type=radio]){inline-size:var(--xb-check-size);block-size:var(--xb-check-size)}:where(select),html .fluentform select.ff-el-form-control{appearance:none;padding-inline-end:2.4em;background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%);background-position:calc(100% - 1.25em) 55%,calc(100% - 0.9em) 55%;background-size:0.35em 0.35em;background-repeat:no-repeat}:where(fieldset){margin:0;padding:var(--xb-space-m);border:var(--xb-field-border) solid var(--xb-border);border-radius:var(--xb-field-radius)}:where(legend){padding-inline:var(--xb-space-2xs);font-size:var(--xb-legend-size);font-weight:var(--xb-label-weight)}html .fluentform .ff-el-group{margin-block-end:var(--xb-field-gap)}';
			if ( 'switch' === $d['check_style'] ) {
				$css .= ':where(input[type=checkbox]){appearance:none;position:relative;inline-size:calc(var(--xb-check-size) * 1.8);block-size:var(--xb-check-size);border-radius:999px;background-color:var(--xb-neutral-300);cursor:pointer;transition:background-color var(--xb-transition)}:where(input[type=checkbox])::before{content:"";position:absolute;inset-block:2px;inset-inline-start:2px;aspect-ratio:1;border-radius:50%;background-color:var(--xb-white);transition:translate var(--xb-transition)}:where(input[type=checkbox]:checked){background-color:var(--xb-field-accent)}:where(input[type=checkbox]:checked)::before{translate:calc(var(--xb-check-size) * 0.8) 0}';
			}
			$submit = (string) $d['form_submit'];
			$css   .= 'html .fluentform .ff-btn-submit{padding:var(--xb-btn-pad-y) var(--xb-btn-pad-x);border:0;border-radius:var(--xb-btn-radius);background-color:var(--xb-' . $submit . ');color:var(--xb-on-' . $submit . ');font-weight:var(--xb-btn-weight)}html .fluentform .ff-btn-submit:hover{background-color:var(--xb-' . $submit . '-hover)}';
			// The step bar of multi-page forms.
			$css .= 'html .fluentform .ff-el-progress{block-size:var(--xb-step-height, 0.5rem);border-radius:var(--xb-radius-full);background-color:var(--xb-step-track, var(--xb-neutral-200))}html .fluentform .ff-el-progress-bar{background-color:var(--xb-step-color, var(--xb-primary))}html .fluentform .ff-el-progress-bar span{display:none}';
		}
		if ( 1 === (int) $d['btn_full_mobile'] ) {
			$css .= '@media (max-width:' . Scale::number( (float) $d['btn_full_below'] ) . 'px){:where(.xb-btn,[class^="xb-btn-"],[class*=" xb-btn-"]){inline-size:100%}}';
		}
		if ( 1 === (int) $d['links_on'] ) {
			$css .= ':where(a:not([class])){color:var(--xb-link);text-decoration-line:var(--xb-link-line);text-decoration-thickness:var(--xb-link-thickness);text-underline-offset:var(--xb-link-offset);transition:color var(--xb-transition)}:where(a:not([class])):hover{color:var(--xb-link-hover);text-decoration-line:var(--xb-link-line-hover)}';
		}
		if ( 1 === (int) $d['external_on'] && '' !== $d['external_host'] ) {
			// The site's domain comes from the panel (a validated host name); so does the words a screen reader says.
			$host   = (string) $d['external_host'];
			$before = 'before' === $d['external_place'];
			$symbol = 'arrow' === $d['external_symbol'] ? '↗' : '';
			$css   .= ':where(:is(a:not([class]),.xb-link,.xb-prose a)[href^="http"]:not([href^="https://' . $host . '"],[href^="http://' . $host . '"],[href^="https://www.' . $host . '"],[href^="http://www.' . $host . '"]))::' . ( $before ? 'before' : 'after' ) . '{content:"' . $symbol . '";content:"' . $symbol . '" / "' . $d['external_label'] . '";margin-inline-' . ( $before ? 'end' : 'start' ) . ':0.2em}';
		}
		// The hover of the light and dark buttons, when it is not the sheet's; `html` in front, because that sheet comes later.
		foreach ( array( 'light' => '200', 'dark' => '900' ) as $variant => $sheet ) {
			if ( $sheet !== (string) $d[ 'btn_' . $variant . '_hover' ] ) {
				foreach ( Design::FAMILIES as $family ) {
					$css .= 'html .xb-btn-' . $family . '-' . $variant . ':hover{background-color:var(--xb-' . $family . '-' . $d[ 'btn_' . $variant . '_hover' ] . ')}';
				}
			}
		}
		// A site surface under a name of its own (a checked class name).
		foreach ( array( 1, 2, 3 ) as $n ) {
			if ( '' !== $d[ 'surface_' . $n . '_name' ] ) {
				$css .= ':where(.xb-surface-' . $d[ 'surface_' . $n . '_name' ] . '){background-color:var(--xb-surface-' . $n . '-bg, var(--xb-surface));background-image:var(--xb-surface-' . $n . '-image, none);color:var(--xb-surface-' . $n . '-text, var(--xb-text));border:var(--xb-surface-' . $n . '-border, none);box-shadow:var(--xb-surface-' . $n . '-shadow, none)}';
			}
		}
		if ( 'content' === $d['flow_scope'] ) {
			// The text spacing of xb-prose for all the page's content: only on text blocks, in `:where()`, so any class wins.
			$css .= ':where(main,[role=main]) :where(p,ul,ol,dl,blockquote,pre,table,figure,h1,h2,h3,h4,h5,h6){margin-block:0}:where(main,[role=main]) :where(p,ul,ol,dl,blockquote,pre,table,figure):where(:not(:first-child)){margin-block-start:var(--xb-flow-text)}:where(main,[role=main]) :where(h1,h2,h3,h4,h5,h6):where(:not(:first-child)){margin-block-start:var(--xb-flow-heading)}:where(main,[role=main]) :where(h1,h2,h3,h4,h5,h6) + :where(*){margin-block-start:var(--xb-flow-after)}:where(main,[role=main]) :where(li + li,li > ul,li > ol){margin-block-start:var(--xb-flow-list)}';
		}
		if ( 1 === (int) $d['section_grid_on'] ) {
			// Every section on the content grid (.xb-content-grid in Utilities).
			$css .= ':where(section,.xb-section){display:grid;row-gap:var(--xb-container-gap);grid-template-columns:[full-start] minmax(var(--xb-gutter), 1fr) [wide-start] minmax(0, calc((var(--xb-width-wide) - var(--xb-width-content)) / 2)) [content-start] min(100% - 2 * var(--xb-gutter), var(--xb-width-content)) [content-end] minmax(0, calc((var(--xb-width-wide) - var(--xb-width-content)) / 2)) [wide-end] minmax(var(--xb-gutter), 1fr) [full-end]}:where(section,.xb-section) > :where(*){grid-column:content;min-inline-size:0}:where(section,.xb-section) > :where(.xb-wide){grid-column:wide}:where(section,.xb-section) > :where(.xb-full){grid-column:full}:where(section,.xb-section) > :where(.xb-narrow){inline-size:100%;max-inline-size:var(--xb-width-prose);justify-self:center}';
		}
		if ( 1 === (int) $d['headings_on'] ) {
			$css .= ':where(h1,h2,h3,h4,h5,h6){font-family:var(--xb-heading-font);letter-spacing:var(--xb-heading-tracking);text-transform:var(--xb-heading-transform);text-wrap:var(--xb-heading-wrap);max-inline-size:var(--xb-heading-measure);font-style:var(--xb-heading-style);color:var(--xb-heading)}';
			foreach ( array_keys( self::HEADINGS ) as $heading ) {
				$css .= ':where(' . $heading . '){text-wrap:var(--xb-' . $heading . '-wrap, var(--xb-heading-wrap));letter-spacing:var(--xb-' . $heading . '-tracking, var(--xb-heading-tracking));max-inline-size:var(--xb-' . $heading . '-measure, var(--xb-heading-measure));font-family:var(--xb-' . $heading . '-font);font-size:var(--xb-' . $heading . ');font-weight:var(--xb-' . $heading . '-weight);line-height:var(--xb-' . $heading . '-leading);text-transform:var(--xb-' . $heading . '-transform);color:var(--xb-' . $heading . '-color)}';
			}
		}
		if ( 1 === (int) $d['text_on'] ) {
			$css .= ':where(body){font-family:var(--xb-font-body);line-height:var(--xb-leading-normal);color:var(--xb-text)}:where(p,li,dd,blockquote,figcaption){text-wrap:var(--xb-text-wrap)}';
		}
		// A text step's own style, on its class: only what the panel sets.
		foreach ( array_keys( self::TEXT ) as $step ) {
			$own = '';
			foreach ( array( 'leading' => array( 'line-height:', '' ), 'tracking' => array( 'letter-spacing:', 'em' ), 'measure' => array( 'max-inline-size:', 'ch' ) ) as $key => [ $property, $unit ] ) {
				$value = (float) $d[ 'text_' . $step . '_' . $key ];
				$own  .= 0.0 === $value ? '' : $property . Scale::number( $value ) . $unit . ';';
			}
			foreach ( array( 'case' => 'text-transform:', 'wrap' => 'text-wrap:' ) as $key => $property ) {
				$value = (string) $d[ 'text_' . $step . '_' . $key ];
				$own  .= 'auto' === $value ? '' : $property . $value . ';';
			}
			$css .= '' === $own ? '' : ':where(.xb-text-' . $step . '){' . $own . '}';
		}
		return $css;
	}

	/**
	 * The base colour of every family switched on, as the parameters make it: its own colour, or the one lightness of
	 * the brand or of the states with its hue and chroma.
	 *
	 * @param array<string, string|int|float> $d Parameters with the defaults.
	 * @return array<string, Color>
	 */
	public static function bases( array $d ): array {
		$out = array();
		foreach ( Design::FAMILIES as $family ) {
			if ( 0 === (int) ( $d[ $family . '_on' ] ?? 1 ) ) {
				continue;
			}
			$color = Color::parse( (string) $d[ $family ] ) ?? Color::parse( (string) Design::DEFAULTS[ $family ] );
			if ( null === $color ) {
				continue;
			}
			$group = in_array( $family, DesignData::BRAND_FAMILIES, true ) ? 'brand' : 'state';
			if ( 1 === (int) $d[ $group . '_lightness_on' ] ) {
				$color = Color::oklch( (float) $d[ $group . '_lightness' ], $color->c, $color->h );
			}
			$out[ $family ] = $color;
		}
		return $out;
	}

	/**
	 * The tokens the dark theme changes: each family's colour (its own dark colour, or its shade 400), the text on it,
	 * its transparencies and hover, and the roles of the dark theme.
	 *
	 * @param array<string, string|int|float> $design
	 * @return array<string, string>
	 */
	public static function dark( array $design ): array {
		$d     = $design + Design::DEFAULTS;
		$light = self::bases( $d );
		$bases = array();
		$out   = array();
		foreach ( $light as $family => $color ) {
			$own              = '' === (string) $d[ $family . '_dark' ] ? null : Color::parse( (string) $d[ $family . '_dark' ] );
			$bases[ $family ] = $own ?? $color->shade( 400 );
			$out[ 'xb-' . $family ]      = $bases[ $family ]->css();
			$out[ 'xb-on-' . $family ]   = $bases[ $family ]->on()->css();
			foreach ( self::ALPHAS as $alpha ) {
				$out[ 'xb-' . $family . '-a' . $alpha ] = 'color-mix(in oklch, var(--xb-' . $family . ') ' . $alpha . '%, transparent)';
			}
			$out[ 'xb-' . $family . '-hover' ] = 'var(--xb-' . $family . '-' . $d['dark_hover_shade'] . ')';
		}
		foreach ( self::roles( $d, $light, $bases, true ) as $role => $value ) {
			$out[ 'xb-' . $role ] = $value;
		}
		return $out;
	}

	/**
	 * The colour roles (bg, text, link…) as `var(--xb-…)`: the one chosen in the panel, or the automatic one. With the
	 * automatic relationships, an automatic text role that does not read on its background (4.5:1) moves along its
	 * family to the nearest shade that does.
	 *
	 * @param array<string, string|int|float> $d      Parameters with the defaults.
	 * @param array<string, Color>            $shades The families the shade tokens come from (the light bases).
	 * @param array<string, Color>            $bases  The families' colours in this theme.
	 * @return array<string, string>
	 */
	private static function roles( array $d, array $shades, array $bases, bool $dark ): array {
		$scheme = $dark ? 'dark' : 'light';
		$refs   = array();
		foreach ( DesignData::ROLES as $role ) {
			$ref            = (string) $d[ Design::role_key( $role, $dark ) ];
			$refs[ $role ] = 'auto' === $ref || null === self::ref_color( $ref, $shades, $bases ) ? DesignData::ROLE_DEFAULTS[ $scheme ][ $role ] : $ref;
		}
		if ( 1 === (int) $d['auto_contrast'] ) {
			foreach ( DesignData::AUTO_TEXT as $role => $bg ) {
				if ( 'auto' === (string) $d[ Design::role_key( $role, $dark ) ] ) {
					$behind        = array_map( static fn ( string $surface ): string => $refs[ $surface ], DesignData::AUTO_ALSO[ $role ] );
					$refs[ $role ] = self::readable( $refs[ $role ], array_merge( array( $refs[ $bg ] ), $behind ), $shades, $bases );
				}
			}
		}
		$out = array();
		foreach ( $refs as $role => $ref ) {
			$out[ $role ] = 'var(--xb-' . $ref . ')';
		}
		return $out;
	}

	/**
	 * A role's colour: white, black, a family's colour in this theme, or a shade.
	 *
	 * @param array<string, Color> $shades
	 * @param array<string, Color> $bases
	 */
	private static function ref_color( string $ref, array $shades, array $bases ): ?Color {
		if ( 'white' === $ref ) {
			return Color::oklch( 0.995, 0.0, 0.0 );
		}
		if ( 'black' === $ref ) {
			return Color::oklch( 0.15, 0.0, 0.0 );
		}
		if ( isset( $bases[ $ref ] ) ) {
			return $bases[ $ref ];
		}
		if ( 1 === preg_match( '/^([a-z]+)-(\d+)$/', $ref, $m ) && isset( $shades[ $m[1] ] ) ) {
			return $shades[ $m[1] ]->shade( (int) $m[2] );
		}
		return null;
	}

	/**
	 * The shade of a text role's family nearest to it that reads on the page's background and on the surfaces (ties:
	 * darker on a light background, lighter on a dark one); when none reads on all of them, on the page's background
	 * alone; white or black when none does. A role that already reads stays; white and black swap.
	 *
	 * @param list<string>         $bg_refs The page's background first, then the surfaces.
	 * @param array<string, Color> $shades
	 * @param array<string, Color> $bases
	 */
	private static function readable( string $ref, array $bg_refs, array $shades, array $bases ): string {
		$text = self::ref_color( $ref, $shades, $bases );
		$bg   = self::ref_color( $bg_refs[0], $shades, $bases );
		if ( null === $text || null === $bg ) {
			return $ref;
		}
		$bgs = array();
		foreach ( $bg_refs as $bg_ref ) {
			$color = self::ref_color( $bg_ref, $shades, $bases );
			if ( null !== $color ) {
				$bgs[] = $color;
			}
		}
		$white = Color::contrast( Color::oklch( 0.995, 0.0, 0.0 ), $bg ) >= Color::contrast( Color::oklch( 0.15, 0.0, 0.0 ), $bg ) ? 'white' : 'black';
		$shade = 1 === preg_match( '/^([a-z]+)-(\d+)$/', $ref, $m );
		$steps = array_keys( Color::SHADES );
		foreach ( array( $bgs, array( $bg ) ) as $targets ) {
			$reads = static function ( Color $color ) use ( $targets ): bool {
				foreach ( $targets as $target ) {
					if ( Color::contrast( $color, $target ) < 4.5 ) {
						return false;
					}
				}
				return true;
			};
			if ( $reads( $text ) ) {
				return $ref;
			}
			if ( ! $shade ) {
				continue;
			}
			$at     = (int) array_search( (int) $m[2], $steps, true );
			$darker = $bg->luminance() > 0.18;
			for ( $far = 1; $far < count( $steps ); $far++ ) {
				foreach ( $darker ? array( $at + $far, $at - $far ) : array( $at - $far, $at + $far ) as $i ) {
					if ( isset( $steps[ $i ] ) && $reads( $shades[ $m[1] ]->shade( (int) $steps[ $i ] ) ) ) {
						return $m[1] . '-' . $steps[ $i ];
					}
				}
			}
		}
		// White or black: the one that reads more; a family's own colour stays.
		return $shade || in_array( $ref, array( 'white', 'black' ), true ) ? $white : $ref;
	}

	/**
	 * Contrast of text/background pairs whose colours resolve.
	 *
	 * @param list<array{0: string, 1: string}> $pairs
	 * @param callable(string): ?Color          $resolve
	 * @return list<array{text: string, bg: string, ratio: float, ok: bool}>
	 */
	private static function pairs( array $pairs, callable $resolve ): array {
		$out = array();
		foreach ( $pairs as [ $text, $bg ] ) {
			$a = $resolve( $text );
			$b = $resolve( $bg );
			if ( null === $a || null === $b ) {
				continue;
			}
			$ratio = round( Color::contrast( $a, $b ), 2 );
			$out[] = array(
				'text'  => $text,
				'bg'    => $bg,
				'ratio' => $ratio,
				'ok'    => $ratio >= 4.5,
			);
		}
		return $out;
	}

	/** @param array<string, string> $tokens */
	private static function declarations( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $name => $value ) {
			$out .= '--' . $name . ':' . $value . ';';
		}
		return $out;
	}

	/** The stylesheet with the defaults (assets/tokens/xb-tokens.css), as bin/build-histos-css.php writes it. */
	public static function stylesheet(): string {
		$lines = '';
		foreach ( self::compute( Design::DEFAULTS ) as $name => $value ) {
			$lines .= "\t--" . $name . ': ' . $value . ";\n";
		}
		return "/*\n * Histeon CSS tokens — the --xb-* values of the default design (GPL-3.0-or-later).\n * Generated by plugin/bin/build-histos-css.php from src/Framework/HistosCss: do not edit by hand.\n */\n:root {\n" . $lines . "}\n";
	}
}
