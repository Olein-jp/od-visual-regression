<?php
/**
 * 重複項目を失わずに生multipartを検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** PHPの自動フォーム展開結果を入力に使わない。 */
final class ODVR_Multipart {
	/**
	 * 本文から結果とPNGを取得する。
	 *
	 * @param string $body 生本文.
	 * @param string $type Content-Type.
	 * @return array|WP_Error 検証済み入力.
	 */
	public static function parse( $body, $type ) {
		$error = new WP_Error( 'odvr_invalid_payload', '画像送信の形式を確認してください。', array( 'status' => 400 ) );
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'odvr_raw_upload_unavailable', '生のmultipart本文を取得できません。専用経路のPHP設定を確認してください。', array( 'status' => 503 ) );
		}
		if ( strlen( $body ) > 42 * 1024 * 1024 ) {
			return new WP_Error( 'odvr_payload_too_large', '画像送信の上限を超えています。', array( 'status' => 413 ) );
		}
		if ( ! preg_match( '/^multipart\/form-data;\s*boundary=(?:"([a-zA-Z0-9\x27()+_,.\/:=?-]{1,70})"|([a-zA-Z0-9\x27()+_,.\/:=?-]{1,70}))$/D', $type, $match ) ) {
			return $error;
		}
		$boundary = '--' . ( '' !== $match[1] ? $match[1] : $match[2] );
		if ( substr( $body, 0, strlen( $boundary ) + 2 ) !== $boundary . "\r\n" || substr( $body, -strlen( $boundary ) - 6 ) !== "\r\n" . $boundary . "--\r\n" ) {
			return $error;
		}
		$parts        = explode( "\r\n" . $boundary . "\r\n", substr( $body, strlen( $boundary ) + 2, -strlen( $boundary ) - 6 ) );
		$fields       = array();
		$files        = array();
		$scalar_bytes = 0;
		$integers     = array( 'schema_version', 'target_id', 'device_id', 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'duration_ms', 'http_status' );
		$strings      = array( 'status', 'error_code', 'error_message', 'no_baseline_reason' );
		$nullable     = array( 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'diff_ratio', 'http_status', 'error_code', 'error_message', 'no_baseline_reason' );
		if ( count( $parts ) > 20 ) {
			return $error;
		}
		foreach ( $parts as $part ) {
			$split = explode( "\r\n\r\n", $part, 2 );
			if ( 2 !== count( $split ) || strlen( $split[0] ) > 2048 ) {
				return $error;
			}
			if ( ! preg_match( '/^Content-Disposition: form-data; name="([a-z_]+)"(?:; filename="[^"\r\n]{0,255}")?(?:\r\nContent-Type: ([a-zA-Z0-9.+\/-]+))?$/Di', $split[0], $headers ) ) {
				return $error;
			}
			$name = $headers[1];
			if ( array_key_exists( $name, $fields ) || array_key_exists( $name, $files ) ) {
				return $error;
			}
			$is_file = false !== strpos( strtolower( $split[0] ), '; filename=' );
			$value   = $split[1];
			if ( in_array( $name, array( 'image', 'diff_image' ), true ) ) {
				if ( ! $is_file || 'image/png' !== ( $headers[2] ?? '' ) || '' === $value || strlen( $value ) > 20 * 1024 * 1024 ) {
					return $error;
				}
				$files[ $name ] = $value;
				continue;
			}
			$scalar_bytes += strlen( $value );
			if ( $is_file || $scalar_bytes > 65536 || isset( $headers[2] ) ) {
				return $error;
			}
			if ( 'null' === $value && in_array( $name, $nullable, true ) ) {
				$fields[ $name ] = null;
			} elseif ( in_array( $name, $integers, true ) && preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $value ) && (float) $value <= 2147483647 ) {
				$fields[ $name ] = (int) $value;
			} elseif ( 'dimension_changed' === $name && in_array( $value, array( 'true', 'false' ), true ) ) {
				$fields[ $name ] = 'true' === $value;
			} elseif ( 'diff_ratio' === $name && preg_match( '/^(?:0(?:\.[0-9]{1,15})?|1(?:\.0{1,15})?)$/D', $value ) ) {
				$fields[ $name ] = (float) $value;
			} elseif ( in_array( $name, $strings, true ) ) {
				$fields[ $name ] = $value;
			} else {
				return $error;
			}
		}
		$result = (object) $fields;
		$valid  = ( new ODVR_Contract_Validator() )->validate( 'snapshot-result', $result );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$expected = 'ERROR' === $result->status ? array() : ( in_array( $result->status, array( 'UNCHANGED', 'REVIEW', 'CHANGED' ), true ) ? array( 'image', 'diff_image' ) : array( 'image' ) );
		if ( array_diff( $expected, array_keys( $files ) ) || array_diff( array_keys( $files ), $expected ) ) {
			return $error;
		}
		return array(
			'result' => $result,
			'files'  => $files,
		);
	}
}
