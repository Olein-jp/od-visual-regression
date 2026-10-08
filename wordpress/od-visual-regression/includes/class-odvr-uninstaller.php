<?php
/**
 * 無効化時の失効と、事前に選択したサイトだけの明示削除。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// 最新の停止状態・存在する既知テーブルだけを扱う.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/** WordPress投稿・ユーザー・Media Libraryには触れない。 */
final class ODVR_Uninstaller extends ODVR_Repository {
	/**
	 * キャッシュでなくDBへ停止/削除状態が保存されたことを確認する。
	 *
	 * @param string $name 固定Option名.
	 * @return void
	 */
	private function flag_saved( $name ) {
		global $wpdb;
		$rows = $this->rows( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		if ( 1 !== count( $rows ) || '1' !== $rows[0]['option_value'] ) {
			$this->fail( 'odvr_uninstall_state_failed', 503 ); }
	}

	/**
	 * 既知のテーブルが存在するか確認する。
	 *
	 * @param string $suffix テーブル種別.
	 * @return bool 存在.
	 */
	private function exists( $suffix ) {
		global $wpdb;
		$rows = $this->rows( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( ODVR_DB::table( $suffix ) ) ) );
		return (bool) $rows;
	}

	/**
	 * 停止状態を先に保存し、全Tokenを失効する。履歴・権限を保持する。
	 *
	 * @return true|WP_Error 結果.
	 */
	public function suspend() {
		return $this->read(
			function () {
				global $wpdb;
				update_option( 'odvr_suspended', true, false );
				$this->flag_saved( 'odvr_suspended' );
				foreach ( array( 'odvr_storage_cleanup', 'odvr_run_expiry', 'odvr_retention' ) as $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
				delete_option( 'odvr_storage_ready' );
				if ( $this->exists( 'runs' ) ) {
					$this->written( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET runner_token_hash = NULL WHERE runner_token_hash IS NOT NULL', ODVR_DB::table( 'runs' ) ) ) );
				}
				return true;
			}
		);
	}

	/**
	 * 既定は保持。明示削除は画像の回収成功後だけDDLを実行する。
	 *
	 * @return true|WP_Error 結果.
	 */
	public function current_site() {
		$result = $this->read(
			function () {
				global $wpdb;
				$this->checked( $this->suspend() );
				if ( ! in_array( get_option( 'odvr_delete_data_on_uninstall', false ), array( true, '1' ), true ) ) {
					return true;
				}
				if ( $this->exists( 'runs' ) ) {
					$active = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE status IN (%s, %s) LIMIT 1', ODVR_DB::table( 'runs' ), 'queued', 'running' ) );
					if ( $active ) {
						$this->fail( 'odvr_uninstall_active', 409 );
					}
				}
				update_option( 'odvr_deleting_site', true, false );
				$this->flag_saved( 'odvr_deleting_site' );
				// 明示選択済みサイトのPinnedも削除対象。失敗時に元へ戻さない.
				if ( $this->exists( 'suites' ) ) {
					$this->written( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET baseline_run_id = NULL', ODVR_DB::table( 'suites' ) ) ) );
				}
				if ( $this->exists( 'runs' ) ) {
					$this->written( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, runner_token_hash = NULL, deletion_requested_at = COALESCE(deletion_requested_at, %s) WHERE status <> %s', ODVR_DB::table( 'runs' ), 'deleting', ODVR_DB::utc_now(), 'deleting' ) ) );
					$runs = $this->rows( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC', ODVR_DB::table( 'runs' ) ) );
					foreach ( $runs as $run ) {
						$this->checked( ( new ODVR_Storage() )->delete_run( $this->integer( $run['id'], true ) ) );
					}
				}
				$this->checked( ( new ODVR_Storage() )->delete_site_root() );
				foreach ( array( 'snapshots', 'runs', 'targets', 'devices', 'suites' ) as $suffix ) {
					$this->written( $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', ODVR_DB::table( $suffix ) ) ) );
				}
				$options = array( 'odvr_db_version', 'odvr_db_error', 'odvr_db_upgrade_lock', 'odvr_storage_ready', 'odvr_storage_cleanup_cursor', 'odvr_storage_staging_cursor', 'odvr_run_expiry_cursor', 'odvr_retention_cursor', 'odvr_deletion_cursor', 'odvr_suspended', 'odvr_deleting_site', 'odvr_uninstall_error', 'odvr_settings', 'odvr_delete_data_on_uninstall' );
				foreach ( $options as $option ) {
					delete_option( $option );
				}
				$errors = $this->rows( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'odvr_deletion_error_' ) . '%' ) );
				foreach ( $errors as $error ) {
					delete_option( $error['option_name'] );
				}
				foreach ( wp_roles()->roles as $name => $role_data ) {
					$role = get_role( $name );
					if ( $role ) {
						$role->remove_cap( ODVR_Capabilities::MANAGE );
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->read(
				function () {
					update_option( 'odvr_uninstall_error', 'ODVRデータの削除を完了できませんでした。保存データを保持して再試行してください。', false );
					return true;
				}
			);
		}
		return $result;
	}

	/**
	 * 小規模ネットワークは各サイトの選択を個別に確認する。
	 *
	 * @return true|WP_Error 結果.
	 */
	public static function run() {
		if ( ! is_multisite() ) {
			return ( new self() )->current_site();
		}
		$sites = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 101,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		if ( count( $sites ) > 100 ) {
			return new WP_Error( 'odvr_uninstall_network_limit', '100サイトを超えるネットワークでは、サイト単位の削除手順を先に実行してください。', array( 'status' => 409 ) );
		}
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site );
			try {
				$result = ( new self() )->current_site();
			} finally {
				restore_current_blog();
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
}
