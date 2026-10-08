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
		$this->initialized = true;
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
