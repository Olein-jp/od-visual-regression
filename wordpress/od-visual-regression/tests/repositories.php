<?php
/**
 * 一時テーブルでRepositoryの境界・参照・論理削除・競合を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// 一時DBと試験用投稿だけを操作し、finallyで片付ける.
// 独立プロセスのローカル試験用パイプと障壁ファイルを直接扱う.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.WP.GlobalVariablesOverride.Prohibited

/**
 * 条件を検査する。
 *
 * @param bool   $condition 条件.
 * @param string $message 検証内容.
 * @return void
 * @throws RuntimeException 失敗時.
 */
function odvr_repo_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * エラーコードを検査する。
 *
 * @param mixed  $result 結果.
 * @param string $code コード.
 * @return bool 一致.
 */
function odvr_repo_error( $result, $code ) {
	return is_wp_error( $result ) && $code === $result->get_error_code();
}

/**
 * Suiteの固定入力を作る。
 *
 * @param array $ids Device IDs.
 * @return stdClass 入力.
 */
function odvr_repo_suite( $ids = array( 1 ) ) {
	return json_decode(
		wp_json_encode(
			array(
				'schema_version'  => 1,
				'name'            => '試験Suite',
				'settings'        => array(
					'settings_version'      => 1,
					'navigation_timeout_ms' => 30000,
					'image_timeout_ms'      => 10000,
					'lazy_load'             => true,
					'concurrency'           => 2,
					'pixel_threshold'       => 0.1,
					'review_threshold'      => 0.001,
					'changed_threshold'     => 0.01,
					'ignore_selectors'      => array(),
				),
				'device_ids'      => $ids,
				'allowed_origins' => array( 'https://fixture.test' ),
				'retention'       => array(
					'mode'  => 'last',
					'count' => 10,
				),
			)
		)
	);
}

/**
 * Targetの固定入力を作る。
 *
 * @return stdClass 入力.
 */
function odvr_repo_target() {
	return (object) array(
		'schema_version' => 1,
		'url'            => 'https://fixture.test/original?fixture=1',
		'label'          => '対象',
		'object_id'      => null,
		'post_type'      => '',
		'enabled'        => true,
		'sort_order'     => 0,
	);
}

/**
 * 独立子プロセスを親の取得済みロックと競合させる。
 *
 * @param string   $mode 処理.
 * @param int      $id ID.
 * @param string   $trigger 親のSQL実行前の障壁.
 * @param callable $parent_operation 親のRepository処理.
 * @return array 親結果/子結果.
 * @throws RuntimeException 起動や障壁失敗時.
 */
function odvr_repo_race( $mode, $id, $trigger, $parent_operation ) {
	global $wpdb;
	$ready  = tempnam( sys_get_temp_dir(), 'odvr-ready-' );
	$result = tempnam( sys_get_temp_dir(), 'odvr-result-' );
	wp_delete_file( $ready );
	$process = null;
	$pipes   = array();
	$filter  = function ( $sql ) use ( $mode, $id, $trigger, $ready, $result, &$process, &$pipes, $wpdb ) {
		if ( null === $process && false !== strpos( $sql, $trigger ) ) {
			$command = array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/repositories-worker.php', $wpdb->prefix, $mode, (string) $id, $ready, $result );
			$process = proc_open(
				implode( ' ', array_map( 'escapeshellarg', $command ) ),
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( '競合試験プロセスを起動できません。' );
			}
			fclose( $pipes[0] );
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $ready ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
				clearstatcache( true, $ready );
			}
			if ( ! file_exists( $ready ) ) {
				throw new RuntimeException( '競合試験の障壁に到達しません。' );
			}
		}
		return $sql;
	};
	add_filter( 'query', $filter );
	try {
		$parent_operation_result = $parent_operation();
		remove_filter( 'query', $filter );
		odvr_repo_assert( is_resource( $process ), '独立DB接続がロック障壁に到達する' );
		stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status  = proc_close( $process );
		$process = null;
		odvr_repo_assert( 0 === $status, '競合試験の子プロセスが完了する: ' . wp_strip_all_tags( $stderr ) );
		return array( $parent_operation_result, json_decode( file_get_contents( $result ), true ) );
	} finally {
		remove_filter( 'query', $filter );
		if ( is_resource( $process ) ) {
			proc_terminate( $process );
			proc_close( $process );
		}
		wp_delete_file( $ready );
		wp_delete_file( $result );
	}
}

/**
 * Repositoryの一連の検証を実行する。
 *
 * @return void
 */
function odvr_test_repositories() {
	global $wpdb;
	$original = $wpdb;
	$home     = get_option( 'home' );
	$siteurl  = get_option( 'siteurl' );
	register_post_type(
		'odvr_repo_cpt',
		array(
			'public'       => true,
			'show_in_rest' => false,
		)
	);
	$post         = wp_insert_post(
		array(
			'post_type'   => 'odvr_repo_cpt',
			'post_status' => 'publish',
			'post_title'  => '公開CPT fixture',
		)
	);
	$private_post = wp_insert_post(
		array(
			'post_status' => 'draft',
			'post_title'  => '非公開fixture',
		)
	);
	$prefix       = $wpdb->prefix . 'odvrrepotest_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) . '_';
	$wpdb         = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->set_prefix( $prefix );
	$wpdb->set_blog_id( get_current_blog_id() );
	$wpdb->suppress_errors( true );
	// 投稿解決は本来のサイトを参照し、ODVRデータとOptionsだけを隔離する.
	$wpdb->posts              = $original->posts;
	$wpdb->postmeta           = $original->postmeta;
	$wpdb->terms              = $original->terms;
	$wpdb->term_taxonomy      = $original->term_taxonomy;
	$wpdb->term_relationships = $original->term_relationships;
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $wpdb->options, $original->options ) );
	wp_cache_flush();
	try {
		update_option( 'home', $home, false );
		update_option( 'siteurl', $siteurl, false );
		$upgraded = ODVR_DB::upgrade();
		odvr_repo_assert( true === $upgraded, '試験専用DBを導入する: ' . ( is_wp_error( $upgraded ) ? $upgraded->get_error_code() : '正常' ) );
		$suites  = new ODVR_Suite_Repository();
		$targets = new ODVR_Target_Repository();
		$devices = new ODVR_Device_Repository();
		$one     = $suites->create( odvr_repo_suite(), 1 );
		$two     = $suites->create( odvr_repo_suite( array( 2 ) ), 1 );
		odvr_repo_assert( is_array( $one ) && 'active' === $one['status'] && 0 === $one['target_count'] && null === $one['latest_run'], 'Suiteの設定・Version・UUID・集計を保存できる' );
		odvr_repo_assert( 2 === $suites->list_items( 1, 1 )['total'] && 1 === count( $suites->list_items( 1, 1 )['items'] ), '一覧は件数とページを分けて返す' );
		foreach ( array( 0, -1, '1', true, 1.2, 2147483648 ) as $bad_id ) {
			odvr_repo_assert( is_wp_error( $suites->get( $bad_id ) ), 'IDを丸めず検査する' );
		}
		foreach ( array(
			json_decode( '{}' ),
			json_decode( '[]' ),
			(object) array(
				'schema_version' => 1,
				'name'           => 2,
			),
			(object) array(
				'schema_version' => 1,
				'name'           => '不正',
				'secret'         => 'fixture-only',
			),
		) as $invalid ) {
			odvr_repo_assert( is_wp_error( $suites->update( $one['id'], $invalid ) ), '空JSON・型・未知項目・秘密項目を拒否する' );
		}
		$invalid = odvr_repo_suite( array( 1, 1 ) );
		odvr_repo_assert( is_wp_error( $suites->create( $invalid, 1 ) ) && odvr_repo_error( $suites->create( odvr_repo_suite( array( 99999 ) ), 1 ), 'odvr_invalid_device_reference' ), '重複Device IDと欠損参照を拒否する' );
		$invalid                              = odvr_repo_suite();
		$invalid->settings->changed_threshold = 0.0001;
		odvr_repo_assert( is_wp_error( $suites->create( $invalid, 1 ) ), '閾値の逆転を拒否する' );
		$device_input = (object) array(
			'schema_version'      => 1,
			'name'                => '試験Device',
			'slug'                => 'fixture-extra',
			'viewport_width'      => 320,
			'viewport_height'     => 240,
			'device_scale_factor' => 1.5,
			'is_mobile'           => false,
			'has_touch'           => false,
			'user_agent'          => '',
			'enabled'             => true,
			'sort_order'          => 5,
		);
		$extra        = $devices->create( $device_input );
		odvr_repo_assert( is_array( $extra ) && 1.5 === $extra['device_scale_factor'] && odvr_repo_error( $devices->create( $device_input ), 'odvr_slug_conflict' ), 'Deviceの数値型とslugの一意性を保証する' );
		odvr_repo_assert(
			odvr_repo_error(
				$devices->update(
					$extra['id'],
					(object) array(
						'schema_version' => 1,
						'slug'           => 'renamed',
					)
				),
				'odvr_slug_immutable'
			),
			'作成後のslug変更を拒否する'
		);
		$in_use = $devices->disable( 1 );
		odvr_repo_assert( odvr_repo_error( $in_use, 'odvr_device_in_use' ) && $one['id'] === $in_use->get_error_data()['using_suites'][0]->id, '参照中Deviceの無効化を利用Suite付きで拒否する' );
		odvr_repo_assert( false === $devices->disable( $extra['id'] )['enabled'] && odvr_repo_error( $suites->create( odvr_repo_suite( array( $extra['id'] ) ), 1 ), 'odvr_invalid_device_reference' ), '未参照Deviceを論理削除し無効Deviceの選択を拒否する' );
		$ids = array( 1, 2, 3 );
		for ( $i = 0; $i < 8; ++$i ) {
			$input       = clone $device_input;
			$input->slug = 'fixture-device-' . $i;
			$created     = $devices->create( $input );
			$ids[]       = $created['id'];
		}
		odvr_repo_assert( is_array( $suites->create( odvr_repo_suite( array_slice( $ids, 0, 10 ) ), 1 ) ) && is_wp_error( $suites->create( odvr_repo_suite( $ids ), 1 ) ), '登録総数と区別してSuite選択Deviceの10件境界を検査する' );
		$many_device       = clone $device_input;
		$many_device->slug = 'fixture-many-suites';
		$many_device       = $devices->create( $many_device );
		for ( $i = 0; $i < 101; ++$i ) {
			$many_suite = $suites->create( odvr_repo_suite( array( $many_device['id'] ) ), 1 );
			if ( is_wp_error( $many_suite ) ) {
				odvr_repo_assert( false, '多数Suiteの参照を保存する' ); }
		}
		$many_usage    = $devices->get( $many_device['id'] );
		$many_disabled = $devices->disable( $many_device['id'] );
		odvr_repo_assert( is_array( $many_usage ) && 101 === count( $many_usage['using_suites'] ) && odvr_repo_error( $many_disabled, 'odvr_device_in_use' ) && 101 === count( $many_disabled->get_error_data()['using_suites'] ), 'Suite一覧のページ上限を参照総数に適用せず全利用先を保護する' );
		$target = $targets->create( $one['id'], odvr_repo_target() );
		odvr_repo_assert(
			is_array( $target ) && odvr_repo_error(
				$targets->update(
					$two['id'],
					$target['id'],
					(object) array(
						'schema_version' => 1,
						'label'          => '別Suite',
					)
				),
				'odvr_not_found'
			),
			'Custom URLを保存し別Suiteからの編集を拒否する'
		);
		$input            = odvr_repo_target();
		$input->object_id = $post;
		$input->post_type = 'odvr_repo_cpt';
		$cpt              = $targets->create( $one['id'], $input );
		odvr_repo_assert( is_array( $cpt ) && get_permalink( $post ) === $cpt['url'] && $input->url !== $cpt['url'], '公開CPTの現在のpermalinkを保存し自己申告URLを採用しない' );
		foreach ( array( array( $private_post, 'post' ), array( $post, 'post' ), array( 99999999, 'post' ), array( null, 'post' ) ) as $reference ) {
			$input            = odvr_repo_target();
			$input->object_id = $reference[0];
			$input->post_type = $reference[1];
			odvr_repo_assert( is_wp_error( $targets->create( $one['id'], $input ) ), '非公開・型不一致・欠損・Customとの混在参照を拒否する' );
		}
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);
		odvr_repo_assert(
			is_wp_error(
				$suites->update(
					$one['id'],
					(object) array(
						'schema_version' => 1,
						'name'           => '再検証',
					)
				)
			),
			'Suite編集時に公開状態を再検査する'
		);
		odvr_repo_assert( false === $targets->disable( $one['id'], $cpt['id'] )['enabled'], '非公開になった投稿でもTargetを無効化できる' );
		odvr_repo_assert(
			odvr_repo_error(
				$targets->update(
					$one['id'],
					$cpt['id'],
					(object) array(
						'schema_version' => 1,
						'enabled'        => true,
					)
				),
				'odvr_target_disabled'
			),
			'無効化Targetを再利用しない'
		);
		$replacement = $targets->create( $one['id'], odvr_repo_target() );
		odvr_repo_assert( $replacement['id'] !== $target['id'] && $replacement['id'] !== $cpt['id'], '同一URLの再追加も新しいTarget IDになる' );
		$before = $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM %i WHERE id = %d', ODVR_DB::table( 'suites' ), $one['id'] ) );
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'settings' => '{broken' ), array( 'id' => $one['id'] ) );
		odvr_repo_assert( is_wp_error( $suites->get( $one['id'] ) ) && is_wp_error( $suites->archive( $one['id'] ) ), '破損JSONを空設定に読み替えず書込もrollbackする' );
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'settings' => str_replace( '"settings_version":1', '"settings_version":2', $before ) ), array( 'id' => $one['id'] ) );
		odvr_repo_assert( is_wp_error( $targets->create( $one['id'], odvr_repo_target() ) ), '未知の保存Versionでは操作を拒否する' );
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'settings' => $before ), array( 'id' => $one['id'] ) );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert(
			ODVR_DB::table( 'targets' ),
			array(
				'suite_id'   => $one['id'],
				'object_id'  => null,
				'post_type'  => '',
				'url'        => 'https://fixture.test/outer-transaction',
				'label'      => '外部トランザクション',
				'enabled'    => 1,
				'sort_order' => 0,
				'created_at' => ODVR_DB::utc_now(),
			)
		);
		$outer = $targets->create( $one['id'], odvr_repo_target() );
		odvr_repo_assert( is_wp_error( $outer ) && '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url = %s', ODVR_DB::table( 'targets' ), 'https://fixture.test/outer-transaction' ) ), '外部トランザクションを拒否して呼出側の書込をrollbackしない' );
		$wpdb->query( 'ROLLBACK' );
		odvr_repo_assert( '0' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE url = %s', ODVR_DB::table( 'targets' ), 'https://fixture.test/outer-transaction' ) ), '外部トランザクションを無断COMMITしない' );
		$failure_count  = 0;
		$failure_filter = function ( $sql ) use ( &$failure_count ) {
			if ( false !== strpos( $sql, 'INSERT INTO `' . ODVR_DB::table( 'targets' ) . '`' ) && 0 === $failure_count++ ) {
				return "SIGNAL SQLSTATE '40001' SET MESSAGE_TEXT = 'Deadlock found fixture-only'";
			}
			return $sql;
		};
		add_filter( 'query', $failure_filter );
		$retried = $targets->create( $one['id'], odvr_repo_target() );
		remove_filter( 'query', $failure_filter );
		odvr_repo_assert( is_array( $retried ) && 2 === $failure_count, 'Deadlock時は全トランザクションを一度だけ再試行する' );
		$failure_count  = 0;
		$failure_filter = function ( $sql ) use ( &$failure_count ) {
			if ( false !== strpos( $sql, 'INSERT INTO `' . ODVR_DB::table( 'targets' ) . '`' ) ) {
				++$failure_count;
				return "SIGNAL SQLSTATE '40001' SET MESSAGE_TEXT = 'Deadlock found fixture-only'";
			}
			return $sql;
		};
		$target_count   = $targets->list_items( $one['id'] )['total'];
		add_filter( 'query', $failure_filter );
		$failed = $targets->create( $one['id'], odvr_repo_target() );
		remove_filter( 'query', $failure_filter );
		odvr_repo_assert( odvr_repo_error( $failed, 'odvr_database_error' ) && 2 === $failure_count && $target_count === $targets->list_items( $one['id'] )['total'], '再試行は有限で失敗時に部分行を残さない' );
		$count_before = $devices->list_items()['total'];
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = 2147483648', ODVR_DB::table( 'devices' ) ) );
		$overflow       = clone $device_input;
		$overflow->slug = 'fixture-overflow';
		odvr_repo_assert( odvr_repo_error( $devices->create( $overflow ), 'odvr_invalid_stored_data' ) && $count_before === $devices->list_items()['total'], 'DB採番がAPI安全範囲を越えたら丸めずrollbackする' );
		$maximum = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', ODVR_DB::table( 'devices' ) ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = %d', ODVR_DB::table( 'devices' ), (int) $maximum + 1 ) );
		$race = odvr_repo_race(
			'suite-update',
			$one['id'],
			'UPDATE `' . ODVR_DB::table( 'suites' ) . '`',
			function () use ( $suites, $one ) {
				return $suites->update(
					$one['id'],
					(object) array(
						'schema_version' => 1,
						'name'           => '親の編集',
					)
				);
			}
		);
		odvr_repo_assert( is_array( $race[0] ) && isset( $race[1]['item'] ) && '親の編集' === $suites->get( $one['id'] )['name'] && 20 === $suites->get( $one['id'] )['retention']->count, '同時PATCHは最新値をロック読取し別項目の変更を失わない' );
		$unused       = clone $device_input;
		$unused->slug = 'fixture-race';
		$unused       = $devices->create( $unused );
		$race         = odvr_repo_race(
			'device-disable',
			$unused['id'],
			'INSERT INTO `' . ODVR_DB::table( 'suites' ) . '`',
			function () use ( $suites, $unused ) {
				return $suites->create( odvr_repo_suite( array( $unused['id'] ) ), 1 );
			}
		);
		odvr_repo_assert( is_array( $race[0] ) && 'odvr_device_in_use' === $race[1]['error'] && true === $devices->get( $unused['id'] )['enabled'], 'Device無効化は競合したSuite保存の新しい参照を検知する' );
		$limit = $suites->create( odvr_repo_suite(), 1 );
		for ( $i = 0; $i < 99; ++$i ) {
			odvr_repo_assert( is_array( $targets->create( $limit['id'], odvr_repo_target() ) ), '上限前Targetを保存する' ); }
		$race = odvr_repo_race(
			'target-create',
			$limit['id'],
			'INSERT INTO `' . ODVR_DB::table( 'targets' ) . '`',
			function () use ( $targets, $limit ) {
				return $targets->create( $limit['id'], odvr_repo_target() );
			}
		);
		odvr_repo_assert( is_array( $race[0] ) && 'odvr_target_limit' === $race[1]['error'] && 100 === $targets->list_items( $limit['id'], 1, 100 )['total'], '同時Target追加も100件の上限を越えない' );
		$wpdb->insert(
			ODVR_DB::table( 'runs' ),
			array(
				'uuid'                => wp_generate_uuid4(),
				'suite_id'            => $two['id'],
				'status'              => 'queued',
				'triggered_by'        => 1,
				'environment'         => '{"environment_version":1}',
				'manifest'            => '{"schema_version":1}',
				'total_snapshots'     => 1,
				'completed_snapshots' => 0,
				'error_snapshots'     => 0,
				'created_at'          => ODVR_DB::utc_now(),
				'updated_at'          => ODVR_DB::utc_now(),
				'deadline_at'         => gmdate( 'Y-m-d H:i:s', time() + 900 ),
			)
		);
		$run_id = $wpdb->insert_id;
		odvr_repo_assert( odvr_repo_error( $suites->archive( $two['id'] ), 'odvr_suite_busy' ), '実行中Suiteのarchiveを拒否する' );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'status' => 'complete' ), array( 'id' => $run_id ) );
		$run_before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run_id ), ARRAY_A );
		odvr_repo_assert( 'archived' === $suites->archive( $two['id'] )['status'] && 'archived' === $suites->archive( $two['id'] )['status'] && is_wp_error( $targets->create( $two['id'], odvr_repo_target() ) ), 'archiveは冪等で新規Targetを拒否する' );
		odvr_repo_assert( $run_before === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run_id ), ARRAY_A ), 'archiveしてもRunの固定設定と履歴を変更しない' );
		odvr_repo_assert( odvr_repo_error( $devices->disable( 2 ), 'odvr_device_in_use' ), 'archive済みSuiteのDevice参照も維持する' );
		if ( is_multisite() ) {
			switch_to_blog( get_current_blog_id() + 1000000 );
			odvr_repo_assert( odvr_repo_error( $suites->get( $one['id'] ), 'odvr_site_mismatch' ), 'Multisiteのサイト切替後は旧Repositoryを拒否する' );
			restore_current_blog();
		}
		$wpdb->set_prefix( $prefix . 'other_' );
		odvr_repo_assert( odvr_repo_error( $suites->get( $one['id'] ), 'odvr_site_mismatch' ) && odvr_repo_error( $targets->disable( $one['id'], $target['id'] ), 'odvr_site_mismatch' ) && odvr_repo_error( $devices->disable( 1 ), 'odvr_site_mismatch' ), '別サイトprefixへRepositoryを使い回せない' );
		$wpdb->set_prefix( $prefix );
		update_option( 'odvr_db_error', 'fixture-only', false );
		odvr_repo_assert( odvr_repo_error( $suites->create( odvr_repo_suite(), 1 ), 'odvr_database_not_ready' ), 'DB診断失敗時はRepositoryの書込を停止する' );
	} finally {
		$wpdb->set_prefix( $prefix );
		foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', ODVR_DB::table( $suffix ) ) ); }
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->options ) );
		$wpdb = $original;
		wp_cache_flush();
		wp_delete_post( $post, true );
		wp_delete_post( $private_post, true );
		unregister_post_type( 'odvr_repo_cpt' );
	}
}

try {
	odvr_test_repositories();
	WP_CLI::success( 'Repositoryの境界・参照・保持・独立接続の競合検証が完了しました。' );
} catch ( Throwable $odvr_repo_test_error ) {
	WP_CLI::error( $odvr_repo_test_error->getMessage() );
}
