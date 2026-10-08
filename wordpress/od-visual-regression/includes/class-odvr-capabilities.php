<?php
/**
 * 管理権限の付与と判定。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理機能の共通権限を扱う。
 */
final class ODVR_Capabilities {
	/**
	 * 管理機能に必要な権限。
	 *
	 * @var string
	 */
	const MANAGE = 'manage_odvr';

	/**
	 * Administratorへ管理権限を付与する。
	 *
	 * @return void
	 */
	public static function grant() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * 現在のユーザーが管理機能を利用できるか判定する。
	 *
	 * 管理用nonceやRunner Tokenの検証とは別に、管理機能の入口で使用する。
	 *
	 * @return bool 管理権限がある場合にtrue。
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE );
	}
}
