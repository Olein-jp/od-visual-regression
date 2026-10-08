<?php
/**
 * 一時prefixのDBで導入・更新・競合・失敗・保持を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// テスト専用のDBを直接検査する。元のテーブルは変更しない.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.WP.GlobalVariablesOverride.Prohibited

/**
 * 検証を失敗として終了する。
 *
 * @param bool   $condition 条件.
 * @param string $message 検証内容.
 * @return void
 * @throws RuntimeException 条件を満たさない場合.
 */
function odvr_db_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * 固定条件に一致するSQLだけを失敗させる。
 *
 * @param string $sql SQL.
 * @return string SQL.
 */
function odvr_db_test_query( $sql ) {
	global $wpdb, $odvr_db_test_failure, $odvr_db_test_ddl, $odvr_db_test_hijack, $odvr_db_test_race;
	if ( preg_match( '/^(CREATE TABLE|ALTER TABLE|SHOW FULL COLUMNS)/i', $sql ) ) {
		++$odvr_db_test_ddl;
	}
	if ( $odvr_db_test_hijack && false !== strpos( $sql, 'CREATE TABLE ' . ODVR_DB::table( 'devices' ) ) ) {
		update_option( 'odvr_db_upgrade_lock', $odvr_db_test_hijack, false );
		$odvr_db_test_hijack = '';
	}
	if ( $odvr_db_test_race && 0 === strpos( $sql, 'INSERT IGNORE INTO' ) && false !== strpos( $sql, 'odvr_db_upgrade_lock' ) ) {
		$race              = $odvr_db_test_race;
		$odvr_db_test_race = '';
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'odvr_db_upgrade_lock',
				'option_value' => $race,
				'autoload'     => 'no',
			)
		);
	}
	if ( $odvr_db_test_failure && false !== strpos( $sql, $odvr_db_test_failure ) ) {
		return 'SELECT odvr_test_missing_column FROM odvr_test_missing_table';
	}
	return $sql;
}

/**
 * 一時テーブルと導入状態だけを初期状態へ戻す。
 *
 * @return void
 */
function odvr_db_test_reset() {
	global $wpdb;
	foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', ODVR_DB::table( $suffix ) ) );
	}
	foreach ( array( 'odvr_db_version', 'odvr_db_error', 'odvr_db_upgrade_lock', 'odvr_suspended', 'odvr_deleting_site' ) as $option ) {
		delete_option( $option );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
	}
	wp_cache_flush();
}

/**
 * 実DBで検証する。一時DB接続へ切り替え、必ず元に戻す。
 *
 * @return void
 * @throws RuntimeException 検証失敗.
 */
function odvr_test_database() {
	global $wpdb, $odvr_db_test_failure, $odvr_db_test_ddl, $odvr_db_test_hijack, $odvr_db_test_race;
	$original = $wpdb;
	$prefix   = $wpdb->prefix . 'odvrtest_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) . '_';
	$wpdb     = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->set_prefix( $prefix );
	$wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $wpdb->options, $original->options ) );
	wp_cache_flush();
	$odvr_db_test_failure = '';
	$odvr_db_test_hijack  = '';
	$odvr_db_test_race    = '';
	$odvr_db_test_ddl     = 0;
	add_filter( 'query', 'odvr_db_test_query' );
	try {
		odvr_db_assert( '2026-10-08 03:00:00' === ODVR_DB::utc_datetime( '2026-10-08T03:00:00Z' ) && '2026-10-08T03:00:00Z' === ODVR_DB::utc_datetime( '2026-10-08 03:00:00', false ), 'UTCをタイムゾーン変換せず往復できる' );
		odvr_db_assert( null === ODVR_DB::utc_datetime( null ) && is_wp_error( ODVR_DB::utc_datetime( '0000-00-00 00:00:00', false ) ) && is_wp_error( ODVR_DB::utc_datetime( '2026-02-30T03:00:00Z' ) ), '未発生時刻だけをNULLとし不正時刻・ゼロ日付を拒否する' );
		foreach ( array( 'settings', 'environment', 'metadata' ) as $kind ) {
			$json = ODVR_DB::stored_json( array( $kind . '_version' => 1 ), $kind );
			odvr_db_assert( is_string( $json ) && ODVR_DB::stored_json( $json, $kind, false ) instanceof stdClass && is_wp_error( ODVR_DB::stored_json( '{broken', $kind, false ) ) && is_wp_error( ODVR_DB::stored_json( array( $kind . '_version' => 2 ), $kind ) ), '保存JSONの破損・未知Versionを拒否する: ' . $kind );
		}
		odvr_db_assert( true === ODVR_DB::upgrade(), '新規導入で5テーブルとDB Version 1を作る' );
		odvr_db_assert( true === ODVR_DB::diagnose() && true === ODVR_DB::writable(), '列・索引・InnoDB・トランザクションを診断できる' );
		$devices = ODVR_DB::table( 'devices' );
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order', $devices ), ARRAY_A );
		odvr_db_assert( array( 'desktop', 'tablet', 'mobile' ) === array_column( $rows, 'slug' ), '初期DeviceはDesktop・Tablet・Mobile' );
		odvr_db_assert( array( '1440', '768', '390' ) === array_column( $rows, 'viewport_width' ), '初期Deviceの画面幅がsharedの既定値と一致する' );
		$wpdb->update(
			$devices,
			array(
				'name'           => '編集済み',
				'viewport_width' => 1234,
				'enabled'        => 0,
			),
			array( 'slug' => 'desktop' )
		);
		$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $devices ), ARRAY_A );
		odvr_db_assert( true === ODVR_DB::upgrade( true ), '再有効化相当の診断を通る' );
		odvr_db_assert( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $devices ), ARRAY_A ), '再導入で重複・編集値の上書きがない' );
		$odvr_db_test_ddl = 0;
		ODVR_DB::upgrade();
		odvr_db_assert( 0 === $odvr_db_test_ddl, '通常初期化はDDLや全テーブル診断を繰り返さない' );
		ODVR_Deactivator::deactivate();
		odvr_db_assert( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $devices ), ARRAY_A ), '無効化後もDBとDeviceを保持する' );
		$duplicate = $rows[0];
		unset( $duplicate['id'] );
		odvr_db_assert( false === $wpdb->insert( $devices, $duplicate ), 'Deviceの重複slugを一意索引で拒否する' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$suite  = array(
			'uuid'            => wp_generate_uuid4(),
			'name'            => 'テスト',
			'status'          => 'active',
			'baseline_run_id' => null,
			'settings'        => wp_json_encode( array( 'settings_version' => 1 ) ),
			'created_by'      => 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$suites = ODVR_DB::table( 'suites' );
		odvr_db_assert( false !== $wpdb->insert( $suites, $suite ), 'UTC datetime・Version付きJSON・NULLを保存できる' );
		$saved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $suites, $suite['uuid'] ), ARRAY_A );
		odvr_db_assert( null === $saved['baseline_run_id'] && $saved['created_at'] === $now && 1 === json_decode( $saved['settings'], true )['settings_version'], 'NULLを0にせずUTC/JSONを読める' );
		odvr_db_assert( false === $wpdb->insert( $suites, $suite ), 'SuiteのUUID一意索引が実際に働く' );
		$snapshot  = array(
			'run_id'            => 1,
			'target_id'         => 1,
			'device_id'         => 1,
			'status'            => 'pending',
			'url'               => 'https://example.com/',
			'dimension_changed' => 0,
			'metadata'          => wp_json_encode( array( 'metadata_version' => 1 ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$snapshots = ODVR_DB::table( 'snapshots' );
		odvr_db_assert( false !== $wpdb->insert( $snapshots, $snapshot ) && false === $wpdb->insert( $snapshots, $snapshot ), 'SnapshotのRun×Target×Device一意索引が実際に働く' );
		update_option( 'odvr_db_version', 99, false );
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade() ) && is_wp_error( ODVR_DB::writable() ), '未知VersionはDDLや書込を許可しない' );
		odvr_db_assert( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $devices ), ARRAY_A ), '未知Versionでも既存データを変更しない' );
		update_option( 'odvr_db_version', 0, false );
		odvr_db_assert( true === ODVR_DB::upgrade(), '既知の旧Versionから再実行してVersionを進められる' );
		odvr_db_assert( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $devices ), ARRAY_A ), '更新でもユーザー設定を上書きしない' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $devices ) );
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade( true ) ) && is_wp_error( ODVR_DB::writable() ), '非InnoDBを診断して書込を止める' );
		$engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $devices ), ARRAY_A );
		odvr_db_assert( 'MyISAM' === $engine['Engine'], '既存Engineを自動変換しない' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $devices ) );
		odvr_db_assert( true === ODVR_DB::upgrade( true ), '手動復旧後に再診断で再開できる' );
		odvr_db_test_reset();
		$odvr_db_test_failure = 'CREATE TABLE ' . ODVR_DB::table( 'runs' );
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade() ) && false === get_option( 'odvr_db_version', false ) && is_wp_error( ODVR_DB::writable() ), 'DDL失敗ではVersionを進めず書込を止める' );
		$odvr_db_test_failure = '';
		odvr_db_assert( true === ODVR_DB::upgrade(), '途中作成済みテーブルを利用して移行を再実行できる' );
		odvr_db_test_reset();
		$odvr_db_test_failure = "'Tablet'";
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade() ), '初期データ投入失敗を成功扱いしない' );
		$odvr_db_test_failure = '';
		odvr_db_assert( '0' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $devices ) ), '初期Deviceの部分投入をrollbackする' );
		odvr_db_assert( true === ODVR_DB::upgrade(), '初期投入失敗から重複なく再実行できる' );
		delete_option( 'odvr_db_version' );
		$owner = wp_json_encode(
			array(
				'owner'   => wp_generate_uuid4(),
				'expires' => time() + 120,
			)
		);
		add_option( 'odvr_db_upgrade_lock', $owner, '', false );
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade() ) && get_option( 'odvr_db_upgrade_lock' ) === $owner, '別ownerの有効leaseを取得・解除しない' );
		update_option(
			'odvr_db_upgrade_lock',
			wp_json_encode(
				array(
					'owner'   => wp_generate_uuid4(),
					'expires' => time() - 1,
				)
			),
			false
		);
		odvr_db_assert( true === ODVR_DB::upgrade() && false === get_option( 'odvr_db_upgrade_lock', false ), '期限切れleaseをCASで再取得し自分のleaseだけを解除する' );
		odvr_db_test_reset();
		$race_owner = wp_json_encode(
			array(
				'owner'   => wp_generate_uuid4(),
				'expires' => time() + 120,
			)
		);
		get_option( 'odvr_db_upgrade_lock', false );
		$odvr_db_test_race = $race_owner;
		odvr_db_assert( is_wp_error( ODVR_DB::upgrade() ) && get_option( 'odvr_db_upgrade_lock' ) === $race_owner, '古いnotoptionsキャッシュと同時取得でも先行ownerを上書きしない' );
		odvr_db_assert( is_wp_error( ODVR_DB::writable() ), '書込可否はキャッシュではなく現在のlockを検査する' );
		odvr_db_test_reset();
		$replacement         = wp_json_encode(
			array(
				'owner'   => wp_generate_uuid4(),
				'expires' => time() + 120,
			)
		);
		$odvr_db_test_hijack = $replacement;
		$lost_result         = ODVR_DB::upgrade();
		odvr_db_assert( is_wp_error( $lost_result ) && false === get_option( 'odvr_db_version', false ), 'owner喪失後は次のDDLやVersion確定へ進まない' );
		odvr_db_assert( get_option( 'odvr_db_upgrade_lock' ) === $replacement, '古いownerが新しいownerのleaseを解除しない' );
	} finally {
		$odvr_db_test_race    = '';
		$odvr_db_test_hijack  = '';
		$odvr_db_test_failure = '';
		remove_filter( 'query', 'odvr_db_test_query' );
		odvr_db_test_reset();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->options ) );
		$wpdb = $original;
		wp_cache_flush();
	}
}

try {
	odvr_test_database();
	WP_CLI::success( 'DB導入・更新・失敗・排他・保持の検証が完了しました。' );
} catch ( Throwable $odvr_test_error ) {
	WP_CLI::error( $odvr_test_error->getMessage() );
}
