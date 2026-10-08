<?php
/**
 * Runner専用のManifest・秘密取得入口。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** 管理CookieやApplication PasswordではRunner権限を与えない。 */
final class ODVR_Runner_Controller extends WP_REST_Controller {
	/** 認証済みの取得ルートを登録する。 */
	public function register_routes() {
		$uuid = '(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})';
		add_filter( 'rest_pre_serve_request', array( $this, 'disable_cors' ), 0, 4 );
		foreach ( array( 'manifest', 'credentials' ) as $operation ) {
			register_rest_route(
				'odvr/v1',
				'/runner/runs/' . $uuid . '/' . $operation,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'permission' ),
				)
			);
		}
	}
	/**
	 * Runner応答をブラウザ向けCORSで公開しない。
	 *
	 * @param bool             $served 配信済み.
	 * @param WP_REST_Response $response 応答.
	 * @param WP_REST_Request  $request リクエスト.
	 * @param WP_REST_Server   $server サーバー.
	 * @return bool 配信済み.
	 */
	public function disable_cors( $served, $response, $request, $server ) {
		if ( 0 === strpos( $request->get_route(), '/odvr/v1/runner/' ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
			foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods' ) as $name ) {
				$server->remove_header( $name ); }
		}
		return $served;
	}

	/**
	 * リクエストを現在サイトのRunへ限定する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return array|WP_Error 内部Run行.
	 */
	private function scope( $request ) {
		if ( ! is_ssl() ) {
			return new WP_Error( 'odvr_runner_unauthorized', 'HTTPSでRunner認証を確認してください。', array( 'status' => 401 ) ); }
		$params    = $request->get_url_params();
		$operation = basename( $request->get_route() );
		if ( array_diff( array_keys( $request->get_query_params() ), array( 'rest_route' ) ) || ! in_array( $request->get_body(), array( null, '' ), true ) ) {
			return new WP_Error( 'odvr_invalid_payload', 'Runnerの入力を確認してください。', array( 'status' => 400 ) );
		}
		return ( new ODVR_Runner_Auth() )->authorize( $request->get_header( 'Authorization' ), $params['uuid'], $operation );
	}
	/**
	 * Bearerだけをpermission callbackで検証する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error 結果.
	 */
	public function permission( $request ) {
		$result = $this->scope( $request );
		return is_wp_error( $result ) ? $result : true;
	}
	/**
	 * Manifest取得でExecutionを固定し、秘密は別応答で返す。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return WP_REST_Response|WP_Error 応答.
	 */
	public function get( $request ) {
		$row = $this->scope( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'manifest' === basename( $request->get_route() ) ) {
			$value = ( new ODVR_Run_Manager() )->start( $row['uuid'], $request->get_header( 'X-ODVR-Execution-ID' ) );
		} else {
			$fixed = json_decode( $row['manifest'] );
			$valid = ( new ODVR_Contract_Validator() )->validate( 'stored-run-manifest', $fixed );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$auth = null === $fixed->http_auth_origin ? null : ODVR_Config::http_auth();
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
			if ( null !== $fixed->http_auth_origin && ( null === $auth || $auth->origin !== $fixed->http_auth_origin ) ) {
				return new WP_Error( 'odvr_configuration_changed', '認証設定が変更されています。', array( 'status' => 409 ) );
			}
			$value = (object) array(
				'schema_version' => 1,
				'http_auth'      => $auth,
			);
			$valid = ( new ODVR_Contract_Validator() )->validate( 'runner-credentials', $value );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return is_wp_error( $value ) ? $value : new WP_REST_Response( $value, 200, array( 'Cache-Control' => 'private, no-store' ) );
	}
}
