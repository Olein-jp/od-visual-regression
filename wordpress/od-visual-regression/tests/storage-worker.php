<?php
/**
 * 実Runロックの削除待ちを確認する独立プロセス。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// テスト専用の固定一時パスにだけ書く.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery
if ( 4 !== count( $args ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $args[0] ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $args[1] ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $args[2] ) || '/tmp/odvr-storage-' . $args[1] !== $args[3] ) {
	WP_CLI::error( 'Storage試験の引数が無効です。' );
}
global $wpdb;
$odvr_storage_worker_run = $wpdb->get_row( $wpdb->prepare( 'SELECT r.id FROM %i r JOIN %i s ON s.id = r.suite_id WHERE r.id = %d AND r.uuid = %s AND s.uuid = %s AND r.status = %s', ODVR_DB::table( 'runs' ), ODVR_DB::table( 'suites' ), $args[0], $args[1], $args[2], 'deleting' ), ARRAY_A );
if ( ! $odvr_storage_worker_run ) {
	WP_CLI::error( '試験Runを確認できません。' );
}
file_put_contents( $args[3] . '.started', '開始' );
$odvr_storage_worker_result = ( new ODVR_Storage() )->delete_run( (int) $args[0] );
file_put_contents( $args[3] . '.result', true === $odvr_storage_worker_result ? 'success' : 'failure' );
