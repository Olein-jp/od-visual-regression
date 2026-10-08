<?php
/**
 * 固定Snapshotの画像・結果を一度だけ確定する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/** ファイル排他ロックとSuite→Run→Snapshotの短いDBロックで確定する。 */
final class ODVR_Snapshot_Repository extends ODVR_Run_Repository {
	/**
	 * 保存直前にもToken・Execution・期限を検査する。DB書込を行わない。
	 *
	 * @param array  $row Run行.
	 * @param string $authorization Bearer.
	 * @param string $execution Execution ID.
	 * @return void
	 */
	private function active( $row, $authorization, $execution ) {
		$this->checked( ODVR_DB::writable() );
		if ( ! preg_match( '/^Bearer ([A-Za-z0-9_-]{43})$/D', $authorization, $matches ) || ! is_string( $row['runner_token_hash'] ) || ! hash_equals( $row['runner_token_hash'], hash( 'sha256', $matches[1] ) ) || null === $row['runner_token_expires_at'] || ODVR_DB::utc_now() >= $row['runner_token_expires_at'] ) {
			$this->fail( 'odvr_runner_unauthorized', 401 );
		}
		if ( 'running' !== $row['status'] || $row['runner_execution_id'] !== $execution || ODVR_DB::utc_now() >= $row['deadline_at'] ) {
			$this->fail( 'odvr_run_conflict', 409 );
		}
	}
	/**
	 * 結果と画像の不変内容から全体digestを計算する。
	 *
	 * @param stdClass    $result 結果.
	 * @param string|null $image current SHA.
	 * @param string|null $diff diff SHA.
	 * @return string digest.
	 */
	public static function digest( $result, $image, $diff ) {
		return ODVR_Environment::digest(
			(object) array(
				'result'       => $result,
				'image_sha256' => $image,
				'diff_sha256'  => $diff,
			)
		);
	}
	/**
	 * 固定参照だけのBaselineを再検査して返す。
	 *
	 * @param array  $run 認証済みRun.
	 * @param int    $id 固定参照Snapshot ID.
	 * @param string $authorization Bearer.
	 * @param string $execution Execution ID.
	 * @return array|WP_Error PNGとdigest.
	 */
	public function baseline( $run, $id, $authorization, $execution ) {
		return $this->read(
			function () use ( $run, $id, $authorization, $execution ) {
				$this->active( $this->uuid_row( $run['uuid'] ), $authorization, $execution );
				$fixed = $this->fixed( $run );
				$pair  = null;
				foreach ( $fixed->reference->snapshots as $reference ) {
					if ( $id === $reference->baseline_snapshot_id ) {
						$pair = $reference;
						break;
					}
				}
				if ( null === $pair ) {
					$this->fail( 'odvr_not_found', 404 );
				}
				$reference = $this->checked( ( new ODVR_Run_Manager() )->baseline_reference( $run['uuid'], $pair->target_id, $pair->device_id ) );
				if ( null === $reference->baseline_snapshot_id ) {
					$this->fail( 'odvr_baseline_unavailable', 404, array( 'reason' => $reference->reason ) );
				}
				$source  = $this->row( 'runs', $fixed->reference->run_id );
				$storage = new ODVR_Storage();
				return $this->checked(
					$storage->with_run_lock(
						$source['uuid'],
						false,
						function () use ( $run, $id, $authorization, $execution, $fixed, $source, $storage ) {
							$this->active( $this->uuid_row( $run['uuid'] ), $authorization, $execution );
							$current = $this->row( 'runs', (int) $source['id'] );
							if ( 'complete' !== $current['status'] || $current['suite_id'] !== $run['suite_id'] || (int) $current['id'] !== $fixed->reference->run_id ) {
								$this->fail( 'odvr_not_found', 404 );
							}
							$snapshot = $this->row( 'snapshots', $id );
							$meta     = json_decode( $snapshot['metadata'] );
							$this->validate( 'snapshot-metadata', $meta );
							$suite = $this->row( 'suites', (int) $run['suite_id'] );
							$png   = $this->checked( $storage->read_png( $snapshot['image_path'], $suite['uuid'], $source['uuid'], (int) $snapshot['target_id'], (int) $snapshot['width'], (int) $snapshot['height'], $meta->image_sha256 ) );
							return array(
								'png'    => $png,
								'sha256' => $meta->image_sha256,
							);
						}
					)
				);
			}
		);
	}
	/**
	 * 生multipartの検証済み結果をpendingへ確定する。
	 *
	 * @param array  $run 認証済みRun行.
	 * @param array  $input 結果とPNG.
	 * @param string $authorization Bearer.
	 * @param string $execution Execution ID.
	 * @return stdClass|WP_Error 元のIDと再送フラグ.
	 */
	public function upload( $run, $input, $authorization, $execution ) {
		$tickets  = array();
		$response = $this->read(
			function () use ( $run, $input, $authorization, $execution, &$tickets ) {
				$this->active( $this->uuid_row( $run['uuid'] ), $authorization, $execution );
				$result = clone $input['result'];
				$this->validate( 'snapshot-result', $result );
				$fixed       = $this->fixed( $run );
				$environment = $this->checked( ODVR_Environment::decode( $run['environment'] ) );
				if ( ! ODVR_Baseline::versions( $environment, $environment ) ) {
					$this->fail( 'odvr_versions_required', 409 );
				}
				$selected = null;
				foreach ( $this->snapshots( $run ) as $snapshot ) {
					if ( (int) $snapshot['target_id'] === $result->target_id && (int) $snapshot['device_id'] === $result->device_id ) {
						$selected = $snapshot;
						break;
					}
				}
				if ( null === $selected ) {
					$this->fail( 'odvr_not_found', 404 );
				}
				if ( 'PENDING' === $selected['status'] ) {
					$this->conditions( $run, $result, $fixed, $input['files'] );
				} elseif ( null !== $result->diff_ratio ) {
					// 確定済み結果の再送では参照画像の現在状態で元の受理を取り消さない.
					if ( abs( $result->diff_ratio - $result->diff_pixels / $result->total_pixels ) > 1e-10 ) {
						$this->fail( 'odvr_invalid_payload' );
					}
					$result->diff_ratio = round( $result->diff_pixels / $result->total_pixels, 10 );
				}
				$storage   = new ODVR_Storage();
				$image_sha = null;
				$diff_sha  = null;
				foreach ( $input['files'] as $kind => $png ) {
					$width            = 'image' === $kind ? $result->width : max( $result->width, $result->baseline_width );
					$height           = 'image' === $kind ? $result->height : max( $result->height, $result->baseline_height );
					$tickets[ $kind ] = $this->checked( $storage->stage( $run['uuid'], $png, $width, $height ) );
					if ( 'image' === $kind ) {
						$image_sha = $tickets[ $kind ]['info']['sha256'];
					} else {
						$diff_sha = $tickets[ $kind ]['info']['sha256'];
					}
				}
				$digest = self::digest( $result, $image_sha, $diff_sha );
				return $this->checked(
					$storage->with_run_lock(
						$run['uuid'],
						true,
						function () use ( $run, $selected, $result, $tickets, $image_sha, $diff_sha, $digest, $storage, $authorization, $execution ) {
							return $this->transaction(
								function () use ( $run, $selected, $result, $tickets, $image_sha, $diff_sha, $digest, $storage, $authorization, $execution ) {
									global $wpdb;
									$suite   = $this->row( 'suites', (int) $run['suite_id'], true );
									$current = $this->uuid_row( $run['uuid'], true );
									$this->active( $current, $authorization, $execution );
									$rows     = $this->snapshots( $current, true );
									$snapshot = $this->row( 'snapshots', (int) $selected['id'], true );
									$metadata = json_decode( $snapshot['metadata'] );
									$replayed = 'PENDING' !== $snapshot['status'];
									if ( $replayed ) {
										if ( ! hash_equals( self::digest( $metadata->result, $metadata->image_sha256, $metadata->diff_sha256 ), $digest ) ) {
											$this->fail( 'odvr_snapshot_conflict', 409 );
										}
									} else {
										$data = get_object_vars( $result );
										foreach ( array( 'schema_version', 'target_id', 'device_id', 'no_baseline_reason' ) as $key ) {
											unset( $data[ $key ] );
										}
										foreach ( $tickets as $kind => $ticket ) {
											$data[ 'image' === $kind ? 'image_path' : 'diff_path' ] = $this->checked( $storage->promote( $ticket, $suite['uuid'], $metadata->target->id, $metadata->device->slug, $digest, 'diff_image' === $kind ) );
										}
										$metadata->result        = $result;
										$metadata->result_digest = ODVR_Environment::digest( $result );
										$metadata->image_sha256  = $image_sha;
										$metadata->diff_sha256   = $diff_sha;
										$this->validate( 'snapshot-metadata', $metadata );
										$data['metadata'] = wp_json_encode( $metadata );
										$this->written(
											$wpdb->update(
												ODVR_DB::table( 'snapshots' ),
												$data,
												array(
													'id' => (int) $snapshot['id'],
													'status' => 'PENDING',
												)
											)
										);
										$success = 0;
										$errors  = 0;
										foreach ( $rows as $other ) {
											if ( $other['id'] === $snapshot['id'] ) {
												$success += 'ERROR' === $result->status ? 0 : 1;
												$errors  += 'ERROR' === $result->status ? 1 : 0;
											} elseif ( 'ERROR' === $other['status'] ) {
												++$errors;
											} elseif ( 'PENDING' !== $other['status'] ) {
												++$success;
											}
										}
										$this->written(
											$wpdb->update(
												ODVR_DB::table( 'runs' ),
												array(
													'completed_snapshots' => $success,
													'error_snapshots' => $errors,
													'updated_at' => ODVR_DB::utc_now(),
												),
												array( 'id' => (int) $run['id'] )
											)
										);
									}
									$response = (object) array(
										'schema_version' => 1,
										'snapshot_id'    => (int) $snapshot['id'],
										'status'         => $result->status,
										'replayed'       => $replayed,
									);
									$this->validate( 'snapshot-upload-response', $response );
									return $response;
								}
							);
						}
					)
				);
			}
		);
		foreach ( $tickets as $ticket ) {
			( new ODVR_Storage() )->discard( $ticket );
		}
		return $response;
	}
	/**
	 * 固定参照・画像条件・比率・閾値を検査する。
	 *
	 * @param array    $run Run行.
	 * @param stdClass $result 結果.
	 * @param stdClass $fixed 固定Manifest.
	 * @param array    $files PNG.
	 * @return void
	 */
	private function conditions( $run, $result, $fixed, $files ) {
		$expected = 'ERROR' === $result->status ? array() : ( in_array( $result->status, array( 'UNCHANGED', 'REVIEW', 'CHANGED' ), true ) ? array( 'image', 'diff_image' ) : array( 'image' ) );
		if ( array_diff( $expected, array_keys( $files ) ) || array_diff( array_keys( $files ), $expected ) || ( 'ERROR' === $result->status && ODVR_Run_Manager::error_message( $result->error_code ) !== $result->error_message ) ) {
			$this->fail( 'odvr_invalid_payload' );
		}
		if ( 'ERROR' === $result->status ) {
			return;
		}
		if ( 'CAPTURED' === $result->status ) {
			if ( null !== $fixed->reference->run_id ) {
				$this->fail( 'odvr_invalid_payload' );
			}
			return;
		}
		$reference = $this->checked( ( new ODVR_Run_Manager() )->baseline_reference( $run['uuid'], $result->target_id, $result->device_id ) );
		if ( 'NO_BASELINE' === $result->status ) {
			if ( null === $fixed->reference->run_id || null !== $reference->baseline_snapshot_id || $result->no_baseline_reason !== $reference->reason ) {
				$this->fail( 'odvr_invalid_payload' );
			}
			return;
		}
		if ( null === $reference->baseline_snapshot_id ) {
			$this->fail( 'odvr_invalid_payload' );
		}
		$baseline = $this->row( 'snapshots', $reference->baseline_snapshot_id );
		$width    = max( $result->width, $result->baseline_width );
		$height   = max( $result->height, $result->baseline_height );
		$total    = $width * $height;
		if ( $result->baseline_width !== (int) $baseline['width'] || $result->baseline_height !== (int) $baseline['height'] || 40000000 < $total || $result->total_pixels !== $total || ( $result->width !== $result->baseline_width || $result->height !== $result->baseline_height ) !== $result->dimension_changed || $result->diff_pixels > $total || abs( $result->diff_ratio - $result->diff_pixels / $total ) > 1e-10 ) {
			$this->fail( 'odvr_invalid_payload' );
		}
		$ratio  = $result->diff_pixels / $total;
		$status = $ratio >= $fixed->settings->changed_threshold ? 'CHANGED' : ( $ratio > $fixed->settings->review_threshold ? 'REVIEW' : 'UNCHANGED' );
		if ( $status !== $result->status ) {
			$this->fail( 'odvr_invalid_payload' );
		}
		$result->diff_ratio = round( $ratio, 10 );
	}
}
