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
			'file_max_size_mb'       => 10,
			'file_allowed_ext'       => '',
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
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $fallback;
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

	/**
	 * Allowed upload types as an ext => mime map.
	 *
	 * The base is always WordPress' own safe upload whitelist
	 * (get_allowed_mime_types), which already excludes dangerous types such as
	 * PHP and executables. When the admin configures a comma separated list of
	 * extensions, the result is reduced to the intersection: the setting can
	 * only narrow the safe set, never add dangerous types.
	 *
	 * @return array Map of extension (or "ext1|ext2") to MIME type.
	 */
	public static function allowed_file_mimes() {
		$safe = get_allowed_mime_types();

		$configured = self::parse_ext_list( self::get( 'file_allowed_ext', '' ) );
		if ( empty( $configured ) ) {
			return $safe;
		}

		$wanted = array_fill_keys( $configured, true );
		$mimes  = array();
		foreach ( $safe as $exts => $mime ) {
			// WordPress keys group aliases like "jpg|jpeg|jpe".
			foreach ( explode( '|', $exts ) as $ext ) {
				if ( isset( $wanted[ $ext ] ) ) {
					$mimes[ $exts ] = $mime;
					break;
				}
			}
		}

		return $mimes;
	}

	/**
	 * Maximum allowed upload size in bytes, capped by the server limit.
	 *
	 * @return int
	 */
	public static function max_file_size_bytes() {
		$mb     = max( 1, (int) self::get( 'file_max_size_mb', 10 ) );
		$wanted = $mb * MB_IN_BYTES;
		$server = (int) wp_max_upload_size();
		if ( $server > 0 ) {
			return min( $wanted, $server );
		}
		return $wanted;
	}

	/**
	 * Normalize a comma separated extension list into a clean lowercase array.
	 *
	 * @param string $raw Raw value, e.g. ".PDF, jpg ,png".
	 * @return string[]
	 */
	public static function parse_ext_list( $raw ) {
		$parts = array_map(
			static function ( $part ) {
				return ltrim( strtolower( trim( $part ) ), '.' );
			},
			explode( ',', (string) $raw )
		);
		return array_values( array_unique( array_filter( $parts, 'strlen' ) ) );
	}
}
