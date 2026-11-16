<?php
/**
 * Functions of custom field plugins XB detects at runtime (static analysis only): ACF / SCF, JetEngine and Meta Box.
 *
 * @package XB
 */

/**
 * @param array<string, mixed> $filter
 * @return array<int, mixed>
 */
function acf_get_field_groups( $filter = array() ) {
	return array();
}

/**
 * @param array<string, mixed>|string|int $parent
 * @return array<int, mixed>
 */
function acf_get_fields( $parent ) {
	return array();
}

/**
 * @param int|string           $post_id
 * @param array<string, mixed> $field
 * @return mixed
 */
function acf_get_value( $post_id, $field ) {
	return null;
}

/** @return object|null */
function jet_engine() {
	return rand( 0, 1 ) ? new stdClass() : null;
}

/**
 * @param string               $selector
 * @param mixed                $value
 * @param int|string|false     $post_id
 * @return bool
 */
function update_field( $selector, $value, $post_id = false ) {
	return true;
}

/**
 * @param array<string, mixed> $field_group
 * @return bool
 */
function acf_add_local_field_group( $field_group ) {
	return true;
}

/**
 * @param string $type
 * @return mixed
 */
function rwmb_get_registry( $type ) {
	return rand( 0, 1 ) ? new stdClass() : null;
}

/**
 * @param string               $field_id
 * @param array<string, mixed> $args
 * @param int|string|null      $post_id
 * @return mixed
 */
function rwmb_get_value( $field_id, $args = array(), $post_id = null ) {
	return null;
}
