<?php
/**
 * Thin Pipedrive API v1 client using the API token (x-api-token header).
 * Classifies failures into retryable (429, 5xx, network) and permanent (4xx).
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal wrapper around the Pipedrive REST API.
 */
class Pdlead_Pipedrive_Client {

	/**
	 * Optional API token override.
	 *
	 * @var string|null
	 */
	private $token_override;

	/**
	 * Optional API base URL override.
	 *
	 * @var string|null
	 */
	private $api_base_override;

	/**
	 * The overrides let the settings sanitizer validate against the token and
	 * company domain being saved before they are persisted; every other caller
	 * uses the stored settings via the null defaults.
	 *
	 * @param string|null $token    Optional API token override.
	 * @param string|null $api_base Optional API base URL override.
	 */
	public function __construct( $token = null, $api_base = null ) {
		$this->token_override    = $token;
		$this->api_base_override = $api_base;
	}

	/**
	 * Resolve the API token, preferring the override.
	 *
	 * @return string
	 */
	private function token() {
		return null !== $this->token_override ? (string) $this->token_override : Pdlead_Settings::api_token();
	}

	/**
	 * Resolve the API base URL, preferring the override.
	 *
	 * @return string
	 */
	private function base_url() {
		return null !== $this->api_base_override ? (string) $this->api_base_override : Pdlead_Settings::api_base();
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash, e.g. /persons.
	 * @param array|null $body   Request body for write methods.
	 * @param array      $query  Optional query args.
	 * @return array { ok: bool, status: int, data: array|null, retryable: bool, error: string }
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		$token = $this->token();
		if ( '' === $token ) {
			return $this->result( false, 0, null, false, __( 'No Pipedrive API token configured.', 'pipedrive-lead-forms' ) );
		}

		$url = $this->base_url() . $path;
		if ( ! empty( $query ) ) {
			// add_query_arg URL-encodes values, so pass them raw.
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'x-api-token' => $token,
				'Accept'      => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// Network level failure is transient: allow a retry.
			return $this->result( false, 0, null, true, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return $this->result( true, $status, $data, false, '' );
		}

		$retryable = ( 429 === $status || $status >= 500 );
		$error     = $this->extract_error( $data, $status );

		return $this->result( false, $status, $data, $retryable, $error );
	}

	/**
	 * Verify the token by calling the current user endpoint.
	 *
	 * @return array Request result.
	 */
	public function test_connection() {
		return $this->request( 'GET', '/users/me' );
	}

	/**
	 * Fetch the lead labels defined in the connected Pipedrive account.
	 *
	 * @return array|null List of labels ( each: id, name, color ), an empty
	 *                    array when the account has none, or null when the
	 *                    labels could not be loaded (missing token or API error).
	 */
	public function get_lead_labels() {
		$res = $this->request( 'GET', '/leadLabels' );
		if ( ! $res['ok'] ) {
			return null;
		}
		if ( empty( $res['data']['data'] ) || ! is_array( $res['data']['data'] ) ) {
			return array();
		}

		$labels = array();
		foreach ( $res['data']['data'] as $label ) {
			if ( empty( $label['id'] ) || ! isset( $label['name'] ) ) {
				continue;
			}
			$labels[] = array(
				'id'    => (string) $label['id'],
				'name'  => (string) $label['name'],
				'color' => isset( $label['color'] ) ? (string) $label['color'] : '',
			);
		}
		return $labels;
	}

	/**
	 * Fetch the custom deal fields that offer selectable options.
	 *
	 * Leads share their custom fields with deals, so custom fields (e.g. an
	 * acquisition type) are discovered here. Only option fields (enum = single
	 * choice, set = multiple) that are user-editable custom fields are returned;
	 * built-in enums such as the deal status are skipped.
	 *
	 * @return array|null List of fields ( each: key, name, field_type, options[
	 *                    id, label ] ), an empty array when the account has no
	 *                    such fields, or null when they could not be loaded
	 *                    (missing token or API error).
	 */
	public function get_deal_option_fields() {
		$res = $this->request(
			'GET',
			'/dealFields',
			null,
			array(
				'start' => 0,
				'limit' => 500,
			)
		);
		if ( ! $res['ok'] ) {
			return null;
		}
		if ( empty( $res['data']['data'] ) || ! is_array( $res['data']['data'] ) ) {
			return array();
		}

		$fields = array();
		foreach ( $res['data']['data'] as $field ) {
			if ( empty( $field['key'] ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$type = isset( $field['field_type'] ) ? (string) $field['field_type'] : '';
			if ( 'enum' !== $type && 'set' !== $type ) {
				continue;
			}
			// Only user-editable custom fields; built-in enums report edit_flag false.
			if ( isset( $field['edit_flag'] ) && false === $field['edit_flag'] ) {
				continue;
			}

			$options = array();
			if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					if ( ! isset( $option['id'] ) || ! isset( $option['label'] ) ) {
						continue;
					}
					$options[] = array(
						'id'    => (string) $option['id'],
						'label' => (string) $option['label'],
					);
				}
			}

			$fields[] = array(
				'key'        => (string) $field['key'],
				'name'       => (string) $field['name'],
				'field_type' => $type,
				'options'    => $options,
			);
		}
		return $fields;
	}

	/**
	 * Fetch the users of the connected Pipedrive account.
	 *
	 * Deactivated users are included on purpose: their IDs are still valid
	 * owner IDs within the account.
	 *
	 * @return array|null List of users ( each: id, name ), an empty array when
	 *                    the account has none, or null when they could not be
	 *                    loaded (missing token or API error).
	 */
	public function get_users() {
		$res = $this->request( 'GET', '/users' );
		if ( ! $res['ok'] ) {
			return null;
		}
		if ( empty( $res['data']['data'] ) || ! is_array( $res['data']['data'] ) ) {
			return array();
		}

		$users = array();
		foreach ( $res['data']['data'] as $user ) {
			if ( empty( $user['id'] ) ) {
				continue;
			}
			$users[] = array(
				'id'   => (int) $user['id'],
				'name' => isset( $user['name'] ) ? (string) $user['name'] : '',
			);
		}
		return $users;
	}

	/**
	 * Search for an existing person by exact email.
	 *
	 * @param string $email Email address.
	 * @return int|null Person ID when found.
	 */
	public function find_person_id_by_email( $email ) {
		if ( '' === $email ) {
			return null;
		}

		$res = $this->request(
			'GET',
			'/persons/search',
			null,
			array(
				'term'        => $email,
				'fields'      => 'email',
				'exact_match' => 'true',
				'limit'       => '1',
			)
		);

		if ( ! $res['ok'] || empty( $res['data']['data']['items'] ) ) {
			return null;
		}

		$item = $res['data']['data']['items'][0];
		if ( isset( $item['item']['id'] ) ) {
			return (int) $item['item']['id'];
		}
		return null;
	}

	/**
	 * Create an organization.
	 *
	 * @param string $name  Organization name.
	 * @param array  $extra Optional extra fields to merge into the payload (e.g. visible_to).
	 * @return array Request result; ID available at data.data.id.
	 */
	public function create_organization( $name, $extra = array() ) {
		return $this->request( 'POST', '/organizations', array_merge( array( 'name' => $name ), $extra ) );
	}

	/**
	 * Create a person.
	 *
	 * @param array $data Person payload.
	 * @return array Request result; ID available at data.data.id.
	 */
	public function create_person( $data ) {
		return $this->request( 'POST', '/persons', $data );
	}

	/**
	 * Create a lead.
	 *
	 * @param array $data Lead payload.
	 * @return array Request result; ID available at data.data.id (UUID string).
	 */
	public function create_lead( $data ) {
		return $this->request( 'POST', '/leads', $data );
	}

	/**
	 * Create a note attached to a lead.
	 *
	 * @param string $content Note content.
	 * @param string $lead_id Lead UUID.
	 * @return array Request result.
	 */
	public function create_note( $content, $lead_id ) {
		return $this->request(
			'POST',
			'/notes',
			array(
				'content' => $content,
				'lead_id' => $lead_id,
			)
		);
	}

	/**
	 * Upload a local file and attach it to a lead.
	 *
	 * @param string $path     Absolute path to the local file.
	 * @param string $filename File name to present to Pipedrive.
	 * @param string $lead_id  Lead UUID to attach the file to.
	 * @return array Request result.
	 */
	public function upload_file( $path, $filename, $lead_id ) {
		if ( ! file_exists( $path ) ) {
			return $this->result( false, 0, null, false, __( 'Upload file not found.', 'pipedrive-lead-forms' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return $this->result( false, 0, null, false, __( 'Could not read upload file.', 'pipedrive-lead-forms' ) );
		}

		return $this->request_multipart(
			'/files',
			array( 'lead_id' => $lead_id ),
			array(
				'name'     => 'file',
				'filename' => $filename,
				'type'     => 'application/octet-stream',
				'content'  => $contents,
			)
		);
	}

	/**
	 * Perform a multipart/form-data POST. The shared request() forces JSON, so
	 * file uploads build their own boundary body and send it raw.
	 *
	 * @param string $path   Path beginning with a slash, e.g. /files.
	 * @param array  $fields Plain text form fields.
	 * @param array  $file   File part: name, filename, type, content.
	 * @return array Request result.
	 */
	private function request_multipart( $path, $fields, $file ) {
		$token = $this->token();
		if ( '' === $token ) {
			return $this->result( false, 0, null, false, __( 'No Pipedrive API token configured.', 'pipedrive-lead-forms' ) );
		}

		$url      = $this->base_url() . $path;
		$boundary = 'pdlead' . wp_generate_password( 24, false );
		$eol      = "\r\n";
		$fname    = str_replace( array( '"', "\r", "\n" ), '', (string) $file['filename'] );

		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $fname . '"' . $eol;
		$body .= 'Content-Type: ' . $file['type'] . $eol . $eol;
		$body .= $file['content'] . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-api-token'  => $token,
					'Accept'       => 'application/json',
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->result( false, 0, null, true, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return $this->result( true, $status, $data, false, '' );
		}

		$retryable = ( 429 === $status || $status >= 500 );
		return $this->result( false, $status, $data, $retryable, $this->extract_error( $data, $status ) );
	}

	/**
	 * Extract a readable error message from a Pipedrive error body.
	 *
	 * @param mixed $data   Decoded body.
	 * @param int   $status HTTP status.
	 * @return string
	 */
	private function extract_error( $data, $status ) {
		if ( is_array( $data ) ) {
			if ( ! empty( $data['error'] ) ) {
				$msg = $data['error'];
				if ( ! empty( $data['error_info'] ) ) {
					$msg .= ' (' . $data['error_info'] . ')';
				}
				return $msg;
			}
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Pipedrive request failed with status %d.', 'pipedrive-lead-forms' ), $status );
	}

	/**
	 * Build a normalized result array.
	 *
	 * @param bool        $ok        Success flag.
	 * @param int         $status    HTTP status.
	 * @param array|null  $data      Decoded body.
	 * @param bool        $retryable Whether a retry may succeed.
	 * @param string      $error     Error message.
	 * @return array
	 */
	private function result( $ok, $status, $data, $retryable, $error ) {
		return array(
			'ok'        => $ok,
			'status'    => $status,
			'data'      => $data,
			'retryable' => $retryable,
			'error'     => $error,
		);
	}
}
