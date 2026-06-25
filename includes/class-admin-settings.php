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
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_pdlead_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_test_notice' ) );
	}

	/**
	 * Add the settings submenu.
	 */
	public static function add_page() {
		add_submenu_page(
			self::MENU_TOP,
			__( 'Pipedrive Settings', 'pipedrive-lead-forms' ),
			__( 'Settings', 'pipedrive-lead-forms' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
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

		$out['company_domain']  = isset( $input['company_domain'] ) ? sanitize_text_field( $input['company_domain'] ) : '';
		$out['turnstile_enabled'] = empty( $input['turnstile_enabled'] ) ? 0 : 1;
		$out['turnstile_site_key']   = isset( $input['turnstile_site_key'] ) ? sanitize_text_field( $input['turnstile_site_key'] ) : '';
		$out['turnstile_secret_key'] = isset( $input['turnstile_secret_key'] ) ? sanitize_text_field( $input['turnstile_secret_key'] ) : '';
		$out['ratelimit_enabled'] = empty( $input['ratelimit_enabled'] ) ? 0 : 1;
		$out['ratelimit_max']     = max( 1, (int) ( $input['ratelimit_max'] ?? 5 ) );
		$out['ratelimit_window']  = max( 60, (int) ( $input['ratelimit_window'] ?? 600 ) );
		$out['time_trap_seconds'] = max( 0, (int) ( $input['time_trap_seconds'] ?? 2 ) );
		$out['backup_email']      = isset( $input['backup_email'] ) ? sanitize_email( $input['backup_email'] ) : '';
		$out['backup_on_every_submit'] = empty( $input['backup_on_every_submit'] ) ? 0 : 1;
		$out['notify_email']      = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '';
		$out['retention_days']    = max( 0, (int) ( $input['retention_days'] ?? 90 ) );
		$out['max_attempts']      = max( 1, (int) ( $input['max_attempts'] ?? 6 ) );
		$out['owner_id']          = max( 0, (int) ( $input['owner_id'] ?? 0 ) );
		$out['label_ids']         = isset( $input['label_ids'] ) ? sanitize_text_field( $input['label_ids'] ) : '';

		// Token: keep the stored value if the field is left blank, and ignore it
		// entirely when a wp-config constant is in use.
		if ( Pdlead_Settings::token_is_constant() ) {
			$out['api_token'] = '';
		} elseif ( isset( $input['api_token'] ) && '' !== trim( $input['api_token'] ) ) {
			$out['api_token'] = trim( sanitize_text_field( $input['api_token'] ) );
		} else {
			$out['api_token'] = isset( $existing['api_token'] ) ? $existing['api_token'] : '';
		}

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

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Pipedrive connection', 'pipedrive-lead-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pdlead_company_domain"><?php esc_html_e( 'Company domain', 'pipedrive-lead-forms' ); ?></label></th>
						<td>
							<input type="text" id="pdlead_company_domain" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[company_domain]" value="<?php echo esc_attr( $s['company_domain'] ); ?>" class="regular-text" placeholder="acme" />
							<p class="description"><?php esc_html_e( 'Your Pipedrive subdomain, for example "acme" for acme.pipedrive.com. Leave empty to use the shared API host.', 'pipedrive-lead-forms' ); ?></p>
						</td>
					</tr>
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
						<th scope="row"><label for="pdlead_label_ids"><?php esc_html_e( 'Lead label IDs', 'pipedrive-lead-forms' ); ?></label></th>
						<td><input type="text" id="pdlead_label_ids" name="<?php echo esc_attr( Pdlead_Settings::OPTION ); ?>[label_ids]" value="<?php echo esc_attr( $s['label_ids'] ); ?>" class="regular-text" placeholder="id1,id2" />
							<p class="description"><?php esc_html_e( 'Optional comma separated lead label IDs.', 'pipedrive-lead-forms' ); ?></p>
						</td>
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

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test connection', 'pipedrive-lead-forms' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pdlead_test_connection" />
				<?php wp_nonce_field( 'pdlead_test_connection' ); ?>
				<?php submit_button( __( 'Test Pipedrive connection', 'pipedrive-lead-forms' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Calls /users/me with the saved token. Save your settings first.', 'pipedrive-lead-forms' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the test connection form post.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}
		check_admin_referer( 'pdlead_test_connection' );

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
					'ok' => true,
					'message' => $message,
				),
				60
			);
		} else {
			set_transient(
				'pdlead_test_result',
				array(
					'ok' => false,
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
