<?php
/**
 * 固定履歴の読取。現在のTargetやDeviceから過去の表示を再構成しない。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 最新の履歴と参照を毎回確認する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Run Managerと共通の固定データ検証。汎用UPDATEは公開しない。 */
class ODVR_Run_Repository extends ODVR_Repository {
	/**
	 * IDでRunを取得し、期限切れの稼働状態を確定する。
	 *
	 * @param int $id Run ID.
	 * @return array|WP_Error 表示用Run.
	 */
	public function get( $id ) {
		return $this->read(
			function () use ( $id ) {
				$this->checked( ( new ODVR_Run_Manager() )->expire( $id ) );
				return $this->item( $this->row( 'runs', $id ) );
			}
		);
	}

	/**
	 * Suite内の履歴を新しい順に取得する。
	 *
	 * @param int $suite_id Suite ID.
	 * @param int $page ページ.
	 * @param int $per_page 最大100件.
	 * @return array|WP_Error 一覧.
	 */
	public function list_items( $suite_id, $page = 1, $per_page = 20 ) {
		return $this->read(
			function () use ( $suite_id, $page, $per_page ) {
				global $wpdb;
				$suite_id = $this->id( $suite_id );
				$this->row( 'suites', $suite_id );
				$offset = $this->offset( $page, $per_page );
				$rows   = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', ODVR_DB::table( 'runs' ), $suite_id, $per_page, $offset ) );
				$items  = array();
				foreach ( $rows as $row ) {
						$item    = $this->checked( $this->get( $this->integer( $row['id'], true ) ) );
						$items[] = (object) $item;
				}
				$total = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i WHERE suite_id = %d', ODVR_DB::table( 'runs' ), $suite_id ) );
				$this->validate(
					'run-list-response',
					(object) array(
						'schema_version' => 1,
						'items'          => $items,
					)
				);
				return array(
					'items' => $items,
					'total' => $this->integer( $total[0]['total'] ),
				);
			}
		);
	}

	/**
	 * UUIDを完全一致で解決する。
	 *
	 * @param string $uuid UUID.
	 * @param bool   $lock 行ロック.
	 * @return array Run行.
	 */
	protected function uuid_row( $uuid, $lock = false ) {
		global $wpdb;
		if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid ) ) {
			$this->fail( 'odvr_invalid_id', 400 );
		}
		$rows = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', ODVR_DB::table( 'runs' ), $uuid ) . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( ! $rows ) {
			$this->fail( 'odvr_not_found', 404 );
		}
		return $rows[0];
	}

	/**
	 * 保存Manifestの固定部分を検証する。
	 *
	 * @param array $row Run行.
	 * @return stdClass 固定Manifest.
	 */
	protected function fixed( $row ) {
		$manifest = json_decode( $row['manifest'] );
		$this->validate( 'stored-run-manifest', $manifest );
		$reference_id = null === $row['reference_run_id'] ? null : $this->integer( $row['reference_run_id'], true );
		if ( $manifest->suite->id !== $this->integer( $row['suite_id'], true ) || $reference_id !== $manifest->reference->run_id ) {
			$this->fail( 'odvr_invalid_stored_data', 503 );
		}
		return $manifest;
	}

	/**
	 * 保存Snapshotの全固定組み合わせを確認する。
	 *
	 * @param array $row Run行.
	 * @param bool  $lock ID順の行ロック.
	 * @return array Snapshot行.
	 */
	protected function snapshots( $row, $lock = false ) {
		global $wpdb;
		$manifest  = $this->fixed( $row );
		$snapshots = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id', ODVR_DB::table( 'snapshots' ), $this->integer( $row['id'], true ) ) . ( $lock ? ' FOR UPDATE' : '' ) );
		$pairs     = array();
		foreach ( $manifest->targets as $target ) {
			foreach ( $manifest->devices as $device ) {
				$pairs[ $target->id . ':' . $device->id ] = array( $target, $device );
			}
		}
		if ( count( $snapshots ) !== count( $pairs ) || count( $pairs ) !== $this->integer( $row['total_snapshots'], true ) ) {
			$this->fail( 'odvr_invalid_stored_data', 503 );
		}
		foreach ( $snapshots as $snapshot ) {
			$key = $this->integer( $snapshot['target_id'], true ) . ':' . $this->integer( $snapshot['device_id'], true );
			if ( ! isset( $pairs[ $key ] ) || $snapshot['url'] !== $pairs[ $key ][0]->url || ! in_array( $snapshot['status'], array( 'PENDING', 'CAPTURED', 'NO_BASELINE', 'UNCHANGED', 'REVIEW', 'CHANGED', 'ERROR' ), true ) ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
			$fixed_pair = $pairs[ $key ];
			unset( $pairs[ $key ] );
			$metadata = json_decode( $snapshot['metadata'] );
			if ( ! $metadata instanceof stdClass ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
			$this->checked( $this->validator->validate_stored_version( 'metadata', $metadata->metadata_version ?? null ) );
			$this->validate( 'snapshot-metadata', $metadata );
			$fixed_reference = null;
			foreach ( $manifest->reference->snapshots as $reference ) {
				if ( $reference->target_id === (int) $snapshot['target_id'] && $reference->device_id === (int) $snapshot['device_id'] ) {
					$fixed_reference = $reference;
					break; }
			}
			$baseline_id = null === $snapshot['baseline_snapshot_id'] ? null : $this->integer( $snapshot['baseline_snapshot_id'], true );
			if ( ODVR_Environment::digest( $metadata->target ) !== ODVR_Environment::digest( $fixed_pair[0] ) || ODVR_Environment::digest( $metadata->device ) !== ODVR_Environment::digest( $fixed_pair[1] ) || ODVR_Environment::digest( $metadata->reference ) !== ODVR_Environment::digest( $fixed_reference ) || $baseline_id !== $metadata->reference->baseline_snapshot_id ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
			if ( null === $metadata->result ) {
				if ( null !== $metadata->result_digest || null !== $metadata->image_sha256 || null !== $metadata->diff_sha256 || null !== $snapshot['image_path'] || null !== $snapshot['diff_path'] ) {
					$this->fail( 'odvr_invalid_stored_data', 503 ); }
			} else {
				$this->validate( 'snapshot-result', $metadata->result );
				if ( ODVR_Environment::digest( $metadata->result ) !== $metadata->result_digest || $metadata->result->target_id !== (int) $snapshot['target_id'] || $metadata->result->device_id !== (int) $snapshot['device_id'] || ( null === $metadata->image_sha256 ) !== ( null === $snapshot['image_path'] ) || ( null === $metadata->diff_sha256 ) !== ( null === $snapshot['diff_path'] ) ) {
					$this->fail( 'odvr_invalid_stored_data', 503 ); }
				foreach ( array( 'width', 'height', 'baseline_width', 'baseline_height', 'duration_ms', 'http_status', 'diff_pixels', 'total_pixels' ) as $field ) {
					if ( ( null === $snapshot[ $field ] ? null : $this->integer( $snapshot[ $field ] ) ) !== $metadata->result->$field ) {
						$this->fail( 'odvr_invalid_stored_data', 503 ); }
				}
				if ( $metadata->result->dimension_changed !== $this->boolean( $snapshot['dimension_changed'] ) || $metadata->result->error_code !== $snapshot['error_code'] || $metadata->result->error_message !== $snapshot['error_message'] || ( null === $metadata->result->diff_ratio ? null !== $snapshot['diff_ratio'] : abs( (float) $snapshot['diff_ratio'] - $metadata->result->diff_ratio ) > 0.0000000001 ) ) {
					$this->fail( 'odvr_invalid_stored_data', 503 ); }
			}

			if ( (int) $snapshot['target_id'] !== $metadata->target->id || (int) $snapshot['device_id'] !== $metadata->device->id || $metadata->target->url !== $snapshot['url'] || ( null === $metadata->result ? 'PENDING' !== $snapshot['status'] : $metadata->result->status !== $snapshot['status'] ) ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
		}
		return $snapshots;
	}

	/**
	 * 件数を実Snapshotから算出し、固定した総数と状態を検査する。
	 *
	 * @param array $row Run行.
	 * @return stdClass Run状態.
	 */
	protected function state( $row ) {
		$completed = 0;
		$errors    = 0;
		$pending   = 0;
		foreach ( $this->snapshots( $row ) as $snapshot ) {
			if ( 'PENDING' === $snapshot['status'] ) {
				++$pending;
			} elseif ( 'ERROR' === $snapshot['status'] ) {
				++$errors;
			} else {
				++$completed;
			}
		}
		$result = (object) array(
			'schema_version'      => 1,
			'run_uuid'            => $row['uuid'],
			'status'              => $row['status'],
			'total_snapshots'     => $this->integer( $row['total_snapshots'], true ),
			'completed_snapshots' => $completed,
			'error_snapshots'     => $errors,
			'pending_snapshots'   => $pending,
			'completed_at'        => $this->checked( ODVR_DB::utc_datetime( $row['completed_at'], false ) ),
			'error_code'          => $row['error_code'],
		);
		$this->validate( 'run-state', $result );
		return $result;
	}

	/**
	 * 固定Manifest・Environmentだけで詳細を組み立てる。
	 *
	 * @param array $row Run行.
	 * @return array 表示用Run.
	 */
	protected function item( $row ) {
		global $wpdb;
		$manifest    = $this->fixed( $row );
		$environment = $this->checked( ODVR_Environment::decode( $row['environment'] ) );
		$state       = get_object_vars( $this->state( $row ) );
		unset( $state['schema_version'] );
		$suite                 = $this->row( 'suites', $manifest->suite->id );
		$reference_environment = null;
		if ( null !== $manifest->reference->run_id ) {
			$reference             = $this->row( 'runs', $manifest->reference->run_id );
			$reference_environment = ODVR_Environment::visible( $this->checked( ODVR_Environment::decode( $reference['environment'] ) ) );
		}
		$referenced    = $this->rows( $wpdb->prepare( 'SELECT id, uuid, status, created_at FROM %i WHERE reference_run_id = %d AND status <> %s ORDER BY id', ODVR_DB::table( 'runs' ), $this->integer( $row['id'], true ), 'deleting' ) );
		$referenced_by = array();
		foreach ( $referenced as $reference ) {
			$referenced_by[] = (object) array(
				'id'         => $this->integer( $reference['id'], true ),
				'run_uuid'   => $reference['uuid'],
				'status'     => $reference['status'],
				'created_at' => $this->checked( ODVR_DB::utc_datetime( $reference['created_at'], false ) ),
			);
		}
		$reasons = array();
		if ( (string) $row['id'] === (string) $suite['baseline_run_id'] ) {
			$reasons[] = 'pinned';
		}
		if ( in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
			$reasons[] = 'active';
		}
		if ( $referenced_by ) {
			$reasons[] = 'referenced';
		}
		$item = array_merge(
			$state,
			array(
				'id'                    => $this->integer( $row['id'], true ),
				'suite_id'              => $manifest->suite->id,
				'suite_name'            => $manifest->suite->name,
				'created_at'            => $this->checked( ODVR_DB::utc_datetime( $row['created_at'], false ) ),
				'deadline_at'           => $this->checked( ODVR_DB::utc_datetime( $row['deadline_at'], false ) ),
				'error_message'         => $row['error_message'],
				'reference_run_id'      => $manifest->reference->run_id,
				'environment'           => ODVR_Environment::visible( $environment ),
				'reference_environment' => $reference_environment,
				'targets'               => $manifest->targets,
				'devices'               => $manifest->devices,
				'settings'              => $manifest->settings,
				'protection'            => (object) array(
					'reasons'       => $reasons,
					'referenced_by' => $referenced_by,
				),
				'deletion_error'        => null,
			)
		);
		$this->validate(
			'run-response',
			(object) array(
				'schema_version' => 1,
				'item'           => (object) $item,
			)
		);
		return $item;
	}
}
