<?php
/**
 * DB導入・更新、診断と書込境界。
 *
 * @package OD_Visual_Regression
 */

// 排他・診断には最新のDB状態が必要なため、キャッシュしない直接SQLを使う.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 排他leaseを持つ処理だけがDDL・初期データを更新する。
 */
final class ODVR_DB {
	const VERSION       = 1;
	const LEASE_SECONDS = 120;

	/**
	 * 固定したサフィックスと現在サイトのprefixからテーブル名を返す。
	 *
	 * @param string $suffix テーブル種別.
	 * @return string テーブル名.
	 * @throws InvalidArgumentException 未定義テーブルの場合.
	 */
	public static function table( $suffix ) {
		global $wpdb;
		if ( ! isset( ODVR_DB_Schema::definitions()[ $suffix ] ) ) {
			throw new InvalidArgumentException( 'odvr_unknown_table' );
		}
		return $wpdb->prefix . 'odvr_' . $suffix;
	}

	/**
	 * UTCで現在のDB時刻を返す。
	 *
	 * @return string UTC datetime.
	 */
	public static function utc_now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * UTC秒精度の通信時刻とDB datetimeを変換する。
	 *
	 * @param string|null $value 元の時刻.
	 * @param bool        $to_database DB形式へ変換するか.
	 * @return string|null|WP_Error 変換結果.
	 */
	public static function utc_datetime( $value, $to_database = true ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || (int) substr( $value, 0, 4 ) < 1000 ) {
			return self::error( 'odvr_invalid_datetime' );
		}
		$api    = 'Y-m-d\TH:i:s\Z';
		$db     = 'Y-m-d H:i:s';
		$format = $to_database ? $api : $db;
		$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( $format ) !== $value ) {
			return self::error( 'odvr_invalid_datetime' );
		}
		return $date->format( $to_database ? $db : $api );
	}

	/**
	 * 保存JSONをVersion付きで変換する。破損を空設定に読み替えない。
	 *
	 * @param mixed  $value JSON値または保存文字列.
	 * @param string $kind settings / environment / metadata.
	 * @param bool   $encode 保存文字列へ変換するか.
	 * @return mixed|WP_Error 変換結果.
	 */
	public static function stored_json( $value, $kind, $encode = true ) {
		if ( ! in_array( $kind, array( 'settings', 'environment', 'metadata' ), true ) ) {
			return self::error( 'odvr_unsupported_storage_version' );
		}
		if ( ! $encode ) {
			if ( ! is_string( $value ) ) {
				return self::error( 'odvr_invalid_stored_json' );
			}
			$value = json_decode( $value );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return self::error( 'odvr_invalid_stored_json' );
			}
		}
		if ( ! is_array( $value ) && ! $value instanceof stdClass ) {
			return self::error( 'odvr_invalid_stored_json' );
		}
		$key    = $kind . '_version';
		$fields = (array) $value;
		if ( ! isset( $fields[ $key ] ) || ! ( is_int( $fields[ $key ] ) || is_float( $fields[ $key ] ) ) || 1.0 !== (float) $fields[ $key ] ) {
			return self::error( 'odvr_unsupported_storage_version' );
		}
		if ( ! $encode ) {
			return $value;
		}
		$json = wp_json_encode( $value );
		return false === $json ? self::error( 'odvr_invalid_stored_json' ) : $json;
	}

	/**
	 * 通常の書込・削除を許可できるか確認する。DDLや全走査はしない。
	 *
	 * @return true|WP_Error 結果.
	 */
	public static function writable() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s, %s, %s)', $wpdb->options, 'odvr_db_version', 'odvr_db_error', 'odvr_db_upgrade_lock', 'odvr_suspended', 'odvr_deleting_site' ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return self::error( 'odvr_database_not_ready' );
		}
		$state = array_column( $rows, 'option_value', 'option_name' );
		if ( '1' !== (string) ( $state['odvr_db_version'] ?? '' ) || isset( $state['odvr_db_error'] ) || isset( $state['odvr_db_upgrade_lock'] ) || isset( $state['odvr_suspended'] ) || isset( $state['odvr_deleting_site'] ) ) {
			return self::error( 'odvr_database_not_ready' );
		}
		return true;
	}

	/**
	 * Version差がある場合だけ更新する。有効化時には既存構造も診断する。
	 *
	 * @param bool $verify_current 現Versionの実構造も検査するか.
	 * @return true|WP_Error 結果.
	 */
	public static function upgrade( $verify_current = false ) {
		if ( false !== get_option( 'odvr_deleting_site', false ) ) {
			return self::error( 'odvr_database_not_ready' );
		}
		$version = get_option( 'odvr_db_version', false );
		if ( false !== $version && ! in_array( (string) $version, array( '0', '1' ), true ) ) {
			return self::fail( 'odvr_unsupported_db_version' );
		}
		if ( '1' === (string) $version && ! $verify_current ) {
			return self::writable();
		}
		$lease = self::acquire();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		try {
			$result = self::diagnose_engines();
			if ( is_wp_error( $result ) ) {
				return self::fail( $result->get_error_code() );
			}
			if ( '1' !== (string) $version ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				global $wpdb;
				foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
					if ( ! self::renew( $lease ) ) {
						return self::fail( 'odvr_database_lease_lost' );
					}
					$lines = array();
					foreach ( $definition['columns'] as $name => $column ) {
						$lines[] = "$name $column";
					}
					foreach ( $definition['indexes'] as $name => $index ) {
						$columns = implode( ',', $index['columns'] );
						$lines[] = 'PRIMARY' === $name ? "PRIMARY KEY  ($columns)" : ( $index['unique'] ? "UNIQUE KEY $name ($columns)" : "KEY $name ($columns)" );
					}
					$table   = self::table( $suffix );
					$collate = $wpdb->get_charset_collate();
					dbDelta( "CREATE TABLE $table (\n" . implode( ",\n", $lines ) . "\n) ENGINE=InnoDB $collate;" );
				}
			}
			if ( ! self::renew( $lease ) ) {
				return self::fail( 'odvr_database_lease_lost' );
			}
			$result = self::diagnose();
			if ( is_wp_error( $result ) ) {
				return self::fail( $result->get_error_code() );
			}
			if ( '1' !== (string) $version ) {
				$result = self::seed_devices( $lease );
				if ( is_wp_error( $result ) ) {
					return self::fail( $result->get_error_code() );
				}
			}
			if ( ! self::renew( $lease ) ) {
				return self::fail( 'odvr_database_lease_lost' );
			}
			update_option( 'odvr_db_version', self::VERSION, false );
			if ( '1' !== (string) get_option( 'odvr_db_version' ) ) {
				return self::fail( 'odvr_database_version_write_failed' );
			}
			delete_option( 'odvr_db_error' );
			return true;
		} finally {
			self::release( $lease );
		}
	}

	/**
	 * InnoDBと既存テーブルのEngineを確認する。自動変換しない。
	 *
	 * @return true|WP_Error 結果.
	 */
	private static function diagnose_engines() {
		global $wpdb;
		$engines = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
		$valid   = false;
		foreach ( $engines as $engine ) {
			if ( 'InnoDB' === $engine['Engine'] && in_array( $engine['Support'], array( 'YES', 'DEFAULT' ), true ) && 'YES' === $engine['Transactions'] ) {
				$valid = true;
			}
		}
		if ( ! $valid ) {
			return self::error( 'odvr_database_transactions_unavailable' );
		}
		foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
			$table = self::table( $suffix );
			$row   = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( $row && 'InnoDB' !== $row['Engine'] ) {
				return self::error( 'odvr_database_engine_mismatch' );
			}
		}
		return true;
	}

	/**
	 * 実テーブルの列・型・null・一意索引を確認する。
	 *
	 * @return true|WP_Error 結果.
	 */
	public static function diagnose() {
		global $wpdb;
		$result = self::diagnose_engines();
		if ( is_wp_error( $result ) ) {
			return self::fail( $result->get_error_code() );
		}
		foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
			$table  = self::table( $suffix );
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( ! $status ) {
				return self::fail( 'odvr_database_schema_mismatch' );
			}
			$columns = array_column( $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A ), null, 'Field' );
			foreach ( $definition['columns'] as $name => $expected ) {
				$type = preg_replace( '/ (not null|null).*$/', '', strtolower( $expected ) );
				if ( ! isset( $columns[ $name ] ) || self::normalize_type( $columns[ $name ]['Type'] ) !== self::normalize_type( $type ) || ( false !== strpos( $expected, 'NOT NULL' ) ? 'NO' : 'YES' ) !== $columns[ $name ]['Null'] || ( 'id' === $name && 'auto_increment' !== $columns[ $name ]['Extra'] ) ) {
					return self::fail( 'odvr_database_schema_mismatch' );
				}
			}
			$indexes = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ) as $index ) {
				$indexes[ $index['Key_name'] ]['unique']                                  = '0' === (string) $index['Non_unique'];
				$indexes[ $index['Key_name'] ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
			foreach ( $definition['indexes'] as $name => $expected ) {
				if ( ! isset( $indexes[ $name ] ) ) {
					return self::fail( 'odvr_database_schema_mismatch' );
				}
				ksort( $indexes[ $name ]['columns'] );
				if ( $indexes[ $name ]['unique'] !== $expected['unique'] || array_values( $indexes[ $name ]['columns'] ) !== $expected['columns'] ) {
					return self::fail( 'odvr_database_schema_mismatch' );
				}
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) || false === $wpdb->query( 'SAVEPOINT odvr_diagnosis' ) || false === $wpdb->query( 'ROLLBACK TO SAVEPOINT odvr_diagnosis' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::fail( 'odvr_database_transactions_unavailable' );
		}
		$wpdb->query( 'ROLLBACK' );
		return true;
	}

	/**
	 * MySQL 8の整数表示幅省略に対応する。
	 *
	 * @param string $type 列型.
	 * @return string 比較用の型.
	 */
	private static function normalize_type( $type ) {
		return preg_replace( '/\b(bigint|int|smallint|tinyint)\([0-9]+\)/', '$1', strtolower( $type ) );
	}

	/**
	 * 初回のDeviceだけを補完する。既存slugの値は上書きしない。
	 *
	 * @param array $lease 排他lease.
	 * @return true|WP_Error 結果.
	 */
	private static function seed_devices( array &$lease ) {
		global $wpdb;
		if ( ! self::renew( $lease ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'odvr_database_seed_failed' );
		}
		$table   = self::table( 'devices' );
		$devices = array(
			array( 'Desktop', 'desktop', 1440, 900, 0, 0 ),
			array( 'Tablet', 'tablet', 768, 1024, 0, 1 ),
			array( 'Mobile', 'mobile', 390, 844, 1, 1 ),
		);
		foreach ( $devices as $order => $device ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $table, $device[1] ) );
			if ( null === $id && false === $wpdb->insert(
				$table,
				array(
					'name'                => $device[0],
					'slug'                => $device[1],
					'viewport_width'      => $device[2],
					'viewport_height'     => $device[3],
					'user_agent'          => '',
					'device_scale_factor' => 1,
					'is_mobile'           => $device[4],
					'has_touch'           => $device[5],
					'enabled'             => 1,
					'sort_order'          => $order,
				)
			) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'odvr_database_seed_failed' );
			}
		}
		if ( ! self::renew( $lease ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'odvr_database_seed_failed' );
		}
		return true;
	}

	/**
	 * キャッシュを消してlockのCAS操作を反映する。
	 *
	 * @return void
	 */
	private static function invalidate_lock_cache() {
		wp_cache_delete( 'odvr_db_upgrade_lock', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Owner・期限を持つサイト単位のlockを原子的に獲得する。
	 *
	 * @return array|WP_Error leaseまたは競合エラー.
	 */
	private static function acquire() {
		global $wpdb;
		$lease   = array(
			'owner'   => wp_generate_uuid4(),
			'expires' => time() + self::LEASE_SECONDS,
		);
		$encoded = wp_json_encode( $lease );
		// add_optionは重複時のUPDATEがあるため、古いnotoptionsキャッシュ下でlockを上書きし得る.
		if ( 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'odvr_db_upgrade_lock', $encoded, 'no' ) ) ) {
			self::invalidate_lock_cache();
			return $lease;
		}
		$old      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'odvr_db_upgrade_lock' ) );
		$previous = json_decode( $old, true );
		if ( ! is_array( $previous ) || ! isset( $previous['owner'], $previous['expires'] ) || ! is_string( $previous['owner'] ) || ! is_int( $previous['expires'] ) || $previous['expires'] > time() || 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $encoded, 'odvr_db_upgrade_lock', $old ) ) ) {
			return self::error( 'odvr_database_upgrade_busy' );
		}
		self::invalidate_lock_cache();
		return $lease;
	}

	/**
	 * Ownerを失わずにleaseを更新する。
	 *
	 * @param array $lease 更新するlease.
	 * @return bool 所有を維持できたか.
	 */
	private static function renew( array &$lease ) {
		global $wpdb;
		if ( $lease['expires'] <= time() ) {
			return false;
		}
		$old              = wp_json_encode( $lease );
		$lease['expires'] = time() + self::LEASE_SECONDS;
		$new              = wp_json_encode( $lease );
		// 同じ秒の更新は値が変わらないため、影響行数0と所有喪失を区別する.
		$result  = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $new, 'odvr_db_upgrade_lock', $old ) );
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'odvr_db_upgrade_lock' ) );
		self::invalidate_lock_cache();
		return false !== $result && $new === $current;
	}

	/**
	 * 自分が持つleaseだけを解除する。
	 *
	 * @param array $lease 解除するlease.
	 * @return void
	 */
	private static function release( array $lease ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'odvr_db_upgrade_lock', wp_json_encode( $lease ) ) );
		self::invalidate_lock_cache();
	}

	/**
	 * 診断を残して書込を止める。SQLや秘密は保存しない。
	 *
	 * @param string $code 定型診断code.
	 * @return WP_Error エラー.
	 */
	private static function fail( $code ) {
		update_option( 'odvr_db_error', $code, false );
		return self::error( $code );
	}

	/**
	 * 定型DBエラーを返す。
	 *
	 * @param string $code エラーcode.
	 * @return WP_Error エラー.
	 */
	private static function error( $code ) {
		return new WP_Error( $code, __( 'OD Visual RegressionのDBを利用できません。導入状態を確認してください。', 'od-visual-regression' ), array( 'status' => 503 ) );
	}
}
