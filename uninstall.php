<?php
/**
 * Uninstall cleanup. Removes the submissions table, plugin options and the
 * form custom posts so no personal data is left behind.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the submissions table (contains PII).
$pdlead_table = $wpdb->prefix . 'pdlead_submissions';
$wpdb->query( "DROP TABLE IF EXISTS {$pdlead_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, no user input.

// Remove plugin options.
delete_option( 'pdlead_settings' );
delete_option( 'pdlead_db_version' );
delete_option( 'pdlead_hmac_secret' );

// Remove form definitions and their meta.
$pdlead_forms = get_posts(
	array(
		'post_type'      => 'pdlead_form',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);
foreach ( $pdlead_forms as $pdlead_form_id ) {
	wp_delete_post( $pdlead_form_id, true );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'pdlead_retry_event' );
wp_clear_scheduled_hook( 'pdlead_cleanup_event' );
