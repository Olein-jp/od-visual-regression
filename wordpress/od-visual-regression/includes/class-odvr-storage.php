<?php
/**
 * サイト別の非公開Storage。DB参照の確定は呼出側のトランザクションで行う。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ローカルfilesystemと固定inodeのRunロックを扱う。 */
final class ODVR_Storage extends ODVR_Repository {
	/**
	 * 作成時のサイトuploads情報。
	 *
	 * @var array
	 */
	private $uploads;

	/**
	 * このインスタンスが保持するRunロック。値は排他ならtrue。
	 *
	 * @var array
	 */
	private $run_locks = array();

	/** 現在サイトへ固定する。 */
	public function __construct() {
		parent::__construct();
		$this->uploads = wp_upload_dir( null, false );
	}

	/**
	 * 内部filesystemの警告から絶対パスを出さず、定型エラーへ変換する。
	 *
	 * @param callable $operation 処理.
	 * @return mixed|WP_Error 結果.
	 */
	protected function read( $operation ) {
		return parent::read(
			function () use ( $operation ) {
           // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- I/O警告に含まれる内部パスを出力しない.
				set_error_handler(
					function ( $level ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- 設定変更せず、抑制済みの競合警告かだけを読む.
						if ( error_reporting() & $level ) {
								$this->fail( 'odvr_storage_unavailable', 503 );
						}
						return true;
					}
				);
				try {
					return call_user_func( $operation );
				} finally {
						restore_error_handler();
				}
			}
		);
	}

	/**
	 * 不変UUIDを検証する。
	 *
	 * @param string $uuid UUID.
	 * @return string UUID.
	 */
	private function uuid( $uuid ) {
		if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid ) ) {
			$this->fail( 'odvr_invalid_storage_path', 400 );
		}
		return $uuid;
	}

	/**
	 * 全祖先のsymlinkと境界を検査する。
	 *
	 * @param string $path 内部生成の絶対パス.
	 * @param bool   $directory ディレクトリが必要.
	 * @return string 実パス.
	 */
	private function safe( $path, $directory = false ) {
		$cursor = $path;
		while ( '/' !== $cursor && '.' !== $cursor ) {
			if ( is_link( $cursor ) ) {
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			$cursor = dirname( $cursor );
		}
		$real = realpath( $path );
		$base = realpath( $this->uploads['basedir'] );
		if ( false === $real || false === $base || ( $real !== $base && 0 !== strpos( $real, $base . '/' ) ) || ( $directory && ! is_dir( $real ) ) || ( ! $directory && ! is_file( $real ) ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		return $real;
	}

	/**
	 * ディレクトリをサイト境界内だけに作る。
	 *
	 * @param string $path 内部生成パス.
	 * @return string 実パス.
	 */
	private function directory( $path ) {
		$cursor = $path;
		while ( '/' !== $cursor && '.' !== $cursor ) {
			if ( is_link( $cursor ) ) {
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			$cursor = dirname( $cursor );
		}
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		$real = $this->safe( $path, true );
		chmod( $real, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- 固定パスのPOSIXモードが必要.
		return $real;
	}

	/**
	 * 初期化したルートを取得する。
	 *
	 * @return string 実パス.
	 */
	private function root() {
		$this->scope();
		$current = wp_upload_dir( null, false );
		if ( $current['basedir'] !== $this->uploads['basedir'] || $current['baseurl'] !== $this->uploads['baseurl'] || $current['error'] || false !== strpos( $this->uploads['basedir'], '://' ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		// uploads自体はWordPressが作成し、内部ディレクトリだけを0700へ設定する.
		if ( ! is_dir( $this->uploads['basedir'] ) && ! wp_mkdir_p( $this->uploads['basedir'] ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		$this->safe( $this->uploads['basedir'], true );
		$root = $this->directory( $this->uploads['basedir'] . '/od-visual-regression' );
		$deny = $root . '/.htaccess';
		if ( ! file_exists( $deny ) && ! is_link( $deny ) ) {
			$this->new_file( $deny, "Require all denied\n" );
		}
		$this->safe( $deny );
		$this->directory( $root . '/.locks' );
		$this->directory( $root . '/.staging' );
		return $root;
	}

	/**
	 * 新規ファイルを排他的に作成する。
	 *
	 * @param string $path 内部生成パス.
	 * @param string $data 本体.
	 * @return void
	 */
	private function new_file( $path, $data ) {
		$this->safe( dirname( $path ), true );
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- 競合時に上書きしないPOSIX作成.
		if ( false === $handle ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		$complete = false;
		try {
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- 固定パスのPOSIXモード.
			$offset = 0;
			$length = strlen( $data );
			while ( $offset < $length ) {
				$count = fwrite( $handle, substr( $data, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- 部分書込を検査する.
				if ( ! $count ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				$offset += $count;
			}
			if ( ! fflush( $handle ) ) {
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			$complete = true;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 必ず解放する.
			if ( ! $complete ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Runの固定inodeを共有または排他でロックし、必ず解放する。
	 *
	 * @param string   $run_uuid Run UUID.
	 * @param bool     $exclusive 排他ロック.
	 * @param callable $callback ロック内処理。DB書込ロックは外で先に取得する.
	 * @param float    $timeout 待機秒。最大5秒.
	 * @return mixed|WP_Error 処理結果.
	 */
	public function with_run_lock( $run_uuid, $exclusive, $callback, $timeout = 5.0 ) {
		return $this->read(
			function () use ( $run_uuid, $exclusive, $callback, $timeout ) {
				$path = $this->root() . '/.locks/run-' . $this->uuid( $run_uuid ) . '.lock';
				if ( isset( $this->run_locks[ $run_uuid ] ) || is_link( $path ) || ! is_bool( $exclusive ) || ! is_callable( $callback ) || $timeout < 0 || $timeout > 5 ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				if ( ! file_exists( $path ) ) {
					$created = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- 同時作成の先行inodeを維持する.
					if ( false !== $created ) {
						chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- POSIXモード.
						fclose( $created ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 作成ハンドルを解放する.
					}
				}
				$this->safe( $path );
				$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 固定inodeのflock用.
				if ( false === $handle ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				try {
					$until = microtime( true ) + $timeout;
					while ( ! flock( $handle, ( $exclusive ? LOCK_EX : LOCK_SH ) | LOCK_NB ) ) {
						if ( microtime( true ) >= $until ) {
							$this->fail( 'odvr_storage_unavailable', 503 );
						}
						usleep( 10000 );
					}
					$this->scope();
					$this->run_locks[ $run_uuid ] = $exclusive;
					$stat                         = fstat( $handle );
					$current                      = stat( $this->safe( $path ) );
					if ( $stat['ino'] !== $current['ino'] || $stat['dev'] !== $current['dev'] ) {
						$this->fail( 'odvr_storage_unavailable', 503 );
					}
					return call_user_func( $callback );
				} finally {
					unset( $this->run_locks[ $run_uuid ] );
					flock( $handle, LOCK_UN );
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 必ず解放する.
				}
			}
		);
	}

	/** 同じfilesystemのrenameと排他ロックを実際に確認する。 */
	private function filesystem_probe() {
		$root = $this->root();
		$lock = $root . '/.locks/filesystem-probe.lock';
		if ( is_link( $lock ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		if ( ! file_exists( $lock ) ) {
			$this->new_file( $lock, '' );
		}
		$this->safe( $lock );
		$first       = fopen( $lock, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 固定inodeのflock診断.
		$second      = fopen( $lock, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 独立handleの排他競合を検査する.
		$uuid        = wp_generate_uuid4();
		$directory   = $this->directory( $root . '/.staging/' . $uuid );
		$source      = $directory . '/probe.tmp';
		$destination = $root . '/.locks/probe-' . $uuid . '.tmp';
		try {
			if ( false === $first || false === $second || ! flock( $first, LOCK_EX | LOCK_NB ) || flock( $second, LOCK_EX | LOCK_NB ) ) {
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			$marker = '非公開filesystem診断';
			$this->new_file( $source, $marker );
			$before = stat( $source );
			$target = stat( dirname( $destination ) );
			if ( $before['dev'] !== $target['dev'] || ! rename( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic renameを診断する.
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			$after = stat( $destination );
			if ( $before['ino'] !== $after['ino'] || file_exists( $source ) || file_get_contents( $destination ) !== $marker ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 診断markerの一致を検査する.
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
		} finally {
			foreach ( array( $first, $second ) as $handle ) {
				if ( is_resource( $handle ) ) {
					flock( $handle, LOCK_UN );
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 診断handleを解放する.
				}
			}
			wp_delete_file( $source );
			wp_delete_file( $destination );
			$this->remove_tree( $directory );
		}
	}

	/**
	 * 全診断の時間予算を使い切る前に停止する。
	 *
	 * @param string $url 固定経路.
	 * @param array  $options 秘密を保存しないHTTP設定.
	 * @param float  $deadline 終了期限.
	 * @return array|WP_Error HTTP応答.
	 */
	private function probe_request( $url, $options, $deadline ) {
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		$options['timeout'] = min( 5, $remaining );
		return wp_remote_get( $url, $options );
	}

	/**
	 * 公開拒否の診断。Web設定の確認なしでは403だけを信用しない。
	 *
	 * @param array|null $http_auth 固定診断経路へのBasic認証。保存しない.
	 * @return true|WP_Error 利用可能.
	 */
	public function diagnose( $http_auth = null ) {
		$result = $this->read(
			function () use ( $http_auth ) {
				$this->scope();
				delete_option( 'odvr_storage_ready' );
				$root     = $this->root();
				$deadline = microtime( true ) + 20;
				if ( ! ODVR_PNG::available() || ! defined( 'ODVR_STORAGE_PROTECTION_VERIFIED' ) || true !== ODVR_STORAGE_PROTECTION_VERIFIED ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				$this->filesystem_probe();
				$bases = array( untrailingslashit( $this->uploads['baseurl'] ) );
				if ( defined( 'ODVR_STORAGE_PUBLIC_BASES' ) ) {
					if ( ! is_array( ODVR_STORAGE_PUBLIC_BASES ) ) {
						$this->fail( 'odvr_storage_unavailable', 503 );
					}
					$bases = array_unique( array_merge( $bases, ODVR_STORAGE_PUBLIC_BASES ) );
				}
				$marker  = 'odvr-canary-' . wp_generate_uuid4();
				$control = $this->uploads['basedir'] . '/' . $marker . '.txt';
				$paths   = array( $marker . '.txt', '.staging/' . $marker . '.txt', '.locks/' . $marker . '.txt' );
				try {
					$this->new_file( $control, $marker );
					foreach ( $paths as $path ) {
						$this->new_file( $root . '/' . $path, $marker );
					}
					foreach ( $bases as $base ) {
						if ( ! is_string( $base ) || strlen( $base ) > 8192 ) {
							$this->fail( 'odvr_storage_unavailable', 503 );
						}
						$parsed = wp_parse_url( $base );
						if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
							$this->fail( 'odvr_storage_unavailable', 503 );
						}
						$headers = array( 'Cache-Control' => 'no-cache, no-store' );

						if ( null !== $http_auth ) {
							if ( ! is_array( $http_auth ) || ! isset( $http_auth['origin'], $http_auth['username'], $http_auth['password'] ) || ! is_string( $http_auth['origin'] ) || ! preg_match( '#^https://[^/?\#]+$#D', $http_auth['origin'] ) || is_wp_error( ODVR_Target_URL::validate( $http_auth['origin'] ) ) || ! is_string( $http_auth['username'] ) || ! is_string( $http_auth['password'] ) || strlen( $http_auth['username'] ) > 1024 || strlen( $http_auth['password'] ) > 1024 ) {
								$this->fail( 'odvr_storage_unavailable', 503 );
							}
							$origin = $parsed['scheme'] . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
							if ( $http_auth['origin'] === $origin ) {
								$headers['Authorization'] = 'Basic ' . base64_encode( $http_auth['username'] . ':' . $http_auth['password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 指定HTTPS originだけへのBasic認証。値を保存・表示しない.
							}
						}

						$options  = array(
							'headers'             => $headers,
							'timeout'             => 5,
							'redirection'         => 0,
							'limit_response_size' => 256,
							'cookies'             => array(),
						);
						$response = $this->probe_request( $base . '/' . $marker . '.txt', $options, $deadline );
						if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || wp_remote_retrieve_body( $response ) !== $marker ) {
							$this->fail( 'odvr_storage_unavailable', 503 );
						}
						foreach ( $paths as $path ) {
							$response = $this->probe_request( $base . '/od-visual-regression/' . $path, $options, $deadline );
							if ( is_wp_error( $response ) || ! in_array( wp_remote_retrieve_response_code( $response ), array( 403, 404 ), true ) || false !== strpos( wp_remote_retrieve_body( $response ), $marker ) ) {
								$this->fail( 'odvr_storage_unavailable', 503 );
							}
						}
					}
					update_option(
						'odvr_storage_ready',
						array(
							'site'    => get_current_blog_id(),
							'root'    => $root,
							'base'    => $this->uploads['baseurl'],
							'bases'   => $bases,
							'expires' => time() + 120,
						),
						false
					);
					return true;
				} finally {
					foreach ( array_merge(
						array( $control ),
						array_map(
							function ( $path ) use ( $root ) {
								return $root . '/' . $path; },
							$paths
						)
					) as $path ) {
						if ( is_file( $path ) && ! is_link( $path ) ) {
							wp_delete_file( $path );
						}
					}
				}
			}
		);
		if ( is_wp_error( $result ) ) {
			// 並行診断の成功値が途中に保存されても、今回の失敗を保護済みと扱わない.
			$this->read(
				function () {
					$this->scope();
					delete_option( 'odvr_storage_ready' );
					return true;
				}
			);
		}
		return $result;
	}

	/**
	 * 短期間の診断成功だけを利用する。失効時は明示的な再診断が必要。
	 *
	 * @return true|WP_Error 利用可能.
	 */
	public function ready() {
		return $this->read(
			function () {
				$root  = $this->root();
				$state = get_option( 'odvr_storage_ready' );
				$bases = array( untrailingslashit( $this->uploads['baseurl'] ) );
				if ( defined( 'ODVR_STORAGE_PUBLIC_BASES' ) && is_array( ODVR_STORAGE_PUBLIC_BASES ) ) {
					$bases = array_unique( array_merge( $bases, ODVR_STORAGE_PUBLIC_BASES ) );
				}
				if ( ! defined( 'ODVR_STORAGE_PROTECTION_VERIFIED' ) || true !== ODVR_STORAGE_PROTECTION_VERIFIED || ! ODVR_PNG::available() || ! is_array( $state ) || ! isset( $state['site'], $state['root'], $state['base'], $state['bases'], $state['expires'] ) || get_current_blog_id() !== $state['site'] || $state['root'] !== $root || $state['base'] !== $this->uploads['baseurl'] || $state['bases'] !== $bases || $state['expires'] <= time() ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				return true;
			}
		);
	}

	/**
	 * 検証したPNGを不変な一時ファイルへ保存する。
	 *
	 * @param string $run_uuid Run UUID.
	 * @param string $data PNG.
	 * @param int    $width 幅.
	 * @param int    $height 高さ.
	 * @return array|WP_Error 内部用ticket。APIで返さない.
	 */
	public function stage( $run_uuid, $data, $width, $height ) {
		return $this->read(
			function () use ( $run_uuid, $data, $width, $height ) {
				$this->checked( $this->ready() );
				$info = $this->checked( ODVR_PNG::validate( $data, $width, $height ) );
				return $this->checked(
					$this->with_run_lock(
						$run_uuid,
						true,
						function () use ( $run_uuid, $data, $info ) {
							$directory = $this->directory( $this->root() . '/.staging/' . $this->uuid( $run_uuid ) );
							$request   = wp_generate_uuid4();
							$path      = $directory . '/' . $request . '.png';
							$this->new_file( $path, $data );
							return array(
								'run_uuid'     => $run_uuid,
								'request_uuid' => $request,
								'info'         => $info,
							);
						}
					)
				);
			}
		);
	}

	/**
	 * Run排他ロック内で、DB確定前に一時PNGを不変名へrenameする。
	 *
	 * @param array  $ticket stageの内部結果.
	 * @param string $suite_uuid Suite UUID.
	 * @param int    $target_id Target ID.
	 * @param string $slug Device slug.
	 * @param string $result_digest 結果digest.
	 * @param bool   $diff diff画像.
	 * @return string|WP_Error 相対パス。DB COMMITまでは配信しない.
	 */
	public function promote( $ticket, $suite_uuid, $target_id, $slug, $result_digest, $diff = false ) {
		return $this->read(
			function () use ( $ticket, $suite_uuid, $target_id, $slug, $result_digest, $diff ) {
				$this->checked( $this->ready() );
				if ( ! is_array( $ticket ) || ! isset( $ticket['run_uuid'], $ticket['request_uuid'], $ticket['info'] ) || ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,49}$/D', $slug ) || ! is_string( $result_digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $result_digest ) || ! is_bool( $diff ) ) {
					$this->fail( 'odvr_invalid_storage_path', 400 );
				}
				if ( ! isset( $this->run_locks[ $ticket['run_uuid'] ] ) || ! $this->run_locks[ $ticket['run_uuid'] ] ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				$root   = $this->root();
				$source = $root . '/.staging/' . $this->uuid( $ticket['run_uuid'] ) . '/' . $this->uuid( $ticket['request_uuid'] ) . '.png';
				$this->safe( $source );
				$data = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同一filesystemの検証.
				$this->checked( ODVR_PNG::validate( $data, $ticket['info']['width'], $ticket['info']['height'], $ticket['info']['sha256'] ) );
				$directory = 'suite-' . $this->uuid( $suite_uuid ) . '/run-' . $ticket['run_uuid'] . '/target-' . $this->id( $target_id );
				$path      = $directory . '/' . $slug . '-' . $result_digest . ( $diff ? '-diff' : '' ) . '.png';
				$this->directory( $root . '/' . $directory );
				if ( file_exists( $root . '/' . $path ) || is_link( $root . '/' . $path ) ) {
					$this->fail( 'odvr_storage_conflict', 409 );
				}
				if ( ! rename( $source, $root . '/' . $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 同一filesystemのatomic renameが必要.
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				return $path;
			}
		);
	}

	/**
	 * DBで確定した相対パスのPNGだけを読む。呼出側は共有Runロックを保持する。
	 *
	 * @param string $path DB相対パス.
	 * @param string $suite_uuid Suite UUID.
	 * @param string $run_uuid Run UUID.
	 * @param int    $target_id Target ID.
	 * @param int    $width 幅.
	 * @param int    $height 高さ.
	 * @param string $digest 画像digest.
	 * @return string|WP_Error 検証済みPNG.
	 */
	public function read_png( $path, $suite_uuid, $run_uuid, $target_id, $width, $height, $digest ) {
		return $this->read(
			function () use ( $path, $suite_uuid, $run_uuid, $target_id, $width, $height, $digest ) {
				if ( ! isset( $this->run_locks[ $run_uuid ] ) ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				$prefix = 'suite-' . $this->uuid( $suite_uuid ) . '/run-' . $this->uuid( $run_uuid ) . '/target-' . $this->id( $target_id ) . '/';
				if ( ! is_string( $path ) || 0 !== strpos( $path, $prefix ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,49}-[a-f0-9]{64}(?:-diff)?\.png$/D', substr( $path, strlen( $prefix ) ) ) ) {
					$this->fail( 'odvr_image_unavailable', 404 );
				}
				$absolute = $this->root() . '/' . $path;
				if ( ! file_exists( $absolute ) || is_link( $absolute ) ) {
					$this->fail( 'odvr_image_unavailable', 404 );
				}
				$this->safe( $absolute );
				if ( filesize( $absolute ) > ODVR_PNG::MAX_BYTES ) {
					$this->fail( 'odvr_image_unavailable', 404 );
				}
				$data   = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 検証する固定DBパス.
				$result = ODVR_PNG::validate( $data, $width, $height, $digest );
				if ( is_wp_error( $result ) ) {
					if ( 'odvr_storage_unavailable' === $result->get_error_code() ) {
						$this->checked( $result );
					}
					$this->fail( 'odvr_image_unavailable', 404 );
				}
				return $data;
			}
		);
	}
	/**
	 * 固定Runのディレクトリを削除する。DBのdeleting確定後に呼ぶ。
	 *
	 * @param int $run_id Run ID.
	 * @return true|WP_Error 削除結果.
	 */
	public function delete_run( $run_id ) {
		return $this->read(
			function () use ( $run_id ) {
				$run   = $this->row( 'runs', $run_id );
				$suite = $this->row( 'suites', $this->integer( $run['suite_id'], true ) );
				return $this->checked(
					$this->with_run_lock(
						$run['uuid'],
						true,
						function () use ( $run_id, $run, $suite ) {
							global $wpdb;
							$current = $this->row( 'runs', $run_id );
							if ( 'deleting' !== $current['status'] || null !== $current['runner_token_hash'] || $current['uuid'] !== $run['uuid'] || $current['suite_id'] !== $run['suite_id'] ) {
								$this->fail( 'odvr_storage_conflict', 409 );
							}
							$references = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE baseline_run_id = %d', ODVR_DB::table( 'suites' ), $run_id ) );
							$references = array_merge( $references, $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE reference_run_id = %d AND status <> %s', ODVR_DB::table( 'runs' ), $run_id, 'deleting' ) ) );
							if ( $references ) {
									$this->fail( 'odvr_storage_conflict', 409 );
							}
							$root = $this->root();
							$this->remove_tree( $root . '/suite-' . $this->uuid( $suite['uuid'] ) . '/run-' . $this->uuid( $run['uuid'] ) );
							$this->remove_tree( $root . '/.staging/' . $run['uuid'] );
							return true;
						}
					)
				);
			}
		);
	}

	/**
	 * 内部symlinkを辿らず内部ディレクトリを削除する。
	 *
	 * @param string $path 内部パス.
	 * @return void
	 */
	private function remove_tree( $path ) {
		if ( is_link( $path ) ) {
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
		if ( ! file_exists( $path ) ) {
			return;
		}
		$this->safe( $path, true );
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}
			if ( $entry->isLink() ) {
				$this->fail( 'odvr_storage_unavailable', 503 );
			}
			if ( $entry->isDir() ) {
				$this->remove_tree( $entry->getPathname() );
			} else {
				$this->safe( $entry->getPathname() );
				wp_delete_file( $entry->getPathname() );
				if ( file_exists( $entry->getPathname() ) ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
			}
		}
		if ( ! rmdir( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 空の内部ディレクトリだけを削除する.
			$this->fail( 'odvr_storage_unavailable', 503 );
		}
	}

	/**
	 * 1時間以上未更新のstaging・未参照PNGを、Run行→Storage順で排他削除する。
	 *
	 * @param string $run_uuid Run UUID.
	 * @return int|WP_Error 削除数.
	 */
	public function cleanup( $run_uuid ) {
		return $this->transaction(
			function () use ( $run_uuid ) {
				global $wpdb;
				$this->uuid( $run_uuid );
				$runs    = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s FOR UPDATE', ODVR_DB::table( 'runs' ), $run_uuid ) );
				$paths   = array();
				$folders = array( $this->root() . '/.staging/' . $run_uuid );
				if ( $runs ) {
						$run = $runs[0];
					if ( 'deleting' === $run['status'] ) {
						return 0;
					}
					$suite     = $this->row( 'suites', $this->integer( $run['suite_id'], true ) );
					$folders[] = $this->root() . '/suite-' . $this->uuid( $suite['uuid'] ) . '/run-' . $run_uuid;
					foreach ( $this->rows( $wpdb->prepare( 'SELECT image_path, diff_path FROM %i WHERE run_id = %d', ODVR_DB::table( 'snapshots' ), $this->integer( $run['id'], true ) ) ) as $snapshot ) {
						foreach ( array( 'image_path', 'diff_path' ) as $key ) {
							if ( is_string( $snapshot[ $key ] ) ) {
								$paths[ $this->root() . '/' . $snapshot[ $key ] ] = true;
							}
						}
					}
				}
				return $this->checked(
					$this->with_run_lock(
						$run_uuid,
						true,
						function () use ( $folders, $paths ) {
							$deleted = 0;
							foreach ( $folders as $folder ) {
								if ( is_link( $folder ) ) {
									$this->fail( 'odvr_storage_unavailable', 503 );
								}
								if ( ! is_dir( $folder ) ) {
									continue;
								}
									$this->safe( $folder, true );
									$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ) );
								foreach ( $iterator as $entry ) {
									if ( $entry->isLink() ) {
										$this->fail( 'odvr_storage_unavailable', 503 );
									}
									$path = $this->safe( $entry->getPathname() );
									if ( ! isset( $paths[ $path ] ) && $entry->getMTime() < time() - HOUR_IN_SECONDS ) {
										wp_delete_file( $path );
										if ( file_exists( $path ) ) {
											$this->fail( 'odvr_storage_unavailable', 503 );
										}
										++$deleted;
									}
								}
								if ( 0 === strpos( $folder, $this->root() . '/.staging/' ) && 2 === count( scandir( $folder ) ) ) {
									if ( ! rmdir( $folder ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 空の内部stagingだけを片付ける.
										$this->fail( 'odvr_storage_unavailable', 503 );
									}
								}
							}
							return $deleted;
						}
					)
				);
			}
		);
	}
	/** 定期処理の入口。エラーは次回へ持ち越し、画像を残す。 */
	public static function cleanup_cron() {
		( new self() )->cleanup_cycle();
	}

	/**
	 * 一時stagingとRun履歴を有限件数ずつ巡回する。
	 *
	 * @return true|WP_Error 処理結果.
	 */
	public function cleanup_cycle() {
		return $this->read(
			function () {
				global $wpdb;
				$root = $this->root();

				$count        = 0;
				$stage_cursor = get_option( 'odvr_storage_staging_cursor', '' );
				$stage_cursor = is_string( $stage_cursor ) ? $stage_cursor : '';
				$directories  = glob( $root . '/.staging/*', GLOB_ONLYDIR );
				if ( false === $directories ) {
					$this->fail( 'odvr_storage_unavailable', 503 );
				}
				foreach ( $directories as $directory ) {
					$name = basename( $directory );
					if ( strcmp( $name, $stage_cursor ) <= 0 ) {
						continue;
					}
					if ( is_link( $directory ) ) {
						$this->fail( 'odvr_storage_unavailable', 503 );
					}
					$this->checked( $this->cleanup( $name ) );
					update_option( 'odvr_storage_staging_cursor', $name, false );
					if ( ++$count >= 50 ) {
						break;
					}
				}
				if ( $count < 50 ) {
					update_option( 'odvr_storage_staging_cursor', '', false );
				}
				$cursor = $this->integer( get_option( 'odvr_storage_cleanup_cursor', 0 ) );
				$runs   = $this->rows( $wpdb->prepare( 'SELECT id, uuid FROM %i WHERE id > %d AND status <> %s ORDER BY id LIMIT 25', ODVR_DB::table( 'runs' ), $cursor, 'deleting' ) );
				foreach ( $runs as $run ) {
					$this->checked( $this->cleanup( $run['uuid'] ) );
					update_option( 'odvr_storage_cleanup_cursor', $this->integer( $run['id'], true ), false );
				}
				if ( ! $runs ) {
					update_option( 'odvr_storage_cleanup_cursor', 0, false );
				}
				return true;
			}
		);
	}
}
