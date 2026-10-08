<?php
/**
 * Suite→Run→Snapshot順の状態遷移と期限処理。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 固定した行ロックと最新の結果を正本として扱う.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Runの許可された遷移だけを提供する。 */
final class ODVR_Run_Manager extends ODVR_Run_Repository {
	/**
	 * 実Versionを照合した参照だけを利用する。保存した参照は書き換えない。
	 *
	 * @param string $uuid Run UUID.
	 * @param int    $target_id Target ID.
	 * @param int    $device_id Device ID.
	 * @return stdClass|WP_Error 利用時の参照.
	 */
	public function baseline_reference( $uuid, $target_id, $device_id ) {
		return $this->read(
			function () use ( $uuid, $target_id, $device_id ) {
				$row = $this->uuid_row( $uuid );
				$this->checked( $this->expire( $this->integer( $row['id'], true ) ) );
				$row = $this->uuid_row( $uuid );
				if ( 'running' !== $row['status'] ) {
					$this->fail( 'odvr_run_conflict', 409 ); }
				$fixed     = $this->fixed( $row );
				$reference = clone $this->pair_reference( $fixed, $this->id( $target_id ), $this->id( $device_id ) );
				if ( null === $reference->baseline_snapshot_id ) {
					return $reference; }
				$environment = $this->checked( ODVR_Environment::decode( $row['environment'] ) );
				if ( ! ODVR_Baseline::versions( $fixed->reference->versions, $environment ) ) {
					$reference->baseline_snapshot_id = null;
					$reference->reason               = 'incompatible';
					return $reference;
				}
				$source   = $this->row( 'runs', $fixed->reference->run_id );
				$snapshot = $this->row( 'snapshots', $reference->baseline_snapshot_id );
				if ( $source['suite_id'] !== $row['suite_id'] || 'complete' !== $source['status'] || $snapshot['run_id'] !== $source['id'] || (int) $snapshot['target_id'] !== $reference->target_id || (int) $snapshot['device_id'] !== $reference->device_id ) {
					$this->fail( 'odvr_invalid_stored_data', 503 ); }
				$image = $this->inspect_image( $source, $snapshot );
				if ( is_wp_error( $image ) ) {
					if ( 404 !== ( $image->get_error_data()['status'] ?? 500 ) ) {
						$this->checked( $image ); }
					$reference->baseline_snapshot_id = null;
					$reference->reason               = $image->get_error_data()['reason'] ?? 'corrupt';
				}
				return $reference;
			}
		);
	}


	/**
	 * 稼働状態とToken失効をサイト内で最大25件ずつ確定する。
	 *
	 * @return array|WP_Error 処理したID.
	 */
	public function expire_cycle() {
		return $this->read(
			function () {
				global $wpdb;
				$cursor    = $this->integer( get_option( 'odvr_run_expiry_cursor', 0 ) );
				$rows      = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d AND (status IN (%s, %s) OR (runner_token_hash IS NOT NULL AND runner_token_expires_at <= %s)) ORDER BY id LIMIT 25', ODVR_DB::table( 'runs' ), $cursor, 'queued', 'running', ODVR_DB::utc_now() ) );
				$processed = array();
				foreach ( $rows as $row ) {
						$id = $this->integer( $row['id'], true );
						$this->checked( $this->expire( $id ) );
						update_option( 'odvr_run_expiry_cursor', $id, false );
						$processed[] = $id;
				}
				if ( count( $rows ) < 25 ) {
					delete_option( 'odvr_run_expiry_cursor' ); }
				return $processed;
			}
		);
	}

	/** サイト内の期限処理。次回または読取時に失敗分を再試行する。 */
	public static function expiry_cron() {
		( new self() )->expire_cycle();
	}

	/**
	 * Run期限を確認する1分間隔を登録する。
	 *
	 * @param array $schedules WordPressの間隔.
	 * @return array 間隔.
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['odvr_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'ODVR: 1分',
		);
		return $schedules;
	}

	/**
	 * 診断・画像検査をロック外で済ませ、固定履歴を原子的に作成する。
	 *
	 * @param int      $suite_id Suite ID.
	 * @param stdClass $input 作成契約.
	 * @param int      $user_id 操作者.
	 * @param array    $options 内部の期限と認証Origin.
	 * @param array    $http_auth 診断用の認証。保存しない.
	 * @return array|WP_Error 公開itemとDispatcher専用Token.
	 */
	public function create( $suite_id, $input, $user_id, $options = array(), $http_auth = null ) {
		return $this->read(
			function () use ( $suite_id, $input, $user_id, $options, $http_auth ) {
				global $wpdb;
				$this->validate( 'run-create-request', $input );
				$suite_id = $this->id( $suite_id );
				$user_id  = $this->id( $user_id );
				$queue    = $options['queued_timeout_seconds'] ?? 900;
				$runtime  = $options['run_timeout_seconds'] ?? 5400;
				if ( array_diff( array_keys( $options ), array( 'queued_timeout_seconds', 'run_timeout_seconds', 'http_auth_origin' ) ) || ! is_int( $queue ) || ! is_int( $runtime ) || $queue < 1 || $queue >= $runtime || $runtime >= 7200 ) {
					$this->fail( 'odvr_invalid_deadline', 400 );
				}
				$storage = new ODVR_Storage();
				if ( is_wp_error( $storage->ready() ) ) {
					$this->checked( $storage->diagnose( $http_auth ) );
				}
				$active = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d AND status IN (%s, %s) ORDER BY id', ODVR_DB::table( 'runs' ), $suite_id, 'queued', 'running' ) );
				foreach ( $active as $row ) {
					$this->checked( $this->expire( $this->integer( $row['id'], true ) ) );
				}
				$prepared                = $this->prepare_run( $suite_id, $input, $options['http_auth_origin'] ?? null );
				$environment             = $this->checked( ODVR_Environment::capture() );
				$environment->completion = null;
				$this->validate( 'stored-run-environment', $environment );
				return $this->checked(
					$this->transaction(
						function () use ( $suite_id, $input, $prepared, $environment, $user_id, $queue, $runtime, $storage ) {
							global $wpdb;
							$suite  = $this->row( 'suites', $suite_id, true );
							$locked = $this->configuration_rows( $suite, true );
							foreach ( $locked['targets'] as $target ) {
								$url          = $this->checked( ODVR_Target_URL::resolve( $target['url'], null === $target['object_id'] ? null : $this->integer( $target['object_id'], true ), $target['post_type'] ) );
								$fixed_target = ODVR_Baseline::find( $prepared['manifest']->targets, (int) $target['id'] );
								if ( null === $fixed_target || $fixed_target->url !== $url ) {
									$this->fail( 'odvr_configuration_changed', 409 );
								}
							}
							$reference = $this->reference_row( $suite, $input, true );
							if ( ODVR_Environment::digest( (object) $this->configuration_rows( $suite ) ) !== $prepared['configuration_digest'] || ( null === $reference ? null : $this->integer( $reference['id'], true ) ) !== $prepared['reference_id'] ) {
								$this->fail( 'odvr_configuration_changed', 409 );
							}
							if ( null !== $reference && ODVR_Environment::digest(
								(object) array(
									'row'       => $reference,
									'snapshots' => $this->snapshots( $reference, true ),
								)
							) !== $prepared['reference_digest'] ) {
								$this->fail( 'odvr_reference_changed', 409 );
							}
							$busy = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d AND status IN (%s, %s) ORDER BY id FOR UPDATE', ODVR_DB::table( 'runs' ), $suite_id, 'queued', 'running' ) );
							if ( $busy ) {
								$this->fail( 'odvr_suite_busy', 409 );
							}
							$this->checked( $storage->ready() );
							$now                          = time();
							$manifest                     = clone $prepared['manifest'];
							$manifest->queued_deadline_at = gmdate( 'Y-m-d\TH:i:s\Z', $now + $queue );
							$this->validate( 'stored-run-manifest', $manifest );
							$uuid = wp_generate_uuid4();
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ランダムTokenの通信表現.
							$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
							$this->written(
								$wpdb->insert(
									ODVR_DB::table( 'runs' ),
									array(
										'uuid'             => $uuid,
										'suite_id'         => $suite_id,
										'reference_run_id' => $prepared['reference_id'],
										'status'           => 'queued',
										'triggered_by'     => $user_id,
										'runner_token_hash' => hash( 'sha256', $token ),
										'runner_token_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 7200 ),
										'environment'      => wp_json_encode( $environment ),
										'manifest'         => wp_json_encode( $manifest ),
										'total_snapshots'  => count( $manifest->targets ) * count( $manifest->devices ),
										'completed_snapshots' => 0,
										'error_snapshots'  => 0,
										'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
										'updated_at'       => gmdate( 'Y-m-d H:i:s', $now ),
										'deadline_at'      => gmdate( 'Y-m-d H:i:s', $now + $runtime ),
									)
								)
							);
							$id = $this->integer( (string) $wpdb->insert_id, true );
							if ( null !== $prepared['reference_id'] && $prepared['reference_id'] >= $id ) {
								$this->fail( 'odvr_invalid_reference', 409 ); }
							foreach ( $manifest->targets as $target ) {
								foreach ( $manifest->devices as $device ) {
									$ref      = $this->pair_reference( $manifest, $target->id, $device->id );
									$metadata = (object) array(
										'metadata_version' => 1,
										'target'           => $target,
										'device'           => $device,
										'reference'        => $ref,
										'image_sha256'     => null,
										'diff_sha256'      => null,
										'result_digest'    => null,
										'result'           => null,
									);
									$this->validate( 'snapshot-metadata', $metadata );
									$this->written(
										$wpdb->insert(
											ODVR_DB::table( 'snapshots' ),
											array(
												'run_id'   => $id,
												'target_id' => $target->id,
												'device_id' => $device->id,
												'baseline_snapshot_id' => $ref->baseline_snapshot_id,
												'status'   => 'PENDING',
												'url'      => $target->url,
												'dimension_changed' => 0,
												'metadata' => wp_json_encode( $metadata ),
												'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
												'updated_at' => gmdate( 'Y-m-d H:i:s', $now ),
											)
										)
									);
									$this->integer( (string) $wpdb->insert_id, true );
								}
							}
							$this->snapshots( $this->row( 'runs', $id ) );
							$item = (object) array(
								'run_uuid'    => $uuid,
								'status'      => 'queued',
								'deadline_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now + $runtime ),
							);
							$this->validate(
								'run-create-response',
								(object) array(
									'schema_version' => 1,
									'item'           => $item,
								)
							);
							return array(
								'item'         => $item,
								'runner_token' => $token,
								'id'           => $id,
							);
						}
					)
				);
			}
		);
	}

	/**
	 * 固定対象の行をID順に検証する。
	 *
	 * @param array $suite Suite行.
	 * @param bool  $lock ロック有無.
	 * @return array 設定と対象行.
	 */
	private function configuration_rows( $suite, $lock = false ) {
		global $wpdb;
		if ( 'active' !== $suite['status'] ) {
			$this->fail( 'odvr_suite_archived', 409 );
		}
		$config  = $this->suite_configuration( $suite );
		$targets = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d AND enabled = 1 ORDER BY id', ODVR_DB::table( 'targets' ), $this->integer( $suite['id'], true ) ) . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( ! $targets || count( $targets ) > 100 ) {
			$this->fail( 'odvr_invalid_targets', 409 );
		}
		$ids = $config->device_ids;
		sort( $ids, SORT_NUMERIC );
		$devices = array();
		foreach ( $ids as $id ) {
			$device = $this->row( 'devices', $id, $lock );
			if ( ! $this->boolean( $device['enabled'] ) ) {
				$this->fail( 'odvr_invalid_device_reference', 409 );
			}
			ODVR_Device_Repository::validate_row( $device, $this->validator );
			$devices[] = $device;
		}
		return array(
			'suite'   => $suite,
			'targets' => $targets,
			'devices' => $devices,
		);
	}

	/**
	 * 同Suiteのcompleteだけを候補とする。
	 *
	 * @param array    $suite Suite行.
	 * @param stdClass $input 作成契約.
	 * @param bool     $lock 行ロック.
	 * @return array|null 参照行.
	 */
	private function reference_row( $suite, $input, $lock = false ) {
		global $wpdb;
		$id = 'specific' === $input->baseline_mode ? $input->reference_run_id : $suite['baseline_run_id'];
		if ( 'previous' === $input->baseline_mode ) {
			$rows = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE suite_id = %d AND status = %s ORDER BY completed_at DESC, id DESC LIMIT 1', ODVR_DB::table( 'runs' ), $this->integer( $suite['id'], true ), 'complete' ) );
			$id   = $rows ? $rows[0]['id'] : null;
		}
		if ( null === $id ) {
			return null;
		}
		$row = $this->row( 'runs', $this->integer( $id, true ), $lock );
		if ( $suite['id'] !== $row['suite_id'] || 'complete' !== $row['status'] ) {
			$this->fail( 'odvr_invalid_reference', 409 );
		}
		return $row;
	}

	/**
	 * Manifestの固定組み合わせを解決する。
	 *
	 * @param stdClass $manifest Manifest.
	 * @param int      $target Target ID.
	 * @param int      $device Device ID.
	 * @return stdClass 参照.
	 */
	private function pair_reference( $manifest, $target, $device ) {
		foreach ( $manifest->reference->snapshots as $reference ) {
			if ( $target === $reference->target_id && $device === $reference->device_id ) {
				return $reference;
			}
		}
		$this->fail( 'odvr_invalid_stored_data', 503 );
	}

	/**
	 * PNGをRun共有ロック下で完全検査する。DBロックから呼ばない。
	 *
	 * @param array $run Run行.
	 * @param array $snapshot Snapshot行.
	 * @return string|WP_Error PNG.
	 */
	private function inspect_image( $run, $snapshot ) {
		$metadata = json_decode( $snapshot['metadata'] );
		$suite    = $this->row( 'suites', $this->integer( $run['suite_id'], true ) );
		if ( null === $snapshot['image_path'] || null === $metadata->image_sha256 || null === $snapshot['width'] || null === $snapshot['height'] ) {
			return new WP_Error( 'odvr_image_not_found', '画像を確認できません。', array( 'status' => 404 ) );
		}
		$storage = new ODVR_Storage();
		return $storage->with_run_lock(
			$run['uuid'],
			false,
			function () use ( $storage, $run, $snapshot, $metadata, $suite ) {
				return $storage->read_png( $snapshot['image_path'], $suite['uuid'], $run['uuid'], (int) $snapshot['target_id'], (int) $snapshot['width'], (int) $snapshot['height'], $metadata->image_sha256 );
			}
		);
	}

	/**
	 * 現行設定と候補の可読画像をロック前に固定する。
	 *
	 * @param int      $suite_id Suite ID.
	 * @param stdClass $input 作成契約.
	 * @param string   $auth_origin 秘密なしOrigin.
	 * @return array 固定情報と再確認用digest.
	 */
	private function prepare_run( $suite_id, $input, $auth_origin ) {
		$suite    = $this->row( 'suites', $suite_id );
		$rows     = $this->configuration_rows( $suite );
		$config   = $this->suite_configuration( $suite );
		$manifest = (object) array(
			'schema_version'     => 1,
			'suite'              => (object) array(
				'id'   => $suite_id,
				'name' => $suite['name'],
			),
			'targets'            => array(),
			'devices'            => array(),
			'settings'           => $config->settings,
			'allowed_origins'    => $config->allowed_origins,
			'queued_deadline_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'http_auth_origin'   => $auth_origin,
			'reference'          => (object) array(
				'mode'      => $input->baseline_mode,
				'run_id'    => null,
				'versions'  => null,
				'snapshots' => array(),
			),
		);
		if ( null !== $auth_origin && ( ! in_array( $auth_origin, $config->allowed_origins, true ) || 0 !== strpos( $auth_origin, 'https://' ) ) ) {
			$this->fail( 'odvr_invalid_auth_origin', 400 );
		}
		foreach ( $rows['targets'] as $target ) {
			$url                 = $this->checked( ODVR_Target_URL::resolve( $target['url'], null === $target['object_id'] ? null : $this->integer( $target['object_id'], true ), $target['post_type'] ) );
			$manifest->targets[] = (object) array(
				'id'        => $this->integer( $target['id'], true ),
				'url'       => $url,
				'label'     => $target['label'],
				'object_id' => null === $target['object_id'] ? null : $this->integer( $target['object_id'], true ),
				'post_type' => $target['post_type'],
			);
		}
		foreach ( $rows['devices'] as $device ) {
			$manifest->devices[] = (object) array(
				'id'                  => $this->integer( $device['id'], true ),
				'name'                => $device['name'],
				'slug'                => $device['slug'],
				'viewport_width'      => $this->integer( $device['viewport_width'], true ),
				'viewport_height'     => $this->integer( $device['viewport_height'], true ),
				'device_scale_factor' => (float) $device['device_scale_factor'],
				'is_mobile'           => $this->boolean( $device['is_mobile'] ),
				'has_touch'           => $this->boolean( $device['has_touch'] ),
				'user_agent'          => $device['user_agent'],
			);
		}
		$reference = $this->reference_row( $suite, $input );
		$old       = null;
		$snapshots = array();
		$versions  = null;
		if ( null !== $reference ) {
			$old         = $this->fixed( $reference );
			$snapshots   = $this->snapshots( $reference );
			$environment = $this->checked( ODVR_Environment::decode( $reference['environment'] ) );
			$versions    = (object) array(
				'runner'     => $environment->runner,
				'playwright' => $environment->playwright,
				'chromium'   => $environment->chromium,
			);
			if ( ! ODVR_Baseline::versions( $versions, $versions ) ) {
				$versions = null;
			}
			$manifest->reference->run_id   = $this->integer( $reference['id'], true );
			$manifest->reference->versions = $versions;
		}
		foreach ( $manifest->targets as $target ) {
			foreach ( $manifest->devices as $device ) {
				$reason      = null === $old ? 'no_reference' : ( ! ODVR_Baseline::find( $old->targets, $target->id ) ? 'new_target' : ( ! ODVR_Baseline::find( $old->devices, $device->id ) ? 'new_device' : ( null === $versions || ! ODVR_Baseline::conditions( $old, $manifest, $target->id, $device->id ) ? 'incompatible' : 'missing' ) ) );
				$baseline_id = null;
				if ( 'missing' === $reason ) {
					foreach ( $snapshots as $snapshot ) {
						if ( (int) $snapshot['target_id'] === $target->id && (int) $snapshot['device_id'] === $device->id ) {
							$image = $this->inspect_image( $reference, $snapshot );
							if ( is_wp_error( $image ) ) {
								if ( 404 !== ( $image->get_error_data()['status'] ?? 500 ) ) {
									$this->checked( $image );
								}
								$reason = $image->get_error_data()['reason'] ?? 'corrupt';
							} else {
								$baseline_id = $this->integer( $snapshot['id'], true );
								$reason      = null;
							}
							break;
						}
					}
				}
				$manifest->reference->snapshots[] = (object) array(
					'target_id'            => $target->id,
					'device_id'            => $device->id,
					'baseline_snapshot_id' => $baseline_id,
					'reason'               => $reason,
				);
			}
		}
		$this->validate( 'stored-run-manifest', $manifest );
		return array(
			'manifest'             => $manifest,
			'configuration_digest' => ODVR_Environment::digest( (object) $rows ),
			'reference_id'         => $manifest->reference->run_id,
			'reference_digest'     => null === $reference ? null : ODVR_Environment::digest(
				(object) array(
					'row'       => $reference,
					'snapshots' => $snapshots,
				)
			),
		);
	}

	/**
	 * Completeかつ全PNGが可読のRunだけをPinnedへ昇格する。
	 *
	 * @param int $suite_id Suite ID.
	 * @param int $run_id Run ID.
	 * @return bool|WP_Error 成功.
	 */
	public function promote_baseline( $suite_id, $run_id ) {
		return $this->read(
			function () use ( $suite_id, $run_id ) {
				global $wpdb;
				$suite       = $this->row( 'suites', $suite_id );
				$input       = (object) array(
					'baseline_mode'    => 'specific',
					'reference_run_id' => $this->id( $run_id ),
				);
				$row         = $this->reference_row( $suite, $input );
				$environment = $this->checked( ODVR_Environment::decode( $row['environment'] ) );
				if ( ! ODVR_Baseline::versions( $environment, $environment ) ) {
					$this->fail( 'odvr_incompatible_versions', 409 );
				}
				$snapshots = $this->snapshots( $row );
				foreach ( $snapshots as $snapshot ) {
						$this->checked( $this->inspect_image( $row, $snapshot ) );
				}
				$digest = ODVR_Environment::digest(
					(object) array(
						'row'       => $row,
						'snapshots' => $snapshots,
					)
				);
				return $this->checked(
					$this->transaction(
						function () use ( $suite_id, $input, $digest ) {
							global $wpdb;
							$suite = $this->row( 'suites', $suite_id, true );
							if ( 'active' !== $suite['status'] ) {
									$this->fail( 'odvr_suite_archived', 409 );
							}
							$row = $this->reference_row( $suite, $input, true );
							if ( ODVR_Environment::digest(
								(object) array(
									'row'       => $row,
									'snapshots' => $this->snapshots( $row, true ),
								)
							) !== $digest ) {
								$this->fail( 'odvr_reference_changed', 409 );
							}
							$this->written(
								$wpdb->update(
									ODVR_DB::table( 'suites' ),
									array(
										'baseline_run_id' => $input->reference_run_id,
										'updated_at'      => ODVR_DB::utc_now(),
									),
									array( 'id' => $suite_id )
								)
							);
							return true;
						}
					)
				);
			}
		);
	}


	/**
	 * 定型Runエラー。任意の例外本文を保存しない。
	 *
	 * @param string $code コード.
	 * @return string|null 定型メッセージ.
	 */
	public static function error_message( $code ) {
		$messages = array(
			'SNAPSHOT_FAILED'       => '一部の撮影結果を取得できませんでした。',
			'NAVIGATION_FAILED'     => 'ページ遷移に失敗しました。',
			'HTTP_ERROR'            => 'HTTP応答に失敗しました。',
			'CAPTURE_FAILED'        => '撮影に失敗しました。',
			'CONTEXT_CLOSE_FAILED'  => 'Browser Contextの終了に失敗しました。',
			'RUN_ABORTED'           => 'Runの実行を中止しました。',
			'RUN_DEADLINE_EXCEEDED' => 'Runの実行期限を超えました。',
			'DISPATCH_TIMEOUT'      => 'Runの起動を確認できませんでした。',
		);
		return $messages[ $code ] ?? null;
	}

	/**
	 * 既存RunのSuiteを先にロックし、Runと全SnapshotをID順にロックする。
	 *
	 * @param string $uuid UUID.
	 * @return array Run行.
	 */
	private function lock_run( $uuid ) {
		$before = $this->uuid_row( $uuid );
		$this->row( 'suites', $this->integer( $before['suite_id'], true ), true );
		$row = $this->uuid_row( $uuid, true );
		if ( $before['suite_id'] !== $row['suite_id'] ) {
			$this->fail( 'odvr_invalid_stored_data', 503 );
		}
		$this->snapshots( $row, true );
		$this->checked( ODVR_Environment::decode( $row['environment'] ) );
		return $row;
	}

	/**
	 * 初回Manifest取得でExecution IDを固定する。retryは同じIDだけ。
	 *
	 * @param string $uuid UUID.
	 * @param string $execution_id Execution ID.
	 * @return stdClass|WP_Error Manifest.
	 */
	public function start( $uuid, $execution_id ) {
		return $this->read(
			function () use ( $uuid, $execution_id ) {
				$before = $this->uuid_row( $uuid );
				$this->checked( $this->expire( $this->integer( $before['id'], true ) ) );
				if ( ! is_string( $execution_id ) || '' === $execution_id || strlen( $execution_id ) > 200 || preg_match( '/[\x00-\x1f\x7f]/', $execution_id ) ) {
						$this->fail( 'odvr_invalid_execution_id', 400 );
				}
				return $this->checked(
					$this->transaction(
						function () use ( $uuid, $execution_id ) {
								global $wpdb;
								$row = $this->lock_run( $uuid );
							if ( ! in_array( $row['status'], array( 'queued', 'running' ), true ) || ( null !== $row['runner_execution_id'] && $row['runner_execution_id'] !== $execution_id ) ) {
								$this->fail( 'odvr_run_conflict', 409 );
							}
							if ( 'queued' === $row['status'] ) {
								$this->written(
									$wpdb->update(
										ODVR_DB::table( 'runs' ),
										array(
											'status'     => 'running',
											'runner_execution_id' => $execution_id,
											'started_at' => ODVR_DB::utc_now(),
											'updated_at' => ODVR_DB::utc_now(),
										),
										array(
											'id'     => $this->integer( $row['id'], true ),
											'status' => 'queued',
										)
									)
								);
								$row = $this->row( 'runs', $this->integer( $row['id'], true ) );
							}
								return $this->wire_manifest( $row );
						}
					)
				);
			}
		);
	}

	/**
	 * 固定部分と現在のstatus/Execution/Snapshot状態だけを合成する。
	 *
	 * @param array $row Run行.
	 * @return stdClass Manifest.
	 */
	private function wire_manifest( $row ) {
		$fixed = $this->fixed( $row );
		$wire  = clone $fixed;
		unset( $wire->queued_deadline_at, $wire->http_auth_origin );
		$states = array();
		foreach ( $this->snapshots( $row ) as $snapshot ) {
			$states[] = (object) array(
				'snapshot_id' => $this->integer( $snapshot['id'], true ),
				'target_id'   => $this->integer( $snapshot['target_id'], true ),
				'device_id'   => $this->integer( $snapshot['device_id'], true ),
				'status'      => $snapshot['status'],
			);
		}
		$wire->run = (object) array(
			'uuid'                => $row['uuid'],
			'suite_id'            => $this->integer( $row['suite_id'], true ),
			'status'              => $row['status'],
			'created_at'          => $this->checked( ODVR_DB::utc_datetime( $row['created_at'], false ) ),
			'deadline_at'         => $this->checked( ODVR_DB::utc_datetime( $row['deadline_at'], false ) ),
			'runner_execution_id' => $row['runner_execution_id'],
			'snapshot_states'     => $states,
		);
		$this->validate( 'run-manifest', $wire );
		return $wire;
	}

	/**
	 * ProgressでVersionを補完し、件数だけを再計算する。
	 *
	 * @param string   $uuid UUID.
	 * @param stdClass $input Progress契約.
	 * @return stdClass|WP_Error 状態.
	 */
	public function progress( $uuid, $input ) {
		return $this->read(
			function () use ( $uuid, $input ) {
				$this->validate( 'progress-request', $input );
				$row = $this->uuid_row( $uuid );
				$this->checked( $this->expire( $this->integer( $row['id'], true ) ) );
				return $this->checked(
					$this->transaction(
						function () use ( $uuid, $input ) {
							global $wpdb;
							$row = $this->lock_run( $uuid );
							$this->running( $row, $input->runner_execution_id );
							$environment = $this->checked( ODVR_Environment::versions( $this->checked( ODVR_Environment::decode( $row['environment'] ) ), $input->versions ) );
							$state       = $this->state( $row );
							$this->written(
								$wpdb->update(
									ODVR_DB::table( 'runs' ),
									array(
										'environment'     => wp_json_encode( $environment ),
										'completed_snapshots' => $state->completed_snapshots,
										'error_snapshots' => $state->error_snapshots,
										'updated_at'      => ODVR_DB::utc_now(),
									),
									array( 'id' => $this->integer( $row['id'], true ) )
								)
							);
							return $state;
						}
					)
				);
			}
		);
	}

	/**
	 * 実行中の同Executionだけを許可する。
	 *
	 * @param array  $row Run行.
	 * @param string $execution_id Execution ID.
	 * @return void
	 */
	private function running( $row, $execution_id ) {
		if ( 'running' !== $row['status'] || $row['runner_execution_id'] !== $execution_id ) {
			$this->fail( 'odvr_run_conflict', 409 );
		}
	}

	/**
	 * 同じCompleteだけを再送できる原子的な完了処理。
	 *
	 * @param string   $uuid UUID.
	 * @param stdClass $input Complete契約.
	 * @return stdClass|WP_Error 最終状態.
	 */
	public function finish( $uuid, $input ) {
		return $this->read(
			function () use ( $uuid, $input ) {
				$this->validate( 'complete-request', $input );
				if ( 'failed' === $input->outcome && self::error_message( $input->error_code ) !== $input->error_message ) {
						$this->fail( 'odvr_invalid_payload', 400 );
				}
				$before = $this->uuid_row( $uuid );
				$this->checked( $this->expire( $this->integer( $before['id'], true ) ) );
				return $this->checked(
					$this->transaction(
						function () use ( $uuid, $input ) {
								global $wpdb;
								$row         = $this->lock_run( $uuid );
								$environment = $this->checked( ODVR_Environment::decode( $row['environment'] ) );
								$digest      = ODVR_Environment::digest( $input );
							if ( in_array( $row['status'], array( 'complete', 'partial', 'failed' ), true ) ) {
								if ( null === $environment->completion || ! hash_equals( $environment->completion->digest, $digest ) || $environment->completion->response->run_uuid !== $uuid || $input->runner_execution_id !== $row['runner_execution_id'] ) {
									$this->fail( 'odvr_run_conflict', 409 );
								}
								return $environment->completion->response;
							}
								$this->running( $row, $input->runner_execution_id );
								$environment = $this->checked( ODVR_Environment::versions( $environment, $input->versions ) );
								$state       = $this->state( $row );
							if ( 'finished' === $input->outcome && $state->pending_snapshots > 0 ) {
								$this->fail( 'odvr_run_incomplete', 409 );
							}
							if ( 'failed' === $input->outcome ) {
								$this->pending_errors( $row, 'RUN_ABORTED' );
							}
								$counts  = $this->count_results( $row );
								$status  = 'failed' === $input->outcome || 0 === $counts['completed_snapshots'] ? 'failed' : ( 0 === $counts['error_snapshots'] ? 'complete' : 'partial' );
								$code    = 'failed' === $input->outcome ? $input->error_code : ( 'complete' === $status ? null : 'SNAPSHOT_FAILED' );
								$changes = array_merge(
									$counts,
									array(
										'status'        => $status,
										'completed_at'  => ODVR_DB::utc_now(),
										'updated_at'    => ODVR_DB::utc_now(),
										'error_code'    => $code,
										'error_message' => null === $code ? null : self::error_message( $code ),
									)
								);
								$this->written(
									$wpdb->update(
										ODVR_DB::table( 'runs' ),
										$changes,
										array(
											'id'     => $this->integer( $row['id'], true ),
											'status' => 'running',
										)
									)
								);
								$row                     = $this->row( 'runs', $this->integer( $row['id'], true ) );
								$response                = $this->state( $row );
								$environment->completion = (object) array(
									'digest'   => $digest,
									'request'  => $input,
									'response' => $response,
								);
								$this->validate( 'stored-run-environment', $environment );
								$this->written( $wpdb->update( ODVR_DB::table( 'runs' ), array( 'environment' => wp_json_encode( $environment ) ), array( 'id' => $this->integer( $row['id'], true ) ) ) );
								return $response;
						}
					)
				);
			}
		);
	}

	/**
	 * Snapshotの確定結果からCOUNTを計算する。
	 *
	 * @param array $row Run行.
	 * @return array 永続化する件数.
	 */
	private function count_results( $row ) {
		$counts = array(
			'completed_snapshots' => 0,
			'error_snapshots'     => 0,
		);
		foreach ( $this->snapshots( $row ) as $snapshot ) {
			if ( 'ERROR' === $snapshot['status'] ) {
				++$counts['error_snapshots'];
			} elseif ( 'PENDING' !== $snapshot['status'] ) {
				++$counts['completed_snapshots'];
			}
		}
		return $counts;
	}

	/**
	 * 未確定pendingだけを定型ERRORにし、固定ラベル・参照・成功結果を残す。
	 *
	 * @param array  $row Run行.
	 * @param string $code エラーコード.
	 * @return void
	 */
	private function pending_errors( $row, $code ) {
		global $wpdb;
		foreach ( $this->snapshots( $row, true ) as $snapshot ) {
			if ( 'PENDING' !== $snapshot['status'] ) {
				continue;
			}
			$result = (object) array(
				'schema_version'     => 1,
				'target_id'          => $this->integer( $snapshot['target_id'], true ),
				'device_id'          => $this->integer( $snapshot['device_id'], true ),
				'status'             => 'ERROR',
				'width'              => null,
				'height'             => null,
				'baseline_width'     => null,
				'baseline_height'    => null,
				'dimension_changed'  => false,
				'diff_pixels'        => null,
				'total_pixels'       => null,
				'diff_ratio'         => null,
				'duration_ms'        => 0,
				'http_status'        => null,
				'error_code'         => $code,
				'error_message'      => self::error_message( $code ),
				'no_baseline_reason' => null,
			);
			$this->validate( 'snapshot-result', $result );
			$metadata                = json_decode( $snapshot['metadata'] );
			$metadata->result        = $result;
			$metadata->result_digest = ODVR_Environment::digest( $result );
			$this->validate( 'snapshot-metadata', $metadata );
			$this->written(
				$wpdb->update(
					ODVR_DB::table( 'snapshots' ),
					array(
						'status'        => 'ERROR',
						'duration_ms'   => 0,
						'error_code'    => $code,
						'error_message' => self::error_message( $code ),
						'metadata'      => wp_json_encode( $metadata ),
						'updated_at'    => ODVR_DB::utc_now(),
					),
					array(
						'id'     => $this->integer( $snapshot['id'], true ),
						'status' => 'PENDING',
					)
				)
			);
		}
	}

	/**
	 * 起動失敗や管理側の強制失敗。Tokenは即時失効する。
	 *
	 * @param string $uuid UUID.
	 * @param string $code 定型コード.
	 * @return stdClass|WP_Error 状態.
	 */
	public function fail_run( $uuid, $code = 'RUN_ABORTED' ) {
		return $this->transaction(
			function () use ( $uuid, $code ) {
				if ( null === self::error_message( $code ) ) {
						$this->fail( 'odvr_invalid_payload', 400 );
				}
				$row = $this->lock_run( $uuid );
				if ( ! in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
					return $this->state( $row );
				}
				return $this->close_failed( $row, $code );
			}
		);
	}

	/**
	 * ロック内で失敗を確定する。
	 *
	 * @param array  $row Run行.
	 * @param string $code コード.
	 * @return stdClass 状態.
	 */
	private function close_failed( $row, $code ) {
		global $wpdb;
		$this->pending_errors( $row, $code );
		$changes = array_merge(
			$this->count_results( $row ),
			array(
				'status'            => 'failed',
				'completed_at'      => ODVR_DB::utc_now(),
				'updated_at'        => ODVR_DB::utc_now(),
				'error_code'        => $code,
				'error_message'     => self::error_message( $code ),
				'runner_token_hash' => null,
			)
		);
		$this->written( $wpdb->update( ODVR_DB::table( 'runs' ), $changes, array( 'id' => $this->integer( $row['id'], true ) ) ) );
		return $this->state( $this->row( 'runs', $this->integer( $row['id'], true ) ) );
	}

	/**
	 * 期限を延長せず、queued期限/Run期限とToken期限を処理する。
	 *
	 * @param int      $id Run ID.
	 * @param int|null $at 内部試験用のUTC秒。APIは指定しない.
	 * @return stdClass|WP_Error 状態.
	 */
	public function expire( $id, $at = null ) {
		return $this->read(
			function () use ( $id, $at ) {
				global $wpdb;
				$at        = null === $at ? time() : $this->integer( $at, true );
				$now       = gmdate( 'Y-m-d H:i:s', $at );
				$row       = $this->row( 'runs', $id );
				$fixed     = $this->fixed( $row );
				$queued    = $this->checked( ODVR_DB::utc_datetime( $fixed->queued_deadline_at ) );
				$due       = in_array( $row['status'], array( 'queued', 'running' ), true ) && ( $row['deadline_at'] <= $now || ( 'queued' === $row['status'] && $queued <= $now ) );
				$token_due = null !== $row['runner_token_hash'] && $row['runner_token_expires_at'] <= $now;
				if ( ! $due && ! $token_due ) {
						return $this->state( $row );
				}
				return $this->checked(
					$this->transaction(
						function () use ( $row, $now, $wpdb ) {
								$current = $this->lock_run( $row['uuid'] );
								$fixed   = $this->fixed( $current );
								$queued  = $this->checked( ODVR_DB::utc_datetime( $fixed->queued_deadline_at ) );
							if ( in_array( $current['status'], array( 'queued', 'running' ), true ) ) {
								if ( 'queued' === $current['status'] && $queued <= $now ) {
									return $this->close_failed( $current, 'DISPATCH_TIMEOUT' );
								}
								if ( $current['deadline_at'] <= $now ) {
									return $this->close_failed( $current, 'RUN_DEADLINE_EXCEEDED' );
								}
							}
							if ( null !== $current['runner_token_hash'] && $current['runner_token_expires_at'] <= $now ) {
								$this->written( $wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_token_hash' => null ), array( 'id' => $this->integer( $current['id'], true ) ) ) );
							}
								return $this->state( $current );
						}
					)
				);
			}
		);
	}
	/**
	 * 終端かつ外部参照のないRunだけをdeletingへ確定する。画像削除はCOMMIT後。
	 *
	 * @param int $id Run ID.
	 * @return stdClass|WP_Error 状態.
	 */
	public function request_delete( $id ) {
		return $this->read(
			function () use ( $id ) {
				$before = $this->row( 'runs', $id );
				$this->checked( $this->expire( $id ) );
				return $this->checked(
					$this->transaction(
						function () use ( $before, $id ) {
							global $wpdb;
							$row = $this->lock_run( $before['uuid'] );
							if ( 'deleting' === $row['status'] ) {
									return $this->state( $row );
							}
							if ( ! in_array( $row['status'], array( 'complete', 'partial', 'failed' ), true ) ) {
								$this->fail( 'odvr_run_protected', 409 );
							}
							$referenced = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE baseline_run_id = %d', ODVR_DB::table( 'suites' ), $id ) );
							$referenced = array_merge( $referenced, $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE reference_run_id = %d AND status <> %s', ODVR_DB::table( 'runs' ), $id, 'deleting' ) ) );
							$referenced = array_merge( $referenced, $this->rows( $wpdb->prepare( 'SELECT s.id FROM %i s JOIN %i r ON r.id = s.run_id JOIN %i b ON b.id = s.baseline_snapshot_id WHERE b.run_id = %d AND r.status <> %s', ODVR_DB::table( 'snapshots' ), ODVR_DB::table( 'runs' ), ODVR_DB::table( 'snapshots' ), $id, 'deleting' ) ) );
							if ( $referenced ) {
								$this->fail( 'odvr_run_protected', 409 );
							}
							$this->written(
								$wpdb->update(
									ODVR_DB::table( 'runs' ),
									array(
										'status'     => 'deleting',
										'runner_token_hash' => null,
										'deletion_requested_at' => ODVR_DB::utc_now(),
										'updated_at' => ODVR_DB::utc_now(),
									),
									array( 'id' => $this->id( $id ) )
								)
							);
							return $this->state( $this->row( 'runs', $id ) );
						}
					)
				);
			}
		);
	}

	/**
	 * Dispatcherの確定Execution IDをqueued時に保存する。
	 *
	 * @param string $uuid UUID.
	 * @param string $execution_id Execution ID.
	 * @return stdClass|WP_Error 状態.
	 */
	public function bind_execution( $uuid, $execution_id ) {
		$before = $this->read(
			function () use ( $uuid ) {
				return $this->uuid_row( $uuid );
			}
		);
		if ( is_wp_error( $before ) ) {
			return $before; }
		$expired = $this->expire( (int) $before['id'] );
		if ( is_wp_error( $expired ) ) {
			return $expired; }
		return $this->transaction(
			function () use ( $uuid, $execution_id ) {
				global $wpdb;
				if ( ! is_string( $execution_id ) || '' === $execution_id || strlen( $execution_id ) > 200 || preg_match( '/[\x00-\x1f\x7f]/', $execution_id ) ) {
						$this->fail( 'odvr_invalid_execution_id', 400 );
				}
				$row = $this->lock_run( $uuid );
				if ( ! in_array( $row['status'], array( 'queued', 'running' ), true ) || ( null !== $row['runner_execution_id'] && $row['runner_execution_id'] !== $execution_id ) ) {
					$this->fail( 'odvr_run_conflict', 409 );
				}
				$this->written( $wpdb->update( ODVR_DB::table( 'runs' ), array( 'runner_execution_id' => $execution_id ), array( 'id' => $this->integer( $row['id'], true ) ) ) );
				return $this->state( $row );
			}
		);
	}
}
