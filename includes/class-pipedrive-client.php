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
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash, e.g. /persons.
	 * @param array|null $body   Request body for write methods.
	 * @param array      $query  Optional query args.
	 * @return array { ok: bool, status: int, data: array|null, retryable: bool, error: string }
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		$token = Pdlead_Settings::api_token();
		if ( '' === $token ) {
			return $this->result( false, 0, null, false, __( 'No Pipedrive API token configured.', 'pipedrive-lead-forms' ) );
		}

		$url = Pdlead_Settings::api_base() . $path;
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
	 * @param string $name Organization name.
	 * @return array Request result; ID available at data.data.id.
	 */
	public function create_organization( $name ) {
		return $this->request( 'POST', '/organizations', array( 'name' => $name ) );
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
