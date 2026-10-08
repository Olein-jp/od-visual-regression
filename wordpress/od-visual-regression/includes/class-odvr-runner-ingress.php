<?php
/**
 * 生multipartの取得上限とRunner経路の診断。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** 専用経路はenable_post_data_reading=OffとWebサーバーの42MiB上限を要求する。 */
final class ODVR_Runner_Ingress {
	/**
	 * 生の入力をWordPressがフォーム展開せず取得できるか。
	 *
	 * @return bool 対応可否.
	 */
	public static function raw_available() {
		return ! filter_var( ini_get( 'enable_post_data_reading' ), FILTER_VALIDATE_BOOLEAN );
	}
	/** 生multipartだけをWordPress RESTの本文取得前に上限付きで読む。 */
	public static function capture() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		// 入口選択だけに使用する。値をファイルパスやSQLへ渡さない.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		$route = $_GET['rest_route'] ?? wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_string( $route ) || ! preg_match( '#/odvr/v1/runner/runs/[a-f0-9-]{36}/snapshots/?$#D', $route ) ) {
			return;
		}
		$length = $_SERVER['CONTENT_LENGTH'] ?? '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		$code   = null;
		$status = 503;
		if ( ! self::raw_available() ) {
			$code = 'odvr_raw_upload_unavailable';
		} elseif ( ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $length ) ) {
			$code   = 'odvr_content_length_required';
			$status = 411;
		} elseif ( (float) $length > 42 * 1024 * 1024 ) {
			$code   = 'odvr_payload_too_large';
			$status = 413;
		} else {
			$input = fopen( 'php://input', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$body  = false === $input ? false : stream_get_contents( $input, 42 * 1024 * 1024 + 1 );
			if ( false !== $input ) {
				fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( ! is_string( $body ) || strlen( $body ) !== (int) $length ) {
				$code   = 'odvr_invalid_payload';
				$status = 400;
			} else {
				// WordPress RESTの公式本文取得処理が参照するglobalへ、生本文だけを渡す.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited
				$GLOBALS['HTTP_RAW_POST_DATA'] = $body;
			}
		}
		if ( null !== $code ) {
			status_header( $status );
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Cache-Control: private, no-store' );
			header( 'X-Content-Type-Options: nosniff' );
			// 定型エラーだけをJSON配信し、本文・秘密を含めない.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode(
				array(
					'schema_version' => 1,
					'code'           => $code,
					'message'        => 'Runnerの専用経路または送信上限を確認してください。',
					'data'           => array(
						'status'     => $status,
						'retryable'  => 503 === $status,
						'request_id' => wp_generate_uuid4(),
					),
				)
			);
			exit;
		}
	}
	/**
	 * 登録callbackだけをBearerで訪ね、Basic除外と生本文対応を診断する。
	 * RunやJobを作らず、実Tokenも送らない。
	 *
	 * @return bool 診断結果.
	 */
	public static function diagnose() {
		$base = ODVR_Config::callback_base();
		if ( 0 !== strpos( $base, 'https://' ) ) {
			return false;
		}
		$response = wp_safe_remote_get(
			$base . '/runs/00000000-0000-4000-8000-000000000000/manifest',
			array(
				'headers'             => array( 'Authorization' => 'Bearer ' . str_repeat( 'A', 43 ) ),
				'timeout'             => 10,
				'redirection'         => 0,
				'cookies'             => array(),
				'sslverify'           => true,
				'limit_response_size' => 4096,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		return 401 === wp_remote_retrieve_response_code( $response ) && '1' === wp_remote_retrieve_header( $response, 'x-odvr-runner-gateway' ) && 'available' === wp_remote_retrieve_header( $response, 'x-odvr-raw-multipart' ) && 'present' === wp_remote_retrieve_header( $response, 'x-odvr-bearer-header' ) && $body instanceof stdClass && 'odvr_runner_unauthorized' === ( $body->code ?? '' );
	}
}
