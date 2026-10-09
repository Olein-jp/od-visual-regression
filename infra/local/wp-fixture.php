<?php
/** wp-env専用の最小結合fixture。TokenやHTTP応答原文を表示しない。 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ODVR_LOCAL_FIXTURE' ) || true !== ODVR_LOCAL_FIXTURE ) { exit( 1 ); }
ini_set( 'memory_limit', '1024M' );
$phase = $args[0] ?? '';
$state_file = '/tmp/odvr-local-fixture-state.json';
function odvr_local_checked( $value ) { if ( is_wp_error( $value ) || false === $value ) { WP_CLI::error( 'local fixture の処理に失敗しました。' . ( is_wp_error( $value ) ? ' ' . $value->get_error_code() : '' ) ); } return $value; }
function odvr_local_request( $method, $route, $body ) {
	global $wp_rest_auth_cookie;
	wp_set_current_user( 1 );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( 1, time() + 3600, 'logged_in', json_decode( file_get_contents( '/tmp/odvr-local-fixture-state.json' ), true )['session_token'] );
	$wp_rest_auth_cookie = true;
	$request = new WP_REST_Request( $method, '/odvr/v1' . $route );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( null !== $body ) { $request->set_body( wp_json_encode( $body ) ); }
	$server = rest_get_server();
	$response = apply_filters( 'rest_post_dispatch', $server->dispatch( $request ), $server, $request );
	if ( $response->get_status() >= 400 ) { WP_CLI::error( 'local管理APIの検証に失敗しました。' . ( $response->get_data()['code'] ?? '' ) ); }
	return json_decode( wp_json_encode( $response->get_data() ), true );
}
if ( 'setup' === $phase ) {
	if ( file_exists( $state_file ) ) { WP_CLI::error( '既存fixtureを先にcleanupしてください。' ); }
	ODVR_Activator::activate();
	$state = array( 'settings' => get_option( 'odvr_settings', null ), 'storage_ready' => get_option( 'odvr_storage_ready', null ), 'diagnostic_lock' => get_option( 'odvr_connection_test_lock', null ), 'suite_id' => null, 'device_id' => null, 'run_uuid' => null, 'session_token' => WP_Session_Tokens::get_instance( 1 )->create( time() + 3600 ) );
	file_put_contents( $state_file, wp_json_encode( $state ) ); chmod( $state_file, 0600 );
	odvr_local_checked( ( new ODVR_Settings() )->update( (object) array( 'schema_version' => 1, 'site_id' => 'local-fixture', 'dispatcher_url' => ODVR_DISPATCHER_URL ) ) );
	$suffix = substr( wp_generate_uuid4(), 0, 8 );
	$state['device_slug'] = 'odvr-local-' . $suffix; $state['suite_name'] = 'ODVR local ' . $suffix; file_put_contents( $state_file, wp_json_encode( $state ) );
	$device = odvr_local_request( 'POST', '/devices', (object) array( 'schema_version' => 1, 'name' => 'ODVR local', 'slug' => $state['device_slug'], 'viewport_width' => 480, 'viewport_height' => 320, 'device_scale_factor' => 1, 'is_mobile' => false, 'has_touch' => false, 'user_agent' => '', 'enabled' => true, 'sort_order' => 0 ) );
	$state['device_id'] = $device['item']['id'];
	file_put_contents( $state_file, wp_json_encode( $state ) );
	$suite = odvr_local_request( 'POST', '/suites', (object) array( 'schema_version' => 1, 'name' => $state['suite_name'], 'settings' => (object) array( 'settings_version' => 1, 'navigation_timeout_ms' => 10000, 'image_timeout_ms' => 5000, 'lazy_load' => false, 'concurrency' => 2, 'pixel_threshold' => 0.1, 'review_threshold' => 0.001, 'changed_threshold' => 0.01, 'ignore_selectors' => array() ), 'device_ids' => array( $state['device_id'] ), 'allowed_origins' => array( 'https://wordpress.fixture.test:8443' ), 'retention' => (object) array( 'mode' => 'last', 'count' => 3 ) ) );
	$state['suite_id'] = $suite['item']['id']; file_put_contents( $state_file, wp_json_encode( $state ) );
	odvr_local_request( 'POST', '/suites/' . $state['suite_id'] . '/targets', (object) array( 'schema_version' => 1, 'url' => 'https://wordpress.fixture.test:8443/index.php?odvr_local_fixture=1', 'label' => 'local fixture', 'object_id' => null, 'post_type' => '', 'enabled' => true, 'sort_order' => 0 ) );
	$diagnosis = odvr_local_request( 'POST', '/settings/connection-test', (object) array( 'schema_version' => 1 ) );
	if ( 'passed' !== $diagnosis['item']['checks']['dispatcher'] || 'passed' !== $diagnosis['item']['checks']['storage'] || 'passed' !== $diagnosis['item']['checks']['settings'] ) { WP_CLI::error( 'local接続診断が成功しませんでした。' ); }
	$run = odvr_local_request( 'POST', '/suites/' . $state['suite_id'] . '/runs', (object) array( 'schema_version' => 1, 'baseline_mode' => 'previous' ) );
	$state['run_uuid'] = $run['item']['run_uuid']; file_put_contents( $state_file, wp_json_encode( $state ) );
	WP_CLI::log( wp_json_encode( array( 'run_uuid' => $state['run_uuid'], 'suite_id' => $state['suite_id'] ) ) );
} elseif ( 'status' === $phase ) {
	if ( ! file_exists( $state_file ) ) { WP_CLI::error( 'fixtureを作成してからstatusを実行してください。' ); }
	$state = json_decode( file_get_contents( $state_file ), true );
	$run = odvr_local_request( 'GET', '/runs/' . $state['run_uuid'], null );
	$snapshots = odvr_local_request( 'GET', '/runs/' . $state['run_uuid'] . '/snapshots', null );
	WP_CLI::log( wp_json_encode( array( 'run_uuid' => $state['run_uuid'], 'status' => $run['item']['status'], 'snapshot_status' => $snapshots['items'][0]['status'] ?? null, 'runner_version' => $run['item']['environment']['runner'] ?? null, 'error_code' => $snapshots['items'][0]['error_code'] ?? null ) ) );
} elseif ( 'cleanup' === $phase ) {
	if ( ! file_exists( $state_file ) ) { exit; }
	$state = json_decode( file_get_contents( $state_file ), true );
	global $wpdb;
	if ( ! $state['run_uuid'] && $state['suite_id'] ) { $state['run_uuid'] = $wpdb->get_var( $wpdb->prepare( 'SELECT uuid FROM %i WHERE suite_id = %d ORDER BY id DESC LIMIT 1', ODVR_DB::table( 'runs' ), $state['suite_id'] ) ); }
	if ( ! $state['device_id'] && isset( $state['device_slug'] ) ) { $state['device_id'] = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', ODVR_DB::table( 'devices' ), $state['device_slug'] ) ); }
	if ( ! $state['suite_id'] && isset( $state['suite_name'] ) ) { $state['suite_id'] = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE name = %s', ODVR_DB::table( 'suites' ), $state['suite_name'] ) ); }
	if ( $state['run_uuid'] ) { $run_id = odvr_local_checked( ( new ODVR_Run_Repository() )->resolve_uuid( $state['run_uuid'] ) ); odvr_local_checked( ( new ODVR_Retention() )->request( $state['suite_id'], array( $run_id ) ) ); odvr_local_checked( ( new ODVR_Retention() )->resume( $run_id ) ); }
	if ( $state['suite_id'] ) { $wpdb->delete( ODVR_DB::table( 'targets' ), array( 'suite_id' => $state['suite_id'] ) ); $wpdb->delete( ODVR_DB::table( 'suites' ), array( 'id' => $state['suite_id'] ) ); }
	if ( $state['device_id'] ) { $wpdb->delete( ODVR_DB::table( 'devices' ), array( 'id' => $state['device_id'] ) ); }
	foreach ( array( 'settings' => 'odvr_settings', 'storage_ready' => 'odvr_storage_ready', 'diagnostic_lock' => 'odvr_connection_test_lock' ) as $key => $option ) { if ( null === $state[ $key ] ) { delete_option( $option ); } else { update_option( $option, $state[ $key ] ); } }
	WP_Session_Tokens::get_instance( 1 )->destroy( $state['session_token'] );
	unlink( $state_file );
	WP_CLI::log( 'local fixtureを回収しました。' );
} else { WP_CLI::error( 'fixture phaseを確認してください。' ); }
