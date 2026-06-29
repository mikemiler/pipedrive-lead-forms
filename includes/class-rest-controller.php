<?php
/**
 * REST endpoints. The token endpoint is deliberately uncacheable so caching
 * plugins and CDNs never serve a stale nonce or timestamp.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the front-end REST routes.
 */
class Pdlead_Rest_Controller {

	const NAMESPACE = 'pdlead/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Issue a fresh, uncacheable token.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_token() {
		// Signal page cache plugins to never store this response.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the standard cache-plugin contract constant.
		}
		nocache_headers();

		$token    = Pdlead_Bot_Guard::issue_token();
		$response = new WP_REST_Response(
			array(
				'nonce' => $token['nonce'],
				'ts'    => $token['ts'],
				'sig'   => $token['sig'],
			)
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Handle a form submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function submit( WP_REST_Request $request ) {
		// Read from the POST body only, never the URL query.
		$params = $request->get_body_params();

		// Nonce check. For logged in users this is real CSRF protection. For
		// anonymous visitors a WP nonce is a rotating shared token, so the bot
		// defense relies on the bot guard below, not on this check.
		$nonce = isset( $params['pdlead_nonce'] ) ? sanitize_text_field( $params['pdlead_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, PDLEAD_NONCE_ACTION ) ) {
			return self::error( 'invalid_token', __( 'Your session expired. Please submit again.', 'pipedrive-lead-forms' ), 400 );
		}

		// Validate the target form.
		$form_id = isset( $params['form_id'] ) ? absint( $params['form_id'] ) : 0;
		$post    = $form_id ? get_post( $form_id ) : null;
		if ( ! $post || PDLEAD_CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::error( 'invalid_form', __( 'This form is not available.', 'pipedrive-lead-forms' ), 404 );
		}

		$fields = Pdlead_Form_CPT::get_fields( $form_id );
		if ( empty( $fields ) ) {
			return self::error( 'invalid_form', __( 'This form is not available.', 'pipedrive-lead-forms' ), 404 );
		}

		$ip      = Pdlead_Bot_Guard::client_ip();
		$verdict = Pdlead_Bot_Guard::evaluate( $params, $ip );

		// Silent drop for high confidence bot signals.
		if ( Pdlead_Bot_Guard::ACTION_DROP === $verdict['action'] ) {
			Pdlead_Submission_Store::insert(
				array(
					'form_id' => $form_id,
					'ip_hash' => Pdlead_Bot_Guard::ip_hash( $ip ),
					'payload' => self::collect_payload( $fields, $params ),
					'status'  => Pdlead_Submission_Store::STATUS_SPAM,
					'flagged' => true,
				)
			);
			// Pretend success so a bot learns nothing.
			return self::success( $form_id );
		}

		// Friendly errors (rate limit, captcha, expired token).
		if ( Pdlead_Bot_Guard::ACTION_ERROR === $verdict['action'] ) {
			$status = ( 'rate_limited' === $verdict['reason'] ) ? 429 : 403;
			if ( 'expired' === $verdict['reason'] ) {
				$status = 400;
			}
			return self::error( $verdict['reason'], $verdict['message'], $status );
		}

		// Validate and sanitize the submitted values.
		$payload = self::collect_payload( $fields, $params );

		// Store any uploaded files and merge their metadata into the payload.
		$uploads = self::process_uploads( $fields, $request->get_file_params() );
		foreach ( $uploads['files'] as $field_key => $list ) {
			$payload[ $field_key ] = $list;
		}

		$invalid = self::validate( $fields, $payload );
		if ( $uploads['error'] || ! empty( $invalid ) ) {
			// Remove just-stored files so a rejected submission leaves nothing behind.
			self::delete_payload_files( $payload );
			return self::error( 'validation', __( 'Please complete all required fields correctly.', 'pipedrive-lead-forms' ), 422, $form_id );
		}

		$flagged = ( Pdlead_Bot_Guard::ACTION_FLAG === $verdict['action'] );

		// Local first: persist before contacting Pipedrive so no lead is lost.
		$id = Pdlead_Submission_Store::insert(
			array(
				'form_id' => $form_id,
				'ip_hash' => Pdlead_Bot_Guard::ip_hash( $ip ),
				'payload' => $payload,
				'status'  => Pdlead_Submission_Store::STATUS_PENDING,
				'flagged' => $flagged,
			)
		);

		if ( ! $id ) {
			// The database write failed. Email the raw lead anyway so it is not
			// lost, regardless of the backup setting, then report the error.
			self::backup_email( $post, $fields, $payload, true );
			return self::error( 'store_failed', __( 'Could not save your submission. Please try again.', 'pipedrive-lead-forms' ), 500, $form_id );
		}

		// Backup email: a copy that does not depend on Pipedrive availability or
		// WP-Cron timing. Enabled by default so a lead is never lost.
		self::backup_email( $post, $fields, $payload, false );

		// Try to push immediately. On failure the row stays pending for retry.
		Pdlead_Lead_Dispatcher::dispatch( $id );

		return self::success( $form_id );
	}

	/**
	 * Collect and sanitize submitted field values keyed by field key.
	 *
	 * @param array $fields Field definitions.
	 * @param array $params Request params.
	 * @return array
	 */
	private static function collect_payload( $fields, $params ) {
		$incoming = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$payload  = array();

		foreach ( $fields as $field ) {
			$key  = isset( $field['key'] ) ? $field['key'] : '';
			$type = isset( $field['type'] ) ? $field['type'] : 'text';
			if ( '' === $key ) {
				continue;
			}

			$raw = isset( $incoming[ $key ] ) ? $incoming[ $key ] : '';

			switch ( $type ) {
				case 'email':
					$payload[ $key ] = sanitize_email( is_scalar( $raw ) ? $raw : '' );
					break;
				case 'textarea':
					$payload[ $key ] = sanitize_textarea_field( is_scalar( $raw ) ? $raw : '' );
					break;
				case 'checkbox':
				case 'consent':
					$payload[ $key ] = ( '' !== $raw && '0' !== $raw && false !== $raw ) ? '1' : '';
					break;
				case 'select':
					$value   = sanitize_text_field( is_scalar( $raw ) ? $raw : '' );
					$options = array_map( 'trim', explode( ',', isset( $field['options'] ) ? $field['options'] : '' ) );
					$payload[ $key ] = in_array( $value, $options, true ) ? $value : '';
					break;
				case 'file':
					// Files arrive via $_FILES, not the text params. The actual
					// stored metadata is merged in later (see process_uploads).
					$payload[ $key ] = array();
					break;
				default:
					$payload[ $key ] = sanitize_text_field( is_scalar( $raw ) ? $raw : '' );
					break;
			}
		}

		return $payload;
	}

	/**
	 * Store uploaded files for every file field and return their metadata.
	 *
	 * @param array $fields Field definitions.
	 * @param array $files  The request file params ($_FILES).
	 * @return array { files: array<string,array[]>, error: bool }
	 */
	private static function process_uploads( $fields, $files ) {
		$group  = isset( $files['fields'] ) ? $files['fields'] : array();
		$result = array(
			'files' => array(),
			'error' => false,
		);

		foreach ( $fields as $field ) {
			$key  = isset( $field['key'] ) ? $field['key'] : '';
			$type = isset( $field['type'] ) ? $field['type'] : 'text';
			if ( '' === $key || 'file' !== $type ) {
				continue;
			}

			$handled = Pdlead_File_Store::handle_field_files( $key, $group );
			if ( $handled['error'] ) {
				$result['error'] = true;
			}
			$result['files'][ $key ] = $handled['files'];
		}

		return $result;
	}

	/**
	 * Delete every stored file referenced by a payload. Used to clean up after
	 * a rejected submission so no orphaned files remain.
	 *
	 * @param array $payload Sanitized values.
	 */
	private static function delete_payload_files( $payload ) {
		foreach ( $payload as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			foreach ( $value as $file ) {
				if ( is_array( $file ) && ! empty( $file['file'] ) ) {
					Pdlead_File_Store::delete( $file['file'] );
				}
			}
		}
	}

	/**
	 * Validate required fields and email format.
	 *
	 * @param array $fields  Field definitions.
	 * @param array $payload Sanitized values.
	 * @return array List of invalid field keys.
	 */
	private static function validate( $fields, $payload ) {
		$invalid = array();

		foreach ( $fields as $field ) {
			$key      = isset( $field['key'] ) ? $field['key'] : '';
			$type     = isset( $field['type'] ) ? $field['type'] : 'text';
			$required = ( isset( $field['required'] ) && 'yes' === $field['required'] );
			$value    = isset( $payload[ $key ] ) ? $payload[ $key ] : '';

			$is_empty = is_array( $value ) ? empty( $value ) : ( '' === $value );
			if ( $required && $is_empty ) {
				$invalid[] = $key;
				continue;
			}
			if ( 'email' === $type && ! is_array( $value ) && '' !== $value && ! is_email( $value ) ) {
				$invalid[] = $key;
			}
		}

		return $invalid;
	}

	/**
	 * Send a backup copy of the raw submission to the admin.
	 *
	 * @param WP_Post $form    Form post.
	 * @param array   $fields  Field definitions.
	 * @param array   $payload Sanitized values.
	 * @param bool    $force   Always send, ignoring the setting (used when the
	 *                         database write failed and this is the only copy).
	 */
	private static function backup_email( $form, $fields, $payload, $force ) {
		if ( ! $force && ! Pdlead_Settings::get( 'backup_on_every_submit' ) ) {
			return;
		}
		$to = Pdlead_Settings::get( 'backup_email' );
		if ( '' === $to ) {
			$to = get_option( 'admin_email' );
		}
		if ( '' === $to ) {
			return;
		}

		$lines       = array();
		$attachments = array();
		foreach ( $fields as $field ) {
			$key   = isset( $field['key'] ) ? $field['key'] : '';
			$label = isset( $field['label'] ) ? $field['label'] : $key;
			$value = isset( $payload[ $key ] ) ? $payload[ $key ] : '';

			// File fields: list the file names and attach the stored copies.
			if ( is_array( $value ) ) {
				$names = array();
				foreach ( $value as $file ) {
					if ( is_array( $file ) && ! empty( $file['file'] ) ) {
						$names[]       = ! empty( $file['name'] ) ? $file['name'] : wp_basename( $file['file'] );
						$attachments[] = Pdlead_File_Store::abs_path( $file['file'] );
					}
				}
				if ( $names ) {
					$lines[] = $label . ': ' . implode( ', ', $names );
				}
				continue;
			}

			if ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		/* translators: %s: form title. */
		$subject = sprintf( __( 'New lead: %s', 'pipedrive-lead-forms' ), $form->post_title );
		$body    = implode( "\n", $lines );

		wp_mail( $to, $subject, $body, '', $attachments );
	}

	/**
	 * Build a success response. Uses the form specific message when one is set,
	 * otherwise the built-in default.
	 *
	 * @param int $form_id Form post ID.
	 * @return WP_REST_Response
	 */
	private static function success( $form_id = 0 ) {
		$message = $form_id ? Pdlead_Form_CPT::get_success_message( $form_id ) : '';
		if ( '' === $message ) {
			$message = __( 'Thank you. Your message has been sent.', 'pipedrive-lead-forms' );
		}
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $message,
			),
			200
		);
	}

	/**
	 * Build an error response. The form specific text overrides the default for
	 * the generic server error and the validation error when one is set. The
	 * remaining messages (rate limit, expired session, unavailable form) are
	 * kept as is so the visitor still gets actionable guidance.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @param int    $form_id Form post ID.
	 * @return WP_REST_Response
	 */
	private static function error( $code, $message, $status, $form_id = 0 ) {
		if ( $form_id ) {
			$custom = '';
			if ( 'store_failed' === $code ) {
				$custom = Pdlead_Form_CPT::get_error_message( $form_id );
			} elseif ( 'validation' === $code ) {
				$custom = Pdlead_Form_CPT::get_validation_message( $form_id );
			}
			if ( '' !== $custom ) {
				$message = $custom;
			}
		}
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
