<?php
/**
 * Apache/PHPの生multipart取得とBasic経路除外を固定fixtureで検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
// 一時Apache設定とfixtureのみをfinallyで回収する。TLSはこの試験の対象外.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
require_once __DIR__ . '/runner-api.php';

/**
 * 条件とHTTP statusを検査する。
 *
 * @param bool   $ok 条件.
 * @param string $message 内容.
 * @return void
 * @throws RuntimeException 失敗時.
 */
function odvr_ingress_check( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

$prefix    = 'odvr-ingress-' . wp_generate_uuid4();
$file      = ABSPATH . $prefix . '.php';
$blocked   = ABSPATH . $prefix . '-basic.php';
$automatic = ABSPATH . $prefix . '-automatic.php';
$passwords = ABSPATH . $prefix . '-passwords';
$htaccess  = ABSPATH . '.htaccess';
$original  = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : null;
$source    = <<<'SOURCE'
<?php
// 試験専用のproxy HTTPS判定。実TLSの証明書検証は後続の結合試験で行う。
$_SERVER['HTTPS'] = 'on';
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $_GET['rest_route'] = '/odvr/v1/runner/runs/00000000-0000-4000-8000-000000000000/snapshots';
}
require dirname($_SERVER['SCRIPT_FILENAME']) . '/wp-load.php';
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $value = ODVR_Multipart::parse(WP_REST_Server::get_raw_data(), $_SERVER['CONTENT_TYPE']);
    header('Content-Type: application/json');
    header('Cache-Control: private, no-store');
    if (is_wp_error($value)) {
        status_header($value->get_error_data()['status']);
        echo wp_json_encode(array('code' => $value->get_error_code()));
    } else {
        echo wp_json_encode(array('accepted' => true, 'file_count' => count($value['files'])));
    }
} else {
    rest_get_server()->serve_request('/odvr/v1/runner/runs/00000000-0000-4000-8000-000000000000/manifest');
}
SOURCE;
try {
	foreach ( array( $file, $blocked, $automatic ) as $fixture_path ) {
		file_put_contents( $fixture_path, $source );
		chmod( $fixture_path, 0600 );
	}
	file_put_contents( $passwords, 'fixture-user:' . password_hash( 'fixture-password', PASSWORD_BCRYPT ) . "\n" );
	chmod( $passwords, 0600 );
	$rules  = "\n<Files \"" . basename( $file ) . "\">\nphp_flag enable_post_data_reading Off\nRequire all granted\n</Files>\n";
	$rules .= '<Files "' . basename( $blocked ) . "\">\nphp_flag enable_post_data_reading Off\nAuthType Basic\nAuthName \"ODVR fixture\"\nAuthUserFile \"" . $passwords . "\"\nRequire valid-user\n</Files>\n";
	$rules .= '<Files "' . basename( $automatic ) . "\">\nphp_flag enable_post_data_reading On\nRequire all granted\n</Files>\n";
	file_put_contents( $htaccess, ( $original ?? '' ) . $rules );
	$base            = is_multisite() ? 'http://tests-wordpress/' : 'http://wordpress/';
	$headers         = array(
		'Authorization' => 'Bearer ' . str_repeat( 'A', 43 ),
		'Content-Type'  => 'multipart/form-data; boundary=odvr-fixture-boundary',
	);
	$result          = (object) array(
		'schema_version'     => 1,
		'target_id'          => 1,
		'device_id'          => 1,
		'status'             => 'ERROR',
		'width'              => null,
		'height'             => null,
		'baseline_width'     => null,
		'baseline_height'    => null,
		'dimension_changed'  => false,
		'diff_pixels'        => null,
		'total_pixels'       => null,
		'diff_ratio'         => null,
		'duration_ms'        => 0,
		'http_status'        => null,
		'error_code'         => 'CAPTURE_FAILED',
		'error_message'      => ODVR_Run_Manager::error_message( 'CAPTURE_FAILED' ),
		'no_baseline_reason' => null,
	);
	$headers['Host'] = wp_parse_url( get_site_url(), PHP_URL_HOST ) . ( wp_parse_url( get_site_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( get_site_url(), PHP_URL_PORT ) : '' );
	$body            = odvr_runner_body( $result );
	$options         = array(
		'body'        => $body,
		'headers'     => $headers,
		'timeout'     => 10,
		'redirection' => 0,
	);
	$response        = wp_remote_post( $base . basename( $file ), $options );
	odvr_ingress_check( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && true === ( json_decode( wp_remote_retrieve_body( $response ) )->accepted ?? false ), 'Apache/PHPから生multipartを取得し契約検証する' );

	$captured                = clone $result;
	$captured->status        = 'CAPTURED';
	$captured->width         = 1;
	$captured->height        = 1;
	$captured->error_code    = null;
	$captured->error_message = null;
	$options['body']         = odvr_runner_body( $captured, array( 'image' => odvr_runner_png() ) );
	$response                = wp_remote_post( $base . basename( $file ), $options );
	odvr_ingress_check( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && 1 === ( json_decode( wp_remote_retrieve_body( $response ) )->file_count ?? null ), 'バイナリPNGを含む生multipartも実HTTPで取得する' );
	$options['body'] = str_replace( 'name="status"', 'name="target_id"', $body );
	$response        = wp_remote_post( $base . basename( $file ), $options );
	odvr_ingress_check( ! is_wp_error( $response ) && 400 === wp_remote_retrieve_response_code( $response ), 'PHP自動展開前の重複scalarを実HTTPで拒否する' );
	$options['body'] = $body;
	$response        = wp_remote_post( $base . basename( $automatic ), $options );
	odvr_ingress_check( ! is_wp_error( $response ) && 503 === wp_remote_retrieve_response_code( $response ) && 'odvr_raw_upload_unavailable' === ( json_decode( wp_remote_retrieve_body( $response ) )->code ?? '' ), 'PHP自動展開が有効な経路はUpload非対応として拒否する' );
	$probe    = array(
		'headers'     => array(
			'Authorization' => $headers['Authorization'],
			'Host'          => $headers['Host'],
		),
		'timeout'     => 10,
		'redirection' => 0,
	);
	$response = wp_remote_get( $base . basename( $blocked ), $probe );
	odvr_ingress_check( ! is_wp_error( $response ) && 401 === wp_remote_retrieve_response_code( $response ) && '' === wp_remote_retrieve_header( $response, 'x-odvr-runner-gateway' ), 'Basic前段がBearerを遮断する設定を検出できる' );
	$response = wp_remote_get( $base . basename( $file ), $probe );
	odvr_ingress_check( ! is_wp_error( $response ) && 401 === wp_remote_retrieve_response_code( $response ) && '1' === wp_remote_retrieve_header( $response, 'x-odvr-runner-gateway' ) && 'available' === wp_remote_retrieve_header( $response, 'x-odvr-raw-multipart' ) && 'present' === wp_remote_retrieve_header( $response, 'x-odvr-bearer-header' ) && 'odvr_runner_unauthorized' === ( json_decode( wp_remote_retrieve_body( $response ) )->code ?? '' ), 'Basic除外経路はPHPのRunner認証まで到達する' );
	WP_CLI::success( '実Apache/PHPの生multipartとBasic経路検証が完了しました。' );
} finally {
	if ( null === $original ) {
		unlink( $htaccess );
	} else {
		file_put_contents( $htaccess, $original );
	}
	foreach ( array( $file, $blocked, $automatic, $passwords ) as $fixture_path ) {
		if ( file_exists( $fixture_path ) ) {
			unlink( $fixture_path );
		}
	}
}
