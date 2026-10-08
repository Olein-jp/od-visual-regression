<?php
/**
 * サイト境界と短いRepositoryトランザクションの共通処理。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// キャッシュを持たず、ロックと最新のDB値を正本として扱う.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** サイト単位のRepository基底。 */
abstract class ODVR_Repository {
	/**
	 * 作成時のサイト。
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * 作成時のprefix。
	 *
	 * @var string
	 */
	private $prefix;
	/**
	 * ネストしたトランザクションを拒否する。
	 *
	 * @var bool
	 */
	private static $in_transaction = false;
	/**
	 * 共通契約。
	 *
	 * @var ODVR_Contract_Validator
	 */
	protected $validator;

	/** サイトと検証器を固定する。 */
	public function __construct() {
		global $wpdb;
		$this->site_id   = get_current_blog_id();
		$this->prefix    = $wpdb->prefix;
		$this->validator = new ODVR_Contract_Validator();
	}

	/**
	 * 定型エラーで処理を中止する。
	 *
	 * @param string $code コード.
	 * @param int    $status HTTP status.
	 * @param array  $extra 追加の非秘密診断.
	 * @return void
	 * @throws ODVR_Repository_Failure 検証や状態が不適切な場合.
	 */
	protected function fail( $code, $status = 400, $extra = array() ) {
		// WP_Errorを保持する内部例外であり表示処理ではない.
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new ODVR_Repository_Failure( new WP_Error( $code, __( '保存データまたは操作の条件を確認してください。', 'od-visual-regression' ), array_merge( array( 'status' => $status ), $extra ) ) );
	}

	/**
	 * 共通検証の失敗を伝える。
	 *
	 * @param mixed $result 結果.
	 * @return mixed 正常値.
	 * @throws ODVR_Repository_Failure 検証失敗時.
	 */
	protected function checked( $result ) {
		if ( is_wp_error( $result ) ) {
			// WP_Errorを保持する内部例外であり表示処理ではない.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ODVR_Repository_Failure( $result );
		}
		return $result;
	}

	/**
	 * 通信IDを丸めず検査する。
	 *
	 * @param mixed $id ID.
	 * @return int ID.
	 */
	protected function id( $id ) {
		if ( ! ( is_int( $id ) || is_float( $id ) ) || ! is_finite( (float) $id ) || floor( $id ) !== (float) $id || $id < 1 || $id > 2147483647 ) {
			$this->fail( 'odvr_invalid_id' );
		}
		return (int) $id;
	}

	/**
	 * DB整数をAPI安全範囲へ変換する。
	 *
	 * @param mixed $value DB値.
	 * @param bool  $positive 正整数か.
	 * @return int 整数.
	 */
	protected function integer( $value, $positive = false ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $value ) || (float) $value > 2147483647 || ( $positive && (float) $value < 1 ) ) {
			$this->fail( 'odvr_invalid_stored_data', 500 );
		}
		return (int) $value;
	}

	/**
	 * DB booleanを変換する。
	 *
	 * @param mixed $value DB値.
	 * @return bool 値.
	 */
	protected function boolean( $value ) {
		if ( ! in_array( (string) $value, array( '0', '1' ), true ) ) {
			$this->fail( 'odvr_invalid_stored_data', 500 );
		}
		return '1' === (string) $value;
	}

	/**
	 * 別サイト/prefixへのRepository再利用を拒否する。
	 *
	 * @return void
	 */
	protected function scope() {
		global $wpdb;
		if ( get_current_blog_id() !== $this->site_id || $wpdb->prefix !== $this->prefix ) {
			$this->fail( 'odvr_site_mismatch', 404 );
		}
	}

	/**
	 * DB失敗を秘密なしのエラーへ変換する。
	 *
	 * @return void
	 */
	protected function database_check() {
		global $wpdb;
		if ( $wpdb->last_error ) {
			$retryable = (bool) preg_match( '/deadlock|lock wait timeout/i', $wpdb->last_error );
			$this->fail( 'odvr_database_error', 503, array( 'retryable' => $retryable ) );
		}
	}

	/**
	 * SELECTを実行する。
	 *
	 * @param string $sql prepare済みSQL.
	 * @return array 結果.
	 */
	protected function rows( $sql ) {
		global $wpdb;
		// 呼出側は固定列とprepare済みSQLだけを渡す.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_results( $sql, ARRAY_A );
		$this->database_check();
		if ( ! is_array( $result ) ) {
			$this->fail( 'odvr_database_error', 503 );
		}
		return $result;
	}

	/**
	 * 単一行を取得する。
	 *
	 * @param string $suffix テーブル種別.
	 * @param int    $id ID.
	 * @param bool   $lock ロックするか.
	 * @return array 行.
	 */
	protected function row( $suffix, $id, $lock = false ) {
		global $wpdb;
		$sql  = $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ODVR_DB::table( $suffix ), $this->id( $id ) );
		$rows = $this->rows( $sql . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( ! $rows ) {
			$this->fail( 'odvr_not_found', 404 );
		}
		return $rows[0];
	}

	/**
	 * 書き込みSQLの結果を確認する。
	 *
	 * @param mixed $result SQL結果.
	 * @return void
	 */
	protected function written( $result ) {
		$this->database_check();
		if ( false === $result ) {
			$this->fail( 'odvr_database_error', 503 );
		}
	}

	/**
	 * 読取処理を安全な戻り値へ包む。
	 *
	 * @param callable $operation 処理.
	 * @return mixed|WP_Error 結果.
	 */
	protected function read( $operation ) {
		try {
			$this->scope();
			return $operation();
		} catch ( ODVR_Repository_Failure $error ) {
			return $error->failure;
		} catch ( Throwable $error ) {
			return new WP_Error( 'odvr_repository_error', __( '保存データを処理できませんでした。', 'od-visual-regression' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * 編集全体を原子的に実行し、ロック競合だけ一度再試行する。
	 *
	 * @param callable $operation 処理.
	 * @return mixed|WP_Error 結果.
	 */
	protected function transaction( $operation ) {
		global $wpdb;
		return $this->read(
			function () use ( $operation, $wpdb ) {
				if ( self::$in_transaction ) {
					$this->fail( 'odvr_nested_transaction', 409 );
				}
				$this->checked( ODVR_DB::writable() );
				for ( $attempt = 0; $attempt < 2; ++$attempt ) {
					self::$in_transaction = true;
					$started              = false;
					try {
						$this->written( $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) );
						$this->written( $wpdb->query( 'START TRANSACTION' ) );
						$started = true;
						$result  = $operation();
						$this->scope();
						$this->checked( ODVR_DB::writable() );
						$this->written( $wpdb->query( 'COMMIT' ) );
						return $result;
					} catch ( Throwable $error ) {
						if ( $started ) {
							$wpdb->query( 'ROLLBACK' );
						}
						if ( 0 === $attempt && $error instanceof ODVR_Repository_Failure && ! empty( $error->failure->get_error_data()['retryable'] ) ) {
							continue;
						}
						throw $error;
					} finally {
						self::$in_transaction = false;
					}
				}
			}
		);
	}

	/**
	 * SchemaへstdClassのまま渡す。
	 *
	 * @param string $name 契約名.
	 * @param mixed  $value Payload.
	 * @return void
	 */
	protected function validate( $name, $value ) {
		$this->checked( $this->validator->validate( $name, $value ) );
	}

	/**
	 * Suite保存JSONを型・Version・全項目まで検査する。
	 *
	 * @param array $row Suite行.
	 * @return stdClass 保存設定.
	 */
	protected function suite_configuration( $row ) {
		$config = $this->checked( ODVR_DB::stored_json( $row['settings'], 'settings', false ) );
		$keys   = array_keys( (array) $config );
		sort( $keys );
		if ( array( 'allowed_origins', 'device_ids', 'retention', 'settings', 'settings_version' ) !== $keys ) {
			$this->fail( 'odvr_invalid_stored_data', 500 );
		}
		$request = (object) array(
			'schema_version'  => 1,
			'name'            => $row['name'],
			'settings'        => $config->settings,
			'device_ids'      => $config->device_ids,
			'allowed_origins' => $config->allowed_origins,
			'retention'       => $config->retention,
		);
		$this->validate( 'suite-create-request', $request );
		return $config;
	}

	/**
	 * ページ範囲を検査する。
	 *
	 * @param int $page ページ.
	 * @param int $per_page 件数.
	 * @return int Offset.
	 */
	protected function offset( $page, $per_page ) {
		$this->id( $page );
		$this->id( $per_page );
		if ( $page > 1000000 || $per_page > 100 ) {
			$this->fail( 'odvr_invalid_page' );
		}
		return ( $page - 1 ) * $per_page;
	}
}
