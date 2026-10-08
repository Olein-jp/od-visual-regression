<?php
/**
 * 同梱Schemaによる製品契約の検証。PHP 7.4で動作する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON objectはstdClassで受け取り、配列と空objectを区別する。
 */
final class ODVR_Contract_Validator {
	const COMMUNICATION_VERSION = 1;
	const SETTINGS_VERSION      = 1;
	const ENVIRONMENT_VERSION   = 1;
	const METADATA_VERSION      = 1;
	const DATABASE_VERSION      = 1;

	/**
	 * 読み込んだSchema。
	 *
	 * @var array
	 */
	private $schemas = array();

	/**
	 * Schemaをすべて読み、未対応keywordを拒否する。
	 *
	 * @param string|null $directory 配布Schemaディレクトリ.
	 * @throws RuntimeException 未対応のSchemaがある場合.
	 */
	public function __construct( $directory = null ) {
		$directory = null === $directory ? dirname( __DIR__ ) . '/schemas' : $directory;
		$files     = glob( $directory . '/*.schema.json' );
		if ( ! $files ) {
			throw new RuntimeException( 'odvr_contract_unavailable' );
		}
		foreach ( $files as $file ) {
			// 固定した同梱Schemaのみを読む。通信や利用者指定パスではない.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$schema = json_decode( file_get_contents( $file ), true );
			if ( ! is_array( $schema ) || 'http://json-schema.org/draft-07/schema#' !== ( $schema['$schema'] ?? null ) ) {
				throw new RuntimeException( 'odvr_contract_unavailable' );
			}
			$this->check_keywords( $schema );
			$this->schemas[ basename( $file, '.schema.json' ) ] = $schema;
		}
	}

	/**
	 * 使用できるkeywordとformatを固定する。
	 *
	 * @param array $schema 検査対象.
	 * @throws RuntimeException 未対応keywordの場合.
	 */
	private function check_keywords( array $schema ) {
		$allowed = array( '$schema', '$id', 'type', 'properties', 'required', 'additionalProperties', 'minProperties', 'items', 'minItems', 'maxItems', 'uniqueItems', 'enum', 'const', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'format', 'anyOf', 'allOf', 'if', 'then', 'else', 'not' );
		foreach ( $schema as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) || ( 'format' === $key && ! in_array( $value, array( 'uri', 'date-time' ), true ) ) || ( 'additionalProperties' === $key && false !== $value ) ) {
				throw new RuntimeException( 'odvr_unsupported_schema_keyword' );
			}
			if ( 'properties' === $key ) {
				foreach ( $value as $child ) {
					$this->check_keywords( $child );
				}
			} elseif ( in_array( $key, array( 'items', 'if', 'then', 'else', 'not' ), true ) ) {
				$this->check_keywords( $value );
			} elseif ( in_array( $key, array( 'anyOf', 'allOf' ), true ) ) {
				foreach ( $value as $child ) {
					$this->check_keywords( $child );
				}
			}
		}
	}

	/**
	 * 通信契約を検証する。sanitizeで不正値を補正しない。
	 *
	 * @param string $name 契約名.
	 * @param mixed  $value JSON値（objectはstdClass）.
	 * @param array  $context 固定閾値・reference_run_idなど.
	 * @return true|WP_Error 結果.
	 */
	public function validate( $name, $value, array $context = array() ) {
		$value = $this->normalize_numbers( $value );
		if ( ! isset( $this->schemas[ $name ] ) ) {
			return $this->invalid( 'odvr_unknown_contract' );
		}
		if ( is_object( $value ) && property_exists( $value, 'schema_version' ) && 1 !== $value->schema_version ) {
			return $this->invalid( 'odvr_unsupported_schema_version' );
		}
		if ( ! $this->matches( $value, $this->schemas[ $name ] ) || ! $this->semantic( $name, $value, $context ) ) {
			return $this->invalid( 'odvr_invalid_payload' );
		}
		return true;
	}

	/**
	 * 保存JSONのVersionを通信・DB・ソフトウェアVersionと独立に検証する。
	 *
	 * @param string $kind settings / environment / metadata.
	 * @param mixed  $version 保存Version.
	 * @return true|WP_Error 結果.
	 */
	public function validate_stored_version( $kind, $version ) {
		if ( ! in_array( $kind, array( 'settings', 'environment', 'metadata' ), true ) || 1 !== $this->normalize_numbers( $version ) ) {
			return $this->invalid( 'odvr_unsupported_storage_version' );
		}
		return true;
	}

	/**
	 * JSONの整数値をPHP整数へそろえる（数値文字列は変換しない）。
	 *
	 * @param mixed $value JSON値.
	 * @return mixed 正規化した値.
	 */
	private function normalize_numbers( $value ) {
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && $value <= PHP_INT_MAX && $value >= PHP_INT_MIN ) {
			return (int) $value;
		}
		if ( $value instanceof stdClass ) {
			$value = clone $value;
			foreach ( $value as $key => $child ) {
				$value->$key = $this->normalize_numbers( $child );
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->normalize_numbers( $child );
			}
		}
		return $value;
	}

	/**
	 * 順序付きscalar部品を変換する。連想配列では重複が失われるので受け付けない。
	 *
	 * @param array $parts array(name, value)のリスト.
	 * @return stdClass|WP_Error 変換結果（契約検証は別途必要）.
	 */
	public function convert_multipart( array $parts ) {
		$result   = new stdClass();
		$nullable = array( 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'diff_ratio', 'http_status', 'error_code', 'error_message', 'no_baseline_reason' );
		$integers = array( 'schema_version', 'target_id', 'device_id', 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'duration_ms', 'http_status' );
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || array_keys( $part ) !== array( 0, 1 ) || ! is_string( $part[0] ) || ! is_string( $part[1] ) ) {
				return $this->invalid( 'odvr_invalid_payload' );
			}
			list( $key, $value ) = $part;
			if ( ! isset( $this->schemas['snapshot-result']['properties'][ $key ] ) || property_exists( $result, $key ) ) {
				return $this->invalid( 'odvr_invalid_payload' );
			}
			if ( 'null' === $value && in_array( $key, $nullable, true ) ) {
				$value = null;
			} elseif ( in_array( $key, $integers, true ) ) {
				if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || strlen( $value ) > 10 || (float) $value > 2147483647 ) {
					return $this->invalid( 'odvr_invalid_payload' );
				}
				$value = (int) $value;
			} elseif ( 'diff_ratio' === $key ) {
				if ( ! preg_match( '/^(0(?:\.[0-9]{1,15})?|1(?:\.0{1,15})?)$/D', $value ) ) {
					return $this->invalid( 'odvr_invalid_payload' );
				}
				$value = (float) $value;
			} elseif ( 'dimension_changed' === $key ) {
				if ( ! in_array( $value, array( 'true', 'false' ), true ) ) {
					return $this->invalid( 'odvr_invalid_payload' );
				}
				$value = 'true' === $value;
			}
			$result->$key = $value;
		}
		return $result;
	}

	/**
	 * Draft-07の採用keywordだけを厳格に評価する。
	 *
	 * @param mixed $value JSON値.
	 * @param array $schema Schema.
	 * @return bool 判定.
	 */
	private function matches( $value, array $schema ) {
		if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
			return false;
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return false;
		}
		if ( isset( $schema['anyOf'] ) ) {
			$matched = false;
			foreach ( $schema['anyOf'] as $child ) {
				$matched = $matched || $this->matches( $value, $child );
			}
			if ( ! $matched ) {
				return false;
			}
		}
		foreach ( $schema['allOf'] ?? array() as $child ) {
			if ( ! $this->matches( $value, $child ) ) {
				return false;
			}
		}
		if ( isset( $schema['not'] ) && $this->matches( $value, $schema['not'] ) ) {
			return false;
		}
		if ( isset( $schema['if'] ) ) {
			$branch = $this->matches( $value, $schema['if'] ) ? 'then' : 'else';
			if ( isset( $schema[ $branch ] ) && ! $this->matches( $value, $schema[ $branch ] ) ) {
				return false;
			}
		}
		if ( isset( $schema['type'] ) ) {
			$type  = $schema['type'];
			$valid = ( 'null' === $type && null === $value ) || ( 'object' === $type && $value instanceof stdClass ) || ( 'array' === $type && is_array( $value ) ) || ( 'string' === $type && is_string( $value ) ) || ( 'boolean' === $type && is_bool( $value ) ) || ( 'integer' === $type && ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) ) ) || ( 'number' === $type && ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) );
			if ( ! $valid ) {
				return false;
			}
			// WordPressの基本検証も通す。型補正を認める前に上で厳格検査する.
			$basic = array_intersect_key( $schema, array_flip( array( 'type', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems' ) ) );
			if ( 'object' !== $type && 'null' !== $type && is_wp_error( rest_validate_value_from_schema( $value, $basic, '' ) ) ) {
				return false;
			}
		}
		if ( $value instanceof stdClass ) {
			$fields = get_object_vars( $value );
			foreach ( $schema['required'] ?? array() as $key ) {
				if ( ! array_key_exists( $key, $fields ) ) {
					return false;
				}
			}
			if ( count( $fields ) < ( $schema['minProperties'] ?? 0 ) ) {
				return false;
			}
			foreach ( $fields as $key => $child ) {
				if ( isset( $schema['additionalProperties'] ) && ! array_key_exists( $key, $schema['properties'] ?? array() ) ) {
					return false;
				}
				if ( isset( $schema['properties'][ $key ] ) && ! $this->matches( $child, $schema['properties'][ $key ] ) ) {
					return false;
				}
			}
		}
		if ( is_array( $value ) ) {
			if ( count( $value ) < ( $schema['minItems'] ?? 0 ) || count( $value ) > ( $schema['maxItems'] ?? PHP_INT_MAX ) ) {
				return false;
			}
			if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'wp_json_encode', $value ) ) ) !== count( $value ) ) {
				return false;
			}
			foreach ( $value as $child ) {
				if ( isset( $schema['items'] ) && ! $this->matches( $child, $schema['items'] ) ) {
					return false;
				}
			}
		}
		if ( is_string( $value ) ) {
			$length = preg_match_all( '/./us', $value );
			if ( false === $length || $length < ( $schema['minLength'] ?? 0 ) || $length > ( $schema['maxLength'] ?? PHP_INT_MAX ) || ( isset( $schema['pattern'] ) && ! preg_match( '~' . $schema['pattern'] . '~uD', $value ) ) ) {
				return false;
			}
			if ( isset( $schema['format'] ) && 'date-time' === $schema['format'] && ! $this->utc( $value ) ) {
				return false;
			}
			if ( isset( $schema['format'] ) && 'uri' === $schema['format'] && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return false;
			}
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && ( ! is_finite( (float) $value ) || $value < ( $schema['minimum'] ?? -INF ) || $value > ( $schema['maximum'] ?? INF ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * UTC秒精度の実在する日時だけを受け付ける。
	 *
	 * @param string $value 日時.
	 * @return bool 判定.
	 */
	private function utc( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value;
	}

	/**
	 * HTTP URLと正規化Originを検査する。通信は行わない。
	 *
	 * @param string $value URL.
	 * @param bool   $origin Originのみか.
	 * @param bool   $https HTTPS必須か.
	 * @return bool 判定.
	 */
	private function url( $value, $origin = false, $https = false ) {
		if ( strlen( $value ) > 2048 || preg_match( '/[^\x21-\x7e]|\\\\/', $value ) || preg_match( '/%(?![0-9a-f]{2})|%0[0-9a-f]|%1[0-9a-f]|%7f/i', $value ) || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$p = wp_parse_url( $value );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( $https && 'https' !== strtolower( $p['scheme'] ) ) || ( isset( $p['port'] ) && ( $p['port'] < 1 || $p['port'] > 65535 ) ) ) {
			return false;
		}
		// ブラウザが別IPへ正規化する数値ホスト表記を許可しない.
		if ( preg_match( '/(?:^|\.)[0-9]+$/D', $p['host'] ) && ! filter_var( $p['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		if ( preg_match( '/^0x[0-9a-f]+$/iD', $p['host'] ) ) {
			return false;
		}
		$host = strtolower( $p['host'] );
		if ( '[' === substr( $host, 0, 1 ) ) {
			$binary = inet_pton( substr( $host, 1, -1 ) );
			if ( false === $binary ) {
				return false;
			}
			$host = '[' . inet_ntop( $binary ) . ']';
		}
		$normalized = strtolower( $p['scheme'] ) . '://' . $host;
		if ( isset( $p['port'] ) && ! ( ( 'https' === $p['scheme'] && 443 === $p['port'] ) || ( 'http' === $p['scheme'] && 80 === $p['port'] ) ) ) {
			$normalized .= ':' . $p['port'];
		}
		return ! $origin || $value === $normalized;
	}

	/**
	 * 保存設定等の共通相互条件を再帰検証する。
	 *
	 * @param mixed $value JSON値.
	 * @return bool 判定.
	 */
	private function walk( $value ) {
		if ( ! is_object( $value ) && ! is_array( $value ) ) {
			return true;
		}
		if ( isset( $value->review_threshold ) && $value->review_threshold >= $value->changed_threshold ) {
			return false;
		}
		if ( isset( $value->mode ) && property_exists( $value, 'count' ) && ( 'all' === $value->mode ? null !== $value->count : ! is_int( $value->count ) || $value->count < 1 ) ) {
			return false;
		}
		if ( is_object( $value ) && property_exists( $value, 'object_id' ) && property_exists( $value, 'post_type' ) && ( null === $value->object_id ? '' !== $value->post_type : '' === $value->post_type ) ) {
			return false;
		}
		foreach ( $value as $field => $child ) {
			if ( is_string( $child ) ) {
				if ( in_array( $field, array( 'url', 'site_url', 'dispatcher_url', 'callback_base', 'http_auth_origin', 'origin' ), true ) ) {
					if ( ! $this->url( $child, in_array( $field, array( 'http_auth_origin', 'origin' ), true ), in_array( $field, array( 'dispatcher_url', 'callback_base', 'http_auth_origin', 'origin' ), true ) ) || ( in_array( $field, array( 'dispatcher_url', 'callback_base' ), true ) && ( false !== strpos( $child, '?' ) || false !== strpos( $child, '#' ) ) ) ) {
						return false;
					}
				}
				if ( in_array( $field, array( 'created_at', 'deadline_at', 'completed_at', 'checked_at' ), true ) && ! $this->utc( $child ) ) {
					return false;
				}
			}
			if ( 'allowed_origins' === $field ) {
				foreach ( $child as $origin ) {
					if ( ! $this->url( $origin, true ) ) {
						return false;
					}
				}
			}
			if ( ! $this->walk( $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 契約に固有の参照・集計・比率を検証する。
	 *
	 * @param string   $name 契約名.
	 * @param stdClass $value 入力.
	 * @param array    $context 固定設定.
	 * @return bool 判定.
	 */
	private function semantic( $name, $value, array $context ) {
		if ( ! $this->walk( $value ) ) {
			return false;
		}
		if ( 'run-manifest' === $name ) {
			if ( $value->run->suite_id !== $value->suite->id || $value->run->created_at >= $value->run->deadline_at ) {
				return false;
			}
			foreach ( array( array( $value->targets, array( 'id' ) ), array( $value->devices, array( 'id', 'slug' ) ), array( $value->run->snapshot_states, array( 'snapshot_id' ) ) ) as $group ) {
				foreach ( $group[1] as $key ) {
					$ids = array_column( $group[0], $key );
					if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
						return false;
					}
				}
			}
			$pairs = array();
			foreach ( $value->targets as $target ) {
				$p    = wp_parse_url( $target->url );
				$host = strtolower( $p['host'] );
				if ( '[' === substr( $host, 0, 1 ) ) {
					$host = '[' . inet_ntop( inet_pton( substr( $host, 1, -1 ) ) ) . ']';
				}
				$origin = strtolower( $p['scheme'] ) . '://' . $host;
				if ( isset( $p['port'] ) && ! ( ( 'https' === strtolower( $p['scheme'] ) && 443 === $p['port'] ) || ( 'http' === strtolower( $p['scheme'] ) && 80 === $p['port'] ) ) ) {
					$origin .= ':' . $p['port'];
				}
				if ( ! in_array( $origin, $value->allowed_origins, true ) ) {
					return false;
				}
				foreach ( $value->devices as $device ) {
					$pairs[] = $target->id . ':' . $device->id;
				}
			}
			foreach ( array( $value->run->snapshot_states, $value->reference->snapshots ) as $entries ) {
				$found = array();
				foreach ( $entries as $entry ) {
					$found[] = $entry->target_id . ':' . $entry->device_id;
				}
				if ( count( $found ) !== count( $pairs ) || count( array_unique( $found ) ) !== count( $pairs ) || array_diff( $found, $pairs ) ) {
					return false;
				}
			}
			foreach ( $value->reference->snapshots as $ref ) {
				if ( ( null === $ref->baseline_snapshot_id ? null === $ref->reason : null !== $ref->reason ) || ( null === $value->reference->run_id ? null !== $ref->baseline_snapshot_id || 'no_reference' !== $ref->reason : 'no_reference' === $ref->reason ) ) {
					return false;
				}
			}
			if ( 'specific' === $value->reference->mode && null === $value->reference->run_id ) {
				return false;
			}
		}
		if ( in_array( $name, array( 'run-state', 'run-response', 'run-list-response' ), true ) ) {
			$items = 'run-state' === $name ? array( $value ) : ( 'run-response' === $name ? array( $value->item ) : $value->items );
			foreach ( $items as $item ) {
				if ( $item->completed_snapshots + $item->error_snapshots + $item->pending_snapshots !== $item->total_snapshots || ( in_array( $item->status, array( 'queued', 'running' ), true ) ? null !== $item->completed_at : ( 'deleting' !== $item->status && null === $item->completed_at ) ) || ( in_array( $item->status, array( 'complete', 'partial', 'failed' ), true ) && 0 !== $item->pending_snapshots ) || ( 'complete' === $item->status && ( 0 !== $item->error_snapshots || 0 === $item->completed_snapshots ) ) || ( 'partial' === $item->status && ( 0 === $item->error_snapshots || 0 === $item->completed_snapshots ) ) ) {
					return false;
				}
			}
		}
		if ( 'dispatch-response' === $name && ( 'accepted' === $value->status ? null !== $value->runner_execution_id : null === $value->runner_execution_id ) ) {
			return false;
		}
		if ( in_array( $name, array( 'settings-response', 'settings-patch-request' ), true ) ) {
			$item = $value->item ?? $value;
			if ( ( isset( $item->queued_timeout_seconds, $item->run_timeout_seconds ) && $item->queued_timeout_seconds >= $item->run_timeout_seconds ) || ( 'settings-response' === $name && ( $item->http_auth_configured ? null === $item->http_auth_origin : null !== $item->http_auth_origin ) ) ) {
				return false;
			}
		}
		if ( in_array( $name, array( 'snapshot-result', 'snapshot-response', 'snapshot-list-response' ), true ) ) {
			$items = 'snapshot-result' === $name ? array( $value ) : ( 'snapshot-response' === $name ? array( $value->item ) : $value->items );
			foreach ( $items as $item ) {
				if ( ! $this->snapshot( $item, $context ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Snapshotの寸法・差分・固定設定を検査する。
	 *
	 * @param stdClass $item Snapshot.
	 * @param array    $context 固定設定.
	 * @return bool 判定.
	 */
	private function snapshot( $item, array $context ) {
		$compared = in_array( $item->status, array( 'UNCHANGED', 'REVIEW', 'CHANGED' ), true );
		if ( 'PENDING' === $item->status ) {
			foreach ( array( 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'diff_ratio', 'http_status', 'error_code', 'error_message', 'no_baseline_reason' ) as $key ) {
				if ( null !== $item->$key ) {
					return false;
				}
			}
			return false === $item->dimension_changed && 0 === $item->duration_ms && ( ! property_exists( $item, 'has_current_image' ) || ( false === $item->has_current_image && false === $item->has_diff_image ) );
		}
		if ( null !== $item->width && $item->width * $item->height > 40000000 ) {
			return false;
		}
		if ( $compared ) {
			$area = max( $item->width, $item->baseline_width ) * max( $item->height, $item->baseline_height );
			if ( $item->baseline_width * $item->baseline_height > 40000000 || 40000000 < $area || $item->total_pixels !== $area || $item->diff_pixels > $area || abs( $item->diff_ratio - $item->diff_pixels / $area ) > 1e-10 || ( $item->width !== $item->baseline_width || $item->height !== $item->baseline_height ) !== $item->dimension_changed ) {
				return false;
			}
			if ( isset( $context['settings'] ) ) {
				$s = (object) $context['settings'];
				if ( ! isset( $s->review_threshold, $s->changed_threshold ) || ! ( is_int( $s->review_threshold ) || is_float( $s->review_threshold ) ) || ! ( is_int( $s->changed_threshold ) || is_float( $s->changed_threshold ) ) || ! is_finite( (float) $s->review_threshold ) || ! is_finite( (float) $s->changed_threshold ) || $s->review_threshold < 0 || $s->review_threshold >= $s->changed_threshold || $s->changed_threshold > 1 ) {
					return false;
				}
				$status = $item->diff_ratio >= $s->changed_threshold ? 'CHANGED' : ( $item->diff_ratio > $s->review_threshold ? 'REVIEW' : 'UNCHANGED' );
				if ( $status !== $item->status ) {
					return false;
				}
			}
		}
		if ( array_key_exists( 'reference_run_id', $context ) && ( ( 'CAPTURED' === $item->status && null !== $context['reference_run_id'] ) || ( ( 'NO_BASELINE' === $item->status || $compared ) && null === $context['reference_run_id'] ) ) ) {
			return false;
		}
		return ! property_exists( $item, 'has_current_image' ) || ( ! in_array( $item->status, array( 'PENDING', 'ERROR' ), true ) === $item->has_current_image && $item->has_diff_image === $compared );
	}

	/**
	 * 外部へ返せる定型検証エラーを生成する。
	 *
	 * @param string $code エラーコード.
	 * @return WP_Error 検証エラー.
	 */
	private function invalid( $code ) {
		return new WP_Error( $code, __( '製品APIの契約に適合しない入力です。', 'od-visual-regression' ), array( 'status' => 400 ) );
	}
}
