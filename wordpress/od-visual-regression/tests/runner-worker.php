<?php
/**
 * 隔離fixtureの並行Uploadワーカー。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
// バイナリfixtureのJSON搬送だけにbase64を使用する.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.AlternativeFunctions, WordPress.PHP.IniSet.memory_limit_Disallowed
require_once __DIR__ . '/runner-api.php';
$input = json_decode( file_get_contents( $args[0] ) );
if ( ! $input instanceof stdClass || ! isset( $input->run, $input->uploads, $input->body ) ) {
	WP_CLI::error( 'fixtureを確認してください。' );
}
ini_set( 'memory_limit', '1024M' );
define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
add_filter(
	'upload_dir',
	function () use ( $input ) {
		return (array) $input->uploads;
	}
);
file_put_contents( $args[1], 'ready' );
$deadline = microtime( true ) + 15;
while ( ! file_exists( $args[2] ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
	clearstatcache();
}
if ( ! file_exists( $args[2] ) ) {
	WP_CLI::error( '並行試験の開始を確認できません。' );
}
$response = odvr_runner_upload( (array) $input->run, base64_decode( $input->body, true ) );
WP_CLI::line(
	wp_json_encode(
		array(
			'status' => $response->get_status(),
			'item'   => $response->get_data(),
		)
	)
);
