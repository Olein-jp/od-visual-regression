<?php
/**
 * 実子サイトのStorageと公開拒否の分離を検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// 試験用子サイトだけを作り、WordPressの完全削除で片付ける.
// phpcs:disable WordPress.PHP.IniSet.memory_limit_Disallowed, WordPress.WP.AlternativeFunctions

/**
 * 試験条件を検査する。
 *
 * @param bool   $valid 条件.
 * @param string $message 説明.
 * @return void
 * @throws RuntimeException 失敗時.
 */
function odvr_ms_storage_assert( $valid, $message ) {
	if ( ! $valid ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * 実Multisiteで保存先と診断が混在しないことを確認する。
 *
 * @return void
 */
function odvr_test_multisite_storage() {
	odvr_ms_storage_assert( is_multisite(), 'Multisite実機でStorageを検証する' );
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	$old_memory = ini_get( 'memory_limit' );
	ini_set( 'memory_limit', '1024M' );
	define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
	$parent         = new ODVR_Storage();
	$parent_uploads = wp_upload_dir();
	$parent_ready   = get_option( 'odvr_storage_ready', null );
	$network        = get_network();
	$site           = wp_insert_site(
		array(
			'domain'     => $network->domain,
			'path'       => $network->path . 'odvr-storage-' . wp_generate_uuid4() . '/',
			'network_id' => $network->id,
		)
	);
	odvr_ms_storage_assert( ! is_wp_error( $site ), 'Storage試験用の子サイトを作成する' );
	$filter = function ( $uploads ) {
		$path               = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$uploads['baseurl'] = 'http://tests-wordpress' . $path;
		return $uploads;
	};
	add_filter( 'upload_dir', $filter );
	try {
		switch_to_blog( $site );
		try {
			ODVR_Activator::activate();
			odvr_ms_storage_assert( is_wp_error( $parent->ready() ), '親Storageインスタンスの子サイト再利用を拒否する' );
			$uploads = wp_upload_dir();
			odvr_ms_storage_assert( $parent_uploads['basedir'] !== $uploads['basedir'] && false === get_option( 'odvr_storage_ready', false ), '子サイトの保存先と診断Optionsが独立する' );
			$child = new ODVR_Storage();
			odvr_ms_storage_assert( true === $child->diagnose(), '子サイトのuploads/sites経路のcanaryを実Apacheが拒否する' );
			$image = imagecreatetruecolor( 1, 1 );
			ob_start();
			imagepng( $image );
			$png = ob_get_clean();
			imagedestroy( $image );
			$uuid   = wp_generate_uuid4();
			$staged = $child->stage( $uuid, $png, 1, 1 );
			odvr_ms_storage_assert( is_array( $staged ) && file_exists( $uploads['basedir'] . '/od-visual-regression/.staging/' . $uuid . '/' . $staged['request_uuid'] . '.png' ), '子サイトだけのstagingへ保存する' );
			odvr_ms_storage_assert( 0 === $child->cleanup( $uuid ), '新しい子サイトstagingを保持する' );
			touch( $uploads['basedir'] . '/od-visual-regression/.staging/' . $uuid . '/' . $staged['request_uuid'] . '.png', time() - 3700 );
			odvr_ms_storage_assert( 1 === $child->cleanup( $uuid ), 'Run未作成の孤立stagingを排他cleanupする' );
			odvr_ms_storage_assert( true === $child->cleanup_cycle(), '子サイトで有限件数の定期cleanupを実行できる' );
		} finally {
			restore_current_blog();
		}
		odvr_ms_storage_assert( get_option( 'odvr_storage_ready', null ) === $parent_ready && ! file_exists( $parent_uploads['basedir'] . '/od-visual-regression/.staging/' . $uuid ), '子サイトの診断・保存・cleanupで親サイトを変更しない' );
	} finally {
		remove_filter( 'upload_dir', $filter );
		wpmu_delete_blog( $site, true );
		ini_set( 'memory_limit', $old_memory );
	}
	WP_CLI::success( 'MultisiteのStorage分離検証が完了しました。' );
}
odvr_test_multisite_storage();
