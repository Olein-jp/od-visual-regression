<?php
/**
 * Suite設定の保存。RESTの認証と公開は後続Controllerが担当する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// 行ロック下のDB値を正本とする.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Suiteの設定だけを編集し、Runの固定Manifestには触れない。 */
final class ODVR_Suite_Repository extends ODVR_Repository {
	/**
	 * Suiteを取得する。
	 *
	 * @param int $id ID.
	 * @return array|WP_Error Suite.
	 */
	public function get( $id ) {
		return $this->read(
			function () use ( $id ) {
				return $this->item( $this->row( 'suites', $id ) );
			}
		);
	}

	/**
	 * ページを取得する。
	 *
	 * @param int    $page ページ.
	 * @param int    $per_page 件数.
	 * @param string $status all / active / archived.
	 * @return array|WP_Error items / total.
	 */
	public function list_items( $page = 1, $per_page = 20, $status = 'all' ) {
		global $wpdb;
		return $this->read(
			function () use ( $page, $per_page, $status, $wpdb ) {
				$offset = $this->offset( $page, $per_page );
				if ( ! in_array( $status, array( 'all', 'active', 'archived' ), true ) ) {
					$this->fail( 'odvr_invalid_status' );
				}
				$where = 'all' === $status ? '' : $wpdb->prepare( ' WHERE status = %s', $status );
				$rows  = $this->rows( $wpdb->prepare( 'SELECT * FROM %i', ODVR_DB::table( 'suites' ) ) . $where . $wpdb->prepare( ' ORDER BY id LIMIT %d OFFSET %d', $per_page, $offset ) );
				$total = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i', ODVR_DB::table( 'suites' ) ) . $where );
				return array(
					'items' => array_map( array( $this, 'item' ), $rows ),
					'total' => $this->integer( $total[0]['total'] ),
				);
			}
		);
	}

	/**
	 * 新しいSuiteを保存する。
	 *
	 * @param stdClass $input 作成契約.
	 * @param int      $created_by ユーザーID.
	 * @return array|WP_Error Suite.
	 */
	public function create( $input, $created_by ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $input, $created_by, $wpdb ) {
				$this->validate( 'suite-create-request', $input );
				$this->lock_devices( $input->device_ids );
				$now = ODVR_DB::utc_now();
				$this->written(
					$wpdb->insert(
						ODVR_DB::table( 'suites' ),
						array(
							'uuid'            => wp_generate_uuid4(),
							'name'            => $input->name,
							'status'          => 'active',
							'baseline_run_id' => null,
							'settings'        => $this->encode( $input ),
							'created_by'      => $this->id( $created_by ),
							'created_at'      => $now,
							'updated_at'      => $now,
						),
						array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
					)
				);
				return $this->item( $this->row( 'suites', $this->integer( (string) $wpdb->insert_id, true ) ) );
			}
		);
	}

	/**
	 * Suite行をロックして部分更新する。
	 *
	 * @param int      $id ID.
	 * @param stdClass $input PATCH契約.
	 * @return array|WP_Error Suite.
	 */
	public function update( $id, $input ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $id, $input, $wpdb ) {
				$this->validate( 'suite-patch-request', $input );
				$row = $this->row( 'suites', $id, true );
				if ( 'active' !== $row['status'] ) {
					$this->fail( 'odvr_suite_archived', 409 );
				}
				$config  = $this->suite_configuration( $row );
				$current = array(
					'schema_version'  => 1,
					'name'            => $row['name'],
					'settings'        => $config->settings,
					'device_ids'      => $config->device_ids,
					'allowed_origins' => $config->allowed_origins,
					'retention'       => $config->retention,
				);
				$merged  = (object) array_merge( $current, (array) $input );
				$this->validate( 'suite-create-request', $merged );
				// TargetとDeviceは、それぞれID昇順でロックする.
				$targets = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d AND enabled = 1 ORDER BY id FOR UPDATE', ODVR_DB::table( 'targets' ), $id ) );
				if ( count( $targets ) > 100 ) {
					$this->fail( 'odvr_target_limit', 409 );
				}
				foreach ( $targets as $target ) {
					$this->checked( ODVR_Target_URL::resolve( $target['url'], null === $target['object_id'] ? null : $this->integer( $target['object_id'], true ), $target['post_type'] ) );
				}
				$this->lock_devices( $merged->device_ids );
				$this->written(
					$wpdb->update(
						ODVR_DB::table( 'suites' ),
						array(
							'name'       => $merged->name,
							'settings'   => $this->encode( $merged ),
							'updated_at' => ODVR_DB::utc_now(),
						),
						array( 'id' => $id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					)
				);
				return $this->item( $this->row( 'suites', $id ) );
			}
		);
	}

	/**
	 * 実行中Runがない場合にarchiveする。
	 *
	 * @param int $id ID.
	 * @return array|WP_Error Suite.
	 */
	public function archive( $id ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $id, $wpdb ) {
				$row = $this->row( 'suites', $id, true );
				$this->item( $row );
				$active = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d AND status IN (%s, %s) ORDER BY id', ODVR_DB::table( 'runs' ), $id, 'queued', 'running' ) );
				if ( $active ) {
					$this->fail( 'odvr_suite_busy', 409 );
				}
				if ( 'archived' !== $row['status'] ) {
					$this->written(
						$wpdb->update(
							ODVR_DB::table( 'suites' ),
							array(
								'status'     => 'archived',
								'updated_at' => ODVR_DB::utc_now(),
							),
							array( 'id' => $id ),
							array( '%s', '%s' ),
							array( '%d' )
						)
					);
				}
				return $this->item( $this->row( 'suites', $id ) );
			}
		);
	}

	/**
	 * 選択Deviceをロックして有効性を検査する。
	 *
	 * @param array $ids 検証済みID.
	 * @return void
	 */
	private function lock_devices( $ids ) {
		global $wpdb;
		sort( $ids, SORT_NUMERIC );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// 個数だけから生成した固定%dを使用し、値はすべてprepareへ渡す.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE', array_merge( array( ODVR_DB::table( 'devices' ) ), $ids ) ) );
		if ( count( $rows ) !== count( $ids ) ) {
			$this->fail( 'odvr_invalid_device_reference' );
		}
		foreach ( $rows as $device ) {
			if ( ! $this->boolean( $device['enabled'] ) ) {
				$this->fail( 'odvr_invalid_device_reference' );
			}
			// 保存済みDeviceの全プロファイルも検査し、壊れた値を選択させない.
			ODVR_Device_Repository::validate_row( $device, $this->validator );
		}
	}

	/**
	 * 保存JSONを生成する。
	 *
	 * @param stdClass $input 作成契約.
	 * @return string JSON.
	 */
	private function encode( $input ) {
		return $this->checked(
			ODVR_DB::stored_json(
				(object) array(
					'settings_version' => 1,
					'settings'         => $input->settings,
					'device_ids'       => $input->device_ids,
					'allowed_origins'  => $input->allowed_origins,
					'retention'        => $input->retention,
				),
				'settings'
			)
		);
	}

	/**
	 * 秘密や内部列を含めず表示値へ変換する。
	 *
	 * @param array $row 行.
	 * @return array Suite.
	 */
	private function item( $row ) {
		global $wpdb;
		$config = $this->suite_configuration( $row );
		$id     = $this->integer( $row['id'], true );
		$count  = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i WHERE suite_id = %d AND enabled = 1', ODVR_DB::table( 'targets' ), $id ) );
		$latest = $this->rows( $wpdb->prepare( 'SELECT id, uuid, status, created_at FROM %i WHERE suite_id = %d ORDER BY created_at DESC, id DESC LIMIT 1', ODVR_DB::table( 'runs' ), $id ) );
		$run    = $latest ? (object) array(
			'id'         => $this->integer( $latest[0]['id'], true ),
			'run_uuid'   => $latest[0]['uuid'],
			'status'     => $latest[0]['status'],
			'created_at' => $this->checked( ODVR_DB::utc_datetime( $latest[0]['created_at'], false ) ),
		) : null;
		$item   = array(
			'id'              => $id,
			'uuid'            => $row['uuid'],
			'name'            => $row['name'],
			'status'          => $row['status'],
			'settings'        => $config->settings,
			'device_ids'      => $config->device_ids,
			'allowed_origins' => $config->allowed_origins,
			'retention'       => $config->retention,
			'baseline_run_id' => null === $row['baseline_run_id'] ? null : $this->integer( $row['baseline_run_id'], true ),
			'target_count'    => $this->integer( $count[0]['total'] ),
			'device_count'    => count( $config->device_ids ),
			'latest_run'      => $run,
		);
		$this->validate(
			'suite-response',
			(object) array(
				'schema_version' => 1,
				'item'           => (object) $item,
			)
		);
		return $item;
	}
}
