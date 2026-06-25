<?php
/**
 * Submission store: single source of truth for every form submission.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for the submissions table.
 */
class Pdlead_Submission_Store {

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_SENT       = 'sent';
	const STATUS_FAILED     = 'failed';
	const STATUS_SPAM       = 'spam';

	/**
	 * How long a claimed (processing) row stays locked before it is considered
	 * stale and may be reclaimed. Must exceed the worst case dispatch duration.
	 */
	const LOCK_TTL = 120;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pdlead_submissions';
	}

	/**
	 * Create or upgrade the table via dbDelta.
	 */
	public static function install_table() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			ip_hash VARCHAR(64) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			flagged TINYINT(1) NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NULL,
			last_error TEXT NULL,
			pipedrive_org_id BIGINT UNSIGNED NULL,
			pipedrive_person_id BIGINT UNSIGNED NULL,
			pipedrive_lead_id VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY form_id (form_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'pdlead_db_version', PDLEAD_DB_VERSION );
	}

	/**
	 * Run a deferred migration when the stored DB version is behind.
	 * Hooked on admin_init because plugin updates do not fire activation.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'pdlead_db_version' ) < PDLEAD_DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * Insert a new submission row.
	 *
	 * @param array $data {
	 *     @type int    $form_id  Form post ID.
	 *     @type string $ip_hash  Salted hash of the visitor IP.
	 *     @type array  $payload  Sanitized field values keyed by field key.
	 *     @type string $status   Initial status.
	 *     @type bool   $flagged  Suspicious flag.
	 * }
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$ok = $wpdb->insert(
			self::table(),
			array(
				'form_id'    => isset( $data['form_id'] ) ? (int) $data['form_id'] : 0,
				// Store timestamps in UTC so scheduling comparisons are consistent
				// regardless of the site timezone.
				'created_at' => current_time( 'mysql', true ),
				'ip_hash'    => isset( $data['ip_hash'] ) ? substr( (string) $data['ip_hash'], 0, 64 ) : '',
				'payload'    => wp_json_encode( isset( $data['payload'] ) ? $data['payload'] : array() ),
				'status'     => isset( $data['status'] ) ? $data['status'] : self::STATUS_PENDING,
				'flagged'    => ! empty( $data['flagged'] ) ? 1 : 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a single submission row as an associative array.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table() is a constant table name; the value is prepared.
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Update arbitrary columns on a submission row.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Column => value pairs (whitelisted below).
	 * @return bool
	 */
	public static function update( $id, $fields ) {
		global $wpdb;

		$formats  = array();
		$columns  = array();
		$allowed  = array(
			'status'              => '%s',
			'flagged'             => '%d',
			'attempts'            => '%d',
			'next_attempt_at'     => '%s',
			'last_error'          => '%s',
			'pipedrive_org_id'    => '%d',
			'pipedrive_person_id' => '%d',
			'pipedrive_lead_id'   => '%s',
		);

		foreach ( $fields as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				$columns[ $key ] = $value;
				$formats[]       = $allowed[ $key ];
			}
		}

		if ( empty( $columns ) ) {
			return false;
		}

		return (bool) $wpdb->update( self::table(), $columns, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Get submissions that are due for a retry attempt. Includes stale
	 * processing rows whose lock has expired (a previous run crashed).
	 * Callers must claim() each row before dispatching to avoid double work.
	 *
	 * @param int $max_attempts Maximum attempts before giving up.
	 * @param int $limit        Batch size.
	 * @return array[] Array of row arrays.
	 */
	public static function get_due_for_retry( $max_attempts, $limit = 20 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table() is a constant table name; all values are prepared.
		$sql = $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			WHERE attempts < %d
			AND (
				( status = %s AND ( next_attempt_at IS NULL OR next_attempt_at <= %s ) )
				OR ( status = %s AND next_attempt_at <= %s )
			)
			ORDER BY id ASC
			LIMIT %d',
			$max_attempts,
			self::STATUS_PENDING,
			$now,
			self::STATUS_PROCESSING,
			$now,
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return $rows ? $rows : array();
	}

	/**
	 * Atomically claim a row for processing. Only one caller can win, which
	 * prevents the synchronous submit dispatch and the cron retry from
	 * processing the same submission concurrently (and creating duplicates).
	 *
	 * @param int $id Row ID.
	 * @return bool True when this caller won the claim.
	 */
	public static function claim( $id ) {
		global $wpdb;

		$now        = current_time( 'mysql', true );
		$lock_until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL );
		$table      = self::table();

		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table comes from the constant table() name; all values are prepared.
				"UPDATE {$table}
				SET status = %s, next_attempt_at = %s
				WHERE id = %d
				AND (
					( status = %s AND ( next_attempt_at IS NULL OR next_attempt_at <= %s ) )
					OR ( status = %s AND next_attempt_at <= %s )
				)",
				self::STATUS_PROCESSING,
				$lock_until,
				$id,
				self::STATUS_PENDING,
				$now,
				self::STATUS_PROCESSING,
				$now
			)
		);

		return ( 1 === (int) $affected );
	}

	/**
	 * Query rows for the admin log with simple filters and paging.
	 *
	 * @param array $args Filter args: status, flagged, form_id, per_page, page.
	 * @return array { rows: array[], total: int }
	 */
	public static function query( $args ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'flagged'  => '',
			'form_id'  => 0,
			'per_page' => 25,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['flagged'] && '' !== (string) $args['flagged'] ) {
			$where[]  = 'flagged = %d';
			$params[] = (int) $args['flagged'];
		}
		if ( $args['form_id'] > 0 ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}

		$where_sql = implode( ' AND ', $where );

		// Total count. $where_sql is built only from the hardcoded clauses above; all values are bound via $params.
		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders come from constant clauses; values are bound.
			$count_sql = $wpdb->prepare( $count_sql, $params );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql is prepared (or a constant query with no user input).
		$total = (int) $wpdb->get_var( $count_sql );

		// Page of rows.
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$rows_sql      = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows_params   = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table() and $where_sql clauses are constants; all values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete rows older than the retention window. Only removes settled rows.
	 *
	 * @param int $days Retention in days. 0 disables cleanup.
	 * @return int Number of rows deleted.
	 */
	public static function cleanup( $days ) {
		global $wpdb;

		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}

		// created_at is stored in UTC, so compare against a UTC cutoff.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table() is a constant table name; all values are prepared.
		$sql = $wpdb->prepare(
			'DELETE FROM ' . self::table() . '
			WHERE created_at < %s
			AND status IN ( %s, %s )',
			$cutoff,
			self::STATUS_SENT,
			self::STATUS_SPAM
		);

		$result = (int) $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return $result;
	}
}
