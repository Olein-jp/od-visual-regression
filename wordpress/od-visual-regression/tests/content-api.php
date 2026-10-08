<?php
/**
 * WordPress実機でコンテンツ選択APIの契約と認証を検証する。
 *
 * 実行方法: npm run env:cli -- eval-file tests/content-api.php
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * 期待した条件を検査する。
 *
 * @param bool   $condition 検査条件.
 * @param string $message 検証内容.
 * @return void
 * @throws RuntimeException 検証失敗時.
 */
function odvr_content_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * Cookieとnonceを設定してコア認証とRESTディスパッチを実行する。
 *
 * @param int    $user_id ユーザーID.
 * @param string $route ルート.
 * @param array  $params 検索条件.
 * @param string $nonce_mode valid、invalid、missing.
 * @param bool   $cookie Cookieの有無.
 * @return WP_REST_Response RESTレスポンス。
 */
function odvr_content_request( $user_id, $route, $params = array(), $nonce_mode = 'valid', $cookie = true ) {
	global $wp_rest_auth_cookie;
	wp_set_current_user( $user_id );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_X_WP_NONCE'] );
	if ( $cookie && $user_id ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
	}
	$request = new WP_REST_Request( 'GET', $route );
	$request->set_query_params( $params );
	if ( 'missing' !== $nonce_mode ) {
		$nonce                      = 'invalid' === $nonce_mode ? 'invalid' : wp_create_nonce( 'wp_rest' );
		$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		$request->set_header( 'X-WP-Nonce', $nonce );
	}
	// 実際のCookie認証が成功した状態から、コアのREST nonce検証を通す.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- コア認証状態の検証用.
	$wp_rest_auth_cookie = $cookie && $user_id ? true : null;
	$server              = rest_get_server();
	$error               = $server->check_authentication();
	if ( is_wp_error( $error ) ) {
		return rest_convert_error_to_response( $error );
	}
	return $server->dispatch( $request );
}

/**
 * 公開範囲、ページング、入力、認証、URL形式を検証する。
 *
 * @return void
 */
function odvr_test_content_api() {
	$original_user = get_current_user_id();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- テスト前の入力を改変せず復元する.
	$original_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- テスト前の入力を改変せず復元する.
	$original_nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : null;
	$user_ids       = array();
	$post_ids       = array();
	$marker         = 'odvr-' . wp_generate_uuid4();
	$types          = array( 'odvr_fixture', 'odvr_hidden', 'odvr_no_front' );

	try {
		register_post_type(
			$types[0],
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);
		register_post_type(
			$types[1],
			array(
				'public'             => false,
				'publicly_queryable' => true,
			)
		);
		register_post_type(
			$types[2],
			array(
				'public'             => true,
				'publicly_queryable' => false,
			)
		);
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$id = wp_insert_user(
				array(
					'user_login' => $marker . $role,
					'user_pass'  => wp_generate_password(),
					'role'       => $role,
				)
			);
			odvr_content_assert( ! is_wp_error( $id ), $role . 'の検証ユーザーを作成' );
			$user_ids[] = $id;
		}
		$admin = $user_ids[0];
		foreach ( array( 'publish', 'publish', 'publish', 'draft', 'private', 'future', 'pending', 'trash' ) as $status ) {
			$post_ids[] = wp_insert_post(
				array(
					'post_type'   => $types[0],
					'post_status' => $status,
					'post_title'  => '<b>' . $marker . '</b>',
					'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : current_time( 'mysql' ),
				),
				true
			);
		}
		$post_ids[] = wp_insert_post(
			array(
				'post_type'     => $types[0],
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_title'    => $marker,
			),
			true
		);
		foreach ( array( $types[1], $types[2], 'post', 'page' ) as $type ) {
			$post_ids[] = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => $marker,
				),
				true
			);
		}
		foreach ( $post_ids as $id ) {
			odvr_content_assert( ! is_wp_error( $id ) && 0 < $id, '検証用投稿を作成' );
		}
		$response = odvr_content_request( $admin, '/odvr/v1/content/post-types' );
		$names    = array_column( $response->get_data(), 'post_type' );
		odvr_content_assert( 200 === $response->get_status() && in_array( $types[0], $names, true ), 'show_in_rest=falseの公開CPTを列挙' );
		odvr_content_assert( in_array( 'post', $names, true ) && in_array( 'page', $names, true ), '投稿と固定ページを列挙' );
		odvr_content_assert( ! in_array( $types[1], $names, true ) && ! in_array( $types[2], $names, true ), '非public・フロント非公開タイプを除外' );

		$params   = array(
			'search'    => $marker,
			'post_type' => $types[0],
			'per_page'  => 2,
		);
		$response = odvr_content_request( $admin, '/odvr/v1/content', $params );
		$data     = $response->get_data();
		$headers  = $response->get_headers();
		odvr_content_assert( 200 === $response->get_status() && 2 === count( $data ), '検索・CPT絞り込み・ページサイズが反映' );
		odvr_content_assert( 3 === $headers['X-WP-Total'] && 2 === $headers['X-WP-TotalPages'], '下書き・非公開・保護投稿を集計から除外' );
		odvr_content_assert( $post_ids[0] === $data[0]['object_id'] && $types[0] === $data[0]['post_type'] && $marker === $data[0]['label'] && get_permalink( $post_ids[0] ) === $data[0]['url'], '対象ID・タイプ・プレーンラベル・URLが正しい' );
		$params['page'] = 2;
		$response       = odvr_content_request( $admin, '/odvr/v1/content', $params );
		odvr_content_assert( 1 === count( $response->get_data() ) && $response->get_data()[0]['object_id'] === $post_ids[2], '2ページ目に重複なく残りの投稿を返す' );
		$params['page'] = 3;
		odvr_content_assert( 400 === odvr_content_request( $admin, '/odvr/v1/content', $params )->get_status(), '範囲外ページを拒否' );
		$response = odvr_content_request( $admin, '/odvr/v1/content', array( 'search' => $marker ) );
		odvr_content_assert( 5 === count( $response->get_data() ), '全タイプ検索でも公開CPT・投稿・固定ページだけを返す' );
		$response = odvr_content_request( $admin, '/odvr/v1/content', array( 'search' => $marker . '-absent' ) );
		odvr_content_assert( array() === $response->get_data() && 0 === $response->get_headers()['X-WP-Total'], '検索結果0件を正常に返す' );

		foreach ( array( array( 'per_page' => 101 ), array( 'per_page' => 0 ), array( 'page' => -1 ), array( 'page' => '1.5' ), array( 'page' => 1000001 ), array( 'search' => array( 'x' ) ), array( 'search' => str_repeat( 'a', 201 ) ), array( 'search' => "bad\nsearch" ), array( 'post_type' => $types[1] ), array( 'post_type' => 'missing' ), array( 'post_type' => array( 'post' ) ) ) as $invalid ) {
			odvr_content_assert( 400 === odvr_content_request( $admin, '/odvr/v1/content', $invalid )->get_status(), '不正な検索条件を拒否: ' . wp_json_encode( $invalid ) );
		}
		foreach ( array( '/odvr/v1/content', '/odvr/v1/content/post-types' ) as $route ) {
			odvr_content_assert( 403 === odvr_content_request( $user_ids[1], $route )->get_status(), '権限なしを拒否: ' . $route );
			odvr_content_assert( 401 === odvr_content_request( 0, $route, array(), 'missing' )->get_status(), '未ログインを拒否: ' . $route );
			odvr_content_assert( 403 === odvr_content_request( $admin, $route, array(), 'invalid' )->get_status(), '不正nonceを拒否: ' . $route );
			odvr_content_assert( 401 === odvr_content_request( $admin, $route, array(), 'missing' )->get_status(), 'nonce欠落を拒否: ' . $route );
			odvr_content_assert( 401 === odvr_content_request( $admin, $route, array(), 'valid', false )->get_status(), 'Cookieなしの認証を拒否: ' . $route );
		}
		foreach ( array( 'https://example.com/path?a=1&b=2', 'http://localhost:8888/?p=1', 'https://[::1]/', 'https://example.com/%E6%97%A5' ) as $url ) {
			odvr_content_assert( ODVR_Target_URL::validate( $url ) === $url, 'HTTP(S)の有効な形式を維持: ' . $url );
		}
		foreach ( array( '', null, array(), '/relative', '//example.com/', 'ftp://example.com/', 'file:///tmp/a', 'javascript:alert(1)', 'https://user:pass@example.com/', 'https://@example.com/', 'https://example.com:0/', 'https://example.com:99999/', "https://example.com/\n", 'https://example.com/%0d%0a', 'https://example.com/%zz', 'https://example.com\\@evil.test/', 'https://', str_repeat( 'a', 2049 ) ) as $url ) {
			odvr_content_assert( is_wp_error( ODVR_Target_URL::validate( $url ) ), '不正URL形式・資格情報を拒否' );
		}
	} finally {
		wp_set_current_user( $original_user );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $original_cookie;
		$_SERVER['HTTP_X_WP_NONCE']  = $original_nonce;
		foreach ( $post_ids as $id ) {
			if ( ! is_wp_error( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
		foreach ( $user_ids as $id ) {
			WP_Session_Tokens::get_instance( $id )->destroy_all();
			wp_delete_user( $id );
		}
		foreach ( $types as $type ) {
			unregister_post_type( $type );
		}
	}
}

try {
	odvr_test_content_api();
	WP_CLI::success( 'コンテンツ選択APIの検証が完了しました。' );
} catch ( Throwable $odvr_content_error ) {
	WP_CLI::error( $odvr_content_error->getMessage() );
}
