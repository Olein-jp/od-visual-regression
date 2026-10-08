<?php
/**
 * 子サイトのRun・画像・環境の分離を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() ) {
	exit; }
// 親テーブル全件を比較し、試験サイトだけを削除する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride

/**
 * 子サイトで実Run試験を行う。
 *
 * @return void
 * @throws RuntimeException サイト分離の失敗.
 */
function odvr_test_runs_multisite() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	$parent = array();
	foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
		$parent[ $suffix ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ODVR_DB::table( $suffix ) ), ARRAY_A );
	}
	$manager = new ODVR_Run_Manager();
	$network = get_network();
	$site_id = wpmu_create_blog( $network->domain, '/odvr-run-' . substr( wp_generate_uuid4(), 0, 8 ) . '/', 'Run試験', 1, array(), $network->id );
	if ( is_wp_error( $site_id ) ) {
		throw new RuntimeException( '試験サイトを作成できません。' ); }
	try {
		switch_to_blog( $site_id );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_wp_error( activate_plugin( 'od-visual-regression/od-visual-regression.php' ) ) ) {
			throw new RuntimeException( '子サイトでPluginを有効化できません。' );
		}
		if ( is_wp_error( ODVR_DB::upgrade() ) || ! is_wp_error( $manager->get( 1 ) ) ) {
			throw new RuntimeException( 'サイト境界を検証できません。' ); }
		require __DIR__ . '/runs.php';
	} finally {
		restore_current_blog();
		wpmu_delete_blog( $site_id, true );
	}
	foreach ( $parent as $suffix => $before ) {
		$after = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ODVR_DB::table( $suffix ) ), ARRAY_A );
		if ( $before !== $after ) {
			throw new RuntimeException( '親サイトの保存値が変化しました。' ); }
	}
	WP_CLI::success( '子サイトのRun・Baseline・画像と親サイトの分離を確認しました。' );
}
odvr_test_runs_multisite();
