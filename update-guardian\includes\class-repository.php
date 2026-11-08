<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for update events and diagnostic errors.
 */
class Update_Guardian_Repository {
	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Create a pre-update event.
	 *
	 * @param array $data Event fields.
	 * @return int|WP_Error
	 */
	public function create_event( array $data ) {
		global $wpdb;

		$now  = self::now();
		$data = wp_parse_args(
			$data,
			array(
				'batch_id'            => '',
				'plugin_file'         => '',
				'plugin_slug'         => '',
				'plugin_name'         => '',
				'old_version'         => '',
				'new_version'         => '',
				'backup_id'           => '',
				'backup_type'         => '',
				'status'              => 'pending',
				'health_status'       => 'unknown',
				'risk_score'          => 0,
				'error_count'         => 0,
				'last_error'          => '',
				'health_message'      => '',
				'recovery_token_hash' => '',
				'created_at'          => $now,
				'updated_at'          => $now,
				'metadata'            => wp_json_encode( array() ),
			)
		);

		$result = $wpdb->insert(
			Update_Guardian_Database::events_table(),
			array(
				'batch_id'            => sanitize_text_field( $data['batch_id'] ),
				'plugin_file'         => sanitize_text_field( $data['plugin_file'] ),
				'plugin_slug'         => sanitize_key( $data['plugin_slug'] ),
				'plugin_name'         => sanitize_text_field( $data['plugin_name'] ),
				'old_version'         => sanitize_text_field( $data['old_version'] ),
				'new_version'         => sanitize_text_field( $data['new_version'] ),
				'backup_id'           => sanitize_text_field( $data['backup_id'] ),
				'backup_type'         => sanitize_key( $data['backup_type'] ),
				'status'              => sanitize_key( $data['status'] ),
				'health_status'       => sanitize_key( $data['health_status'] ),
				'risk_score'          => absint( $data['risk_score'] ),
				'error_count'         => absint( $data['error_count'] ),
				'last_error'          => wp_kses_post( $data['last_error'] ),
				'health_message'      => wp_kses_post( $data['health_message'] ),
				'recovery_token_hash' => preg_replace( '/[^a-f0-9]/', '', (string) $data['recovery_token_hash'] ),
				'created_at'          => $data['created_at'],
				'updated_at'          => $data['updated_at'],
				'metadata'            => wp_json_encode( $data['metadata'] ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'update_guardian_db_error', __( 'Could not save the update record.', 'update-guardian' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a recent pending event for a plugin.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return array|null
	 */
	public function find_pending_event( $plugin_file ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE plugin_file = %s AND status = 'pending' ORDER BY id DESC LIMIT 1",
				$plugin_file
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Mark an update as completed.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $version New version.
	 * @return void
	 */
	public function mark_updated( $event_id, $version ) {
		global $wpdb;

		$wpdb->update(
			Update_Guardian_Database::events_table(),
			array(
				'status'      => 'updated',
				'new_version' => sanitize_text_field( $version ),
				'updated_at'  => self::now(),
			),
			array( 'id' => absint( $event_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get an event by ID.
	 *
	 * @param int $event_id Event ID.
	 * @return array|null
	 */
	public function find( $event_id ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $event_id ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get events by batch.
	 *
	 * @param string $batch_id Batch ID.
	 * @return array
	 */
	public function find_batch( $batch_id ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s ORDER BY id ASC", sanitize_text_field( $batch_id ) ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Recent events.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function recent( $limit = 50 ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, absint( $limit ) ) ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Events that happened within a given number of minutes.
	 *
	 * @param int $minutes Window in minutes.
	 * @return array
	 */
	public function recent_within( $minutes ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$time  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s AND status IN ('pending', 'updated') ORDER BY id DESC",
				$time
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Recent errors.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function recent_errors( $limit = 50 ) {
		global $wpdb;

		$table = Update_Guardian_Database::errors_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, absint( $limit ) ) ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Count errors attached to an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function count_errors( $event_id ) {
		global $wpdb;

		$table = Update_Guardian_Database::errors_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", absint( $event_id ) )
		);
	}

	/**
	 * Get the latest error attached to an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array|null
	 */
	public function latest_error_for_event( $event_id ) {
		global $wpdb;

		$table = Update_Guardian_Database::errors_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY id DESC LIMIT 1",
				absint( $event_id )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Add a diagnostic error and update the associated event.
	 *
	 * @param array $data Error fields.
	 * @return int|false
	 */
	public function add_error( array $data ) {
		global $wpdb;

		$now = self::now();
		$data = wp_parse_args(
			$data,
			array(
				'event_id'    => 0,
				'plugin_file' => '',
				'plugin_slug' => '',
				'source'      => 'php',
				'error_type'  => '',
				'file_path'   => '',
				'line_number' => 0,
				'message'     => '',
				'stack'       => '',
				'request_url' => '',
				'created_at'  => $now,
			)
		);

		$result = $wpdb->insert(
			Update_Guardian_Database::errors_table(),
			array(
				'event_id'    => absint( $data['event_id'] ),
				'plugin_file' => sanitize_text_field( $data['plugin_file'] ),
				'plugin_slug' => sanitize_key( $data['plugin_slug'] ),
				'source'      => sanitize_key( $data['source'] ),
				'error_type'  => sanitize_text_field( $data['error_type'] ),
				'file_path'   => sanitize_textarea_field( $data['file_path'] ),
				'line_number' => absint( $data['line_number'] ),
				'message'     => sanitize_textarea_field( $data['message'] ),
				'stack'       => sanitize_textarea_field( $data['stack'] ),
				'request_url' => esc_url_raw( $data['request_url'] ),
				'created_at'  => $data['created_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( ! empty( $data['event_id'] ) ) {
			$table = Update_Guardian_Database::events_table();
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET error_count = error_count + 1,
						health_status = 'failed',
						risk_score = GREATEST(risk_score, 90),
						last_error = %s,
						updated_at = %s
					WHERE id = %d",
					substr( sanitize_textarea_field( $data['message'] ), 0, 2000 ),
					$now,
					absint( $data['event_id'] )
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update health scan results.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status Health status.
	 * @param int    $risk Risk score from 0 to 100.
	 * @param string $message Human-readable reason.
	 * @return void
	 */
	public function update_health( $event_id, $status, $risk, $message = '' ) {
		global $wpdb;

		$wpdb->update(
			Update_Guardian_Database::events_table(),
			array(
				'health_status'  => sanitize_key( $status ),
				'risk_score'     => min( 100, absint( $risk ) ),
				'health_message' => sanitize_textarea_field( $message ),
				'updated_at'     => self::now(),
			),
			array( 'id' => absint( $event_id ) ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update event state after a restore.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status New status.
	 * @param string $message Result message.
	 * @return void
	 */
	public function update_restore_state( $event_id, $status, $message = '' ) {
		global $wpdb;

		$restored = in_array( $status, array( 'rolled_back', 'restored' ), true );
		$wpdb->update(
			Update_Guardian_Database::events_table(),
			array(
				'status'         => sanitize_key( $status ),
				'health_status'  => $restored ? 'healthy' : 'failed',
				'risk_score'     => $restored ? 0 : 100,
				'health_message' => sanitize_textarea_field( $message ),
				'updated_at'     => self::now(),
			),
			array( 'id' => absint( $event_id ) ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store a hashed single-use recovery token.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $token_hash SHA-256 hash.
	 * @return void
	 */
	public function set_recovery_token_hash( $event_id, $token_hash ) {
		global $wpdb;

		$wpdb->update(
			Update_Guardian_Database::events_table(),
			array(
				'recovery_token_hash' => preg_replace( '/[^a-f0-9]/', '', $token_hash ),
				'updated_at'          => self::now(),
			),
			array( 'id' => absint( $event_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Find the most relevant recent event for a plugin slug.
	 *
	 * @param string $plugin_slug Plugin directory slug.
	 * @param int    $minutes Lookback window.
	 * @return array|null
	 */
	public function find_recent_by_slug( $plugin_slug, $minutes = 360 ) {
		global $wpdb;

		$table = Update_Guardian_Database::events_table();
		$time  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $minutes ) ) * MINUTE_IN_SECONDS ) );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE plugin_slug = %s AND created_at >= %s AND status IN ('pending', 'updated')
				ORDER BY id DESC LIMIT 1",
				sanitize_key( $plugin_slug ),
				$time
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete old events and their errors.
	 *
	 * @param int $days Retention days.
	 * @return void
	 */
	public function prune_events( $days ) {
		global $wpdb;

		$events_table = Update_Guardian_Database::events_table();
		$errors_table = Update_Guardian_Database::errors_table();
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		$ids          = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$events_table} WHERE created_at < %s", $cutoff )
		);

		if ( empty( $ids ) ) {
			return;
		}

		$id_list = implode( ',', array_map( 'absint', $ids ) );
		$wpdb->query( "DELETE FROM {$errors_table} WHERE event_id IN ({$id_list})" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events_table} WHERE created_at < %s", $cutoff ) );
	}
}

