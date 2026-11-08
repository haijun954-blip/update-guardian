<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin coordinator.
 */
class Update_Guardian_Plugin {
	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Repository.
	 *
	 * @var Update_Guardian_Repository
	 */
	private $repository;

	/**
	 * Health scanner.
	 *
	 * @var Update_Guardian_Health_Scanner
	 */
	private $health_scanner;

	/**
	 * Whether boot has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'block_on_backup_failure' => 1,
			'capture_recovery_email'  => 1,
			'monitor_php_errors'      => 1,
			'monitor_js_errors'       => 1,
			'monitor_window_minutes'  => 360,
			'retention_days'          => 14,
			'max_backups'              => 30,
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( 'update_guardian_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		Update_Guardian_Database::install();
		Update_Guardian_Backup_Manager::initialize_storage();

		$settings = self::get_settings();
		add_option( 'update_guardian_settings', $settings, '', false );
		add_option( 'update_guardian_version', UPDATE_GUARDIAN_VERSION, '', false );

		$mu_result = Update_Guardian_Recovery_Service::install_mu_plugin();
		if ( is_wp_error( $mu_result ) ) {
			update_option( 'update_guardian_mu_install_error', $mu_result->get_error_message(), false );
		} else {
			delete_option( 'update_guardian_mu_install_error' );
		}
	}

	/**
	 * Deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'update_guardian_health_check' );
		wp_clear_scheduled_hook( 'update_guardian_daily_cleanup' );
	}

	/**
	 * Boot the plugin once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->maybe_upgrade();

		$this->repository     = new Update_Guardian_Repository();
		$this->health_scanner = new Update_Guardian_Health_Scanner( $this->repository );

		$update_monitor = new Update_Guardian_Update_Monitor( $this->repository );
		$error_monitor  = new Update_Guardian_Error_Monitor( $this->repository );
		$admin_page     = new Update_Guardian_Admin_Page( $this->repository, $this->health_scanner );

		$update_monitor->register();
		$error_monitor->register();
		$this->health_scanner->register();
		$admin_page->register();

		add_action( 'update_guardian_daily_cleanup', array( $this, 'run_cleanup' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UPDATE_GUARDIAN_FILE ), array( $this, 'plugin_action_links' ) );

		if ( ! wp_next_scheduled( 'update_guardian_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'update_guardian_daily_cleanup' );
		}
	}

	/**
	 * Install schema and recovery loader after a plugin upgrade.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		$installed_version = get_option( 'update_guardian_version', '0' );
		$schema_version    = get_option( 'update_guardian_schema_version', '0' );

		if ( version_compare( $installed_version, UPDATE_GUARDIAN_VERSION, '<' ) || Update_Guardian_Database::SCHEMA_VERSION !== $schema_version ) {
			Update_Guardian_Database::install();
			Update_Guardian_Backup_Manager::initialize_storage();
			$mu_result = Update_Guardian_Recovery_Service::install_mu_plugin();

			if ( is_wp_error( $mu_result ) ) {
				update_option( 'update_guardian_mu_install_error', $mu_result->get_error_message(), false );
			} else {
				delete_option( 'update_guardian_mu_install_error' );
			}

			update_option( 'update_guardian_version', UPDATE_GUARDIAN_VERSION, false );
		}
	}

	/**
	 * Daily snapshot and log cleanup.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		$settings = self::get_settings();
		Update_Guardian_Backup_Manager::prune( $settings['retention_days'], $settings['max_backups'] );

		$repository = new Update_Guardian_Repository();
		$repository->prune_events( max( 30, (int) $settings['retention_days'] + 7 ) );
	}

	/**
	 * Add settings link to the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=update-guardian' ) ) . '">' . esc_html__( 'Update Guard', 'update-guardian' ) . '</a>'
		);
		return $links;
	}
}

