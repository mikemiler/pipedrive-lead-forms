<?php
/**
 * Form custom post type and field editor meta box.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the pdlead_form CPT and the field configuration meta box.
 */
class Pdlead_Form_CPT {

	const META_FIELDS         = '_pdlead_fields';
	const META_SUCCESS_MSG    = '_pdlead_success_message';
	const META_ERROR_MSG      = '_pdlead_error_message';
	const META_VALIDATION_MSG = '_pdlead_validation_message';
	const META_SENDING_MSG    = '_pdlead_sending_message';

	/**
	 * Supported field types.
	 *
	 * @return array
	 */
	public static function field_types() {
		return array(
			'text'     => __( 'Text', 'pipedrive-lead-forms' ),
			'email'    => __( 'Email', 'pipedrive-lead-forms' ),
			'tel'      => __( 'Phone', 'pipedrive-lead-forms' ),
			'textarea' => __( 'Textarea', 'pipedrive-lead-forms' ),
			'select'   => __( 'Select', 'pipedrive-lead-forms' ),
			'checkbox' => __( 'Checkbox', 'pipedrive-lead-forms' ),
			'consent'  => __( 'Consent checkbox', 'pipedrive-lead-forms' ),
			'file'     => __( 'File Upload', 'pipedrive-lead-forms' ),
		);
	}

	/**
	 * Supported Pipedrive mapping targets.
	 *
	 * @return array
	 */
	public static function map_targets() {
		return array(
			'none'        => __( 'Do not send to Pipedrive', 'pipedrive-lead-forms' ),
			'person_name' => __( 'Person: Name', 'pipedrive-lead-forms' ),
			'email'       => __( 'Person: Email', 'pipedrive-lead-forms' ),
			'phone'       => __( 'Person: Phone', 'pipedrive-lead-forms' ),
			'org_name'    => __( 'Organization: Name', 'pipedrive-lead-forms' ),
			'lead_title'  => __( 'Lead: Title', 'pipedrive-lead-forms' ),
			'note'        => __( 'Lead: Note', 'pipedrive-lead-forms' ),
			'file'        => __( 'Lead: File attachment', 'pipedrive-lead-forms' ),
			'consent'     => __( 'Consent (not sent)', 'pipedrive-lead-forms' ),
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . PDLEAD_CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_filter( 'manage_' . PDLEAD_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . PDLEAD_CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register() {
		register_post_type(
			PDLEAD_CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Lead Forms', 'pipedrive-lead-forms' ),
					'singular_name' => __( 'Lead Form', 'pipedrive-lead-forms' ),
					'add_new_item'  => __( 'Add New Lead Form', 'pipedrive-lead-forms' ),
					'edit_item'     => __( 'Edit Lead Form', 'pipedrive-lead-forms' ),
					'menu_name'     => __( 'Pipedrive Leads', 'pipedrive-lead-forms' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-feedback',
				'menu_position'       => 26,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_in_rest'        => false,
			)
		);
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$columns['pdlead_shortcode'] = __( 'Shortcode', 'pipedrive-lead-forms' );
		return $columns;
	}

	/**
	 * Render the shortcode column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		if ( 'pdlead_shortcode' === $column ) {
			echo '<code>[pdlead_form id="' . esc_attr( $post_id ) . '"]</code>';
		}
	}

	/**
	 * Enqueue the repeater script on the edit screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_admin( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || PDLEAD_CPT !== $screen->post_type ) {
			return;
		}
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'pdlead-admin', PDLEAD_URL . 'assets/admin.css', array(), PDLEAD_VERSION );
		wp_enqueue_script( 'pdlead-admin-forms', PDLEAD_URL . 'assets/admin-forms.js', array( 'jquery-ui-sortable' ), PDLEAD_VERSION, true );
	}

	/**
	 * Register the meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'pdlead_fields_box',
			__( 'Form Fields', 'pipedrive-lead-forms' ),
			array( __CLASS__, 'render_meta_box' ),
			PDLEAD_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Get the stored fields for a form.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function get_fields( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_FIELDS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * Get the form specific success message, or '' when none is set.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_success_message( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_SUCCESS_MSG, true );
	}

	/**
	 * Get the form specific error message, or '' when none is set.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_error_message( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_ERROR_MSG, true );
	}

	/**
	 * Get the form specific validation message, or '' when none is set.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_validation_message( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_VALIDATION_MSG, true );
	}

	/**
	 * Get the form specific sending message, or '' when none is set.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_sending_message( $post_id ) {
		return (string) get_post_meta( $post_id, self::META_SENDING_MSG, true );
	}

	/**
	 * Render the field editor meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'pdlead_save_fields', 'pdlead_fields_nonce' );

		$fields = self::get_fields( $post->ID );
		if ( empty( $fields ) ) {
			// Sensible starter set for a new form.
			$fields = array(
				array(
					'key'      => 'name',
					'label'    => __( 'Name', 'pipedrive-lead-forms' ),
					'type'     => 'text',
					'required' => 'yes',
					'map_to'   => 'person_name',
				),
				array(
					'key'      => 'email',
					'label'    => __( 'Email', 'pipedrive-lead-forms' ),
					'type'     => 'email',
					'required' => 'yes',
					'map_to'   => 'email',
				),
				array(
					'key'      => 'message',
					'label'    => __( 'Message', 'pipedrive-lead-forms' ),
					'type'     => 'textarea',
					'required' => 'no',
					'map_to'   => 'note',
				),
			);
		}

		echo '<p class="description">' . esc_html__( 'Define the fields shown in the form and how each maps to Pipedrive. At least one field should map to Person: Email.', 'pipedrive-lead-forms' ) . '</p>';

		echo '<table class="widefat pdlead-fields-table" id="pdlead-fields-table"><thead><tr>';
		echo '<th class="pdlead-handle-col"><span class="screen-reader-text">' . esc_html__( 'Reorder', 'pipedrive-lead-forms' ) . '</span></th>';
		echo '<th>' . esc_html__( 'Label', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Key', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Required', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Options (comma separated)', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th>' . esc_html__( 'Maps to', 'pipedrive-lead-forms' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $fields as $field ) {
			self::render_field_row( $field );
		}

		echo '</tbody></table>';

		echo '<p><button type="button" class="button" id="pdlead-add-field">' . esc_html__( 'Add field', 'pipedrive-lead-forms' ) . '</button></p>';

		// Form specific submission messages. Leaving a field empty falls back to
		// the built-in default. HTML links are allowed, like in the consent text.
		$success_message    = self::get_success_message( $post->ID );
		$error_message      = self::get_error_message( $post->ID );
		$validation_message = self::get_validation_message( $post->ID );
		$sending_message    = self::get_sending_message( $post->ID );

		echo '<h2 class="pdlead-messages-title">' . esc_html__( 'Submission messages', 'pipedrive-lead-forms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shown to the visitor around submitting the form. Leave empty to use the default text. HTML links are allowed.', 'pipedrive-lead-forms' ) . '</p>';

		echo '<p><label for="pdlead-success-message"><strong>' . esc_html__( 'Success message', 'pipedrive-lead-forms' ) . '</strong></label><br />';
		echo '<textarea id="pdlead-success-message" name="pdlead_success_message" rows="2" class="widefat" placeholder="' . esc_attr__( 'Thank you. Your message has been sent.', 'pipedrive-lead-forms' ) . '">' . esc_textarea( $success_message ) . '</textarea></p>';

		echo '<p><label for="pdlead-validation-message"><strong>' . esc_html__( 'Validation message', 'pipedrive-lead-forms' ) . '</strong></label><br />';
		echo '<textarea id="pdlead-validation-message" name="pdlead_validation_message" rows="2" class="widefat" placeholder="' . esc_attr__( 'Please complete all required fields correctly.', 'pipedrive-lead-forms' ) . '">' . esc_textarea( $validation_message ) . '</textarea></p>';

		echo '<p><label for="pdlead-error-message"><strong>' . esc_html__( 'General error message', 'pipedrive-lead-forms' ) . '</strong></label><br />';
		echo '<textarea id="pdlead-error-message" name="pdlead_error_message" rows="2" class="widefat" placeholder="' . esc_attr__( 'Something went wrong. Please try again.', 'pipedrive-lead-forms' ) . '">' . esc_textarea( $error_message ) . '</textarea></p>';

		echo '<p><label for="pdlead-sending-message"><strong>' . esc_html__( 'Sending message', 'pipedrive-lead-forms' ) . '</strong></label><br />';
		echo '<textarea id="pdlead-sending-message" name="pdlead_sending_message" rows="2" class="widefat" placeholder="' . esc_attr__( 'Sending...', 'pipedrive-lead-forms' ) . '">' . esc_textarea( $sending_message ) . '</textarea></p>';

		// Hidden template row used by the repeater script.
		echo '<script type="text/html" id="pdlead-field-template">';
		self::render_field_row( array() );
		echo '</script>';
	}

	/**
	 * Render a single editable field row.
	 *
	 * @param array $field Field data.
	 */
	private static function render_field_row( $field ) {
		$field = wp_parse_args(
			$field,
			array(
				'label'        => '',
				'key'          => '',
				'type'         => 'text',
				'required'     => 'no',
				'options'      => '',
				'map_to'       => 'none',
				'consent_text' => '',
			)
		);

		echo '<tr class="pdlead-field-row">';

		echo '<td class="pdlead-handle-cell"><span class="pdlead-drag-handle dashicons dashicons-menu" aria-hidden="true" title="' . esc_attr__( 'Drag to reorder', 'pipedrive-lead-forms' ) . '"></span></td>';

		echo '<td><input type="text" name="pdlead_field_label[]" value="' . esc_attr( $field['label'] ) . '" class="widefat" /></td>';

		echo '<td><input type="text" name="pdlead_field_key[]" value="' . esc_attr( $field['key'] ) . '" class="widefat" /></td>';

		echo '<td><select name="pdlead_field_type[]">';
		foreach ( self::field_types() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><select name="pdlead_field_required[]">';
		echo '<option value="no" ' . selected( $field['required'], 'no', false ) . '>' . esc_html__( 'No', 'pipedrive-lead-forms' ) . '</option>';
		echo '<option value="yes" ' . selected( $field['required'], 'yes', false ) . '>' . esc_html__( 'Yes', 'pipedrive-lead-forms' ) . '</option>';
		echo '</select></td>';

		echo '<td><input type="text" name="pdlead_field_options[]" value="' . esc_attr( $field['options'] ) . '" class="widefat" /></td>';

		echo '<td><select name="pdlead_field_map[]">';
		foreach ( self::map_targets() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $field['map_to'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><button type="button" class="button-link pdlead-remove-field" aria-label="' . esc_attr__( 'Remove field', 'pipedrive-lead-forms' ) . '">&times;</button></td>';

		echo '</tr>';

		// Consent text lives in its own full-width row directly below the field.
		// It is only relevant for consent fields, so admin-forms.js shows this row
		// only when the consent type is selected. The textarea still posts in field
		// order, so the index alignment in save() stays intact.
		echo '<tr class="pdlead-consent-row">';
		echo '<td colspan="8"><label class="pdlead-consent-label">' . esc_html__( 'Consent text', 'pipedrive-lead-forms' ) . ' ';
		echo '<textarea name="pdlead_field_consent[]" rows="2" class="widefat pdlead-consent-text" placeholder="' . esc_attr__( 'HTML links allowed, e.g. I accept the <a href="/privacy">privacy policy</a>.', 'pipedrive-lead-forms' ) . '">' . esc_textarea( $field['consent_text'] ) . '</textarea>';
		echo '</label></td>';
		echo '</tr>';
	}

	/**
	 * Persist field configuration on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (unused; required by the save_post hook signature).
	 */
	public static function save( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post is part of the save_post callback contract.
		// Verify nonce.
		if ( ! isset( $_POST['pdlead_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pdlead_fields_nonce'] ) ), 'pdlead_save_fields' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$labels   = isset( $_POST['pdlead_field_label'] ) ? (array) wp_unslash( $_POST['pdlead_field_label'] ) : array();
		$keys     = isset( $_POST['pdlead_field_key'] ) ? (array) wp_unslash( $_POST['pdlead_field_key'] ) : array();
		$types    = isset( $_POST['pdlead_field_type'] ) ? (array) wp_unslash( $_POST['pdlead_field_type'] ) : array();
		$required = isset( $_POST['pdlead_field_required'] ) ? (array) wp_unslash( $_POST['pdlead_field_required'] ) : array();
		$options  = isset( $_POST['pdlead_field_options'] ) ? (array) wp_unslash( $_POST['pdlead_field_options'] ) : array();
		$maps     = isset( $_POST['pdlead_field_map'] ) ? (array) wp_unslash( $_POST['pdlead_field_map'] ) : array();
		$consents = isset( $_POST['pdlead_field_consent'] ) ? (array) wp_unslash( $_POST['pdlead_field_consent'] ) : array();

		$valid_types = array_keys( self::field_types() );
		$valid_maps  = array_keys( self::map_targets() );
		$used_keys   = array();
		$fields      = array();

		$count = count( $labels );
		for ( $i = 0; $i < $count; $i++ ) {
			$label = sanitize_text_field( $labels[ $i ] );
			if ( '' === $label ) {
				continue; // Skip empty template rows.
			}

			$type = isset( $types[ $i ] ) && in_array( $types[ $i ], $valid_types, true ) ? $types[ $i ] : 'text';
			$map  = isset( $maps[ $i ] ) && in_array( $maps[ $i ], $valid_maps, true ) ? $maps[ $i ] : 'none';

			// Derive a unique key from the provided key or the label.
			$key = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';
			if ( '' === $key ) {
				$key = sanitize_key( $label );
			}
			if ( '' === $key ) {
				$key = 'field';
			}
			$base = $key;
			$n    = 2;
			while ( isset( $used_keys[ $key ] ) ) {
				$key = $base . '_' . $n;
				++$n;
			}
			$used_keys[ $key ] = true;

			$fields[] = array(
				'key'          => $key,
				'label'        => $label,
				'type'         => $type,
				'required'     => ( isset( $required[ $i ] ) && 'yes' === $required[ $i ] ) ? 'yes' : 'no',
				'options'      => isset( $options[ $i ] ) ? sanitize_text_field( $options[ $i ] ) : '',
				'map_to'       => $map,
				'consent_text' => isset( $consents[ $i ] ) ? wp_kses_post( $consents[ $i ] ) : '',
			);
		}

		update_post_meta( $post_id, self::META_FIELDS, $fields );

		// Form specific submission messages. HTML links are allowed, like in the
		// consent text, so wp_kses_post is the right sanitizer here.
		$success_message    = isset( $_POST['pdlead_success_message'] ) ? wp_kses_post( wp_unslash( $_POST['pdlead_success_message'] ) ) : '';
		$error_message      = isset( $_POST['pdlead_error_message'] ) ? wp_kses_post( wp_unslash( $_POST['pdlead_error_message'] ) ) : '';
		$validation_message = isset( $_POST['pdlead_validation_message'] ) ? wp_kses_post( wp_unslash( $_POST['pdlead_validation_message'] ) ) : '';
		$sending_message    = isset( $_POST['pdlead_sending_message'] ) ? wp_kses_post( wp_unslash( $_POST['pdlead_sending_message'] ) ) : '';
		update_post_meta( $post_id, self::META_SUCCESS_MSG, $success_message );
		update_post_meta( $post_id, self::META_ERROR_MSG, $error_message );
		update_post_meta( $post_id, self::META_VALIDATION_MSG, $validation_message );
		update_post_meta( $post_id, self::META_SENDING_MSG, $sending_message );
	}
}
