<?php
/**
 * Run同時開始を検査する独立WP-CLIプロセス。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit; }
// ランダム試験Suiteと障壁ファイルだけを利用する.
// phpcs:disable WordPress.PHP.IniSet.memory_limit_Disallowed, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions
ini_set( 'memory_limit', '1024M' );
define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
add_filter(
	'upload_dir',
	function ( $uploads ) {
		$uploads['baseurl'] = ( is_multisite() ? 'http://tests-wordpress/' : 'http://wordpress/' ) . substr( $uploads['basedir'], strlen( ABSPATH ) );
		return $uploads;
	}
);
$odvr_suite_id = (int) $args[0];
$odvr_gate     = $args[1];
$odvr_marker   = $args[2];
file_put_contents( $odvr_marker, 'ready' );
$odvr_deadline = microtime( true ) + 15;
while ( ! file_exists( $odvr_gate ) && microtime( true ) < $odvr_deadline ) {
	usleep( 10000 );
	clearstatcache(); }
$odvr_result = ( new ODVR_Run_Manager() )->create(
	$odvr_suite_id,
	(object) array(
		'schema_version' => 1,
		'baseline_mode'  => 'pinned',
	),
	1
);
WP_CLI::line(
	wp_json_encode(
		is_wp_error( $odvr_result ) ? array( 'error' => $odvr_result->get_error_code() ) : array(
			'id'   => $odvr_result['id'],
			'uuid' => $odvr_result['item']->run_uuid,
		)
	)
);
