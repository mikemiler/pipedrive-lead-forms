<?php
/**
 * Settings page: Pipedrive credentials, bot protection and lead options.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and persists the plugin settings.
 */
class Pdlead_Admin_Settings {

	const GROUP    = 'pdlead_settings_group';
	const PAGE     = 'pdlead-settings';
	const MENU_TOP = 'edit.php?post_type=pdlead_form';

	/**
	 * Admin page hook suffix of the settings page, used to scope asset loading.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_pdlead_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_test_notice' ) );
	}

	/**
	 * Add the settings submenu.
	 */
	public static function add_page() {
		self::$page_hook = add_submenu_page(
			self::MENU_TOP,
			__( 'Pipedrive Settings', 'pipedrive-lead-forms' ),
			__( 'Settings', 'pipedrive-lead-forms' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Well-known first-party cookies offered as a hint below the cookie list.
	 *
	 * @return array<string,string> Cookie name => what it holds.
	 */
	private static function common_cookies() {
		return array(
			'_ga'                => __( 'Google Analytics client ID, links the lead to the visitor in GA4.', 'pipedrive-lead-forms' ),
			'_gcl_aw'            => __( 'Google Ads click ID as stored by Google itself (conversion linker).', 'pipedrive-lead-forms' ),
			'_fbp'               => __( 'Meta Pixel browser ID.', 'pipedrive-lead-forms' ),
			'_fbc'               => __( 'Meta (Facebook, Instagram) click ID, used for the Conversions API.', 'pipedrive-lead-forms' ),
			'li_fat_id'          => __( 'LinkedIn Insight Tag click ID.', 'pipedrive-lead-forms' ),
			'_uetmsclkid'        => __( 'Microsoft Ads (Bing) click ID.', 'pipedrive-lead-forms' ),
			'ttclid'             => __( 'TikTok Pixel click ID.', 'pipedrive-lead-forms' ),
			'hubspotutk'         => __( 'HubSpot visitor token.', 'pipedrive-lead-forms' ),
			'handl_original_ref' => __( 'Where the visitor originally came from (referrer). Requires the HandL UTM Grabber plugin.', 'pipedrive-lead-forms' ),
			'handl_landing_page' => __( 'The first page the visitor landed on. Requires the HandL UTM Grabber plugin.', 'pipedrive-lead-forms' ),
		);
	}

	/**
	 * Load the admin stylesheet on the settings page only (label swatches, cookie hints).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( '' === self::$page_hook || $hook_suffix !== self::$page_hook ) {
			return;
		}
		wp_enqueue_style( 'pdlead-admin', PDLEAD_URL . 'assets/admin.css', array(), PDLEAD_VERSION );
	}

	/**
	 * Register the setting and its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Pdlead_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Pdlead_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitize all settings on save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = Pdlead_Settings::all();
		$out      = Pdlead_Settings::defaults();

		// Token first: keep the stored value if the field is left blank, and
		// ignore it entirely when a wp-config constant is in use. It is resolved
		// before the ID checks below so they validate against the account of the
		// token being saved, not the previously stored one.
		if ( Pdlead_Settings::token_is_constant() ) {
			$out['api_token'] = '';
		} elseif ( isset( $input['api_token'] ) && '' !== trim( $input['api_token'] ) ) {
			$out['api_token'] = trim( sanitize_text_field( $input['api_token'] ) );
		} else {
			$out['api_token'] = isset( $existing['api_token'] ) ? $existing['api_token'] : '';
		}
		$effective_token = Pdlead_Settings::token_is_constant() ? Pdlead_Settings::api_token() : $out['api_token'];

		// Client for validating account-scoped IDs, honoring a token changed in
		// this same save.
		$client = new Pdlead_Pipedrive_Client( $effective_token );

		$out['turnstile_enabled']      = empty( $input['turnstile_enabled'] ) ? 0 : 1;
		$out['turnstile_site_key']     = isset( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
		$out['turnstile_secret_key']   = isset( $input['turnstile_secret_key'] ) ? sanitize_text_field( $input['turnstile_secret_key'] ) : '';
		$out['ratelimit_enabled']      = empty( $input['ratelimit_enabled'] ) ? 0 : 1;
		$out['ratelimit_max']          = max( 1, (int) ( $input['ratelimit_max'] ?? 5 ) );
		$out['ratelimit_window']       = max( 60, (int) ( $input['ratelimit_window'] ?? 600 ) );
		$out['time_trap_seconds']      = max( 0, (int) ( $input['time_trap_seconds'] ?? 2 ) );
		$out['backup_email']           = isset( $input['backup_email'] ) ? sanitize_email( $input['backup_email'] ) : '';
		$out['backup_on_every_submit'] = empty( $input['backup_on_every_submit'] ) ? 0 : 1;
		$out['notify_email']           = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '';
		$out['retention_days']         = max( 0, (int) ( $input['retention_days'] ?? 90 ) );
		// Keep the stored list when the field was not part of the save.
		$out['cookie_names'] = isset( $input['cookie_names'] ) ? implode( ', ', Pdlead_Settings::parse_cookie_names( $input['cookie_names'] ) ) : $existing['cookie_names'];
		$out['max_attempts']           = max( 1, (int) ( $input['max_attempts'] ?? 6 ) );
		// Owner: reject IDs that do not exist in the connected account. When the
		// users cannot be loaded the submitted value is kept, so an API hiccup
		// never resets the setting.
		$out['owner_id'] = max( 0, (int) ( $input['owner_id'] ?? 0 ) );
		if ( $out['owner_id'] > 0 ) {
			$users = $client->get_users();
			if ( null !== $users && ! in_array( $out['owner_id'], array_column( $users, 'id' ), true ) ) {
				$out['owner_id'] = 0;
				add_settings_error(
					Pdlead_Settings::OPTION,
					'pdlead_stale_owner',
					__( 'The default owner ID does not exist in the connected Pipedrive account and was reset to 0 (token owner).', 'pipedrive-lead-forms' ),
					'warning'
				);
			}
		}
		// Lead visibility: whitelist to Pipedrive's visible_to levels (0 = leave unset).
		$vis               = (int) ( $input['visible_to'] ?? 0 );
		$out['visible_to'] = in_array( $vis, array( 0, 1, 3, 5, 7 ), true ) ? $vis : 0;
		// Lead labels: the settings page submits selected label IDs as an array.
		// A marker field tells "user cleared all" apart from "selector not rendered"
		// (no token / API error), so an unavailable API never wipes stored IDs.
		// Submitted IDs are validated against the connected account's labels and
		// unknown ones dropped (stale after an account switch, or deleted in
		// Pipedrive); when the labels cannot be loaded they are kept as submitted.
		if ( ! empty( $input['label_ids_present'] ) ) {
			$ids = isset( $input['label_ids'] ) ? (array) $input['label_ids'] : array();
			$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ids ), 'strlen' ) ) );
			if ( $ids ) {
				$labels = $client->get_lead_labels();
				if ( null !== $labels ) {
					$known = array_fill_keys( array_column( $labels, 'id' ), true );
					$kept  = array_values(
						array_filter(
							$ids,
							static function ( $id ) use ( $known ) {
								return isset( $known[ $id ] );
							}
						)
					);
					if ( count( $kept ) < count( $ids ) ) {
						add_settings_error(
							Pdlead_Settings::OPTION,
							'pdlead_stale_labels',
							__( 'One or more selected lead labels do not exist in the connected Pipedrive account and were removed.', 'pipedrive-lead-forms' ),
							'warning'
						);
					}
					$ids = $kept;
				}
			}
			$out['label_ids'] = implode( ',', $ids );
		} else {
			$out['label_ids'] = isset( $existing['label_ids'] ) ? $existing['label_ids'] : '';
		}
		// Custom field: the chosen custom deal field key plus its selected option
		// IDs. Same present-marker logic as labels so an unavailable API never
		// wipes stored values. When the field changes the old option IDs belong
		// to a different field and are dropped. Key and option IDs are validated
		// against the connected account's fields; when the fields cannot be
		// loaded they are kept as submitted.
		if ( ! empty( $input['custom_field_present'] ) ) {
			$key          = isset( $input['custom_field_key'] ) ? sanitize_text_field( $input['custom_field_key'] ) : '';
			$existing_key = isset( $existing['custom_field_key'] ) ? $existing['custom_field_key'] : '';
			$field        = null;
			if ( '' !== $key ) {
				$fields = $client->get_deal_option_fields();
				if ( null !== $fields ) {
					foreach ( $fields as $candidate ) {
						if ( $candidate['key'] === $key ) {
							$field = $candidate;
							break;
						}
					}
					if ( null === $field ) {
						$key = '';
						add_settings_error(
							Pdlead_Settings::OPTION,
							'pdlead_stale_custom_field',
							__( 'The selected custom field does not exist in the connected Pipedrive account and was cleared.', 'pipedrive-lead-forms' ),
							'warning'
						);
					}
				}
			}
			$out['custom_field_key'] = $key;
			if ( '' === $key || $key !== $existing_key ) {
				$out['custom_field_option_ids'] = '';
			} else {
				$ids = isset( $input['custom_field_option_ids'] ) ? (array) $input['custom_field_option_ids'] : array();
				$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ids ), 'strlen' ) ) );
				if ( $ids && null !== $field ) {
					$known = array_fill_keys( array_column( $field['options'], 'id' ), true );
					$kept  = array_values(
						array_filter(
							$ids,
							static function ( $id ) use ( $known ) {
								return isset( $known[ $id ] );
							}
						)
					);
					if ( count( $kept ) < count( $ids ) ) {
						add_settings_error(
							Pdlead_Settings::OPTION,
							'pdlead_stale_custom_options',
							__( 'One or more selected custom field values do not exist in the connected Pipedrive account and were removed.', 'pipedrive-lead-forms' ),
							'warning'
						);
					}
					$ids = $kept;
				}
				$out['custom_field_option_ids'] = implode( ',', $ids );
			}
		} else {
			$out['custom_field_key']        = isset( $existing['custom_field_key'] ) ? $existing['custom_field_key'] : '';
			$out['custom_field_option_ids'] = isset( $existing['custom_field_option_ids'] ) ? $existing['custom_field_option_ids'] : '';
		}
		$out['file_max_size_mb'] = max( 1, (int) ( $input['file_max_size_mb'] ?? 10 ) );
		// Store the allowed extensions as a clean, comma separated lowercase list.
		$out['file_allowed_ext'] = isset( $input['file_allowed_ext'] )
			? implode( ',', Pdlead_Settings::parse_ext_list( $input['file_allowed_ext'] ) )
			: '';

		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s              = Pdlead_Settings::all();
		$token_constant = Pdlead_Settings::token_is_constant();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pipedrive Lead Forms', 'pipedrive-lead-forms' ); ?></h1>

			<?php settings_errors(); ?>

			<?php if ( get_option( 'pdlead_cf_seen' ) && ! ( defined( 'PDLEAD_TRUST_CF_IP' ) && PDLEAD_TRUST_CF_IP ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: PHP code to add to wp-config.php. */
						esc_html__( 'This site runs behind Cloudflare. Add %s to wp-config.php so the rate limit sees each visitor\'s real IP address. Until then all visitors share Cloudflare\'s addresses, and submissions over the limit are held back in the submission log.', 'pipedrive-lead-forms' ),
						'<code>define( \'PDLEAD_TRUST_CF_IP\', true );</code>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Pipedrive connection', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdlead_api_token"><?php esc_html_e( 'API token', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<?php if ( $token_constant ) : ?>
								<p><strong><?php esc_html_e( 'The API token is defined in wp-config.php (PDLEAD_PIPEDRIVE_TOKEN) and cannot be edited here.', 'pipedrive-lead-forms' ); ?></strong></p>
							<?php else : ?>
								<input type="password" id="pdlead_api_token" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[api_token]" value="" autocomplete="off" class="regular-text" placeholder="<?php echo $s['api_token'] ? esc_attr__( 'Saved. Leave blank to keep.', 'pipedrive-lead-forms' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'For best security define PDLEAD_PIPEDRIVE_TOKEN in wp-config.php instead. Leave blank to keep the saved token.', 'pipedrive-lead-forms' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Bot protection', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Time trap', 'pipedrive-lead-forms' ); ?></th>
						<td>
							<label><input type="number" min="0" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[time_trap_seconds]" value="<?php echo esc_attr( $s['time_trap_seconds'] ); ?>" class="small-text" /> <?php esc_html_e( 'seconds minimum fill time', 'pipedrive-lead-forms' ); ?></label>
							<p class="description"><?php esc_html_e( 'Faster submissions are flagged as suspicious but still saved. Set 0 to disable.', 'pipedrive-lead-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate limit', 'pipedrive-lead-forms' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[ratelimit_enabled]" value="1" <?php checked( $s['ratelimit_enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable per IP rate limiting', 'pipedrive-lead-forms' ); ?></label>
							<p>
								<label><?php esc_html_e( 'Max', 'pipedrive-lead-forms' ); ?> <input type="number" min="1" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[ratelimit_max]" value="<?php echo esc_attr( $s['ratelimit_max'] ); ?>" class="small-text" /></label>
								<label><?php esc_html_e( 'per', 'pipedrive-lead-forms' ); ?> <input type="number" min="60" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[ratelimit_window]" value="<?php echo esc_attr( $s['ratelimit_window'] ); ?>" class="small-text" /> <?php esc_html_e( 'seconds', 'pipedrive-lead-forms' ); ?></label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile', 'pipedrive-lead-forms' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[turnstile_enabled]" value="1" <?php checked( $s['turnstile_enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable Turnstile captcha (optional)', 'pipedrive-lead-forms' ); ?></label>
							<p>
								<label for="pdlead_ts_site"><?php esc_html_e( 'Site key', 'pipedrive-lead-forms' ); ?></label><br />
								<input type="text" id="pdlead_ts_site" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>" class="regular-text" />
							</p>
							<p>
								<label for="pdlead_ts_secret"><?php esc_html_e( 'Secret key', 'pipedrive-lead-forms' ); ?></label><br />
								<input type="text" id="pdlead_ts_secret" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[turnstile_secret_key]" value="<?php echo esc_attr( $s['turnstile_secret_key'] ); ?>" class="regular-text" />
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Leads and delivery', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdlead_owner_id"><?php esc_html_e( 'Default owner ID', 'pipedrive-lead-forms' ); ?></label></th>
						<td><input type="number" id="pdlead_owner_id" min="0" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[owner_id]" value="<?php echo esc_attr( $s['owner_id'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Optional Pipedrive user ID that owns new leads. 0 uses the token owner.', 'pipedrive-lead-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdlead_visible_to"><?php esc_html_e( 'Lead visibility', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<select id="pdlead_visible_to" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[visible_to]">
								<?php
								$visibility_options = array(
									0 => __( 'Pipedrive default (currently: owner only)', 'pipedrive-lead-forms' ),
									1 => __( 'Owner only', 'pipedrive-lead-forms' ),
									3 => __( "Owner's visibility group", 'pipedrive-lead-forms' ),
									5 => __( "Owner's visibility group and sub-groups", 'pipedrive-lead-forms' ),
									7 => __( 'Entire company', 'pipedrive-lead-forms' ),
								);
								foreach ( $visibility_options as $value => $label ) {
									echo '<option value="' . esc_attr( $value ) . '" ' . selected( (int) $s['visible_to'], $value, false ) . '>' . esc_html( $label ) . '</option>';
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Who can see new leads, persons and organizations. Choose "Owner\'s visibility group" so the owner\'s whole group sees them instead of the owner alone. Visibility groups require a Professional or Enterprise Pipedrive plan; on lower plans "Owner\'s visibility group" behaves like "Entire company".', 'pipedrive-lead-forms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lead labels', 'pipedrive-lead-forms' ); ?></th>
						<td><?php self::render_label_ids_field( $s ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom field', 'pipedrive-lead-forms' ); ?></th>
						<td><?php self::render_custom_field( $s ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Backup email', 'pipedrive-lead-forms' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[backup_on_every_submit]" value="1" <?php checked( $s['backup_on_every_submit'], 1 ); ?> /> <?php esc_html_e( 'Email a copy of every submission (recommended, guarantees no lead is lost)', 'pipedrive-lead-forms' ); ?></label>
							<p><input type="email" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[backup_email]" value="<?php echo esc_attr( $s['backup_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdlead_notify_email"><?php esc_html_e( 'Failure notification email', 'pipedrive-lead-forms' ); ?></label></th>
						<td><input type="email" id="pdlead_notify_email" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdlead_retention"><?php esc_html_e( 'Log retention', 'pipedrive-lead-forms' ); ?></label></th>
						<td><input type="number" id="pdlead_retention" min="0" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text" /> <?php esc_html_e( 'days (sent and spam rows are deleted after this. 0 keeps forever)', 'pipedrive-lead-forms' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pdlead_max_attempts"><?php esc_html_e( 'Max retry attempts', 'pipedrive-lead-forms' ); ?></label></th>
						<td><input type="number" id="pdlead_max_attempts" min="1" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $s['max_attempts'] ); ?>" class="small-text" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cookies', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdlead_cookie_names"><?php esc_html_e( 'Cookies to read', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<textarea id="pdlead_cookie_names" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[cookie_names]" rows="3" class="large-text code"><?php echo esc_textarea( $s['cookie_names'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Comma separated cookie names. The values of these cookies are read at every submission and added to the lead note. They are also suggested for Cookie value fields in the form editor, which map a cookie to a Pipedrive field. The plugin never sets cookies; it only reads cookies other plugins have stored, for example the UTM values of HandL UTM Grabber. Leave empty to add no cookies to the note.', 'pipedrive-lead-forms' ); ?></p>
							<p class="description">
								<?php
								/* translators: %s: default cookie names. */
								printf( esc_html__( 'Default: %s', 'pipedrive-lead-forms' ), esc_html( implode( ', ', Pdlead_Settings::DEFAULT_COOKIE_NAMES ) ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'Other common cookies you can add. Each one only has a value when the tool that sets it is active on the site and the visitor gave consent.', 'pipedrive-lead-forms' ); ?></p>
							<ul class="pdlead-cookie-help">
								<?php foreach ( self::common_cookies() as $cookie_name => $cookie_info ) : ?>
									<li><code><?php echo esc_html( $cookie_name ); ?></code> <?php echo esc_html( $cookie_info ); ?></li>
								<?php endforeach; ?>
							</ul>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'File uploads', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdlead_file_max_size"><?php esc_html_e( 'Maximum file size', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<input type="number" id="pdlead_file_max_size" min="1" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[file_max_size_mb]" value="<?php echo esc_attr( $s['file_max_size_mb'] ); ?>" class="small-text" /> <?php esc_html_e( 'MB per file', 'pipedrive-lead-forms' ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: human readable server upload limit. */
									esc_html__( 'Capped by the server upload limit (%s).', 'pipedrive-lead-forms' ),
									esc_html( size_format( wp_max_upload_size() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pdlead_file_allowed_ext"><?php esc_html_e( 'Allowed file types', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<input type="text" id="pdlead_file_allowed_ext" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[file_allowed_ext]" value="<?php echo esc_attr( $s['file_allowed_ext'] ); ?>" class="regular-text" placeholder="pdf,jpg,png,docx" />
							<p class="description"><?php esc_html_e( 'Comma separated file extensions. Leave empty to allow all safe file types WordPress permits. Dangerous types (PHP, executables) are always blocked.', 'pipedrive-lead-forms' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test connection', 'pipedrive-lead-forms' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pdlead_test_connection" />
				<?php wp_nonce_field( 'pdlead_test_connection' ); ?>
				<?php submit_button( __( 'Test Pipedrive connection', 'pipedrive-lead-forms' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Calls /users/me with the saved token and reloads the Pipedrive organization and lead fields offered in the form editor. Save your settings first.', 'pipedrive-lead-forms' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the lead label selector.
	 *
	 * Shows a checkbox per Pipedrive lead label (name visible, ID stored). When
	 * the labels cannot be loaded (no token yet or an API error) only a hint is
	 * shown and no field is emitted, so saving leaves the stored value untouched.
	 * Stored IDs whose label does not exist in the connected account render no
	 * control and are dropped by the sanitizer on save; a hint points this out.
	 *
	 * @param array $s Current settings.
	 */
	private static function render_label_ids_field( $s ) {
		$stored = array_values( array_filter( array_map( 'trim', explode( ',', (string) $s['label_ids'] ) ), 'strlen' ) );
		$labels = ( new Pdlead_Pipedrive_Client() )->get_lead_labels();

		if ( null === $labels ) {
			echo '<p class="description">' . esc_html__( 'Save a valid Pipedrive API token and run "Test Pipedrive connection" first, then your lead labels can be selected here.', 'pipedrive-lead-forms' ) . '</p>';
			return;
		}

		// Marker so an all-unchecked save is distinguishable from the field not being rendered.
		echo '<input type="hidden" name="' . esc_attr( Pdlead_Settings::OPTION ) . '[label_ids_present]" value="1" />';

		$name     = Pdlead_Settings::OPTION . '[label_ids][]';
		$selected = array_flip( $stored );

		foreach ( $labels as $label ) {
			$checked   = isset( $selected[ $label['id'] ] ) ? ' checked' : '';
			$dot_class = 'pdlead-label-dot pdlead-label-dot--' . sanitize_html_class( $label['color'], 'default' );
			printf(
				'<label class="pdlead-label-choice"><input type="checkbox" name="%1$s" value="%2$s"%3$s /> <span class="%4$s"></span> %5$s</label>',
				esc_attr( $name ),
				esc_attr( $label['id'] ),
				esc_attr( $checked ),
				esc_attr( $dot_class ),
				esc_html( $label['name'] )
			);
		}

		if ( empty( $labels ) ) {
			echo '<p class="description">' . esc_html__( 'No lead labels found in Pipedrive.', 'pipedrive-lead-forms' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Select the lead labels to add to every new lead.', 'pipedrive-lead-forms' ) . '</p>';
		}

		// Stale IDs (deleted in Pipedrive or from another account) make Pipedrive
		// reject the labels, so new leads are created without them until saved.
		if ( array_diff( $stored, array_column( $labels, 'id' ) ) ) {
			echo '<p class="description">' . esc_html__( 'One or more saved lead labels do not exist in the connected Pipedrive account. New leads are created without labels until you save these settings, which removes them.', 'pipedrive-lead-forms' ) . '</p>';
		}
	}

	/**
	 * Render the custom-field selector.
	 *
	 * Leads share custom fields with deals. A dropdown chooses which custom
	 * option field to use (for example an acquisition type); the field's options
	 * are then shown as radios (enum, single choice) or checkboxes (set,
	 * multiple). When the fields cannot be loaded (no token or API error) only a
	 * hint is shown and no control is emitted, so saving leaves the stored values
	 * untouched. Stored option IDs whose option does not exist in the connected
	 * account render no control and are dropped by the sanitizer on save.
	 *
	 * @param array $s Current settings.
	 */
	private static function render_custom_field( $s ) {
		$stored_key = (string) $s['custom_field_key'];
		$stored_ids = array_values( array_filter( array_map( 'trim', explode( ',', (string) $s['custom_field_option_ids'] ) ), 'strlen' ) );
		$fields     = ( new Pdlead_Pipedrive_Client() )->get_deal_option_fields();

		if ( null === $fields ) {
			echo '<p class="description">' . esc_html__( 'Save a valid Pipedrive API token and run "Test Pipedrive connection" first, then a custom field can be selected here.', 'pipedrive-lead-forms' ) . '</p>';
			return;
		}

		// Marker so a cleared selection is distinguishable from the control not being rendered.
		echo '<input type="hidden" name="' . esc_attr( Pdlead_Settings::OPTION ) . '[custom_field_present]" value="1" />';

		if ( empty( $fields ) ) {
			echo '<p class="description">' . esc_html__( 'No custom option fields found in Pipedrive.', 'pipedrive-lead-forms' ) . '</p>';
			return;
		}

		// Field selector: pick which custom deal field to set on new leads.
		$field_name     = Pdlead_Settings::OPTION . '[custom_field_key]';
		$selected_field = null;
		echo '<select name="' . esc_attr( $field_name ) . '">';
		echo '<option value="">' . esc_html__( '(none)', 'pipedrive-lead-forms' ) . '</option>';
		foreach ( $fields as $field ) {
			if ( $field['key'] === $stored_key ) {
				$selected_field = $field;
			}
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $field['key'] ),
				selected( $field['key'], $stored_key, false ),
				esc_html( $field['name'] )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Choose a Pipedrive custom field, save, then select its value(s) below.', 'pipedrive-lead-forms' ) . '</p>';

		if ( null === $selected_field ) {
			return;
		}

		// Options of the chosen field: checkboxes for multi (set), radios for single (enum).
		$is_multi  = ( 'set' === $selected_field['field_type'] );
		$input_tag = $is_multi ? 'checkbox' : 'radio';
		$opt_name  = Pdlead_Settings::OPTION . '[custom_field_option_ids][]';
		$selected  = array_flip( $stored_ids );

		if ( ! $is_multi ) {
			// A "none" radio lets a single-choice field be cleared again.
			$has_selection = false;
			foreach ( $selected_field['options'] as $option ) {
				if ( isset( $selected[ $option['id'] ] ) ) {
					$has_selection = true;
					break;
				}
			}
			printf(
				'<label class="pdlead-custom-field-choice"><input type="radio" name="%1$s" value=""%2$s /> %3$s</label>',
				esc_attr( $opt_name ),
				$has_selection ? '' : ' checked',
				esc_html__( '(none)', 'pipedrive-lead-forms' )
			);
		}

		foreach ( $selected_field['options'] as $option ) {
			$checked = isset( $selected[ $option['id'] ] ) ? ' checked' : '';
			printf(
				'<label class="pdlead-custom-field-choice"><input type="%1$s" name="%2$s" value="%3$s"%4$s /> %5$s</label>',
				esc_attr( $input_tag ),
				esc_attr( $opt_name ),
				esc_attr( $option['id'] ),
				esc_attr( $checked ),
				esc_html( $option['label'] )
			);
		}

		if ( empty( $selected_field['options'] ) ) {
			echo '<p class="description">' . esc_html__( 'This field has no options in Pipedrive.', 'pipedrive-lead-forms' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Select the value(s) to set on every new lead.', 'pipedrive-lead-forms' ) . '</p>';
		}
	}

	/**
	 * Handle the test connection form post.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}
		check_admin_referer( 'pdlead_test_connection' );

		// Also refreshes the organization and lead fields offered in the form editor.
		Pdlead_Pipedrive_Client::flush_organization_fields();
		Pdlead_Pipedrive_Client::flush_lead_fields();

		$client = new Pdlead_Pipedrive_Client();
		$res    = $client->test_connection();

		if ( $res['ok'] ) {
			$name    = isset( $res['data']['data']['name'] ) ? $res['data']['data']['name'] : '';
			$message = $name ? sprintf(
				/* translators: %s: Pipedrive user name. */
				__( 'Connected as %s.', 'pipedrive-lead-forms' ),
				$name
			) : __( 'Connection successful.', 'pipedrive-lead-forms' );
			set_transient(
				'pdlead_test_result',
				array(
					'ok'      => true,
					'message' => $message,
				),
				60
			);
		} else {
			set_transient(
				'pdlead_test_result',
				array(
					'ok'      => false,
					'message' => $res['error'],
				),
				60
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => PDLEAD_CPT,
					'page'      => self::PAGE,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Show the test result as an admin notice once.
	 */
	public static function maybe_show_test_notice() {
		$result = get_transient( 'pdlead_test_result' );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'pdlead_test_result' );

		$class = ! empty( $result['ok'] ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $result['message'] )
		);
	}
}
