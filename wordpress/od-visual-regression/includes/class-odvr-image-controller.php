<?php
/**
 * Cookie認証後のPNG配信。URLにnonceや秘密を付けない。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-odvr-image-response.php';

/** DB確定した画像を共有Runロック内で配信する。 */
final class ODVR_Image_Controller extends WP_REST_Controller {
	/** RESTフックを登録する。 */
	public function register_routes() {
		register_rest_route(
			'odvr/v1',
			'/snapshots/(?P<id>[1-9][0-9]{0,9})/image',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_image' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	/**
	 * Cookie・ヘッダーnonce・管理権限を検証する。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error 認証結果.
	 */
	public function permission( $request ) {
		$user = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $user || get_current_user_id() !== (int) $user ) {
			return new WP_Error( 'odvr_cookie_required', __( 'ログインCookieが必要です。', 'od-visual-regression' ), array( 'status' => 401 ) );
		}
		if ( null !== $request->get_param( '_wpnonce' ) || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'REST nonceが無効です。', 'od-visual-regression' ), array( 'status' => 403 ) );
		}
		if ( ! ODVR_Capabilities::can_manage() ) {
			return new WP_Error( 'odvr_forbidden', __( '画像を閲覧する権限がありません。', 'od-visual-regression' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * エラーも含めてキャッシュを禁止する。
	 *
	 * @param WP_REST_Response $response 応答.
	 * @param WP_REST_Server   $server サーバー.
	 * @param WP_REST_Request  $request リクエスト.
	 * @return WP_REST_Response 応答.
	 */
	public function no_store( $response, $server, $request ) {
		if ( preg_match( '#^/odvr/v1/snapshots/[^/]+/image$#D', $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}

	/**
	 * 画像IDと種別を検証し、配信参照を返す。
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return ODVR_Image_Response|WP_Error 応答.
	 */
	public function get_image( $request ) {
		$kind = $request->get_param( 'kind' );
		$kind = null === $kind ? 'current' : $kind;
		if ( ! in_array( $kind, array( 'current', 'diff', 'baseline' ), true ) || (float) $request['id'] > 2147483647 || array_diff( array_keys( $request->get_query_params() ), array( 'kind', 'rest_route' ) ) ) {
			return new WP_Error( 'odvr_invalid_image_request', __( '画像の指定が無効です。', 'od-visual-regression' ), array( 'status' => 400 ) );
		}
		$source = $this->source( (int) $request['id'], $kind );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$response              = new ODVR_Image_Response(
			null,
			200,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
		$response->snapshot_id = (int) $request['id'];
		$response->kind        = $kind;
		return $response;
	}

	/**
	 * 同サイトの確定Snapshotと固定Baselineだけを解決する。
	 *
	 * @param int    $id Snapshot ID.
	 * @param string $kind 種別.
	 * @return array|WP_Error 画像参照.
	 */
	private function source( $id, $kind ) {
		global $wpdb;
		$error = new WP_Error( 'odvr_image_unavailable', __( '画像を利用できません。', 'od-visual-regression' ), array( 'status' => 404 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- 配信ごとに最新DB参照を検査する.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT s.*, r.uuid AS run_uuid, r.status AS run_status, r.suite_id, r.reference_run_id, u.uuid AS suite_uuid FROM %i s JOIN %i r ON r.id = s.run_id JOIN %i u ON u.id = r.suite_id WHERE s.id = %d', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'runs' ), ODVR_DB::table( 'suites' ), $id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'odvr_storage_unavailable', __( '画像保存先を利用できません。', 'od-visual-regression' ), array( 'status' => 503 ) );
		}
		if ( ! $row || ! in_array( $row['status'], array( 'CAPTURED', 'UNCHANGED', 'REVIEW', 'CHANGED', 'NO_BASELINE' ), true ) || ! in_array( $row['run_status'], array( 'running', 'complete', 'failed' ), true ) ) {
			return $error;
		}
		if ( 'baseline' === $kind ) {
			$baseline = $wpdb->get_row( $wpdb->prepare( 'SELECT s.*, r.uuid AS run_uuid, r.status AS run_status, r.suite_id, u.uuid AS suite_uuid FROM %i s JOIN %i r ON r.id = s.run_id JOIN %i u ON u.id = r.suite_id WHERE s.id = %d AND r.id = %d AND r.suite_id = %d AND s.target_id = %d AND s.device_id = %d AND r.status = %s', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'runs' ), ODVR_DB::table( 'suites' ), $row['baseline_snapshot_id'], $row['reference_run_id'], $row['suite_id'], $row['target_id'], $row['device_id'], 'complete' ), ARRAY_A );
			if ( ! $baseline || $wpdb->last_error || ! in_array( $baseline['status'], array( 'CAPTURED', 'UNCHANGED', 'REVIEW', 'CHANGED', 'NO_BASELINE' ), true ) ) {
				return $error;
			}
			$row = $baseline;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$metadata = json_decode( $row['metadata'] );
		$key      = 'diff' === $kind ? 'diff_sha256' : 'image_sha256';
		$path     = 'diff' === $kind ? 'diff_path' : 'image_path';
		if ( ! $metadata instanceof stdClass || ! isset( $metadata->metadata_version, $metadata->$key ) || 1 !== $metadata->metadata_version || ! is_string( $metadata->$key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $metadata->$key ) || ! is_string( $row[ $path ] ) ) {
			return $error;
		}
		$row['path']         = $row[ $path ];
		$row['digest']       = $metadata->$key;
		$row['image_width']  = 'diff' === $kind ? max( (int) $row['width'], (int) $row['baseline_width'] ) : (int) $row['width'];
		$row['image_height'] = 'diff' === $kind ? max( (int) $row['height'], (int) $row['baseline_height'] ) : (int) $row['height'];
		return $row;
	}

	/**
	 * 共有ロックの取得後にDB参照を再確認して、PNGをPHPからstreamする。
	 *
	 * @param bool             $served 配信済みか.
	 * @param WP_REST_Response $result 応答.
	 * @param WP_REST_Request  $request リクエスト.
	 * @param WP_REST_Server   $server サーバー.
	 * @return bool 配信済み.
	 */
	public function serve( $served, $result, $request, $server ) {
		if ( preg_match( '#^/odvr/v1/snapshots/[^/]+/image$#D', $request->get_route() ) ) {
			header( 'Cache-Control: private, no-store' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		if ( ! $result instanceof ODVR_Image_Response || $served ) {
			return $served;
		}
		$error  = $this->permission( $request );
		$source = is_wp_error( $error ) ? $error : $this->source( $result->snapshot_id, $result->kind );
		if ( ! is_wp_error( $source ) ) {
			$storage = new ODVR_Storage();
			$error   = $storage->with_run_lock(
				$source['run_uuid'],
				false,
				function () use ( $result, $source, $storage ) {
					$current = $this->source( $result->snapshot_id, $result->kind );
					if ( is_wp_error( $current ) ) {
						return $current;
					}
					if ( $current !== $source ) {
						return new WP_Error( 'odvr_image_unavailable', __( '画像を利用できません。', 'od-visual-regression' ), array( 'status' => 404 ) );
					}
					$data = $storage->read_png( $current['path'], $current['suite_uuid'], $current['run_uuid'], (int) $current['target_id'], $current['image_width'], $current['image_height'], $current['digest'] );
					if ( is_wp_error( $data ) ) {
						return $data;
					}
					header( 'Content-Type: image/png' );
					header( 'Content-Length: ' . strlen( $data ) );
					header( 'X-ODVR-Image-SHA256: ' . $current['digest'] );
					// 生PNGの配信でありHTMLへ埋め込まない.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $data;
					return true;
				}
			);
		} else {
			$error = $source;
		}
		if ( is_wp_error( $error ) ) {
			$response = rest_convert_error_to_response( $error );
			status_header( $response->get_status() );
			header( 'Content-Type: application/json; charset=UTF-8' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- エラーはJSONとして配信する.
			echo wp_json_encode( $response->get_data() );
		}
		return true;
	}
}
