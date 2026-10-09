<?php
/**
 * CGI/FastCGIのRunner専用入口。WordPress直下の専用ディレクトリへ配置する。
 *
 * @package OD_Visual_Regression
 */

$odvr_uri    = $_SERVER['REQUEST_URI'] ?? '';
$odvr_method = $_SERVER['REQUEST_METHOD'] ?? '';
$odvr_uuid   = '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}';
$odvr_get    = '#^/wp-json/odvr/v1/runner/(?:runs/' . $odvr_uuid . '/(?:manifest|credentials)|snapshots/[1-9][0-9]{0,9}/baseline)$#D';
$odvr_post   = '#^/wp-json/odvr/v1/runner/runs/' . $odvr_uuid . '/(?:snapshots|progress|complete)$#D';

// 直接URL、管理画面、他のREST経路、queryやエンコード経由の迂回をWordPress起動前に拒否する。
if ( ! is_string( $odvr_uri ) || ! (
	( 'GET' === $odvr_method && preg_match( $odvr_get, $odvr_uri ) ) ||
	( 'POST' === $odvr_method && preg_match( $odvr_post, $odvr_uri ) )
) || ! empty( $_SERVER['QUERY_STRING'] ) || ! empty( $_GET ) ) {
	http_response_code( 404 );
	header( 'Cache-Control: private, no-store' );
	exit;
}

// 設定の反映前には画像を受け付けない。ini_setでは変更できないディレクティブである。
if ( filter_var( ini_get( 'enable_post_data_reading' ), FILTER_VALIDATE_BOOLEAN ) ) {
	http_response_code( 503 );
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Cache-Control: private, no-store' );
	// 設置不備を判別する真偽値だけを返す。サーバーのパス・環境変数・資格情報は公開しない。
	echo json_encode(
		array(
			'code'    => 'odvr_raw_upload_unavailable',
			'message' => 'Runner専用ディレクトリのPHP設定を確認してください。',
			'checks'  => array(
				'per_directory_ini_supported' => in_array( PHP_SAPI, array( 'cgi-fcgi', 'fpm-fcgi' ), true ),
				'user_ini_filename_matches'   => '.user.ini' === ini_get( 'user_ini.filename' ),
				'user_ini_present'            => is_file( __DIR__ . '/.user.ini' ),
				'user_ini_readable'           => is_readable( __DIR__ . '/.user.ini' ),
			),
		)
	);
	exit;
}

if ( 'POST' === $odvr_method ) {
	$odvr_length = $_SERVER['CONTENT_LENGTH'] ?? '';
	if ( ! is_string( $odvr_length ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $odvr_length ) ) {
		http_response_code( 411 );
		header( 'Cache-Control: private, no-store' );
		exit;
	}
	if ( (float) $odvr_length > 44040192 ) {
		http_response_code( 413 );
		header( 'Cache-Control: private, no-store' );
		exit;
	}
}

$odvr_bootstrap = dirname( __DIR__ ) . '/wp-blog-header.php';
if ( ! is_file( $odvr_bootstrap ) ) {
	http_response_code( 503 );
	header( 'Cache-Control: private, no-store' );
	exit;
}

// 元のURLとBearerを保ち、検査済みのRunner経路だけを既存REST controllerへ渡す。
$_GET['rest_route'] = substr( $odvr_uri, strlen( '/wp-json' ) );
define( 'WP_USE_THEMES', false );
require $odvr_bootstrap;
