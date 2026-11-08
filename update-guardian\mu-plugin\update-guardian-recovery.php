<?php
/**
 * Plugin Name: Update Guardian Emergency Recovery
 * Description: Loads a minimal recovery handler before regular WordPress plugins.
 * Version: 0.1.0
 *
 * UPDATE_GUARDIAN_RECOVERY_MU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UPDATE_GUARDIAN_VERSION' ) ) {
	define( 'UPDATE_GUARDIAN_VERSION', '0.1.0' );
}
if ( ! defined( 'UPDATE_GUARDIAN_DIR' ) ) {
	define( 'UPDATE_GUARDIAN_DIR', WP_PLUGIN_DIR . '/update-guardian/' );
}
if ( ! defined( 'UPDATE_GUARDIAN_STORAGE_DIR' ) ) {
	define( 'UPDATE_GUARDIAN_STORAGE_DIR', WP_CONTENT_DIR . '/update-guardian' );
}

$update_guardian_backup = UPDATE_GUARDIAN_DIR . 'includes/class-backup-manager.php';
$update_guardian_db     = UPDATE_GUARDIAN_DIR . 'includes/class-database.php';
$update_guardian_repo   = UPDATE_GUARDIAN_DIR . 'includes/class-repository.php';
$update_guardian_rec    = UPDATE_GUARDIAN_DIR . 'includes/class-recovery-service.php';

if (
	file_exists( $update_guardian_backup ) &&
	file_exists( $update_guardian_db ) &&
	file_exists( $update_guardian_repo ) &&
	file_exists( $update_guardian_rec )
) {
	require_once $update_guardian_backup;
	require_once $update_guardian_db;
	require_once $update_guardian_repo;
	require_once $update_guardian_rec;

	Update_Guardian_Recovery_Service::handle_request();
	Update_Guardian_Recovery_Service::watch_fatal();
}

