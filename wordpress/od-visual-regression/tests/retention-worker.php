<?php
/**
 * 隔離fixtureへ独立接続して削除・昇格を実行する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; }
// 試験prefix・一時Uploads・障壁だけを使用する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.WP.AlternativeFunctions, WordPress.PHP.IniSet.memory_limit_Disallowed

/**
 * 独立した同時操作。
 *
 * @param array $input 試験引数.
 * @return void
 */
function odvr_retention_worker( $input ) {
	global $wpdb;
	ini_set( 'memory_limit', '1024M' );
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$wpdb->set_prefix( $input[0] );
	$wpdb->set_blog_id( get_current_blog_id() );
	$wpdb->suppress_errors( true );
	wp_cache_flush();
	add_filter(
		'upload_dir',
		function ( $uploads ) use ( $input ) {
			$uploads['basedir'] = $input[1];
			$uploads['baseurl'] = 'https://fixture.test/uploads';
			return $uploads;
		}
	);
	file_put_contents( $input[6], 'ready' );
	$deadline = microtime( true ) + 15;
	while ( ! file_exists( $input[5] ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
		clearstatcache(); }
	if ( 'promote' === $input[4] ) {
		$result = ( new ODVR_Run_Manager() )->promote_baseline( (int) $input[2], (int) $input[3] ); } elseif ( 'request' === $input[4] ) {
		$result = ( new ODVR_Retention() )->request( (int) $input[2], array( (int) $input[3] ) ); } else {
			$result = ( new ODVR_Retention() )->resume( (int) $input[3] ); }
		WP_CLI::line( wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'success' => true ) ) );
}
odvr_retention_worker( $args );
