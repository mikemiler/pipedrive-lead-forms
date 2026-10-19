<?php
/**
 * Maps submissions to Pipedrive and pushes them. Each Pipedrive step is
 * idempotent: the resulting ID is stored immediately, so a retry never
 * creates a duplicate person, organization or lead.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead dispatch and retry engine.
 */
class Pdlead_Lead_Dispatcher {

	/**
	 * Register cron hooks.
	 */
	public static function init() {
		add_action( PDLEAD_RETRY_HOOK, array( __CLASS__, 'process_retry_queue' ) );
		add_action( PDLEAD_CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Translate a stored submission into mapped Pipedrive values.
	 *
	 * @param int   $form_id Form post ID.
	 * @param array $payload Field values keyed by field key.
	 * @return array
	 */
	public static function map( $form_id, $payload ) {
		$fields = Pdlead_Form_CPT::get_fields( $form_id );

		$mapped = array(
			'person_name' => '',
			'email'       => '',
			'phone'       => '',
			'org_name'    => '',
			'lead_title'  => '',
			'org_fields'  => array(),
			'notes'       => array(),
			'files'       => array(),
			'tracking'    => array(),
		);

		// Campaign tracking values captured at submission.
		if ( isset( $payload[ Pdlead_Form_CPT::TRACKING_KEY ] ) && is_array( $payload[ Pdlead_Form_CPT::TRACKING_KEY ] ) ) {
			$mapped['tracking'] = $payload[ Pdlead_Form_CPT::TRACKING_KEY ];
		}

		foreach ( $fields as $field ) {
			$key = isset( $field['key'] ) ? $field['key'] : '';
			$map = isset( $field['map_to'] ) ? $field['map_to'] : 'none';

			if ( '' === $key || ! isset( $payload[ $key ] ) ) {
				continue;
			}

			// File attachments carry metadata arrays, not a scalar value, and
			// are uploaded separately after the lead exists.
			if ( 'file' === $map ) {
				if ( is_array( $payload[ $key ] ) ) {
					foreach ( $payload[ $key ] as $file ) {
						if ( is_array( $file ) && ! empty( $file['file'] ) ) {
							$mapped['files'][] = $file;
						}
					}
				}
				continue;
			}

			$value = is_array( $payload[ $key ] ) ? implode( ', ', $payload[ $key ] ) : (string) $payload[ $key ];
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			switch ( $map ) {
				case 'person_name':
					$mapped['person_name'] = $value;
					break;
				case 'email':
					$mapped['email'] = $value;
					break;
				case 'phone':
					$mapped['phone'] = $value;
					break;
				case 'org_name':
					$mapped['org_name'] = $value;
					break;
				case 'lead_title':
					$mapped['lead_title'] = $value;
					break;
				case 'note':
					$label = isset( $field['label'] ) ? $field['label'] : $key;
					$mapped['notes'][] = $label . ': ' . $value;
					break;
				default:
					// Organization field, keyed by its Pipedrive field code. The
					// label is kept for the note fallback (see build_org_payload).
					if ( Pdlead_Form_CPT::is_org_field_target( $map ) ) {
						$code = substr( $map, strlen( Pdlead_Form_CPT::ORG_FIELD_PREFIX ) );

						$mapped['org_fields'][ $code ] = array(
							'label' => isset( $field['label'] ) ? $field['label'] : $key,
							'value' => $value,
						);
					}
					break;
			}
		}

		// Fallbacks so a lead can always be created.
		if ( '' === $mapped['person_name'] ) {
			$mapped['person_name'] = '' !== $mapped['email'] ? $mapped['email'] : __( 'Website lead', 'pipedrive-lead-forms' );
		}
		if ( '' === $mapped['lead_title'] ) {
			/* translators: %s: person name or email. */
			$mapped['lead_title'] = sprintf( __( 'Lead from %s', 'pipedrive-lead-forms' ), $mapped['person_name'] );
		}

		return $mapped;
	}

	/**
	 * Dispatch a single submission. Safe to call repeatedly (idempotent).
	 *
	 * @param int $id Submission ID.
	 * @return string Resulting status.
	 */
	public static function dispatch( $id ) {
		$row = Pdlead_Submission_Store::get( $id );
		if ( ! $row ) {
			return 'missing';
		}
		if ( Pdlead_Submission_Store::STATUS_SENT === $row['status'] || Pdlead_Submission_Store::STATUS_SPAM === $row['status'] ) {
			return $row['status'];
		}

		// Claim the row atomically. If another worker (cron vs. synchronous
		// submit) already holds it, skip to avoid creating duplicates.
		if ( ! Pdlead_Submission_Store::claim( $id ) ) {
			return 'locked';
		}

		// Reload after claiming so we act on the latest stored Pipedrive IDs.
		$row = Pdlead_Submission_Store::get( $id );
		if ( ! $row ) {
			return 'missing';
		}

		$payload = json_decode( $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		$mapped = self::map( (int) $row['form_id'], $payload );

		$client      = new Pdlead_Pipedrive_Client();
		$org_id      = $row['pipedrive_org_id'] ? (int) $row['pipedrive_org_id'] : 0;
		$person_id   = $row['pipedrive_person_id'] ? (int) $row['pipedrive_person_id'] : 0;
		$lead_id     = $row['pipedrive_lead_id'] ? (string) $row['pipedrive_lead_id'] : '';
		$settings    = Pdlead_Settings::all();
		// Pipedrive visible_to level shared by lead, person and organization. 0 = leave unset.
		$visible_to = (int) $settings['visible_to'];

		// Step 1: organization (optional, only when a name was provided).
		// Reuse an existing organization by name to avoid duplicates. Its data
		// is never overwritten, so mapped organization field values that are not
		// sent end up in the lead note instead.
		if ( ! $org_id && '' !== $mapped['org_name'] ) {
			$leftover = $mapped['org_fields'];
			$found    = $client->find_organization_id_by_name( $mapped['org_name'] );
			if ( $found ) {
				$org_id = $found;
			} else {
				$base = array( 'name' => $mapped['org_name'] );
				if ( $visible_to > 0 ) {
					// API v2 expects visible_to as an integer.
					$base['visible_to'] = $visible_to;
				}
				list( $org_payload, $leftover ) = self::build_org_payload( $base, $mapped['org_fields'], $client );

				$res = $client->create_organization( $org_payload );
				if ( ! $res['ok'] && ! $res['retryable'] && $org_payload !== $base ) {
					// v2 validation errors do not name the failing field. Rather
					// than losing the lead, create the organization with its name
					// only and keep every mapped value in the note.
					$res      = $client->create_organization( $base );
					$leftover = $mapped['org_fields'];
				}
				if ( ! $res['ok'] ) {
					return self::handle_failure( $id, $row, $res );
				}
				$org_id = isset( $res['data']['data']['id'] ) ? (int) $res['data']['data']['id'] : 0;
			}
			foreach ( $leftover as $item ) {
				$mapped['notes'][] = $item['label'] . ': ' . $item['value'];
			}
			Pdlead_Submission_Store::update( $id, array( 'pipedrive_org_id' => $org_id ) );
		}

		// Step 2: person. Reuse an existing person by email to avoid duplicates.
		if ( ! $person_id ) {
			if ( '' !== $mapped['email'] ) {
				$found = $client->find_person_id_by_email( $mapped['email'] );
				if ( $found ) {
					$person_id = $found;
				}
			}

			if ( ! $person_id ) {
				// API v2 expects emails and phones as lists of objects and
				// visible_to as an integer.
				$person_payload = array( 'name' => $mapped['person_name'] );
				if ( '' !== $mapped['email'] ) {
					$person_payload['emails'] = array(
						array(
							'value'   => $mapped['email'],
							'primary' => true,
							'label'   => 'work',
						),
					);
				}
				if ( '' !== $mapped['phone'] ) {
					$person_payload['phones'] = array(
						array(
							'value'   => $mapped['phone'],
							'primary' => true,
							'label'   => 'work',
						),
					);
				}
				if ( $org_id ) {
					$person_payload['org_id'] = $org_id;
				}
				if ( $visible_to > 0 ) {
					$person_payload['visible_to'] = $visible_to;
				}

				$res = $client->create_person( $person_payload );
				if ( ! $res['ok'] ) {
					return self::handle_failure( $id, $row, $res );
				}
				$person_id = isset( $res['data']['data']['id'] ) ? (int) $res['data']['data']['id'] : 0;
			}

			Pdlead_Submission_Store::update( $id, array( 'pipedrive_person_id' => $person_id ) );
		}

		// Step 3: lead.
		if ( '' === $lead_id ) {
			$lead_payload = array(
				'title'     => $mapped['lead_title'],
				'person_id' => $person_id,
			);
			if ( $org_id ) {
				$lead_payload['organization_id'] = $org_id;
			}
			if ( (int) $settings['owner_id'] > 0 ) {
				$lead_payload['owner_id'] = (int) $settings['owner_id'];
			}
			if ( $visible_to > 0 ) {
				// The leads endpoint is still v1, which expects visible_to as a string.
				$lead_payload['visible_to'] = (string) $visible_to;
			}
			$label_ids = self::parse_id_list( $settings['label_ids'] );
			if ( $label_ids ) {
				$lead_payload['label_ids'] = $label_ids;
			}
			// Custom field: leads share deal custom fields, so the field key
			// carries its selected option ID(s) (enum: "123"; set: "12,13").
			$cf_key = (string) $settings['custom_field_key'];
			$cf_ids = self::parse_id_list( $settings['custom_field_option_ids'] );
			if ( '' !== $cf_key && $cf_ids ) {
				$lead_payload[ $cf_key ] = implode( ',', $cf_ids );
			}

			$res = $client->create_lead( $lead_payload );
			if ( ! $res['ok'] ) {
				return self::handle_failure( $id, $row, $res );
			}
			$lead_id = isset( $res['data']['data']['id'] ) ? (string) $res['data']['data']['id'] : '';
			Pdlead_Submission_Store::update( $id, array( 'pipedrive_lead_id' => $lead_id ) );
		}

		// Step 4: note (secondary). A note failure must not block the lead nor
		// trigger a retry, otherwise the lead would be recreated. Best effort.
		// The tracking block goes last, after any organization leftover lines.
		$notes = $mapped['notes'];
		if ( ! empty( $mapped['tracking'] ) ) {
			if ( $notes ) {
				$notes[] = '';
			}
			$notes[] = __( 'Tracking:', 'pipedrive-lead-forms' );
			foreach ( $mapped['tracking'] as $name => $value ) {
				$notes[] = $name . ': ' . $value;
			}
		}
		if ( ! empty( $notes ) && '' !== $lead_id ) {
			$client->create_note( implode( "\n", $notes ), $lead_id );
		}

		// Step 4b: file attachments (secondary, best effort like the note). A
		// retry would recreate the lead, so a failure here must not trigger one.
		if ( ! empty( $mapped['files'] ) && '' !== $lead_id ) {
			foreach ( $mapped['files'] as $file ) {
				$path = Pdlead_File_Store::abs_path( $file['file'] );
				$name = ! empty( $file['name'] ) ? $file['name'] : wp_basename( $file['file'] );
				$client->upload_file( $path, $name, $lead_id );
			}
		}

		Pdlead_Submission_Store::update(
			$id,
			array(
				'status'     => Pdlead_Submission_Store::STATUS_SENT,
				'last_error' => '',
			)
		);

		return Pdlead_Submission_Store::STATUS_SENT;
	}

	/**
	 * Add the mapped organization field values to an organization payload.
	 *
	 * Each value is converted to the write format of its Pipedrive field. A
	 * value that does not fit (unknown or deleted field, no matching option,
	 * not a number or date) is returned as leftover for the lead note, since a
	 * rejected organization would otherwise block the whole lead.
	 *
	 * @param array                   $payload    Base payload (name, visible_to).
	 * @param array                   $org_fields Mapped values keyed by field code ( label, value ).
	 * @param Pdlead_Pipedrive_Client $client     API client.
	 * @return array { 0: array payload, 1: array leftover values keyed by field code }
	 */
	private static function build_org_payload( $payload, $org_fields, $client ) {
		if ( empty( $org_fields ) ) {
			return array( $payload, array() );
		}
		$fields = $client->get_organization_fields();
		if ( null === $fields ) {
			return array( $payload, $org_fields );
		}
		$known = array_column( $fields, null, 'code' );

		$leftover = array();
		foreach ( $org_fields as $code => $item ) {
			$value = isset( $known[ $code ] ) ? self::org_field_value( $known[ $code ], $item['value'] ) : null;
			if ( null === $value ) {
				$leftover[ $code ] = $item;
			} elseif ( $known[ $code ]['custom'] ) {
				$payload['custom_fields'][ $code ] = $value;
			} else {
				$payload[ $code ] = $value;
			}
		}
		return array( $payload, $leftover );
	}

	/**
	 * Convert a submitted value to the API v2 write format of a field.
	 *
	 * @param array  $field Organization field ( format, options ).
	 * @param string $raw   Submitted value, trimmed and not empty.
	 * @return mixed Converted value, or null when the value does not fit.
	 */
	private static function org_field_value( $field, $raw ) {
		switch ( $field['format'] ) {
			case 'string':
				return $raw;
			case 'address':
				return array( 'value' => $raw );
			case 'number':
				$number = str_replace( ' ', '', $raw );
				if ( false === strpos( $number, '.' ) ) {
					// Accept a decimal comma, e.g. "12,5".
					$number = str_replace( ',', '.', $number );
				}
				return is_numeric( $number ) ? (float) $number : null;
			case 'int':
				$number = str_replace( ' ', '', $raw );
				return ctype_digit( $number ) ? (int) $number : null;
			case 'date':
				if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return $raw;
				}
				return null;
			case 'enum':
			case 'set':
				foreach ( $field['options'] as $option ) {
					if ( 0 === strcasecmp( trim( $option['label'] ), $raw ) && is_numeric( $option['id'] ) ) {
						// The form select is single choice, so a set gets one option.
						return 'set' === $field['format'] ? array( (int) $option['id'] ) : (int) $option['id'];
					}
				}
				return null;
		}
		return null;
	}

	/**
	 * Handle a failed Pipedrive step: schedule a retry or give up.
	 *
	 * @param int   $id  Submission ID.
	 * @param array $row Current row.
	 * @param array $res Client result.
	 * @return string Resulting status.
	 */
	private static function handle_failure( $id, $row, $res ) {
		$attempts     = (int) $row['attempts'] + 1;
		$max_attempts = (int) Pdlead_Settings::get( 'max_attempts' );

		if ( $res['retryable'] && $attempts < $max_attempts ) {
			$delay = min( HOUR_IN_SECONDS, 60 * pow( 2, $attempts ) );
			Pdlead_Submission_Store::update(
				$id,
				array(
					'status'          => Pdlead_Submission_Store::STATUS_PENDING,
					'attempts'        => $attempts,
					'last_error'      => $res['error'],
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				)
			);
			return Pdlead_Submission_Store::STATUS_PENDING;
		}

		// Permanent failure or attempts exhausted.
		Pdlead_Submission_Store::update(
			$id,
			array(
				'status'     => Pdlead_Submission_Store::STATUS_FAILED,
				'attempts'   => $attempts,
				'last_error' => $res['error'],
			)
		);
		self::notify_failure( $id, $res['error'] );
		return Pdlead_Submission_Store::STATUS_FAILED;
	}

	/**
	 * Cron handler: process pending submissions that are due.
	 */
	public static function process_retry_queue() {
		$max = (int) Pdlead_Settings::get( 'max_attempts' );
		$due = Pdlead_Submission_Store::get_due_for_retry( $max, 20 );
		foreach ( $due as $row ) {
			self::dispatch( (int) $row['id'] );
		}
	}

	/**
	 * Cron handler: delete old settled rows per retention policy.
	 */
	public static function cleanup() {
		Pdlead_Submission_Store::cleanup( (int) Pdlead_Settings::get( 'retention_days' ) );
	}

	/**
	 * Email the admin when a lead permanently fails to reach Pipedrive.
	 *
	 * @param int    $id    Submission ID.
	 * @param string $error Error message.
	 */
	private static function notify_failure( $id, $error ) {
		$to = Pdlead_Settings::get( 'notify_email' );
		if ( '' === $to ) {
			$to = get_option( 'admin_email' );
		}
		if ( '' === $to ) {
			return;
		}

		$subject = __( 'Pipedrive lead failed to sync', 'pipedrive-lead-forms' );
		$link    = admin_url( 'edit.php?post_type=' . PDLEAD_CPT . '&page=pdlead-log' );
		$body    = sprintf(
			/* translators: 1: submission ID, 2: error message, 3: admin URL. */
			__( "A lead could not be created in Pipedrive after several attempts.\n\nSubmission ID: %1\$d\nError: %2\$s\n\nReview and retry: %3\$s", 'pipedrive-lead-forms' ),
			$id,
			$error,
			$link
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Parse a comma separated list of IDs into an array of non-empty strings.
	 *
	 * Used for both lead label IDs and custom field option IDs.
	 *
	 * @param string $raw Raw value.
	 * @return string[]
	 */
	private static function parse_id_list( $raw ) {
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}
}
