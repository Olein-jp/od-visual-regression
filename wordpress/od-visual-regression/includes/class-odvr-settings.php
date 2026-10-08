<?php
/**
 * サイト別の非秘密設定。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// 集計と最新設定は現在サイトのDBを読む.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/** 保存Versionと表示契約を分ける。 */
final class ODVR_Settings extends ODVR_Repository {
	/**
	 * 保存済み非秘密設定。
	 *
	 * @return array|WP_Error 設定.
	 */
	public function saved() {
		return $this->read(
			function () {
				global $wpdb;
				$rows  = $this->rows( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'odvr_settings' ) );
				$value = $rows ? maybe_unserialize( $rows[0]['option_value'] ) : null;
				if ( null === $value ) {
						$value = array(
							'settings_version'       => 1,
							'dispatcher_url'         => ODVR_Config::value( 'ODVR_DISPATCHER_URL' ) ?? 'https://dispatcher.invalid',
							'site_id'                => 'site-' . get_current_blog_id(),
							'retention'              => (object) array(
								'mode'  => 'last',
								'count' => 10,
							),
							'queued_timeout_seconds' => 900,
							'run_timeout_seconds'    => 5400,
						);
				}
				if ( ! is_array( $value ) || 1 !== ( $value['settings_version'] ?? null ) || count( $value ) !== 6 ) {
					$this->fail( 'odvr_unsupported_storage_version', 503 );
				}
				$payload = $value;
				unset( $payload['settings_version'] );
				$payload['schema_version'] = 1;
				$this->validate( 'settings-patch-request', (object) $payload );
				return $value;
			}
		);
	}
	/**
	 * 秘密有無と保存統計だけを返す。
	 *
	 * @return array|WP_Error 表示用設定.
	 */
	public function get() {
		return $this->read(
			function () {
				global $wpdb;
				$item = $this->checked( $this->saved() );
				unset( $item['settings_version'] );
				$auth                                 = ODVR_Config::http_auth();
				$secret                               = ODVR_Config::value( 'ODVR_DISPATCHER_SHARED_SECRET' );
				$item['dispatcher_secret_configured'] = is_string( $secret ) && strlen( $secret ) >= 32;
				$item['http_auth_configured']         = ! is_wp_error( $auth ) && null !== $auth;
				$item['http_auth_origin']             = $item['http_auth_configured'] ? $auth->origin : null;
				$counts                               = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i', ODVR_DB::table( 'runs' ) ) );
				$item['retained_runs']                = $this->integer( $counts[0]['total'] );
				$item['storage_bytes']                = ( new ODVR_Storage() )->usage_bytes();
				$errors                               = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i o WHERE o.option_name LIKE %s AND EXISTS (SELECT 1 FROM %i r WHERE r.status = %s AND o.option_name = CONCAT(%s, r.id))', $wpdb->options, $wpdb->esc_like( 'odvr_deletion_error_' ) . '%', ODVR_DB::table( 'runs' ), 'deleting', 'odvr_deletion_error_' ) );
				$item['deletion_failures']            = $this->integer( $errors[0]['total'] );
				$this->validate(
					'settings-response',
					(object) array(
						'schema_version' => 1,
						'item'           => (object) $item,
					)
				);
				return $item;
			}
		);
	}
	/**
	 * 未知キー・秘密キーは共通契約で拒否する。
	 *
	 * @param stdClass $input 非秘密PATCH.
	 * @return array|WP_Error 保存後表示.
	 */
	public function update( $input ) {
		$result = $this->transaction(
			function () use ( $input ) {
				global $wpdb;
				$this->validate( 'settings-patch-request', $input );
				$item    = $this->checked( $this->lock_saved() );
				$changes = get_object_vars( $input );
				unset( $changes['schema_version'] );
				foreach ( array( 'dispatcher_url', 'site_id' ) as $field ) {
					if ( isset( $changes[ $field ] ) && $changes[ $field ] !== $item[ $field ] && $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE status IN (%s, %s) LIMIT 1', ODVR_DB::table( 'runs' ), 'queued', 'running' ) ) ) {
						$this->fail( 'odvr_runs_active', 409 );
					}
				}
				$item = array_merge( $item, $changes );
				if ( isset( $changes['dispatcher_url'] ) && ODVR_Config::value( 'ODVR_DISPATCHER_URL' ) !== $changes['dispatcher_url'] ) {
						$this->fail( 'odvr_dispatch_destination_invalid', 400 );
				}
				$payload = $item;
				unset( $payload['settings_version'] );
				$payload['schema_version'] = 1;
				$this->validate( 'settings-patch-request', (object) $payload );
				$this->written( $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $item ) ), array( 'option_name' => 'odvr_settings' ) ) );
				wp_cache_delete( 'odvr_settings', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return true;
			}
		);
		return is_wp_error( $result ) ? $result : $this->get();
	}
	/**
	 * Run作成/Settings更新の既存トランザクション内で設定行を先にロックする。
	 *
	 * @return array|WP_Error 固定設定.
	 */
	public function lock_saved() {
		return $this->read(
			function () {
				global $wpdb;
				$this->checked( ODVR_DB::writable() );
				$item = $this->checked( $this->saved() );
				$this->written( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'odvr_settings', maybe_serialize( $item ), 'no' ) ) );
				$this->rows( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $wpdb->options, 'odvr_settings' ) );
				return $this->checked( $this->saved() );
			}
		);
	}

	/**
	 * 接続診断の30秒間隔をDBの原子的比較で確定する。
	 *
	 * @return true|WP_Error 結果.
	 */
	public function claim_diagnostic() {
		return $this->read(
			function () {
				global $wpdb;
				$this->checked( ODVR_DB::writable() );
				$expires  = (string) ( time() + 30 );
				$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'odvr_connection_test_lock', $expires, 'no' ) );
				$this->written( $inserted );
				if ( 1 !== $inserted ) {
						$rows = $this->rows( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'odvr_connection_test_lock' ) );
					if ( ! $rows || ! ctype_digit( $rows[0]['option_value'] ) || (float) $rows[0]['option_value'] > time() ) {
						$this->fail( 'odvr_rate_limited', 429 ); }
					$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $expires, 'odvr_connection_test_lock', $rows[0]['option_value'] ) );
					$this->written( $updated );
					if ( 1 !== $updated ) {
						$this->fail( 'odvr_rate_limited', 429 ); }
				}
				return true;
			}
		);
	}
}
