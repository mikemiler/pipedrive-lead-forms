<?php
/**
 * Plugin bootstrap. Wires all components together.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that initializes the plugin.
 */
class Pdlead_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Pdlead_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get or create the instance.
	 *
	 * @return Pdlead_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register components.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'Pdlead_Submission_Store', 'maybe_upgrade' ) );

		Pdlead_Form_CPT::init();
		Pdlead_Form_Renderer::init();
		Pdlead_Rest_Controller::init();
		Pdlead_Lead_Dispatcher::init();
		Pdlead_Admin_Settings::init();
		Pdlead_Admin_Log::init();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'pipedrive-lead-forms', false, dirname( plugin_basename( PDLEAD_FILE ) ) . '/languages' );
	}
}
