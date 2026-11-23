<?php
/**
 * Per form email notifications: one email to the site owner and one
 * confirmation to the visitor, both written in the form editor with
 * placeholders for the submitted values and sent through wp_mail().
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, edits and sends the email notifications of a form.
 */
class Pdlead_Notifier {

	const META = '_pdlead_emails';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . PDLEAD_CPT, array( __CLASS__, 'save' ) );
	}

	/**
	 * The emails a form can send, keyed by type.
	 *
	 * @return array<string,string>
	 */
	private static function types() {
		return array(
			'admin' => __( 'Email to us', 'pipedrive-lead-forms' ),
			'user'  => __( 'Email to the visitor', 'pipedrive-lead-forms' ),
		);
	}

	/**
	 * Default settings of both emails. Both start disabled, so existing forms
	 * send nothing new until an email is switched on.
	 *
	 * @param array[] $fields Field definitions.
	 * @return array
	 */
	private static function defaults( $fields ) {
		$email = '{' . self::first_email_key( $fields ) . '}';
		return array(
			'admin' => array(
				'enabled'  => 0,
				'to'       => '',
				'reply_to' => $email,
				/* translators: {form_title} is an email placeholder, keep it unchanged. */
				'subject'  => __( 'New submission: {form_title}', 'pipedrive-lead-forms' ),
				'body'     => '{all_fields}',
			),
			'user'  => array(
				'enabled'  => 0,
				'to'       => $email,
				'reply_to' => '',
				'subject'  => __( 'Thank you for your message', 'pipedrive-lead-forms' ),
				/* translators: {all_fields} and {site_name} are email placeholders, keep them unchanged. */
				'body'     => __( "Hello,\n\nthank you for your message. We will get back to you as soon as possible.\n\nYour details:\n{all_fields}\n\nBest regards\n{site_name}", 'pipedrive-lead-forms' ),
			),
		);
	}

	/**
	 * Key of the first email field, used to prefill recipient and Reply-To.
	 *
	 * @param array[] $fields Field definitions.
	 * @return string
	 */
	private static function first_email_key( $fields ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['type'], $field['key'] ) && 'email' === $field['type'] ) {
				return $field['key'];
			}
		}
		return 'email';
	}

	/**
	 * Get the email settings of a form, merged with the defaults.
	 *
	 * @param int     $post_id Form post ID.
	 * @param array[] $fields  Field definitions.
	 * @return array
	 */
	public static function get( $post_id, $fields ) {
		$defaults = self::defaults( $fields );
		$stored   = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		foreach ( $defaults as $type => $values ) {
			$defaults[ $type ] = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? array_merge( $values, $stored[ $type ] ) : $values;
		}
		return $defaults;
	}

	/**
	 * Register the meta box below the field editor.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'pdlead_emails_box',
			__( 'Email notifications', 'pipedrive-lead-forms' ),
			array( __CLASS__, 'render_meta_box' ),
			PDLEAD_CPT,
			'normal',
			'default'
		);
	}

	/**
	 * Render the email editor.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'pdlead_save_emails', 'pdlead_emails_nonce' );

		$fields = Pdlead_Form_CPT::get_fields( $post->ID );
		$config = self::get( $post->ID, $fields );

		echo '<p class="description">' . esc_html__( 'Sent through the WordPress mailer as plain text right after a submission is saved. Variables are replaced in every field below, including the recipients.', 'pipedrive-lead-forms' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Available variables', 'pipedrive-lead-forms' ) . '</strong></p>';
		echo '<ul class="pdlead-email-vars">';
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) ) {
				continue;
			}
			echo '<li><code>{' . esc_html( $field['key'] ) . '}</code> ' . esc_html( isset( $field['label'] ) ? $field['label'] : '' ) . '</li>';
		}
		echo '<li><code>{all_fields}</code> ' . esc_html__( 'All fields the visitor filled in, one "Label: value" per line (without hidden and cookie value fields)', 'pipedrive-lead-forms' ) . '</li>';
		echo '<li><code>{form_title}</code> ' . esc_html__( 'Title of this form', 'pipedrive-lead-forms' ) . '</li>';
		echo '<li><code>{site_name}</code> ' . esc_html__( 'Name of this website', 'pipedrive-lead-forms' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Save the form to list newly added fields here.', 'pipedrive-lead-forms' ) . '</p>';

		foreach ( self::types() as $type => $title ) {
			$values = $config[ $type ];
			$name   = 'pdlead_emails[' . $type . ']';
			$id     = 'pdlead-email-' . $type;

			echo '<h2 class="pdlead-messages-title">' . esc_html( $title ) . '</h2>';
			if ( 'user' === $type ) {
				echo '<p class="description">' . esc_html__( 'Not sent for submissions flagged as suspicious or held back by the rate limit, so the form cannot be abused to send mail to strangers.', 'pipedrive-lead-forms' ) . '</p>';
			}

			echo '<table class="form-table pdlead-email-table" role="presentation"><tbody>';

			echo '<tr><th scope="row">' . esc_html__( 'Send', 'pipedrive-lead-forms' ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[enabled]" value="1" ' . checked( ! empty( $values['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Send this email after every submission', 'pipedrive-lead-forms' ) . '</label>';
			echo '</td></tr>';

			$to_placeholder = 'admin' === $type ? get_option( 'admin_email' ) : '{email}';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '-to">' . esc_html__( 'To', 'pipedrive-lead-forms' ) . '</label></th><td>';
			echo '<input type="text" id="' . esc_attr( $id ) . '-to" name="' . esc_attr( $name ) . '[to]" value="' . esc_attr( $values['to'] ) . '" class="regular-text" placeholder="' . esc_attr( $to_placeholder ) . '" />';
			if ( 'admin' === $type ) {
				echo '<p class="description">' . esc_html__( 'Separate several addresses with commas. Empty sends to the site admin email.', 'pipedrive-lead-forms' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Usually the variable of the email field, e.g. {email}.', 'pipedrive-lead-forms' ) . '</p>';
			}
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '-reply-to">' . esc_html__( 'Reply-To', 'pipedrive-lead-forms' ) . '</label></th><td>';
			echo '<input type="text" id="' . esc_attr( $id ) . '-reply-to" name="' . esc_attr( $name ) . '[reply_to]" value="' . esc_attr( $values['reply_to'] ) . '" class="regular-text" />';
			echo '<p class="description">' . esc_html__( 'Optional. Address used when the recipient clicks reply.', 'pipedrive-lead-forms' ) . '</p>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '-subject">' . esc_html__( 'Subject', 'pipedrive-lead-forms' ) . '</label></th><td>';
			echo '<input type="text" id="' . esc_attr( $id ) . '-subject" name="' . esc_attr( $name ) . '[subject]" value="' . esc_attr( $values['subject'] ) . '" class="large-text" />';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '-body">' . esc_html__( 'Message', 'pipedrive-lead-forms' ) . '</label></th><td>';
			echo '<textarea id="' . esc_attr( $id ) . '-body" name="' . esc_attr( $name ) . '[body]" rows="10" class="large-text">' . esc_textarea( $values['body'] ) . '</textarea>';
			echo '</td></tr>';

			echo '</tbody></table>';
		}
	}

	/**
	 * Persist the email settings.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['pdlead_emails_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pdlead_emails_nonce'] ) ), 'pdlead_save_emails' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitized per key below.
		$raw    = isset( $_POST['pdlead_emails'] ) && is_array( $_POST['pdlead_emails'] ) ? wp_unslash( $_POST['pdlead_emails'] ) : array();
		$config = array();
		foreach ( array_keys( self::types() ) as $type ) {
			$values = isset( $raw[ $type ] ) && is_array( $raw[ $type ] ) ? $raw[ $type ] : array();
			$text   = static function ( $key ) use ( $values ) {
				return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
			};

			$config[ $type ] = array(
				'enabled'  => '' !== $text( 'enabled' ) ? 1 : 0,
				'to'       => sanitize_text_field( $text( 'to' ) ),
				'reply_to' => sanitize_text_field( $text( 'reply_to' ) ),
				'subject'  => sanitize_text_field( $text( 'subject' ) ),
				'body'     => sanitize_textarea_field( $text( 'body' ) ),
			);
		}

		update_post_meta( $post_id, self::META, $config );
	}

	/**
	 * Send the enabled emails of a form for one submission.
	 *
	 * @param WP_Post $form    Form post.
	 * @param array[] $fields  Field definitions.
	 * @param array   $payload Sanitized submission values.
	 * @param bool    $flagged Whether the submission is flagged or held back.
	 */
	public static function send( $form, $fields, $payload, $flagged ) {
		$config = self::get( $form->ID, $fields );
		$vars   = self::variables( $form, $fields, $payload );

		foreach ( $config as $type => $email ) {
			if ( empty( $email['enabled'] ) ) {
				continue;
			}
			// A suspicious submission may carry a stranger's address.
			if ( 'user' === $type && $flagged ) {
				continue;
			}

			$to = self::addresses( strtr( $email['to'], $vars ) );
			if ( ! $to && 'admin' === $type ) {
				$to = self::addresses( get_option( 'admin_email' ) );
			}
			if ( ! $to ) {
				continue;
			}

			$headers  = array();
			$reply_to = self::addresses( strtr( $email['reply_to'], $vars ) );
			if ( $reply_to ) {
				$headers[] = 'Reply-To: ' . implode( ', ', $reply_to );
			}

			// Multi-line values must not break the subject line.
			$subject = trim( preg_replace( '/\s+/', ' ', strtr( $email['subject'], $vars ) ) );
			$body    = strtr( $email['body'], $vars );

			wp_mail( $to, $subject, $body, $headers );
		}
	}

	/**
	 * Build the placeholder map of a submission.
	 *
	 * @param WP_Post $form    Form post.
	 * @param array[] $fields  Field definitions.
	 * @param array   $payload Sanitized submission values.
	 * @return array<string,string>
	 */
	private static function variables( $form, $fields, $payload ) {
		$vars  = array(
			'{form_title}' => $form->post_title,
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$lines = array();

		foreach ( $fields as $field ) {
			$key = isset( $field['key'] ) ? $field['key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = self::format_value( $type, isset( $payload[ $key ] ) ? $payload[ $key ] : '' );

			$vars[ '{' . $key . '}' ] = $value;

			// Hidden and cookie values are internal and never shown to visitors.
			if ( '' !== $value && ! Pdlead_Form_CPT::is_server_value_type( $type ) ) {
				$label   = isset( $field['label'] ) ? $field['label'] : $key;
				$lines[] = $label . ': ' . $value;
			}
		}

		$vars['{all_fields}'] = implode( "\n", $lines );
		return $vars;
	}

	/**
	 * Turn a stored value into readable text.
	 *
	 * @param string       $type  Field type.
	 * @param string|array $value Stored value.
	 * @return string
	 */
	private static function format_value( $type, $value ) {
		if ( 'checkbox' === $type || 'consent' === $type ) {
			return '' !== $value ? __( 'Yes', 'pipedrive-lead-forms' ) : __( 'No', 'pipedrive-lead-forms' );
		}
		if ( is_array( $value ) ) {
			$names = array();
			foreach ( $value as $file ) {
				if ( is_array( $file ) && ! empty( $file['file'] ) ) {
					$names[] = ! empty( $file['name'] ) ? $file['name'] : wp_basename( $file['file'] );
				}
			}
			return implode( ', ', $names );
		}
		return (string) $value;
	}

	/**
	 * Split a list into valid, unique email addresses.
	 *
	 * @param string $raw Comma, semicolon or whitespace separated list.
	 * @return string[]
	 */
	private static function addresses( $raw ) {
		$list = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( $list, 'is_email' ) ) );
	}
}
