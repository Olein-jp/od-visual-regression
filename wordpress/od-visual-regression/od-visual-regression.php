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

require_once __DIR__ . '/includes/class-odvr-capabilities.php';
require_once __DIR__ . '/includes/class-odvr-target-url.php';
require_once __DIR__ . '/includes/class-odvr-contract-validator.php';
require_once __DIR__ . '/includes/class-odvr-db-schema.php';
require_once __DIR__ . '/includes/class-odvr-db.php';
require_once __DIR__ . '/includes/class-odvr-repository-failure.php';
require_once __DIR__ . '/includes/class-odvr-repository.php';
require_once __DIR__ . '/includes/class-odvr-suite-repository.php';
require_once __DIR__ . '/includes/class-odvr-target-repository.php';
require_once __DIR__ . '/includes/class-odvr-device-repository.php';
require_once __DIR__ . '/includes/class-odvr-environment.php';
require_once __DIR__ . '/includes/class-odvr-baseline.php';
require_once __DIR__ . '/includes/class-odvr-run-repository.php';
require_once __DIR__ . '/includes/class-odvr-run-manager.php';
require_once __DIR__ . '/includes/class-odvr-png.php';
require_once __DIR__ . '/includes/class-odvr-storage.php';
require_once __DIR__ . '/includes/class-odvr-activator.php';
require_once __DIR__ . '/includes/class-odvr-deactivator.php';
require_once __DIR__ . '/includes/class-odvr-plugin.php';

register_activation_hook( __FILE__, array( 'ODVR_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ODVR_Deactivator', 'deactivate' ) );

/**
 * プラグインのフックを一度だけ登録する。
 *
 * @return void
 */
function odvr_init_plugin() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new ODVR_Plugin( __FILE__ );
	}

	$plugin->init();
}
add_action( 'plugins_loaded', 'odvr_init_plugin' );
