<?php
/**
 * Suspend an integration-test import immediately after its selected phase has persisted a real WordPress change.
 * This process is killed by the parent PHPUnit process; it is never used by the plugin at runtime.
 */

declare(strict_types=1);

$arguments = $GLOBALS['argv'] ?? null;
if ( ! is_array( $arguments ) || ! array_is_list( $arguments ) || 7 !== count( $arguments ) ) {
	fwrite( STDERR, "Expected wp-load, archive, operation ID, phase, entity ID and marker path.\n" );
	exit( 2 );
}

$script_path = $arguments[0] ?? null;
$wp_load = $arguments[1] ?? null;
$archive_path = $arguments[2] ?? null;
$operation_id = $arguments[3] ?? null;
$target_phase = $arguments[4] ?? null;
$target_entity_id = $arguments[5] ?? null;
$marker_path = $arguments[6] ?? null;
if ( ! is_string( $script_path ) || ! is_string( $wp_load ) || ! is_string( $archive_path ) || ! is_string( $operation_id ) || ! is_string( $target_phase ) || ! is_string( $target_entity_id ) || ! is_string( $marker_path ) ) {
	fwrite( STDERR, "All import-process arguments must be strings.\n" );
	exit( 2 );
}
if ( ! is_file( $wp_load ) || ! is_file( $archive_path ) ) {
	fwrite( STDERR, "WordPress bootstrap or transfer archive is missing.\n" );
	exit( 2 );
}

require $wp_load;
wp_set_current_user( 1 );
$status_key = 'xb_remote_operation_' . $operation_id;
$phase_is_active = static function () use ( $status_key, $target_phase ): bool {
	$status = get_option( $status_key );
	return is_array( $status ) && 'applying' === ( $status['state'] ?? null ) && $target_phase === ( $status['phase'] ?? null );
};
$pause = static function ( string $event ) use ( $marker_path, $operation_id, $target_phase ): void {
	$marker = array(
		'event'       => $event,
		'operationId' => $operation_id,
		'phase'       => $target_phase,
		'pid'         => getmypid(),
	);
	$encoded = json_encode( $marker, JSON_THROW_ON_ERROR );
	if ( false === file_put_contents( $marker_path, $encoded, LOCK_EX ) ) {
		fwrite( STDERR, "Could not write the phase marker.\n" );
		exit( 3 );
	}
	while ( is_file( $marker_path ) ) {
		sleep( 1 );
	}
};

add_action(
	'updated_option',
	static function ( string $option, mixed $old_value, mixed $value ) use ( $status_key, $target_phase, $target_entity_id, $phase_is_active, $pause ): void {
		if ( $option === $status_key && is_array( $value ) && $target_phase === ( $value['phase'] ?? null ) && 'applying' === ( $value['state'] ?? null ) ) {
			$changed = is_array( $value['changed'] ?? null ) ? $value['changed'] : array();
			if ( 'media' === $target_phase && in_array( $target_entity_id, $changed, true ) ) {
				$pause( 'asset-imported' );
			}
			if ( 'components' === $target_phase && in_array( $target_entity_id, $changed, true ) ) {
				$pause( 'component-imported' );
			}
			if ( 'pages' === $target_phase && in_array( $target_entity_id, $changed, true ) ) {
				$pause( 'page-imported' );
			}
		}
		if ( 'cms-content' === $target_phase && $phase_is_active() && in_array( $option, array( 'xb_content', 'xb_page_profile_content_type_ids' ), true ) ) {
			$pause( 'content-definition-saved' );
		}
		if ( 'styles' === $target_phase && $phase_is_active() && 'xb_page_profile_styles_id' === $option && $target_entity_id === $value ) {
			$pause( 'styles-applied' );
		}
	},
	10,
	3
);
add_action(
	'added_option',
	static function ( string $option, mixed $value ) use ( $target_phase, $target_entity_id, $phase_is_active, $pause ): void {
		if ( 'cms-content' === $target_phase && $phase_is_active() && in_array( $option, array( 'xb_content', 'xb_page_profile_content_type_ids' ), true ) ) {
			$pause( 'content-definition-saved' );
		}
		if ( 'styles' === $target_phase && $phase_is_active() && 'xb_page_profile_styles_id' === $option && $target_entity_id === $value ) {
			$pause( 'styles-applied' );
		}
	},
	10,
	2
);

add_action(
	'added_post_meta',
	static function ( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ) use ( $target_phase, $target_entity_id, $phase_is_active, $pause ): void {
		$post = get_post( $post_id );
		if ( 'templates' === $target_phase && $phase_is_active() && '_xb_histos_managed_template' === $meta_key && $post instanceof WP_Post && 'wp_template' === $post->post_type && $target_entity_id === get_post_meta( $post_id, '_xb_page_profile_id', true ) ) {
			$pause( 'template-imported' );
		}
	},
	10,
	4
);

$archive = file_get_contents( $archive_path );
if ( ! is_string( $archive ) ) {
	fwrite( STDERR, "Could not read the transfer archive.\n" );
	exit( 2 );
}
\XB\Remote\PageProfileAdapter::import( $archive, false );
fwrite( STDERR, "The import completed without reaching the requested crash point.\n" );
exit( 4 );
