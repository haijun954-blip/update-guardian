<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures browser-side plugin errors reported by signed-in administrators.
 */
class Update_Guardian_Error_Monitor {
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
	 * Register admin error collection.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_monitor' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_monitor' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Enqueue on all admin screens for administrators during a monitoring window.
	 *
	 * @return void
	 */
	public function enqueue_monitor() {
		$this->enqueue_script();
	}

	/**
	 * Enqueue on the frontend only for administrators.
	 *
	 * @return void
	 */
	public function enqueue_frontend_monitor() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$this->enqueue_script();
		}
	}

	/**
	 * Enqueue the shared JavaScript monitor.
	 *
	 * @return void
	 */
	private function enqueue_script() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Update_Guardian_Plugin::get_settings();
		if ( empty( $settings['monitor_js_errors'] ) ) {
			return;
		}

		$events = $this->repository->recent_within( (int) $settings['monitor_window_minutes'] );
		if ( empty( $events ) ) {
			return;
		}

		wp_enqueue_script(
			'update-guardian-monitor',
			UPDATE_GUARDIAN_URL . 'assets/admin.js',
			array(),
			UPDATE_GUARDIAN_VERSION,
			true
		);

		wp_localize_script(
			'update-guardian-monitor',
			'UpdateGuardianMonitor',
			array(
				'endpoint' => esc_url_raw( rest_url( 'update-guardian/v1/errors' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'limit'    => 12,
			)
		);
	}

	/**
	 * Register error ingestion endpoint.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			'update-guardian/v1',
			'/errors',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive_error' ),
				'permission_callback' => static function () {
					return is_user_logged_in() && current_user_can( 'manage_options' );
				},
				'args'                => array(
					'type'      => array( 'type' => 'string', 'required' => false ),
					'message'   => array( 'type' => 'string', 'required' => true ),
					'source'    => array( 'type' => 'string', 'required' => false ),
					'line'      => array( 'type' => 'integer', 'required' => false ),
					'column'    => array( 'type' => 'integer', 'required' => false ),
					'stack'     => array( 'type' => 'string', 'required' => false ),
					'page_url'  => array( 'type' => 'string', 'required' => false ),
					'error_key' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	/**
	 * Store a browser error against the most relevant recent plugin update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive_error( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$limit_key = 'ug_js_rate_' . $user_id;
		$count     = (int) get_transient( $limit_key );

		if ( $count >= 30 ) {
			return new WP_Error( 'update_guardian_rate_limited', __( 'Too many diagnostic requests.', 'update-guardian' ), array( 'status' => 429 ) );
		}
		set_transient( $limit_key, $count + 1, HOUR_IN_SECONDS );

		$message = substr( sanitize_textarea_field( (string) $request->get_param( 'message' ) ), 0, 2000 );
		$source  = substr( sanitize_textarea_field( (string) $request->get_param( 'source' ) ), 0, 2000 );
		$stack   = substr( sanitize_textarea_field( (string) $request->get_param( 'stack' ) ), 0, 5000 );
		$key     = sanitize_key( (string) $request->get_param( 'error_key' ) );

		if ( '' === $message ) {
			return new WP_Error( 'update_guardian_empty_error', __( 'The error message is empty.', 'update-guardian' ), array( 'status' => 400 ) );
		}

		$dedupe_key = 'ug_js_seen_' . $user_id . '_' . md5( $key . '|' . $message . '|' . $source );
		if ( get_transient( $dedupe_key ) ) {
			return new WP_REST_Response( array( 'stored' => false, 'reason' => 'duplicate' ), 200 );
		}
		set_transient( $dedupe_key, 1, 10 * MINUTE_IN_SECONDS );

		$plugin_slug = $this->plugin_slug_from_text( $source . "\n" . $stack );
		if ( empty( $plugin_slug ) || 'update-guardian' === $plugin_slug ) {
			return new WP_REST_Response( array( 'stored' => false, 'reason' => 'no_plugin_source' ), 200 );
		}

		$settings = Update_Guardian_Plugin::get_settings();
		$event    = $this->repository->find_recent_by_slug( $plugin_slug, (int) $settings['monitor_window_minutes'] );

		$this->repository->add_error(
			array(
				'event_id'    => $event ? (int) $event['id'] : 0,
				'plugin_file' => $event ? $event['plugin_file'] : '',
				'plugin_slug' => $plugin_slug,
				'source'      => 'browser',
				'error_type'  => sanitize_text_field( (string) $request->get_param( 'type' ) ),
				'file_path'   => $source,
				'line_number' => max( 0, absint( $request->get_param( 'line' ) ) ),
				'message'     => $message,
				'stack'       => $stack,
				'request_url' => esc_url_raw( (string) $request->get_param( 'page_url' ) ),
			)
		);

		return new WP_REST_Response(
			array(
				'stored'  => true,
				'event'   => $event ? (int) $event['id'] : 0,
				'plugin'  => $plugin_slug,
				'rollback' => $event ? admin_url( 'admin.php?page=update-guardian&event=' . (int) $event['id'] ) : '',
			),
			201
		);
	}

	/**
	 * Extract a plugin slug from a file path or JavaScript stack.
	 *
	 * @param string $text Error source.
	 * @return string
	 */
	private function plugin_slug_from_text( $text ) {
		$text = rawurldecode( (string) $text );

		if ( preg_match( '#[/\\\\]wp-content[/\\\\]plugins[/\\\\]([^/\\\\?#]+)#i', $text, $matches ) ) {
			return sanitize_key( $matches[1] );
		}

		if ( preg_match( '#[/\\\\]plugins[/\\\\]([^/\\\\?#]+)#i', $text, $matches ) ) {
			return sanitize_key( $matches[1] );
		}

		return '';
	}
}

