<?php
/**
 * RunnerのBearer Tokenを現在サイトのRunへ限定する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/** 権限をWordPressユーザーへ変換しない。 */
final class ODVR_Runner_Auth extends ODVR_Run_Repository {
	/**
	 * TTL・Hash・状態と操作scopeを検証する。
	 *
	 * @param string      $authorization Authorization header.
	 * @param string|null $uuid 指定Run。Baseline読取だけnullでHashから解決する.
	 * @param string      $operation manifest/credentials/baseline/upload/progress/complete.
	 * @return array|WP_Error 内部Run行.
	 */
	public function authorize( $authorization, $uuid, $operation ) {
		$unauthorized = new WP_Error( 'odvr_runner_unauthorized', 'Runner認証を確認してください。', array( 'status' => 401 ) );
		if ( true !== ODVR_DB::writable() || ! is_string( $authorization ) || ! preg_match( '/^Bearer ([A-Za-z0-9_-]{43})$/D', $authorization, $matches ) ) {
			return $unauthorized;
		}
		$result = $this->read(
			function () use ( $matches, $uuid, $operation, $unauthorized ) {
				global $wpdb;
				$hash = hash( 'sha256', $matches[1] );
				if ( null === $uuid ) {
						$rows = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE runner_token_hash = %s LIMIT 2', ODVR_DB::table( 'runs' ), $hash ) );
					if ( 1 !== count( $rows ) ) {
						return $unauthorized;
					}
					$row = $rows[0];
				} else {
					$row = $this->uuid_row( $uuid );
				}
				if ( ! is_string( $row['runner_token_hash'] ) || ! hash_equals( $row['runner_token_hash'], $hash ) || null === $row['runner_token_expires_at'] || ODVR_DB::utc_now() >= $row['runner_token_expires_at'] ) {
					return $unauthorized;
				}
				$this->checked( ( new ODVR_Run_Manager() )->expire( $this->integer( $row['id'], true ) ) );
				$row = $this->uuid_row( $row['uuid'] );
				if ( ! is_string( $row['runner_token_hash'] ) || ! hash_equals( $row['runner_token_hash'], $hash ) || ODVR_DB::utc_now() >= $row['runner_token_expires_at'] || ! in_array( $row['status'], array( 'queued', 'running', 'complete', 'partial', 'failed' ), true ) ) {
					return $unauthorized;
				}
				if ( ! in_array( $operation, array( 'manifest', 'credentials', 'baseline', 'upload', 'progress', 'complete' ), true ) || ( 'queued' === $row['status'] && 'manifest' !== $operation ) || ( in_array( $row['status'], array( 'complete', 'partial', 'failed' ), true ) && 'complete' !== $operation ) ) {
					$this->fail( 'odvr_run_closed', 409 );
				}
				if ( in_array( $row['status'], array( 'complete', 'partial', 'failed' ), true ) ) {
					$environment = $this->checked( ODVR_Environment::decode( $row['environment'] ) );
					if ( null === $environment->completion ) {
						return $unauthorized;
					}
				}
				return $row;
			}
		);
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'odvr_not_found', 'odvr_invalid_id', 'odvr_site_mismatch' ), true ) ) {
			return $unauthorized;
		}
		return $result;
	}
	/**
	 * 終端後の期限切れHashを最大25件回収する。認証期限は巡回に依存しない。
	 *
	 * @return true|WP_Error 結果.
	 */
	public function clean_expired() {
		return $this->read(
			function () {
				global $wpdb;
				$this->checked( ODVR_DB::writable() );
				$rows = $this->rows( $wpdb->prepare( 'SELECT id, suite_id FROM %i WHERE status IN (%s, %s, %s) AND runner_token_hash IS NOT NULL AND runner_token_expires_at <= %s ORDER BY id LIMIT 25', ODVR_DB::table( 'runs' ), 'complete', 'partial', 'failed', ODVR_DB::utc_now() ) );
				foreach ( $rows as $row ) {
						$this->checked(
							$this->transaction(
								function () use ( $row ) {
									global $wpdb;
									$this->row( 'suites', $this->integer( $row['suite_id'], true ), true );
									$current = $this->row( 'runs', $this->integer( $row['id'], true ), true );
									if ( in_array( $current['status'], array( 'complete', 'partial', 'failed' ), true ) && $current['runner_token_expires_at'] <= ODVR_DB::utc_now() ) {
												$this->written( $wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_token_hash' => null ), array( 'id' => $this->integer( $current['id'], true ) ) ) );
									}
									return true;
								}
							)
						);
				}
				return true;
			}
		);
	}
	/** 期限処理と同じ停止可能なCronでHashを回収する。 */
	public static function cleanup_cron() {
		( new self() )->clean_expired();
	}
}
