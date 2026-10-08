<?php
/**
 * Runner向けの検証済みPNG応答。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 配信開始前に共有ロック内で検証したPNGを保持する。 */
final class ODVR_Runner_PNG_Response extends WP_REST_Response {
	/**
	 * PNG bytes。JSONデータには含めない。
	 *
	 * @var string
	 */
	public $png;
}
