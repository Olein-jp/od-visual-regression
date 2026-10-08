<?php
/**
 * 管理API・Run Token・fixture Dispatcherの実WordPress検証。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
// 試験で作成したSuite・ユーザー・一時画像だけを回収する.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.IniSet.memory_limit_Disallowed, WordPress.WP.GlobalVariablesOverride, WordPress.Security.ValidatedSanitizedInput

/**
 * 条件を確認する。秘密を失敗メッセージへ含めない。
 *
 * @param mixed  $value 条件.
 * @param string $message 内容.
 * @return mixed 値.
 * @throws RuntimeException 失敗時.
 */
function odvr_admin_check( $value, $message ) {
	if ( false === $value || is_wp_error( $value ) ) {
		throw new RuntimeException( esc_html( $message ) ); }
	WP_CLI::log( '確認済み: ' . $message );
	return $value;
}
/**
 * Cookie/nonceと実RESTディスパッチを使う。
 *
 * @param int    $user ユーザー.
 * @param string $method method.
 * @param string $route route.
 * @param mixed  $body JSON.
 * @param array  $query query.
 * @param array  $headers headers.
 * @param bool   $tls RunnerのTLS fixture.
 * @return WP_REST_Response 応答.
 */
function odvr_admin_request( $user, $method, $route, $body = null, $query = array(), $headers = array(), $tls = true ) {
	global $wp_rest_auth_cookie;
	$https = $_SERVER['HTTPS'] ?? null;
	if ( 0 === strpos( $route, '/runner/' ) ) {
		if ( $tls ) {
			$_SERVER['HTTPS'] = 'on';
		} else {
			unset( $_SERVER['HTTPS'] ); }
	}
	wp_set_current_user( $user );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	if ( $user ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + 3600, 'logged_in' ); }
	$request = new WP_REST_Request( $method, '/odvr/v1' . $route );
	$request->set_query_params( $query );
	if ( $user ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value ); }
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) ); }
	$wp_rest_auth_cookie = $user ? true : null;
	$server              = rest_get_server();
	$response            = $server->dispatch( $request );
	if ( null === $https ) {
		unset( $_SERVER['HTTPS'] );
	} else {
		$_SERVER['HTTPS'] = $https; }
	return apply_filters( 'rest_post_dispatch', $response, $server, $request );
}
/**
 * 固定fixtureから正常入力を取得する。
 *
 * @param string $schema 契約.
 * @return stdClass 値.
 */
function odvr_admin_fixture( $schema ) {
	$fixtures = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.json' ) );
	foreach ( $fixtures as $fixture ) {
		if ( $fixture->valid && $schema === $fixture->schema ) {
			return clone $fixture->value; }
	}
	return null;
}
/**
 * 障害注入できる署名済みDispatcher fixtureとAPIを確認する。
 *
 * @return void
 */
function odvr_test_admin_api() {
	global $wpdb;
	ODVR_Activator::activate();
	$original_user   = get_current_user_id();
	$original_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
	$memory          = ini_get( 'memory_limit' );
	ini_set( 'memory_limit', '1024M' );
	$directory = sys_get_temp_dir() . '/odvr-api-' . wp_generate_uuid4();
	mkdir( $directory, 0700 );
	$uploads  = function ( $value ) use ( $directory ) {
		$value['basedir'] = $directory;
		$value['path']    = $directory;
		$value['baseurl'] = 'https://staging.example.com/uploads';
		$value['url']     = $value['baseurl'];
		$value['error']   = false;
		return $value;
	};
	$rest_url = function ( $url, $path ) {
		return 'https://staging.example.com/wp-json/' . ltrim( $path, '/' );
	};
	define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
	define( 'ODVR_DISPATCHER_URL', 'https://dispatcher.fixture.test' );
	define( 'ODVR_DISPATCHER_SHARED_SECRET', 'fixture-shared-secret-with-at-least-32-bytes' );
	define( 'ODVR_HTTP_AUTH_USER', 'fixture-user' );
	define( 'ODVR_HTTP_AUTH_PASSWORD', 'fixture-password' );
	define( 'ODVR_HTTP_AUTH_ORIGIN', 'https://staging.example.com' );
	$option_names = array( 'odvr_settings', 'odvr_storage_ready', 'odvr_connection_test_lock' );
	$options      = array();
	foreach ( $option_names as $name ) {
		$options[ $name ] = get_option( $name, null );
		delete_option( $name ); }
	$users      = array();
	$suite_ids  = array();
	$device_ids = array();
	$ledger     = array();
	$posts      = array();
	$lookups    = 0;
	$diagnoses  = 0;
	$mode       = 'accepted';
	$transport  = function ( $pre, $args, $url ) use ( &$ledger, &$posts, &$lookups, &$diagnoses, &$mode, $directory ) {
		global $wpdb;
		$response = function ( $status, $body ) {
			return array(
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => $body,
				'cookies'  => array(),
			);
		};
		if ( 0 === strpos( $url, 'https://staging.example.com/uploads/' ) ) {
			if ( false !== strpos( $url, '/od-visual-regression/' ) ) {
				return $response( 403, '' ); }
			return $response( 200, file_get_contents( $directory . '/' . basename( $url ) ) );
		}
		odvr_admin_check( 0 === strpos( $url, ODVR_DISPATCHER_URL . '/v1/' ) && 0 === $args['redirection'] && empty( $args['cookies'] ) && empty( $args['headers']['Authorization'] ), 'Dispatcher通信先・Cookie・リダイレクトを固定する' );
		$body      = $args['body'];
		$timestamp = $args['headers']['X-ODVR-Timestamp'];
		odvr_admin_check( ctype_digit( $timestamp ) && abs( time() - (int) $timestamp ) <= 300 && hash_equals( hash_hmac( 'sha256', $timestamp . "\n" . $body, ODVR_DISPATCHER_SHARED_SECRET ), $args['headers']['X-ODVR-Signature'] ), 'raw bodyとtimestampのHMACをfixtureで検証する' );
		if ( '/v1/connection-test' === substr( $url, strlen( ODVR_DISPATCHER_URL ) ) ) {
			++$diagnoses;
			$value = json_decode( $body );
			odvr_admin_check( true === ( new ODVR_Contract_Validator() )->validate( 'dispatch-connection-test-request', $value ) && ! isset( $value->runner_token, $value->run_uuid ), '診断はRun・Tokenなしの登録確認だけを送る' );
			return $response(
				200,
				wp_json_encode(
					(object) array(
						'schema_version' => 1,
						'item'           => (object) array(
							'checks'     => (object) array(
								'settings'   => 'passed',
								'storage'    => 'passed',
								'dispatcher' => 'passed',
							),
							'checked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
							'code'       => 'odvr_connection_passed',
							'message'    => 'fixture確認済み',
						),
					)
				)
			);
		}
		if ( 'GET' === $args['method'] ) {
			++$lookups;
			odvr_admin_check( '' === $body, '別リクエストはTokenを復元せず空bodyで照合する' );
			$uuid = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			if ( ! isset( $ledger[ $uuid ] ) ) {
				return $response( 404, '' ); }
			$value = $ledger[ $uuid ]['payload'];
			return $response(
				200,
				wp_json_encode(
					(object) array(
						'schema_version'      => 1,
						'site_id'             => $value->site_id,
						'run_uuid'            => $uuid,
						'status'              => 'started',
						'runner_execution_id' => 'fixture-execution',
					)
				)
			);
		}
		$value = json_decode( $body );
		odvr_admin_check( true === ( new ODVR_Contract_Validator() )->validate( 'dispatch-request', $value ), 'Dispatchは製品Schemaで検証する' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', ODVR_DB::table( 'runs' ), $value->run_uuid ), ARRAY_A );
		odvr_admin_check( $row && (int) $row['total_snapshots'] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND status = %s', ODVR_DB::table( 'snapshots' ), $row['id'], 'PENDING' ) ) && hash_equals( $row['runner_token_hash'], hash( 'sha256', $value->runner_token ) ), 'Dispatchより先に固定Manifest・pending・Token HashをCOMMITする' );
		$posts[] = hash( 'sha256', $body );
		if ( isset( $ledger[ $value->run_uuid ] ) ) {
			odvr_admin_check( hash_equals( $ledger[ $value->run_uuid ]['digest'], hash( 'sha256', $body ) ), '応答喪失の再送でもbody・Token・UUIDを変えない' ); }
		$ledger[ $value->run_uuid ] = array(
			'digest'  => hash( 'sha256', $body ),
			'payload' => $value,
		);
		if ( 'unknown' === $mode ) {
			return new WP_Error( 'fixture_response_lost', 'fixture応答喪失' ); }
		if ( 'rejected' === $mode ) {
			return $response(
				400,
				wp_json_encode(
					(object) array(
						'schema_version' => 1,
						'code'           => 'odvr_invalid_payload',
						'message'        => 'fixture拒否',
						'data'           => (object) array(
							'status'     => 400,
							'retryable'  => false,
							'request_id' => 'fixture-request',
						),
					)
				)
			); }
		return $response(
			202,
			wp_json_encode(
				(object) array(
					'schema_version'      => 1,
					'site_id'             => $value->site_id,
					'run_uuid'            => $value->run_uuid,
					'status'              => 'accepted',
					'runner_execution_id' => null,
				)
			)
		);
	};
	add_filter( 'upload_dir', $uploads );
	add_filter( 'rest_url', $rest_url, 10, 2 );
	add_filter( 'pre_http_request', $transport, 10, 3 );
	try {
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$users[] = odvr_admin_check(
				wp_insert_user(
					array(
						'user_login' => 'odvr-api-' . $role . '-' . wp_generate_uuid4(),
						'user_pass'  => wp_generate_password( 32 ),
						'role'       => $role,
					)
				),
				'試験ユーザーを作成する'
			); }
		$admin = $users[0];
		odvr_admin_check( 401 === odvr_admin_request( 0, 'GET', '/suites' )->get_status(), '未ログイン・Bearerなしは拒否する' );
		odvr_admin_check( 403 === odvr_admin_request( $admin, 'GET', '/suites', null, array(), array( 'X-WP-Nonce' => 'invalid' ) )->get_status(), '不正nonceを拒否する' );
		odvr_admin_check( 403 === odvr_admin_request( $users[1], 'GET', '/suites' )->get_status(), 'manage_odvrのないユーザーを拒否する' );
		odvr_admin_check( 403 === odvr_admin_request( $admin, 'GET', '/suites', null, array(), array( 'Authorization' => 'Bearer ' . str_repeat( 'A', 43 ) ) )->get_status(), 'Runner Tokenで管理APIを許可しない' );
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'GET', '/suites', null, array(), array( 'Authorization' => 'Basic fixture-frontend' ) )->get_status(), 'Frontend BasicがあってもCookie・nonceの管理認証を維持する' );
		odvr_admin_check( 401 === odvr_admin_request( 0, 'GET', '/suites', null, array(), array( 'Authorization' => 'Basic fixture-frontend' ) )->get_status(), 'Basicだけで管理権限を与えない' );
		global $wp_rest_application_password_status;
		$application_status                  = $wp_rest_application_password_status;
		$wp_rest_application_password_status = get_user_by( 'id', $admin );
		try {
			odvr_admin_check( 403 === odvr_admin_request( $admin, 'GET', '/suites' )->get_status(), 'WordPress Application Passwordの認証を管理APIで拒否する' ); } finally {
			$wp_rest_application_password_status = $application_status; }

			odvr_admin_check( 403 === odvr_admin_request( $admin, 'GET', '/suites', null, array( '_wpnonce' => 'fixture' ) )->get_status(), 'nonceをURLで受け付けない' );
			foreach ( array( array( 'page' => '01' ), array( 'per_page' => '101' ), array( 'site_id' => 'other' ) ) as $query ) {
				$invalid = odvr_admin_request( $admin, 'GET', '/suites', null, $query );
				odvr_admin_check( 400 === $invalid->get_status(), '不正ページング・別サイト指定を拒否する: ' . $invalid->get_status() . ' ' . ( $invalid->get_data()['code'] ?? 'unknown' ) ); }
			$input = odvr_admin_fixture( 'suite-create-request' );
			foreach ( array( 'first', 'second' ) as $name ) {
				$input->name = 'odvr-api-' . $name;
				$response    = odvr_admin_request( $admin, 'POST', '/suites', $input );
				odvr_admin_check( 201 === $response->get_status(), 'Suiteを管理APIから保存する' );
				$suite_ids[] = $response->get_data()['item']->id;
			}
			$suite_id        = $suite_ids[0];
			$target          = odvr_admin_fixture( 'target-create-request' );
			$target->enabled = true;
			$response        = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/targets', $target );
			odvr_admin_check( 201 === $response->get_status(), 'Targetを保存する' );
			$target_id = $response->get_data()['item']->id;
			odvr_admin_check(
				200 === odvr_admin_request(
					$admin,
					'PATCH',
					'/suites/' . $suite_id,
					(object) array(
						'schema_version' => 1,
						'name'           => '編集済みfixture',
					)
				)->get_status(),
				'SuiteをPATCHする'
			);
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'DELETE', '/suites/' . $suite_id . '/targets/' . $target_id )->get_status(), 'Targetを論理削除する' );
		odvr_admin_check(
			409 === odvr_admin_request(
				$admin,
				'PATCH',
				'/suites/' . $suite_id . '/targets/' . $target_id,
				(object) array(
					'schema_version' => 1,
					'enabled'        => true,
				)
			)->get_status(),
			'削除済みTargetの復活を拒否する'
		);
		$replacement = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/targets', $target );
		odvr_admin_check( 201 === $replacement->get_status(), 'Target再追加は新IDを作る' );
		$target_id = $replacement->get_data()['item']->id;

		$device         = odvr_admin_fixture( 'device-create-request' );
		$device->slug   = 'api-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$created_device = odvr_admin_request( $admin, 'POST', '/devices', $device );
		odvr_admin_check( 201 === $created_device->get_status(), 'Deviceを管理APIから作成する' );
		$device_id    = $created_device->get_data()['item']->id;
		$device_ids[] = $device_id;
		odvr_admin_check(
			200 === odvr_admin_request( $admin, 'GET', '/devices/' . $device_id )->get_status() && 200 === odvr_admin_request(
				$admin,
				'PATCH',
				'/devices/' . $device_id,
				(object) array(
					'schema_version' => 1,
					'name'           => '編集済みDevice',
				)
			)->get_status(),
			'Deviceの取得・更新を接続する'
		);
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'DELETE', '/devices/' . $device_id )->get_status(), '未参照Deviceを無効化する' );
		odvr_admin_check( 409 === odvr_admin_request( $admin, 'DELETE', '/devices/1' )->get_status(), 'Suiteが参照するDeviceを無効化しない' );

		odvr_admin_check(
			404 === odvr_admin_request(
				$admin,
				'PATCH',
				'/suites/' . $suite_ids[1] . '/targets/' . $target_id,
				(object) array(
					'schema_version' => 1,
					'label'          => 'other',
				)
			)->get_status(),
			'別SuiteのTargetを変更しない'
		);
		$list = odvr_admin_request( $admin, 'GET', '/suites', null, array( 'per_page' => '1' ) );
		if ( 200 !== $list->get_status() ) {
			WP_CLI::log( '一覧失敗: ' . ( $list->get_data()['code'] ?? 'unknown' ) ); }
		odvr_admin_check( 200 === $list->get_status() && 1 === count( $list->get_data()->items ) && $list->get_headers()['X-WP-Total'] >= 2 && 'private, no-store' === $list->get_headers()['Cache-Control'], '一覧の件数・総数・no-storeを返す' );
		$response = odvr_admin_request( $admin, 'GET', '/settings' );
		odvr_admin_check( 200 === $response->get_status() && $response->get_data()['item']->dispatcher_secret_configured && $response->get_data()['item']->http_auth_configured, 'Settingsは秘密の有無とOriginだけを表示する' );
		foreach ( array( 'shared_secret', 'runner_token', 'http_auth_password' ) as $secret_field ) {
			odvr_admin_check(
				400 === odvr_admin_request(
					$admin,
					'PATCH',
					'/settings',
					(object) array(
						'schema_version' => 1,
						$secret_field    => 'fixture',
					)
				)->get_status(),
				'秘密のPATCHを拒否する'
			); }
		odvr_admin_check(
			400 === odvr_admin_request(
				$admin,
				'PATCH',
				'/settings',
				(object) array(
					'schema_version' => 1,
					'dispatcher_url' => 'https://arbitrary.example.com',
				)
			)->get_status(),
			'登録外Dispatcherへ変更しない'
		);
		odvr_admin_check(
			200 === odvr_admin_request(
				$admin,
				'PATCH',
				'/settings',
				(object) array(
					'schema_version' => 1,
					'site_id'        => 'fixture-site',
				)
			)->get_status(),
			'非秘密設定を保存する'
		);
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ODVR_DB::table( 'runs' ) ) );
		odvr_admin_check(
			400 === odvr_admin_request(
				$admin,
				'POST',
				'/settings/connection-test',
				(object) array(
					'schema_version' => 1,
					'url'            => 'https://arbitrary.example.com',
				)
			)->get_status(),
			'診断に任意URLを渡さない'
		);
		$diagnosis = odvr_admin_request( $admin, 'POST', '/settings/connection-test', (object) array( 'schema_version' => 1 ) );
		odvr_admin_check( 200 === $diagnosis->get_status() && 'passed' === $diagnosis->get_data()['item']->checks->storage && 1 === $diagnoses, '登録済み設定・Storage・署名を診断する' );
		odvr_admin_check( 429 === odvr_admin_request( $admin, 'POST', '/settings/connection-test', (object) array( 'schema_version' => 1 ) )->get_status() && $before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ODVR_DB::table( 'runs' ) ) ), '診断はレート制限しRunを作らない' );
		$run_input      = (object) array(
			'schema_version' => 1,
			'baseline_mode'  => 'previous',
		);
		$stale_settings = ( new ODVR_Settings() )->saved();
		odvr_admin_check(
			( new ODVR_Settings() )->update(
				(object) array(
					'schema_version' => 1,
					'site_id'        => 'fixture-site-updated',
				)
			),
			'同時変更を模したサイト設定を保存する'
		);
		$stale = ( new ODVR_Run_Manager() )->create( $suite_id, $run_input, $admin, array( 'expected_site_settings_digest' => ODVR_Environment::digest( (object) $stale_settings ) ) );
		odvr_admin_check( is_wp_error( $stale ) && 'odvr_configuration_changed' === $stale->get_error_code() && $before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ODVR_DB::table( 'runs' ) ) ), '設定が変わった古い要求はRunの保存前に拒否する' );
		odvr_admin_check(
			( new ODVR_Settings() )->update(
				(object) array(
					'schema_version' => 1,
					'site_id'        => 'fixture-site',
				)
			),
			'Run作成前に登録設定を復元する'
		);

		$create = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input );
		if ( 202 !== $create->get_status() ) {
			WP_CLI::log( 'Run作成失敗: ' . ( $create->get_data()['code'] ?? 'unknown' ) ); }
		odvr_admin_check( 202 === $create->get_status(), 'Runを保存して署名付きDispatchを受理する' );
		$uuid = $create->get_data()['item']->run_uuid;
		odvr_admin_check(
			409 === odvr_admin_request(
				$admin,
				'PATCH',
				'/settings',
				(object) array(
					'schema_version' => 1,
					'site_id'        => 'different-site',
				)
			)->get_status(),
			'稼働Runがある間はDispatch登録識別子を変更しない'
		);
		$token  = $ledger[ $uuid ]['payload']->runner_token;
		$bearer = 'Bearer ' . $token;
		if ( is_multisite() ) {
			$network = get_network();
			$child   = wp_insert_site(
				array(
					'domain'     => $network->domain,
					'path'       => '/odvr-api-' . wp_generate_uuid4() . '/',
					'network_id' => $network->id,
				)
			);
			odvr_admin_check( ! is_wp_error( $child ), 'サイト境界用の子サイトを作成する' );
			$parent_token = new ODVR_Runner_Auth();
			switch_to_blog( $child );
			try {
				ODVR_Activator::activate();
				odvr_admin_check( is_wp_error( ( new ODVR_Runner_Auth() )->authorize( $bearer, $uuid, 'manifest' ) ) && is_wp_error( $parent_token->authorize( $bearer, $uuid, 'manifest' ) ), '別サイトのRun・Token・親インスタンス再利用を拒否する' );
				odvr_admin_check( 403 === odvr_admin_request( $admin, 'GET', '/suites' )->get_status(), '別サイトの管理権限のないCookieを拒否する' );
			} finally {
				restore_current_blog();
				wp_delete_site( $child );
			}
		}

		odvr_admin_check( 409 === odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input )->get_status(), '同Suiteの重複Runを作らない' );
		$auth = new ODVR_Runner_Auth();
		odvr_admin_check( ! is_wp_error( $auth->authorize( $bearer, $uuid, 'manifest' ) ) && is_wp_error( $auth->authorize( $bearer, $uuid, 'credentials' ) ), 'queuedはManifestだけを許可する' );
		foreach ( array( '', 'Bearer short', 'Bearer ' . str_repeat( 'A', 43 ), 'Basic fixture' ) as $bad ) {
			odvr_admin_check( is_wp_error( $auth->authorize( $bad, $uuid, 'manifest' ) ), 'Token不在・形式不正・別Tokenを同じ401で拒否する' ); }
		odvr_admin_check( 401 === odvr_admin_request( 0, 'GET', '/runner/runs/' . $uuid . '/manifest', null, array( 'token' => $token ) )->get_status() || 400 === odvr_admin_request( 0, 'GET', '/runner/runs/' . $uuid . '/manifest', null, array( 'token' => $token ) )->get_status(), 'queryのTokenを認証に使わない' );
		odvr_admin_check(
			401 === odvr_admin_request(
				0,
				'GET',
				'/runner/runs/' . $uuid . '/manifest',
				null,
				array(),
				array(
					'Authorization'       => $bearer,
					'X-ODVR-Execution-ID' => 'fixture-execution',
				),
				false
			)->get_status(),
			'HTTPではBearerを受け付けない'
		);
		$manifest = odvr_admin_request(
			0,
			'GET',
			'/runner/runs/' . $uuid . '/manifest',
			null,
			array(),
			array(
				'Authorization'       => $bearer,
				'X-ODVR-Execution-ID' => 'fixture-execution',
			)
		);
		odvr_admin_check( 200 === $manifest->get_status(), 'ManifestでrunningとExecutionを確定する' );
		odvr_admin_check(
			409 === odvr_admin_request(
				0,
				'GET',
				'/runner/runs/' . $uuid . '/manifest',
				null,
				array(),
				array(
					'Authorization'       => $bearer,
					'X-ODVR-Execution-ID' => 'other-execution',
				)
			)->get_status(),
			'別Executionを拒否する'
		);
		$credentials = odvr_admin_request( 0, 'GET', '/runner/runs/' . $uuid . '/credentials', null, array(), array( 'Authorization' => $bearer ) );
		odvr_admin_check( 200 === $credentials->get_status() && ODVR_HTTP_AUTH_PASSWORD === $credentials->get_data()->http_auth->password && 'private, no-store' === $credentials->get_headers()['Cache-Control'], '秘密は自Run runningのCredentialsだけで返す' );

		$cors_priority = has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		$cors_request  = new WP_REST_Request( 'GET', '/odvr/v1/runner/runs/' . $uuid . '/credentials' );
		odvr_admin_check( false === ( new ODVR_Runner_Controller() )->disable_cors( false, $credentials, $cors_request, rest_get_server() ) && false === has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'RunnerはWordPressのブラウザ向けCORS公開を無効にする' );
		if ( false !== $cors_priority ) {
			add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers', $cors_priority ); }
		foreach ( array( 'manifest', 'credentials', 'baseline', 'upload', 'progress', 'complete' ) as $scope ) {
			odvr_admin_check( ! is_wp_error( $auth->authorize( $bearer, $uuid, $scope ) ), 'runningの自Run操作だけを許可する' ); }
		$snapshots = odvr_admin_request( $admin, 'GET', '/runs/' . $uuid . '/snapshots' );
		odvr_admin_check( 200 === $snapshots->get_status() && 'PENDING' === $snapshots->get_data()->items[0]->status && false === $snapshots->get_data()->items[0]->has_current_image, '固定SnapshotのPENDING・画像有無を返す' );
		$finish                      = odvr_admin_fixture( 'complete-request' );
		$finish->runner_execution_id = 'fixture-execution';
		$finish->outcome             = 'failed';
		$finish->error_code          = 'RUN_ABORTED';
		$finish->error_message       = ODVR_Run_Manager::error_message( 'RUN_ABORTED' );
		$manager                     = new ODVR_Run_Manager();
		odvr_admin_check( $manager->finish( $uuid, $finish ), 'Runner報告failedを確定してTokenをComplete再送用に残す' );
		odvr_admin_check( ! is_wp_error( $auth->authorize( $bearer, $uuid, 'complete' ) ) && is_wp_error( $auth->authorize( $bearer, $uuid, 'progress' ) ) && is_wp_error( $auth->authorize( $bearer, $uuid, 'manifest' ) ), '終端後はCompleteだけを許可する' );
		odvr_admin_check( $manager->finish( $uuid, $finish ), '同一Completeを再送できる' );
		$changed                = clone $finish;
		$changed->error_code    = 'DISPATCH_TIMEOUT';
		$changed->error_message = ODVR_Run_Manager::error_message( 'DISPATCH_TIMEOUT' );
		odvr_admin_check( is_wp_error( $manager->finish( $uuid, $changed ) ), '別内容のComplete再送は拒否する' );
		$run_id = ( new ODVR_Run_Repository() )->resolve_uuid( $uuid );
		$wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_token_expires_at' => ODVR_DB::utc_now() ), array( 'id' => $run_id ) );
		odvr_admin_check( is_wp_error( $auth->authorize( $bearer, $uuid, 'complete' ) ), 'TTL境界でComplete再送も失効する' );
		odvr_admin_check( $auth->clean_expired(), '期限切れの終端Hashを巡回で回収する' );
		odvr_admin_check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT runner_token_hash FROM %i WHERE id = %d', ODVR_DB::table( 'runs' ), $run_id ) ), 'Hashの回収を確認する' );
		$mode       = 'unknown';
		$post_count = count( $posts );
		$lost       = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input );
		odvr_admin_check( 202 === $lost->get_status() && 'queued' === $lost->get_data()['item']->status && count( $posts ) === $post_count + 2, '応答喪失でも同一本文だけを限定再送しqueuedを保持する' );
		$lost_uuid = $lost->get_data()['item']->run_uuid;
		odvr_admin_check( is_wp_error( $auth->authorize( $bearer, $lost_uuid, 'manifest' ) ), '別RunのTokenを拒否する' );
		$count_before_lookup = count( $posts );
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'GET', '/runs/' . $lost_uuid )->get_status() && count( $posts ) === $count_before_lookup && 1 === $lookups, '別PHP要求相当のGETは照合だけで新POSTを送らない' );
		odvr_admin_check( $manager->fail_run( $lost_uuid ), '照合済み試験Runを閉じる' );
		$mode     = 'rejected';
		$rejected = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input );
		odvr_admin_check( 202 === $rejected->get_status() && 'queued' === $rejected->get_data()['item']->status && 'failed' === ( new ODVR_Run_Repository() )->get( ( new ODVR_Run_Repository() )->resolve_uuid( $rejected->get_data()['item']->run_uuid ) )['status'], '明確な受付拒否はfailedに確定する' );
		$rejected_uuid = $rejected->get_data()['item']->run_uuid;
		odvr_admin_check( is_wp_error( $auth->authorize( 'Bearer ' . $ledger[ $rejected_uuid ]['payload']->runner_token, $rejected_uuid, 'complete' ) ), '管理側failedのTokenは即時失効する' );
		$mode             = 'accepted';
		$complete_created = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input );
		odvr_admin_check( 202 === $complete_created->get_status(), 'Baseline用Runを管理APIで作成する' );
		$complete_uuid   = $complete_created->get_data()['item']->run_uuid;
		$complete_id     = ( new ODVR_Run_Repository() )->resolve_uuid( $complete_uuid );
		$complete_bearer = 'Bearer ' . $ledger[ $complete_uuid ]['payload']->runner_token;
		odvr_admin_check(
			200 === odvr_admin_request(
				0,
				'GET',
				'/runner/runs/' . $complete_uuid . '/manifest',
				null,
				array(),
				array(
					'Authorization'       => $complete_bearer,
					'X-ODVR-Execution-ID' => 'fixture-execution',
				)
			)->get_status(),
			'Baseline用Runを開始する'
		);
		$progress                      = odvr_admin_fixture( 'progress-request' );
		$progress->runner_execution_id = 'fixture-execution';
		odvr_admin_check( $manager->progress( $complete_uuid, $progress ), 'fixture撮影前に実行Versionを保存する' );
		$snapshot          = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d', ODVR_DB::table( 'snapshots' ), $complete_id ), ARRAY_A );
		$metadata          = json_decode( $snapshot['metadata'] );
		$result            = odvr_admin_fixture( 'snapshot-result' );
		$result->target_id = (int) $snapshot['target_id'];
		$result->device_id = (int) $snapshot['device_id'];
		$result->width     = 1;
		$result->height    = 1;
		// Upload APIは#33で接続する。この試験は既知の確定PNGをfixtureとして保存する.
		$pixel = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagepng( $pixel );
		$png = ob_get_clean();
		imagedestroy( $pixel );
		$storage                 = new ODVR_Storage();
		$ticket                  = odvr_admin_check( $storage->stage( $complete_uuid, $png, 1, 1 ), 'Baseline fixture PNGを保存する' );
		$suite_row               = $wpdb->get_row( $wpdb->prepare( 'SELECT uuid FROM %i WHERE id = %d', ODVR_DB::table( 'suites' ), $suite_id ), ARRAY_A );
		$digest                  = ODVR_Environment::digest( $result );
		$path                    = odvr_admin_check(
			$storage->with_run_lock(
				$complete_uuid,
				true,
				function () use ( $storage, $ticket, $suite_row, $metadata, $digest ) {
					return $storage->promote( $ticket, $suite_row['uuid'], $metadata->target->id, $metadata->device->slug, $digest );
				}
			),
			'fixture画像を不変パスへ確定する'
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
				'duration_ms' => 0,
				'http_status' => null,
				'metadata'    => wp_json_encode( $metadata ),
			),
			array( 'id' => (int) $snapshot['id'] )
		);
		$completed                      = odvr_admin_fixture( 'complete-request' );
		$completed->runner_execution_id = 'fixture-execution';
		odvr_admin_check( $manager->finish( $complete_uuid, $completed ), 'fixture撮影済みRunをcompleteへ確定する' );
		odvr_admin_check(
			404 === odvr_admin_request(
				$admin,
				'POST',
				'/suites/' . $suite_ids[1] . '/baseline',
				(object) array(
					'schema_version' => 1,
					'run_id'         => $complete_id,
				)
			)->get_status(),
			'別SuiteのBaseline昇格を拒否する'
		);
		$pinned = odvr_admin_request(
			$admin,
			'POST',
			'/suites/' . $suite_id . '/baseline',
			(object) array(
				'schema_version' => 1,
				'run_id'         => $complete_id,
			)
		);
		odvr_admin_check( 200 === $pinned->get_status() && $complete_id === $pinned->get_data()['item']->baseline_run_id, '同Suiteの可読PNGをBaselineへ昇格する' );
		odvr_admin_check( 409 === odvr_admin_request( $admin, 'DELETE', '/runs/' . $complete_uuid )->get_status(), 'Pinned Runを管理APIから削除しない' );
		odvr_admin_check( ! is_wp_error( $auth->authorize( $complete_bearer, $complete_uuid, 'complete' ) ) && is_wp_error( $auth->authorize( $complete_bearer, $complete_uuid, 'credentials' ) ), 'complete後のTokenもCompleteだけに限定する' );
		$capacity = odvr_admin_request( $admin, 'GET', '/settings' )->get_data()['item']->storage_bytes;
		odvr_admin_check( is_int( $capacity ) && $capacity >= strlen( $png ), '私有保存領域の使用量を表示する' );

		$second_target       = clone $target;
		$second_target->url .= '/second';
		odvr_admin_check( 201 === odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/targets', $second_target )->get_status(), '部分完了用の2つ目のTargetを追加する' );
		$partial_created = odvr_admin_request( $admin, 'POST', '/suites/' . $suite_id . '/runs', $run_input );
		odvr_admin_check( 202 === $partial_created->get_status(), '部分完了用Runを作成する' );
		$partial_uuid   = $partial_created->get_data()['item']->run_uuid;
		$partial_id     = ( new ODVR_Run_Repository() )->resolve_uuid( $partial_uuid );
		$partial_bearer = 'Bearer ' . $ledger[ $partial_uuid ]['payload']->runner_token;
		odvr_admin_check(
			200 === odvr_admin_request(
				0,
				'GET',
				'/runner/runs/' . $partial_uuid . '/manifest',
				null,
				array(),
				array(
					'Authorization'       => $partial_bearer,
					'X-ODVR-Execution-ID' => 'fixture-execution',
				)
			)->get_status(),
			'部分完了用Runを開始する'
		);
		odvr_admin_check( $manager->progress( $partial_uuid, $progress ), '部分完了用RunのVersionを保存する' );
		$partial_rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id', ODVR_DB::table( 'snapshots' ), $partial_id ), ARRAY_A );
		$image_snapshot = null;
		foreach ( $partial_rows as $partial_row ) {
			$meta                      = json_decode( $partial_row['metadata'] );
			$partial_result            = clone $result;
			$partial_result->target_id = (int) $partial_row['target_id'];
			$partial_result->device_id = (int) $partial_row['device_id'];
			$data                      = array();
			if ( $target_id === $partial_result->target_id ) {
				$partial_result->status          = 'UNCHANGED';
				$partial_result->baseline_width  = 1;
				$partial_result->baseline_height = 1;
				$partial_result->diff_pixels     = 0;
				$partial_result->total_pixels    = 1;
				$partial_result->diff_ratio      = 0;
				$partial_digest                  = ODVR_Environment::digest( $partial_result );
				foreach ( array( 'image', 'diff' ) as $kind ) {
					$partial_ticket = odvr_admin_check( $storage->stage( $partial_uuid, $png, 1, 1 ), '部分完了fixtureのPNGを検証する' );
					$partial_path   = odvr_admin_check(
						$storage->with_run_lock(
							$partial_uuid,
							true,
							function () use ( $storage, $partial_ticket, $suite_row, $meta, $partial_digest, $kind ) {
								return $storage->promote( $partial_ticket, $suite_row['uuid'], $meta->target->id, $meta->device->slug, $partial_digest, 'diff' === $kind );
							}
						),
						'部分完了fixtureのPNGを保存する'
					);
					$data[ 'image' === $kind ? 'image_path' : 'diff_path' ] = $partial_path;
				}
				$meta->image_sha256 = hash( 'sha256', $png );
				$meta->diff_sha256  = hash( 'sha256', $png );
				$image_snapshot     = (int) $partial_row['id'];
			} else {
				$partial_result->status        = 'ERROR';
				$partial_result->width         = null;
				$partial_result->height        = null;
				$partial_result->error_code    = 'SNAPSHOT_FAILED';
				$partial_result->error_message = ODVR_Run_Manager::error_message( 'SNAPSHOT_FAILED' );
			}
			odvr_admin_check( ( new ODVR_Contract_Validator() )->validate( 'snapshot-result', $partial_result ), '部分完了fixtureの結果を契約検証する' );
			$meta->result        = $partial_result;
			$meta->result_digest = ODVR_Environment::digest( $partial_result );
			foreach ( array( 'status', 'width', 'height', 'baseline_width', 'baseline_height', 'duration_ms', 'http_status', 'diff_pixels', 'total_pixels', 'diff_ratio', 'error_code', 'error_message' ) as $field ) {
				$data[ $field ] = $partial_result->$field; }
			$data['metadata'] = wp_json_encode( $meta );
			$wpdb->update( ODVR_DB::table( 'snapshots' ), $data, array( 'id' => (int) $partial_row['id'] ) );
		}
		$partial = odvr_admin_check( $manager->finish( $partial_uuid, $completed ), '成功・ERRORのあるRunを確定する' );
		odvr_admin_check( 'partial' === $partial->status && ! is_wp_error( $auth->authorize( $partial_bearer, $partial_uuid, 'complete' ) ) && is_wp_error( $auth->authorize( $partial_bearer, $partial_uuid, 'manifest' ) ), 'partialもComplete再送だけに限定する' );
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'GET', '/snapshots/' . $image_snapshot . '/image' )->get_status(), '部分完了Runの成功画像も管理APIで配信する' );

		$history   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d', ODVR_DB::table( 'runs' ), $suite_id ), ARRAY_A );
		$persisted = wp_json_encode( $history ) . wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'odvr_' ) . '%' ), ARRAY_A ) );
		foreach ( $ledger as $entry ) {
			odvr_admin_check( false === strpos( $persisted, $entry['payload']->runner_token ), '平文Tokenを履歴・設定へ保存しない' ); }
		odvr_admin_check( 200 === odvr_admin_request( $admin, 'DELETE', '/suites/' . $suite_ids[1] )->get_status(), 'Suiteのアーカイブを接続する' );
		odvr_admin_check( false === strpos( $persisted, ODVR_DISPATCHER_SHARED_SECRET ) && false === strpos( $persisted, ODVR_HTTP_AUTH_PASSWORD ), 'Shared SecretとBasic passwordを永続化しない' );
		odvr_admin_check( 202 === odvr_admin_request( $admin, 'DELETE', '/runs/' . $uuid )->get_status() && 'deleting' === ( new ODVR_Run_Repository() )->get( $run_id )['status'], '管理DELETEで削除中を確定する' );
	} finally {
		remove_filter( 'pre_http_request', $transport );
		remove_filter( 'rest_url', $rest_url );
		foreach ( $suite_ids as $id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d', ODVR_DB::table( 'runs' ), $id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$wpdb->delete( ODVR_DB::table( 'snapshots' ), array( 'run_id' => $row['id'] ) ); }
			$wpdb->delete( ODVR_DB::table( 'runs' ), array( 'suite_id' => $id ) );
			$wpdb->delete( ODVR_DB::table( 'targets' ), array( 'suite_id' => $id ) );
			$wpdb->delete( ODVR_DB::table( 'suites' ), array( 'id' => $id ) );
		}
		foreach ( $device_ids as $id ) {
			$wpdb->delete( ODVR_DB::table( 'devices' ), array( 'id' => $id ) ); }
		foreach ( $users as $id ) {
			wp_delete_user( $id ); }
		foreach ( $options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false ); }
		}
		remove_filter( 'upload_dir', $uploads );
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() ); }
		}
		rmdir( $directory );
		wp_set_current_user( $original_user );
		if ( null === $original_cookie ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $original_cookie; }
		ini_set( 'memory_limit', $memory );
	}
}
try {
	odvr_test_admin_api();
	WP_CLI::success( '管理API・Run Token・署名Dispatchの検証が完了しました。' );
} catch ( Throwable $odvr_test_error ) {
	WP_CLI::error( $odvr_test_error->getMessage() );
}
