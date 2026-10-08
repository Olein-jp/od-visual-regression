<?php
/**
 * Runnerの生multipart・再送・完了・固定参照を実WordPressで検証する。
 * admin-api.phpの隔離したSuite・私有保存先内で実行する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
// バイナリfixtureのJSON搬送だけにbase64を使用する.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_terminate
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput, WordPress.WP.GlobalVariablesOverride

/**
 * 同じ固定PNGの色だけを変える。
 *
 * @param int $red 赤成分.
 * @return string PNG.
 */
function odvr_runner_png( $red = 0 ) {
	$pixel = imagecreatetruecolor( 1, 1 );
	imagesetpixel( $pixel, 0, 0, imagecolorallocate( $pixel, $red, 0, 0 ) );
	ob_start();
	imagepng( $pixel );
	$data = ob_get_clean();
	imagedestroy( $pixel );
	return $data;
}
/**
 * Scalarを1回ずつ含む生multipartを生成する。
 *
 * @param stdClass $result 結果.
 * @param array    $files PNG.
 * @param string   $boundary 境界.
 * @return string 本文.
 */
function odvr_runner_body( $result, $files = array(), $boundary = 'odvr-fixture-boundary' ) {
	$body = '';
	foreach ( get_object_vars( $result ) as $name => $value ) {
		$scalar = is_string( $value ) ? $value : wp_json_encode( $value );
		$body  .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $scalar . "\r\n";
	}
	foreach ( $files as $name => $data ) {
		$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"; filename=\"ignored.png\"\r\nContent-Type: image/png\r\n\r\n" . $data . "\r\n";
	}
	return $body . '--' . $boundary . "--\r\n";
}
/**
 * 生multipartをRESTへ渡す。HTTPS判定だけはfixture。
 *
 * @param array  $run 作成結果.
 * @param string $body 本文.
 * @param string $execution Execution.
 * @return WP_REST_Response 応答.
 */
function odvr_runner_upload( $run, $body, $execution = 'fixture-execution' ) {
	wp_set_current_user( 0 );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	$_SERVER['HTTPS'] = 'on';
	$request          = new WP_REST_Request( 'POST', '/odvr/v1/runner/runs/' . $run['item']->run_uuid . '/snapshots' );
	$request->set_header( 'Authorization', 'Bearer ' . $run['runner_token'] );
	$request->set_header( 'X-ODVR-Execution-ID', $execution );
	$request->set_header( 'Content-Type', 'multipart/form-data; boundary=odvr-fixture-boundary' );
	$request->set_body( $body );
	$server   = rest_get_server();
	$response = $server->dispatch( $request );
	return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
}
/**
 * 実APIを通し結果の状態遷移と再送を検査する。
 *
 * @param int $admin 管理者.
 * @param int $suite_id 試験Suite.
 * @param int $target_id 対象.
 * @param int $device_id Device.
 * @return void
 */
function odvr_test_runner_api( $admin, $suite_id, $target_id, $device_id ) {
	global $wpdb;
	$manager = new ODVR_Run_Manager();
	$input   = (object) array(
		'schema_version' => 1,
		'baseline_mode'  => 'previous',
	);
	$run     = odvr_admin_check( $manager->create( $suite_id, $input, $admin ), 'Runner API用Runを作成する' );
	$uuid    = $run['item']->run_uuid;
	$headers = array(
		'Authorization'       => 'Bearer ' . $run['runner_token'],
		'X-ODVR-Execution-ID' => 'fixture-execution',
	);
	odvr_admin_check( 200 === odvr_admin_request( 0, 'GET', '/runner/runs/' . $uuid . '/manifest', null, array(), $headers )->get_status(), 'Runner APIのManifestで開始する' );
	$fixed                   = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT manifest FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run['id'] ) ) );
	$device_id               = $fixed->devices[0]->id;
	$result                  = odvr_admin_fixture( 'snapshot-result' );
	$result->target_id       = $target_id;
	$result->device_id       = $device_id;
	$result->status          = 'UNCHANGED';
	$result->width           = 1;
	$result->height          = 1;
	$result->baseline_width  = 1;
	$result->baseline_height = 1;
	$result->diff_pixels     = 0;
	$result->total_pixels    = 1;
	$result->diff_ratio      = 0;
	$files                   = array(
		'image'      => odvr_runner_png(),
		'diff_image' => odvr_runner_png(),
	);
	$body                    = odvr_runner_body( $result, $files );
	odvr_admin_check( 409 === odvr_runner_upload( $run, $body )->get_status(), 'Version報告前のUploadは拒否する' );
	$progress                      = odvr_admin_fixture( 'progress-request' );
	$progress->runner_execution_id = 'fixture-execution';
	$complete                      = odvr_admin_fixture( 'complete-request' );
	$complete->runner_execution_id = 'fixture-execution';
	$route                         = '/runner/runs/' . $uuid;
	odvr_admin_check( 200 === odvr_admin_request( 0, 'POST', $route . '/progress', $progress, array(), $headers )->get_status(), 'Progressで実Versionを保存する' );
	odvr_admin_check( 409 === odvr_admin_request( 0, 'POST', $route . '/complete', $complete, array(), $headers )->get_status(), 'Complete早着は409にする' );
	odvr_admin_check( 409 === odvr_runner_upload( $run, $body, 'other-execution' )->get_status(), '別ExecutionのUploadを拒否する' );
	$fixed       = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT manifest FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run['id'] ) ) );
	$baseline_id = $fixed->reference->snapshots[0]->baseline_snapshot_id;
	$baseline    = odvr_admin_request( 0, 'GET', '/runner/snapshots/' . $baseline_id . '/baseline', null, array(), $headers );
	odvr_admin_check( 200 === $baseline->get_status() && $baseline instanceof ODVR_Runner_PNG_Response && hash( 'sha256', $baseline->png ) === $baseline->get_headers()['X-ODVR-Image-SHA256'], '固定Baselineの完全検証済みPNGとSHAを返す' );
	odvr_admin_check( 404 === odvr_admin_request( 0, 'GET', '/runner/snapshots/2147483647/baseline', null, array(), $headers )->get_status(), '固定参照外のBaselineを拒否する' );
	$mutations = array(
		str_replace( 'name="target_id"', 'name="target_id[]"', $body ),
		str_replace( 'name="status"', 'name="target_id"', $body ),
		str_replace( 'name="image"', 'name="diff_image"', $body ),
		str_replace( "\r\n\r\n0\r\n", "\r\n\r\n0e0\r\n", $body ),
		str_replace( 'name="duration_ms"', 'name="unknown"', $body ),
		substr( $body, 0, -8 ),
	);
	foreach ( $mutations as $invalid ) {
		odvr_admin_check( 400 === odvr_runner_upload( $run, $invalid )->get_status(), 'multipartの配列・重複・未知・部分parse・欠落を拒否する' );
	}
	odvr_admin_check( 503 === odvr_runner_upload( $run, '' )->get_status(), '生本文の欠落をERROR Snapshotに変換しない' );
	$unknown            = clone $result;
	$unknown->target_id = 2147483647;
	odvr_admin_check( 404 === odvr_runner_upload( $run, odvr_runner_body( $unknown, $files ) )->get_status(), 'Manifest外の組み合わせを拒否する' );
	$bad_ratio             = clone $result;
	$bad_ratio->diff_ratio = 0.5;
	odvr_admin_check( 400 === odvr_runner_upload( $run, odvr_runner_body( $bad_ratio, $files ) )->get_status(), '画素数と比率の不一致を拒否する' );
	$bad_png           = $files;
	$bad_png['image'] .= 'trailing-data';
	$bad_response      = odvr_runner_upload( $run, odvr_runner_body( $result, $bad_png ) );
	odvr_admin_check( 400 === $bad_response->get_status(), 'PNG末尾の異常データを拒否する' );

	// 2つ目のrename先に破損した孤立ファイルを注入し、最初のPNGだけ確定した障害を再現する.
	$suite_row              = $wpdb->get_row( $wpdb->prepare( 'SELECT uuid FROM %i WHERE id = %d', ODVR_DB::table( 'suites' ), $suite_id ), ARRAY_A );
	$normalized             = clone $result;
	$normalized->diff_ratio = round( $result->diff_pixels / $result->total_pixels, 10 );
	$upload_digest          = ODVR_Snapshot_Repository::digest( $normalized, hash( 'sha256', $files['image'] ), hash( 'sha256', $files['diff_image'] ) );
	$folder                 = wp_upload_dir()['basedir'] . '/od-visual-regression/suite-' . $suite_row['uuid'] . '/run-' . $uuid . '/target-' . $target_id;
	mkdir( $folder, 0700, true );
	$broken = $folder . '/' . $fixed->devices[0]->slug . '-' . $upload_digest . '-diff.png';
	file_put_contents( $broken, 'broken-fixture' );
	chmod( $broken, 0600 );
	odvr_admin_check( 503 === odvr_runner_upload( $run, $body )->get_status(), '2つの画像確定間の障害はDBをpendingに保つ' );
	unlink( $broken );
	// DB更新を1回だけ失敗させ、rename済みの孤立ファイルから同じ再送を回復する.
	$fault  = function ( $sql ) {
		if ( 0 === strpos( $sql, 'UPDATE `' . ODVR_DB::table( 'snapshots' ) . '`' ) ) {
			return 'ODVR_FIXTURE_INVALID_SQL';
		}
		return $sql;
	};
	$errors = $wpdb->suppress_errors( true );
	add_filter( 'query', $fault );
	$failed = odvr_runner_upload( $run, $body );
	remove_filter( 'query', $fault );
	$wpdb->suppress_errors( $errors );
	odvr_admin_check( 503 === $failed->get_status(), '画像rename後のDB障害は確定せず503にする' );
	$saved = odvr_runner_upload( $run, $body );
	odvr_admin_check( 200 === $saved->get_status() && false === $saved->get_data()->replayed, '孤立した同一PNGから再送でDB確定を回復する' );
	$again = odvr_runner_upload( $run, $body );
	odvr_admin_check( 200 === $again->get_status() && true === $again->get_data()->replayed && $saved->get_data()->snapshot_id === $again->get_data()->snapshot_id, '同一Upload再送は元のSnapshot IDを返す' );

	$baseline_row   = $wpdb->get_row( $wpdb->prepare( 'SELECT image_path FROM %i WHERE id = %d', ODVR_DB::table( 'snapshots' ), $baseline_id ), ARRAY_A );
	$baseline_path  = wp_upload_dir()['basedir'] . '/od-visual-regression/' . $baseline_row['image_path'];
	$baseline_bytes = file_get_contents( $baseline_path );
	unlink( $baseline_path );
	try {
		$missing = odvr_admin_request( 0, 'GET', '/runner/snapshots/' . $baseline_id . '/baseline', null, array(), $headers );
		odvr_admin_check( 404 === $missing->get_status() && 'missing' === $missing->get_data()['data']['reason'], '固定Baseline欠損の理由はHTTP共通エラーでも残す' );
		odvr_admin_check( ( new ODVR_Contract_Validator() )->validate( 'error', json_decode( wp_json_encode( $missing->get_data() ) ) ), 'Baseline欠損応答は共通エラー契約を満たす' );
		odvr_admin_check( 200 === odvr_runner_upload( $run, $body )->get_status(), '確定後に参照画像が失われても同一Uploadの受理は変えない' );
	} finally {
		file_put_contents( $baseline_path, $baseline_bytes );
		chmod( $baseline_path, 0600 );
	}
	$changed          = $files;
	$changed['image'] = odvr_runner_png( 255 );
	odvr_admin_check( 409 === odvr_runner_upload( $run, odvr_runner_body( $result, $changed ) )->get_status(), '結果値が同じでも別画像への再送は409にする' );
	$counts       = odvr_admin_request( 0, 'POST', $route . '/progress', $progress, array(), $headers )->get_data();
	$counts_again = odvr_admin_request( 0, 'POST', $route . '/progress', $progress, array(), $headers )->get_data();
	odvr_admin_check( 1 === $counts->completed_snapshots && 1 === $counts->pending_snapshots && wp_json_encode( $counts ) === wp_json_encode( $counts_again ), 'Progress再送はCOUNTを増減しない' );
	$rows                 = $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, device_id FROM %i WHERE run_id = %d AND status = %s', ODVR_DB::table( 'snapshots' ), $run['id'], 'PENDING' ), ARRAY_A );
	$error                = odvr_admin_fixture( 'snapshot-result' );
	$error->target_id     = (int) $rows[0]['target_id'];
	$error->device_id     = (int) $rows[0]['device_id'];
	$error->status        = 'ERROR';
	$error->width         = null;
	$error->height        = null;
	$error->error_code    = 'CAPTURE_FAILED';
	$error->error_message = ODVR_Run_Manager::error_message( 'CAPTURE_FAILED' );
	odvr_admin_check( 200 === odvr_runner_upload( $run, odvr_runner_body( $error ) )->get_status(), 'ERRORは画像なしでpendingだけを終端にする' );
	$finished = odvr_admin_request( 0, 'POST', $route . '/complete', $complete, array(), $headers );
	odvr_admin_check( 200 === $finished->get_status() && 'partial' === $finished->get_data()->status && 1 === $finished->get_data()->completed_snapshots && 1 === $finished->get_data()->error_snapshots, 'Completeが保存COUNTからpartialを確定する' );
	$replay = odvr_admin_request( 0, 'POST', $route . '/complete', $complete, array(), $headers );
	odvr_admin_check( 200 === $replay->get_status() && wp_json_encode( $finished->get_data() ) === wp_json_encode( $replay->get_data() ), '同一Completeは元の終端時刻と集計を返す' );
	$altered                = clone $complete;
	$altered->outcome       = 'failed';
	$altered->error_code    = 'RUN_ABORTED';
	$altered->error_message = ODVR_Run_Manager::error_message( 'RUN_ABORTED' );
	odvr_admin_check( 409 === odvr_admin_request( 0, 'POST', $route . '/complete', $altered, array(), $headers )->get_status(), '異なるComplete再送は409にする' );
	odvr_admin_check( 409 === odvr_runner_upload( $run, $body )->get_status() && 409 === odvr_admin_request( 0, 'POST', $route . '/progress', $progress, array(), $headers )->get_status(), '終端後のUpload・Progressを拒否する' );
	$wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_token_expires_at' => ODVR_DB::utc_now() ), array( 'id' => $run['id'] ) );
	odvr_admin_check( 401 === odvr_admin_request( 0, 'POST', $route . '/complete', $complete, array(), $headers )->get_status(), '期限切れComplete再送を拒否する' );

	$race_run     = odvr_admin_check( $manager->create( $suite_id, $input, $admin ), '同時Upload用Runを作成する' );
	$race_uuid    = $race_run['item']->run_uuid;
	$race_headers = array(
		'Authorization'       => 'Bearer ' . $race_run['runner_token'],
		'X-ODVR-Execution-ID' => 'fixture-execution',
	);
	odvr_admin_check( $manager->start( $race_uuid, 'fixture-execution' ), '同時Upload用Runを開始する' );
	odvr_admin_check( $manager->progress( $race_uuid, $progress ), '同時Upload用Versionを保存する' );
	$same = odvr_runner_race( $race_run, array( $body, $body ) );
	odvr_admin_check( 200 === $same[0]->status && 200 === $same[1]->status && $same[0]->item->snapshot_id === $same[1]->item->snapshot_id && $same[0]->item->replayed !== $same[1]->item->replayed, '同時同一Uploadは1度だけ確定して元のIDを返す' );
	$other_error              = clone $error;
	$other_error->duration_ms = $error->duration_ms + 1;
	$different                = odvr_runner_race( $race_run, array( odvr_runner_body( $error ), odvr_runner_body( $other_error ) ) );
	$statuses                 = array( $different[0]->status, $different[1]->status );
	sort( $statuses );
	odvr_admin_check( array( 200, 409 ) === $statuses, '同時異なるUploadは一方だけ確定し他方は409にする' );
	$final_counts = odvr_admin_request( 0, 'POST', '/runner/runs/' . $race_uuid . '/progress', $progress, array(), $race_headers )->get_data();
	odvr_admin_check( 1 === $final_counts->completed_snapshots && 1 === $final_counts->error_snapshots && 0 === $final_counts->pending_snapshots, '競合後のProgressは保存COUNTと一致する' );
	odvr_admin_check( $manager->finish( $race_uuid, $complete ), '並行試験Runを完了する' );

	$guard_run     = odvr_admin_check( $manager->create( $suite_id, $input, $admin ), '期限競合用Runを作成する' );
	$guard_uuid    = $guard_run['item']->run_uuid;
	$guard_headers = array(
		'Authorization'       => 'Bearer ' . $guard_run['runner_token'],
		'X-ODVR-Execution-ID' => 'fixture-execution',
	);
	odvr_admin_check( $manager->start( $guard_uuid, 'fixture-execution' ), '期限競合用Runを開始する' );
	odvr_admin_check( $manager->progress( $guard_uuid, $progress ), '期限競合用Versionを保存する' );
	// 認証・PNG検証後、RunのFOR UPDATE直前に期限を過ぎたDB状態を注入する.
	$expire_before_lock = function ( $sql ) use ( $guard_run ) {
		global $wpdb;
		if ( false !== strpos( $sql, 'FROM `' . ODVR_DB::table( 'runs' ) . '`' ) && false !== strpos( $sql, 'FOR UPDATE' ) ) {
			$wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_token_expires_at' => ODVR_DB::utc_now() ), array( 'id' => $guard_run['id'] ) );
		}
		return $sql;
	};
	add_filter( 'query', $expire_before_lock );
	try {
		odvr_admin_check( 401 === odvr_runner_upload( $guard_run, $body )->get_status(), '検証中に期限を迎えたUploadはロック後のToken再検査で拒否する' );
		odvr_admin_check( 401 === odvr_admin_request( 0, 'POST', '/runner/runs/' . $guard_uuid . '/progress', $progress, array(), $guard_headers )->get_status(), 'Progressも状態確定直前にToken期限を再検査する' );
		odvr_admin_check( 401 === odvr_admin_request( 0, 'POST', '/runner/runs/' . $guard_uuid . '/complete', $altered, array(), $guard_headers )->get_status(), 'Completeも状態確定直前にToken期限を再検査する' );
	} finally {
		remove_filter( 'query', $expire_before_lock );
	}
	$aborted = odvr_admin_request( 0, 'POST', '/runner/runs/' . $guard_uuid . '/complete', $altered, array(), $guard_headers );
	odvr_admin_check( 200 === $aborted->get_status() && 'failed' === $aborted->get_data()->status && 2 === $aborted->get_data()->error_snapshots && 0 === $aborted->get_data()->pending_snapshots, 'failed Completeは残pendingだけを定型ERRORにする' );

	$new_target_run = odvr_admin_check( $manager->create( $suite_id, $input, $admin ), '新規Target結果用Runを作成する' );
	$new_uuid       = $new_target_run['item']->run_uuid;
	odvr_admin_check( $manager->start( $new_uuid, 'fixture-execution' ), '新規Target結果用Runを開始する' );
	odvr_admin_check( $manager->progress( $new_uuid, $progress ), '新規Target用Versionを保存する' );
	$no_baseline                     = odvr_admin_fixture( 'snapshot-result' );
	$no_baseline->target_id          = $error->target_id;
	$no_baseline->device_id          = $device_id;
	$no_baseline->status             = 'NO_BASELINE';
	$no_baseline->width              = 1;
	$no_baseline->height             = 1;
	$no_baseline->no_baseline_reason = 'missing';
	odvr_admin_check( 400 === odvr_runner_upload( $new_target_run, odvr_runner_body( $no_baseline, array( 'image' => $files['image'] ) ) )->get_status(), 'NO_BASELINEの理由は固定判定と一致させる' );
	$no_baseline->no_baseline_reason = 'new_target';
	odvr_admin_check( 200 === odvr_runner_upload( $new_target_run, odvr_runner_body( $no_baseline, array( 'image' => $files['image'] ) ) )->get_status(), '新規TargetのNO_BASELINEを成功撮影として保存する' );
	$new_headers = array(
		'Authorization'       => 'Bearer ' . $new_target_run['runner_token'],
		'X-ODVR-Execution-ID' => 'fixture-execution',
	);
	$new_failed  = odvr_admin_request( 0, 'POST', '/runner/runs/' . $new_uuid . '/complete', $altered, array(), $new_headers );
	odvr_admin_check( 200 === $new_failed->get_status() && 1 === $new_failed->get_data()->completed_snapshots && 1 === $new_failed->get_data()->error_snapshots, 'failedでもNO_BASELINEの成功結果を保持する' );
	// 初回撮影のSuite状態を既知のfixtureへ戻す。試験用Suite以外には触れない.
	$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => null ), array( 'id' => $suite_id ) );
	$initial      = odvr_admin_check(
		$manager->create(
			$suite_id,
			(object) array(
				'schema_version' => 1,
				'baseline_mode'  => 'pinned',
			),
			$admin
		),
		'初回撮影用Runを作成する'
	);
	$initial_uuid = $initial['item']->run_uuid;
	odvr_admin_check( $manager->start( $initial_uuid, 'fixture-execution' ), '初回撮影用Runを開始する' );
	odvr_admin_check( $manager->progress( $initial_uuid, $progress ), '初回撮影用Versionを保存する' );
	$captured            = odvr_admin_fixture( 'snapshot-result' );
	$captured->target_id = $target_id;
	$captured->device_id = $device_id;
	$captured->width     = 1;
	$captured->height    = 1;
	odvr_admin_check( 200 === odvr_runner_upload( $initial, odvr_runner_body( $captured, array( 'image' => $files['image'] ) ) )->get_status(), '参照なしのCAPTUREDをcurrent画像だけで保存する' );
	$initial_headers = array(
		'Authorization'       => 'Bearer ' . $initial['runner_token'],
		'X-ODVR-Execution-ID' => 'fixture-execution',
	);
	$initial_failed  = odvr_admin_request( 0, 'POST', '/runner/runs/' . $initial_uuid . '/complete', $altered, array(), $initial_headers );
	odvr_admin_check( 200 === $initial_failed->get_status() && 1 === $initial_failed->get_data()->completed_snapshots && 1 === $initial_failed->get_data()->error_snapshots, 'failed Completeは撮影済みCAPTUREDも保持する' );
	WP_CLI::success( 'Runner API・multipart・結果再送・Completeの検証が完了しました。' );
}

/**
 * 独立WordPressの2つのUploadを同時に開始する。
 *
 * @param array $run Run.
 * @param array $bodies 2本文.
 * @return array 2応答.
 */
function odvr_runner_race( $run, $bodies ) {
	$root      = sys_get_temp_dir() . '/odvr-upload-race-' . wp_generate_uuid4();
	$processes = array();
	$pipes     = array();
	mkdir( $root, 0700 );
	try {
		foreach ( $bodies as $index => $body ) {
			$fixture = $root . '/input-' . $index;
			file_put_contents(
				$fixture,
				wp_json_encode(
					array(
						'run'     => $run,
						'uploads' => wp_upload_dir(),
						'body'    => base64_encode( $body ),
					)
				)
			);
			chmod( $fixture, 0600 );
			$command             = 'wp eval-file ' . escapeshellarg( __DIR__ . '/runner-worker.php' ) . ' ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $root . '/ready-' . $index ) . ' ' . escapeshellarg( $root . '/go' ) . ' --path=' . escapeshellarg( ABSPATH ) . ' --url=' . escapeshellarg( get_site_url() );
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
		while ( ( ! file_exists( $root . '/ready-0' ) || ! file_exists( $root . '/ready-1' ) ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
			clearstatcache();
		}
		odvr_admin_check( file_exists( $root . '/ready-0' ) && file_exists( $root . '/ready-1' ), '独立2プロセスがUploadの障壁へ到達する' );
		file_put_contents( $root . '/go', 'go' );
		$responses = array();
		foreach ( $processes as $index => $process ) {
			$output = stream_get_contents( $pipes[ $index ][1] );
			$error  = stream_get_contents( $pipes[ $index ][2] );
			fclose( $pipes[ $index ][1] );
			fclose( $pipes[ $index ][2] );
			odvr_admin_check( 0 === proc_close( $process ), '並行Uploadワーカーが正常終了する: ' . $error );
			unset( $processes[ $index ] );
			$responses[] = json_decode( trim( $output ) );
		}
		return $responses;
	} finally {
		foreach ( $processes as $process ) {
			proc_terminate( $process );
			proc_close( $process );
		}
		foreach ( glob( $root . '/*' ) as $path ) {
			unlink( $path );
		}
		rmdir( $root );
	}
}
