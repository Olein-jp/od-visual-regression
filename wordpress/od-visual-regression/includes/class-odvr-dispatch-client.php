<?php
/**
 * 登録Dispatcherへのraw body署名と受付照合。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** 平文Tokenは呼出中のメモリだけに保持する。 */
final class ODVR_Dispatch_Client {
	/**
	 * 固定先へ署名して送る。リダイレクトやCookieは許可しない。
	 *
	 * @param array  $settings 保存済み設定.
	 * @param string $path 固定path.
	 * @param string $method method.
	 * @param string $body raw JSON.
	 * @return array|WP_Error HTTP応答.
	 */
	private function request( $settings, $path, $method, $body ) {
		$ready = ODVR_DB::writable();
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		$ready = ODVR_Config::dispatch_ready( $settings );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$timestamp = (string) time();
		return wp_safe_remote_request(
			untrailingslashit( $settings['dispatcher_url'] ) . $path,
			array(
				'method'              => $method,
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'cookies'             => array(),
				'limit_response_size' => 16385,
				'headers'             => array(
					'Content-Type'     => 'application/json',
					'Accept'           => 'application/json',
					'X-ODVR-Timestamp' => $timestamp,
					'X-ODVR-Signature' => hash_hmac( 'sha256', $timestamp . "\n" . $body, ODVR_Config::value( 'ODVR_DISPATCHER_SHARED_SECRET' ) ),
				),
				'body'                => $body,
				'data_format'         => 'body',
			)
		);
	}
	/**
	 * 契約一致の受付と明確な拒否だけを分類する。
	 *
	 * @param mixed  $response HTTP応答.
	 * @param array  $settings 保存設定.
	 * @param string $uuid Run UUID.
	 * @return array 受付結果.
	 */
	private function classify( $response, $settings, $uuid ) {
		$unknown = array(
			'outcome'      => 'unknown',
			'execution_id' => null,
		);
		if ( is_wp_error( $response ) ) {
			return $unknown;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 16384 ) {
			return $unknown;
		}
		$value     = json_decode( $body );
		$validator = new ODVR_Contract_Validator();
		if ( in_array( $status, array( 200, 202 ), true ) && true === $validator->validate( 'dispatch-response', $value ) && $settings['site_id'] === $value->site_id && $uuid === $value->run_uuid && ( 'started' === $value->status ? 200 === $status : 202 === $status ) ) {
			return array(
				'outcome'      => 'accepted',
				'execution_id' => $value->runner_execution_id,
			);
		}
		if ( true === $validator->validate( 'error', $value ) && $status === $value->data->status && ( in_array( $status, array( 400, 401, 403 ), true ) || ( 503 === $status && 'odvr_dispatch_unavailable' === $value->code && false === $value->data->retryable ) ) ) {
			return array(
				'outcome'      => 'rejected',
				'execution_id' => null,
			);
		}
		// 409や未知bodyは既存受付の可能性を否定できない.
		return $unknown;
	}
	/**
	 * 保存済みRunのTokenを一度送る。再送する場合も同じbytesだけを使う。
	 *
	 * @param array $settings 保存済み設定.
	 * @param array $created Run作成の内部戻り値.
	 * @return array 受付結果.
	 */
	public function send( $settings, $created ) {
		$uuid  = $created['item']->run_uuid;
		$value = (object) array(
			'schema_version' => 1,
			'site_id'        => $settings['site_id'],
			'run_uuid'       => $uuid,
			'callback_base'  => ODVR_Config::callback_base(),
			'runner_token'   => $created['runner_token'],
		);
		if ( is_wp_error( ( new ODVR_Contract_Validator() )->validate( 'dispatch-request', $value ) ) ) {
			return array(
				'outcome'      => 'rejected',
				'execution_id' => null,
			);
		}
		$body = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( is_wp_error( ( new ODVR_Runner_Auth() )->authorize( 'Bearer ' . $created['runner_token'], $uuid, 'manifest' ) ) ) {
			return array(
				'outcome'      => 'unknown',
				'execution_id' => null,
			); }
		$response = $this->classify( $this->request( $settings, '/v1/jobs', 'POST', $body ), $settings, $uuid );
		// メモリ内の1回限定再送。新Token・新Runは発行しない.
		if ( 'unknown' === $response['outcome'] ) {
			usleep( 1000000 + random_int( 0, 250000 ) );
			if ( is_wp_error( ( new ODVR_Runner_Auth() )->authorize( 'Bearer ' . $created['runner_token'], $uuid, 'manifest' ) ) ) {
				return array(
					'outcome'      => 'unknown',
					'execution_id' => null,
				); }
			$response = $this->classify( $this->request( $settings, '/v1/jobs', 'POST', $body ), $settings, $uuid );
		}
		return $this->apply( $uuid, $response );
	}
	/**
	 * 別PHPリクエストは空bodyの照合だけを行う。
	 *
	 * @param array  $settings 保存済み設定.
	 * @param string $uuid Run UUID.
	 * @return array 受付結果.
	 */
	public function reconcile( $settings, $uuid ) {
		$response = $this->request( $settings, '/v1/jobs/' . rawurlencode( $uuid ) . '?site_id=' . rawurlencode( $settings['site_id'] ), 'GET', '' );
		return $this->apply( $uuid, $this->classify( $response, $settings, $uuid ) );
	}
	/**
	 * Execution確定または明確な拒否だけを履歴に反映する。
	 *
	 * @param string $uuid UUID.
	 * @param array  $result 受付結果.
	 * @return array 受付結果.
	 */
	private function apply( $uuid, $result ) {
		$manager = new ODVR_Run_Manager();
		if ( 'rejected' === $result['outcome'] ) {
			$manager->fail_run( $uuid, 'RUN_ABORTED' );
		} elseif ( null !== $result['execution_id'] ) {
			$manager->bind_execution( $uuid, $result['execution_id'] );
		}
		return $result;
	}
	/**
	 * Run/Tokenを作らない登録・署名の診断。
	 *
	 * @param array $settings 保存済み設定.
	 * @return bool 成功.
	 */
	public function diagnose( $settings ) {
		$value    = (object) array(
			'schema_version' => 1,
			'site_id'        => $settings['site_id'],
			'callback_base'  => ODVR_Config::callback_base(),
		);
		$response = $this->request( $settings, '/v1/connection-test', 'POST', wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || strlen( wp_remote_retrieve_body( $response ) ) > 16384 ) {
			return false;
		}
		$value = json_decode( wp_remote_retrieve_body( $response ) );
		return true === ( new ODVR_Contract_Validator() )->validate( 'connection-test-response', $value ) && 'passed' === $value->item->checks->dispatcher;
	}
}
