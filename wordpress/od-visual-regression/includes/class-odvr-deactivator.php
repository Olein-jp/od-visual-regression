<?php
/**
 * 無効化時の処理。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 無効化時の一時停止を担当する。
 */
final class ODVR_Deactivator {
	/**
	 * 永続データ・画像・権限を維持したまま停止する。
	 *
	 * Storageの定期cleanupを停止する。
	 * 履歴の削除は無効化の責務に含めない。
	 *
	 * @return void
	 */
	public static function deactivate() {
		// 停止とToken失効を確定し、履歴や設定を削除しない.
		$result = ( new ODVR_Uninstaller() )->suspend();
		if ( is_wp_error( $result ) ) {
			update_option( 'odvr_uninstall_error', 'Runの停止状態を確認してください。', false ); }
		wp_clear_scheduled_hook( 'odvr_storage_cleanup' );
		wp_clear_scheduled_hook( 'odvr_run_expiry' );
	}
}
