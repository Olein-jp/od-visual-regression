<?php
/**
 * 撮影対象URLの形式検証。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom URLと投稿URLに共通の契約を提供する。
 */
final class ODVR_Target_URL {
	/**
	 * HTTP(S)の絶対URLを検証する。通信やDNS解決は行わない。
	 *
	 * @param mixed $url 検証するURL.
	 * @return string|WP_Error 安全な保存形式のURLまたは検証エラー。
	 */
	public static function validate( $url ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ||
			preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ||
			preg_match( '/%(?![0-9a-f]{2})|%0[0-9a-f]|%1[0-9a-f]|%7f/i', $url ) ||
			! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return self::invalid_url();
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ||
			! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) ) {
			return self::invalid_url();
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * 共通の検証エラーを生成する。
	 *
	 * @return WP_Error URLの形式エラー。
	 */
	private static function invalid_url() {
		return new WP_Error(
			'odvr_invalid_target_url',
			__( '資格情報を含まない有効なHTTP(S)の絶対URLを指定してください。', 'od-visual-regression' ),
			array( 'status' => 400 )
		);
	}
}
