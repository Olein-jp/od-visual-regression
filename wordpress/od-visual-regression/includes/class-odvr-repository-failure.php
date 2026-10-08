<?php
/**
 * Repository内部のエラー保持。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository内部の安全なエラー。
 */
final class ODVR_Repository_Failure extends RuntimeException {
	/**
	 * 上位へ返すエラー。
	 *
	 * @var WP_Error
	 */
	public $failure;
	/**
	 * エラーを保持する。
	 *
	 * @param WP_Error $failure エラー.
	 */
	public function __construct( $failure ) {
		parent::__construct( $failure->get_error_code() );
		$this->failure = $failure;
	}
}
