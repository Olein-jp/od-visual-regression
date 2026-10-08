<?php
/**
 * プラグインの通常実行時の初期化。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 通常実行時のフックと翻訳を管理する。
 */
final class ODVR_Plugin {
	/**
	 * プラグインのメインファイル。
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * フックが登録済みかどうか。
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * メインファイルの場所を受け取る。
	 *
	 * @param string $plugin_file メインファイルの絶対パス.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * 通常実行時のフックを一度だけ登録する。
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		$this->initialized = true;
	}

	/**
	 * RESTクラスが読み込まれた後に選択用APIを登録する。
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		require_once __DIR__ . '/class-odvr-content-controller.php';
		$controller = new ODVR_Content_Controller();
		$controller->register_routes();
	}

	/**
	 * 同梱した翻訳ファイルの場所を登録する。
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'od-visual-regression',
			false,
			dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}
}
