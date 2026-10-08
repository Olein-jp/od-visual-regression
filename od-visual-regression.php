<?php
/**
 * OD Visual Regression のエントリーポイント。
 *
 * @package OD_Visual_Regression
 *
 * Plugin Name: OD Visual Regression
 * Description: WordPress サイトのビジュアルリグレッション機能を開発するためのプラグイン。
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Koji Kuno
 * Author URI: https://olein-design.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: od-visual-regression
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 同梱した翻訳ファイルの場所を登録する。
 *
 * @return void
 */
function od_visual_regression_load_textdomain() {
	load_plugin_textdomain(
		'od-visual-regression',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'od_visual_regression_load_textdomain' );
