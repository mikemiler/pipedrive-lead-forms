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
	 * Whether assets have been registered this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( 'pdlead_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
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
		$turnstile_enabled = (int) Pdlead_Settings::get( 'turnstile_enabled' );
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

		wp_enqueue_style( 'pdlead-form' );
		wp_enqueue_script( 'pdlead-form' );

		if ( Pdlead_Settings::get( 'turnstile_enabled' ) && Pdlead_Settings::get( 'turnstile_site_key' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party CDN script; versioning is managed by Cloudflare, not us.
			wp_enqueue_script( 'pdlead-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		}

		return self::render( $form_id, $fields );
	}

	/**
	 * Build the form markup.
	 *
	 * @param int   $form_id Form post ID.
	 * @param array $fields  Field definitions.
	 * @return string
	 */
	private static function render( $form_id, $fields ) {
		$turnstile_enabled = Pdlead_Settings::get( 'turnstile_enabled' ) && Pdlead_Settings::get( 'turnstile_site_key' );

		ob_start();
		?>
		<form class="pdlead-form" data-pdlead-form="<?php echo esc_attr( $form_id ); ?>" novalidate>
			<div class="pdlead-status" role="alert" aria-live="polite"></div>

			<?php foreach ( $fields as $field ) : ?>
				<?php echo self::render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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

			<button type="submit" class="pdlead-submit"><?php esc_html_e( 'Send', 'pipedrive-lead-forms' ); ?></button>
		</form>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * Render a single form field.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	private static function render_field( $field ) {
		$key      = isset( $field['key'] ) ? $field['key'] : '';
		$label    = isset( $field['label'] ) ? $field['label'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ( isset( $field['required'] ) && 'yes' === $field['required'] );
		$name     = 'fields[' . $key . ']';
		$id       = 'pdlead-' . $key;
		$req_attr = $required ? ' required' : '';
		$req_mark = $required ? ' <span class="pdlead-req" aria-hidden="true">*</span>' : '';

		ob_start();

		switch ( $type ) {
			case 'textarea':
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></textarea>
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
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="file" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[]" multiple<?php echo $accept; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</p>
				<?php
				break;

			default:
				$input_type = in_array( $type, array( 'email', 'tel', 'text' ), true ) ? $type : 'text';
				?>
				<p class="pdlead-row">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
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
