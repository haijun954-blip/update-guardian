<?php
/**
 * Plugin Name: Update Guardian
 * Plugin URI:  https://github.com/haijun954-blip/update-guardian
 * Description: Snapshot plugin updates, detect likely regressions, and restore the previous version with one click.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Update Guardian Team
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: update-guardian
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UPDATE_GUARDIAN_VERSION' ) ) {
	define( 'UPDATE_GUARDIAN_VERSION', '0.1.0' );
}
if ( ! defined( 'UPDATE_GUARDIAN_FILE' ) ) {
	define( 'UPDATE_GUARDIAN_FILE', __FILE__ );
}
if ( ! defined( 'UPDATE_GUARDIAN_DIR' ) ) {
	define( 'UPDATE_GUARDIAN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'UPDATE_GUARDIAN_URL' ) ) {
	define( 'UPDATE_GUARDIAN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'UPDATE_GUARDIAN_STORAGE_DIR' ) ) {
	define( 'UPDATE_GUARDIAN_STORAGE_DIR', WP_CONTENT_DIR . '/update-guardian' );
}
if ( ! defined( 'UPDATE_GUARDIAN_MU_PLUGIN' ) ) {
	define( 'UPDATE_GUARDIAN_MU_PLUGIN', WPMU_PLUGIN_DIR . '/update-guardian-recovery.php' );
}

/**
 * Load plugin classes without requiring Composer.
 *
 * @param string $class_name Class name.
 * @return void
 */
function update_guardian_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Update_Guardian_' ) ) {
		return;
	}

	$relative = strtolower( str_replace( '_', '-', substr( $class_name, 16 ) ) );
	$file     = UPDATE_GUARDIAN_DIR . 'includes/class-' . $relative . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'update_guardian_autoload' );

register_activation_hook( __FILE__, array( 'Update_Guardian_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Update_Guardian_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Update_Guardian_Plugin::instance()->boot();
	}
);

