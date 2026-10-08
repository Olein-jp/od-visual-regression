<?php
/**
 * 管理REST APIとRepositoryの接続。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** JSON bodyとURLパラメーターを混ぜず処理する。 */
final class ODVR_Admin_Controller extends WP_REST_Controller {
	/** 管理ルートを登録する。 */
	public function register_routes() {
		$id     = '(?P<id>[1-9][0-9]{0,9})';
		$uuid   = '(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})';
		$routes = array(
			'/suites'                       => array( 'GET', 'POST' ),
			'/suites/' . $id                => array( 'GET', 'PATCH', 'DELETE' ),
			'/suites/' . $id . '/targets'   => array( 'GET', 'POST' ),
			'/suites/' . $id . '/targets/(?P<target_id>[1-9][0-9]{0,9})' => array( 'PATCH', 'DELETE' ),
			'/devices'                      => array( 'GET', 'POST' ),
			'/devices/' . $id               => array( 'GET', 'PATCH', 'DELETE' ),
			'/suites/' . $id . '/runs'      => array( 'GET', 'POST' ),
			'/suites/' . $id . '/baseline'  => array( 'POST' ),
			'/runs/' . $uuid                => array( 'GET', 'DELETE' ),
			'/runs/' . $uuid . '/snapshots' => array( 'GET' ),
			'/settings'                     => array( 'GET', 'PATCH' ),
			'/settings/connection-test'     => array( 'POST' ),
		);
		foreach ( $routes as $route => $methods ) {
			$endpoints = array();
			foreach ( $methods as $method ) {
				$endpoints[] = array(
					'methods'             => $method,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'permission' ),
				);
			}
			register_rest_route( 'odvr/v1', $route, $endpoints );
		}
		add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 );
	}
	/**
	 * Cookie・ヘッダーnonce・現在サイトの管理権限。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error 認証結果.
	 */
	public function permission( $request ) {
		global $wp_rest_application_password_status;
		if ( $wp_rest_application_password_status instanceof WP_User || is_wp_error( $wp_rest_application_password_status ) || preg_match( '/^Bearer(?:\s|$)/i', (string) $request->get_header( 'Authorization' ) ) ) {
			return new WP_Error( 'odvr_forbidden', '管理画面からログインしてください。', array( 'status' => 403 ) );
		}
		return ( new ODVR_Image_Controller() )->permission( $request );
	}
	/**
	 * ODVRの管理応答・エラーをキャッシュしない。
	 *
	 * @param WP_REST_Response $response 応答.
	 * @param WP_REST_Server   $server サーバー.
	 * @param WP_REST_Request  $request リクエスト.
	 * @return WP_REST_Response 応答.
	 */
	public function no_store( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/odvr/v1/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			$data = $response->get_data();
			if ( $response->get_status() >= 400 && is_array( $data ) && isset( $data['code'] ) && 0 === strpos( $data['code'], 'odvr_' ) && ! preg_match( '#^/odvr/v1/content(?:/|$)#', $request->get_route() ) ) {
				$response->set_data(
					array(
						'schema_version' => 1,
						'code'           => $data['code'],
						'message'        => '操作または接続の条件を確認してください。',
						'data'           => array(
							'status'     => $response->get_status(),
							'retryable'  => in_array( $response->get_status(), array( 429, 503 ), true ),
							'request_id' => wp_generate_uuid4(),
						),
					)
				);
				// 固定Baselineの欠損理由だけを契約の許可値として返す.
				if ( 'odvr_baseline_unavailable' === $data['code'] && 404 === $response->get_status() && preg_match( '#^/odvr/v1/runner/snapshots/[1-9][0-9]*/baseline$#D', $request->get_route() ) && isset( $data['data']['reason'] ) && in_array( $data['data']['reason'], array( 'missing', 'corrupt', 'incompatible' ), true ) ) {
					$normalized                   = $response->get_data();
					$normalized['data']['reason'] = $data['data']['reason'];
					$response->set_data( $normalized );
				}
				if ( in_array( $response->get_status(), array( 429, 503 ), true ) ) {
					$response->header( 'Retry-After', 30 ); }
			}
		}
		return $response;
	}
	/**
	 * HTTPの入力を検証しRepositoryへ接続する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return WP_REST_Response|WP_Error 応答.
	 */
	public function handle( $request ) {
		try {
			return $this->dispatch( $request );
		} catch ( ODVR_Repository_Failure $error ) {
			return $error->failure;
		} catch ( Throwable $error ) {
			return new WP_Error( 'odvr_api_unavailable', '操作を完了できませんでした。', array( 'status' => 503 ) );
		}
	}
	/**
	 * 内部失敗を定型応答で扱う。
	 *
	 * @param mixed $value 結果.
	 * @return mixed 正常値.
	 * @throws ODVR_Repository_Failure 失敗時.
	 */
	private function checked( $value ) {
		if ( is_wp_error( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 内部の定型WP_Errorのみ.
			throw new ODVR_Repository_Failure( $value );
		}
		return $value;
	}
	/**
	 * 不正入力は補正せず拒否する。
	 *
	 * @return void
	 * @throws ODVR_Repository_Failure 不正時.
	 */
	private function invalid() {
		$this->checked( new WP_Error( 'odvr_invalid_payload', '入力を確認してください。', array( 'status' => 400 ) ) );
	}
	/**
	 * 単一応答。
	 *
	 * @param mixed $item 表示値.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response 応答.
	 */
	private function item( $item, $status = 200 ) {
		return new WP_REST_Response(
			array(
				'schema_version' => 1,
				'item'           => (object) $this->checked( $item ),
			),
			$status
		);
	}
	/**
	 * Repository一覧を契約とページングへ変換する。
	 *
	 * @param mixed  $collection Repository一覧.
	 * @param int    $page ページ.
	 * @param int    $per_page 件数.
	 * @param string $schema 応答契約.
	 * @return WP_REST_Response 応答.
	 */
	private function collection( $collection, $page, $per_page, $schema ) {
		$collection = $this->checked( $collection );
		$pages      = (int) ceil( $collection['total'] / $per_page );
		if ( $page > 1 && $page > $pages ) {
			$this->invalid();
		}
		$body = (object) array(
			'schema_version' => 1,
			'items'          => array_map(
				function ( $item ) {
					return (object) $item;
				},
				$collection['items']
			),
		);
		$this->checked( ( new ODVR_Contract_Validator() )->validate( $schema, $body ) );
		return new WP_REST_Response(
			$body,
			200,
			array(
				'X-WP-Total'      => $collection['total'],
				'X-WP-TotalPages' => $pages,
			)
		);
	}
	/**
	 * ルート・body・queryを個別に検証する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return WP_REST_Response 応答.
	 */
	private function dispatch( $request ) {
		$route  = substr( $request->get_route(), strlen( '/odvr/v1' ) );
		$method = $request->get_method();
		$params = $request->get_url_params();
		foreach ( array( 'id', 'target_id' ) as $key ) {
			if ( isset( $params[ $key ] ) && (float) $params[ $key ] > 2147483647 ) {
				$this->invalid();
			}
		}
		$id      = isset( $params['id'] ) ? (int) $params['id'] : null;
		$query   = $request->get_query_params();
		$allowed = array( 'rest_route' );
		if ( 'GET' === $method && ( in_array( $route, array( '/suites', '/devices' ), true ) || preg_match( '#^/suites/[0-9]+/(targets|runs)$#D', $route ) || '/snapshots' === substr( $route, -10 ) ) ) {
			$allowed = array_merge( $allowed, array( 'page', 'per_page' ) );
		}
		if ( 'GET' === $method && '/suites' === $route ) {
			$allowed[] = 'status'; }
		if ( 'GET' === $method && preg_match( '#^/suites/[0-9]+/targets$#D', $route ) ) {
			$allowed[] = 'include_disabled'; }
		if ( array_diff( array_keys( $query ), $allowed ) ) {
			$this->invalid();
		}
		$page     = 1;
		$per_page = 20;
		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( isset( $query[ $key ] ) ) {
				if ( ! is_scalar( $query[ $key ] ) || ! preg_match( '/^[1-9][0-9]{0,6}$/D', (string) $query[ $key ] ) || (float) $query[ $key ] > ( 'page' === $key ? 1000000 : 100 ) ) {
					$this->invalid();
				}
				$$key = (int) $query[ $key ];
			}
		}
		$input = null;
		if ( in_array( $method, array( 'POST', 'PATCH' ), true ) ) {
			if ( 'application/json' !== strtolower( trim( explode( ';', $request->get_header( 'Content-Type' ) )[0] ) ) || strlen( $request->get_body() ) > 65536 ) {
				$this->invalid();
			}
			$input = json_decode( $request->get_body() );
			if ( ! $input instanceof stdClass || JSON_ERROR_NONE !== json_last_error() ) {
				$this->invalid();
			}
		} elseif ( ! in_array( $request->get_body(), array( null, '' ), true ) ) {
			$this->invalid();
		}
		$suites   = new ODVR_Suite_Repository();
		$targets  = new ODVR_Target_Repository();
		$devices  = new ODVR_Device_Repository();
		$runs     = new ODVR_Run_Repository();
		$manager  = new ODVR_Run_Manager();
		$settings = new ODVR_Settings();
		if ( '/settings/connection-test' === $route ) {
			$this->checked( ( new ODVR_Contract_Validator() )->validate( 'connection-test-request', $input ) );
			return $this->connection_test( $settings );
		}
		if ( '/settings' === $route ) {
			return $this->item( 'GET' === $method ? $settings->get() : $settings->update( $input ) );
		}
		if ( '/suites' === $route ) {
			return 'GET' === $method ? $this->collection( $suites->list_items( $page, $per_page, $query['status'] ?? 'all' ), $page, $per_page, 'suite-list-response' ) : $this->item( $suites->create( $input, get_current_user_id() ), 201 );
		}
		if ( '/devices' === $route ) {
			return 'GET' === $method ? $this->collection( $devices->list_items( $page, $per_page ), $page, $per_page, 'device-list-response' ) : $this->item( $devices->create( $input ), 201 );
		}
		if ( preg_match( '#^/devices/[0-9]+$#D', $route ) ) {
			return $this->item( 'GET' === $method ? $devices->get( $id ) : ( 'PATCH' === $method ? $devices->update( $id, $input ) : $devices->disable( $id ) ) );
		}
		if ( preg_match( '#^/suites/[0-9]+$#D', $route ) ) {
			return $this->item( 'GET' === $method ? $suites->get( $id ) : ( 'PATCH' === $method ? $suites->update( $id, $input ) : $suites->archive( $id ) ) );
		}
		if ( preg_match( '#^/suites/[0-9]+/targets(?:/[0-9]+)?$#D', $route ) ) {
			if ( 'GET' === $method ) {
				$disabled = $query['include_disabled'] ?? 'false';
				if ( ! in_array( $disabled, array( 'true', 'false' ), true ) ) {
					$this->invalid(); }
				return $this->collection( $targets->list_items( $id, $page, $per_page, 'true' === $disabled ), $page, $per_page, 'target-list-response' );
			}
			return $this->item( 'POST' === $method ? $targets->create( $id, $input ) : ( 'PATCH' === $method ? $targets->update( $id, (int) $params['target_id'], $input ) : $targets->disable( $id, (int) $params['target_id'] ) ), 'POST' === $method ? 201 : 200 );
		}
		if ( preg_match( '#^/suites/[0-9]+/baseline$#D', $route ) ) {
			$this->checked( ( new ODVR_Contract_Validator() )->validate( 'baseline-request', $input ) );
			$reference = $this->checked( $runs->get( $input->run_id ) );
			if ( $id !== $reference['suite_id'] ) {
				$this->checked( new WP_Error( 'odvr_not_found', '履歴が見つかりません。', array( 'status' => 404 ) ) ); }
			$this->checked( $manager->promote_baseline( $id, $input->run_id ) );
			return $this->item( $suites->get( $id ) );
		}
		if ( preg_match( '#^/suites/[0-9]+/runs$#D', $route ) ) {
			if ( 'GET' === $method ) {
				return $this->collection( $runs->list_items( $id, $page, $per_page ), $page, $per_page, 'run-list-response' );
			}
			$this->checked( ( new ODVR_Contract_Validator() )->validate( 'run-create-request', $input ) );
			if ( 'specific' === $input->baseline_mode ) {
				$reference = $this->checked( $runs->get( $input->reference_run_id ) );
				if ( $id !== $reference['suite_id'] ) {
					$this->checked( new WP_Error( 'odvr_not_found', '履歴が見つかりません。', array( 'status' => 404 ) ) ); }
			}

			$saved = $this->checked( $settings->saved() );
			$this->checked( ODVR_Config::dispatch_ready( $saved ) );
			$auth    = $this->checked( ODVR_Config::http_auth() );
			$options = array(
				'queued_timeout_seconds'        => $saved['queued_timeout_seconds'],
				'run_timeout_seconds'           => $saved['run_timeout_seconds'],
				'http_auth_origin'              => null === $auth ? null : $auth->origin,
				'expected_site_settings_digest' => ODVR_Environment::digest( (object) $saved ),
			);
			$created = $this->checked( $manager->create( $id, $input, get_current_user_id(), $options, null === $auth ? null : (array) $auth ) );
			( new ODVR_Dispatch_Client() )->send( $saved, $created );
			return $this->item( $created['item'], 202 );
		}
		$uuid   = $params['uuid'];
		$run_id = $this->checked( $runs->resolve_uuid( $uuid ) );
		if ( isset( $query['status'] ) || isset( $query['include_disabled'] ) ) {
			$this->invalid(); }
		if ( '/snapshots' === substr( $route, -10 ) ) {
			return $this->collection( $runs->list_snapshots( $uuid, $page, $per_page ), $page, $per_page, 'snapshot-list-response' );
		}
		if ( 'DELETE' === $method ) {
			$state = $this->checked( $manager->request_delete( $run_id ) );
			wp_schedule_single_event( time() + 1, 'odvr_retention' );
			return $this->item( $runs->get( $run_id ), 202 );
		}
		$item = $this->checked( $runs->get( $run_id ) );
		if ( 'queued' === $item['status'] ) {
			$saved = $this->checked( $settings->saved() );
			( new ODVR_Dispatch_Client() )->reconcile( $saved, $uuid );
			$item = $this->checked( $runs->get( $run_id ) );
		}
		return $this->item( $item );
	}
	/**
	 * 保存済み先だけを診断し、Runを作らない。
	 *
	 * @param ODVR_Settings $settings 保存設定.
	 * @return WP_REST_Response 応答.
	 */
	private function connection_test( $settings ) {
		$this->checked( $settings->claim_diagnostic() );
		$saved  = $this->checked( $settings->saved() );
		$checks = array(
			'settings'   => true === ODVR_Config::dispatch_ready( $saved ) && ODVR_Runner_Ingress::diagnose() ? 'passed' : 'failed',
			'storage'    => 'failed',
			'dispatcher' => 'failed',
		);
		$auth   = ODVR_Config::http_auth();
		if ( ! is_wp_error( $auth ) ) {
			$checks['storage'] = true === ( new ODVR_Storage() )->diagnose( null === $auth ? null : (array) $auth ) ? 'passed' : 'failed';
		}
		if ( 'passed' === $checks['settings'] ) {
			$checks['dispatcher'] = ( new ODVR_Dispatch_Client() )->diagnose( $saved ) ? 'passed' : 'failed';
		}
		$passed = ! in_array( 'failed', $checks, true );
		$item   = (object) array(
			'checks'     => (object) $checks,
			'checked_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'code'       => $passed ? 'odvr_connection_passed' : 'odvr_connection_failed',
			'message'    => $passed ? '登録先と保存先の接続を確認しました。' : '設定または接続先を確認してください。',
		);
		$this->checked(
			( new ODVR_Contract_Validator() )->validate(
				'connection-test-response',
				(object) array(
					'schema_version' => 1,
					'item'           => $item,
				)
			)
		);
		return $this->item( $item );
	}
}
