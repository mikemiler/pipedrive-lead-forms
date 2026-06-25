<?php
/**
 * Plugin Name:       Pipedrive Lead Forms
 * Description:        Configurable front-end forms that log every submission locally and create leads in Pipedrive. Includes bot protection and cache safe submission.
 * Version:           1.0.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:            WP Mike
 * Text Domain:       pipedrive-lead-forms
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package PipedriveLeadForms
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDLEAD_VERSION', '1.0.0' );
define( 'PDLEAD_DB_VERSION', 1 );
define( 'PDLEAD_FILE', __FILE__ );
define( 'PDLEAD_PATH', plugin_dir_path( __FILE__ ) );
define( 'PDLEAD_URL', plugin_dir_url( __FILE__ ) );
define( 'PDLEAD_CPT', 'pdlead_form' );
define( 'PDLEAD_NONCE_ACTION', 'pdlead_submit' );
define( 'PDLEAD_RETRY_HOOK', 'pdlead_retry_event' );
define( 'PDLEAD_CLEANUP_HOOK', 'pdlead_cleanup_event' );

// Load classes.
require_once PDLEAD_PATH . 'includes/class-submission-store.php';
require_once PDLEAD_PATH . 'includes/class-settings.php';
require_once PDLEAD_PATH . 'includes/class-form-cpt.php';
require_once PDLEAD_PATH . 'includes/class-form-renderer.php';
require_once PDLEAD_PATH . 'includes/class-bot-guard.php';
require_once PDLEAD_PATH . 'includes/class-pipedrive-client.php';
require_once PDLEAD_PATH . 'includes/class-lead-dispatcher.php';
require_once PDLEAD_PATH . 'includes/class-rest-controller.php';
require_once PDLEAD_PATH . 'includes/class-admin-settings.php';
require_once PDLEAD_PATH . 'includes/class-admin-log.php';
require_once PDLEAD_PATH . 'includes/class-plugin.php';

/**
 * Activation: create table, ensure HMAC secret, schedule cron events.
 */
function pdlead_activate() {
	Pdlead_Submission_Store::install_table();

	// Persistent secret used to sign the timestamp token (HMAC).
	if ( ! get_option( 'pdlead_hmac_secret' ) ) {
		add_option( 'pdlead_hmac_secret', wp_generate_password( 64, true, true ), '', 'no' );
	}

	if ( ! wp_next_scheduled( PDLEAD_RETRY_HOOK ) ) {
		wp_schedule_event( time() + 300, 'pdlead_five_minutes', PDLEAD_RETRY_HOOK );
	}
	if ( ! wp_next_scheduled( PDLEAD_CLEANUP_HOOK ) ) {
		wp_schedule_event( time() + 3600, 'daily', PDLEAD_CLEANUP_HOOK );
	}
}
register_activation_hook( __FILE__, 'pdlead_activate' );

/**
 * Deactivation: clear scheduled events. Data is kept until uninstall.
 */
function pdlead_deactivate() {
	wp_clear_scheduled_hook( PDLEAD_RETRY_HOOK );
	wp_clear_scheduled_hook( PDLEAD_CLEANUP_HOOK );
}
register_deactivation_hook( __FILE__, 'pdlead_deactivate' );

/**
 * Register a custom five minute cron schedule for retries.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function pdlead_cron_schedules( $schedules ) {
	$schedules['pdlead_five_minutes'] = array(
		'interval' => 300,
		'display'  => __( 'Every 5 minutes (Pipedrive Lead Forms)', 'pipedrive-lead-forms' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'pdlead_cron_schedules' );

// Boot the plugin.
add_action( 'plugins_loaded', array( 'Pdlead_Plugin', 'instance' ) );
