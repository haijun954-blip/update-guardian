<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone recovery entry loaded from a must-use plugin.
 */
class Update_Guardian_Recovery_Service {
	const MU_MARKER = 'UPDATE_GUARDIAN_RECOVERY_MU';

	/**
	 * Install the must-use recovery loader.
	 *
	 * @return true|WP_Error
	 */
	public static function install_mu_plugin() {
		$source = UPDATE_GUARDIAN_DIR . 'mu-plugin/update-guardian-recovery.php';
		$target = UPDATE_GUARDIAN_MU_PLUGIN;

		if ( ! is_file( $source ) ) {
			return new WP_Error( 'update_guardian_mu_source', __( 'The emergency recovery template does not exist.', 'update-guardian' ) );
		}

		if ( file_exists( $target ) ) {
			$existing = (string) file_get_contents( $target );
			if ( false === strpos( $existing, self::MU_MARKER ) ) {
				return new WP_Error(
					'update_guardian_mu_conflict',
					__( 'An emergency recovery file already exists and is not owned by Update Guardian. It was not overwritten.', 'update-guardian' )
				);
			}
		}

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'update_guardian_mu_dir', __( 'Could not create the must-use plugin directory.', 'update-guardian' ) );
		}

		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'update_guardian_mu_copy', __( 'Could not install the emergency recovery entry point.', 'update-guardian' ) );
		}

		@chmod( $target, 0644 );
		return true;
	}

	/**
	 * Remove our must-use recovery loader.
	 *
	 * @return bool
	 */
	public static function remove_mu_plugin() {
		if ( ! file_exists( UPDATE_GUARDIAN_MU_PLUGIN ) ) {
			return true;
		}

		$content = (string) file_get_contents( UPDATE_GUARDIAN_MU_PLUGIN );
		if ( false === strpos( $content, self::MU_MARKER ) ) {
			return false;
		}

		return @unlink( UPDATE_GUARDIAN_MU_PLUGIN );
	}

	/**
	 * Issue a new single-use recovery URL for an update event.
	 *
	 * @param int $event_id Event ID.
	 * @return string|WP_Error
	 */
	public static function issue_recovery_url( $event_id ) {
		$repository = new Update_Guardian_Repository();
		$event      = $repository->find( $event_id );

		if ( ! $event || empty( $event['backup_id'] ) || 'rolled_back' === $event['status'] ) {
			return new WP_Error( 'update_guardian_recovery_unavailable', __( 'No rollback snapshot is available for this update.', 'update-guardian' ) );
		}

		try {
			$raw = random_bytes( 32 );
		} catch ( Exception $exception ) {
			$raw = wp_generate_password( 64, true, true );
		}

		$token = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
		$repository->set_recovery_token_hash( $event_id, hash( 'sha256', $token ) );

		return add_query_arg(
			array(
				'update_guardian_recovery' => '1',
				'event'                    => absint( $event_id ),
				'token'                    => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle a recovery page request before regular plugins load.
	 *
	 * @return void
	 */
	public static function handle_request() {
		if ( empty( $_GET['update_guardian_recovery'] ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$event    = self::get_authorized_event( $event_id, $token );

		if ( is_wp_error( $event ) ) {
			status_header( 403 );
			self::render_message(
				__( 'Invalid recovery link', 'update-guardian' ),
				$event->get_error_message(),
				'error'
			);
			exit;
		}

		if ( isset( $_POST['update_guardian_confirm_rollback'] ) ) {
			$submitted_csrf = isset( $_POST['ug_recovery_csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['ug_recovery_csrf'] ) ) : '';
			$expected_csrf  = self::csrf_token( $event_id, $token );
			if ( empty( $submitted_csrf ) || ! hash_equals( $expected_csrf, $submitted_csrf ) ) {
				status_header( 403 );
				self::render_message(
					__( 'Confirmation expired', 'update-guardian' ),
					__( 'Open the recovery link again and retry.', 'update-guardian' ),
					'error',
					$event
				);
				exit;
			}

			$result = Update_Guardian_Backup_Manager::restore_event( $event );
			if ( is_wp_error( $result ) ) {
				status_header( 500 );
				self::render_message(
					__( 'Rollback was not completed', 'update-guardian' ),
					$result->get_error_message(),
					'error',
					$event
				);
				exit;
			}

			$repository = new Update_Guardian_Repository();
			$repository->update_restore_state(
				$event_id,
				'rolled_back',
				sprintf( __( 'Rolled back to %s through the emergency recovery entry point.', 'update-guardian' ), $result['version'] )
			);
			$repository->set_recovery_token_hash( $event_id, '' );

			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset();
			}

			self::render_message(
				__( 'Rollback complete', 'update-guardian' ),
				sprintf(
					__( '%1$s was restored to %2$s. The faulty version was quarantined. Refresh the site to continue.', 'update-guardian' ),
					esc_html( $event['plugin_name'] ),
					esc_html( $result['version'] )
				),
				'success',
				null,
				home_url( '/' )
			);
			exit;
		}

		self::render_confirmation( $event, $token );
		exit;
	}

	/**
	 * Watch for PHP fatal errors and attach them to a recent plugin update.
	 *
	 * @return void
	 */
	public static function watch_fatal() {
		register_shutdown_function(
			static function () {
				$error = error_get_last();
				if ( ! $error ) {
					return;
				}

				$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
				if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
					return;
				}

				$parsed = self::parse_plugin_path( $error['file'] );
				if ( empty( $parsed['slug'] ) ) {
					return;
				}

				global $wpdb;
				if ( ! $wpdb instanceof wpdb ) {
					return;
				}

				$table = Update_Guardian_Database::events_table();
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				if ( $found !== $table ) {
					return;
				}

				$dedupe = 'ug_fatal_' . md5( $parsed['slug'] . '|' . $error['file'] . '|' . $error['line'] . '|' . $error['message'] );
				if ( get_transient( $dedupe ) ) {
					return;
				}
				set_transient( $dedupe, 1, 10 * MINUTE_IN_SECONDS );

				$settings   = get_option( 'update_guardian_settings', array() );
				if ( isset( $settings['monitor_php_errors'] ) && ! $settings['monitor_php_errors'] ) {
					return;
				}
				$window     = isset( $settings['monitor_window_minutes'] ) ? absint( $settings['monitor_window_minutes'] ) : 360;
				$repository = new Update_Guardian_Repository();
				$event      = $repository->find_recent_by_slug( $parsed['slug'], $window );
				$request    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$request    = strtok( $request, '?' );

				$repository->add_error(
					array(
						'event_id'    => $event ? (int) $event['id'] : 0,
						'plugin_file' => $event ? $event['plugin_file'] : '',
						'plugin_slug' => $parsed['slug'],
						'source'      => 'php',
						'error_type'  => 'fatal_error',
						'file_path'   => $parsed['relative'],
						'line_number' => absint( $error['line'] ),
						'message'     => substr( sanitize_textarea_field( $error['message'] ), 0, 2000 ),
						'stack'       => '',
						'request_url' => esc_url_raw( home_url( $request ) ),
					)
				);
			}
		);
	}

	/**
	 * Validate a recovery token and return the event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $token Raw token.
	 * @return array|WP_Error
	 */
	private static function get_authorized_event( $event_id, $token ) {
		$repository = new Update_Guardian_Repository();
		$event      = $repository->find( $event_id );

		if ( ! $event || empty( $event['recovery_token_hash'] ) || strlen( $token ) < 20 ) {
			return new WP_Error( 'update_guardian_recovery_invalid', __( 'The link does not exist, has already been used, or has expired.', 'update-guardian' ) );
		}

		$actual = hash( 'sha256', $token );
		if ( ! hash_equals( $event['recovery_token_hash'], $actual ) ) {
			return new WP_Error( 'update_guardian_recovery_invalid', __( 'Recovery token validation failed.', 'update-guardian' ) );
		}

		if ( 'rolled_back' === $event['status'] ) {
			return new WP_Error( 'update_guardian_already_restored', __( 'This update has already been rolled back.', 'update-guardian' ) );
		}

		if ( empty( $event['backup_id'] ) ) {
			return new WP_Error( 'update_guardian_no_backup', __( 'No snapshot is available for this update.', 'update-guardian' ) );
		}

		return $event;
	}

	/**
	 * Map an absolute plugin file path to a plugin slug.
	 *
	 * @param string $file Absolute path.
	 * @return array
	 */
	private static function parse_plugin_path( $file ) {
		$file = wp_normalize_path( (string) $file );
		$base = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $base ) ) {
			return array( 'slug' => '', 'relative' => '' );
		}

		$relative = ltrim( substr( $file, strlen( $base ) ), '/' );
		$parts    = explode( '/', $relative );
		$slug     = count( $parts ) > 1 ? $parts[0] : basename( $parts[0], '.php' );

		return array(
			'slug'     => sanitize_key( $slug ),
			'relative' => sanitize_text_field( $relative ),
		);
	}

	/**
	 * Render the recovery confirmation page.
	 *
	 * @param array  $event Event row.
	 * @param string $token Raw recovery token.
	 * @return void
	 */
	private static function render_confirmation( array $event, $token ) {
		$form_url = add_query_arg(
			array(
				'update_guardian_recovery' => '1',
				'event'                    => (int) $event['id'],
				'token'                    => $token,
			),
			home_url( '/' )
		);
		?>
		<!doctype html>
		<html lang="en-US">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Emergency Plugin Rollback', 'update-guardian' ); ?></title>
			<style>
				:root { color-scheme: light; --ink:#1d2327; --muted:#646970; --line:#dcdcde; --blue:#2271b1; --red:#b32d2e; --bg:#f6f7f7; }
				* { box-sizing:border-box; }
				body { margin:0; background:var(--bg); color:var(--ink); font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif; }
				main { width:min(680px,calc(100% - 32px)); margin:10vh auto 40px; background:#fff; border:1px solid var(--line); border-top:4px solid var(--red); padding:32px; }
				h1 { margin:0 0 8px; font-size:24px; line-height:1.25; }
				p { margin:0 0 16px; }
				.meta { border:1px solid var(--line); padding:16px; margin:24px 0; }
				.meta div { display:flex; gap:16px; justify-content:space-between; padding:6px 0; }
				.meta span:first-child { color:var(--muted); }
				.actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:24px; }
				button, .button { appearance:none; border:1px solid var(--blue); background:var(--blue); color:#fff; padding:9px 16px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
				.button.secondary { background:#fff; color:var(--blue); }
				.note { color:var(--muted); font-size:13px; margin-top:18px; }
			</style>
		</head>
		<body>
			<main>
				<h1><?php echo esc_html__( 'Confirm Plugin Update Rollback', 'update-guardian' ); ?></h1>
				<p><?php echo esc_html__( 'This restores the plugin files from before the update. The faulty version remains in a quarantine directory for investigation.', 'update-guardian' ); ?></p>
				<div class="meta">
					<div><span><?php echo esc_html__( 'Plugin', 'update-guardian' ); ?></span><strong><?php echo esc_html( $event['plugin_name'] ); ?></strong></div>
					<div><span><?php echo esc_html__( 'Version', 'update-guardian' ); ?></span><strong><?php echo esc_html( $event['old_version'] ); ?> → <?php echo esc_html( $event['new_version'] ); ?></strong></div>
					<div><span><?php echo esc_html__( 'Update batch', 'update-guardian' ); ?></span><code><?php echo esc_html( $event['batch_id'] ); ?></code></div>
				</div>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>">
					<input type="hidden" name="ug_recovery_csrf" value="<?php echo esc_attr( self::csrf_token( (int) $event['id'], $token ) ); ?>">
					<div class="actions">
						<button type="submit" name="update_guardian_confirm_rollback" value="1"><?php echo esc_html__( 'Restore the previous version', 'update-guardian' ); ?></button>
						<a class="button secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html__( 'Not now', 'update-guardian' ); ?></a>
					</div>
				</form>
				<p class="note"><?php echo esc_html__( 'This does not undo database migrations performed by the plugin. Keep a database backup when an update changes stored data.', 'update-guardian' ); ?></p>
			</main>
		</body>
		</html>
		<?php
	}

	/**
	 * Build a CSRF token that is safe to use before pluggable.php is loaded.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $recovery_token Recovery token.
	 * @return string
	 */
	private static function csrf_token( $event_id, $recovery_token ) {
		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH;
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$secret .= SECURE_AUTH_KEY;
		}

		return hash_hmac( 'sha256', absint( $event_id ) . '|' . $recovery_token, $secret );
	}

	/**
	 * Render a standalone status page.
	 *
	 * @param string      $title Page title.
	 * @param string      $message Message.
	 * @param string      $type success or error.
	 * @param array|null  $event Optional event.
	 * @param string|null $home_url Optional home link.
	 * @return void
	 */
	private static function render_message( $title, $message, $type, $event = null, $home_url = null ) {
		$color = 'success' === $type ? '#008a20' : '#b32d2e';
		?>
		<!doctype html>
		<html lang="en-US">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ); ?></title>
			<style>
				body { margin:0; background:#f6f7f7; color:#1d2327; font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif; }
				main { width:min(640px,calc(100% - 32px)); margin:12vh auto 40px; padding:32px; background:#fff; border:1px solid #dcdcde; border-top:4px solid <?php echo esc_attr( $color ); ?>; }
				h1 { margin:0 0 12px; font-size:24px; }
				p { margin:0 0 18px; }
				a { color:#2271b1; }
			</style>
		</head>
		<body>
			<main>
				<h1><?php echo esc_html( $title ); ?></h1>
				<p><?php echo wp_kses_post( $message ); ?></p>
				<?php if ( $event ) : ?>
					<p><strong><?php echo esc_html( $event['plugin_name'] ); ?></strong> <?php echo esc_html( $event['old_version'] ); ?> → <?php echo esc_html( $event['new_version'] ); ?></p>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( $home_url ? $home_url : home_url( '/' ) ); ?>"><?php echo esc_html__( 'Return to the site', 'update-guardian' ); ?></a></p>
			</main>
		</body>
		</html>
		<?php
	}
}

