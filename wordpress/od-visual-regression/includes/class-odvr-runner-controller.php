<?php
/**
 * Runner専用の取得・結果受付入口。
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
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_png' ), 5, 4 );
		add_filter( 'rest_post_dispatch', array( $this, 'gateway_headers' ), 11, 3 );
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
		foreach ( array( 'snapshots', 'progress', 'complete' ) as $operation ) {
			register_rest_route(
				'odvr/v1',
				'/runner/runs/' . $uuid . '/' . $operation,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'permission_callback' => array( $this, 'permission' ),
				)
			);
		}
		register_rest_route(
			'odvr/v1',
			'/runner/snapshots/(?P<id>[1-9][0-9]{0,9})/baseline',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_baseline' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}
	/**
	 * 認証なしの診断でも秘密を返さず、専用経路の対応状態だけを表示する。
	 *
	 * @param WP_REST_Response $response 応答.
	 * @param WP_REST_Server   $server サーバー.
	 * @param WP_REST_Request  $request リクエスト.
	 * @return WP_REST_Response 応答.
	 */
	public function gateway_headers( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/odvr/v1/runner/' ) ) {
			$response->header( 'X-ODVR-Runner-Gateway', '1' );
			$response->header( 'X-ODVR-Bearer-Header', preg_match( '/^Bearer [A-Za-z0-9_-]{43}$/D', $request->get_header( 'Authorization' ) ?? '' ) ? 'present' : 'absent' );
			$response->header( 'X-ODVR-Raw-Multipart', ODVR_Runner_Ingress::raw_available() ? 'available' : 'unavailable' );
		}
		return $response;
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
		$operation = 'snapshots' === $operation ? 'upload' : $operation;
		if ( array_diff( array_keys( $request->get_query_params() ), array( 'rest_route' ) ) || ( 'GET' === $request->get_method() && ! in_array( $request->get_body(), array( null, '' ), true ) ) ) {
			return new WP_Error( 'odvr_invalid_payload', 'Runnerの入力を確認してください。', array( 'status' => 400 ) );
		}
		$row = ( new ODVR_Runner_Auth() )->authorize( $request->get_header( 'Authorization' ), $params['uuid'] ?? null, $operation );
		if ( ! is_wp_error( $row ) && 'manifest' !== $operation ) {
			$execution = $request->get_header( 'X-ODVR-Execution-ID' );
			if ( ! is_string( $execution ) || '' === $execution || $execution !== $row['runner_execution_id'] ) {
				return new WP_Error( 'odvr_run_conflict', 'Executionを確認してください。', array( 'status' => 409 ) );
			}
		}
		return $row;
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
			$value = ( new ODVR_Run_Manager() )->start( $row['uuid'], $request->get_header( 'X-ODVR-Execution-ID' ), $request->get_header( 'Authorization' ) );
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

	/**
	 * JSONのProgress・Completeと生multipartのUploadを受け付ける。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return WP_REST_Response|WP_Error 応答.
	 */
	public function post( $request ) {
		$row = $this->scope( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$operation = basename( $request->get_route() );
		if ( 'snapshots' === $operation ) {
			$input = ODVR_Multipart::parse( $request->get_body(), $request->get_header( 'Content-Type' ) );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			$value = ( new ODVR_Snapshot_Repository() )->upload( $row, $input, $request->get_header( 'Authorization' ), $request->get_header( 'X-ODVR-Execution-ID' ) );
		} else {
			$body = $request->get_body();
			if ( ! $request->is_json_content_type() || ! is_string( $body ) || strlen( $body ) > 65536 ) {
				return new WP_Error( 'odvr_invalid_payload', '結果送信の形式を確認してください。', array( 'status' => 400 ) );
			}
			$input = json_decode( $body );
			$valid = ( new ODVR_Contract_Validator() )->validate( $operation . '-request', $input );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			if ( $input->runner_execution_id !== $request->get_header( 'X-ODVR-Execution-ID' ) ) {
				return new WP_Error( 'odvr_run_conflict', 'Executionを確認してください。', array( 'status' => 409 ) );
			}
			$manager = new ODVR_Run_Manager();
			$value   = 'progress' === $operation ? $manager->progress( $row['uuid'], $input, $request->get_header( 'Authorization' ) ) : $manager->finish( $row['uuid'], $input, $request->get_header( 'Authorization' ) );
		}
		return is_wp_error( $value ) ? $value : new WP_REST_Response( $value, 200, array( 'Cache-Control' => 'private, no-store' ) );
	}
	/**
	 * Tokenに固定された参照画像だけを取得する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return ODVR_Runner_PNG_Response|WP_Error 応答.
	 */
	public function get_baseline( $request ) {
		$row = $this->scope( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$params = $request->get_url_params();
		if ( (float) $params['id'] > 2147483647 ) {
			return new WP_Error( 'odvr_invalid_id', '画像の指定を確認してください。', array( 'status' => 400 ) );
		}
		$png = ( new ODVR_Snapshot_Repository() )->baseline( $row, (int) $params['id'], $request->get_header( 'Authorization' ), $request->get_header( 'X-ODVR-Execution-ID' ) );
		if ( is_wp_error( $png ) ) {
			return $png;
		}
		$response      = new ODVR_Runner_PNG_Response(
			null,
			200,
			array(
				'Content-Type'           => 'image/png',
				'Content-Length'         => strlen( $png['png'] ),
				'X-ODVR-Image-SHA256'    => $png['sha256'],
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
		$response->png = $png['png'];
		return $response;
	}
	/**
	 * 共有ロック中に検証したPNGをバッファから配信する。
	 *
	 * @param bool             $served 配信済み.
	 * @param WP_REST_Response $response 応答.
	 * @param WP_REST_Request  $request リクエスト.
	 * @param WP_REST_Server   $server サーバー.
	 * @return bool 配信済み.
	 */
	public function serve_png( $served, $response, $request, $server ) {
		if ( ! $served && $response instanceof ODVR_Runner_PNG_Response ) {
			// 生PNGでありHTMLに埋め込まない.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $response->png;
			return true;
		}
		return $served;
	}
}
