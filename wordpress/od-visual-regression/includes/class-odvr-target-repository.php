<?php
/**
 * Suite配下のTarget保存と論理削除。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Suite行ロックで上限検査と編集を直列化する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Targetを別Suiteへ移動せず、履歴の参照元を保持する。 */
final class ODVR_Target_Repository extends ODVR_Repository {
	/**
	 * 所属Suiteも一致するTargetを取得する。
	 *
	 * @param int $suite_id Suite ID.
	 * @param int $id Target ID.
	 * @return array|WP_Error Target.
	 */
	public function get( $suite_id, $id ) {
		return $this->read(
			function () use ( $suite_id, $id ) {
				$this->row( 'suites', $suite_id );
				return $this->item( $this->target_row( $suite_id, $id ) );
			}
		);
	}

	/**
	 * 対象をページ取得する。
	 *
	 * @param int  $suite_id Suite ID.
	 * @param int  $page ページ.
	 * @param int  $per_page 件数.
	 * @param bool $include_disabled 論理削除を含めるか.
	 * @return array|WP_Error items / total.
	 */
	public function list_items( $suite_id, $page = 1, $per_page = 20, $include_disabled = false ) {
		global $wpdb;
		return $this->read(
			function () use ( $suite_id, $page, $per_page, $include_disabled, $wpdb ) {
				$this->row( 'suites', $suite_id );
				$offset = $this->offset( $page, $per_page );
				if ( ! is_bool( $include_disabled ) ) {
					$this->fail( 'odvr_invalid_filter' );
				}
				$where = $include_disabled ? '' : ' AND enabled = 1';
				$rows  = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d', ODVR_DB::table( 'targets' ), $suite_id ) . $where . $wpdb->prepare( ' ORDER BY id LIMIT %d OFFSET %d', $per_page, $offset ) );
				$total = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i WHERE suite_id = %d', ODVR_DB::table( 'targets' ), $suite_id ) . $where );
				return array(
					'items' => array_map( array( $this, 'item' ), $rows ),
					'total' => $this->integer( $total[0]['total'] ),
				);
			}
		);
	}

	/**
	 * 公開投稿またはCustom URLを保存する。
	 *
	 * @param int      $suite_id Suite ID.
	 * @param stdClass $input 作成契約.
	 * @return array|WP_Error Target.
	 */
	public function create( $suite_id, $input ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $suite_id, $input, $wpdb ) {
				$this->validate( 'target-create-request', $input );
				$this->active_suite( $suite_id );
				$this->capacity( $suite_id, $input->enabled );
				$data               = $this->data( $input );
				$data['suite_id']   = $this->id( $suite_id );
				$data['created_at'] = ODVR_DB::utc_now();
				$this->written( $wpdb->insert( ODVR_DB::table( 'targets' ), $data, array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ) ) );
				return $this->item( $this->target_row( $suite_id, $this->integer( (string) $wpdb->insert_id, true ) ) );
			}
		);
	}

	/**
	 * Suite→Targetの順にロックして部分更新する。
	 *
	 * @param int      $suite_id Suite ID.
	 * @param int      $id Target ID.
	 * @param stdClass $input PATCH契約.
	 * @return array|WP_Error Target.
	 */
	public function update( $suite_id, $id, $input ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $suite_id, $id, $input, $wpdb ) {
				$this->validate( 'target-patch-request', $input );
				$this->active_suite( $suite_id );
				$row     = $this->target_row( $suite_id, $id, true );
				$current = $this->item( $row );
				unset( $current['id'], $current['suite_id'] );
				$merged = (object) array_merge( $current, array( 'schema_version' => 1 ), (array) $input );
				$this->validate( 'target-create-request', $merged );
				if ( ! $this->boolean( $row['enabled'] ) && $merged->enabled ) {
					$this->fail( 'odvr_target_disabled', 409 );
				}
				// 削除は投稿が消失・非公開でも可能にする.
				$data = $this->data( $merged, $merged->enabled );
				$this->written(
					$wpdb->update(
						ODVR_DB::table( 'targets' ),
						$data,
						array(
							'id'       => $id,
							'suite_id' => $suite_id,
						),
						array( '%s', '%s', '%d', '%s', '%d', '%d' ),
						array( '%d', '%d' )
					)
				);
				return $this->item( $this->target_row( $suite_id, $id ) );
			}
		);
	}

	/**
	 * Targetを無効化する。
	 *
	 * @param int $suite_id Suite ID.
	 * @param int $id Target ID.
	 * @return array|WP_Error Target.
	 */
	public function disable( $suite_id, $id ) {
		return $this->update(
			$suite_id,
			$id,
			(object) array(
				'schema_version' => 1,
				'enabled'        => false,
			)
		);
	}

	/**
	 * 所属Suiteをロックする。
	 *
	 * @param int $suite_id ID.
	 * @return void
	 */
	private function active_suite( $suite_id ) {
		$row = $this->row( 'suites', $suite_id, true );
		$this->suite_configuration( $row );
		if ( 'active' !== $row['status'] ) {
			$this->fail( 'odvr_suite_archived', 409 );
		}
	}

	/**
	 * 有効Target上限を同じSuiteロック下で検査する。
	 *
	 * @param int  $suite_id ID.
	 * @param bool $enabled 新規有効行か.
	 * @return void
	 */
	private function capacity( $suite_id, $enabled ) {
		global $wpdb;
		$count = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i WHERE suite_id = %d AND enabled = 1', ODVR_DB::table( 'targets' ), $suite_id ) );
		if ( $enabled && $this->integer( $count[0]['total'] ) >= 100 ) {
			$this->fail( 'odvr_target_limit', 409 );
		}
	}

	/**
	 * 対象IDとSuite IDを必ず合わせて検索する。
	 *
	 * @param int  $suite_id Suite ID.
	 * @param int  $id Target ID.
	 * @param bool $lock ロックするか.
	 * @return array 行.
	 */
	private function target_row( $suite_id, $id, $lock = false ) {
		global $wpdb;
		$rows = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE suite_id = %d AND id = %d', ODVR_DB::table( 'targets' ), $this->id( $suite_id ), $this->id( $id ) ) . ( $lock ? ' FOR UPDATE' : '' ) );
		if ( ! $rows ) {
			$this->fail( 'odvr_not_found', 404 );
		}
		return $rows[0];
	}

	/**
	 * DB値を型付きの表示契約へ変換する。
	 *
	 * @param array $row 行.
	 * @return array Target.
	 */
	private function item( $row ) {
		$item = array(
			'id'         => $this->integer( $row['id'], true ),
			'suite_id'   => $this->integer( $row['suite_id'], true ),
			'url'        => $row['url'],
			'label'      => $row['label'],
			'object_id'  => null === $row['object_id'] ? null : $this->integer( $row['object_id'], true ),
			'post_type'  => $row['post_type'],
			'enabled'    => $this->boolean( $row['enabled'] ),
			'sort_order' => $this->integer( $row['sort_order'] ),
		);
		$this->validate(
			'target-response',
			(object) array(
				'schema_version' => 1,
				'item'           => (object) $item,
			)
		);
		return $item;
	}

	/**
	 * 投稿URLを保存時に解決し、固定列を作る。
	 *
	 * @param stdClass $input 契約.
	 * @param bool     $resolve_post 公開状態とURLを再確認するか.
	 * @return array SQL値.
	 */
	private function data( $input, $resolve_post = true ) {
		$object_id = null === $input->object_id ? null : $this->id( $input->object_id );
		$url       = $resolve_post ? $this->checked( ODVR_Target_URL::resolve( $input->url, $object_id, $input->post_type ) ) : $this->checked( ODVR_Target_URL::validate( $input->url ) );
		return array(
			'url'        => $url,
			'label'      => $input->label,
			'object_id'  => $input->object_id,
			'post_type'  => $input->post_type,
			'enabled'    => (int) $input->enabled,
			'sort_order' => $input->sort_order,
		);
	}
}
