<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs lightweight post-update health checks and assigns suspicion scores.
 */
class Update_Guardian_Health_Scanner {
	/**
	 * Repository.
	 *
	 * @var Update_Guardian_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Update_Guardian_Repository $repository Repository.
	 */
	public function __construct( Update_Guardian_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register scheduled health checks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'update_guardian_health_check', array( $this, 'scan_events' ), 10, 1 );
	}

	/**
	 * Scan events by ID. Empty input scans all recent updates.
	 *
	 * @param array $event_ids Event IDs.
	 * @return array
	 */
	public function scan_events( $event_ids = array() ) {
		$event_ids = is_array( $event_ids ) ? array_map( 'absint', $event_ids ) : array( absint( $event_ids ) );
		$events    = array();

		if ( empty( $event_ids ) ) {
			foreach ( $this->repository->recent( 20 ) as $event ) {
				if ( in_array( $event['status'], array( 'pending', 'updated' ), true ) ) {
					$events[] = $event;
				}
			}
		} else {
			foreach ( array_unique( $event_ids ) as $event_id ) {
				$event = $this->repository->find( $event_id );
				if ( $event ) {
					$events[] = $event;
				}
			}
		}

		if ( empty( $events ) ) {
			return array(
				'status'  => 'idle',
				'message' => __( 'No updates need a health check.', 'update-guardian' ),
			);
		}

		$groups = array();
		foreach ( $events as $event ) {
			$key            = $event['batch_id'] ? $event['batch_id'] : 'event-' . $event['id'];
			$groups[ $key ] = isset( $groups[ $key ] ) ? $groups[ $key ] : array();
			$groups[ $key ][] = $event;
		}

		$summary = array(
			'status'   => 'healthy',
			'message'  => __( 'No obvious problems were found.', 'update-guardian' ),
			'checked'  => 0,
			'failed'   => 0,
			'warnings' => 0,
		);

		foreach ( $groups as $group ) {
			$probe = $this->probe_site();

			foreach ( $group as $index => $event ) {
				$error_count = $this->repository->count_errors( (int) $event['id'] );
				$latest      = $error_count ? $this->repository->latest_error_for_event( (int) $event['id'] ) : null;

				if ( $error_count > 0 ) {
					$message = $latest && ! empty( $latest['message'] )
						? $latest['message']
						: __( 'This plugin produced an error after the update.', 'update-guardian' );
					$this->repository->update_health( (int) $event['id'], 'failed', 95, $message );
					$summary['failed']++;
					$summary['status'] = 'failed';
					$summary['message'] = __( 'A plugin runtime error was detected. Rollback is recommended.', 'update-guardian' );
				} elseif ( 'failed' === $probe['status'] ) {
					$risk = count( $group ) > 1 ? max( 20, 55 - ( $index * 8 ) ) : 60;
					$this->repository->update_health( (int) $event['id'], 'failed', $risk, $probe['message'] );
					$summary['failed']++;
					$summary['status'] = 'failed';
					$summary['message'] = $probe['message'];
				} elseif ( 'warning' === $probe['status'] ) {
					$risk = count( $group ) > 1 ? max( 15, 40 - ( $index * 6 ) ) : 45;
					$this->repository->update_health( (int) $event['id'], 'warning', $risk, $probe['message'] );
					$summary['warnings']++;
					if ( 'healthy' === $summary['status'] ) {
						$summary['status']  = 'warning';
						$summary['message'] = $probe['message'];
					}
				} elseif ( 'unknown' === $probe['status'] ) {
					$this->repository->update_health( (int) $event['id'], 'unknown', 0, $probe['message'] );
				} else {
					$this->repository->update_health( (int) $event['id'], 'healthy', 5, $probe['message'] );
				}

				$summary['checked']++;
			}
		}

		$result = array_merge(
			$summary,
			array(
				'timestamp' => time(),
				'probe'     => $probe,
			)
		);

		update_option( 'update_guardian_last_health_scan', $result, false );
		return $result;
	}

	/**
	 * Probe the public site and REST endpoint.
	 *
	 * @return array
	 */
	private function probe_site() {
		$front = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'user-agent'  => 'Update-Guardian/' . UPDATE_GUARDIAN_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $front ) ) {
			return array(
				'status'  => 'unknown',
				'message' => __( 'The server could not probe its own home page. The host may block loopback requests.', 'update-guardian' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $front );
		$body = (string) wp_remote_retrieve_body( $front );

		if ( $code >= 500 ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf( __( 'The home page returned HTTP %d after the update.', 'update-guardian' ), $code ),
			);
		}

		if ( strlen( $body ) < 200 ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'The home page is nearly blank after the update. A fatal error may have occurred.', 'update-guardian' ),
			);
		}

		if ( preg_match( '/There has been a critical error|致命错误/i', $body ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'The home page shows a WordPress critical error.', 'update-guardian' ),
			);
		}

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $body, 'UTF-8' ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'The home page is not valid UTF-8. Character corruption is likely.', 'update-guardian' ),
			);
		}

		$text = trim( wp_strip_all_tags( $body ) );
		if ( preg_match( '/锟斤拷|ï¿½|â€|Ã[\x{0080}-\x{00BF}]/u', $text ) ) {
			return array(
				'status'  => 'warning',
				'message' => __( 'The home page contains characters associated with encoding corruption. Check the site encoding and language files.', 'update-guardian' ),
			);
		}

		if ( false === stripos( $body, '</html>' ) ) {
			return array(
				'status'  => 'warning',
				'message' => __( 'The home page HTML is incomplete. An error or plugin output may have interrupted it.', 'update-guardian' ),
			);
		}

		$rest = wp_remote_get(
			rest_url(),
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'user-agent'  => 'Update-Guardian/' . UPDATE_GUARDIAN_VERSION,
			)
		);

		if ( ! is_wp_error( $rest ) ) {
			$rest_code = (int) wp_remote_retrieve_response_code( $rest );
			if ( $rest_code >= 500 ) {
				return array(
					'status'  => 'failed',
					'message' => sprintf( __( 'The WordPress REST API returned HTTP %d.', 'update-guardian' ), $rest_code ),
				);
			}
		}

		return array(
			'status'  => 'healthy',
			'message' => __( 'The home page and REST API respond normally.', 'update-guardian' ),
		);
	}
}

