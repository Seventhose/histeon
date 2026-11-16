<?php
/**
 * Generated from packages/cms/assets/histos-css/design-data.json. Do not edit by hand.
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework\HistosCss;

final class DesignData {
	public const SCHEMA_VERSION = 23;

	public const ROOT_PX = 16;

	public const FAMILIES = array(
		'primary',
		'secondary',
		'tertiary',
		'accent',
		'neutral',
		'success',
		'warning',
		'danger',
		'info'
	);

	public const BRAND_FAMILIES = array(
		'primary',
		'secondary',
		'tertiary',
		'accent',
		'neutral'
	);

	public const STATE_FAMILIES = array(
		'success',
		'warning',
		'danger',
		'info'
	);

	public const DEFAULTS = array(
		'primary' => 'oklch(55% 0.2 265)',
		'secondary' => 'oklch(70% 0.15 25)',
		'tertiary' => 'oklch(52% 0.17 320)',
		'accent' => 'oklch(72% 0.16 165)',
		'neutral' => 'oklch(55% 0.02 265)',
		'success' => 'oklch(62% 0.17 150)',
		'warning' => 'oklch(78% 0.16 75)',
		'danger' => 'oklch(58% 0.21 27)',
		'info' => 'oklch(62% 0.13 230)',
		'text_min' => 16,
		'text_max' => 18,
		'text_ratio_min' => 1.2,
		'text_ratio_max' => 1.25,
		'space_min' => 16,
		'space_max' => 24,
		'space_ratio_min' => 1.5,
		'space_ratio_max' => 1.6,
		'viewport_min' => 360,
		'viewport_max' => 1440,
		'radius' => 8,
		'width_content' => 72,
		'width_wide' => 90,
		'width_prose' => 65,
		'font_body' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
		'font_heading' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
		'font_mono' => 'ui-monospace, "SF Mono", Menlo, Consolas, monospace',
		'secondary_on' => 1,
		'tertiary_on' => 0,
		'accent_on' => 1,
		'success_on' => 1,
		'warning_on' => 1,
		'danger_on' => 1,
		'info_on' => 1,
		'brand_lightness_on' => 0,
		'brand_lightness' => 0.62,
		'state_lightness_on' => 0,
		'state_lightness' => 0.62,
		'hover_shade' => '600',
		'auto_contrast' => 1,
		'dark_on' => 0,
		'scheme' => 'light',
		'primary_dark' => '',
		'secondary_dark' => '',
		'tertiary_dark' => '',
		'accent_dark' => '',
		'neutral_dark' => '',
		'success_dark' => '',
		'warning_dark' => '',
		'danger_dark' => '',
		'info_dark' => '',
		'role_bg' => 'auto',
		'role_bg_dark' => 'auto',
		'role_surface' => 'auto',
		'role_surface_dark' => 'auto',
		'role_surface_2' => 'auto',
		'role_surface_2_dark' => 'auto',
		'role_text' => 'auto',
		'role_text_dark' => 'auto',
		'role_text_muted' => 'auto',
		'role_text_muted_dark' => 'auto',
		'role_heading' => 'auto',
		'role_heading_dark' => 'auto',
		'role_border' => 'auto',
		'role_border_dark' => 'auto',
		'role_link' => 'auto',
		'role_link_dark' => 'auto',
		'role_link_hover' => 'auto',
		'role_link_hover_dark' => 'auto',
		'role_focus' => 'auto',
		'role_focus_dark' => 'auto',
		'root_size' => 100,
		'heading_scale_on' => 1,
		'heading_min' => 18,
		'heading_max' => 20,
		'heading_ratio_min' => 1.2,
		'heading_ratio_max' => 1.333,
		'headings_on' => 0,
		'heading_font' => 'heading',
		'heading_weight' => 700,
		'heading_leading' => 1.15,
		'heading_tracking' => 0,
		'heading_case' => 'none',
		'heading_wrap' => 'balance',
		'heading_measure' => 0,
		'h1_weight' => 0,
		'h1_leading' => 0,
		'h2_weight' => 0,
		'h2_leading' => 0,
		'h3_weight' => 0,
		'h3_leading' => 0,
		'h4_weight' => 0,
		'h4_leading' => 0,
		'h5_weight' => 0,
		'h5_leading' => 0,
		'h6_weight' => 0,
		'h6_leading' => 0,
		'text_on' => 0,
		'text_leading' => 1.6,
		'text_wrap' => 'pretty',
		'font_accent' => '',
		'font_display' => '',
		'section_multiplier_min' => 1,
		'section_multiplier_max' => 1,
		'section_padding' => 'm',
		'gutter_min' => 0,
		'gutter_max' => 0,
		'container_gap' => 'l',
		'content_gap' => 'm',
		'grid_gap' => 'm',
		'grid_min' => 14,
		'grid_fill' => 'auto-fit',
		'width_min' => 320,
		'header_min' => 64,
		'header_max' => 80,
		'scroll_offset_on' => 1,
		'border_width' => 1,
		'border_style' => 'solid',
		'btn_pad_y' => 0.75,
		'btn_pad_x' => 1.4,
		'btn_radius' => 'm',
		'btn_weight' => 600,
		'btn_leading' => 1.2,
		'btn_size' => 'inherit',
		'btn_case' => 'none',
		'btn_tracking' => 0,
		'btn_border' => 0,
		'btn_min' => 0,
		'btn_full_mobile' => 0,
		'btn_full_below' => 480,
		'link_underline' => 'always',
		'link_thickness' => 0,
		'link_offset' => 0.2,
		'links_on' => 0,
		'field_pad_y' => 0.65,
		'field_pad_x' => 0.9,
		'field_radius' => 'm',
		'field_bg' => 'bg',
		'field_border' => 0,
		'field_focus_ring' => 3,
		'label_weight' => 600,
		'forms_on' => 0,
		'focus_on' => 1,
		'focus_width' => 2,
		'focus_offset' => 2,
		'smooth_scroll' => 1,
		'reduce_motion' => 1,
		'selection_on' => 0,
		'selection_shade' => '200',
		'quotes_on' => 0,
		'columns_min' => 20,
		'columns_gap' => 'l',
		'columns_rule' => 0,
		'enter_range' => 30,
		'enter_distance' => 'l',
		'enter_ease' => 'ease-out',
		'hover_duration' => 150,
		'hover_lift' => 4,
		'hover_scale' => 1.04,
		'card_pad' => 'l',
		'card_gap' => 'm',
		'card_radius' => 'l',
		'card_bg' => 'surface',
		'card_shadow' => 's',
		'card_border' => 1,
		'card_ratio' => '16/9',
		'icon_color' => 'currentColor',
		'icon_stroke' => 2,
		'icon_box_shade' => '100',
		'list_icon_color' => 'primary',
		'list_icon_gap' => 'xs',
		'print_alphas' => 1,
		'print_shadows' => 1,
		'print_buttons' => 1,
		'print_forms' => 1,
		'print_cards' => 1,
		'print_icons' => 1,
		'print_motion' => 1,
		'block_palette' => 1,
		'field_size' => 'inherit',
		'field_placeholder' => 'text-muted',
		'label_size' => 'inherit',
		'help_size' => 'xs',
		'help_color' => 'text-muted',
		'field_invalid' => 'danger',
		'field_valid' => 'success',
		'check_style' => 'normal',
		'check_size' => 1.1,
		'field_gap' => 'm',
		'size_text_xs_min' => 0,
		'size_text_xs_max' => 0,
		'size_text_s_min' => 0,
		'size_text_s_max' => 0,
		'size_text_m_min' => 0,
		'size_text_m_max' => 0,
		'size_text_l_min' => 0,
		'size_text_l_max' => 0,
		'size_text_xl_min' => 0,
		'size_text_xl_max' => 0,
		'size_text_2xl_min' => 0,
		'size_text_2xl_max' => 0,
		'size_text_3xl_min' => 0,
		'size_text_3xl_max' => 0,
		'size_text_4xl_min' => 0,
		'size_text_4xl_max' => 0,
		'size_h1_min' => 0,
		'size_h1_max' => 0,
		'h1_color' => 'auto',
		'h1_font' => 'auto',
		'h1_case' => 'auto',
		'size_h2_min' => 0,
		'size_h2_max' => 0,
		'h2_color' => 'auto',
		'h2_font' => 'auto',
		'h2_case' => 'auto',
		'size_h3_min' => 0,
		'size_h3_max' => 0,
		'h3_color' => 'auto',
		'h3_font' => 'auto',
		'h3_case' => 'auto',
		'size_h4_min' => 0,
		'size_h4_max' => 0,
		'h4_color' => 'auto',
		'h4_font' => 'auto',
		'h4_case' => 'auto',
		'size_h5_min' => 0,
		'size_h5_max' => 0,
		'h5_color' => 'auto',
		'h5_font' => 'auto',
		'h5_case' => 'auto',
		'size_h6_min' => 0,
		'size_h6_max' => 0,
		'h6_color' => 'auto',
		'h6_font' => 'auto',
		'h6_case' => 'auto',
		'overlay_color' => 'black',
		'overlay_opacity' => 0.5,
		'overlay_blend' => 'normal',
		'role_named_1' => 'auto',
		'surface_1_border' => 0,
		'surface_1_shadow' => 'none',
		'role_named_2' => 'auto',
		'surface_2_border' => 0,
		'surface_2_shadow' => 'none',
		'role_named_3' => 'auto',
		'surface_3_border' => 0,
		'surface_3_shadow' => 'none',
		'flow_text' => 1,
		'flow_heading' => 1.6,
		'flow_after' => 0,
		'flow_list' => 0,
		'boxed_on' => 0,
		'boxed_width' => 90,
		'boxed_bg' => 'neutral-100',
		'boxed_shadow' => 'm',
		'radius_ratio' => 0,
		'divider_width' => 1,
		'divider_style' => 'solid',
		'divider_space' => 'm',
		'grid_max' => 0,
		'classes_colors' => 1,
		'classes_buttons' => 1,
		'classes_motion' => 1,
		'classes_icons' => 1,
		'classes_cards' => 1,
		'classes_forms' => 1,
		'dark_hover_shade' => '300',
		'form_submit' => 'primary',
		'tokens_inline' => 0,
		'text_xs_leading' => 0,
		'text_xs_tracking' => 0,
		'text_xs_measure' => 0,
		'text_xs_case' => 'auto',
		'text_xs_wrap' => 'auto',
		'text_s_leading' => 0,
		'text_s_tracking' => 0,
		'text_s_measure' => 0,
		'text_s_case' => 'auto',
		'text_s_wrap' => 'auto',
		'text_m_leading' => 0,
		'text_m_tracking' => 0,
		'text_m_measure' => 0,
		'text_m_case' => 'auto',
		'text_m_wrap' => 'auto',
		'text_l_leading' => 0,
		'text_l_tracking' => 0,
		'text_l_measure' => 0,
		'text_l_case' => 'auto',
		'text_l_wrap' => 'auto',
		'text_xl_leading' => 0,
		'text_xl_tracking' => 0,
		'text_xl_measure' => 0,
		'text_xl_case' => 'auto',
		'text_xl_wrap' => 'auto',
		'text_2xl_leading' => 0,
		'text_2xl_tracking' => 0,
		'text_2xl_measure' => 0,
		'text_2xl_case' => 'auto',
		'text_2xl_wrap' => 'auto',
		'text_3xl_leading' => 0,
		'text_3xl_tracking' => 0,
		'text_3xl_measure' => 0,
		'text_3xl_case' => 'auto',
		'text_3xl_wrap' => 'auto',
		'text_4xl_leading' => 0,
		'text_4xl_tracking' => 0,
		'text_4xl_measure' => 0,
		'text_4xl_case' => 'auto',
		'text_4xl_wrap' => 'auto',
		'step_height' => 8,
		'step_color' => 'primary',
		'step_track' => 'neutral-200',
		'shadow_s_y' => 1,
		'shadow_s_blur' => 2,
		'shadow_s_opacity' => 0.08,
		'shadow_m_y' => 4,
		'shadow_m_blur' => 12,
		'shadow_m_opacity' => 0.1,
		'shadow_l_y' => 12,
		'shadow_l_blur' => 32,
		'shadow_l_opacity' => 0.14,
		'shadow_xl_y' => 24,
		'shadow_xl_blur' => 56,
		'shadow_xl_opacity' => 0.18,
		'shadow_2xl_y' => 40,
		'shadow_2xl_blur' => 80,
		'shadow_2xl_opacity' => 0.24,
		'tshadow_s_y' => 1,
		'tshadow_s_blur' => 2,
		'tshadow_s_opacity' => 0.25,
		'tshadow_m_y' => 2,
		'tshadow_m_blur' => 6,
		'tshadow_m_opacity' => 0.3,
		'tshadow_l_y' => 4,
		'tshadow_l_blur' => 14,
		'tshadow_l_opacity' => 0.4,
		'btn_primary_on' => 1,
		'btn_secondary_on' => 1,
		'btn_tertiary_on' => 1,
		'btn_accent_on' => 1,
		'btn_neutral_on' => 1,
		'btn_success_on' => 1,
		'btn_warning_on' => 1,
		'btn_danger_on' => 1,
		'btn_info_on' => 1,
		'btn_outline_on' => 1,
		'btn_light_on' => 1,
		'btn_dark_on' => 1,
		'list_icon_symbol' => 'check',
		'external_on' => 0,
		'external_host' => '',
		'external_label' => '(sito esterno)',
		'external_symbol' => 'arrow',
		'external_place' => 'after',
		'flow_scope' => 'prose',
		'section_grid_on' => 0,
		'role_named_1_to' => 'auto',
		'surface_1_angle' => 180,
		'role_named_2_to' => 'auto',
		'surface_2_angle' => 180,
		'role_named_3_to' => 'auto',
		'surface_3_angle' => 180,
		'btn_light_hover' => '200',
		'btn_dark_hover' => '900',
		'surface_1_name' => '',
		'surface_2_name' => '',
		'surface_3_name' => '',
		'enter_scale' => 0.85,
		'enter_blur' => 8,
		'exit_range' => 100,
		'hover_bright' => 1.08,
		'hover_fade' => 0.8,
		'transition_ms' => 150,
		'heading_italic' => 0,
		'focus_style' => 'solid',
		'quote_width' => 4,
		'quote_color' => 'primary-300',
		'quote_pad' => 'm',
		'quote_italic' => 0,
		'selection_text' => 'text',
		'divider_color' => 'border',
		'border_color' => 'border',
		'label_color' => 'heading',
		'legend_size' => 'inherit',
		'field_focus_color' => 'focus',
		'icon_box_pad' => 'xs',
		'icon_box_radius' => 'full',
		'h1_tracking' => 0,
		'h1_measure' => 0,
		'h2_tracking' => 0,
		'h2_measure' => 0,
		'h3_tracking' => 0,
		'h3_measure' => 0,
		'h4_tracking' => 0,
		'h4_measure' => 0,
		'h5_tracking' => 0,
		'h5_measure' => 0,
		'h6_tracking' => 0,
		'h6_measure' => 0,
		'icon_xs' => 0.75,
		'icon_s' => 1,
		'icon_m' => 1.25,
		'icon_l' => 1.5,
		'icon_xl' => 2,
		'icon_2xl' => 3,
		'h1_wrap' => 'auto',
		'h2_wrap' => 'auto',
		'h3_wrap' => 'auto',
		'h4_wrap' => 'auto',
		'h5_wrap' => 'auto',
		'h6_wrap' => 'auto',
		'card_title' => 'h4',
		'overlay_2_color' => 'black',
		'overlay_2_opacity' => 0.5,
		'overlay_2_blend' => 'normal',
		'overlay_3_color' => 'black',
		'overlay_3_opacity' => 0.5,
		'overlay_3_blend' => 'normal'
	);

	public const LIMITS = array(
		'text_min' => array(
			10,
			32
		),
		'text_max' => array(
			10,
			40
		),
		'text_ratio_min' => array(
			1,
			2
		),
		'text_ratio_max' => array(
			1,
			2
		),
		'space_min' => array(
			4,
			64
		),
		'space_max' => array(
			4,
			96
		),
		'space_ratio_min' => array(
			1,
			2.5
		),
		'space_ratio_max' => array(
			1,
			2.5
		),
		'viewport_min' => array(
			240,
			1200
		),
		'viewport_max' => array(
			600,
			3840
		),
		'radius' => array(
			0,
			64
		),
		'width_content' => array(
			30,
			160
		),
		'width_wide' => array(
			30,
			200
		),
		'width_prose' => array(
			30,
			120
		),
		'brand_lightness' => array(
			0.3,
			0.85
		),
		'state_lightness' => array(
			0.3,
			0.85
		),
		'root_size' => array(
			62.5,
			125
		),
		'heading_min' => array(
			10,
			48
		),
		'heading_max' => array(
			10,
			64
		),
		'heading_ratio_min' => array(
			1,
			2
		),
		'heading_ratio_max' => array(
			1,
			2
		),
		'heading_weight' => array(
			100,
			900
		),
		'heading_leading' => array(
			0.8,
			2.5
		),
		'heading_tracking' => array(
			-0.1,
			0.3
		),
		'heading_measure' => array(
			0,
			120
		),
		'text_leading' => array(
			1,
			2.5
		),
		'h1_weight' => array(
			0,
			900
		),
		'h1_leading' => array(
			0,
			2.5
		),
		'h2_weight' => array(
			0,
			900
		),
		'h2_leading' => array(
			0,
			2.5
		),
		'h3_weight' => array(
			0,
			900
		),
		'h3_leading' => array(
			0,
			2.5
		),
		'h4_weight' => array(
			0,
			900
		),
		'h4_leading' => array(
			0,
			2.5
		),
		'h5_weight' => array(
			0,
			900
		),
		'h5_leading' => array(
			0,
			2.5
		),
		'h6_weight' => array(
			0,
			900
		),
		'h6_leading' => array(
			0,
			2.5
		),
		'section_multiplier_min' => array(
			0.5,
			4
		),
		'section_multiplier_max' => array(
			0.5,
			4
		),
		'gutter_min' => array(
			0,
			128
		),
		'gutter_max' => array(
			0,
			128
		),
		'grid_min' => array(
			6,
			40
		),
		'width_min' => array(
			240,
			480
		),
		'header_min' => array(
			32,
			200
		),
		'header_max' => array(
			32,
			200
		),
		'border_width' => array(
			0,
			8
		),
		'btn_pad_y' => array(
			0.2,
			2
		),
		'btn_pad_x' => array(
			0.4,
			4
		),
		'btn_weight' => array(
			100,
			900
		),
		'btn_leading' => array(
			0.8,
			2
		),
		'btn_tracking' => array(
			-0.05,
			0.3
		),
		'btn_border' => array(
			0,
			8
		),
		'btn_min' => array(
			0,
			20
		),
		'btn_full_below' => array(
			320,
			960
		),
		'link_thickness' => array(
			0,
			4
		),
		'link_offset' => array(
			0,
			0.5
		),
		'field_pad_y' => array(
			0.2,
			2
		),
		'field_pad_x' => array(
			0.2,
			3
		),
		'field_border' => array(
			0,
			8
		),
		'field_focus_ring' => array(
			0,
			8
		),
		'label_weight' => array(
			100,
			900
		),
		'focus_width' => array(
			1,
			6
		),
		'focus_offset' => array(
			0,
			8
		),
		'columns_min' => array(
			8,
			40
		),
		'enter_range' => array(
			10,
			100
		),
		'hover_duration' => array(
			50,
			1000
		),
		'hover_lift' => array(
			1,
			16
		),
		'hover_scale' => array(
			1,
			1.2
		),
		'icon_stroke' => array(
			1,
			3
		),
		'check_size' => array(
			0.8,
			2
		),
		'size_text_xs_min' => array(
			0,
			200
		),
		'size_text_xs_max' => array(
			0,
			200
		),
		'size_text_s_min' => array(
			0,
			200
		),
		'size_text_s_max' => array(
			0,
			200
		),
		'size_text_m_min' => array(
			0,
			200
		),
		'size_text_m_max' => array(
			0,
			200
		),
		'size_text_l_min' => array(
			0,
			200
		),
		'size_text_l_max' => array(
			0,
			200
		),
		'size_text_xl_min' => array(
			0,
			200
		),
		'size_text_xl_max' => array(
			0,
			200
		),
		'size_text_2xl_min' => array(
			0,
			200
		),
		'size_text_2xl_max' => array(
			0,
			200
		),
		'size_text_3xl_min' => array(
			0,
			200
		),
		'size_text_3xl_max' => array(
			0,
			200
		),
		'size_text_4xl_min' => array(
			0,
			200
		),
		'size_text_4xl_max' => array(
			0,
			200
		),
		'size_h1_min' => array(
			0,
			200
		),
		'size_h1_max' => array(
			0,
			200
		),
		'size_h2_min' => array(
			0,
			200
		),
		'size_h2_max' => array(
			0,
			200
		),
		'size_h3_min' => array(
			0,
			200
		),
		'size_h3_max' => array(
			0,
			200
		),
		'size_h4_min' => array(
			0,
			200
		),
		'size_h4_max' => array(
			0,
			200
		),
		'size_h5_min' => array(
			0,
			200
		),
		'size_h5_max' => array(
			0,
			200
		),
		'size_h6_min' => array(
			0,
			200
		),
		'size_h6_max' => array(
			0,
			200
		),
		'overlay_opacity' => array(
			0,
			1
		),
		'flow_text' => array(
			0,
			3
		),
		'flow_heading' => array(
			0,
			4
		),
		'flow_after' => array(
			0,
			3
		),
		'flow_list' => array(
			0,
			1.5
		),
		'boxed_width' => array(
			40,
			200
		),
		'radius_ratio' => array(
			0,
			3
		),
		'divider_width' => array(
			0,
			8
		),
		'grid_max' => array(
			0,
			12
		),
		'text_xs_leading' => array(
			0,
			3
		),
		'text_xs_tracking' => array(
			-0.1,
			0.3
		),
		'text_xs_measure' => array(
			0,
			120
		),
		'text_s_leading' => array(
			0,
			3
		),
		'text_s_tracking' => array(
			-0.1,
			0.3
		),
		'text_s_measure' => array(
			0,
			120
		),
		'text_m_leading' => array(
			0,
			3
		),
		'text_m_tracking' => array(
			-0.1,
			0.3
		),
		'text_m_measure' => array(
			0,
			120
		),
		'text_l_leading' => array(
			0,
			3
		),
		'text_l_tracking' => array(
			-0.1,
			0.3
		),
		'text_l_measure' => array(
			0,
			120
		),
		'text_xl_leading' => array(
			0,
			3
		),
		'text_xl_tracking' => array(
			-0.1,
			0.3
		),
		'text_xl_measure' => array(
			0,
			120
		),
		'text_2xl_leading' => array(
			0,
			3
		),
		'text_2xl_tracking' => array(
			-0.1,
			0.3
		),
		'text_2xl_measure' => array(
			0,
			120
		),
		'text_3xl_leading' => array(
			0,
			3
		),
		'text_3xl_tracking' => array(
			-0.1,
			0.3
		),
		'text_3xl_measure' => array(
			0,
			120
		),
		'text_4xl_leading' => array(
			0,
			3
		),
		'text_4xl_tracking' => array(
			-0.1,
			0.3
		),
		'text_4xl_measure' => array(
			0,
			120
		),
		'step_height' => array(
			2,
			24
		),
		'shadow_s_y' => array(
			0,
			100
		),
		'shadow_s_blur' => array(
			0,
			200
		),
		'shadow_s_opacity' => array(
			0,
			1
		),
		'shadow_m_y' => array(
			0,
			100
		),
		'shadow_m_blur' => array(
			0,
			200
		),
		'shadow_m_opacity' => array(
			0,
			1
		),
		'shadow_l_y' => array(
			0,
			100
		),
		'shadow_l_blur' => array(
			0,
			200
		),
		'shadow_l_opacity' => array(
			0,
			1
		),
		'shadow_xl_y' => array(
			0,
			100
		),
		'shadow_xl_blur' => array(
			0,
			200
		),
		'shadow_xl_opacity' => array(
			0,
			1
		),
		'shadow_2xl_y' => array(
			0,
			100
		),
		'shadow_2xl_blur' => array(
			0,
			200
		),
		'shadow_2xl_opacity' => array(
			0,
			1
		),
		'tshadow_s_y' => array(
			0,
			100
		),
		'tshadow_s_blur' => array(
			0,
			200
		),
		'tshadow_s_opacity' => array(
			0,
			1
		),
		'tshadow_m_y' => array(
			0,
			100
		),
		'tshadow_m_blur' => array(
			0,
			200
		),
		'tshadow_m_opacity' => array(
			0,
			1
		),
		'tshadow_l_y' => array(
			0,
			100
		),
		'tshadow_l_blur' => array(
			0,
			200
		),
		'tshadow_l_opacity' => array(
			0,
			1
		),
		'surface_1_angle' => array(
			0,
			359
		),
		'surface_2_angle' => array(
			0,
			359
		),
		'surface_3_angle' => array(
			0,
			359
		),
		'enter_scale' => array(
			0.5,
			1
		),
		'enter_blur' => array(
			0,
			40
		),
		'exit_range' => array(
			10,
			100
		),
		'hover_bright' => array(
			1,
			1.5
		),
		'hover_fade' => array(
			0.2,
			1
		),
		'transition_ms' => array(
			0,
			1000
		),
		'quote_width' => array(
			0,
			16
		),
		'h1_tracking' => array(
			-0.1,
			0.3
		),
		'h1_measure' => array(
			0,
			120
		),
		'h2_tracking' => array(
			-0.1,
			0.3
		),
		'h2_measure' => array(
			0,
			120
		),
		'h3_tracking' => array(
			-0.1,
			0.3
		),
		'h3_measure' => array(
			0,
			120
		),
		'h4_tracking' => array(
			-0.1,
			0.3
		),
		'h4_measure' => array(
			0,
			120
		),
		'h5_tracking' => array(
			-0.1,
			0.3
		),
		'h5_measure' => array(
			0,
			120
		),
		'h6_tracking' => array(
			-0.1,
			0.3
		),
		'h6_measure' => array(
			0,
			120
		),
		'icon_xs' => array(
			0.25,
			8
		),
		'icon_s' => array(
			0.25,
			8
		),
		'icon_m' => array(
			0.25,
			8
		),
		'icon_l' => array(
			0.25,
			8
		),
		'icon_xl' => array(
			0.25,
			8
		),
		'icon_2xl' => array(
			0.25,
			8
		),
		'overlay_2_opacity' => array(
			0,
			1
		),
		'overlay_3_opacity' => array(
			0,
			1
		)
	);

	public const FLAGS = array(
		'secondary_on',
		'tertiary_on',
		'accent_on',
		'success_on',
		'warning_on',
		'danger_on',
		'info_on',
		'brand_lightness_on',
		'state_lightness_on',
		'auto_contrast',
		'dark_on',
		'heading_scale_on',
		'headings_on',
		'text_on',
		'scroll_offset_on',
		'btn_full_mobile',
		'links_on',
		'forms_on',
		'focus_on',
		'smooth_scroll',
		'reduce_motion',
		'selection_on',
		'quotes_on',
		'columns_rule',
		'card_border',
		'print_alphas',
		'print_shadows',
		'print_buttons',
		'print_forms',
		'print_cards',
		'print_icons',
		'print_motion',
		'block_palette',
		'surface_1_border',
		'surface_2_border',
		'surface_3_border',
		'boxed_on',
		'classes_colors',
		'classes_buttons',
		'classes_motion',
		'classes_icons',
		'classes_cards',
		'classes_forms',
		'tokens_inline',
		'btn_primary_on',
		'btn_secondary_on',
		'btn_tertiary_on',
		'btn_accent_on',
		'btn_neutral_on',
		'btn_success_on',
		'btn_warning_on',
		'btn_danger_on',
		'btn_info_on',
		'btn_outline_on',
		'btn_light_on',
		'btn_dark_on',
		'external_on',
		'section_grid_on',
		'heading_italic',
		'quote_italic'
	);

	public const CHOICES = array(
		'hover_shade' => array(
			'400',
			'500',
			'600',
			'700',
			'800'
		),
		'scheme' => array(
			'light',
			'dark',
			'system'
		),
		'heading_font' => array(
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'heading_case' => array(
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'heading_wrap' => array(
			'wrap',
			'balance',
			'pretty'
		),
		'text_wrap' => array(
			'wrap',
			'balance',
			'pretty'
		),
		'section_padding' => array(
			's',
			'm',
			'l',
			'xl'
		),
		'container_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'content_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'grid_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'grid_fill' => array(
			'auto-fit',
			'auto-fill'
		),
		'border_style' => array(
			'solid',
			'dashed',
			'dotted',
			'double'
		),
		'btn_radius' => array(
			'none',
			's',
			'm',
			'l',
			'xl',
			'full'
		),
		'btn_size' => array(
			'inherit',
			'xs',
			's',
			'm',
			'l',
			'xl'
		),
		'btn_case' => array(
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'link_underline' => array(
			'always',
			'hover',
			'never'
		),
		'field_radius' => array(
			'none',
			's',
			'm',
			'l',
			'full'
		),
		'field_bg' => array(
			'bg',
			'surface',
			'surface-2',
			'white'
		),
		'selection_shade' => array(
			'100',
			'200',
			'300',
			'400'
		),
		'columns_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'enter_distance' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'enter_ease' => array(
			'ease-out',
			'ease-in-out',
			'ease',
			'linear'
		),
		'card_pad' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'card_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'card_radius' => array(
			's',
			'm',
			'l',
			'xl'
		),
		'card_bg' => array(
			'bg',
			'surface',
			'surface-2',
			'white'
		),
		'card_shadow' => array(
			'none',
			's',
			'm',
			'l',
			'xl'
		),
		'card_ratio' => array(
			'16/9',
			'4/3',
			'3/2',
			'1/1',
			'3/4'
		),
		'icon_color' => array(
			'currentColor',
			'primary',
			'secondary',
			'accent',
			'text',
			'text-muted'
		),
		'icon_box_shade' => array(
			'50',
			'100',
			'200'
		),
		'list_icon_color' => array(
			'primary',
			'secondary',
			'accent',
			'success',
			'text'
		),
		'list_icon_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'field_size' => array(
			'inherit',
			'xs',
			's',
			'm',
			'l'
		),
		'field_placeholder' => array(
			'text-muted',
			'text',
			'neutral-400',
			'neutral-500',
			'neutral-600'
		),
		'label_size' => array(
			'inherit',
			'xs',
			's',
			'm',
			'l'
		),
		'help_size' => array(
			'xs',
			's',
			'm'
		),
		'help_color' => array(
			'text-muted',
			'text',
			'neutral-500'
		),
		'field_invalid' => array(
			'danger',
			'warning',
			'primary'
		),
		'field_valid' => array(
			'success',
			'info',
			'primary'
		),
		'check_style' => array(
			'normal',
			'switch'
		),
		'field_gap' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'h1_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h1_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h1_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'h2_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h2_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h2_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'h3_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h3_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h3_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'h4_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h4_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h4_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'h5_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h5_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h5_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'h6_color' => array(
			'auto',
			'heading',
			'text',
			'text-muted',
			'primary',
			'secondary',
			'accent'
		),
		'h6_font' => array(
			'auto',
			'body',
			'heading',
			'mono',
			'accent',
			'display'
		),
		'h6_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'overlay_color' => array(
			'black',
			'white',
			'primary',
			'secondary',
			'accent',
			'neutral'
		),
		'overlay_blend' => array(
			'normal',
			'multiply',
			'screen',
			'overlay',
			'darken',
			'lighten'
		),
		'surface_1_shadow' => array(
			'none',
			's',
			'm',
			'l'
		),
		'surface_2_shadow' => array(
			'none',
			's',
			'm',
			'l'
		),
		'surface_3_shadow' => array(
			'none',
			's',
			'm',
			'l'
		),
		'boxed_bg' => array(
			'neutral-100',
			'neutral-200',
			'surface',
			'surface-2',
			'neutral-900'
		),
		'boxed_shadow' => array(
			'none',
			's',
			'm',
			'l',
			'xl'
		),
		'divider_style' => array(
			'solid',
			'dashed',
			'dotted',
			'double'
		),
		'divider_space' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'dark_hover_shade' => array(
			'200',
			'300',
			'400',
			'500'
		),
		'form_submit' => array(
			'primary',
			'secondary',
			'tertiary',
			'accent',
			'neutral',
			'success'
		),
		'text_xs_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_xs_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_s_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_s_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_m_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_m_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_l_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_l_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_xl_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_xl_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_2xl_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_2xl_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_3xl_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_3xl_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'text_4xl_case' => array(
			'auto',
			'none',
			'uppercase',
			'lowercase',
			'capitalize'
		),
		'text_4xl_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'step_color' => array(
			'primary',
			'secondary',
			'tertiary',
			'accent',
			'neutral',
			'success'
		),
		'step_track' => array(
			'neutral-100',
			'neutral-200',
			'neutral-300',
			'surface',
			'surface-2'
		),
		'list_icon_symbol' => array(
			'check',
			'arrow',
			'dot',
			'star',
			'plus',
			'dash'
		),
		'external_symbol' => array(
			'arrow',
			'none'
		),
		'external_place' => array(
			'after',
			'before'
		),
		'flow_scope' => array(
			'prose',
			'content'
		),
		'btn_light_hover' => array(
			'100',
			'200',
			'300'
		),
		'btn_dark_hover' => array(
			'700',
			'900',
			'950'
		),
		'focus_style' => array(
			'solid',
			'dashed',
			'dotted',
			'double'
		),
		'quote_color' => array(
			'primary-300',
			'neutral-300',
			'accent-300',
			'border',
			'primary'
		),
		'quote_pad' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'selection_text' => array(
			'text',
			'white',
			'black',
			'on-primary'
		),
		'divider_color' => array(
			'border',
			'text-muted',
			'primary',
			'neutral-300'
		),
		'border_color' => array(
			'border',
			'text-muted',
			'primary',
			'neutral-300'
		),
		'label_color' => array(
			'heading',
			'text',
			'text-muted'
		),
		'legend_size' => array(
			'inherit',
			'xs',
			's',
			'm',
			'l'
		),
		'field_focus_color' => array(
			'focus',
			'primary',
			'accent'
		),
		'icon_box_pad' => array(
			'3xs',
			'2xs',
			'xs',
			's',
			'm',
			'l',
			'xl',
			'2xl',
			'3xl',
			'4xl'
		),
		'icon_box_radius' => array(
			'none',
			's',
			'm',
			'l',
			'full'
		),
		'h1_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'h2_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'h3_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'h4_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'h5_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'h6_wrap' => array(
			'auto',
			'wrap',
			'balance',
			'pretty'
		),
		'card_title' => array(
			'h2',
			'h3',
			'h4',
			'h5',
			'h6'
		),
		'overlay_2_color' => array(
			'black',
			'white',
			'primary',
			'secondary',
			'accent',
			'neutral'
		),
		'overlay_2_blend' => array(
			'normal',
			'multiply',
			'screen',
			'overlay',
			'darken',
			'lighten'
		),
		'overlay_3_color' => array(
			'black',
			'white',
			'primary',
			'secondary',
			'accent',
			'neutral'
		),
		'overlay_3_blend' => array(
			'normal',
			'multiply',
			'screen',
			'overlay',
			'darken',
			'lighten'
		)
	);

	public const OPTIONAL_FONTS = array(
		'font_accent',
		'font_display'
	);

	public const ROLES = array(
		'bg',
		'surface',
		'surface-2',
		'text',
		'text-muted',
		'heading',
		'border',
		'link',
		'link-hover',
		'focus'
	);

	public const ROLE_DEFAULTS = array(
		'light' => array(
			'bg' => 'white',
			'surface' => 'neutral-50',
			'surface-2' => 'neutral-100',
			'text' => 'neutral-900',
			'text-muted' => 'neutral-700',
			'heading' => 'neutral-950',
			'border' => 'neutral-200',
			'link' => 'primary-700',
			'link-hover' => 'primary-800',
			'focus' => 'primary'
		),
		'dark' => array(
			'bg' => 'neutral-950',
			'surface' => 'neutral-900',
			'surface-2' => 'neutral-800',
			'text' => 'neutral-100',
			'text-muted' => 'neutral-300',
			'heading' => 'white',
			'border' => 'neutral-700',
			'link' => 'primary-300',
			'link-hover' => 'primary-200',
			'focus' => 'primary'
		)
	);

	public const AUTO_TEXT = array(
		'text' => 'bg',
		'text-muted' => 'bg',
		'heading' => 'bg',
		'link' => 'bg',
		'link-hover' => 'bg'
	);

	public const AUTO_ALSO = array(
		'text' => array(
			'surface',
			'surface-2'
		),
		'text-muted' => array(
			'surface',
			'surface-2'
		),
		'heading' => array(
			'surface'
		),
		'link' => array(
			'surface'
		),
		'link-hover' => array(
			'surface'
		)
	);

	public const LIST_ICONS = array(
		'arrow' => 'M4 11h12.2l-5.6-5.6L12 4l8 8-8 8-1.4-1.4 5.6-5.6H4z',
		'dot' => 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z',
		'star' => 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z',
		'plus' => 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z',
		'dash' => 'M5 11h14v2H5z'
	);

	public const PATTERNS = array(
		'external_host' => '^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)?$',
		'external_label' => '^[\\p{L}\\p{N} ().,:-]{0,60}$',
		'surface_1_name' => '^(?=.{0,30}$)(?:[a-z][a-z0-9]*(?:-[a-z0-9]+)*)?$',
		'surface_2_name' => '^(?=.{0,30}$)(?:[a-z][a-z0-9]*(?:-[a-z0-9]+)*)?$',
		'surface_3_name' => '^(?=.{0,30}$)(?:[a-z][a-z0-9]*(?:-[a-z0-9]+)*)?$'
	);

	public const DARK_HOVER = '300';

	public const PRINT_GROUPS = array(
		'alphas' => '^xb-[a-z]+-a[0-9]+$',
		'shadows' => '^xb-(?:shadow|text-shadow|drop-shadow)-',
		'buttons' => '^xb-(?:btn-|link-(?:line|thickness|offset))',
		'forms' => '^xb-(?:field|label|legend|step)-',
		'cards' => '^xb-(?:card-|radius-inner$)',
		'icons' => '^xb-(?:icon|list-icon)-',
		'motion' => '^xb-(?:enter|exit|hover)-'
	);

	public const CLASS_GROUPS = array(
		'colors' => '^xb-(?:color|bg|border)-',
		'buttons' => '^xb-btn',
		'motion' => '^xb-(?:enter|exit|hover)-',
		'icons' => '^xb-(?:icon|list-icon)',
		'cards' => '^xb-card',
		'forms' => '^xb-(?:input|field|label|help)'
	);

	public const SHADES = array(
		'50' => 0.971,
		'100' => 0.936,
		'200' => 0.885,
		'300' => 0.808,
		'400' => 0.707,
		'500' => 0.62,
		'600' => 0.546,
		'700' => 0.47,
		'800' => 0.4,
		'900' => 0.33,
		'950' => 0.25
	);

	public const CHROMA = array(
		'50' => 0.18,
		'100' => 0.32,
		'200' => 0.55,
		'300' => 0.8,
		'400' => 0.95,
		'500' => 1,
		'600' => 0.95,
		'700' => 0.85,
		'800' => 0.72,
		'900' => 0.6,
		'950' => 0.48
	);

	public const TEXT_STEPS = array(
		'xs' => -2,
		's' => -1,
		'm' => 0,
		'l' => 1,
		'xl' => 2,
		'2xl' => 3,
		'3xl' => 4,
		'4xl' => 5
	);

	public const SPACE_STEPS = array(
		'3xs' => -4,
		'2xs' => -3,
		'xs' => -2,
		's' => -1,
		'm' => 0,
		'l' => 1,
		'xl' => 2,
		'2xl' => 3,
		'3xl' => 4,
		'4xl' => 5
	);

	public const ALPHAS = array(
		10,
		20,
		30,
		40,
		50,
		60,
		70,
		80,
		90
	);

	public const HEADINGS = array(
		'h1' => '4xl',
		'h2' => '3xl',
		'h3' => '2xl',
		'h4' => 'xl',
		'h5' => 'l',
		'h6' => 'm'
	);

}
