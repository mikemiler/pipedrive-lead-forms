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
	 * Pipedrive API base URLs. The API token identifies the account, so the
	 * shared host works for every company. Organizations and persons are only
	 * supported on v2; unlike v1, the v2 host also needs the /api prefix.
	 */
	const API_BASE_V1 = 'https://api.pipedrive.com/v1';
	const API_BASE_V2 = 'https://api.pipedrive.com/api/v2';

	/**
	 * Default cookies: the standard UTM parameters and the Google Ads click ID,
	 * under the plain parameter names most UTM plugins store them with.
	 */
	const DEFAULT_COOKIE_NAMES = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'gclid',
	);

	/**
	 * Maximum number of cookies.
	 */
	const MAX_COOKIE_NAMES = 20;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_token'               => '',
			'turnstile_enabled'       => 0,
			'turnstile_site_key'      => '',
			'turnstile_secret_key'    => '',
			'ratelimit_enabled'       => 1,
			'ratelimit_max'           => 5,
			'ratelimit_window'        => 600,
			'time_trap_seconds'       => 2,
			'backup_email'            => '',
			'backup_on_every_submit'  => 1,
			'notify_email'            => '',
			'retention_days'          => 90,
			'max_attempts'            => 6,
			'owner_id'                => 0,
			'visible_to'              => 0,
			'label_ids'               => '',
			'custom_field_key'        => '',
			'custom_field_option_ids' => '',
			'file_max_size_mb'        => 10,
			'file_allowed_ext'        => '',
			'cookie_names'            => implode( ', ', self::DEFAULT_COOKIE_NAMES ),
		);
	}

	/**
	 * The cookies read at every submission and added to the lead note. They
	 * are also suggested for Cookie value fields in the form editor.
	 *
	 * @return string[]
	 */
	public static function cookie_names() {
		return self::parse_cookie_names( self::get( 'cookie_names', '' ) );
	}

	/**
	 * Split a comma or whitespace separated list into valid, unique cookie names.
	 *
	 * @param string $raw Raw list.
	 * @return string[]
	 */
	public static function parse_cookie_names( $raw ) {
		$names = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$names = array_map(
			static function ( $name ) {
				return preg_replace( '/[^A-Za-z0-9_.-]/', '', $name );
			},
			$names
		);
		$names = array_values( array_unique( array_filter( $names, 'strlen' ) ) );
		return array_slice( $names, 0, self::MAX_COOKIE_NAMES );
	}

	/**
	 * Whether Turnstile is fully configured. The front end and the server check
	 * the same condition, so a half configured captcha never blocks everyone.
	 *
	 * @return bool
	 */
	public static function turnstile_active() {
		return self::get( 'turnstile_enabled' ) && '' !== (string) self::get( 'turnstile_site_key' ) && '' !== (string) self::get( 'turnstile_secret_key' );
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
