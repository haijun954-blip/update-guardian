<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin dashboard and action handlers.
 */
class Update_Guardian_Admin_Page {
	const MENU_SLUG = 'update-guardian';

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
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Update_Guardian_Repository     $repository Repository.
	 * @param Update_Guardian_Health_Scanner $health_scanner Scanner.
	 */
	public function __construct( Update_Guardian_Repository $repository, Update_Guardian_Health_Scanner $health_scanner ) {
		$this->repository     = $repository;
		$this->health_scanner = $health_scanner;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_update_guardian_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_post_update_guardian_rollback_batch', array( $this, 'handle_batch_rollback' ) );
		add_action( 'admin_post_update_guardian_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_update_guardian_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_update_guardian_recovery_link', array( $this, 'handle_recovery_link' ) );
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( '更新守护', 'update-guardian' ),
			__( '更新守护', 'update-guardian' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			58
		);
	}

	/**
	 * Load assets only on our page.
	 *
	 * @param string $hook_suffix Current screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'update-guardian-admin',
			UPDATE_GUARDIAN_URL . 'assets/admin.css',
			array(),
			UPDATE_GUARDIAN_VERSION
		);
		wp_enqueue_script(
			'update-guardian-monitor',
			UPDATE_GUARDIAN_URL . 'assets/admin.js',
			array(),
			UPDATE_GUARDIAN_VERSION,
			true
		);
	}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限访问此页面。', 'update-guardian' ) );
		}

		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'updates';
		$events = $this->repository->recent( 100 );
		$errors = 'errors' === $tab ? $this->repository->recent_errors( 100 ) : array();
		$last   = get_option( 'update_guardian_last_health_scan', array() );
		$mu_ok  = file_exists( UPDATE_GUARDIAN_MU_PLUGIN );
		$stats  = $this->build_stats( $events );

		$this->render_notices();
		?>
		<div class="wrap ug-wrap">
			<div class="ug-page-header">
				<div>
					<h1><?php echo esc_html__( '更新守护', 'update-guardian' ); ?></h1>
					<p><?php echo esc_html__( '更新前保存插件快照，异常时快速定位并恢复。', 'update-guardian' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="update_guardian_scan">
					<?php wp_nonce_field( 'update_guardian_scan' ); ?>
					<button class="button button-primary" type="submit">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo esc_html__( '运行健康诊断', 'update-guardian' ); ?>
					</button>
				</form>
			</div>

			<?php if ( ! $mu_ok ) : ?>
				<div class="notice notice-error inline">
					<p><?php echo esc_html__( '紧急恢复入口未安装。普通后台回滚仍可使用，但网站遇到致命错误时可能无法进入恢复页面。请重新启用本插件以修复。', 'update-guardian' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="ug-stats" aria-label="<?php esc_attr_e( '状态概览', 'update-guardian' ); ?>">
				<div class="ug-stat">
					<span><?php echo esc_html__( '待确认更新', 'update-guardian' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></strong>
				</div>
				<div class="ug-stat <?php echo $stats['suspects'] ? 'is-danger' : ''; ?>">
					<span><?php echo esc_html__( '疑似故障', 'update-guardian' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $stats['suspects'] ) ); ?></strong>
				</div>
				<div class="ug-stat">
					<span><?php echo esc_html__( '快照占用', 'update-guardian' ); ?></span>
					<strong><?php echo esc_html( $this->format_bytes( Update_Guardian_Backup_Manager::storage_size() ) ); ?></strong>
				</div>
				<div class="ug-stat <?php echo $mu_ok ? 'is-ok' : 'is-danger'; ?>">
					<span><?php echo esc_html__( '紧急恢复', 'update-guardian' ); ?></span>
					<strong><?php echo $mu_ok ? esc_html__( '已就绪', 'update-guardian' ) : esc_html__( '不可用', 'update-guardian' ); ?></strong>
				</div>
			</div>

			<?php if ( ! empty( $last ) && ! empty( $last['timestamp'] ) ) : ?>
				<p class="ug-last-scan">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date, 2: result */
							__( '最近诊断：%1$s，%2$s', 'update-guardian' ),
							wp_date( 'Y-m-d H:i', (int) $last['timestamp'] ),
							isset( $last['message'] ) ? $last['message'] : ''
						)
					);
					?>
				</p>
			<?php endif; ?>

			<nav class="nav-tab-wrapper ug-tabs" aria-label="<?php esc_attr_e( '更新守护页面', 'update-guardian' ); ?>">
				<a class="nav-tab <?php echo 'updates' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=updates' ) ); ?>"><?php echo esc_html__( '更新记录', 'update-guardian' ); ?></a>
				<a class="nav-tab <?php echo 'errors' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=errors' ) ); ?>"><?php echo esc_html__( '错误证据', 'update-guardian' ); ?></a>
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>"><?php echo esc_html__( '设置', 'update-guardian' ); ?></a>
			</nav>

			<?php
			if ( 'errors' === $tab ) {
				$this->render_errors( $errors );
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_events( $events );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Calculate header statistics.
	 *
	 * @param array $events Events.
	 * @return array
	 */
	private function build_stats( array $events ) {
		$active   = 0;
		$suspects = 0;

		foreach ( $events as $event ) {
			if ( in_array( $event['status'], array( 'pending', 'updated' ), true ) ) {
				$active++;
			}
			if ( (int) $event['risk_score'] >= 60 && 'rolled_back' !== $event['status'] ) {
				$suspects++;
			}
		}

		return array(
			'active'   => $active,
			'suspects' => $suspects,
		);
	}

	/**
	 * Render update records.
	 *
	 * @param array $events Events.
	 * @return void
	 */
	private function render_events( array $events ) {
		$batch_sizes = array();
		foreach ( $events as $event ) {
			$batch = $event['batch_id'] ? $event['batch_id'] : 'event-' . $event['id'];
			$batch_sizes[ $batch ] = isset( $batch_sizes[ $batch ] ) ? $batch_sizes[ $batch ] + 1 : 1;
		}
		?>
		<section class="ug-section">
			<div class="ug-section-heading">
				<div>
					<h2><?php echo esc_html__( '最近更新', 'update-guardian' ); ?></h2>
					<p><?php echo esc_html__( '风险分数结合错误文件路径、浏览器异常、更新时间与站点健康检查计算。', 'update-guardian' ); ?></p>
				</div>
			</div>

			<?php if ( empty( $events ) ) : ?>
				<div class="ug-empty">
					<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<h3><?php echo esc_html__( '还没有受保护的更新', 'update-guardian' ); ?></h3>
					<p><?php echo esc_html__( '下一次通过 WordPress 更新插件时，系统会自动创建快照。', 'update-guardian' ); ?></p>
				</div>
			<?php else : ?>
				<div class="ug-table-wrap">
					<table class="widefat fixed striped ug-table">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( '插件与版本', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '更新时间', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '状态', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '风险判断', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '操作', 'update-guardian' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $events as $event ) : ?>
								<?php
								$batch      = $event['batch_id'] ? $event['batch_id'] : 'event-' . $event['id'];
								$risk       = (int) $event['risk_score'];
								$risk_class = $risk >= 70 ? 'high' : ( $risk >= 35 ? 'medium' : 'low' );
								$can_restore = ! empty( $event['backup_id'] ) && ! in_array( $event['status'], array( 'rolled_back' ), true );
								?>
								<tr id="event-<?php echo esc_attr( $event['id'] ); ?>" class="<?php echo ( isset( $_GET['event'] ) && absint( $_GET['event'] ) === (int) $event['id'] ) ? 'is-highlighted' : ''; ?>">
									<td>
										<strong><?php echo esc_html( $event['plugin_name'] ); ?></strong>
										<code class="ug-plugin-file"><?php echo esc_html( $event['plugin_file'] ); ?></code>
										<div class="ug-version">
											<?php echo esc_html( $event['old_version'] ? $event['old_version'] : '—' ); ?>
											<span aria-hidden="true">→</span>
											<?php echo esc_html( $event['new_version'] ? $event['new_version'] : '—' ); ?>
										</div>
									</td>
									<td>
										<?php echo esc_html( $this->format_time( $event['created_at'] ) ); ?>
										<div class="ug-muted"><?php echo esc_html( $event['batch_id'] ); ?></div>
									</td>
									<td>
										<span class="ug-status status-<?php echo esc_attr( $event['status'] ); ?>"><?php echo esc_html( $this->status_label( $event['status'] ) ); ?></span>
										<div class="ug-muted"><?php echo esc_html( $this->health_label( $event['health_status'] ) ); ?></div>
									</td>
									<td>
										<span class="ug-risk risk-<?php echo esc_attr( $risk_class ); ?>"><?php echo esc_html( number_format_i18n( $risk ) ); ?> / 100</span>
										<div class="ug-risk-reason">
											<?php
											$reason = $event['health_message'] ? $event['health_message'] : $event['last_error'];
											echo esc_html( $reason ? wp_trim_words( $reason, 22, '…' ) : __( '尚未发现异常', 'update-guardian' ) );
											?>
										</div>
									</td>
									<td class="ug-actions">
										<?php if ( $can_restore ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="update_guardian_rollback">
												<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>">
												<?php wp_nonce_field( 'update_guardian_rollback_' . $event['id'] ); ?>
												<button class="button button-primary button-small" type="submit" data-update-guardian-confirm="<?php esc_attr_e( '确认恢复此插件到更新前版本？', 'update-guardian' ); ?>">
													<span class="dashicons dashicons-undo" aria-hidden="true"></span>
													<?php echo esc_html__( '一键回滚', 'update-guardian' ); ?>
												</button>
											</form>

											<?php if ( $batch_sizes[ $batch ] > 1 ) : ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="update_guardian_rollback_batch">
													<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch ); ?>">
													<?php wp_nonce_field( 'update_guardian_rollback_batch_' . $batch ); ?>
													<button class="button button-small" type="submit" data-update-guardian-confirm="<?php esc_attr_e( '确认回滚同一批次内的全部插件？', 'update-guardian' ); ?>">
														<?php echo esc_html__( '回滚整批', 'update-guardian' ); ?>
													</button>
												</form>
											<?php endif; ?>

											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="update_guardian_recovery_link">
												<input type="hidden" name="event_id" value="<?php echo esc_attr( $event['id'] ); ?>">
												<?php wp_nonce_field( 'update_guardian_recovery_link_' . $event['id'] ); ?>
												<button class="button-link" type="submit"><?php echo esc_html__( '生成紧急链接', 'update-guardian' ); ?></button>
											</form>
										<?php elseif ( 'rolled_back' === $event['status'] ) : ?>
											<span class="ug-muted"><?php echo esc_html__( '已恢复', 'update-guardian' ); ?></span>
										<?php else : ?>
											<span class="ug-muted"><?php echo esc_html__( '无可用快照', 'update-guardian' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render diagnostic errors.
	 *
	 * @param array $errors Errors.
	 * @return void
	 */
	private function render_errors( array $errors ) {
		?>
		<section class="ug-section">
			<div class="ug-section-heading">
				<div>
					<h2><?php echo esc_html__( '错误证据', 'update-guardian' ); ?></h2>
					<p><?php echo esc_html__( '只收集管理员访问期间、能够定位到具体插件目录的 PHP 或浏览器错误。', 'update-guardian' ); ?></p>
				</div>
			</div>

			<?php if ( empty( $errors ) ) : ?>
				<div class="ug-empty">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<h3><?php echo esc_html__( '没有错误证据', 'update-guardian' ); ?></h3>
					<p><?php echo esc_html__( '这通常表示最近没有捕获到插件级异常，但不能排除纯视觉问题。', 'update-guardian' ); ?></p>
				</div>
			<?php else : ?>
				<div class="ug-table-wrap">
					<table class="widefat fixed striped ug-table">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( '时间与来源', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '插件', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '错误', 'update-guardian' ); ?></th>
								<th scope="col"><?php echo esc_html__( '位置', 'update-guardian' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $errors as $error ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $this->format_time( $error['created_at'] ) ); ?>
										<div class="ug-muted"><?php echo esc_html( 'browser' === $error['source'] ? __( '浏览器', 'update-guardian' ) : __( 'PHP', 'update-guardian' ) ); ?></div>
									</td>
									<td>
										<strong><?php echo esc_html( $error['plugin_slug'] ); ?></strong>
										<?php if ( ! empty( $error['event_id'] ) ) : ?>
											<div><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&event=' . absint( $error['event_id'] ) . '#event-' . absint( $error['event_id'] ) ) ); ?>"><?php echo esc_html__( '查看更新记录', 'update-guardian' ); ?></a></div>
										<?php endif; ?>
									</td>
									<td title="<?php echo esc_attr( $error['message'] ); ?>">
										<strong><?php echo esc_html( $error['error_type'] ); ?></strong>
										<div><?php echo esc_html( wp_trim_words( $error['message'], 28, '…' ) ); ?></div>
									</td>
									<td>
										<code class="ug-path"><?php echo esc_html( $error['file_path'] ); ?><?php echo $error['line_number'] ? ':' . esc_html( $error['line_number'] ) : ''; ?></code>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render settings.
	 *
	 * @return void
	 */
	private function render_settings() {
		$settings = Update_Guardian_Plugin::get_settings();
		?>
		<section class="ug-section ug-settings">
			<div class="ug-section-heading">
				<div>
					<h2><?php echo esc_html__( '防护设置', 'update-guardian' ); ?></h2>
					<p><?php echo esc_html__( '默认值适合大多数站点。只有在备份频繁失败且主机空间充足时才建议关闭更新阻断。', 'update-guardian' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="update_guardian_save_settings">
				<?php wp_nonce_field( 'update_guardian_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( '快照失败时', 'update-guardian' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="block_on_backup_failure" value="1" <?php checked( ! empty( $settings['block_on_backup_failure'] ) ); ?>>
									<?php echo esc_html__( '阻止插件继续更新', 'update-guardian' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( '避免在没有可恢复版本时继续升级。', 'update-guardian' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( '监控内容', 'update-guardian' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="monitor_php_errors" value="1" <?php checked( ! empty( $settings['monitor_php_errors'] ) ); ?>>
									<?php echo esc_html__( 'PHP 致命错误', 'update-guardian' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="monitor_js_errors" value="1" <?php checked( ! empty( $settings['monitor_js_errors'] ) ); ?>>
									<?php echo esc_html__( '管理员浏览器中的插件脚本错误', 'update-guardian' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="monitor_window_minutes"><?php echo esc_html__( '监控窗口', 'update-guardian' ); ?></label></th>
							<td>
								<input id="monitor_window_minutes" class="small-text" type="number" min="30" max="1440" name="monitor_window_minutes" value="<?php echo esc_attr( $settings['monitor_window_minutes'] ); ?>">
								<?php echo esc_html__( '分钟', 'update-guardian' ); ?>
								<p class="description"><?php echo esc_html__( '只把这段时间内的错误关联到最近更新的插件。', 'update-guardian' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( '通知与留存', 'update-guardian' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="capture_recovery_email" value="1" <?php checked( ! empty( $settings['capture_recovery_email'] ) ); ?>>
									<?php echo esc_html__( '更新完成后向管理员邮箱发送紧急恢复链接', 'update-guardian' ); ?>
								</label>
								<div class="ug-inline-fields">
									<label for="retention_days"><?php echo esc_html__( '保留天数', 'update-guardian' ); ?></label>
									<input id="retention_days" class="small-text" type="number" min="1" max="365" name="retention_days" value="<?php echo esc_attr( $settings['retention_days'] ); ?>">
									<label for="max_backups"><?php echo esc_html__( '最多快照数', 'update-guardian' ); ?></label>
									<input id="max_backups" class="small-text" type="number" min="1" max="200" name="max_backups" value="<?php echo esc_attr( $settings['max_backups'] ); ?>">
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="ug-warning"><?php echo esc_html__( '重要：本插件回滚的是插件文件。插件更新时执行的数据库迁移、远程配置变更或缓存数据不会被自动撤销。涉及数据库升级时，请同时使用主机级数据库备份。', 'update-guardian' ); ?></p>

				<?php submit_button( __( '保存设置', 'update-guardian' ) ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Display action notices and one-time recovery link.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notice  = isset( $_GET['ug_notice'] ) ? sanitize_key( wp_unslash( $_GET['ug_notice'] ) ) : '';
		$message = isset( $_GET['ug_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ug_message'] ) ) : '';

		$map = array(
			'rollback_success' => array( 'success', __( '插件已回滚到更新前版本。', 'update-guardian' ) ),
			'rollback_failed'  => array( 'error', __( '回滚失败。', 'update-guardian' ) ),
			'batch_success'    => array( 'success', __( '同一批次插件已全部回滚。', 'update-guardian' ) ),
			'batch_partial'    => array( 'warning', __( '部分插件回滚成功，请检查失败原因。', 'update-guardian' ) ),
			'settings_saved'   => array( 'success', __( '设置已保存。', 'update-guardian' ) ),
			'scan_done'        => array( 'info', __( '健康诊断已完成。', 'update-guardian' ) ),
			'link_created'     => array( 'success', __( '紧急恢复链接已生成。', 'update-guardian' ) ),
		);

		if ( isset( $map[ $notice ] ) ) {
			$class = 'notice notice-' . $map[ $notice ][0];
			echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>';
			echo esc_html( $message ? $message : $map[ $notice ][1] );
			echo '</p></div>';
		}

		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		if ( $event_id && 'link_created' === $notice ) {
			$key = 'ug_recovery_link_' . get_current_user_id() . '_' . $event_id;
			$url = get_transient( $key );
			if ( $url ) {
				delete_transient( $key );
				?>
				<div class="notice notice-info ug-recovery-notice">
					<p><strong><?php echo esc_html__( '单次紧急恢复链接', 'update-guardian' ); ?></strong></p>
					<div class="ug-link-row">
						<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" aria-label="<?php esc_attr_e( '紧急恢复链接', 'update-guardian' ); ?>">
						<button type="button" class="button" data-update-guardian-copy="<?php echo esc_attr( $url ); ?>" data-copied-label="<?php esc_attr_e( '已复制', 'update-guardian' ); ?>"><?php echo esc_html__( '复制', 'update-guardian' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html__( '此链接使用一次后失效，请勿公开分享。', 'update-guardian' ); ?></p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Handle a single plugin rollback.
	 *
	 * @return void
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'update-guardian' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		check_admin_referer( 'update_guardian_rollback_' . $event_id );

		$event = $this->repository->find( $event_id );
		if ( ! $event ) {
			$this->redirect( 'rollback_failed', __( '找不到更新记录。', 'update-guardian' ) );
		}

		$result = Update_Guardian_Backup_Manager::restore_event( $event );
		if ( is_wp_error( $result ) ) {
			$this->repository->update_restore_state( $event_id, 'rollback_failed', $result->get_error_message() );
			$this->redirect( 'rollback_failed', $result->get_error_message() );
		}

		$this->repository->update_restore_state(
			$event_id,
			'rolled_back',
			sprintf( __( '已回滚到 %s。', 'update-guardian' ), $result['version'] )
		);
		$this->flush_caches();

		$this->redirect(
			'rollback_success',
			sprintf( __( '%1$s 已恢复至 %2$s。', 'update-guardian' ), $event['plugin_name'], $result['version'] )
		);
	}

	/**
	 * Handle all plugins in an update batch.
	 *
	 * @return void
	 */
	public function handle_batch_rollback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'update-guardian' ) );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		check_admin_referer( 'update_guardian_rollback_batch_' . $batch_id );

		$events = $this->repository->find_batch( $batch_id );
		if ( empty( $events ) ) {
			$this->redirect( 'rollback_failed', __( '找不到更新批次。', 'update-guardian' ) );
		}

		$success = 0;
		$failed  = array();

		foreach ( $events as $event ) {
			if ( empty( $event['backup_id'] ) || 'rolled_back' === $event['status'] ) {
				continue;
			}

			$result = Update_Guardian_Backup_Manager::restore_event( $event );
			if ( is_wp_error( $result ) ) {
				$this->repository->update_restore_state( (int) $event['id'], 'rollback_failed', $result->get_error_message() );
				$failed[] = $event['plugin_name'] . '：' . $result->get_error_message();
				continue;
			}

			$this->repository->update_restore_state(
				(int) $event['id'],
				'rolled_back',
				sprintf( __( '已作为同批更新回滚到 %s。', 'update-guardian' ), $result['version'] )
			);
			$success++;
		}

		$this->flush_caches();

		if ( $failed ) {
			$this->redirect(
				'batch_partial',
				sprintf(
					__( '%1$d 个插件已回滚；失败：%2$s', 'update-guardian' ),
					$success,
					implode( '；', $failed )
				)
			);
		}

		$this->redirect( 'batch_success', sprintf( __( '%d 个插件已回滚。', 'update-guardian' ), $success ) );
	}

	/**
	 * Run a manual health scan.
	 *
	 * @return void
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'update-guardian' ) );
		}
		check_admin_referer( 'update_guardian_scan' );

		$result = $this->health_scanner->scan_events();
		$this->redirect( 'scan_done', isset( $result['message'] ) ? $result['message'] : '' );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'update-guardian' ) );
		}
		check_admin_referer( 'update_guardian_save_settings' );

		$settings = array(
			'block_on_backup_failure' => isset( $_POST['block_on_backup_failure'] ) ? 1 : 0,
			'capture_recovery_email'  => isset( $_POST['capture_recovery_email'] ) ? 1 : 0,
			'monitor_php_errors'      => isset( $_POST['monitor_php_errors'] ) ? 1 : 0,
			'monitor_js_errors'       => isset( $_POST['monitor_js_errors'] ) ? 1 : 0,
			'monitor_window_minutes'  => isset( $_POST['monitor_window_minutes'] ) ? min( 1440, max( 30, absint( $_POST['monitor_window_minutes'] ) ) ) : 360,
			'retention_days'          => isset( $_POST['retention_days'] ) ? min( 365, max( 1, absint( $_POST['retention_days'] ) ) ) : 14,
			'max_backups'              => isset( $_POST['max_backups'] ) ? min( 200, max( 1, absint( $_POST['max_backups'] ) ) ) : 30,
		);

		update_option( 'update_guardian_settings', $settings, false );
		$this->redirect( 'settings_saved' );
	}

	/**
	 * Create a one-time emergency recovery link.
	 *
	 * @return void
	 */
	public function handle_recovery_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'update-guardian' ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		check_admin_referer( 'update_guardian_recovery_link_' . $event_id );

		$url = Update_Guardian_Recovery_Service::issue_recovery_url( $event_id );
		if ( is_wp_error( $url ) ) {
			$this->redirect( 'rollback_failed', $url->get_error_message() );
		}

		set_transient( 'ug_recovery_link_' . get_current_user_id() . '_' . $event_id, $url, 5 * MINUTE_IN_SECONDS );
		$this->redirect( 'link_created', __( '链接仅显示一次，请立即复制。', 'update-guardian' ), $event_id );
	}

	/**
	 * Clear common caches after restoring files.
	 *
	 * @return void
	 */
	private function flush_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
	}

	/**
	 * Redirect back to the dashboard.
	 *
	 * @param string $notice Notice key.
	 * @param string $message Message.
	 * @param int    $event_id Optional event.
	 * @return void
	 */
	private function redirect( $notice, $message = '', $event_id = 0 ) {
		$args = array(
			'page'       => self::MENU_SLUG,
			'ug_notice'  => $notice,
			'ug_message' => $message,
		);
		if ( $event_id ) {
			$args['event'] = absint( $event_id );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Format a stored UTC timestamp.
	 *
	 * @param string $timestamp MySQL UTC timestamp.
	 * @return string
	 */
	private function format_time( $timestamp ) {
		$time = strtotime( $timestamp . ' UTC' );
		return $time ? wp_date( 'Y-m-d H:i', $time ) : $timestamp;
	}

	/**
	 * Format bytes for display.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < KB_IN_BYTES ) {
			return sprintf( '%s B', number_format_i18n( $bytes ) );
		}
		if ( $bytes < MB_IN_BYTES ) {
			return sprintf( '%s KB', number_format_i18n( $bytes / KB_IN_BYTES, 1 ) );
		}
		return sprintf( '%s MB', number_format_i18n( $bytes / MB_IN_BYTES, 1 ) );
	}

	/**
	 * Human-readable update status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'pending'         => __( '等待完成', 'update-guardian' ),
			'updated'         => __( '已更新', 'update-guardian' ),
			'rolled_back'     => __( '已回滚', 'update-guardian' ),
			'rollback_failed' => __( '回滚失败', 'update-guardian' ),
			'update_failed'   => __( '更新失败', 'update-guardian' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Human-readable health status.
	 *
	 * @param string $status Health status.
	 * @return string
	 */
	private function health_label( $status ) {
		$labels = array(
			'unknown'  => __( '等待诊断', 'update-guardian' ),
			'healthy'  => __( '未发现异常', 'update-guardian' ),
			'warning'  => __( '需要检查', 'update-guardian' ),
			'failed'   => __( '检测到故障', 'update-guardian' ),
			'restored' => __( '恢复成功', 'update-guardian' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}

