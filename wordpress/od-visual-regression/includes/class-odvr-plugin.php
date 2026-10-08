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

		ODVR_DB::upgrade();
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 間隔はRun Managerで60秒と定義する.
		add_filter( 'cron_schedules', array( 'ODVR_Run_Manager', 'cron_schedules' ) );
		add_action( 'odvr_run_expiry', array( 'ODVR_Run_Manager', 'expiry_cron' ) );
		if ( true === ODVR_DB::writable() && ! wp_next_scheduled( 'odvr_run_expiry' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'odvr_minute', 'odvr_run_expiry' );
		}
		add_action( 'odvr_retention', array( 'ODVR_Retention', 'cron' ) );
		if ( true === ODVR_DB::writable() && ! wp_next_scheduled( 'odvr_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'odvr_retention' ); }
		add_action( 'odvr_storage_cleanup', array( 'ODVR_Storage', 'cleanup_cron' ) );
		if ( true === ODVR_DB::writable() && ! wp_next_scheduled( 'odvr_storage_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'odvr_storage_cleanup' );
		}
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'database_notice' ) );
		add_filter( 'wpmu_drop_tables', array( $this, 'site_tables' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		$this->initialized = true;
	}

	/**
	 * 書込できないDB状態を管理者へ定型表示する。
	 *
	 * @return void
	 */
	public function database_notice() {
		if ( ODVR_Capabilities::can_manage() && is_wp_error( ODVR_DB::writable() ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'OD Visual RegressionのDBを利用できません。導入状態を確認してください。', 'od-visual-regression' ) . '</p></div>';
		}
	}

	/**
	 * WordPressによるサイト完全削除へ、そのサイトの5テーブルを追加する。
	 *
	 * @param array $tables 削除対象.
	 * @param int   $site_id 完全削除するサイト.
	 * @return array 削除対象.
	 */
	public function site_tables( $tables, $site_id ) {
		if ( get_current_blog_id() === (int) $site_id ) {
			foreach ( ODVR_DB_Schema::definitions() as $suffix => $definition ) {
				$tables[] = ODVR_DB::table( $suffix );
			}
		}
		return array_values( array_unique( $tables ) );
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
		require_once __DIR__ . '/class-odvr-image-controller.php';
		$images = new ODVR_Image_Controller();
		$images->register_routes();
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
