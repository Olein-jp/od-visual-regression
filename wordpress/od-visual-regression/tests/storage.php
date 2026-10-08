<?php
/**
 * 実filesystem・Apache・DBによる非公開画像の試験。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// 固定fixtureとランダム試験用パスだけを操作し、finallyで除去する.
// phpcs:disable WordPress.PHP.IniSet.memory_limit_Disallowed -- メモリ不足と復元を実機検証する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.ValidatedSanitizedInput, WordPress.WP.GlobalVariablesOverride

/**
 * 試験条件を確認する。
 *
 * @param bool   $valid 条件.
 * @param string $message 内容.
 * @return void
 * @throws RuntimeException 試験失敗時.
 */
function odvr_storage_assert( $valid, $message ) {
	if ( ! $valid ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * PNGの固定チャンクを作る。
 *
 * @param string $type 種別.
 * @param string $data 本体.
 * @return string PNGチャンク.
 */
function odvr_storage_chunk( $type, $data ) {
	return pack( 'N', strlen( $data ) ) . $type . $data . hash( 'crc32b', $type . $data, true );
}

/**
 * 1画素の真のRGBA PNGを作る。
 *
 * @param string $raw scanline.
 * @param string $extra 圧縮後の余分なデータ.
 * @return string PNG.
 */
function odvr_storage_png( $raw = "\x00\xff\x00\x00\xff", $extra = '' ) {
	return "\x89PNG\r\n\x1a\n" . odvr_storage_chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 ) ) . odvr_storage_chunk( 'IDAT', gzcompress( $raw ) . $extra ) . odvr_storage_chunk( 'IEND', '' );
}

/**
 * 実HTTPで管理画像を要求する。
 *
 * @param int    $id Snapshot ID.
 * @param int    $user ユーザーID.
 * @param string $mode 認証条件.
 * @param string $kind 種別.
 * @return array|WP_Error 応答.
 */
function odvr_storage_http( $id, $user = 0, $mode = 'valid', $kind = 'current' ) {
	static $cookies = array();
	wp_set_current_user( $user );
	$headers    = array();
	$old_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
	if ( $user && 'no-cookie' !== $mode ) {
		if ( ! isset( $cookies[ $user ] ) ) {
			$cookies[ $user ] = wp_generate_auth_cookie( $user, time() + 300, 'logged_in' );
		}
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ $user ];
		$headers['Cookie']           = LOGGED_IN_COOKIE . '=' . $_COOKIE[ LOGGED_IN_COOKIE ];
	}
	if ( 'no-nonce' !== $mode ) {
		$headers['X-WP-Nonce'] = 'bad-nonce' === $mode ? 'invalid' : wp_create_nonce( 'wp_rest' );
	}
	$url = 'http://wordpress/?rest_route=/odvr/v1/snapshots/' . $id . '/image&kind=' . $kind;
	if ( 'query-nonce' === $mode ) {
		$url .= '&_wpnonce=' . wp_create_nonce( 'wp_rest' );
	}
	if ( null === $old_cookie ) {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	} else {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $old_cookie;
	}
	return wp_remote_get(
		$url,
		array(
			'headers'     => $headers,
			'timeout'     => 10,
			'redirection' => 0,
		)
	);
}

/**
 * PNG検証と保存・認証配信・削除を試験する。
 *
 * @return void
 */
function odvr_test_storage() {
	global $wpdb;
	$old_memory    = ini_get( 'memory_limit' );
	$old_user      = get_current_user_id();
	$old_cursor    = get_option( 'odvr_storage_cleanup_cursor', null );
	$old_ready     = get_option( 'odvr_storage_ready', null );
	$users         = array();
	$suite         = null;
	$runs          = array();
	$snapshots     = array();
	$links         = array();
	$uuid          = wp_generate_uuid4();
	$root          = wp_upload_dir()['basedir'] . '/od-visual-regression';
	$upload_filter = function ( $uploads ) {
		$uploads['baseurl'] = 'http://wordpress/wp-content/uploads';
		return $uploads;
	};
	add_filter( 'upload_dir', $upload_filter );
	try {
		ini_set( 'memory_limit', '1024M' );
		$png  = odvr_storage_png();
		$info = ODVR_PNG::validate( $png, 1, 1 );
		odvr_storage_assert( is_array( $info ) && hash( 'sha256', $png ) === $info['sha256'], '完全PNGのCRC・inflate・GDデコード・digestが一致する' );
		foreach ( array( $png . 'trailer', substr( $png, 0, -1 ), substr_replace( $png, "\x00", 29, 1 ), odvr_storage_png( "\x05\xff\x00\x00\xff" ), odvr_storage_png( "\x00\xff" ), odvr_storage_png( str_repeat( "\x00", 100000 ) ), odvr_storage_png( "\x00\xff\x00\x00\xff", 'junk' ), substr( $png, 0, 33 ) . odvr_storage_chunk( 'acTL', pack( 'NN', 1, 0 ) ) . substr( $png, 33 ), substr( $png, 0, 33 ) . odvr_storage_chunk( 'ABCD', '' ) . substr( $png, 33 ), str_repeat( 'x', ODVR_PNG::MAX_BYTES + 1 ) ) as $invalid ) {
			odvr_storage_assert( is_wp_error( ODVR_PNG::validate( $invalid, 1, 1 ) ), '破損・CRC・filter・展開不足/過剰・圧縮末尾・APNG・未知critical・サイズを拒否する' );
		}
		odvr_storage_assert( is_wp_error( ODVR_PNG::validate( $png, 2, 1 ) ) && is_wp_error( ODVR_PNG::validate( $png, 1, 1, str_repeat( '0', 64 ) ) ) && is_wp_error( ODVR_PNG::validate( $png, 16385, 1 ) ) && is_wp_error( ODVR_PNG::validate( $png, 10000, 10000 ) ), '申告寸法・digest・辺・面積の上限を拒否する' );

		$limit_png = substr( $png, 0, -12 );
		$remaining = ODVR_PNG::MAX_BYTES - strlen( $png ) - 20 * 17;
		for ( $index = 0; $index < 20; ++$index ) {
			$size       = min( 1048576, $remaining );
			$limit_png .= odvr_storage_chunk( 'tEXt', 'note' . "\x00" . str_repeat( 'x', $size ) );
			$remaining -= $size;
		}
		$limit_png .= substr( $png, -12 );
		odvr_storage_assert( ODVR_PNG::MAX_BYTES === strlen( $limit_png ) && is_array( ODVR_PNG::validate( $limit_png, 1, 1 ) ), '20MiBの境界は完全検証後に受理する' );
		unset( $limit_png );

		$image = imagecreatetruecolor( 17, 13 );
		imageinterlace( $image, true );
		ob_start();
		imagepng( $image );
		$interlaced = ob_get_clean();
		imagedestroy( $image );
		odvr_storage_assert( is_array( ODVR_PNG::validate( $interlaced, 17, 13 ) ), 'Adam7の全passを検証して完全デコードできる' );
		$storage = new ODVR_Storage();
		odvr_storage_assert( is_wp_error( $storage->diagnose() ) && is_wp_error( $storage->stage( $uuid, $png, 1, 1 ) ), 'Web設定の運用確認がない環境は403でもreadyにしない' );
		// この試験ではwp-envのApacheが.htaccessを適用し、直URLを実際に拒否することを下で確認する.
		define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
		ini_set( 'memory_limit', '128M' );
		odvr_storage_assert( is_wp_error( $storage->diagnose() ), 'MVP最大画像の完全検証メモリが不足する環境はreadyにしない' );
		ini_set( 'memory_limit', '1024M' );
		odvr_storage_assert( true === $storage->diagnose(), '実Apacheのcontrol 200とroot/staging/lock canary拒否を確認する' );
		foreach ( array( 200, 401, 302, 'error', 'waf' ) as $mode ) {
			$mock = function ( $pre, $options, $url ) use ( $mode ) {
				if ( 'error' === $mode ) {
					return new WP_Error( 'fixture_failure', '固定通信失敗' );
				}
				$is_storage = false !== strpos( $url, '/od-visual-regression/' );
				$code       = 'waf' === $mode ? 403 : ( $is_storage ? $mode : 200 );
				return array(
					'headers'  => array(),
					'body'     => $is_storage ? '' : basename( $url, '.txt' ),
					'response' => array(
						'code'    => $code,
						'message' => 'fixture',
					),
					'cookies'  => array(),
				);
			};
			add_filter( 'pre_http_request', $mock, 10, 3 );
			try {
				odvr_storage_assert( is_wp_error( $storage->diagnose() ) && is_wp_error( $storage->ready() ), '公開・Basic401・redirect・通信失敗・全面WAF拒否はreadyにしない' );
			} finally {
				remove_filter( 'pre_http_request', $mock, 10 );
			}
		}
		odvr_storage_assert( true === $storage->diagnose(), '診断失敗後も実Apacheを再確認して復旧できる' );
		$state            = get_option( 'odvr_storage_ready' );
		$state['expires'] = time() - 1;
		update_option( 'odvr_storage_ready', $state, false );
		odvr_storage_assert( is_wp_error( $storage->ready() ), '診断成功は120秒で失効する' );
		odvr_storage_assert( true === $storage->diagnose(), '失効後に再診断する' );
		$fixtures = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.json' ) );
		foreach ( $fixtures as $fixture ) {
			if ( 'suite-create-request' === $fixture->schema && $fixture->valid && false !== strpos( $fixture->name, '正常' ) ) {
				$input             = $fixture->value;
				$input->device_ids = array( 1 );
				$suite             = ( new ODVR_Suite_Repository() )->create( $input, 1 );
				break;
			}
		}
		odvr_storage_assert( is_array( $suite ), '画像試験だけのSuiteを作成する' );
		$now = ODVR_DB::utc_now();
		foreach ( array( $uuid, wp_generate_uuid4() ) as $run_uuid ) {
			$wpdb->insert(
				ODVR_DB::table( 'runs' ),
				array(
					'uuid'                => $run_uuid,
					'suite_id'            => $suite['id'],
					'status'              => 'complete',
					'triggered_by'        => 1,
					'environment'         => '{}',
					'manifest'            => '{}',
					'total_snapshots'     => 1,
					'completed_snapshots' => 1,
					'error_snapshots'     => 0,
					'created_at'          => $now,
					'updated_at'          => $now,
					'deadline_at'         => $now,
				)
			);
			$runs[] = (int) $wpdb->insert_id;
		}
		$ticket = $storage->stage( $uuid, $png, 1, 1 );
		odvr_storage_assert( is_array( $ticket ), '検証したPNGをサイト別stagingへ保存する' );
		odvr_storage_assert( is_wp_error( $storage->promote( $ticket, $suite['uuid'], 1, 'desktop', hash( 'sha256', 'result' ) ) ), '排他Runロックなしのrenameを拒否する' );
		$path = $storage->with_run_lock(
			$uuid,
			true,
			function () use ( $storage, $ticket, $suite ) {
				return $storage->promote( $ticket, $suite['uuid'], 1, 'desktop', hash( 'sha256', 'result' ) );
			}
		);
		odvr_storage_assert( is_string( $path ) && false === strpos( $path, '..' ) && '/' !== $path[0], 'サーバー生成のdigest付き不変相対パスへrenameする' );
		$wpdb->insert(
			ODVR_DB::table( 'snapshots' ),
			array(
				'run_id'            => $runs[0],
				'target_id'         => 1,
				'device_id'         => 1,
				'status'            => 'pending',
				'url'               => 'https://fixture.test/',
				'image_path'        => $path,
				'width'             => 1,
				'height'            => 1,
				'dimension_changed' => 0,
				'metadata'          => wp_json_encode(
					array(
						'metadata_version' => 1,
						'image_sha256'     => $info['sha256'],
					)
				),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$snapshots[] = (int) $wpdb->insert_id;
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$users[] = wp_insert_user(
				array(
					'user_login' => 'odvr-storage-' . wp_generate_uuid4(),
					'user_pass'  => 'fixture-only-password',
					'role'       => $role,
				)
			);
		}
		$pending_response = odvr_storage_http( $snapshots[0], $users[0] );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( $pending_response ), 'DBがpendingの部分renameを配信しない' );
		$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'status' => 'CAPTURED' ), array( 'id' => $snapshots[0] ) );
		$response = odvr_storage_http( $snapshots[0], $users[0] );
		odvr_storage_assert( 200 === wp_remote_retrieve_response_code( $response ) && wp_remote_retrieve_body( $response ) === $png, '実Cookie/nonce/manage_odvrでPHPからPNGを配信する' );
		odvr_storage_assert( 'private, no-store' === wp_remote_retrieve_header( $response, 'cache-control' ) && 'nosniff' === wp_remote_retrieve_header( $response, 'x-content-type-options' ) && 'image/png' === wp_remote_retrieve_header( $response, 'content-type' ) && wp_remote_retrieve_header( $response, 'x-odvr-image-sha256' ) === $info['sha256'], 'private/no-store・nosniff・PNG MIME・digestを付ける' );
		foreach ( array( array( 0, 'valid' ), array( $users[1], 'valid' ), array( $users[0], 'bad-nonce' ), array( $users[0], 'no-nonce' ), array( $users[0], 'no-cookie' ), array( $users[0], 'query-nonce' ) ) as $auth ) {
			$response = odvr_storage_http( $snapshots[0], $auth[0], $auth[1] );
			odvr_storage_assert( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) && false === strpos( wp_remote_retrieve_body( $response ), $png ) && 'private, no-store' === wp_remote_retrieve_header( $response, 'cache-control' ), '未ログイン・権限なし・nonce不正/欠落・Cookieなし・秘密付きURLを拒否する' );
		}
		odvr_storage_assert( 403 === wp_remote_retrieve_response_code( wp_remote_get( 'http://wordpress/wp-content/uploads/od-visual-regression/' . $path ) ), '実PNGの直接URLをApacheが拒否する' );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[0], $users[0], 'valid', 'baseline' ) ) && 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[0], $users[0], 'valid', 'diff' ) ), '未固定Baseline・存在しないdiffを別画像で補わない' );

		$second_uuid  = $wpdb->get_var( $wpdb->prepare( 'SELECT uuid FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $runs[1] ) );
		$second       = $storage->stage( $second_uuid, $png, 1, 1 );
		$second_diff  = $storage->stage( $second_uuid, $png, 1, 1 );
		$second_paths = $storage->with_run_lock(
			$second_uuid,
			true,
			function () use ( $storage, $second, $second_diff, $suite ) {
				return array( $storage->promote( $second, $suite['uuid'], 1, 'desktop', hash( 'sha256', 'second-result' ) ), $storage->promote( $second_diff, $suite['uuid'], 1, 'desktop', hash( 'sha256', 'second-result' ), true ) );
			}
		);
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => $runs[0] ), array( 'id' => $runs[1] ) );
		$wpdb->insert(
			ODVR_DB::table( 'snapshots' ),
			array(
				'run_id'               => $runs[1],
				'target_id'            => 1,
				'device_id'            => 1,
				'baseline_snapshot_id' => $snapshots[0],
				'status'               => 'UNCHANGED',
				'url'                  => 'https://fixture.test/',
				'image_path'           => $second_paths[0],
				'diff_path'            => $second_paths[1],
				'width'                => 1,
				'height'               => 1,
				'baseline_width'       => 1,
				'baseline_height'      => 1,
				'dimension_changed'    => 0,
				'metadata'             => wp_json_encode(
					array(
						'metadata_version' => 1,
						'image_sha256'     => $info['sha256'],
						'diff_sha256'      => $info['sha256'],
					)
				),
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		$snapshots[] = (int) $wpdb->insert_id;
		foreach ( array( 'current', 'diff', 'baseline' ) as $kind ) {
			$response = odvr_storage_http( $snapshots[1], $users[0], 'valid', $kind );
			odvr_storage_assert( 200 === wp_remote_retrieve_response_code( $response ) && wp_remote_retrieve_body( $response ) === $png, 'current・diff・固定BaselineをそれぞれDB参照から配信する' );
		}
		$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'target_id' => 2 ), array( 'id' => $snapshots[1] ) );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[1], $users[0], 'valid', 'baseline' ) ), '固定Baselineが別Targetの参照なら拒否する' );
		$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'target_id' => 1 ), array( 'id' => $snapshots[1] ) );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => null ), array( 'id' => $runs[1] ) );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[1], $users[0], 'valid', 'baseline' ) ), 'Runの固定参照と一致しないBaselineを拒否する' );
		$wpdb->update( ODVR_DB::table( 'snapshots' ), array( 'baseline_snapshot_id' => null ), array( 'id' => $snapshots[1] ) );
		odvr_storage_assert(
			is_wp_error(
				$storage->with_run_lock(
					$uuid,
					false,
					function () use ( $storage, $suite, $uuid, $info ) {
						return $storage->read_png( '../secret.png', $suite['uuid'], $uuid, 1, 1, 1, $info['sha256'] );
					}
				)
			),
			'DBに任意相対パスが混入しても境界を拒否する'
		);
		ini_set( 'memory_limit', '128M' );
		$unavailable = $storage->with_run_lock(
			$uuid,
			false,
			function () use ( $storage, $suite, $uuid, $path, $info ) {
				return $storage->read_png( $path, $suite['uuid'], $uuid, 1, 10000, 4000, $info['sha256'] );
			}
		);
		odvr_storage_assert( is_wp_error( $unavailable ) && 503 === $unavailable->get_error_data()['status'], 'デコードのメモリ不足をBaseline欠損404に読み替えない' );
		ini_set( 'memory_limit', '1024M' );

		$absolute = $root . '/' . $path;
		$backup   = $absolute . '.fixture';
		rename( $absolute, $backup );
		symlink( $backup, $absolute );
		$links[] = $absolute;
		odvr_storage_assert(
			is_wp_error(
				$storage->with_run_lock(
					$uuid,
					false,
					function () use ( $storage, $suite, $uuid, $path, $info ) {
						return $storage->read_png( $path, $suite['uuid'], $uuid, 1, 1, 1, $info['sha256'] );
					}
				)
			),
			'ファイルsymlinkを拒否する'
		);
		unlink( $absolute );
		rename( $backup, $absolute );

		$target_directory = dirname( $absolute );
		rename( $target_directory, $target_directory . '.fixture' );
		try {
			symlink( $target_directory . '.fixture', $target_directory );
			$response = odvr_storage_http( $snapshots[0], $users[0] );
			odvr_storage_assert( 503 === wp_remote_retrieve_response_code( $response ), '実HTTP配信でも祖先ディレクトリのsymlinkを拒否する' );
		} finally {
			if ( is_link( $target_directory ) ) {
				unlink( $target_directory );
			}
			rename( $target_directory . '.fixture', $target_directory );
		}
		file_put_contents( $absolute, $png . 'corrupt' );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[0], $users[0] ) ), '確定後の破損画像を配信しない' );
		file_put_contents( $absolute, $png );
		$orphan      = $storage->stage( $uuid, $png, 1, 1 );
		$orphan_path = $storage->with_run_lock(
			$uuid,
			true,
			function () use ( $storage, $orphan, $suite ) {
				return $storage->promote( $orphan, $suite['uuid'], 1, 'desktop', hash( 'sha256', 'rollback-result' ), true );
			}
		);

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert(
			ODVR_DB::table( 'snapshots' ),
			array(
				'run_id'            => $runs[0],
				'target_id'         => 2,
				'device_id'         => 1,
				'status'            => 'CAPTURED',
				'url'               => 'https://fixture.test/',
				'image_path'        => $orphan_path,
				'width'             => 1,
				'height'            => 1,
				'dimension_changed' => 0,
				'metadata'          => wp_json_encode(
					array(
						'metadata_version' => 1,
						'image_sha256'     => $info['sha256'],
					)
				),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$uncommitted_id = (int) $wpdb->insert_id;
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $uncommitted_id, $users[0] ) ), 'rename後でも別DB接続はCOMMIT前の画像を配信しない' );
		$wpdb->query( 'ROLLBACK' );
		$staging      = $storage->stage( $uuid, $png, 1, 1 );
		$staging_path = $root . '/.staging/' . $uuid . '/' . $staging['request_uuid'] . '.png';
		touch( $root . '/' . $orphan_path, time() - 3700 );
		touch( $staging_path, time() - 3700 );
		touch( $absolute, time() - 3700 );
		odvr_storage_assert( 2 === $storage->cleanup( $uuid ) && file_exists( $absolute ) && ! file_exists( $staging_path ) && ! file_exists( $root . '/' . $orphan_path ), 'rollbackした未参照PNGと古いstagingだけを排他cleanupする' );
		$fresh = $storage->stage( $uuid, $png, 1, 1 );
		odvr_storage_assert( 0 === $storage->cleanup( $uuid ), '新しいstagingと確定DB参照は保持する' );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'status' => 'deleting' ), array( 'id' => $runs[0] ) );
		odvr_storage_assert( 404 === wp_remote_retrieve_response_code( odvr_storage_http( $snapshots[0], $users[0] ) ), 'deleting確定後の新規画像読取を拒否する' );
		$other  = new ODVR_Storage();
		$result = $storage->with_run_lock(
			$uuid,
			false,
			function () use ( $other, $uuid ) {
				return $other->with_run_lock(
					$uuid,
					true,
					function () {
							return true;
					},
					0.05
				);
			}
		);
		odvr_storage_assert( is_wp_error( $result ) && file_exists( $absolute ), '共有読取中は排他削除ロックを取得できない' );
		$lock        = $root . '/.locks/run-' . $uuid . '.lock';
		$inode       = fileinode( $lock );
		$worker_path = '/tmp/odvr-storage-' . $uuid;
		$process     = null;
		$pipes       = array();
		try {
			$result = $storage->with_run_lock(
				$uuid,
				false,
				function () use ( $storage, $uuid, $suite, $runs, $absolute, $path, $info, $png, $worker_path, &$process, &$pipes ) {
					$command = 'wp eval-file ' . escapeshellarg( __DIR__ . '/storage-worker.php' ) . ' ' . escapeshellarg( (string) $runs[0] ) . ' ' . escapeshellarg( $uuid ) . ' ' . escapeshellarg( $suite['uuid'] ) . ' ' . escapeshellarg( $worker_path );
					$process = proc_open(
						$command,
						array(
							0 => array( 'pipe', 'r' ),
							1 => array( 'file', $worker_path . '.log', 'w' ),
							2 => array( 'file', $worker_path . '.log', 'a' ),
						),
						$pipes,
						ABSPATH
					);
					odvr_storage_assert( is_resource( $process ), '独立した削除プロセスを起動する' );
					$until = microtime( true ) + 3;
					while ( ! file_exists( $worker_path . '.started' ) && microtime( true ) < $until ) {
						usleep( 10000 );
						clearstatcache();
					}
					usleep( 100000 );
					odvr_storage_assert( file_exists( $worker_path . '.started' ) && proc_get_status( $process )['running'] && file_exists( $absolute ), '実削除プロセスが共有streamの終了を待つ' );
					odvr_storage_assert( $storage->read_png( $path, $suite['uuid'], $uuid, 1, 1, 1, $info['sha256'] ) === $png, '先に認証したstreamはdeleting後でも取得済み参照を完了できる' );
					return true;
				}
			);
			odvr_storage_assert( true === $result, '読取を完了して共有ロックを解放する' );
			fclose( $pipes[0] );
			proc_close( $process );
			$process = null;
			odvr_storage_assert( file_exists( $worker_path . '.result' ) && 'success' === file_get_contents( $worker_path . '.result' ) && ! file_exists( $absolute ), '共有ロック解放後に独立プロセスの画像削除が完了する' );
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				proc_close( $process );
			}
			foreach ( array( '.started', '.result', '.log' ) as $suffix ) {
				wp_delete_file( $worker_path . $suffix );
			}
		}

		odvr_storage_assert( true === $storage->delete_run( $runs[0] ) && ! file_exists( $absolute ) && fileinode( $lock ) === $inode, 'stream終了後にRunだけを削除しロックinodeを保持する' );
		odvr_storage_assert( true === $storage->delete_run( $runs[0] ), '画像削除は冪等で再開できる' );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		foreach ( $links as $link ) {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
		}
		if ( is_array( $suite ) ) {
			$wpdb->update( ODVR_DB::table( 'suites' ), array( 'baseline_run_id' => null ), array( 'id' => $suite['id'] ) );
			foreach ( $runs as $run ) {
				$wpdb->update( ODVR_DB::table( 'runs' ), array( 'reference_run_id' => null ), array( 'id' => $run ) );
			}
			foreach ( $runs as $run ) {
				$wpdb->update(
					ODVR_DB::table( 'runs' ),
					array(
						'status'            => 'deleting',
						'runner_token_hash' => null,
					),
					array( 'id' => $run )
				);
				( new ODVR_Storage() )->delete_run( $run );
				$wpdb->delete( ODVR_DB::table( 'snapshots' ), array( 'run_id' => $run ) );
				$wpdb->delete( ODVR_DB::table( 'runs' ), array( 'id' => $run ) );
			}
			$wpdb->delete( ODVR_DB::table( 'suites' ), array( 'id' => $suite['id'] ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $user ) {
			wp_delete_user( $user );
		}
		remove_filter( 'upload_dir', $upload_filter );
		if ( null === $old_ready ) {
			delete_option( 'odvr_storage_ready' );
		} else {
			update_option( 'odvr_storage_ready', $old_ready, false );
		}
		if ( null === $old_cursor ) {
			delete_option( 'odvr_storage_cleanup_cursor' );
		} else {
			update_option( 'odvr_storage_cleanup_cursor', $old_cursor, false );
		}
		wp_set_current_user( $old_user );
		ini_set( 'memory_limit', $old_memory );
	}
	WP_CLI::success( '非公開Storageと認証画像配信の検証が完了しました。' );
}
odvr_test_storage();
