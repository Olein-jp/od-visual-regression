<?php
/**
 * WordPressの明示Uninstall入口。既定では履歴を保持する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }
require_once __DIR__ . '/od-visual-regression.php';
$odvr_uninstall_result = ODVR_Uninstaller::run();
if ( is_wp_error( $odvr_uninstall_result ) ) {
	wp_die( esc_html( $odvr_uninstall_result->get_error_message() ) ); }
