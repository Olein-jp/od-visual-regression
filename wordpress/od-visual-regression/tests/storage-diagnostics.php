<?php
/**
 * 複数公開経路とBasicのorigin限定を固定HTTP fixtureで検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

// 外部へ通信せず、秘密でない固定認証値だけを使う.
// phpcs:disable WordPress.PHP.IniSet.memory_limit_Disallowed, WordPress.PHP.DiscouragedPHPFunctions

/**
 * 固定fixture条件を確認する。
 *
 * @param bool   $valid 条件.
 * @param string $message 説明.
 * @return void
 * @throws RuntimeException 失敗.
 */
function odvr_storage_diagnostic_assert( $valid, $message ) {
	if ( ! $valid ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	WP_CLI::log( '確認済み: ' . $message );
}

/**
 * 公開canary診断がaliasを省かず、Basicを別originへ転送しないことを確認する。
 *
 * @return void
 */
function odvr_test_storage_diagnostics() {
	$old_memory = ini_get( 'memory_limit' );
	$old_ready  = get_option( 'odvr_storage_ready', null );
	ini_set( 'memory_limit', '1024M' );
	define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
	define( 'ODVR_STORAGE_PUBLIC_BASES', array( 'https://alias.fixture.test/uploads' ) );
	$filter = function ( $uploads ) {
		$uploads['baseurl'] = 'https://primary.fixture.test/uploads';
		return $uploads;
	};
	add_filter( 'upload_dir', $filter );
	try {
		foreach ( array( 'passed', 'wrong-origin', 'basic401', 'alias-exposed', 'redirect', 'offline', 'waf' ) as $mode ) {
			$primary = 0;
			$alias   = 0;
			$mock    = function ( $pre, $options, $url ) use ( $mode, &$primary, &$alias ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				odvr_storage_diagnostic_assert( in_array( $host, array( 'primary.fixture.test', 'alias.fixture.test' ), true ) && 0 === $options['redirection'] && array() === $options['cookies'], '固定経路・redirectなし・Cookieなしで診断する' );
				$storage = false !== strpos( $url, '/od-visual-regression/' );
				if ( 'primary.fixture.test' === $host ) {
					++$primary;
					if ( 'wrong-origin' === $mode ) {
						odvr_storage_diagnostic_assert( ! isset( $options['headers']['Authorization'] ), '異なるoriginへBasicを注入しない' );
					} else {
						odvr_storage_diagnostic_assert( isset( $options['headers']['Authorization'] ) && 'Basic ' . base64_encode( 'fixture-only:test-password' ) === $options['headers']['Authorization'], '指定HTTPS originだけへ固定Basicを注入する' );
					}
				} else {
					++$alias;
					odvr_storage_diagnostic_assert( ! isset( $options['headers']['Authorization'] ), '追加aliasへBasicを転送しない' );
				}
				if ( 'offline' === $mode ) {
					return new WP_Error( 'fixture_offline', '固定通信失敗' );
				}
				$code = $storage ? 403 : 200;
				if ( 'waf' === $mode ) {
					$code = 403;
				} elseif ( 'wrong-origin' === $mode || ( 'basic401' === $mode && $storage ) ) {
					$code = 401;
					if ( 'basic401' === $mode ) {
						update_option( 'odvr_storage_ready', array( 'fixture' => '途中の別診断' ), false );
					}
				} elseif ( 'alias-exposed' === $mode && 'alias.fixture.test' === $host && $storage ) {
					$code = 200;
				} elseif ( 'redirect' === $mode && $storage ) {
					$code = 302;
				}
				return array(
					'headers'  => array(),
					'body'     => $storage ? '' : basename( $url, '.txt' ),
					'response' => array(
						'code'    => $code,
						'message' => 'fixture',
					),
					'cookies'  => array(),
				);
			};
			add_filter( 'pre_http_request', $mock, 10, 3 );
			try {
				$storage = new ODVR_Storage();
				$auth    = array(
					'origin'   => 'wrong-origin' === $mode ? 'https://other.fixture.test' : 'https://primary.fixture.test',
					'username' => 'fixture-only',
					'password' => 'test-password',
				);
				$result  = $storage->diagnose( $auth );
				odvr_storage_diagnostic_assert( 'passed' === $mode ? true === $result && 4 === $primary && 4 === $alias : is_wp_error( $result ) && is_wp_error( $storage->ready() ) && false === get_option( 'odvr_storage_ready', false ), '全経路が確認できた場合だけreadyにする' );
			} finally {
				remove_filter( 'pre_http_request', $mock, 10 );
			}
		}
	} finally {
		remove_filter( 'upload_dir', $filter );
		if ( null === $old_ready ) {
			delete_option( 'odvr_storage_ready' );
		} else {
			update_option( 'odvr_storage_ready', $old_ready, false );
		}
		ini_set( 'memory_limit', $old_memory );
	}
	WP_CLI::success( 'Storageの経路別診断と認証範囲の検証が完了しました。' );
}
odvr_test_storage_diagnostics();
