<?php
/**
 * サイト別の保持/削除と、WordPressのUninstall入口を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() ) {
	exit; }
// 実際に作成した試験用2サイトだけをUninstallの対象へ注入する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions

/**
 * 条件を確認する。
 *
 * @param mixed  $value 結果.
 * @param string $message 内容.
 * @return mixed 正常値.
 * @throws RuntimeException 失敗時.
 */
function odvr_uninstall_check( $value, $message ) {
	if ( false === $value || is_wp_error( $value ) ) {
		throw new RuntimeException( esc_html( $message ) ); }
	WP_CLI::log( '確認済み: ' . $message );
	return $value;
}

/**
 * 親サイトを保持し、選択した子サイトだけを削除する。
 *
 * @return void
 */
function odvr_test_uninstall_multisite() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$parent = array();
	foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
		$parent[ $suffix ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ODVR_DB::table( $suffix ) ), ARRAY_A ); }
	$wrong    = new ODVR_Uninstaller();
	$sites    = array();
	$fixtures = array();
	$network  = get_network();
	$seeds    = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.json' ) );
	foreach ( $seeds as $seed ) {
		if ( $seed->valid && 'suite-create-request' === $seed->schema ) {
			$input             = clone $seed->value;
			$input->device_ids = array( 1 );
			break; }
	}
	try {
		foreach ( array( false, true ) as $delete ) {
			$id      = odvr_uninstall_check( wpmu_create_blog( $network->domain, '/odvr-uninstall-' . substr( wp_generate_uuid4(), 0, 8 ) . '/', 'Uninstall試験', 1, array(), $network->id ), '試験子サイトを作成する' );
			$sites[] = $id;
			switch_to_blog( $id );
			try {
				odvr_uninstall_check( is_wp_error( $wrong->current_site() ) && false === get_option( 'odvr_uninstall_error', false ), '親Uninstallerの再利用は別サイトを変更せず拒否する' );
				ODVR_Activator::activate();
				$suite = odvr_uninstall_check( ( new ODVR_Suite_Repository() )->create( $input, 1 ), '子サイトのSuiteを保存する' );
				$uuid  = wp_generate_uuid4();
				$now   = ODVR_DB::utc_now();
				$wpdb->insert(
					ODVR_DB::table( 'runs' ),
					array(
						'uuid'                    => $uuid,
						'suite_id'                => $suite['id'],
						'status'                  => $delete ? 'failed' : 'queued',
						'triggered_by'            => 1,
						'runner_token_hash'       => str_repeat( 'a', 64 ),
						'runner_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7200 ),
						'manifest'                => '{}',
						'environment'             => '{}',
						'total_snapshots'         => 1,
						'completed_snapshots'     => 0,
						'error_snapshots'         => 0,
						'created_at'              => $now,
						'updated_at'              => $now,
						'deadline_at'             => gmdate( 'Y-m-d H:i:s', time() + 5400 ),
					)
				);
				$run_id = (int) $wpdb->insert_id;
				odvr_uninstall_check(
					( new ODVR_Storage() )->with_run_lock(
						$uuid,
						true,
						function () {
							return true;
						}
					),
					'子サイトの私有Storageを作成する'
				);
				$root   = wp_upload_dir()['basedir'] . '/od-visual-regression';
				$marker = $root . '/fixture.txt';
				file_put_contents( $marker, 'fixture-only' );
				$outside = wp_upload_dir()['basedir'] . '/outside-' . wp_generate_uuid4() . '.txt';
				file_put_contents( $outside, 'WordPress-owned-fixture' );
				$post = wp_insert_post(
					array(
						'post_title'  => '保持するWordPress投稿',
						'post_status' => 'publish',
					)
				);
				if ( $delete ) {
					update_option( 'odvr_delete_data_on_uninstall', true, false ); }
				wp_schedule_event( time() + 60, 'hourly', 'odvr_retention' );
				$fixtures[ $id ] = array(
					'delete'  => $delete,
					'run_id'  => $run_id,
					'root'    => $root,
					'marker'  => $marker,
					'outside' => $outside,
					'post'    => $post,
				);
			} finally {
				restore_current_blog(); }
		}
		$limit = function () {
			return range( 1, 101 );
		};
		add_filter( 'sites_pre_query', $limit );
		try {
			$blocked = ODVR_Uninstaller::run();
			odvr_uninstall_check( is_wp_error( $blocked ) && 'odvr_uninstall_network_limit' === $blocked->get_error_code(), '100サイト超を無制限に処理して成功扱いしない' );
		} finally {
			remove_filter( 'sites_pre_query', $limit ); }
		$only_fixtures = function () use ( $sites ) {
			return $sites;
		};
		add_filter( 'sites_pre_query', $only_fixtures );
		try {
			odvr_uninstall_check( uninstall_plugin( 'od-visual-regression/od-visual-regression.php' ), 'WordPressのUninstall入口でサイト別選択を処理する' );
		} finally {
			remove_filter( 'sites_pre_query', $only_fixtures ); }
		foreach ( $fixtures as $id => $fixture ) {
			switch_to_blog( $id );
			try {
				if ( $fixture['delete'] ) {
					odvr_uninstall_check( ! is_dir( $fixture['root'] ) && false === get_option( 'odvr_db_version', false ) && ! get_role( 'administrator' )->has_cap( 'manage_odvr' ), '明示選択したサイトの画像・5テーブル・Options・権限を削除する' ); } else {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $fixture['run_id'] ), ARRAY_A );
					odvr_uninstall_check( is_array( $row ) && null === $row['runner_token_hash'] && file_exists( $fixture['marker'] ) && get_role( 'administrator' )->has_cap( 'manage_odvr' ), '未選択サイトはTokenだけ失効して履歴・画像・権限を保持する' ); }
					odvr_uninstall_check( ! wp_next_scheduled( 'odvr_retention' ) && get_post( $fixture['post'] ) && file_get_contents( $fixture['outside'] ) === 'WordPress-owned-fixture', 'ODVR Cronを止め、WordPress投稿と私有領域外のUploadsを保持する' );
			} finally {
				restore_current_blog(); }
		}
		foreach ( $parent as $suffix => $before ) {
			odvr_uninstall_check( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ODVR_DB::table( $suffix ) ), ARRAY_A ), '親サイトの' . $suffix . 'を変更しない' ); }
	} finally {
		foreach ( $sites as $id ) {
			wpmu_delete_blog( $id, true ); }
	}
}
odvr_test_uninstall_multisite();
WP_CLI::success( 'サイト別のUninstall・保持・削除・境界の検証が完了しました。' );
