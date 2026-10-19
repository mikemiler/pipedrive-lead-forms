<?php
/**
 * Thin Pipedrive API client using the API token (x-api-token header).
 * Organizations and persons go through API v2 (their v1 endpoints are out of
 * support); everything else (leads, notes, files, labels, users) stays on v1.
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
	 * Transient caching the organization fields of the connected account.
	 */
	const ORG_FIELDS_CACHE = 'pdlead_org_fields';

	/**
	 * Transient caching the lead custom fields of the connected account.
	 */
	const LEAD_FIELDS_CACHE = 'pdlead_lead_fields';

	/**
	 * Write format per Pipedrive custom field type, for the field types a form
	 * value can be converted to. Other types (monetary, user, ...) are skipped.
	 */
	const CUSTOM_FIELD_FORMATS = array(
		'varchar'      => 'string',
		'varchar_auto' => 'string',
		'text'         => 'string',
		'phone'        => 'string',
		'double'       => 'number',
		'date'         => 'date',
		'enum'         => 'enum',
		'set'          => 'set',
		'address'      => 'address',
	);

	/**
	 * Optional API token override.
	 *
	 * @var string|null
	 */
	private $token_override;

	/**
	 * Optional company domain override.
	 *
	 * @var string|null
	 */
	private $domain_override;

	/**
	 * The overrides let the settings sanitizer validate against the token and
	 * company domain being saved before they are persisted; every other caller
	 * uses the stored settings via the null defaults.
	 *
	 * @param string|null $token  Optional API token override.
	 * @param string|null $domain Optional company domain override (raw setting value).
	 */
	public function __construct( $token = null, $domain = null ) {
		$this->token_override  = $token;
		$this->domain_override = $domain;
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
	 * Resolve the API base URL for an API version, preferring the override.
	 *
	 * @param string $version API version, 'v1' or 'v2'.
	 * @return string
	 */
	private function base_url( $version ) {
		$domain = null !== $this->domain_override ? (string) $this->domain_override : (string) Pdlead_Settings::get( 'company_domain', '' );
		return 'v2' === $version ? Pdlead_Settings::api_base_v2_for_domain( $domain ) : Pdlead_Settings::api_base_for_domain( $domain );
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash, e.g. /persons.
	 * @param array|null $body   Request body for write methods.
	 * @param array      $query  Optional query args.
	 * @param string     $version API version, 'v1' (default) or 'v2'.
	 * @return array { ok: bool, status: int, data: array|null, retryable: bool, error: string }
	 */
	public function request( $method, $path, $body = null, $query = array(), $version = 'v1' ) {
		$token = $this->token();
		if ( '' === $token ) {
			return $this->result( false, 0, null, false, __( 'No Pipedrive API token configured.', 'pipedrive-lead-forms' ) );
		}

		$url = $this->base_url( $version ) . $path;
		if ( ! empty( $query ) ) {
			// add_query_arg does not encode values. Search terms such as
			// "Smith & Sons" or "max+news@example.com" must be encoded here.
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
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
	 * choice, set = multiple) that are custom fields are returned; built-in
	 * enums such as the deal status are skipped. The field code is the same
	 * hash the v1 leads endpoint expects as key.
	 *
	 * @return array|null List of fields ( each: key, name, field_type, options[
	 *                    id, label ] ), an empty array when the account has no
	 *                    such fields, or null when they could not be loaded
	 *                    (missing token or API error).
	 */
	public function get_deal_option_fields() {
		$items = $this->get_fields( '/dealFields' );
		if ( null === $items ) {
			return null;
		}

		$fields = array();
		foreach ( $items as $field ) {
			if ( ! $field['custom'] || ( 'enum' !== $field['type'] && 'set' !== $field['type'] ) ) {
				continue;
			}
			$fields[] = array(
				'key'        => $field['code'],
				'name'       => $field['name'],
				'field_type' => $field['type'],
				'options'    => $field['options'],
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
	 * Fetch the organization fields that a form field can be mapped to.
	 *
	 * Custom fields qualify by type. API v2 also returns system fields but has
	 * no read-only flag, so writable system fields come from an allowlist.
	 * Each field carries the write format the dispatcher converts a value to:
	 * string, number, int, date, enum, set or address. The organization name is
	 * left out, it has its own mapping target. Cached for an hour per account,
	 * "Test connection" refreshes the cache.
	 *
	 * @return array|null List of fields ( each: code, name, format, custom,
	 *                    options[ id, label ] ), an empty array when the account
	 *                    has none, or null when they could not be loaded
	 *                    (missing token or API error).
	 */
	public function get_organization_fields() {
		$system_formats = array(
			'address'        => 'address',
			'website'        => 'string',
			'linkedin'       => 'string',
			'industry'       => 'enum',
			'annual_revenue' => 'int',
			'employee_count' => 'int',
		);
		return $this->cached_fields( self::ORG_FIELDS_CACHE, '/organizationFields', $system_formats );
	}

	/**
	 * Fetch the lead custom fields that a form field can be mapped to.
	 *
	 * Leads share their custom fields with deals. Only custom fields are
	 * offered, which keeps the list short and avoids deal-only system fields.
	 * Cached like the organization fields.
	 *
	 * @return array|null Same shape as get_organization_fields().
	 */
	public function get_lead_fields() {
		return $this->cached_fields( self::LEAD_FIELDS_CACHE, '/dealFields', array() );
	}

	/**
	 * Load the mappable fields of an entity, cached for an hour per account.
	 *
	 * @param string $cache          Transient name.
	 * @param string $path           v2 fields endpoint, e.g. /dealFields.
	 * @param array  $system_formats Writable system fields ( code => format ).
	 * @return array|null List of fields ( each: code, name, format, custom,
	 *                    options[ id, label ] ), or null when they could not
	 *                    be loaded.
	 */
	private function cached_fields( $cache, $path, $system_formats ) {
		$account = md5( $this->token() . '|' . $this->base_url( 'v2' ) );
		$cached  = get_transient( $cache );
		if ( is_array( $cached ) && isset( $cached['account'], $cached['fields'] ) && $cached['account'] === $account ) {
			return $cached['fields'];
		}

		$items = $this->get_fields( $path );
		if ( null === $items ) {
			return null;
		}

		$fields = array();
		foreach ( $items as $field ) {
			if ( $field['custom'] ) {
				$format = isset( self::CUSTOM_FIELD_FORMATS[ $field['type'] ] ) ? self::CUSTOM_FIELD_FORMATS[ $field['type'] ] : '';
			} else {
				$format = isset( $system_formats[ $field['code'] ] ) ? $system_formats[ $field['code'] ] : '';
			}
			if ( '' === $format ) {
				continue;
			}
			$fields[] = array(
				'code'    => $field['code'],
				'name'    => $field['name'],
				'format'  => $format,
				'custom'  => $field['custom'],
				'options' => $field['options'],
			);
		}

		set_transient(
			$cache,
			array(
				'account' => $account,
				'fields'  => $fields,
			),
			HOUR_IN_SECONDS
		);
		return $fields;
	}

	/**
	 * Drop the cached organization fields so the next lookup reloads them.
	 */
	public static function flush_organization_fields() {
		delete_transient( self::ORG_FIELDS_CACHE );
	}

	/**
	 * Drop the cached lead fields so the next lookup reloads them.
	 */
	public static function flush_lead_fields() {
		delete_transient( self::LEAD_FIELDS_CACHE );
	}

	/**
	 * Load all fields of an entity from a v2 fields endpoint, following the
	 * cursor pagination.
	 *
	 * @param string $path Fields endpoint, e.g. /dealFields.
	 * @return array|null List of fields ( each: code, name, type, custom,
	 *                    options[ id, label ] ), or null when they could not be
	 *                    loaded (missing token or API error).
	 */
	private function get_fields( $path ) {
		$fields = array();
		$cursor = '';
		do {
			$query = array( 'limit' => 500 );
			if ( '' !== $cursor ) {
				$query['cursor'] = $cursor;
			}
			$res = $this->request( 'GET', $path, null, $query, 'v2' );
			if ( ! $res['ok'] ) {
				return null;
			}
			$items = isset( $res['data']['data'] ) && is_array( $res['data']['data'] ) ? $res['data']['data'] : array();
			foreach ( $items as $field ) {
				if ( empty( $field['field_code'] ) || ! isset( $field['field_name'] ) ) {
					continue;
				}

				$options = array();
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as $option ) {
						if ( isset( $option['id'], $option['label'] ) ) {
							$options[] = array(
								'id'    => (string) $option['id'],
								'label' => (string) $option['label'],
							);
						}
					}
				}

				$fields[] = array(
					'code'    => (string) $field['field_code'],
					'name'    => (string) $field['field_name'],
					'type'    => isset( $field['field_type'] ) ? (string) $field['field_type'] : '',
					'custom'  => ! empty( $field['is_custom_field'] ),
					'options' => $options,
				);
			}
			$cursor = isset( $res['data']['additional_data']['next_cursor'] ) ? (string) $res['data']['additional_data']['next_cursor'] : '';
		} while ( '' !== $cursor );

		return $fields;
	}

	/**
	 * Search for an existing person by exact email.
	 *
	 * @param string $email Email address.
	 * @return int|null Person ID when found.
	 */
	public function find_person_id_by_email( $email ) {
		return $this->find_id( '/persons/search', $email, 'email' );
	}

	/**
	 * Search for an existing organization by exact name (case insensitive).
	 *
	 * @param string $name Organization name.
	 * @return int|null Organization ID when found.
	 */
	public function find_organization_id_by_name( $name ) {
		return $this->find_id( '/organizations/search', $name, 'name' );
	}

	/**
	 * Run an exact match v2 search and return the first hit's ID.
	 *
	 * @param string $path  Search endpoint, e.g. /persons/search.
	 * @param string $term  Search term.
	 * @param string $field Field to search in.
	 * @return int|null ID when found; null on no match or a failed search.
	 */
	private function find_id( $path, $term, $field ) {
		if ( '' === $term ) {
			return null;
		}

		$res = $this->request(
			'GET',
			$path,
			null,
			array(
				'term'        => $term,
				'fields'      => $field,
				'exact_match' => 'true',
				'limit'       => '1',
			),
			'v2'
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
	 * Create an organization (API v2).
	 *
	 * @param array $data Organization payload; name is required.
	 * @return array Request result; ID available at data.data.id.
	 */
	public function create_organization( $data ) {
		return $this->request( 'POST', '/organizations', $data, array(), 'v2' );
	}

	/**
	 * Create a person (API v2).
	 *
	 * @param array $data Person payload.
	 * @return array Request result; ID available at data.data.id.
	 */
	public function create_person( $data ) {
		return $this->request( 'POST', '/persons', $data, array(), 'v2' );
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

		$url      = $this->base_url( 'v1' ) . $path;
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
