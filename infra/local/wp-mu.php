<?php
/** 固定local fixtureだけのTLS中継・認証局・静的撮影ページ。 */
if ( ! defined( 'ODVR_LOCAL_FIXTURE' ) || true !== ODVR_LOCAL_FIXTURE ) {
	return;
}
$_SERVER['HTTPS'] = 'on';
/** 本来の安全検査を、固定した2つのHTTPS入口だけへ限定して補う。 */
function odvr_local_origin( $url ) {
	$parts = wp_parse_url( $url );
	return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && 8443 === ( $parts['port'] ?? null ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && in_array( $parts['host'] ?? '', array( 'wordpress.fixture.test', 'dispatcher.fixture.test' ), true );
}
add_filter( 'http_request_host_is_external', function ( $allowed, $host, $url ) { return odvr_local_origin( $url ) ? true : $allowed; }, 10, 3 );
add_filter( 'http_allowed_safe_ports', function ( $ports, $host, $url ) { return odvr_local_origin( $url ) ? array_merge( $ports, array( 8443 ) ) : $ports; }, 10, 3 );
add_filter( 'http_request_args', function ( $arguments, $url ) { if ( odvr_local_origin( $url ) ) { $arguments['sslcertificates'] = '/tmp/odvr-fixture-ca.pem'; $arguments['sslverify'] = true; } return $arguments; }, 10, 2 );
add_action( 'template_redirect', function () {
	if ( '/index.php?odvr_local_fixture=1' === ( $_SERVER['REQUEST_URI'] ?? '' ) ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>ODVR fixture</title><body style="margin:0;background:#f1f4f9;font-family:sans-serif"><main style="padding:32px"><h1>ODVR local fixture</h1><p>WordPressから撮影・保存・完了までを確認します。</p></main></body></html>';
		exit;
	}
}, 0 );
