<?php
/**
 * 設定ファイル専用の秘密と登録済み通信先。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** 秘密値をOptionsや表示用応答へ保存しない。 */
final class ODVR_Config {
	/**
	 * 現在サイトのconfig値を返す。MultisiteではサイトIDをキーとする配列も許可する。
	 *
	 * @param string $name 定数名.
	 * @return mixed|null 値.
	 */
	public static function value( $name ) {
		$value = defined( $name ) ? constant( $name ) : null;
		return is_array( $value ) ? ( $value[ get_current_blog_id() ] ?? null ) : $value;
	}
	/**
	 * Basic認証の完全な設定だけを返す。
	 *
	 * @return stdClass|null|WP_Error 設定.
	 */
	public static function http_auth() {
		$user     = self::value( 'ODVR_HTTP_AUTH_USER' );
		$password = self::value( 'ODVR_HTTP_AUTH_PASSWORD' );
		$origin   = self::value( 'ODVR_HTTP_AUTH_ORIGIN' );
		if ( null === $user && null === $password && null === $origin ) {
			return null;
		}
		$credentials = (object) array(
			'schema_version' => 1,
			'http_auth'      => (object) array(
				'origin'   => $origin,
				'username' => $user,
				'password' => $password,
			),
		);
		if ( ! is_string( $user ) || '' === $user || ! is_string( $password ) || '' === $password || is_wp_error( ( new ODVR_Contract_Validator() )->validate( 'runner-credentials', $credentials ) ) ) {
			return new WP_Error( 'odvr_config_invalid', '認証設定を確認してください。', array( 'status' => 503 ) );
		}
		return $credentials->http_auth;
	}
	/**
	 * 固定Dispatcher登録と32bytes以上のShared Secretを確認する。
	 *
	 * @param array $settings 保存済み設定.
	 * @return true|WP_Error 結果.
	 */
	public static function dispatch_ready( $settings ) {
		$url      = self::value( 'ODVR_DISPATCHER_URL' );
		$secret   = self::value( 'ODVR_DISPATCHER_SHARED_SECRET' );
		$callback = self::callback_base();
		$payload  = (object) array(
			'schema_version' => 1,
			'site_id'        => $settings['site_id'],
			'callback_base'  => $callback,
		);
		if ( ! is_string( $url ) || $url !== $settings['dispatcher_url'] || ! is_string( $secret ) || strlen( $secret ) < 32 || is_wp_error( ( new ODVR_Contract_Validator() )->validate( 'dispatch-connection-test-request', $payload ) ) || is_wp_error( self::http_auth() ) ) {
			return new WP_Error( 'odvr_config_invalid', '登録先と秘密設定を確認してください。', array( 'status' => 503 ) );
		}
		return true;
	}
	/**
	 * 固定したWordPress Runner入口。
	 *
	 * @return string URL.
	 */
	public static function callback_base() {
		return untrailingslashit( rest_url( 'odvr/v1/runner' ) );
	}
}
