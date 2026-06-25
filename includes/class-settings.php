<?php
/**
 * Centralized settings access with sane defaults.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings reader and writer. Secrets are never exposed to the front end.
 */
class Pdlead_Settings {

	const OPTION = 'pdlead_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'company_domain'         => '',
			'api_token'              => '',
			'turnstile_enabled'      => 0,
			'turnstile_site_key'     => '',
			'turnstile_secret_key'   => '',
			'ratelimit_enabled'      => 1,
			'ratelimit_max'          => 5,
			'ratelimit_window'       => 600,
			'time_trap_seconds'      => 2,
			'backup_email'           => '',
			'backup_on_every_submit' => 1,
			'notify_email'           => '',
			'retention_days'         => 90,
			'max_attempts'           => 6,
			'owner_id'               => 0,
			'label_ids'              => '',
		);
	}

	/**
	 * Get the full settings array merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Resolve the Pipedrive API token.
	 * Prefer a wp-config constant so the secret is not stored in the database.
	 *
	 * @return string
	 */
	public static function api_token() {
		if ( defined( 'PDLEAD_PIPEDRIVE_TOKEN' ) && PDLEAD_PIPEDRIVE_TOKEN ) {
			return (string) PDLEAD_PIPEDRIVE_TOKEN;
		}
		return (string) self::get( 'api_token', '' );
	}

	/**
	 * Whether the token comes from a wp-config constant.
	 *
	 * @return bool
	 */
	public static function token_is_constant() {
		return defined( 'PDLEAD_PIPEDRIVE_TOKEN' ) && PDLEAD_PIPEDRIVE_TOKEN;
	}

	/**
	 * Base API host. Uses the company domain when provided, else the shared host.
	 *
	 * @return string
	 */
	public static function api_base() {
		$domain = trim( (string) self::get( 'company_domain', '' ) );
		if ( $domain ) {
			// Accept either "acme" or "acme.pipedrive.com".
			$domain = preg_replace( '#^https?://#', '', $domain );
			$domain = preg_replace( '#\.pipedrive\.com.*$#', '', $domain );
			$domain = preg_replace( '#[^a-z0-9-].*$#i', '', $domain );
			if ( $domain ) {
				return 'https://' . $domain . '.pipedrive.com/api/v1';
			}
		}
		return 'https://api.pipedrive.com/v1';
	}
}
