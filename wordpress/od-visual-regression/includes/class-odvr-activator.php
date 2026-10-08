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
	 * 管理権限を付与する。再実行しても重複しない。
	 *
	 * @return void
	 */
	public static function activate() {
		ODVR_Capabilities::grant();
	}
}
