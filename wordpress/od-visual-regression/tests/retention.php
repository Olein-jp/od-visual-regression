<?php
/**
 * 隔離DBと私有UploadsでRetention・削除再開・停止を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; }
// ランダムprefixの5テーブル/Optionsと一時Uploadsだけを操作する.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_get_status
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.IniSet.memory_limit_Disallowed

/**
 * 条件を確認する。
 *
 * @param mixed  $value 結果.
 * @param string $message 内容.
 * @return mixed 正常値.
 * @throws RuntimeException 失敗時.
 */
function odvr_retention_check( $value, $message ) {
	if ( false === $value || is_wp_error( $value ) ) {
		throw new RuntimeException( esc_html( $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ) ); }
	WP_CLI::log( '確認済み: ' . $message );
	return $value;
}

/**
 * 共通契約から単一Runの固定fixtureを作る。
 *
 * @param int    $suite_id Suite ID.
 * @param int    $index 時系列順.
 * @param string $status 状態.
 * @return array Run ID/UUID/Snapshot ID.
 */
function odvr_retention_run( $suite_id, $index, $status = 'failed' ) {
	global $wpdb;
	$fixtures = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.json' ) );
	$seed     = array();
	foreach ( $fixtures as $fixture ) {
		if ( $fixture->valid && in_array( $fixture->schema, array( 'stored-run-manifest', 'stored-run-environment', 'snapshot-metadata' ), true ) ) {
			$seed[ $fixture->schema ] = clone $fixture->value; }
	}
	$manifest                = $seed['stored-run-manifest'];
	$manifest->suite->id     = $suite_id;
	$environment             = $seed['stored-run-environment'];
	$environment->runner     = '1';
	$environment->playwright = '1';
	$environment->chromium   = '1';
	$uuid                    = wp_generate_uuid4();
	$at                      = gmdate( 'Y-m-d H:i:s', 1600000000 + $index );
	$wpdb->insert(
		ODVR_DB::table( 'runs' ),
		array(
			'uuid'                    => $uuid,
			'suite_id'                => $suite_id,
			'status'                  => $status,
			'triggered_by'            => 1,
			'runner_token_hash'       => str_repeat( 'a', 64 ),
			'runner_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7200 ),
			'environment'             => wp_json_encode( $environment ),
			'manifest'                => wp_json_encode( $manifest ),
			'total_snapshots'         => 1,
			'completed_snapshots'     => 'complete' === $status ? 1 : 0,
			'error_snapshots'         => 'failed' === $status ? 1 : 0,
			'created_at'              => $at,
			'updated_at'              => $at,
			'completed_at'            => in_array( $status, array( 'queued', 'running' ), true ) ? null : $at,
			'deadline_at'             => gmdate( 'Y-m-d H:i:s', time() + 5400 ),
			'error_code'              => 'failed' === $status ? 'RUN_ABORTED' : null,
			'error_message'           => 'failed' === $status ? ODVR_Run_Manager::error_message( 'RUN_ABORTED' ) : null,
		)
	);
	$id       = (int) $wpdb->insert_id;
	$metadata = $seed['snapshot-metadata'];
	$result   = (object) array(
		'schema_version'     => 1,
		'target_id'          => 1,
		'device_id'          => 1,
		'status'             => 'complete' === $status ? 'CAPTURED' : 'ERROR',
		'width'              => 'complete' === $status ? 1 : null,
		'height'             => 'complete' === $status ? 1 : null,
		'baseline_width'     => null,
		'baseline_height'    => null,
		'dimension_changed'  => false,
		'diff_pixels'        => null,
		'total_pixels'       => null,
		'diff_ratio'         => null,
		'http_status'        => 'complete' === $status ? 200 : null,
		'duration_ms'        => 0,
		'error_code'         => 'complete' === $status ? null : 'RUN_ABORTED',
		'error_message'      => 'complete' === $status ? null : ODVR_Run_Manager::error_message( 'RUN_ABORTED' ),
		'no_baseline_reason' => null,
	);
	if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
		$metadata->result        = $result;
		$metadata->result_digest = ODVR_Environment::digest( $result ); }

	$image_path = null;
	if ( 'complete' === $status ) {
		$image = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );
		$metadata->image_sha256 = hash( 'sha256', $png );
		$suite                  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'suites' ), $suite_id ), ARRAY_A );
		odvr_retention_check(
			( new ODVR_Storage() )->with_run_lock(
				$uuid,
				true,
				function () {
					return true;
				}
			),
			'比較用PNG fixtureの固定lockを準備する'
		);
		$image_path = 'suite-' . $suite['uuid'] . '/run-' . $uuid . '/target-1/example-' . $metadata->result_digest . '.png';
		$absolute   = wp_upload_dir()['basedir'] . '/od-visual-regression/' . $image_path;
		mkdir( dirname( $absolute ), 0700, true );
		file_put_contents( $absolute, $png );
		chmod( $absolute, 0600 );
	}
	$wpdb->insert(
		ODVR_DB::table( 'snapshots' ),
		array(
			'run_id'            => $id,
			'image_path'        => $image_path,
			'target_id'         => 1,
			'device_id'         => 1,
			'status'            => in_array( $status, array( 'queued', 'running' ), true ) ? 'PENDING' : $result->status,
			'url'               => $manifest->targets[0]->url,
			'width'             => $result->width,
			'height'            => $result->height,
			'http_status'       => $result->http_status,
			'duration_ms'       => 0,
			'dimension_changed' => 0,
			'error_code'        => in_array( $status, array( 'queued', 'running' ), true ) ? null : $result->error_code,
			'error_message'     => in_array( $status, array( 'queued', 'running' ), true ) ? null : $result->error_message,
			'metadata'          => wp_json_encode( $metadata ),
			'created_at'        => $at,
			'updated_at'        => $at,
		)
	);
	return array(
		'id'          => $id,
		'uuid'        => $uuid,
		'snapshot_id' => (int) $wpdb->insert_id,
	);
}

/**
 * 試験用の独立WP-CLIを起動する。
 *
 * @param array  $arguments prefix・Uploads・Suite・Run・モード・障壁.
 * @param string $url 本来のサイトURL.
 * @return array プロセスと出力.
 */
function odvr_retention_spawn( $arguments, $url ) {
	$command = 'wp eval-file ' . escapeshellarg( __DIR__ . '/retention-worker.php' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) ) . ' --path=' . escapeshellarg( ABSPATH ) . ' --url=' . escapeshellarg( $url );
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	fclose( $pipes[0] );
	return array(
		'process' => $process,
		'pipes'   => $pipes,
	);
}

/**
 * 出力を確認してプロセスを回収する。
 *
 * @param array $worker プロセス.
 * @return array 結果.
 */
function odvr_retention_wait( $worker ) {
	$output = stream_get_contents( $worker['pipes'][1] );
	$error  = stream_get_contents( $worker['pipes'][2] );
	fclose( $worker['pipes'][1] );
	fclose( $worker['pipes'][2] );
	odvr_retention_check( 0 === proc_close( $worker['process'] ), '独立プロセスの終了: ' . $error );
	return json_decode( trim( $output ), true );
}

/**
 * 独立操作が障壁へ到達するまで待つ。
 *
 * @param array $markers 障壁ファイル.
 * @return void
 */
function odvr_retention_barrier( $markers ) {
	$deadline = microtime( true ) + 10;
	do {
		$ready = true;
		foreach ( $markers as $marker ) {
			$ready = $ready && file_exists( $marker );
		} if ( $ready ) {
			break;
		} usleep( 10000 );
		clearstatcache();
	} while ( microtime( true ) < $deadline );
	odvr_retention_check( $ready, '独立プロセスが操作前の障壁へ到達する' );
}

/**
 * Keep All・Last N・閉包・削除再開・Uninstall保持を検証する。
 *
 * @return void
 */
function odvr_test_retention() {
	global $wpdb;
	$original     = $wpdb;
	$original_url = get_site_url();
	$memory       = ini_get( 'memory_limit' );
	$prefix       = $wpdb->prefix . 'odvrretain_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) . '_';
	$directory    = sys_get_temp_dir() . '/odvr-retention-' . wp_generate_uuid4();
	mkdir( $directory, 0700 );
	$filter = function ( $uploads ) use ( $directory ) {
		$uploads['basedir'] = $directory;
		$uploads['baseurl'] = 'https://fixture.test/uploads';
		return $uploads;
	};
	add_filter( 'upload_dir', $filter );
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->set_prefix( $prefix );
	$wpdb->set_blog_id( get_current_blog_id() );
	$wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $wpdb->options, $original->options ) );
	wp_cache_flush();
	try {
		ini_set( 'memory_limit', '1024M' );
		update_option( 'home', 'https://fixture.test' );
		update_option( 'siteurl', 'https://fixture.test' );
		odvr_retention_check( ODVR_DB::upgrade(), '隔離した5テーブルを導入する' );
		$fixtures = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.json' ) );
		foreach ( $fixtures as $fixture ) {
			if ( 'suite-create-request' === $fixture->schema && $fixture->valid ) {
				$input = clone $fixture->value;
				break; }
		}
		$input->device_ids = array( 1 );
		$input->retention  = (object) array(
			'mode'  => 'last',
			'count' => 10,
		);
		$suites            = new ODVR_Suite_Repository();
		$suite             = odvr_retention_check( $suites->create( $input, 1 ), 'Retention試験Suiteを作成する' );
		$suite_id          = $suite['id'];
		$runs              = array();
		for ( $index = 0; $index < 25; ++$index ) {
			$runs[] = odvr_retention_run( $suite_id, $index ); }
		$retention = new ODVR_Retention();
		odvr_retention_check( 15 === count( $retention->plan( $suite_id )['candidates'] ), 'Last 10は最新10終端を残す' );
		$saved_environment                        = $wpdb->get_var( $wpdb->prepare( 'SELECT environment FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $runs[0]['id'] ) );
		$unknown_environment                      = json_decode( $saved_environment );
		$unknown_environment->environment_version = 2;
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'environment' => wp_json_encode( $unknown_environment ) ), array( 'id' => $runs[0]['id'] ) );
		odvr_retention_check( is_wp_error( $retention->request( $suite_id, array(), true ) ), '未知の保存Versionを自動削除しない' );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'environment' => $saved_environment ), array( 'id' => $runs[0]['id'] ) );
		odvr_retention_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'retention'      => (object) array(
						'mode'  => 'last',
						'count' => 20,
					),
				)
			),
			'Last 20を選択する'
		);
		odvr_retention_check( 5 === count( $retention->plan( $suite_id )['candidates'] ), 'Last 20は最新20終端を残す' );
		odvr_retention_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'retention'      => (object) array(
						'mode'  => 'all',
						'count' => null,
					),
				)
			),
			'Keep Allを選択する'
		);
		odvr_retention_check( array() === $retention->plan( $suite_id )['candidates'], 'Keep Allは候補を作らない' );
		odvr_retention_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'retention'      => (object) array(
						'mode'  => 'last',
						'count' => 1,
					),
				)
			),
			'Custom 1を選択する'
		);
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => $runs[10]['id'] ), array( 'id' => $runs[24]['id'] ) );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => $runs[2]['id'] ), array( 'id' => $runs[10]['id'] ) );
		// Snapshot列の参照も、Manifestの破損に関係なく保守的に保護する.
		$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'baseline_snapshot_id' => $runs[1]['snapshot_id'] ), array( 'id' => $runs[2]['snapshot_id'] ) );
		$plan = odvr_retention_check( $retention->plan( $suite_id ), '保持Runからの参照閉包を求める' );
		odvr_retention_check( isset( $plan['protected'][ $runs[24]['id'] ], $plan['protected'][ $runs[10]['id'] ], $plan['protected'][ $runs[2]['id'] ], $plan['protected'][ $runs[1]['id'] ] ), 'RunとSnapshotを辿った参照先をすべて残す' );
		odvr_retention_check( is_wp_error( $retention->request( $suite_id, array( $runs[1]['id'] ) ) ), '保持Snapshotから参照されたRunを手動削除しない' );
		$active = odvr_retention_run( $suite_id, 26, 'queued' );
		odvr_retention_check( isset( $retention->plan( $suite_id )['protected'][ $active['id'] ] ) && in_array( $runs[24]['id'], array_keys( $retention->plan( $suite_id )['protected'] ), true ), '稼働Runは最新N件の枠に数えず保護する' );
		odvr_retention_check( is_wp_error( $retention->request( $suite_id, array( $active['id'] ) ) ), '稼働Runの削除を拒否する' );
		$complete = odvr_retention_run( $suite_id, 27, 'complete' );
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => $complete['id'] ), array( 'id' => $suite_id ) );
		odvr_retention_check( is_wp_error( $retention->request( $suite_id, array( $complete['id'] ) ) ), 'Pinnedの手動削除を拒否する' );
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => null ), array( 'id' => $suite_id ) );
		odvr_retention_check( $retention->request( $suite_id, array( $complete['id'] ) ), '明示操作では最新completeの削除を許可する' );
		odvr_retention_check( $retention->resume( $complete['id'] ), '画像→Snapshot→Runの順に完了する' );
		$target = $runs[0];
		$root   = $directory . '/od-visual-regression';
		// Storageが自ら作るroot/lockを用意する。公開診断はこの試験では不要.
		odvr_retention_check(
			( new ODVR_Storage() )->with_run_lock(
				$target['uuid'],
				true,
				function () {
					return true;
				}
			),
			'固定Run lockを準備する'
		);
		$run_dir = $root . '/suite-' . $suite['uuid'] . '/run-' . $target['uuid'];
		mkdir( $run_dir, 0700, true );
		file_put_contents( $run_dir . '/a.png', 'fixture-a' );
		file_put_contents( $run_dir . '/b.png', 'fixture-b' );
		odvr_retention_check( $retention->request( $suite_id, array( $target['id'] ) ), 'ファイル削除前にdeletingを確定する' );
		$before  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $target['id'] ), ARRAY_A );
		$calls   = 0;
		$failure = function ( $path ) use ( $run_dir, &$calls ) {
			if ( 0 === strpos( $path, $run_dir . '/' ) && ++$calls > 1 ) {
				return '';
			} return $path;
		};
		add_filter( 'wp_delete_file', $failure );
		try {
			odvr_retention_check( is_wp_error( $retention->resume( $target['id'] ) ), '途中の画像削除失敗を返す' );
		} finally {
			remove_filter( 'wp_delete_file', $failure ); }
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $target['id'] ), ARRAY_A );
		odvr_retention_check( 'deleting' === $after['status'] && null === $after['runner_token_hash'] && $before['deletion_requested_at'] === $after['deletion_requested_at'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d', ODVR_DB::table( 'snapshots' ), $target['id'] ) ), '失敗後も削除中の履歴・Snapshotと失効済みTokenを保持する' );
		odvr_retention_check( is_string( get_option( 'odvr_deletion_error_' . $target['id'] ) ), 'パスや例外を含まない定型診断を保存する' );
		odvr_retention_check( $retention->resume( $target['id'] ), 'ファイル不存在を許容して途中から再開する' );
		odvr_retention_check( ! is_dir( $run_dir ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $target['id'] ) ), '再開成功後に画像とDBがなくなる' );
		odvr_retention_check( $retention->resume( $target['id'] ), '完了済み削除の再送は冪等に成功する' );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => $runs[3]['id'] ), array( 'id' => $runs[4]['id'] ) );
		odvr_retention_check( is_wp_error( $retention->request( $suite_id, array( $runs[3]['id'] ) ) ), '参照先だけの削除を拒否する' );
		odvr_retention_check( $retention->request( $suite_id, array( $runs[3]['id'], $runs[4]['id'] ) ), '閉じた削除集合を一度に確定する' );
		odvr_retention_check( is_wp_error( $retention->resume( $runs[3]['id'] ) ), '参照先を先に物理削除しない' );
		odvr_retention_check( $retention->resume( $runs[4]['id'] ), '参照元を先に削除する' );
		odvr_retention_check( $retention->resume( $runs[3]['id'] ), 'その後で参照先を削除する' );
		$race    = odvr_retention_run( $suite_id, 30, 'complete' );
		$gate    = $directory . '/race-gate';
		$workers = array();
		foreach ( array( 'request', 'promote' ) as $mode ) {
			$workers[] = odvr_retention_spawn( array( $wpdb->base_prefix, $directory, (string) $suite_id, (string) $race['id'], $mode, $gate, $gate . '-' . $mode ), $original_url ); }
		odvr_retention_barrier( array( $gate . '-request', $gate . '-promote' ) );
		file_put_contents( $gate, 'go' );
		$outcomes = array_map( 'odvr_retention_wait', $workers );
		odvr_retention_check(
			1 === count(
				array_filter(
					$outcomes,
					function ( $result ) {
						return isset( $result['success'] );
					}
				)
			) && 1 === count(
				array_filter(
					$outcomes,
					function ( $result ) {
						return isset( $result['error'] );
					}
				)
			),
			'同時昇格と削除は片方だけ成立する: ' . wp_json_encode( $outcomes )
		);
		$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => null ), array( 'id' => $suite_id ) );
		$snapshot    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'snapshots' ), $race['snapshot_id'] ), ARRAY_A );
		$metadata    = json_decode( $snapshot['metadata'] );
		$storage     = new ODVR_Storage();
		$read_worker = null;
		$read_gate   = $directory . '/read-gate';
		odvr_retention_check(
			$storage->with_run_lock(
				$race['uuid'],
				false,
				function () use ( $retention, $suite_id, $race, $storage, $suite, $snapshot, $metadata, $directory, $original_url, $read_gate, &$read_worker ) {
					global $wpdb;
					odvr_retention_check( $retention->request( $suite_id, array( $race['id'] ) ), '共有読取lockを保持したままdeletingを確定する' );
					$read_worker = odvr_retention_spawn( array( $wpdb->base_prefix, $directory, (string) $suite_id, (string) $race['id'], 'resume', $read_gate, $read_gate . '-ready' ), $original_url );
					odvr_retention_barrier( array( $read_gate . '-ready' ) );
					file_put_contents( $read_gate, 'go' );
					usleep( 150000 );
					odvr_retention_check( proc_get_status( $read_worker['process'] )['running'], '共有読取が終わるまで独立削除は完了しない' );
					odvr_retention_check( is_string( $storage->read_png( $snapshot['image_path'], $suite['uuid'], $race['uuid'], 1, 1, 1, $metadata->image_sha256 ) ), '先に許可された読取はdeleting後も同じPNGを完読できる' );
					return true;
				}
			),
			'画像読取と削除の競合を扱う'
		);
		odvr_retention_check( isset( odvr_retention_wait( $read_worker )['success'] ), '共有lock解放後に削除が完了する' );
		$cron = odvr_retention_check( $retention->cycle(), 'Cronと同じ処理で候補確定と削除再開を巡回する' );
		odvr_retention_check( array() === $retention->plan( $suite_id )['candidates'], 'Cron処理後も保持閉包と稼働Runだけが残る' );
		$uninstaller = new ODVR_Uninstaller();
		odvr_retention_check( $uninstaller->current_site(), 'Uninstall既定は削除せず停止する' );
		odvr_retention_check( is_wp_error( ODVR_DB::writable() ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE runner_token_hash IS NOT NULL', ODVR_DB::table( 'runs' ) ) ), '停止後は全Token失効と書込拒否を確定する' );
		odvr_retention_check( is_dir( $root ) && count( $retention->plan( $suite_id )['runs'] ) > 0, '既定では画像とDB履歴を保持する' );
		update_option( 'odvr_delete_data_on_uninstall', true, false );
		odvr_retention_check( is_wp_error( $uninstaller->current_site() ), '明示削除でも稼働Runがある場合は保持する' );
		$wpdb->update(
			ODVR_DB::table( 'runs' ),
			array(
				'status'       => 'failed',
				'completed_at' => ODVR_DB::utc_now(),
			),
			array( 'id' => $active['id'] )
		);

		// 明示Uninstall途中の失敗でも、DBと削除選択を失わない.
		$block = $root . '/blocked-fixture.txt';
		file_put_contents( $block, 'fixture-only' );
		$purge_failure = function ( $path ) use ( $block ) {
			return $block === $path ? '' : $path;
		};
		add_filter( 'wp_delete_file', $purge_failure );
		try {
			odvr_retention_check( is_wp_error( $uninstaller->current_site() ), 'Uninstallの画像削除失敗は成功にしない' );
		} finally {
			remove_filter( 'wp_delete_file', $purge_failure ); }
		odvr_retention_check( file_exists( $block ) && 1 === (int) get_option( 'odvr_db_version' ) && in_array( get_option( 'odvr_delete_data_on_uninstall' ), array( true, '1' ), true ) && get_option( 'odvr_deleting_site' ), 'Uninstall失敗後はDB・選択・削除中の状態を保持する' );
		wp_cache_delete( 'odvr_delete_data_on_uninstall', 'options' );
		odvr_retention_check( '1' === get_option( 'odvr_delete_data_on_uninstall' ), '別要求で文字列として読んだ選択も明示削除として扱う' );
		odvr_retention_check( $uninstaller->current_site(), '事前選択と稼働Runなしの場合だけ明示削除する' );
		odvr_retention_check( ! is_dir( $root ) && false === get_option( 'odvr_db_version', false ), '画像回収後に5テーブル・ODVR Optionsを回収する' );
	} finally {
		foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', ODVR_DB::table( $suffix ) ) ); }
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->options ) );
		$wpdb = $original;
		wp_cache_flush();
		wp_roles()->for_site( get_current_blog_id() );
		remove_filter( 'upload_dir', $filter );
		ini_set( 'memory_limit', $memory );
		if ( is_dir( $directory ) ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getPathname() );
				} else {
					unlink( $file->getPathname() );
				}
			} rmdir( $directory ); }
	}
}
odvr_test_retention();
WP_CLI::success( 'Retention・参照閉包・削除再開・停止の検証が完了しました。' );
