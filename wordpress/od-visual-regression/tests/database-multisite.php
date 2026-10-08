<?php
/**
 * Multisiteのサイト単位導入・分離・完全削除を実機検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// 各サイトに作成したテストテーブルを直接検査する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * 検証失敗を例外にする。
 *
 * @param bool   $condition 条件.
 * @param string $message 検証内容.
 * @return void
 * @throws RuntimeException 条件不成立.
 */
function odvr_ms_db_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * ネットワーク拒否のwp_dieを捕捉する。
 *
 * @param string $message メッセージ.
 * @return void
 * @throws RuntimeException 拒否メッセージ.
 */
function odvr_ms_db_die( $message ) {
	throw new RuntimeException( esc_html( $message ) );
}

/**
 * テスト専用のwp_die handlerを返す。
 *
 * @return callable ハンドラー.
 */
function odvr_ms_db_die_handler() {
	return 'odvr_ms_db_die';
}

/**
 * 現在サイトと新規子サイトの分離を確認する。
 *
 * @return void
 * @throws RuntimeException 失敗.
 */
function odvr_test_multisite_database() {
	global $wpdb;
	odvr_ms_db_assert( is_multisite(), 'Multisite実機で検証する' );
	$parent_id      = get_current_blog_id();
	$parent_table   = ODVR_DB::table( 'devices' );
	$parent_rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $parent_table ), ARRAY_A );
	$parent_version = get_option( 'odvr_db_version' );
	$parent_cap     = get_role( 'administrator' )->has_cap( 'manage_odvr' );
	$rejected       = false;
	add_filter( 'wp_die_handler', 'odvr_ms_db_die_handler', 999 );
	try {
		ODVR_Activator::activate( true );
	} catch ( RuntimeException $error ) {
		$rejected = false !== strpos( $error->getMessage(), 'サイト単位' );
	} finally {
		remove_filter( 'wp_die_handler', 'odvr_ms_db_die_handler', 999 );
	}
	odvr_ms_db_assert( $rejected && get_option( 'odvr_db_version' ) === $parent_version && get_role( 'administrator' )->has_cap( 'manage_odvr' ) === $parent_cap, 'ネットワーク有効化をデータ・権限の変更前に拒否する' );
	$network = get_network();
	$site_id = wp_insert_site(
		array(
			'domain'     => $network->domain,
			'path'       => $network->path . 'odvrtest-' . wp_generate_uuid4() . '/',
			'network_id' => $network->id,
		)
	);
	odvr_ms_db_assert( ! is_wp_error( $site_id ), '検証用の新規子サイトを作成できる' );
	$child_tables = array();
	try {
		switch_to_blog( $site_id );
		try {
			$child_table = ODVR_DB::table( 'devices' );
			foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
				$child_tables[] = ODVR_DB::table( $suffix );
			}
			odvr_ms_db_assert( $child_table !== $parent_table && false === get_option( 'odvr_db_version', false ), 'サイトprefixとOptionsが親サイトから独立する' );
			odvr_ms_db_assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $child_table ) ) ), '新規サイトへ自動導入しない' );
			ODVR_Activator::activate();
			odvr_ms_db_assert( true === ODVR_DB::diagnose() && get_role( 'administrator' )->has_cap( 'manage_odvr' ), 'サイト単位有効化で5テーブルと権限を導入する' );
			odvr_ms_db_assert( '3' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $child_table ) ), '子サイトも初期Deviceを独立して持つ' );
			$wpdb->update( $child_table, array( 'viewport_width' => 777 ), array( 'slug' => 'desktop' ) );
			ODVR_Deactivator::deactivate();
			odvr_ms_db_assert( '777' === $wpdb->get_var( $wpdb->prepare( 'SELECT viewport_width FROM %i WHERE slug = %s', $child_table, 'desktop' ) ), '子サイト無効化でもデータを保持する' );
		} finally {
			restore_current_blog();
		}
		odvr_ms_db_assert( get_current_blog_id() === $parent_id && $parent_rows === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $parent_table ), ARRAY_A ), '子サイト操作が親サイトの同じ数値IDのデータを変更しない' );
	} finally {
		wp_delete_site( $site_id );
	}
	foreach ( $child_tables as $table ) {
		odvr_ms_db_assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'サイト完全削除で該当ODVRテーブルだけを削除する' );
	}
	odvr_ms_db_assert( true === ODVR_DB::diagnose(), '子サイト完全削除後も親サイトのDBが利用できる' );
}

try {
	odvr_test_multisite_database();
	WP_CLI::success( 'Multisite導入・ネットワーク拒否・サイト分離・完全削除の検証が完了しました。' );
} catch ( Throwable $odvr_test_error ) {
	WP_CLI::error( $odvr_test_error->getMessage() );
}
