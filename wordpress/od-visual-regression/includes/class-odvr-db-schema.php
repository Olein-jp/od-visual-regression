<?php
/**
 * Version 1のDB構造。DDLと検証が同じ定義を参照する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * サイト単位の5テーブルだけを定義する。
 */
final class ODVR_DB_Schema {
	/**
	 * 列と索引の固定定義を返す。
	 *
	 * @return array 定義.
	 */
	public static function definitions() {
		return array(
			'suites'    => array(
				'columns' => array(
					'id'              => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
					'uuid'            => 'char(36) NOT NULL',
					'name'            => 'varchar(200) NOT NULL',
					'status'          => 'varchar(20) NOT NULL',
					'baseline_run_id' => 'bigint(20) unsigned NULL',
					'settings'        => 'longtext NOT NULL',
					'created_by'      => 'bigint(20) unsigned NOT NULL',
					'created_at'      => 'datetime NOT NULL',
					'updated_at'      => 'datetime NOT NULL',
				),
				'indexes' => array(
					'PRIMARY'        => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'uuid'           => array(
						'unique'  => true,
						'columns' => array( 'uuid' ),
					),
					'status_updated' => array(
						'unique'  => false,
						'columns' => array( 'status', 'updated_at', 'id' ),
					),
					'baseline_run'   => array(
						'unique'  => false,
						'columns' => array( 'baseline_run_id' ),
					),
				),
			),
			'targets'   => array(
				'columns' => array(
					'id'         => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
					'suite_id'   => 'bigint(20) unsigned NOT NULL',
					'object_id'  => 'bigint(20) unsigned NULL',
					'post_type'  => 'varchar(20) NOT NULL',
					'label'      => 'varchar(200) NOT NULL',
					'url'        => 'text NOT NULL',
					'enabled'    => 'tinyint(1) unsigned NOT NULL',
					'sort_order' => 'int(11) unsigned NOT NULL',
					'created_at' => 'datetime NOT NULL',
				),
				'indexes' => array(
					'PRIMARY'     => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'suite_order' => array(
						'unique'  => false,
						'columns' => array( 'suite_id', 'enabled', 'sort_order', 'id' ),
					),
					'object_id'   => array(
						'unique'  => false,
						'columns' => array( 'object_id' ),
					),
				),
			),
			'devices'   => array(
				'columns' => array(
					'id'                  => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
					'name'                => 'varchar(100) NOT NULL',
					'slug'                => 'varchar(50) NOT NULL',
					'viewport_width'      => 'int(11) unsigned NOT NULL',
					'viewport_height'     => 'int(11) unsigned NOT NULL',
					'user_agent'          => 'text NOT NULL',
					'device_scale_factor' => 'double NOT NULL',
					'is_mobile'           => 'tinyint(1) unsigned NOT NULL',
					'has_touch'           => 'tinyint(1) unsigned NOT NULL',
					'enabled'             => 'tinyint(1) unsigned NOT NULL',
					'sort_order'          => 'int(11) unsigned NOT NULL',
				),
				'indexes' => array(
					'PRIMARY'       => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'slug'          => array(
						'unique'  => true,
						'columns' => array( 'slug' ),
					),
					'enabled_order' => array(
						'unique'  => false,
						'columns' => array( 'enabled', 'sort_order', 'id' ),
					),
				),
			),
			'runs'      => array(
				'columns' => array(
					'id'                      => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
					'uuid'                    => 'char(36) NOT NULL',
					'suite_id'                => 'bigint(20) unsigned NOT NULL',
					'reference_run_id'        => 'bigint(20) unsigned NULL',
					'status'                  => 'varchar(20) NOT NULL',
					'triggered_by'            => 'bigint(20) unsigned NOT NULL',
					'runner_execution_id'     => 'varchar(255) NULL',
					'runner_token_hash'       => 'char(64) NULL',
					'runner_token_expires_at' => 'datetime NULL',
					'environment'             => 'longtext NOT NULL',
					'manifest'                => 'longtext NOT NULL',
					'total_snapshots'         => 'int(11) unsigned NOT NULL',
					'completed_snapshots'     => 'int(11) unsigned NOT NULL',
					'error_snapshots'         => 'int(11) unsigned NOT NULL',
					'started_at'              => 'datetime NULL',
					'completed_at'            => 'datetime NULL',
					'created_at'              => 'datetime NOT NULL',
					'updated_at'              => 'datetime NOT NULL',
					'deadline_at'             => 'datetime NOT NULL',
					'error_code'              => 'varchar(64) NULL',
					'error_message'           => 'text NULL',
					'deletion_requested_at'   => 'datetime NULL',
				),
				'indexes' => array(
					'PRIMARY'       => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'uuid'          => array(
						'unique'  => true,
						'columns' => array( 'uuid' ),
					),
					'suite_history' => array(
						'unique'  => false,
						'columns' => array( 'suite_id', 'created_at', 'id' ),
					),
					'suite_status'  => array(
						'unique'  => false,
						'columns' => array( 'suite_id', 'status' ),
					),
					'reference_run' => array(
						'unique'  => false,
						'columns' => array( 'reference_run_id' ),
					),
					'deadline'      => array(
						'unique'  => false,
						'columns' => array( 'status', 'deadline_at' ),
					),
				),
			),
			'snapshots' => array(
				'columns' => array(
					'id'                   => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
					'run_id'               => 'bigint(20) unsigned NOT NULL',
					'target_id'            => 'bigint(20) unsigned NOT NULL',
					'device_id'            => 'bigint(20) unsigned NOT NULL',
					'baseline_snapshot_id' => 'bigint(20) unsigned NULL',
					'status'               => 'varchar(20) NOT NULL',
					'url'                  => 'text NOT NULL',
					'image_path'           => 'text NULL',
					'diff_path'            => 'text NULL',
					'width'                => 'int(11) unsigned NULL',
					'height'               => 'int(11) unsigned NULL',
					'baseline_width'       => 'int(11) unsigned NULL',
					'baseline_height'      => 'int(11) unsigned NULL',
					'dimension_changed'    => 'tinyint(1) unsigned NOT NULL',
					'diff_pixels'          => 'bigint(20) unsigned NULL',
					'total_pixels'         => 'bigint(20) unsigned NULL',
					'diff_ratio'           => 'decimal(12,10) unsigned NULL',
					'http_status'          => 'smallint(5) unsigned NULL',
					'duration_ms'          => 'int(11) unsigned NULL',
					'error_code'           => 'varchar(64) NULL',
					'error_message'        => 'text NULL',
					'metadata'             => 'longtext NOT NULL',
					'created_at'           => 'datetime NOT NULL',
					'updated_at'           => 'datetime NOT NULL',
				),
				'indexes' => array(
					'PRIMARY'           => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'run_target_device' => array(
						'unique'  => true,
						'columns' => array( 'run_id', 'target_id', 'device_id' ),
					),
					'run_status'        => array(
						'unique'  => false,
						'columns' => array( 'run_id', 'status' ),
					),
					'baseline_snapshot' => array(
						'unique'  => false,
						'columns' => array( 'baseline_snapshot_id' ),
					),
				),
			),
		);
	}
}
