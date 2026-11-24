<?php
/**
 * Per form integrations box (Pipedrive switch and custom endpoint) and the
 * custom endpoint delivery: the submission is sent as JSON, built from a
 * template with variables, to any HTTP API.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, edits, previews and sends the custom endpoint request of a form.
 */
class Pdlead_Webhook {

	const META = '_pdlead_webhook';

	/**
	 * Supported HTTP methods. GET sends no body.
	 */
	const METHODS = array( 'POST', 'PUT', 'PATCH', 'GET' );

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Maximum stored length of an endpoint response, in characters.
	 */
	const RESPONSE_MAX_LENGTH = 10000;

	/**
	 * Number of real submissions offered as preview data.
	 */
	const PREVIEW_SUBMISSIONS = 20;

	/**
	 * Characters allowed in a variable name, e.g. "email" or "cookie:utm_source".
	 */
	const VAR_NAME = '[A-Za-z0-9_:.-]+';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . PDLEAD_CPT, array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_pdlead_webhook_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_pdlead_webhook_test', array( __CLASS__, 'ajax_test' ) );
	}

	/**
	 * Get the endpoint settings of a form, merged with the defaults. The
	 * endpoint starts disabled, so existing forms send nothing new.
	 *
	 * @param int $post_id Form post ID.
	 * @return array { enabled: int, url: string, method: string, headers: string, body: string }
	 */
	public static function get( $post_id ) {
		$defaults = array(
			'enabled' => 0,
			'url'     => '',
			'method'  => 'POST',
			'headers' => '',
			'body'    => '',
		);
		$stored   = get_post_meta( $post_id, self::META, true );
		return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
	}

	/**
	 * Whether the custom endpoint of a form is switched on.
	 *
	 * @param int $post_id Form post ID.
	 * @return bool
	 */
	public static function is_enabled( $post_id ) {
		$config = self::get( $post_id );
		return ! empty( $config['enabled'] );
	}

	/**
	 * Register the meta box below the email notifications.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'pdlead_integrations_box',
			__( 'Integrations', 'pipedrive-lead-forms' ),
			array( __CLASS__, 'render_meta_box' ),
			PDLEAD_CPT,
			'normal',
			'default'
		);
	}

	/**
	 * Render the integrations box: Pipedrive switch and custom endpoint.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'pdlead_save_integrations', 'pdlead_integrations_nonce' );

		$fields = Pdlead_Form_CPT::get_fields( $post->ID );
		$stored = get_post_meta( $post->ID, self::META, true );
		$config = self::get( $post->ID );
		if ( ! is_array( $stored ) ) {
			// A starting point for a new endpoint: every field under its own key.
			$config['body'] = self::default_body( $fields );
		}
		$error = self::config_error( $config );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Pipedrive', 'pipedrive-lead-forms' ) . '</th><td>';
		echo '<label><input type="checkbox" name="pdlead_pipedrive_enabled" value="1" ' . checked( Pdlead_Form_CPT::pipedrive_enabled( $post->ID ), true, false ) . ' /> ' . esc_html__( 'Send submissions to Pipedrive', 'pipedrive-lead-forms' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When switched off, the field mapping is kept but nothing is sent to Pipedrive.', 'pipedrive-lead-forms' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 class="pdlead-messages-title">' . esc_html__( 'Custom endpoint', 'pipedrive-lead-forms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Sends every submission as JSON to your own API, right after it is saved. Failed requests are retried like Pipedrive leads, and the submission log shows what was sent and what the endpoint answered.', 'pipedrive-lead-forms' ) . '</p>';

		if ( '' !== $error ) {
			echo '<p class="pdlead-error">' . esc_html( $error ) . ' ' . esc_html__( 'The endpoint stays switched off until this is fixed.', 'pipedrive-lead-forms' ) . '</p>';
		}

		$name = 'pdlead_webhook';
		echo '<table class="form-table pdlead-webhook-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Send', 'pipedrive-lead-forms' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[enabled]" value="1" ' . checked( ! empty( $config['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Send every submission to this endpoint', 'pipedrive-lead-forms' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pdlead-webhook-url">' . esc_html__( 'URL', 'pipedrive-lead-forms' ) . '</label></th><td>';
		echo '<input type="text" id="pdlead-webhook-url" name="' . esc_attr( $name ) . '[url]" value="' . esc_attr( $config['url'] ) . '" class="large-text code" placeholder="https://api.example.com/leads" />';
		echo '<p class="description">' . esc_html__( 'Variables are allowed and URL encoded, e.g. https://api.example.com/leads?email={email}.', 'pipedrive-lead-forms' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pdlead-webhook-method">' . esc_html__( 'Method', 'pipedrive-lead-forms' ) . '</label></th><td>';
		echo '<select id="pdlead-webhook-method" name="' . esc_attr( $name ) . '[method]">';
		foreach ( self::METHODS as $method ) {
			echo '<option value="' . esc_attr( $method ) . '" ' . selected( $config['method'], $method, false ) . '>' . esc_html( $method ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'GET sends no body; put the variables into the URL instead.', 'pipedrive-lead-forms' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pdlead-webhook-headers">' . esc_html__( 'Headers', 'pipedrive-lead-forms' ) . '</label></th><td>';
		echo '<textarea id="pdlead-webhook-headers" name="' . esc_attr( $name ) . '[headers]" rows="3" class="large-text code" placeholder="Authorization: Bearer your-token">' . esc_textarea( $config['headers'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional. One header per line as "Name: value", e.g. for an API key. Content-Type: application/json is added automatically. Headers are never written to the submission log.', 'pipedrive-lead-forms' ) . '</p>';
		echo '</td></tr>';

		echo '<tr class="pdlead-webhook-body-row"><th scope="row"><label for="pdlead-webhook-body">' . esc_html__( 'JSON body', 'pipedrive-lead-forms' ) . '</label></th><td>';
		echo '<textarea id="pdlead-webhook-body" name="' . esc_attr( $name ) . '[body]" rows="12" class="large-text code">' . esc_textarea( $config['body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Write the JSON the endpoint expects. Fixed values are plain JSON ("source": "website", "priority": 2). Variables go inside strings ("email": "{email}"). A string that is only one variable takes its typed value: checkboxes become true or false, file fields a list of file names. Values are escaped automatically.', 'pipedrive-lead-forms' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Variables', 'pipedrive-lead-forms' ) . '</th><td>';
		self::render_variables( $fields );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pdlead-webhook-source">' . esc_html__( 'Preview', 'pipedrive-lead-forms' ) . '</label></th><td>';
		self::render_preview( $post->ID, $fields );
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Render the list of available variables.
	 *
	 * @param array[] $fields Field definitions.
	 */
	private static function render_variables( $fields ) {
		echo '<ul class="pdlead-email-vars">';
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) ) {
				continue;
			}
			echo '<li><code>{' . esc_html( $field['key'] ) . '}</code> ' . esc_html( isset( $field['label'] ) ? $field['label'] : '' ) . '</li>';
		}
		$cookies = Pdlead_Settings::cookie_names();
		$example = $cookies ? reset( $cookies ) : 'utm_source';
		/* translators: %s: example variable such as {cookie:utm_source}. */
		echo '<li><code>{cookie:name}</code> ' . esc_html( sprintf( __( 'Value of a cookie listed in the settings, e.g. %s', 'pipedrive-lead-forms' ), '{cookie:' . $example . '}' ) ) . '</li>';
		echo '<li><code>{submission_id}</code> ' . esc_html__( 'ID of the submission in the log', 'pipedrive-lead-forms' ) . '</li>';
		echo '<li><code>{submitted_at}</code> ' . esc_html__( 'Date and time of the submission (ISO 8601, UTC)', 'pipedrive-lead-forms' ) . '</li>';
		echo '<li><code>{form_id}</code> <code>{form_title}</code> ' . esc_html__( 'ID and title of this form', 'pipedrive-lead-forms' ) . '</li>';
		echo '<li><code>{site_url}</code> ' . esc_html__( 'Address of this website', 'pipedrive-lead-forms' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Save the form to list newly added fields here and to use them in the preview.', 'pipedrive-lead-forms' ) . '</p>';
	}

	/**
	 * Render the preview controls. The script posts the unsaved editor values,
	 * so the preview always shows what is currently typed in.
	 *
	 * @param int     $post_id Form post ID.
	 * @param array[] $fields  Field definitions.
	 */
	private static function render_preview( $post_id, $fields ) {
		echo '<div class="pdlead-webhook-preview" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'pdlead_webhook' ) ) . '" data-post-id="' . esc_attr( $post_id ) . '"';
		echo ' data-confirm="' . esc_attr__( 'Really send a test request to this URL?', 'pipedrive-lead-forms' ) . '"';
		echo ' data-unknown="' . esc_attr__( 'Unknown variables, sent as empty text:', 'pipedrive-lead-forms' ) . '"';
		echo ' data-error="' . esc_attr__( 'The request failed. Please reload the page and try again.', 'pipedrive-lead-forms' ) . '">';

		echo '<select id="pdlead-webhook-source">';
		echo '<option value="0">' . esc_html__( 'Sample data', 'pipedrive-lead-forms' ) . '</option>';
		foreach ( Pdlead_Submission_Store::recent_for_form( $post_id, self::PREVIEW_SUBMISSIONS ) as $row ) {
			echo '<option value="' . esc_attr( $row['id'] ) . '">' . esc_html( self::submission_label( $row, $fields ) ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="button" class="button pdlead-webhook-run" data-action="pdlead_webhook_preview">' . esc_html__( 'Preview', 'pipedrive-lead-forms' ) . '</button> ';
		echo '<button type="button" class="button pdlead-webhook-run" data-action="pdlead_webhook_test">' . esc_html__( 'Send test request', 'pipedrive-lead-forms' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Preview shows the request built from sample data or a real submission and sends nothing. Send test request really sends it and shows the answer; the submission itself is not changed.', 'pipedrive-lead-forms' ) . '</p>';
		echo '<div class="pdlead-webhook-result" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Label of a submission in the preview source list.
	 *
	 * @param array   $row    Submission row ( id, created_at, status, payload ).
	 * @param array[] $fields Field definitions.
	 * @return string
	 */
	private static function submission_label( $row, $fields ) {
		$payload = json_decode( $row['payload'], true );
		$parts   = array(
			'#' . $row['id'],
			get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ),
			$row['status'],
		);
		foreach ( $fields as $field ) {
			if ( isset( $field['type'], $field['key'] ) && 'email' === $field['type'] && ! empty( $payload[ $field['key'] ] ) && is_string( $payload[ $field['key'] ] ) ) {
				$parts[] = $payload[ $field['key'] ];
				break;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Template prefilled for a form without endpoint settings.
	 *
	 * @param array[] $fields Field definitions.
	 * @return string
	 */
	private static function default_body( $fields ) {
		$data = array( 'form' => '{form_title}' );
		foreach ( $fields as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$data[ $field['key'] ] = '{' . $field['key'] . '}';
			}
		}
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Persist the Pipedrive switch and the endpoint settings.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['pdlead_integrations_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pdlead_integrations_nonce'] ) ), 'pdlead_save_integrations' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Pdlead_Form_CPT::META_PIPEDRIVE, isset( $_POST['pdlead_pipedrive_enabled'] ) ? '1' : '0' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key in sanitize_config().
		$raw    = isset( $_POST['pdlead_webhook'] ) && is_array( $_POST['pdlead_webhook'] ) ? wp_unslash( $_POST['pdlead_webhook'] ) : array();
		$config = self::sanitize_config( $raw );

		// An endpoint that cannot be sent stays off; the box names the problem.
		if ( '' !== self::config_error( $config ) ) {
			$config['enabled'] = 0;
		}

		update_post_meta( $post_id, self::META, $config );
	}

	/**
	 * Sanitize raw endpoint settings from the editor.
	 *
	 * URL, headers and body are not run through sanitize_text_field(): it
	 * strips percent encoded octets, which breaks encoded URLs and JSON. They
	 * are only ever printed escaped and sent to the endpoint.
	 *
	 * @param array $raw Unslashed values.
	 * @return array
	 */
	private static function sanitize_config( $raw ) {
		$text = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? wp_check_invalid_utf8( (string) $raw[ $key ] ) : '';
		};

		$method = strtoupper( $text( 'method' ) );

		return array(
			'enabled' => '' !== $text( 'enabled' ) ? 1 : 0,
			// No whitespace or control characters inside a URL.
			'url'     => preg_replace( '/[\x00-\x20\x7f]/', '', $text( 'url' ) ),
			'method'  => in_array( $method, self::METHODS, true ) ? $method : 'POST',
			// Keep line breaks, drop every other control character.
			'headers' => preg_replace( '/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', '', $text( 'headers' ) ),
			'body'    => preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text( 'body' ) ),
		);
	}

	/**
	 * Why a configuration cannot be sent, or '' when it can.
	 *
	 * @param array $config Endpoint settings.
	 * @return string
	 */
	private static function config_error( $config ) {
		if ( '' === $config['url'] && empty( $config['enabled'] ) ) {
			return ''; // Not configured yet.
		}
		if ( ! preg_match( '#^https?://[^/?\#]+#i', $config['url'] ) ) {
			return __( 'The endpoint URL must start with http:// or https://.', 'pipedrive-lead-forms' );
		}
		if ( 'GET' !== $config['method'] ) {
			return self::body_error( $config['body'] );
		}
		return '';
	}

	/**
	 * Why a JSON template is not usable, or '' when it is.
	 *
	 * @param string $body JSON template.
	 * @return string
	 */
	private static function body_error( $body ) {
		if ( '' === trim( $body ) ) {
			return __( 'The JSON body is empty.', 'pipedrive-lead-forms' );
		}
		json_decode( $body );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %s: JSON parser error message. */
			return sprintf( __( 'The JSON body is not valid JSON: %s.', 'pipedrive-lead-forms' ), json_last_error_msg() );
		}
		return '';
	}

	/**
	 * Build the request of one submission.
	 *
	 * The template is decoded and variables are replaced inside its string
	 * values only, then the result is encoded again. Replacing on the raw text
	 * would break the JSON as soon as a value contains a quote or line break,
	 * and a value can never inject a variable or JSON of its own.
	 *
	 * @param array   $config  Endpoint settings.
	 * @param WP_Post $form    Form post.
	 * @param array[] $fields  Field definitions.
	 * @param array   $payload Stored submission values.
	 * @param array   $meta    Submission meta ( id: int, created_at: UTC "Y-m-d H:i:s" ).
	 * @return array { method, url, headers: array, data: mixed, body: string|null, unknown: string[], error: string }
	 */
	public static function build( $config, $form, $fields, $payload, $meta ) {
		$vars    = self::variables( $form, $fields, $payload, $meta );
		$unknown = array();

		$url = preg_replace_callback(
			'/\{(' . self::VAR_NAME . ')\}/',
			static function ( $m ) use ( $vars, &$unknown ) {
				return rawurlencode( self::to_text( self::lookup( $m[1], $vars, $unknown ) ) );
			},
			$config['url']
		);

		$request = array(
			'method'  => $config['method'],
			'url'     => $url,
			'headers' => self::headers( $config['headers'], 'GET' !== $config['method'] ),
			'data'    => null,
			'body'    => null,
			'unknown' => array(),
			'error'   => self::config_error( array_merge( $config, array( 'enabled' => 1 ) ) ),
		);

		if ( '' === $request['error'] && 'GET' !== $config['method'] ) {
			$request['data'] = self::fill( json_decode( $config['body'] ), $vars, $unknown );
			$request['body'] = (string) wp_json_encode( $request['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$request['unknown'] = array_values( array_unique( $unknown ) );
		return $request;
	}

	/**
	 * Request headers: defaults plus the configured lines. A configured header
	 * replaces a default of the same name.
	 *
	 * @param string $raw      Configured headers, one "Name: value" per line.
	 * @param bool   $has_body Whether the request carries a JSON body.
	 * @return array<string,string>
	 */
	private static function headers( $raw, $has_body ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( $has_body ) {
			$headers['Content-Type'] = 'application/json';
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$parts = explode( ':', $line, 2 );
			$name  = trim( $parts[0] );
			// Header names are RFC 7230 tokens; anything else is skipped.
			if ( 2 !== count( $parts ) || ! preg_match( "/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/", $name ) ) {
				continue;
			}
			foreach ( array_keys( $headers ) as $existing ) {
				if ( 0 === strcasecmp( $existing, $name ) ) {
					unset( $headers[ $existing ] );
				}
			}
			$headers[ $name ] = trim( $parts[1] );
		}
		return $headers;
	}

	/**
	 * Variable values of a submission, typed for JSON.
	 *
	 * @param WP_Post $form    Form post.
	 * @param array[] $fields  Field definitions.
	 * @param array   $payload Stored submission values.
	 * @param array   $meta    Submission meta ( id, created_at ).
	 * @return array<string,mixed> Values keyed by variable name without braces.
	 */
	private static function variables( $form, $fields, $payload, $meta ) {
		$created = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $meta['created_at'], new DateTimeZone( 'UTC' ) );

		$vars = array(
			'form_id'       => (int) $form->ID,
			'form_title'    => $form->post_title,
			'submission_id' => (int) $meta['id'],
			'submitted_at'  => $created ? $created->format( 'c' ) : '',
			'site_url'      => home_url( '/' ),
		);

		foreach ( $fields as $field ) {
			$key = isset( $field['key'] ) ? $field['key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $payload[ $key ] ) ? $payload[ $key ] : '';

			if ( 'checkbox' === $type || 'consent' === $type ) {
				$value = '' !== $value && '0' !== $value;
			} elseif ( is_array( $value ) ) {
				$names = array();
				foreach ( $value as $file ) {
					if ( is_array( $file ) && ! empty( $file['file'] ) ) {
						$names[] = ! empty( $file['name'] ) ? $file['name'] : wp_basename( $file['file'] );
					}
				}
				$value = $names;
			} else {
				$value = (string) $value;
			}
			$vars[ $key ] = $value;
		}

		$cookies = isset( $payload[ Pdlead_Form_CPT::COOKIES_KEY ] ) && is_array( $payload[ Pdlead_Form_CPT::COOKIES_KEY ] ) ? $payload[ Pdlead_Form_CPT::COOKIES_KEY ] : array();
		foreach ( $cookies as $name => $value ) {
			$vars[ 'cookie:' . $name ] = (string) $value;
		}

		return $vars;
	}

	/**
	 * Replace the variables in every string of a decoded template.
	 *
	 * @param mixed    $node    Decoded JSON node.
	 * @param array    $vars    Variable values.
	 * @param string[] $unknown Collects variable names that do not exist.
	 * @return mixed
	 */
	private static function fill( $node, $vars, &$unknown ) {
		if ( is_string( $node ) ) {
			// A string that is exactly one variable keeps the typed value.
			if ( preg_match( '/^\{(' . self::VAR_NAME . ')\}$/', $node, $m ) ) {
				return self::lookup( $m[1], $vars, $unknown );
			}
			return preg_replace_callback(
				'/\{(' . self::VAR_NAME . ')\}/',
				static function ( $m ) use ( $vars, &$unknown ) {
					return self::to_text( self::lookup( $m[1], $vars, $unknown ) );
				},
				$node
			);
		}
		if ( is_array( $node ) || is_object( $node ) ) {
			foreach ( $node as $key => $child ) {
				if ( is_object( $node ) ) {
					$node->$key = self::fill( $child, $vars, $unknown );
				} else {
					$node[ $key ] = self::fill( $child, $vars, $unknown );
				}
			}
		}
		return $node;
	}

	/**
	 * Value of one variable. A cookie that was not set is an empty string; any
	 * other unknown name is collected, so the preview can point out typos.
	 *
	 * @param string   $name    Variable name without braces.
	 * @param array    $vars    Variable values.
	 * @param string[] $unknown Collects unknown names.
	 * @return mixed
	 */
	private static function lookup( $name, $vars, &$unknown ) {
		if ( array_key_exists( $name, $vars ) ) {
			return $vars[ $name ];
		}
		if ( 0 !== strpos( $name, 'cookie:' ) ) {
			$unknown[] = '{' . $name . '}';
		}
		return '';
	}

	/**
	 * A typed value as text, for variables inside a longer string or a URL.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function to_text( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}
		return (string) $value;
	}

	/**
	 * Send a built request.
	 *
	 * Redirects are not followed: a moved endpoint shows up as an error instead
	 * of silently losing the body on the redirect.
	 *
	 * @param array $request Built request.
	 * @return array { ok: bool, retryable: bool, error: string, status_code: int, response_body: string, duration_ms: int }
	 */
	public static function send( $request ) {
		$args = array(
			'method'      => $request['method'],
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'user-agent'  => 'Pipedrive Lead Forms/' . PDLEAD_VERSION . '; ' . home_url( '/' ),
			'headers'     => $request['headers'],
		);
		if ( null !== $request['body'] ) {
			$args['body'] = $request['body'];
		}

		$start    = microtime( true );
		$response = wp_remote_request( $request['url'], $args );
		$result   = array(
			'ok'            => false,
			'retryable'     => false,
			'error'         => '',
			'status_code'   => 0,
			'response_body' => '',
			'duration_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);

		if ( is_wp_error( $response ) ) {
			// Network level failure is transient: allow a retry.
			$result['retryable'] = true;
			/* translators: %s: transport error message. */
			$result['error'] = sprintf( __( 'Request failed: %s', 'pipedrive-lead-forms' ), $response->get_error_message() );
			return $result;
		}

		$status                  = (int) wp_remote_retrieve_response_code( $response );
		$result['status_code']   = $status;
		$result['response_body'] = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, self::RESPONSE_MAX_LENGTH );

		if ( $status >= 200 && $status < 300 ) {
			$result['ok'] = true;
			return $result;
		}

		$result['retryable'] = ( 408 === $status || 429 === $status || $status >= 500 );
		if ( $status >= 300 && $status < 400 ) {
			$result['error'] = sprintf(
				/* translators: 1: HTTP status code, 2: redirect target URL. */
				__( 'The endpoint redirected (HTTP %1$d) to %2$s. Enter the final URL.', 'pipedrive-lead-forms' ),
				$status,
				(string) wp_remote_retrieve_header( $response, 'location' )
			);
		} else {
			/* translators: %d: HTTP status code. */
			$result['error'] = sprintf( __( 'The endpoint responded with HTTP %d.', 'pipedrive-lead-forms' ), $status );
		}
		return $result;
	}

	/**
	 * Deliver a submission to the custom endpoint of its form and log the
	 * attempt on the row. Called by the dispatcher.
	 *
	 * @param array $row     Submission row.
	 * @param array $payload Decoded submission values.
	 * @return array|null { ok, retryable, error }, or null when there is nothing to do.
	 */
	public static function deliver( $row, $payload ) {
		$form_id = (int) $row['form_id'];
		$config  = self::get( $form_id );
		$form    = get_post( $form_id );
		if ( empty( $config['enabled'] ) || ! $form || 'sent' === $row['webhook_status'] ) {
			return null;
		}

		$request = self::build(
			$config,
			$form,
			Pdlead_Form_CPT::get_fields( $form_id ),
			$payload,
			array(
				'id'         => (int) $row['id'],
				'created_at' => $row['created_at'],
			)
		);

		if ( '' !== $request['error'] ) {
			$result = array(
				'ok'            => false,
				'retryable'     => false,
				'error'         => $request['error'],
				'status_code'   => 0,
				'response_body' => '',
				'duration_ms'   => 0,
			);
		} else {
			$result = self::send( $request );
		}

		// Headers are left out on purpose, they usually carry a token.
		$log = array(
			'at'            => gmdate( 'c' ),
			'method'        => $request['method'],
			'url'           => $request['url'],
			'request_body'  => $request['body'],
			'status_code'   => $result['status_code'],
			'response_body' => $result['response_body'],
			'error'         => $result['error'],
			'duration_ms'   => $result['duration_ms'],
		);
		Pdlead_Submission_Store::update(
			(int) $row['id'],
			array(
				'webhook_status' => $result['ok'] ? 'sent' : 'failed',
				'webhook_log'    => wp_json_encode( $log ),
			)
		);

		return array(
			'ok'        => $result['ok'],
			'retryable' => $result['retryable'],
			'error'     => $result['error'],
		);
	}

	/**
	 * Ajax: build the request from the unsaved editor values and return it
	 * without sending anything.
	 */
	public static function ajax_preview() {
		$request = self::ajax_build();
		wp_send_json_success( self::preview_data( $request ) );
	}

	/**
	 * Ajax: build the request like the preview and really send it. The chosen
	 * submission is not changed and no row is stored.
	 */
	public static function ajax_test() {
		$request = self::ajax_build();
		$data    = self::preview_data( $request );
		if ( '' === $request['error'] ) {
			$data['response'] = self::send( $request );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Check the ajax request and build the endpoint request from the posted
	 * editor values and the chosen data source.
	 *
	 * @return array Built request.
	 */
	private static function ajax_build() {
		check_ajax_referer( 'pdlead_webhook', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$form    = $post_id ? get_post( $post_id ) : null;
		if ( ! $form || PDLEAD_CPT !== $form->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) ), 403 );
		}

		$config = self::sanitize_config(
			array(
				'url'     => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_config().
				'method'  => isset( $_POST['method'] ) ? wp_unslash( $_POST['method'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_config().
				'headers' => isset( $_POST['headers'] ) ? wp_unslash( $_POST['headers'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_config().
				'body'    => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_config().
			)
		);
		$fields = Pdlead_Form_CPT::get_fields( $post_id );
		$source = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;

		if ( $source ) {
			$row = Pdlead_Submission_Store::get( $source );
			if ( ! $row || (int) $row['form_id'] !== $post_id || Pdlead_Submission_Store::STATUS_SPAM === $row['status'] ) {
				wp_send_json_error( array( 'message' => __( 'This submission is not available.', 'pipedrive-lead-forms' ) ), 404 );
			}
			$payload = json_decode( $row['payload'], true );
			$meta    = array(
				'id'         => (int) $row['id'],
				'created_at' => $row['created_at'],
			);
		} else {
			$payload = self::sample_payload( $fields, $config['url'] . $config['body'] );
			$meta    = array(
				'id'         => 123,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		return self::build( $config, $form, $fields, is_array( $payload ) ? $payload : array(), $meta );
	}

	/**
	 * The parts of a built request shown in the editor. The body is pretty
	 * printed; the request itself sends it compact.
	 *
	 * @param array $request Built request.
	 * @return array
	 */
	private static function preview_data( $request ) {
		return array(
			'method'  => $request['method'],
			'url'     => $request['url'],
			'body'    => null === $request['body'] ? '' : (string) wp_json_encode( $request['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'unknown' => $request['unknown'],
			'error'   => $request['error'],
		);
	}

	/**
	 * Sample values for the preview, shaped like a stored submission.
	 *
	 * @param array[] $fields   Field definitions.
	 * @param string  $template URL and body template, scanned for cookie variables.
	 * @return array
	 */
	private static function sample_payload( $fields, $template ) {
		$payload = array();
		foreach ( $fields as $field ) {
			$key = isset( $field['key'] ) ? $field['key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$type    = isset( $field['type'] ) ? $field['type'] : 'text';
			$options = isset( $field['options'] ) ? (string) $field['options'] : '';
			$label   = isset( $field['label'] ) ? $field['label'] : $key;

			switch ( $type ) {
				case 'email':
					$value = 'jane.doe@example.com';
					break;
				case 'tel':
					$value = '+49 30 1234567';
					break;
				case 'textarea':
					// Quote, umlaut and line break, so the escaping is visible.
					$value = "First line with \"quotes\"\nSecond line: Grüße";
					break;
				case 'select':
					$choices = array_map( 'trim', explode( ',', $options ) );
					$value   = $choices[0];
					break;
				case 'checkbox':
				case 'consent':
					$value = '1';
					break;
				case 'file':
					$value = array(
						array(
							'file' => 'sample.pdf',
							'name' => 'sample.pdf',
						),
					);
					break;
				case 'hidden':
					$value = $options;
					break;
				case 'cookie':
					$value = 'sample_' . $options;
					break;
				default:
					/* translators: %s: field label. */
					$value = sprintf( __( 'Sample %s', 'pipedrive-lead-forms' ), $label );
					break;
			}
			$payload[ $key ] = $value;
		}

		$names = Pdlead_Settings::cookie_names();
		if ( preg_match_all( '/\{cookie:(' . self::VAR_NAME . ')\}/', $template, $m ) ) {
			$names = array_merge( $names, $m[1] );
		}
		foreach ( array_unique( $names ) as $name ) {
			$payload[ Pdlead_Form_CPT::COOKIES_KEY ][ $name ] = 'sample_' . $name;
		}

		return $payload;
	}
}
