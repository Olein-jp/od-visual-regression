<?php
/**
 * 開発用wp-envのWordPressで初期化・権限・翻訳を検証する。
 *
 * 実行方法: npm run env:cli -- eval-file tests/bootstrap.php
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

/**
 * 条件を満たさない場合にテストを失敗させる。
 *
 * @param bool   $condition 検証する条件.
 * @param string $message   検証内容.
 * @return void
 * @throws RuntimeException 条件を満たさない場合.
 */
function odvr_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}

	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * 翻訳検証用のロケールを返す。
 *
 * @return string 日本語のロケール。
 */
function odvr_test_japanese_locale() {
	return 'ja';
}

/**
 * 初期化・権限・翻訳・無効化の保持を検証する。
 *
 * @return void
 * @throws RuntimeException 検証に失敗した場合.
 */
function odvr_test_bootstrap() {
	global $wp_filter, $wp_filesystem;

	$plugin         = 'od-visual-regression/od-visual-regression.php';
	$was_active     = is_plugin_active( $plugin );
	$original_user  = get_current_user_id();
	$user_ids       = array();
	$marker         = wp_generate_uuid4();
	$option_name    = 'odvr_test_history_' . $marker;
	$uploads        = wp_upload_dir();
	$test_directory = $uploads['basedir'] . '/od-visual-regression/test-' . $marker;
	$image_path     = $test_directory . '/snapshot.png';

	try {
		odvr_test_assert( true === ODVR_DB::writable() && true === ODVR_DB::diagnose(), '有効化済みDBのVersion・5テーブル・索引を確認する' );
		odvr_test_assert( $was_active, '有効化済みのプラグインを検証する' );
		odvr_test_assert( WP_Filesystem(), '検証用ファイルの操作を初期化できる' );
		odvr_test_assert( ! $uploads['error'], 'Uploadsの保存先を取得できる' );
		odvr_test_assert( wp_mkdir_p( $test_directory ), '検証用の画像保存先を作成できる' );
		// ファイルは無効化時の保持確認用であり、撮影画像ではない.
		odvr_test_assert( $wp_filesystem->put_contents( $image_path, $marker ), '保持確認用ファイルを保存できる' );
		add_option( $option_name, $marker, '', false );

		deactivate_plugins( $plugin );
		get_role( 'administrator' )->remove_cap( 'manage_odvr' );
		odvr_test_assert( ! get_role( 'administrator' )->has_cap( 'manage_odvr' ), '有効化前は管理権限を外せる' );

		$activated = activate_plugin( $plugin );
		odvr_test_assert( ! is_wp_error( $activated ) && is_plugin_active( $plugin ), '通常の有効化フックが実行される' );
		odvr_test_assert( get_role( 'administrator' )->has_cap( 'manage_odvr' ), '有効化でAdministratorに管理権限を付与する' );

		ODVR_Activator::activate();
		$capabilities = get_role( 'administrator' )->capabilities;
		ODVR_Activator::activate();
		odvr_test_assert( get_role( 'administrator' )->capabilities === $capabilities, '有効化処理の再実行で権限が変わらない' );

		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( 'administrator' !== $slug ) {
				odvr_test_assert( ! get_role( $slug )->has_cap( 'manage_odvr' ), $slug . 'には管理権限を付与しない' );
			}
		}

		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => 'odvr-test-' . $role . '-' . $marker,
					'user_pass'  => wp_generate_password( 32 ),
					'role'       => $role,
				)
			);
			odvr_test_assert( ! is_wp_error( $user_id ), '検証用の' . $role . 'ユーザーを作成できる' );
			$user_ids[] = $user_id;
			wp_set_current_user( $user_id );
			odvr_test_assert( ( 'administrator' === $role ) === ODVR_Capabilities::can_manage(), $role . 'の権限判定が正しい' );
		}
		wp_set_current_user( 0 );
		odvr_test_assert( ! ODVR_Capabilities::can_manage(), '未ログインユーザーを拒否する' );

		odvr_init_plugin();
		odvr_init_plugin();
		$translation_hooks = 0;
		foreach ( $wp_filter['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof ODVR_Plugin && 'load_textdomain' === $function[1] ) {
					++$translation_hooks;
				}
			}
		}
		odvr_test_assert( 1 === $translation_hooks, '初期化を繰り返しても翻訳フックが重複しない' );

		add_filter( 'locale', 'odvr_test_japanese_locale' );
		add_filter( 'determine_locale', 'odvr_test_japanese_locale' );
		unload_textdomain( 'od-visual-regression', true );
		odvr_test_assert( 'OD ビジュアルリグレッション' === __( 'OD Visual Regression', 'od-visual-regression' ), '同梱した日本語翻訳を現在のマウント先から読み込む' );
		remove_filter( 'locale', 'odvr_test_japanese_locale' );
		remove_filter( 'determine_locale', 'odvr_test_japanese_locale' );
		unload_textdomain( 'od-visual-regression', true );

		deactivate_plugins( $plugin );
		odvr_test_assert( ! is_plugin_active( $plugin ), '通常の無効化フックが実行される' );
		odvr_test_assert( get_option( 'odvr_suspended' ) && is_wp_error( ODVR_DB::writable() ), '無効化後の書込を拒否する' );
		foreach ( array( 'odvr_retention', 'odvr_run_expiry', 'odvr_storage_cleanup' ) as $hook ) {
			odvr_test_assert( ! wp_next_scheduled( $hook ), '無効化で' . $hook . 'を停止する' );
		}
		odvr_test_assert( get_option( $option_name ) === $marker, '無効化後も履歴相当の保存値が残る' );
		odvr_test_assert( file_exists( $image_path ) && $wp_filesystem->get_contents( $image_path ) === $marker, '無効化後も画像保存先のファイルが残る' );
		odvr_test_assert( get_role( 'administrator' )->has_cap( 'manage_odvr' ), '無効化後も管理権限を保持する' );

		$activated = activate_plugin( $plugin );
		odvr_test_assert( ! is_wp_error( $activated ) && is_plugin_active( $plugin ) && true === ODVR_DB::writable(), '停止状態を解除して再有効化できる' );
		odvr_test_assert( get_role( 'administrator' )->has_cap( 'manage_odvr' ), '再有効化後も管理権限が有効である' );
	} finally {
		remove_filter( 'locale', 'odvr_test_japanese_locale' );
		remove_filter( 'determine_locale', 'odvr_test_japanese_locale' );
		unload_textdomain( 'od-visual-regression', true );
		wp_set_current_user( $original_user );
		foreach ( $user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		delete_option( $option_name );
		if ( file_exists( $image_path ) ) {
			wp_delete_file( $image_path );
		}
		if ( is_dir( $test_directory ) ) {
			$wp_filesystem->delete( $test_directory, false, 'd' );
		}
		if ( $was_active ) {
			activate_plugin( $plugin );
		} else {
			deactivate_plugins( $plugin );
		}
	}
}

try {
	odvr_test_bootstrap();
	WP_CLI::success( '初期化・権限・翻訳・履歴保持の検証が完了しました。' );
} catch ( Throwable $odvr_test_error ) {
	WP_CLI::error( $odvr_test_error->getMessage() );
}
