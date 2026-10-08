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
	 * 公開投稿/CPTのURLを保存時に解決する。
	 *
	 * @param string   $url Custom URLまたは候補URL.
	 * @param int|null $object_id 投稿IDまたはnull.
	 * @param string   $post_type 投稿タイプまたは空文字.
	 * @return string|WP_Error URLまたはエラー.
	 */
	public static function resolve( $url, $object_id, $post_type ) {
		if ( null === $object_id ) {
			return '' === $post_type ? self::validate( $url ) : new WP_Error( 'odvr_invalid_target_reference', __( '投稿IDと投稿タイプの組み合わせが無効です。', 'od-visual-regression' ), array( 'status' => 400 ) );
		}
		if ( ! is_int( $object_id ) || $object_id < 1 || $object_id > 2147483647 || ! is_string( $post_type ) ) {
			return new WP_Error( 'odvr_invalid_target_reference', __( '投稿IDと投稿タイプの組み合わせが無効です。', 'od-visual-regression' ), array( 'status' => 400 ) );
		}
		$post = get_post( $object_id );
		$type = get_post_type_object( $post_type );
		if ( ! $post || ! $type || ! $type->public || ! is_post_type_viewable( $type ) || $post->post_type !== $post_type || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
			return new WP_Error( 'odvr_invalid_target_reference', __( '公開状態の投稿を選択してください。', 'od-visual-regression' ), array( 'status' => 400 ) );
		}
		return self::validate( get_permalink( $post ) );
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
