<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and restores plugin file snapshots.
 */
class Update_Guardian_Backup_Manager {
	const BACKUP_PREFIX = 'ug-';

	/**
	 * Ensure the private storage directory exists.
	 *
	 * @return true|WP_Error
	 */
	public static function initialize_storage() {
		$base = UPDATE_GUARDIAN_STORAGE_DIR;

		foreach ( array( $base, $base . '/backups', $base . '/quarantine' ) as $directory ) {
			if ( ! wp_mkdir_p( $directory ) ) {
				return new WP_Error(
					'update_guardian_storage_error',
					sprintf(
						/* translators: %s: directory path */
						__( '无法创建备份目录：%s', 'update-guardian' ),
						$directory
					)
				);
			}
		}

		self::protect_directory( $base );

		return true;
	}

	/**
	 * Add files that prevent direct web access on common servers.
	 *
	 * @param string $directory Storage directory.
	 * @return void
	 */
	private static function protect_directory( $directory ) {
		$index = $directory . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $directory . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}

		$web_config = $directory . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			$xml .= "<configuration><system.webServer><authorization>";
			$xml .= "<remove users=\"*\" roles=\"\" verbs=\"\" />";
			$xml .= "<add accessType=\"Deny\" users=\"*\" />";
			$xml .= "</authorization></system.webServer></configuration>\n";
			file_put_contents( $web_config, $xml );
		}
	}

	/**
	 * Create a backup for a plugin that is about to be updated.
	 *
	 * @param string $plugin_file Plugin basename, for example elementor/elementor.php.
	 * @param string $version Installed version before update.
	 * @return array|WP_Error
	 */
	public static function create_for_plugin( $plugin_file, $version ) {
		$storage = self::initialize_storage();
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$source = self::plugin_root_path( $plugin_file );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! file_exists( $source['path'] ) ) {
			return new WP_Error( 'update_guardian_missing_plugin', __( '找不到待备份的插件目录。', 'update-guardian' ) );
		}

		$backup_id = self::new_backup_id( $source['slug'] );
		$backup_dir = trailingslashit( UPDATE_GUARDIAN_STORAGE_DIR . '/backups' ) . $backup_id;

		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new WP_Error( 'update_guardian_backup_dir', __( '无法创建插件快照目录。', 'update-guardian' ) );
		}

		$manifest = array(
			'backup_id'    => $backup_id,
			'plugin_file'  => $plugin_file,
			'plugin_slug'  => $source['slug'],
			'root_name'    => $source['root_name'],
			'source_path'  => $source['path'],
			'source_type'  => $source['type'],
			'version'      => (string) $version,
			'created_at'   => current_time( 'mysql', true ),
			'created_ts'   => time(),
			'backup_type'  => '',
			'payload'      => '',
			'file_count'   => 0,
			'byte_count'   => 0,
			'tree_hash'    => '',
			'warnings'     => array(),
		);

		$result = class_exists( 'ZipArchive' )
			? self::create_zip_snapshot( $source['path'], $backup_dir )
			: self::create_directory_snapshot( $source['path'], $backup_dir );

		if ( is_wp_error( $result ) ) {
			self::delete_directory( $backup_dir );
			return $result;
		}

		$manifest              = array_merge( $manifest, $result );
		$manifest['size_bytes'] = self::directory_size( $backup_dir );

		$written = file_put_contents(
			$backup_dir . '/manifest.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		if ( false === $written ) {
			self::delete_directory( $backup_dir );
			return new WP_Error( 'update_guardian_manifest_error', __( '无法写入插件快照清单。', 'update-guardian' ) );
		}

		self::protect_directory( $backup_dir );

		return $manifest;
	}

	/**
	 * Resolve a plugin basename to the directory or file to snapshot.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param bool   $allow_missing Allow a missing target when restoring.
	 * @return array|WP_Error
	 */
	private static function plugin_root_path( $plugin_file, $allow_missing = false ) {
		if ( validate_file( $plugin_file ) !== 0 ) {
			return new WP_Error( 'update_guardian_invalid_plugin', __( '插件文件路径无效。', 'update-guardian' ) );
		}

		$parts   = explode( '/', $plugin_file );
		$is_file = 1 === count( $parts );
		$root    = $is_file ? $plugin_file : $parts[0];
		$path    = WP_PLUGIN_DIR . '/' . $root;
		$real    = realpath( $path );
		$base    = realpath( WP_PLUGIN_DIR );

		if ( false === $base ) {
			return new WP_Error( 'update_guardian_missing_plugin_root', __( '无法确认插件根目录。', 'update-guardian' ) );
		}

		if ( false === $real && $allow_missing ) {
			$real = wp_normalize_path( $path );
		}

		if ( false === $real ) {
			return new WP_Error( 'update_guardian_missing_plugin', __( '找不到待备份的插件。', 'update-guardian' ) );
		}

		$real = wp_normalize_path( $real );
		$base = trailingslashit( wp_normalize_path( $base ) );
		if ( 0 !== strpos( $real, $base ) ) {
			return new WP_Error( 'update_guardian_plugin_outside_root', __( '插件路径超出允许范围。', 'update-guardian' ) );
		}

		$slug = $is_file ? basename( $plugin_file, '.php' ) : $parts[0];

		return array(
			'path'      => $real,
			'root_name' => wp_basename( $root ),
			'slug'      => sanitize_key( $slug ),
			'type'      => $is_file ? 'file' : 'directory',
		);
	}

	/**
	 * Generate a unique backup ID.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function new_backup_id( $slug ) {
		$random = substr( wp_generate_password( 12, false, false ), 0, 8 );
		return self::BACKUP_PREFIX . sanitize_key( $slug ) . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( $random );
	}

	/**
	 * Compress a plugin into a zip snapshot.
	 *
	 * @param string $source Source directory or file.
	 * @param string $backup_dir Backup directory.
	 * @return array|WP_Error
	 */
	private static function create_zip_snapshot( $source, $backup_dir ) {
		$zip_path = $backup_dir . '/payload.zip';
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'update_guardian_zip_error', __( '无法创建 ZIP 快照。', 'update-guardian' ) );
		}

		$count    = 0;
		$bytes    = 0;
		$warnings = array();
		$hash     = hash_init( 'sha256' );

		if ( is_file( $source ) ) {
			$relative = wp_basename( $source );
			if ( ! $zip->addFile( $source, $relative ) ) {
				$zip->close();
				return new WP_Error( 'update_guardian_zip_add', __( '无法将插件文件加入快照。', 'update-guardian' ) );
			}
			$file_size = filesize( $source );
			$bytes    += false === $file_size ? 0 : $file_size;
			$count++;
			hash_update( $hash, $relative . ':' . hash_file( 'sha256', $source ) . "\n" );
		} else {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				$path = $item->getPathname();

				if ( $item->isLink() ) {
					$warnings[] = sprintf( '跳过符号链接：%s', $path );
					continue;
				}

				$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $source ) ) ), '/' );
				if ( '' === $relative ) {
					continue;
				}

				if ( $item->isDir() ) {
					$zip->addEmptyDir( $relative );
					continue;
				}

				if ( ! $item->isFile() || ! $zip->addFile( $path, $relative ) ) {
					$zip->close();
					return new WP_Error( 'update_guardian_zip_add', __( '读取插件文件时失败。', 'update-guardian' ) );
				}

				$file_size = $item->getSize();
				$bytes    += false === $file_size ? 0 : $file_size;
				$count++;
				hash_update( $hash, $relative . ':' . hash_file( 'sha256', $path ) . "\n" );
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'update_guardian_zip_close', __( '保存 ZIP 快照时失败。', 'update-guardian' ) );
		}

		return array(
			'backup_type' => 'zip',
			'payload'     => 'payload.zip',
			'file_count'  => $count,
			'byte_count'  => $bytes,
			'tree_hash'   => hash_final( $hash ),
			'warnings'    => $warnings,
		);
	}

	/**
	 * Copy a plugin into an uncompressed snapshot fallback.
	 *
	 * @param string $source Source directory or file.
	 * @param string $backup_dir Backup directory.
	 * @return array|WP_Error
	 */
	private static function create_directory_snapshot( $source, $backup_dir ) {
		$payload = $backup_dir . '/payload';
		$stats   = array(
			'file_count' => 0,
			'byte_count' => 0,
			'warnings'   => array(),
			'hash_parts' => array(),
		);

		if ( is_file( $source ) ) {
			if ( ! wp_mkdir_p( $payload ) || ! copy( $source, $payload . '/' . wp_basename( $source ) ) ) {
				return new WP_Error( 'update_guardian_copy_error', __( '复制插件文件时失败。', 'update-guardian' ) );
			}
			$stats['file_count'] = 1;
			$stats['byte_count'] = (int) filesize( $source );
			$stats['hash_parts'][] = wp_basename( $source ) . ':' . hash_file( 'sha256', $source ) . "\n";
		} else {
			$result = self::copy_tree( $source, $payload, $stats );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'backup_type' => 'directory',
			'payload'     => 'payload',
			'file_count'  => $stats['file_count'],
			'byte_count'  => $stats['byte_count'],
			'tree_hash'   => hash( 'sha256', implode( '', $stats['hash_parts'] ) ),
			'warnings'    => $stats['warnings'],
		);
	}

	/**
	 * Recursively copy a directory while collecting integrity metadata.
	 *
	 * @param string $source Source directory.
	 * @param string $destination Destination directory.
	 * @param array  $stats Stats accumulator.
	 * @return true|WP_Error
	 */
	private static function copy_tree( $source, $destination, array &$stats ) {
		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error( 'update_guardian_copy_dir', __( '无法创建快照子目录。', 'update-guardian' ) );
		}

		$items = scandir( $source );
		if ( false === $items ) {
			return new WP_Error( 'update_guardian_read_dir', __( '无法读取插件目录。', 'update-guardian' ) );
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source_path = trailingslashit( $source ) . $item;
			$dest_path   = trailingslashit( $destination ) . $item;

			if ( is_link( $source_path ) ) {
				$stats['warnings'][] = sprintf( '跳过符号链接：%s', $source_path );
				continue;
			}

			if ( is_dir( $source_path ) ) {
				$result = self::copy_tree( $source_path, $dest_path, $stats );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				continue;
			}

			if ( ! is_file( $source_path ) || ! copy( $source_path, $dest_path ) ) {
				return new WP_Error( 'update_guardian_copy_file', __( '复制插件文件时失败。', 'update-guardian' ) );
			}

			$size = filesize( $source_path );
			$stats['file_count']++;
			$stats['byte_count'] += false === $size ? 0 : $size;
			$stats['hash_parts'][] = $item . ':' . hash_file( 'sha256', $source_path ) . "\n";
		}

		return true;
	}

	/**
	 * Restore one event from its saved snapshot.
	 *
	 * @param array $event Event row.
	 * @return array|WP_Error
	 */
	public static function restore_event( array $event ) {
		$storage = self::initialize_storage();
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$backup_id = isset( $event['backup_id'] ) ? sanitize_text_field( $event['backup_id'] ) : '';
		if ( empty( $backup_id ) ) {
			return new WP_Error( 'update_guardian_no_backup', __( '此更新没有可用快照。', 'update-guardian' ) );
		}

		$backup_dir = trailingslashit( UPDATE_GUARDIAN_STORAGE_DIR . '/backups' ) . $backup_id;
		$manifest   = self::read_manifest( $backup_dir );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		if ( ! empty( $event['plugin_file'] ) && $manifest['plugin_file'] !== $event['plugin_file'] ) {
			return new WP_Error( 'update_guardian_manifest_mismatch', __( '快照与更新记录不匹配。', 'update-guardian' ) );
		}

		$target = self::plugin_root_path( $manifest['plugin_file'], true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$parent    = dirname( $target['path'] );
		$suffix    = gmdate( 'YmdHis' ) . '-' . self::random_suffix();
		$temp_root = $parent . '/.ug-restore-' . $suffix;

		if ( 'zip' === $manifest['backup_type'] ) {
			$result = self::extract_zip( $backup_dir . '/' . $manifest['payload'], $temp_root );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$result = self::copy_tree( $backup_dir . '/' . $manifest['payload'], $temp_root, $stats = array( 'file_count' => 0, 'byte_count' => 0, 'warnings' => array(), 'hash_parts' => array() ) );
			if ( is_wp_error( $result ) ) {
				self::delete_temporary_path( $temp_root );
				return $result;
			}
		}

		$temp_target = 'file' === $target['type']
			? $temp_root . '/' . $target['root_name']
			: $temp_root;

		if ( ! file_exists( $temp_target ) ) {
			self::delete_temporary_path( $temp_root );
			return new WP_Error( 'update_guardian_restore_payload', __( '快照内容不完整，未执行回滚。', 'update-guardian' ) );
		}

		$quarantine_root = trailingslashit( UPDATE_GUARDIAN_STORAGE_DIR . '/quarantine' ) . $backup_id . '-' . $suffix;
		if ( ! wp_mkdir_p( $quarantine_root ) ) {
			self::delete_temporary_path( $temp_root );
			return new WP_Error( 'update_guardian_quarantine', __( '无法创建故障版本隔离目录。', 'update-guardian' ) );
		}

		$quarantine_target = trailingslashit( $quarantine_root ) . $target['root_name'];
		$had_existing_target = file_exists( $target['path'] ) || is_link( $target['path'] );

		if ( $had_existing_target && ! @rename( $target['path'], $quarantine_target ) ) {
			self::delete_temporary_path( $temp_root );
			self::delete_directory( $quarantine_root );
			return new WP_Error( 'update_guardian_move_current', __( '无法移动当前故障版本，请检查文件权限。', 'update-guardian' ) );
		}

		if ( ! @rename( $temp_target, $target['path'] ) ) {
			if ( $had_existing_target ) {
				@rename( $quarantine_target, $target['path'] );
			}
			self::delete_temporary_path( $temp_root );
			self::delete_directory( $quarantine_root );
			return new WP_Error( 'update_guardian_move_restore', __( '无法安装回滚版本，已尝试恢复现场。', 'update-guardian' ) );
		}

		self::delete_temporary_path( $temp_root );
		if ( ! $had_existing_target ) {
			self::delete_directory( $quarantine_root );
			$quarantine_target = '';
		}
		self::normalize_permissions( $target['path'], $target['type'] );
		self::invalidate_opcache( $target['path'], $target['type'] );

		return array(
			'backup_id'  => $backup_id,
			'plugin'     => $manifest['plugin_file'],
			'version'    => $manifest['version'],
			'quarantine' => $quarantine_target,
		);
	}

	/**
	 * Extract a snapshot ZIP to a temporary location.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $destination Destination directory.
	 * @return true|WP_Error
	 */
	private static function extract_zip( $zip_path, $destination ) {
		if ( ! class_exists( 'ZipArchive' ) || ! is_file( $zip_path ) ) {
			return new WP_Error( 'update_guardian_zip_missing', __( '快照 ZIP 不存在或无法读取。', 'update-guardian' ) );
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error( 'update_guardian_restore_dir', __( '无法创建临时恢复目录。', 'update-guardian' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) || ! $zip->extractTo( $destination ) ) {
			$zip->close();
			self::delete_temporary_path( $destination );
			return new WP_Error( 'update_guardian_extract_error', __( '解压插件快照失败。', 'update-guardian' ) );
		}
		$zip->close();

		return true;
	}

	/**
	 * Read and validate a backup manifest.
	 *
	 * @param string $backup_dir Backup directory.
	 * @return array|WP_Error
	 */
	private static function read_manifest( $backup_dir ) {
		$path = trailingslashit( $backup_dir ) . 'manifest.json';
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'update_guardian_manifest_missing', __( '快照清单不存在。', 'update-guardian' ) );
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['plugin_file'] ) || empty( $manifest['backup_type'] ) || empty( $manifest['payload'] ) ) {
			return new WP_Error( 'update_guardian_manifest_invalid', __( '快照清单无效。', 'update-guardian' ) );
		}

		return $manifest;
	}

	/**
	 * Normalize restored file and directory permissions.
	 *
	 * @param string $path Target path.
	 * @param string $type file or directory.
	 * @return void
	 */
	private static function normalize_permissions( $path, $type ) {
		if ( 'file' === $type ) {
			@chmod( $path, 0644 );
			return;
		}

		@chmod( $path, 0755 );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			@chmod( $item->getPathname(), $item->isDir() ? 0755 : 0644 );
		}
	}

	/**
	 * Clear PHP opcode cache entries when available.
	 *
	 * @param string $path Restored path.
	 * @param string $type file or directory.
	 * @return void
	 */
	private static function invalidate_opcache( $path, $type ) {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}

		if ( 'file' === $type ) {
			@opcache_invalidate( $path, true );
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				@opcache_invalidate( $item->getPathname(), true );
			}
		}
	}

	/**
	 * Remove a backup directory.
	 *
	 * @param string $backup_id Backup ID.
	 * @return bool
	 */
	public static function delete_backup( $backup_id ) {
		$backup_id = sanitize_text_field( $backup_id );
		if ( empty( $backup_id ) || 0 !== strpos( $backup_id, self::BACKUP_PREFIX ) ) {
			return false;
		}

		return self::delete_directory( trailingslashit( UPDATE_GUARDIAN_STORAGE_DIR . '/backups' ) . $backup_id );
	}

	/**
	 * Delete a directory tree, constrained to our storage path.
	 *
	 * @param string $directory Directory.
	 * @return bool
	 */
	public static function delete_directory( $directory ) {
		$real = realpath( $directory );
		$base = realpath( UPDATE_GUARDIAN_STORAGE_DIR );

		if ( false === $real || false === $base ) {
			return false;
		}

		$real = wp_normalize_path( $real );
		$base = trailingslashit( wp_normalize_path( $base ) );
		if ( 0 !== strpos( $real, $base ) || $real === untrailingslashit( $base ) ) {
			return false;
		}

		if ( is_file( $real ) || is_link( $real ) ) {
			return @unlink( $real );
		}

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

		return @rmdir( $real );
	}

	/**
	 * Delete a temporary restore path inside the plugins directory.
	 *
	 * @param string $path Temporary path.
	 * @return bool
	 */
	private static function delete_temporary_path( $path ) {
		$real = realpath( $path );
		$base = realpath( WP_PLUGIN_DIR );

		if ( false === $real || false === $base ) {
			return false;
		}

		$real = wp_normalize_path( $real );
		$base = trailingslashit( wp_normalize_path( $base ) );
		$name = wp_basename( $real );

		if ( 0 !== strpos( $real, $base ) || 0 !== strpos( $name, '.ug-restore-' ) ) {
			return false;
		}

		if ( is_file( $real ) || is_link( $real ) ) {
			return @unlink( $real );
		}

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

		return @rmdir( $real );
	}

	/**
	 * Generate a suffix without relying on pluggable.php.
	 *
	 * @return string
	 */
	private static function random_suffix() {
		try {
			return bin2hex( random_bytes( 4 ) );
		} catch ( Exception $exception ) {
			return substr( md5( uniqid( '', true ) ), 0, 8 );
		}
	}

	/**
	 * Prune snapshots by age and maximum count.
	 *
	 * @param int $days Retention days.
	 * @param int $max_backups Maximum snapshots.
	 * @return int Number deleted.
	 */
	public static function prune( $days, $max_backups ) {
		$directories = glob( trailingslashit( UPDATE_GUARDIAN_STORAGE_DIR . '/backups' ) . self::BACKUP_PREFIX . '*', GLOB_ONLYDIR );
		if ( false === $directories ) {
			return 0;
		}

		usort(
			$directories,
			static function ( $left, $right ) {
				return filemtime( $right ) <=> filemtime( $left );
			}
		);

		$deleted = 0;
		$cutoff  = time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS );

		foreach ( $directories as $index => $directory ) {
			$too_old = filemtime( $directory ) < $cutoff;
			$too_many = $index >= max( 1, absint( $max_backups ) );
			if ( ( $too_old || $too_many ) && self::delete_directory( $directory ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Calculate total storage used by snapshots.
	 *
	 * @return int
	 */
	public static function storage_size() {
		return self::directory_size( UPDATE_GUARDIAN_STORAGE_DIR . '/backups' );
	}

	/**
	 * Recursively calculate directory size.
	 *
	 * @param string $directory Directory.
	 * @return int
	 */
	public static function directory_size( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$size     = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$size += (int) $item->getSize();
			}
		}

		return $size;
	}
}

