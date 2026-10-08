<?php
/**
 * 参照閉包によるRetentionと、再開可能な削除。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Suiteロック下の最新値を正本とする.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** 画像削除とDBトランザクションを分離する。 */
final class ODVR_Retention extends ODVR_Run_Repository {
	/**
	 * 保存設定と全参照の閉包から自動削除候補を求める。
	 *
	 * @param int $suite_id Suite ID.
	 * @return array|WP_Error 保護理由と候補.
	 */
	public function plan( $suite_id ) {
		return $this->read(
			function () use ( $suite_id ) {
				return $this->protection( $this->row( 'suites', $suite_id ) );
			}
		);
	}

	/**
	 * 同じSuiteの参照グラフ。deletingも再開順序のため残す。
	 *
	 * @param array $suite Suite行.
	 * @return array 行と参照先.
	 */
	private function graph( $suite ) {
		global $wpdb;
		$rows  = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d ORDER BY created_at DESC, id DESC', ODVR_DB::table( 'runs' ), $this->integer( $suite['id'], true ) ) );
		$runs  = array();
		$edges = array();
		foreach ( $rows as $row ) {
			$this->validate( 'stored-run-manifest', json_decode( $row['manifest'] ) );
			$this->checked( ODVR_Environment::decode( $row['environment'] ) );
			$id = $this->integer( $row['id'], true );
			if ( ! in_array( $row['status'], array( 'queued', 'running', 'complete', 'partial', 'failed', 'deleting' ), true ) ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
			$runs[ $id ]  = $row;
			$edges[ $id ] = null === $row['reference_run_id'] ? array() : array( $this->integer( $row['reference_run_id'], true ) );
		}
		$metadata_rows = $this->rows( $wpdb->prepare( 'SELECT s.metadata FROM %i s JOIN %i r ON r.id = s.run_id WHERE r.suite_id = %d', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'runs' ), $this->integer( $suite['id'], true ) ) );
		foreach ( $metadata_rows as $metadata ) {
			$this->validate( 'snapshot-metadata', json_decode( $metadata['metadata'] ) ); }
		$references = $this->rows( $wpdb->prepare( 'SELECT DISTINCT s.run_id, b.run_id AS baseline_run_id FROM %i s JOIN %i r ON r.id = s.run_id LEFT JOIN %i b ON b.id = s.baseline_snapshot_id WHERE r.suite_id = %d AND s.baseline_snapshot_id IS NOT NULL', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'runs' ), ODVR_DB::table( 'snapshots' ), $this->integer( $suite['id'], true ) ) );
		foreach ( $references as $reference ) {
			if ( null === $reference['baseline_run_id'] ) {
				$this->fail( 'odvr_invalid_stored_data', 503 );
			}
			$edges[ $this->integer( $reference['run_id'], true ) ][] = $this->integer( $reference['baseline_run_id'], true );
		}
		foreach ( $edges as $id => $targets ) {
			foreach ( $targets as $target ) {
				if ( ! isset( $runs[ $target ] ) || $target >= $id || ( 'deleting' !== $runs[ $id ]['status'] && 'deleting' === $runs[ $target ]['status'] ) ) {
					$this->fail( 'odvr_invalid_stored_data', 503 );
				}
			}
			$edges[ $id ] = array_values( array_unique( $targets ) );
		}
		return array(
			'runs'  => $runs,
			'edges' => $edges,
		);
	}

	/**
	 * 最新N件・稼働・Pinned・最新completeを起点に参照先を保護する。
	 *
	 * @param array $suite Suite行.
	 * @return array 保護理由・候補・グラフ.
	 */
	private function protection( $suite ) {
		$config          = $this->suite_configuration( $suite );
		$graph           = $this->graph( $suite );
		$protected       = array();
		$terminal        = 0;
		$latest_complete = null;
		foreach ( $graph['runs'] as $id => $run ) {
			if ( 'complete' === $run['status'] && ( null === $latest_complete || array( $run['completed_at'], $id ) > array( $graph['runs'][ $latest_complete ]['completed_at'], $latest_complete ) ) ) {
				$latest_complete = $id;
			}
		}
		foreach ( $graph['runs'] as $id => $run ) {
			if ( 'deleting' === $run['status'] ) {
				continue;
			}
			$reasons = array();
			if ( 'all' === $config->retention->mode ) {
				$reasons[] = 'keep_all';
			}
			if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
				$reasons[] = 'active';
			} else {
				++$terminal;
				if ( 'last' === $config->retention->mode && $terminal <= $config->retention->count ) {
					$reasons[] = 'retention';
				}
			}
			if ( null !== $suite['baseline_run_id'] && $id === $this->integer( $suite['baseline_run_id'], true ) ) {
				$reasons[] = 'pinned';
			}
			if ( $latest_complete === $id ) {
				$reasons[] = 'latest_complete';
			}
			if ( $reasons ) {
				$protected[ $id ] = $reasons;
			}
		}
		if ( null !== $suite['baseline_run_id'] && ( ! isset( $protected[ (int) $suite['baseline_run_id'] ] ) || 'complete' !== $graph['runs'][ (int) $suite['baseline_run_id'] ]['status'] ) ) {
			$this->fail( 'odvr_invalid_stored_data', 503 );
		}
		$queue = array_keys( $protected );
		while ( $queue ) {
			$id = array_pop( $queue );
			foreach ( $graph['edges'][ $id ] as $target ) {
				if ( ! isset( $protected[ $target ] ) ) {
					$protected[ $target ] = array( 'referenced' );
					$queue[]              = $target;
				} elseif ( ! in_array( 'referenced', $protected[ $target ], true ) ) {
					$protected[ $target ][] = 'referenced';
				}
			}
		}

		$candidates = array();
		foreach ( $graph['runs'] as $id => $run ) {
			if ( 'deleting' !== $run['status'] && ! isset( $protected[ $id ] ) ) {
				$candidates[] = $id;
			}
		}
		return array_merge(
			$graph,
			array(
				'protected'  => $protected,
				'candidates' => $candidates,
			)
		);
	}

	/**
	 * 対象全件を1回のCOMMITでdeletingへ確定する。
	 *
	 * @param int   $suite_id Suite ID.
	 * @param array $ids 手動指定ID。自動なら無視する.
	 * @param bool  $automatic Retention条件も保護する.
	 * @return array|WP_Error 確定ID.
	 */
	public function request( $suite_id, $ids = array(), $automatic = false ) {
		return $this->transaction(
			function () use ( $suite_id, $ids, $automatic ) {
				global $wpdb;
				$suite = $this->row( 'suites', $suite_id, true );
				$plan  = $this->protection( $suite );
				if ( $automatic ) {
					$ids = $plan['candidates'];
					foreach ( $plan['runs'] as $id => $run ) {
						if ( 'deleting' === $run['status'] ) {
							$ids[] = $id;
						}
					}
				}
				$ids = array_map( array( $this, 'id' ), $ids );
				if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
					$this->fail( 'odvr_invalid_id', 400 );
				}
				sort( $ids, SORT_NUMERIC );
				foreach ( $ids as $id ) {
					if ( ! isset( $plan['runs'][ $id ] ) ) {
						$this->fail( 'odvr_not_found', 404 );
					}
					$run = $this->row( 'runs', $id, true );
					if ( ! in_array( $run['status'], array( 'complete', 'partial', 'failed', 'deleting' ), true ) || ( null !== $suite['baseline_run_id'] && $id === $this->integer( $suite['baseline_run_id'], true ) ) || ( $automatic && isset( $plan['protected'][ $id ] ) ) ) {
						$this->fail( 'odvr_run_protected', 409 );
					}
				}
				foreach ( $ids as $id ) {
					$this->outside_references( $id, $ids );
				}
				foreach ( $ids as $id ) {
					if ( 'deleting' === $plan['runs'][ $id ]['status'] ) {
						continue;
					}
					$this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE run_id = %d ORDER BY id FOR UPDATE', ODVR_DB::table( 'snapshots' ), $id ) );
					$this->written(
						$wpdb->update(
							ODVR_DB::table( 'runs' ),
							array(
								'status'                => 'deleting',
								'runner_token_hash'     => null,
								'deletion_requested_at' => ODVR_DB::utc_now(),
								'updated_at'            => ODVR_DB::utc_now(),
							),
							array( 'id' => $id )
						)
					);
				}
				return $ids;
			}
		);
	}

	/**
	 * 選択外の保持Run/SnapshotやPinnedからの参照を拒否する。
	 *
	 * @param int   $id 対象Run.
	 * @param array $selected 同時削除集合.
	 * @return void
	 */
	private function outside_references( $id, $selected ) {
		global $wpdb;
		$pinned   = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE baseline_run_id = %d', ODVR_DB::table( 'suites' ), $id ) );
		$incoming = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE reference_run_id = %d', ODVR_DB::table( 'runs' ), $id ) );
		$incoming = array_merge( $incoming, $this->rows( $wpdb->prepare( 'SELECT DISTINCT s.run_id AS id FROM %i s JOIN %i b ON b.id = s.baseline_snapshot_id WHERE b.run_id = %d', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'snapshots' ), $id ) ) );
		if ( $pinned ) {
			$this->fail( 'odvr_run_protected', 409 );
		}
		foreach ( $incoming as $reference ) {
			if ( ! in_array( $this->integer( $reference['id'], true ), $selected, true ) ) {
				$this->fail( 'odvr_run_protected', 409 );
			}
		}
	}

	/**
	 * 画像→Snapshot→Runの順で削除し、失敗時はdeletingを保持する。
	 *
	 * @param int $id Run ID.
	 * @return true|WP_Error 完了.
	 */
	public function resume( $id ) {
		$id = $this->read(
			function () use ( $id ) {
				return $this->id( $id );
			}
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$result = $this->read(
			function () use ( $id ) {
				$this->checked( ODVR_DB::writable() );
				$row = $this->row( 'runs', $id );
				if ( 'deleting' !== $row['status'] || null !== $row['runner_token_hash'] ) {
					$this->fail( 'odvr_run_conflict', 409 );
				}
				// 参照元を先に処理する。deletingからの参照も、この段階では切らない.
				$this->outside_references( $id, array() );
				$this->checked( ( new ODVR_Storage() )->delete_run( $id ) );
				return $this->checked(
					$this->transaction(
						function () use ( $id, $row ) {
							global $wpdb;
							$this->row( 'suites', $this->integer( $row['suite_id'], true ), true );
							$current = $this->row( 'runs', $id, true );
							if ( 'deleting' !== $current['status'] || null !== $current['runner_token_hash'] || $current['uuid'] !== $row['uuid'] ) {
								$this->fail( 'odvr_run_conflict', 409 );
							}
							$this->outside_references( $id, array() );
							$this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE run_id = %d ORDER BY id FOR UPDATE', ODVR_DB::table( 'snapshots' ), $id ) );
							$this->written( $wpdb->delete( ODVR_DB::table( 'snapshots' ), array( 'run_id' => $id ) ) );
							$this->written(
								$wpdb->delete(
									ODVR_DB::table( 'runs' ),
									array(
										'id'     => $id,
										'status' => 'deleting',
									)
								)
							);
							return true;
						}
					)
				);
			}
		);
		if ( is_wp_error( $result ) && 'odvr_not_found' === $result->get_error_code() ) {
			$result = true;
		}
		if ( is_wp_error( $result ) ) {
			$this->read(
				function () use ( $id ) {
					update_option( 'odvr_deletion_error_' . $id, '画像または履歴の削除を完了できませんでした。再試行してください。', false );
					return true;
				}
			);
		} else {
			$this->read(
				function () use ( $id ) {
					delete_option( 'odvr_deletion_error_' . $id );
					return true;
				}
			);
		}
			return $result;
	}

	/**
	 * サイト内の削除中Runを参照元のID降順で最大25件処理する。
	 *
	 * @return array|WP_Error 結果.
	 */
	public function resume_cycle() {
		return $this->read(
			function () {
				global $wpdb;
				$cursor  = $this->integer( get_option( 'odvr_deletion_cursor', 2147483647 ), true );
				$rows    = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND id <= %d ORDER BY id DESC LIMIT 25', ODVR_DB::table( 'runs' ), 'deleting', $cursor ) );
				$results = array();
				foreach ( $rows as $row ) {
					$id             = $this->integer( $row['id'], true );
					$results[ $id ] = $this->resume( $id );
					if ( $id > 1 ) {
						update_option( 'odvr_deletion_cursor', $id - 1, false );
					} else {
						delete_option( 'odvr_deletion_cursor' ); }
				}
				if ( count( $rows ) < 25 ) {
					delete_option( 'odvr_deletion_cursor' ); }
				return $results;
			}
		);
	}

	/**
	 * Suiteを最大10件巡回し、削除中の履歴を再開する。
	 *
	 * @return array|WP_Error 巡回結果.
	 */
	public function cycle() {
		return $this->read(
			function () {
				global $wpdb;
				$cursor  = $this->integer( get_option( 'odvr_retention_cursor', 0 ) );
				$rows    = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id LIMIT 10', ODVR_DB::table( 'suites' ), $cursor ) );
				$results = array();
				foreach ( $rows as $row ) {
					$id             = $this->integer( $row['id'], true );
					$results[ $id ] = $this->request( $id, array(), true );
					update_option( 'odvr_retention_cursor', $id, false );
				}
				if ( count( $rows ) < 10 ) {
					delete_option( 'odvr_retention_cursor' );
				}
				$this->checked( $this->resume_cycle() );
				return $results;
			}
		);
	}

	/** サイト内の定期Retention。失敗分はdeletingを保持して次回へ残す。 */
	public static function cron() {
		( new self() )->cycle();
	}
}
