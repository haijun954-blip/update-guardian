<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

wp_clear_scheduled_hook( 'update_guardian_health_check' );
wp_clear_scheduled_hook( 'update_guardian_daily_cleanup' );

$mu_plugin = WPMU_PLUGIN_DIR . '/update-guardian-recovery.php';
if ( file_exists( $mu_plugin ) ) {
	$content = (string) file_get_contents( $mu_plugin );
	if ( false !== strpos( $content, 'UPDATE_GUARDIAN_RECOVERY_MU' ) ) {
		@unlink( $mu_plugin );
	}
}

$options = array(
	'update_guardian_settings',
	'update_guardian_version',
	'update_guardian_schema_version',
	'update_guardian_last_health_scan',
	'update_guardian_mu_install_error',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_ug_%'
	OR option_name LIKE '_transient_timeout_ug_%'"
);

$events_table = $wpdb->prefix . 'update_guardian_events';
$errors_table = $wpdb->prefix . 'update_guardian_errors';
$wpdb->query( "DROP TABLE IF EXISTS {$errors_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );

$storage = WP_CONTENT_DIR . '/update-guardian';
$real    = realpath( $storage );
$content = realpath( WP_CONTENT_DIR );

if ( false !== $real && false !== $content ) {
	$real    = wp_normalize_path( $real );
	$content = trailingslashit( wp_normalize_path( $content ) );

	if ( 0 === strpos( $real, $content ) && 'update-guardian' === basename( $real ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $real );
	}
}

