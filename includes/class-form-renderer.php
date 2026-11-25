<?php
/**
 * Front-end form rendering via shortcode. Output is fully cacheable: it
 * contains no nonce and no timestamp. Those are fetched at submit time from
 * an uncached REST endpoint so caching plugins never serve a stale token.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders forms and enqueues front-end assets on demand.
 */
class Pdlead_Form_Renderer {

	/**
	 * Honeypot field name. Neutral so password managers ignore it.
	 */
	const HONEYPOT = 'pdlead_website';

	/**
	 * Uploads subdirectory holding the generated custom CSS file.
	 */
	const CSS_SUBDIR = 'pipedrive-lead-forms-css';

	/**
	 * Whether assets have been registered this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Forms rendered this request, so element IDs stay unique when one page
	 * shows several forms or the same form twice.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Browser autocomplete tokens per mapping target (WCAG 1.3.5).
	 */
	const AUTOCOMPLETE = array(
		'person_name' => 'name',
		'email'       => 'email',
		'phone'       => 'tel',
		'org_name'    => 'organization',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( 'pdlead_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		// WP Rocket: keep the form working with its optimizations on, and drop
		// cached pages when a form or the settings change, since the form
		// markup is part of the cached page.
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_delay_js_exclusions' ) );
		add_filter( 'rocket_rucss_safelist', array( __CLASS__, 'rocket_rucss_safelist' ) );
		// After the form fields are saved (save_post, default priority).
		add_action( 'save_post', array( __CLASS__, 'purge_on_form_save' ), 20, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'purge_on_form_save' ) );
		add_action( 'update_option_' . Pdlead_Settings::OPTION, array( __CLASS__, 'purge_page_cache' ) );
	}

	/**
	 * Never delay the form script, its config and Turnstile: delayed until the
	 * first interaction, a click on Send could submit the form natively.
	 *
	 * @param array $exclusions Patterns WP Rocket must not delay.
	 * @return array
	 */
	public static function rocket_delay_js_exclusions( $exclusions ) {
		return array_merge( (array) $exclusions, array( 'pipedrive-lead-forms/assets/form.js', 'pdleadConfig', 'challenges.cloudflare.com/turnstile' ) );
	}

	/**
	 * Keep the form styles that only the script applies (status colors,
	 * invalid fields), which Remove Unused CSS cannot see in the page.
	 *
	 * @param array $safelist Selectors WP Rocket must keep.
	 * @return array
	 */
	public static function rocket_rucss_safelist( $safelist ) {
		return array_merge( (array) $safelist, array( '.pdlead-(.*)' ) );
	}

	/**
	 * Purge after a real save of a form, not for the auto-draft "Add New"
	 * creates, autosaves or revisions.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function purge_on_form_save( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || PDLEAD_CPT !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::purge_page_cache();
	}

	/**
	 * Clear the WP Rocket page cache. Form posts are not public, so WP Rocket
	 * does not purge the pages that embed them on its own.
	 */
	public static function purge_page_cache() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Selectable form designs. "standard" is the base stylesheet, which leaves
	 * colors and fonts to the theme; every other design adds its own
	 * stylesheet assets/designs/<key>.css on top, scoped to its form class.
	 *
	 * @return array<string,string> Labels keyed by design key.
	 */
	public static function designs() {
		return array(
			'standard' => __( 'Standard (theme styles)', 'pipedrive-lead-forms' ),
			'outline'  => __( 'Outline with floating labels', 'pipedrive-lead-forms' ),
		);
	}

	/**
	 * Design of a form: its own choice, else the default from the settings.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Design key.
	 */
	public static function form_design( $form_id ) {
		$designs = self::designs();
		$design  = Pdlead_Form_CPT::get_design( $form_id );
		if ( ! isset( $designs[ $design ] ) ) {
			$design = (string) Pdlead_Settings::get( 'form_design' );
		}
		return isset( $designs[ $design ] ) ? $design : 'standard';
	}

	/**
	 * URL of the custom CSS from the settings. It is written to a file in the
	 * uploads folder, so it loads like any stylesheet instead of inline. The
	 * file name carries a hash of the content: a change gets a new URL, so no
	 * browser cache keeps serving the old rules. Older files are kept, because
	 * pages in a page cache still link them until the cache is cleared.
	 *
	 * @return string URL, or '' when there is no custom CSS or it cannot be written.
	 */
	private static function custom_css_url() {
		$css = trim( (string) Pdlead_Settings::get( 'custom_css' ) );
		if ( '' === $css ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$name = 'custom-' . substr( md5( $css ), 0, 12 ) . '.css';
		$dir  = trailingslashit( $uploads['basedir'] ) . self::CSS_SUBDIR;
		$file = $dir . '/' . $name;
		if ( ! file_exists( $file ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plain write of a generated file, WP_Filesystem may need credentials on the front end.
			if ( false === file_put_contents( $file, $css ) ) {
				return '';
			}
		}
		return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::CSS_SUBDIR . '/' . $name );
	}

	/**
	 * Register (but do not enqueue) front-end assets.
	 */
	public static function register_assets() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		wp_register_style( 'pdlead-form', PDLEAD_URL . 'assets/form.css', array(), PDLEAD_VERSION );
		wp_register_script( 'pdlead-form', PDLEAD_URL . 'assets/form.js', array(), PDLEAD_VERSION, true );

		// Only non-secret, visitor-agnostic data is localized so it stays cacheable.
		$turnstile_enabled = Pdlead_Settings::turnstile_active();
		wp_localize_script(
			'pdlead-form',
			'pdleadConfig',
			array(
				'restUrl'          => esc_url_raw( rest_url( 'pdlead/v1/' ) ),
				'turnstileEnabled' => $turnstile_enabled ? 1 : 0,
				'turnstileSiteKey' => $turnstile_enabled ? (string) Pdlead_Settings::get( 'turnstile_site_key' ) : '',
				'i18n'             => array(
					'sending'    => __( 'Sending...', 'pipedrive-lead-forms' ),
					'genericErr' => __( 'Something went wrong. Please try again.', 'pipedrive-lead-forms' ),
					'fileErr'    => __( 'A file is too large or there are too many files. Please attach smaller or fewer files.', 'pipedrive-lead-forms' ),
				),
			)
		);
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'pdlead_form' );
		$form_id = (int) $atts['id'];

		if ( $form_id <= 0 ) {
			return '';
		}

		$post = get_post( $form_id );
		if ( ! $post || PDLEAD_CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$fields = Pdlead_Form_CPT::get_fields( $form_id );
		if ( empty( $fields ) ) {
			return '';
		}

		$design = self::form_design( $form_id );
		$style  = 'pdlead-form';
		wp_enqueue_style( 'pdlead-form' );
		if ( 'standard' !== $design ) {
			$style = 'pdlead-design-' . $design;
			wp_enqueue_style( $style, PDLEAD_URL . 'assets/designs/' . $design . '.css', array( 'pdlead-form' ), PDLEAD_VERSION );
		}
		// The custom CSS fine tunes every design, so it loads after it.
		$custom_css = self::custom_css_url();
		if ( '' !== $custom_css ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the file name carries a content hash.
			wp_enqueue_style( 'pdlead-custom', $custom_css, array( $style ), null );
		}
		wp_enqueue_script( 'pdlead-form' );

		if ( Pdlead_Settings::turnstile_active() ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party CDN script; versioning is managed by Cloudflare, not us.
			wp_enqueue_script( 'pdlead-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		}

		return self::render( $form_id, $fields, $design );
	}

	/**
	 * Build the form markup.
	 *
	 * @param int    $form_id Form post ID.
	 * @param array  $fields  Field definitions.
	 * @param string $design  Design key.
	 * @return string
	 */
	private static function render( $form_id, $fields, $design ) {
		$turnstile_enabled = Pdlead_Settings::turnstile_active();

		// Form specific texts handled on the client: the error text is the fallback
		// when the request never reaches the server (a network failure), and the
		// sending text shows while the request is in flight.
		$error_message   = Pdlead_Form_CPT::get_error_message( $form_id );
		$sending_message = Pdlead_Form_CPT::get_sending_message( $form_id );

		++self::$instance;
		$id_prefix = 'pdlead-' . $form_id . '-' . self::$instance . '-';

		// Upload limits for the client side check: per file, files per request
		// and the whole request body (PHP drops every field above post_max_size).
		$max_bytes = Pdlead_Settings::max_file_size_bytes();
		$max_files = (int) ini_get( 'max_file_uploads' );
		$max_total = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		// method="post" keeps the values out of the URL should the script never
		// run; the script always handles the submit itself.
		ob_start();
		?>
		<form class="pdlead-form pdlead-design-<?php echo esc_attr( $design ); ?>" method="post" data-pdlead-form="<?php echo esc_attr( $form_id ); ?>" data-pdlead-error="<?php echo esc_attr( $error_message ); ?>" data-pdlead-sending="<?php echo esc_attr( $sending_message ); ?>" data-pdlead-max-bytes="<?php echo esc_attr( $max_bytes ); ?>" data-pdlead-max-files="<?php echo esc_attr( $max_files ); ?>" data-pdlead-max-total="<?php echo esc_attr( $max_total ); ?>" novalidate>
			<?php foreach ( $fields as $field ) : ?>
				<?php echo self::render_field( $field, $id_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>

			<?php // Honeypot: hidden from humans, assistive tech and keyboard nav. ?>
			<div class="pdlead-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'pipedrive-lead-forms' ); ?>
					<input type="text" name="<?php echo esc_attr( self::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
				</label>
			</div>

			<?php if ( $turnstile_enabled ) : ?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Pdlead_Settings::get( 'turnstile_site_key' ) ); ?>"></div>
			<?php endif; ?>

			<?php // Token fields are populated by form.js from the uncached REST endpoint. ?>
			<input type="hidden" name="pdlead_nonce" value="" />
			<input type="hidden" name="pdlead_ts" value="" />
			<input type="hidden" name="pdlead_sig" value="" />

			<?php // wp-element-button picks up the button style of block themes. ?>
			<button type="submit" class="pdlead-submit wp-element-button"><?php esc_html_e( 'Send', 'pipedrive-lead-forms' ); ?></button>

			<div class="pdlead-status" role="status" aria-live="polite" tabindex="-1"></div>
		</form>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * Render a single form field.
	 *
	 * @param array  $field     Field definition.
	 * @param string $id_prefix Element ID prefix unique to this form instance.
	 * @return string
	 */
	private static function render_field( $field, $id_prefix ) {
		$key      = isset( $field['key'] ) ? $field['key'] : '';
		$label    = isset( $field['label'] ) ? $field['label'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ( isset( $field['required'] ) && 'yes' === $field['required'] );
		$name     = 'fields[' . $key . ']';
		$id       = $id_prefix . $key;
		$map      = isset( $field['map_to'] ) ? $field['map_to'] : 'none';
		$auto     = isset( self::AUTOCOMPLETE[ $map ] ) ? ' autocomplete="' . self::AUTOCOMPLETE[ $map ] . '"' : '';
		$req_attr = $required ? ' required' : '';
		$req_mark = $required ? ' <span class="pdlead-req" aria-hidden="true">*</span>' : '';
		// The outline design hides the placeholder and moves the label into the
		// field instead; it still needs the attribute to detect an empty field.
		$placeholder = $label;

		// Hidden and cookie values are added on the server at submission, so
		// nothing is rendered: visitors can neither see nor change them.
		if ( Pdlead_Form_CPT::is_server_value_type( $type ) ) {
			return '';
		}

		ob_start();

		switch ( $type ) {
			case 'textarea':
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5" maxlength="<?php echo esc_attr( Pdlead_Rest_Controller::TEXTAREA_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></textarea>
				</p>
				<?php
				break;

			case 'select':
				$options = self::split_options( isset( $field['options'] ) ? $field['options'] : '' );
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( 'Please choose', 'pipedrive-lead-forms' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php
				break;

			case 'checkbox':
			case 'consent':
				$consent_text = isset( $field['consent_text'] ) && '' !== $field['consent_text'] ? $field['consent_text'] : $label;
				?>
				<p class="pdlead-row pdlead-row-check">
					<label for="<?php echo esc_attr( $id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php echo wp_kses_post( $consent_text ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
				</p>
				<?php
				break;

			case 'file':
				$accept = self::accept_attr();
				/* translators: %s: maximum file size, e.g. "10 MB". */
				$hint = sprintf( __( 'Max. %s per file', 'pipedrive-lead-forms' ), size_format( Pdlead_Settings::max_file_size_bytes() ) );
				if ( ! $required ) {
					$hint .= ' ' . __( '(optional)', 'pipedrive-lead-forms' );
				}
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span class="pdlead-file-hint"><?php echo esc_html( $hint ); ?></span></label>
					<input type="file" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[]" multiple<?php echo $accept; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</p>
				<?php
				break;

			default:
				$input_type = in_array( $type, array( 'email', 'tel', 'text' ), true ) ? $type : 'text';
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" maxlength="<?php echo esc_attr( Pdlead_Rest_Controller::TEXT_MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo $auto . $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute strings. ?> />
				</p>
				<?php
				break;
		}

		return ob_get_clean();
	}

	/**
	 * Split a comma separated options string into a clean array.
	 *
	 * @param string $raw Raw options string.
	 * @return string[]
	 */
	private static function split_options( $raw ) {
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Build an accept="..." attribute from the allowed upload types so the file
	 * picker hints the permitted extensions. UX only; the server still validates.
	 *
	 * @return string The attribute (with a leading space) or an empty string.
	 */
	private static function accept_attr() {
		$exts = array();
		foreach ( array_keys( Pdlead_Settings::allowed_file_mimes() ) as $group ) {
			foreach ( explode( '|', $group ) as $ext ) {
				$exts[] = '.' . $ext;
			}
		}
		if ( empty( $exts ) ) {
			return '';
		}
		return ' accept="' . esc_attr( implode( ',', $exts ) ) . '"';
	}
}
