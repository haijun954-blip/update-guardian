<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures snapshots around WordPress plugin updates.
 */
class Update_Guardian_Update_Monitor {
	/**
	 * Repository.
	 *
	 * @var Update_Guardian_Repository
	 */
	private $repository;

	/**
	 * ID shared by plugins updated in the same request.
	 *
	 * @var string
	 */
	private static $batch_id = '';

	/**
	 * Constructor.
	 *
	 * @param Update_Guardian_Repository $repository Repository.
	 */
	public function __construct( Update_Guardian_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'upgrader_pre_install', array( $this, 'capture_before_update' ), 5, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'capture_after_update' ), 5, 2 );
	}

	/**
	 * Snapshot each plugin before the upgrader replaces its files.
	 *
	 * @param true|WP_Error $response Upgrader response.
	 * @param array         $hook_extra Hook context.
	 * @return true|WP_Error
	 */
	public function capture_before_update( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return $response;
		}

		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return $response;
		}

		if ( empty( self::$batch_id ) ) {
			self::$batch_id = 'batch-' . gmdate( 'YmdHis' ) . '-' . strtolower( substr( wp_generate_password( 12, false, false ), 0, 8 ) );
		}

		foreach ( $this->plugin_files_from_hook( $hook_extra ) as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! file_exists( $plugin_path ) ) {
				continue;
			}

			$plugin_data = get_file_data(
				$plugin_path,
				array(
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				)
			);

			$version  = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
			$name     = isset( $plugin_data['Name'] ) && $plugin_data['Name'] ? $plugin_data['Name'] : $plugin_file;
			$snapshot = Update_Guardian_Backup_Manager::create_for_plugin( $plugin_file, $version );
			$settings = Update_Guardian_Plugin::get_settings();

			if ( is_wp_error( $snapshot ) ) {
				if ( ! empty( $settings['block_on_backup_failure'] ) ) {
					return new WP_Error(
						'update_guardian_snapshot_failed',
						sprintf(
							/* translators: 1: plugin name, 2: error message */
							__( 'The update for "%1$s" was blocked because a safe snapshot could not be created: %2$s', 'update-guardian' ),
							$name,
							$snapshot->get_error_message()
						)
					);
				}

				$snapshot = array(
					'backup_id'   => '',
					'backup_type' => '',
					'file_count'  => 0,
					'byte_count'  => 0,
					'warnings'    => array( $snapshot->get_error_message() ),
				);
			}

			$parts = explode( '/', $plugin_file );
			$slug  = count( $parts ) > 1 ? $parts[0] : basename( $plugin_file, '.php' );
			$result = $this->repository->create_event(
				array(
					'batch_id'    => self::$batch_id,
					'plugin_file' => $plugin_file,
					'plugin_slug' => $slug,
					'plugin_name' => $name,
					'old_version' => $version,
					'backup_id'   => $snapshot['backup_id'],
					'backup_type' => $snapshot['backup_type'],
					'metadata'    => array(
						'file_count' => $snapshot['file_count'],
						'byte_count' => $snapshot['byte_count'],
						'warnings'   => $snapshot['warnings'],
					),
				)
			);

			if ( is_wp_error( $result ) && ! empty( $settings['block_on_backup_failure'] ) ) {
				return new WP_Error(
					'update_guardian_event_failed',
					sprintf(
						/* translators: 1: plugin name, 2: error message */
						__( 'The update for "%1$s" was blocked because its rollback record could not be saved: %2$s', 'update-guardian' ),
						$name,
						$result->get_error_message()
					)
				);
			}
		}

		return $response;
	}

	/**
	 * Finalize records after the upgrader has installed new files.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $hook_extra Hook context.
	 * @return void
	 */
	public function capture_after_update( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$events = ! empty( self::$batch_id ) ? $this->repository->find_batch( self::$batch_id ) : array();
		if ( empty( $events ) ) {
			foreach ( $this->plugin_files_from_hook( $hook_extra ) as $plugin_file ) {
				$pending = $this->repository->find_pending_event( $plugin_file );
				if ( $pending ) {
					$events[] = $pending;
				}
			}
		}

		$updated_ids = array();
		$email_rows  = array();

		foreach ( $events as $event ) {
			if ( 'pending' !== $event['status'] ) {
				continue;
			}

			$plugin_path = WP_PLUGIN_DIR . '/' . $event['plugin_file'];
			if ( ! file_exists( $plugin_path ) ) {
				continue;
			}

			$plugin_data = get_file_data(
				$plugin_path,
				array(
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				)
			);
			$new_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';

			$this->repository->mark_updated( (int) $event['id'], $new_version );
			$updated_ids[] = (int) $event['id'];

			$email_rows[] = array(
				'event_id' => (int) $event['id'],
				'name'     => isset( $plugin_data['Name'] ) && $plugin_data['Name'] ? $plugin_data['Name'] : $event['plugin_name'],
				'old'      => $event['old_version'],
				'new'      => $new_version,
			);
		}

		if ( empty( $updated_ids ) ) {
			return;
		}

		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'update_guardian_health_check', array( $updated_ids ) );
		$this->maybe_send_recovery_email( $email_rows );
	}

	/**
	 * Extract plugin files from an upgrader hook context.
	 *
	 * @param array $hook_extra Hook context.
	 * @return array
	 */
	private function plugin_files_from_hook( $hook_extra ) {
		$files = array();

		if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$files[] = $hook_extra['plugin'];
		}

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$files = array_merge( $files, $hook_extra['plugins'] );
		}

		$files = array_map( 'sanitize_text_field', $files );
		return array_values( array_unique( array_filter( $files ) ) );
	}

	/**
	 * Email recovery links immediately after the update.
	 *
	 * @param array $rows Updated plugin summaries.
	 * @return void
	 */
	private function maybe_send_recovery_email( array $rows ) {
		$settings = Update_Guardian_Plugin::get_settings();
		if ( empty( $settings['capture_recovery_email'] ) ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$lines   = array();
		$lines[] = sprintf( __( '%s has just completed a plugin update.', 'update-guardian' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$lines[] = '';
		$lines[] = __( 'If the site shows character corruption, broken layout, an Elementor editor failure, or a missing feature after the update, use these emergency recovery links:', 'update-guardian' );
		$lines[] = '';

		foreach ( $rows as $row ) {
			$url     = Update_Guardian_Recovery_Service::issue_recovery_url( $row['event_id'] );
			$lines[] = sprintf( '%1$s：%2$s → %3$s', $row['name'], $row['old'], $row['new'] );
			if ( ! is_wp_error( $url ) ) {
				$lines[] = $url;
			} else {
				$lines[] = __( 'The recovery link could not be generated. Sign in to WordPress admin to continue.', 'update-guardian' );
			}
			$lines[] = '';
		}

		$lines[] = __( 'Each link becomes invalid after use. If WordPress admin is still available, you can roll back from the Update Guardian screen.', 'update-guardian' );
		$lines[] = admin_url( 'admin.php?page=update-guardian' );

		wp_mail(
			$admin_email,
			sprintf( __( '[%s] Plugin update complete with a saved safety snapshot', 'update-guardian' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			implode( "\n", $lines )
		);
	}
}

