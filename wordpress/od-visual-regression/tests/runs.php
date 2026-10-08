<?php
/**
 * Run履歴・Baseline・状態遷移の実DB/Apache試験。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; }
// 試験用のSuiteと画像だけを作り、finallyで回収する.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_terminate, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.IniSet.memory_limit_Disallowed, WordPress.WP.GlobalVariablesOverride

/**
 * 正常値と試験条件を確認する。
 *
 * @param mixed  $value 結果.
 * @param string $message 内容.
 * @return mixed 正常値.
 * @throws RuntimeException 失敗時.
 */
function odvr_run_check( $value, $message ) {
	if ( is_wp_error( $value ) || false === $value ) {
		throw new RuntimeException( esc_html( $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() . ' ' . wp_json_encode( $value->get_error_data() ) : '' ) ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
	return $value;
}

/**
 * 固定1画素PNGを生成する。
 *
 * @return string PNG.
 */
function odvr_run_png() {
	$chunk = function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . hash( 'crc32b', $type . $data, true );
	};
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( "\x00\xff\x00\x00\xff" ) ) . $chunk( 'IEND', '' );
}

/**
 * 後続Uploadに依存せず、確定撮影結果を実保存する試験fixture。
 *
 * @param array    $run 作成結果.
 * @param int      $suite_id Suite ID.
 * @param int|null $limit 保存する件数.
 * @return void
 */
function odvr_run_capture_fixture( $run, $suite_id, $limit = null ) {
	global $wpdb;
	$storage   = new ODVR_Storage();
	$png       = odvr_run_png();
	$suite     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'suites' ), $suite_id ), ARRAY_A );
	$snapshots = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id', ODVR_DB::table( 'snapshots' ), $run['id'] ), ARRAY_A );
	if ( null !== $limit ) {
		$snapshots = array_slice( $snapshots, 0, $limit );
	}
	foreach ( $snapshots as $snapshot ) {
		$metadata = json_decode( $snapshot['metadata'] );
		$result   = (object) array(
			'schema_version'     => 1,
			'target_id'          => (int) $snapshot['target_id'],
			'device_id'          => (int) $snapshot['device_id'],
			'status'             => 'CAPTURED',
			'width'              => 1,
			'height'             => 1,
			'baseline_width'     => null,
			'baseline_height'    => null,
			'dimension_changed'  => false,
			'diff_pixels'        => null,
			'total_pixels'       => null,
			'diff_ratio'         => null,
			'http_status'        => 200,
			'duration_ms'        => 1,
			'error_code'         => null,
			'error_message'      => null,
			'no_baseline_reason' => null,
		);
		odvr_run_check( ( new ODVR_Contract_Validator() )->validate( 'snapshot-result', $result ), 'fixtureの撮影結果を契約検証する' );
		$digest                  = ODVR_Environment::digest( $result );
		$ticket                  = odvr_run_check( $storage->stage( $run['item']->run_uuid, $png, 1, 1 ), 'fixture PNGをstagingへ保存する' );
		$path                    = odvr_run_check(
			$storage->with_run_lock(
				$run['item']->run_uuid,
				true,
				function () use ( $storage, $ticket, $suite, $metadata, $digest ) {
					return $storage->promote( $ticket, $suite['uuid'], $metadata->target->id, $metadata->device->slug, $digest );
				}
			),
			'fixture PNGをRun内へ確定する'
		);
		$metadata->image_sha256  = hash( 'sha256', $png );
		$metadata->result        = $result;
		$metadata->result_digest = $digest;
		$wpdb->update(
			ODVR_DB::table( 'snapshots' ),
			array(
				'status'      => 'CAPTURED',
				'image_path'  => $path,
				'width'       => 1,
				'height'      => 1,
				'duration_ms' => 1,
				'http_status' => 200,
				'metadata'    => wp_json_encode( $metadata ),
			),
			array( 'id' => (int) $snapshot['id'] )
		);
	}
}

/**
 * 独立WordPressプロセスを同時に開始する。
 *
 * @param int $suite_id 試験Suite ID.
 * @return array 2要求の結果.
 */
function odvr_run_race( $suite_id ) {
	$base      = sys_get_temp_dir() . '/odvr-run-race-' . wp_generate_uuid4();
	$processes = array();
	$pipes     = array();
	try {
		for ( $index = 0; $index < 2; ++$index ) {
			$command             = 'wp eval-file ' . escapeshellarg( __DIR__ . '/runs-worker.php' ) . ' ' . escapeshellarg( (string) $suite_id ) . ' ' . escapeshellarg( $base ) . ' ' . escapeshellarg( $base . '-' . $index ) . ' --path=' . escapeshellarg( ABSPATH ) . ' --url=' . escapeshellarg( get_site_url() );
			$processes[ $index ] = proc_open(
				$command,
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes[ $index ]
			);
			fclose( $pipes[ $index ][0] );
		}
		$deadline = microtime( true ) + 10;
		while ( ( ! file_exists( $base . '-0' ) || ! file_exists( $base . '-1' ) ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
			clearstatcache(); }
		odvr_run_check( file_exists( $base . '-0' ) && file_exists( $base . '-1' ), '独立2プロセスが同時開始の障壁へ到達する' );
		file_put_contents( $base, 'go' );
		$results = array();
		foreach ( $processes as $index => $process ) {
			$output = stream_get_contents( $pipes[ $index ][1] );
			$errors = stream_get_contents( $pipes[ $index ][2] );
			fclose( $pipes[ $index ][1] );
			fclose( $pipes[ $index ][2] );
			odvr_run_check( 0 === proc_close( $process ), '独立プロセスの終了: ' . $errors );
			unset( $processes[ $index ] );
			$results[] = json_decode( trim( $output ), true );
		}
		return $results;
	} finally {
		foreach ( $processes as $process ) {
			proc_terminate( $process );
			proc_close( $process ); }
		foreach ( array( $base, $base . '-0', $base . '-1' ) as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path ); }
		}
	}
}

/**
 * 実環境の履歴を検証する。
 *
 * @return void
 */
function odvr_test_runs() {
	global $wpdb;
	$memory          = ini_get( 'memory_limit' );
	$ready           = get_option( 'odvr_storage_ready', null );
	$suite_id        = null;
	$extra_suite_id  = null;
	$runs            = array();
	$created_devices = array();
	$filter          = function ( $uploads ) {
		$uploads['baseurl'] = ( is_multisite() ? 'http://tests-wordpress/' : 'http://wordpress/' ) . substr( $uploads['basedir'], strlen( ABSPATH ) );
		return $uploads;
	};
	add_filter( 'upload_dir', $filter );
	if ( ! defined( 'ODVR_STORAGE_PROTECTION_VERIFIED' ) ) {
		define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true ); }
	try {
		ini_set( 'memory_limit', '1024M' );
		$ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE enabled = 1 ORDER BY id LIMIT 2', ODVR_DB::table( 'devices' ) ) ) );
		$devices = new ODVR_Device_Repository();
		$own_ids = array();
		foreach ( $ids as $id ) {
			$device = odvr_run_check( $devices->get( $id ), '試験用Profileを読む' );
			unset( $device['id'], $device['using_suites'] );
			$device['schema_version'] = 1;
			$device['slug']           = 'fixture-' . substr( wp_generate_uuid4(), 0, 8 );
			$created                  = odvr_run_check( $devices->create( (object) $device ), '試験専用Deviceを作成する' );
			$created_devices[]        = $created['id'];
			$own_ids[]                = $created['id'];
		}
		$ids      = $own_ids;
		$input    = (object) array(
			'schema_version'  => 1,
			'name'            => 'Run試験-' . wp_generate_uuid4(),
			'settings'        => (object) array(
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
			'retention'       => (object) array(
				'mode'  => 'last',
				'count' => 10,
			),
		);
		$suites   = new ODVR_Suite_Repository();
		$suite    = odvr_run_check( $suites->create( $input, 1 ), '試験Suiteを作成する' );
		$suite_id = $suite['id'];
		$targets  = new ODVR_Target_Repository();
		foreach ( array( 'a', 'b' ) as $name ) {
			odvr_run_check(
				$targets->create(
					$suite_id,
					(object) array(
						'schema_version' => 1,
						'url'            => 'https://fixture.test/' . $name . '?v=1',
						'label'          => $name,
						'object_id'      => null,
						'post_type'      => '',
						'enabled'        => true,
						'sort_order'     => 0,
					)
				),
				'撮影対象を保存する'
			);
		}
		$manager = new ODVR_Run_Manager();
		$request = (object) array(
			'schema_version' => 1,
			'baseline_mode'  => 'pinned',
		);
		$run     = odvr_run_check( $manager->create( $suite_id, $request, 1 ), 'Runと全pendingを作成する' );
		$runs[]  = $run;
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run['id'] ), ARRAY_A );
		odvr_run_check( 4 === (int) $row['total_snapshots'] && hash( 'sha256', $run['runner_token'] ) === $row['runner_token_hash'] && false === strpos( $row['manifest'] . $row['environment'], $run['runner_token'] ), '全4組とTokenのhashのみを保存する' );
		odvr_run_check( is_wp_error( $manager->create( $suite_id, $request, 1 ) ), '同Suiteの稼働Runを拒否する' );
		$manifest = odvr_run_check( $manager->start( $run['item']->run_uuid, 'fixture-execution' ), 'queuedをrunningへ一度だけ遷移する' );
		odvr_run_check( is_wp_error( $manager->start( $run['item']->run_uuid, 'other-execution' ) ), '別Executionを拒否する' );
		$versions = (object) array(
			'runner'     => '0.1.0',
			'playwright' => '1.64.0',
			'chromium'   => '150',
		);
		$progress = (object) array(
			'schema_version'      => 1,
			'runner_execution_id' => 'fixture-execution',
			'versions'            => $versions,
		);
		odvr_run_check( $manager->progress( $run['item']->run_uuid, $progress ), 'Versionを初回だけ補完する' );
		$wrong                     = clone $progress;
		$wrong->versions           = clone $versions;
		$wrong->versions->chromium = 'other';
		odvr_run_check( is_wp_error( $manager->progress( $run['item']->run_uuid, $wrong ) ), '異なるVersionへの書換を拒否する' );
		odvr_run_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'name'           => '後の名前',
				)
			),
			'Run開始後にSuite名を編集する'
		);
		$first_target = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d ORDER BY id LIMIT 1', ODVR_DB::table( 'targets' ), $suite_id ), ARRAY_A );
		odvr_run_check(
			$targets->update(
				$suite_id,
				(int) $first_target['id'],
				(object) array(
					'schema_version' => 1,
					'label'          => '変更後ラベル',
				)
			),
			'Targetを編集する'
		);
		odvr_run_check(
			$devices->update(
				$ids[0],
				(object) array(
					'schema_version' => 1,
					'name'           => '変更後Device名',
				)
			),
			'Deviceを編集する'
		);
		$changed_settings                  = clone $input->settings;
		$changed_settings->pixel_threshold = 0.3;
		odvr_run_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'settings'       => $changed_settings,
				)
			),
			'判定閾値を編集する'
		);
		$history = odvr_run_check( $manager->get( $run['id'] ), '固定履歴を取得する' );
		odvr_run_check( $history['suite_name'] === $manifest->suite->name && '後の名前' !== $history['suite_name'], '現在のSuite名が履歴へ波及しない' );
		$complete = (object) array(
			'schema_version'      => 1,
			'runner_execution_id' => 'fixture-execution',
			'versions'            => $versions,
			'outcome'             => 'finished',
			'error_code'          => null,
			'error_message'       => null,
		);
		odvr_run_check( $history['targets'][0]->label === $manifest->targets[0]->label && $history['devices'][0]->name === $manifest->devices[0]->name && $history['settings']->pixel_threshold === $manifest->settings->pixel_threshold, 'Target・Device・設定の編集が履歴へ波及しない' );
		$before_environment            = json_decode( $row['environment'] );
		$after_environment             = clone $history['environment'];
		$after_environment->runner     = null;
		$after_environment->playwright = null;
		$after_environment->chromium   = null;
		unset( $before_environment->completion );
		odvr_run_check( ODVR_Environment::digest( $before_environment ) === ODVR_Environment::digest( $after_environment ), '3Version以外のEnvironmentを変更しない' );
		odvr_run_check( is_wp_error( $manager->finish( $run['item']->run_uuid, $complete ) ), 'pendingがある完了を拒否する' );
		odvr_run_capture_fixture( $run, $suite_id );
		$finished = odvr_run_check( $manager->finish( $run['item']->run_uuid, $complete ), '全結果のあるRunをcompleteにする' );
		odvr_run_check( 'complete' === $finished->status && 4 === $finished->completed_snapshots, '確定結果の件数から完了状態を計算する' );
		odvr_run_check( ODVR_Environment::digest( $manager->finish( $run['item']->run_uuid, $complete ) ) === ODVR_Environment::digest( $finished ), '同じ完了再送は保存した応答を返す' );
		odvr_run_check( is_wp_error( $manager->start( $run['item']->run_uuid, 'fixture-execution' ) ), '終端Runを再開しない' );
		odvr_run_check( $manager->promote_baseline( $suite_id, $run['id'] ), '全PNG可読のcompleteをPinnedへ昇格する' );
		foreach ( array( 'pinned', 'previous', 'specific' ) as $mode ) {
			$next_request = (object) array(
				'schema_version' => 1,
				'baseline_mode'  => $mode,
			);
			if ( 'specific' === $mode ) {
				$next_request->reference_run_id = $run['id']; }
			$next   = odvr_run_check( $manager->create( $suite_id, $next_request, 1 ), 'Baseline ' . $mode . ' を選択する' );
			$runs[] = $next;
			$wire   = odvr_run_check( $manager->start( $next['item']->run_uuid, 'fixture-execution' ), '固定参照をManifestで取得する' );
			odvr_run_check(
				$wire->reference->run_id === $run['id'] && count(
					array_filter(
						$wire->reference->snapshots,
						function ( $ref ) {
							return null !== $ref->baseline_snapshot_id;
						}
					)
				) === 4,
				'全4参照を開始時に固定する'
			);
			odvr_run_check( is_wp_error( $manager->request_delete( $run['id'] ) ), 'Pinnedかつ参照されているRunの削除を拒否する' );
			odvr_run_check( $manager->fail_run( $next['item']->run_uuid ), '稼働Runのpendingを定型ERRORへ確定する' );
			odvr_run_check( $manager->request_delete( $next['id'] ), '終端Runをdeletingへ遷移する' );
		}

		$extra_suite    = odvr_run_check( $suites->create( $input, 1 ), '別Suiteの昇格検査を用意する' );
		$extra_suite_id = $extra_suite['id'];
		$cross          = $manager->promote_baseline( $extra_suite_id, $run['id'] );
		odvr_run_check( is_wp_error( $cross ) && 'odvr_invalid_reference' === $cross->get_error_code(), '別SuiteのcompleteをBaselineへ昇格しない' );
		$source_snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id LIMIT 1', ODVR_DB::table( 'snapshots' ), $run['id'] ), ARRAY_A );
		$path            = wp_upload_dir()['basedir'] . '/od-visual-regression/' . $source_snapshot['image_path'];
		$original        = file_get_contents( $path );
		foreach ( array( 'missing', 'corrupt' ) as $reason ) {
			try {
				if ( 'missing' === $reason ) {
					unlink( $path );
				} else {
					file_put_contents( $path, 'invalid PNG' ); }
				odvr_run_check( is_wp_error( $manager->promote_baseline( $suite_id, $run['id'] ) ), '不備のある画像の昇格を拒否する' );
				$missing = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '画像不備のRunを作成する' );
				$runs[]  = $missing;
				$wire    = odvr_run_check( $manager->start( $missing['item']->run_uuid, 'fixture-execution' ), '画像不備の固定参照を取得する' );
				odvr_run_check( $wire->reference->snapshots[0]->reason === $reason && null === $wire->reference->snapshots[0]->baseline_snapshot_id, '該当Snapshotだけ ' . $reason . ' として固定する' );
				odvr_run_check( $manager->fail_run( $missing['item']->run_uuid ), '画像不備の試験Runを終了する' );
			} finally {
				file_put_contents( $path, $original );
				chmod( $path, 0600 ); }
		}
		$mixed  = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '部分結果Runを作成する' );
		$runs[] = $mixed;
		odvr_run_check( $manager->start( $mixed['item']->run_uuid, 'fixture-execution' ), '部分結果Runを開始する' );
		odvr_run_capture_fixture( $mixed, $suite_id, 1 );
		$failed                = clone $complete;
		$failed->outcome       = 'failed';
		$failed->error_code    = 'RUN_ABORTED';
		$failed->error_message = ODVR_Run_Manager::error_message( 'RUN_ABORTED' );
		$bad                   = clone $failed;
		$bad->error_message    = 'secret-exception';
		odvr_run_check( is_wp_error( $manager->finish( $mixed['item']->run_uuid, $bad ) ), '任意の例外本文を保存しない' );
		$fatal = odvr_run_check( $manager->finish( $mixed['item']->run_uuid, $failed ), '致命的失敗でpendingだけERRORにする' );
		odvr_run_check( 'failed' === $fatal->status && 1 === $fatal->completed_snapshots && 3 === $fatal->error_snapshots, '成功結果があっても致命的失敗はfailedにする' );
		odvr_run_check( is_wp_error( $manager->finish( $mixed['item']->run_uuid, $complete ) ), '異なる完了再送を拒否する' );
		// ERROR確定はUploadの別Issueに依存せずDB fixtureとして用意する.
		$partial = odvr_run_check( $manager->create( $suite_id, $request, 1 ), 'partial判定のRunを作成する' );
		$runs[]  = $partial;
		odvr_run_check( $manager->start( $partial['item']->run_uuid, 'fixture-execution' ), 'partial判定のRunを開始する' );
		odvr_run_capture_fixture( $partial, $suite_id );
		$error_snapshot        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', ODVR_DB::table( 'snapshots' ), $partial['id'] ), ARRAY_A );
		$metadata              = json_decode( $error_snapshot['metadata'] );
		$result                = clone $metadata->result;
		$result->status        = 'ERROR';
		$result->width         = null;
		$result->height        = null;
		$result->http_status   = null;
		$result->duration_ms   = 0;
		$result->error_code    = 'CAPTURE_FAILED';
		$result->error_message = '撮影に失敗しました。';
		odvr_run_check( ( new ODVR_Contract_Validator() )->validate( 'snapshot-result', $result ), 'ERROR fixtureも契約検証する' );
		$metadata->result        = $result;
		$metadata->result_digest = ODVR_Environment::digest( $result );
		$metadata->image_sha256  = null;
		$wpdb->update(
			ODVR_DB::table( 'snapshots' ),
			array(
				'status'        => 'ERROR',
				'image_path'    => null,
				'width'         => null,
				'height'        => null,
				'http_status'   => null,
				'duration_ms'   => 0,
				'error_code'    => $result->error_code,
				'error_message' => $result->error_message,
				'metadata'      => wp_json_encode( $metadata ),
			),
			array( 'id' => (int) $error_snapshot['id'] )
		);
		$partial_state = odvr_run_check( $manager->finish( $partial['item']->run_uuid, $complete ), '正常結果とERRORが混在するとpartialにする' );
		odvr_run_check( 'partial' === $partial_state->status && 3 === $partial_state->completed_snapshots && 1 === $partial_state->error_snapshots, 'partial件数を確定する' );
		odvr_run_check( is_wp_error( $manager->promote_baseline( $suite_id, $partial['id'] ) ), 'partialのBaseline昇格を拒否する' );
		$incompatible_settings            = clone $changed_settings;
		$incompatible_settings->lazy_load = false;
		odvr_run_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'settings'       => $incompatible_settings,
				)
			),
			'撮影条件を変更する'
		);
		$incompatible      = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '非互換のRunを作成する' );
		$runs[]            = $incompatible;
		$incompatible_wire = odvr_run_check( $manager->start( $incompatible['item']->run_uuid, 'fixture-execution' ), '非互換Manifestを取得する' );
		odvr_run_check(
			4 === count(
				array_filter(
					$incompatible_wire->reference->snapshots,
					function ( $reference ) {
						return 'incompatible' === $reference->reason && null === $reference->baseline_snapshot_id;
					}
				)
			),
			'撮影条件変更は全参照を非互換として固定する'
		);
		odvr_run_check( $manager->fail_run( $incompatible['item']->run_uuid ), '非互換Runを終了する' );
		odvr_run_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'settings'       => $changed_settings,
				)
			),
			'撮影条件を戻す'
		);
		$version_run  = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '利用時Version検査のRunを作成する' );
		$runs[]       = $version_run;
		$version_wire = odvr_run_check( $manager->start( $version_run['item']->run_uuid, 'fixture-execution' ), '利用時Version検査を開始する' );
		$target_id    = $version_wire->targets[0]->id;
		$device_id    = $version_wire->devices[0]->id;
		$unknown      = odvr_run_check( $manager->baseline_reference( $version_run['item']->run_uuid, $target_id, $device_id ), 'Version未報告時はBaselineを利用しない' );
		odvr_run_check( 'incompatible' === $unknown->reason, '未報告Versionは非互換となる' );
		odvr_run_check( $manager->progress( $version_run['item']->run_uuid, $wrong ), '参照元と異なる実Versionを今回の環境へ固定する' );
		$blocked = odvr_run_check( $manager->baseline_reference( $version_run['item']->run_uuid, $target_id, $device_id ), '異なるVersionのBaselineを利用しない' );
		odvr_run_check( 'incompatible' === $blocked->reason && null !== $version_wire->reference->snapshots[0]->baseline_snapshot_id, '利用時の非互換でも開始時の参照IDを変更しない' );
		odvr_run_check( $manager->fail_run( $version_run['item']->run_uuid ), 'Version試験Runを終了する' );
		$race    = odvr_run_race( $suite_id );
		$winners = array_values(
			array_filter(
				$race,
				function ( $result ) {
					return isset( $result['id'] );
				}
			)
		);
		$losers  = array_values(
			array_filter(
				$race,
				function ( $result ) {
					return isset( $result['error'] );
				}
			)
		);
		// 異常時も作成済み行をfinallyで回収する.
		foreach ( $winners as $winner ) {
			$runs[] = array(
				'id'   => $winner['id'],
				'item' => (object) array( 'run_uuid' => $winner['uuid'] ),
			); }
		odvr_run_check( 1 === count( $winners ) && 1 === count( $losers ) && 'odvr_suite_busy' === $losers[0]['error'], '同時開始は1件だけ作成し、もう1件を409相当で拒否する: ' . wp_json_encode( $race ) );
		odvr_run_check( $manager->fail_run( $winners[0]['uuid'] ), '同時開始の試験Runを終了する' );
		$deadline = odvr_run_check(
			$manager->create(
				$suite_id,
				$request,
				1,
				array(
					'queued_timeout_seconds' => 1,
					'run_timeout_seconds'    => 2,
				)
			),
			'短い期限のRunを作成する'
		);
		$runs[]   = $deadline;
		$expired  = odvr_run_check( $manager->expire( $deadline['id'], strtotime( $deadline['item']->deadline_at ) - 1 ), 'queued期限を判定する' );
		odvr_run_check( 'failed' === $expired->status && 'DISPATCH_TIMEOUT' === $expired->error_code && 0 === $expired->pending_snapshots && 4 === $expired->error_snapshots, '期限後は全pendingがERRORになり終端を再開しない' );

		$running_deadline = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '実行期限のRunを作成する' );
		$runs[]           = $running_deadline;
		odvr_run_check( $manager->start( $running_deadline['item']->run_uuid, 'fixture-execution' ), '実行期限のRunを開始する' );
		odvr_run_capture_fixture( $running_deadline, $suite_id, 1 );
		$at_deadline    = strtotime( $running_deadline['item']->deadline_at );
		$deadline_state = odvr_run_check( $manager->expire( $running_deadline['id'], $at_deadline ), '実行期限に終端へ移る' );
		odvr_run_check( 'RUN_DEADLINE_EXCEEDED' === $deadline_state->error_code && 1 === $deadline_state->completed_snapshots && 3 === $deadline_state->error_snapshots && 0 === $deadline_state->pending_snapshots, '実行期限は成功結果を保持してpendingだけERRORにする' );
		odvr_run_check( is_wp_error( $manager->bind_execution( $running_deadline['item']->run_uuid, 'fixture-execution' ) ), '期限後のExecution再設定を拒否する' );
		$device_input = odvr_run_check( $devices->get( $ids[0] ), '上限試験のDevice Profileを読む' );
		unset( $device_input['id'], $device_input['using_suites'] );
		$device_input['schema_version'] = 1;
		for ( $index = 0; $index < 8; ++$index ) {
			$device_input['slug'] = 'fixture-' . substr( wp_generate_uuid4(), 0, 8 );
			$created              = odvr_run_check( $devices->create( (object) $device_input ), '上限試験のDeviceを追加する' );
			$created_devices[]    = $created['id'];
			$ids[]                = $created['id'];
		}
		for ( $index = 2; $index < 100; ++$index ) {
			odvr_run_check(
				$targets->create(
					$suite_id,
					(object) array(
						'schema_version' => 1,
						'url'            => 'https://fixture.test/limit-' . $index,
						'label'          => '上限試験',
						'object_id'      => null,
						'post_type'      => '',
						'enabled'        => true,
						'sort_order'     => $index,
					)
				),
				'上限試験のTargetを追加する'
			);
		}
		odvr_run_check(
			$suites->update(
				$suite_id,
				(object) array(
					'schema_version' => 1,
					'device_ids'     => $ids,
				)
			),
			'100Target×10Deviceを選択する'
		);
		$maximum      = odvr_run_check( $manager->create( $suite_id, $request, 1 ), '最大1000組のRunを原子的に作成する' );
		$runs[]       = $maximum;
		$maximum_item = odvr_run_check( $manager->get( $maximum['id'] ), '最大1000組の固定履歴を読む' );
		odvr_run_check( 1000 === $maximum_item['total_snapshots'] && 1000 === $maximum_item['pending_snapshots'], '最大1000組が欠落・重複なしにpendingとして存在する' );
		$maximum_failed = odvr_run_check( $manager->fail_run( $maximum['item']->run_uuid ), '最大1000組のpendingをERRORへ確定する' );
		odvr_run_check( 1000 === $maximum_failed->error_snapshots && 0 === $maximum_failed->pending_snapshots, '最大1000組の終端にもpendingを残さない' );
		odvr_run_check( $manager->list_items( $suite_id ), '一覧も契約通りの固定履歴を返す' );
	} finally {
		if ( null !== $suite_id ) {
			$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => null ), array( 'id' => $suite_id ) );
			foreach ( $runs as $run ) {
				$wpdb->update(
					ODVR_DB::table( 'runs' ),
					array(
						'status'            => 'deleting',
						'runner_token_hash' => null,
						'reference_run_id'  => null,
					),
					array( 'id' => $run['id'] )
				);
				$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'baseline_snapshot_id' => null ), array( 'run_id' => $run['id'] ) );
			}
			foreach ( $runs as $run ) {
				odvr_run_check( ( new ODVR_Storage() )->delete_run( $run['id'] ), '試験Run画像を回収する' );
				$wpdb->delete( ODVR_DB::table( 'snapshots' ), array( 'run_id' => $run['id'] ) );
				$wpdb->delete( ODVR_DB::table( 'runs' ), array( 'id' => $run['id'] ) );
			}
			$wpdb->delete( ODVR_DB::table( 'targets' ), array( 'suite_id' => $suite_id ) );
			$wpdb->delete( ODVR_DB::table( 'suites' ), array( 'id' => $suite_id ) );
		}
		remove_filter( 'upload_dir', $filter );
		if ( null !== $extra_suite_id ) {
			$wpdb->delete( ODVR_DB::table( 'suites' ), array( 'id' => $extra_suite_id ) ); }
		foreach ( $created_devices as $id ) {
			$wpdb->delete( ODVR_DB::table( 'devices' ), array( 'id' => $id ) );
		}
		if ( null === $ready ) {
			delete_option( 'odvr_storage_ready' );
		} else {
			update_option( 'odvr_storage_ready', $ready, false ); }
		ini_set( 'memory_limit', $memory );
	}
}
odvr_test_runs();
WP_CLI::success( 'RunとBaselineの検証が完了しました。' );
