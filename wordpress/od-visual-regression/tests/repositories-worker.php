<?php
/**
 * Repository競合テストの独立DB接続。テスト用prefixだけを受け取る。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// テスト専用の独立プロセスで一時prefixへ切り替える.
// ローカルの試験用障壁ファイルだけを直接書く.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
if ( count( $args ) !== 5 || ! preg_match( '/^[A-Za-z0-9_]+odvrrepotest_[a-f0-9]{12}_$/D', $args[0] ) ) {
	WP_CLI::error( '競合テストの引数が無効です。' );
}
global $wpdb;
$wpdb->set_prefix( $args[0] );
wp_cache_flush();
$odvr_repository_worker_id    = (int) $args[2];
$odvr_repository_worker_ready = $args[3];
$odvr_repository_worker_mode  = $args[1];
add_filter(
	'query',
	function ( $sql ) use ( $odvr_repository_worker_ready ) {
		if ( false !== strpos( $sql, 'FOR UPDATE' ) ) {
			file_put_contents( $odvr_repository_worker_ready, '準備完了' );
		}
		return $sql;
	}
);
if ( 'device-disable' === $odvr_repository_worker_mode ) {
	$odvr_repository_worker_result = ( new ODVR_Device_Repository() )->disable( $odvr_repository_worker_id );
} elseif ( 'target-create' === $odvr_repository_worker_mode ) {
	$odvr_repository_worker_result = ( new ODVR_Target_Repository() )->create(
		$odvr_repository_worker_id,
		(object) array(
			'schema_version' => 1,
			'url'            => 'https://fixture.test/concurrent',
			'label'          => '競合対象',
			'object_id'      => null,
			'post_type'      => '',
			'enabled'        => true,
			'sort_order'     => 100,
		)
	);
} elseif ( 'suite-update' === $odvr_repository_worker_mode ) {
	$odvr_repository_worker_result = ( new ODVR_Suite_Repository() )->update(
		$odvr_repository_worker_id,
		(object) array(
			'schema_version' => 1,
			'retention'      => (object) array(
				'mode'  => 'last',
				'count' => 20,
			),
		)
	);
} else {
	WP_CLI::error( '競合テストの処理が無効です。' );
}
file_put_contents( $args[4], wp_json_encode( is_wp_error( $odvr_repository_worker_result ) ? array( 'error' => $odvr_repository_worker_result->get_error_code() ) : array( 'item' => $odvr_repository_worker_result ) ) );
