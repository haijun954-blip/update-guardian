<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema management.
 */
class Update_Guardian_Database {
	const SCHEMA_VERSION = '1';

	/**
	 * Install or upgrade database tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events_table    = self::events_table();
		$errors_table    = self::errors_table();

		$events_sql = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id varchar(64) NOT NULL DEFAULT '',
			plugin_file varchar(191) NOT NULL,
			plugin_slug varchar(191) NOT NULL,
			plugin_name varchar(255) NOT NULL DEFAULT '',
			old_version varchar(64) NOT NULL DEFAULT '',
			new_version varchar(64) NOT NULL DEFAULT '',
			backup_id varchar(191) NOT NULL DEFAULT '',
			backup_type varchar(16) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			health_status varchar(32) NOT NULL DEFAULT 'unknown',
			risk_score smallint(5) unsigned NOT NULL DEFAULT 0,
			error_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			health_message text NULL,
			recovery_token_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			metadata longtext NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY plugin_slug (plugin_slug),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$errors_sql = "CREATE TABLE {$errors_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			plugin_file varchar(191) NOT NULL DEFAULT '',
			plugin_slug varchar(191) NOT NULL DEFAULT '',
			source varchar(32) NOT NULL DEFAULT 'php',
			error_type varchar(64) NOT NULL DEFAULT '',
			file_path text NULL,
			line_number int(10) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			stack longtext NULL,
			request_url text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY plugin_slug (plugin_slug),
			KEY source (source),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $events_sql );
		dbDelta( $errors_sql );

		update_option( 'update_guardian_schema_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Events table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'update_guardian_events';
	}

	/**
	 * Errors table name.
	 *
	 * @return string
	 */
	public static function errors_table() {
		global $wpdb;
		return $wpdb->prefix . 'update_guardian_errors';
	}
}

