<?php
/**
 * 有効化時の処理。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化時に必要な準備を行う。
 */
final class ODVR_Activator {
	/**
	 * DBを導入して管理権限を付与する。再実行しても重複しない。
	 *
	 * @param bool $network_wide ネットワーク一括有効化か.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'OD Visual Regressionはサイト単位で有効化してください。ネットワーク一括有効化には対応していません。', 'od-visual-regression' ) );
		}
		$result = ODVR_DB::upgrade( true );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		ODVR_Capabilities::grant();
	}
}
