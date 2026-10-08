<?php
/**
 * 画像配信の内部応答。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** JSONの代わりに、配信時に再確認する内部参照を保持する。 */
final class ODVR_Image_Response extends WP_REST_Response {
	/**
	 * Snapshot ID。
	 *
	 * @var int
	 */
	public $snapshot_id;
	/**
	 * 画像種別。
	 *
	 * @var string
	 */
	public $kind;
}
