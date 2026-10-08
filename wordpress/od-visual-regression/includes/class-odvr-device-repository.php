<?php
/**
 * Deviceの保存とSuite参照の保護。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// 参照はREAD COMMITTEDの最新読取で検査する.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Device slugと過去の参照元IDを保持する。 */
final class ODVR_Device_Repository extends ODVR_Repository {
	/**
	 * Deviceを取得する。
	 *
	 * @param int $id ID.
	 * @return array|WP_Error Device.
	 */
	public function get( $id ) {
		return $this->read(
			function () use ( $id ) {
				return $this->item( $this->row( 'devices', $id ) );
			}
		);
	}

	/**
	 * Device一覧を取得する。
	 *
	 * @param int $page ページ.
	 * @param int $per_page 件数.
	 * @return array|WP_Error items / total.
	 */
	public function list_items( $page = 1, $per_page = 20 ) {
		global $wpdb;
		return $this->read(
			function () use ( $page, $per_page, $wpdb ) {
				$offset = $this->offset( $page, $per_page );
				$rows   = $this->rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id LIMIT %d OFFSET %d', ODVR_DB::table( 'devices' ), $per_page, $offset ) );
				$total  = $this->rows( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i', ODVR_DB::table( 'devices' ) ) );
				return array(
					'items' => array_map( array( $this, 'item' ), $rows ),
					'total' => $this->integer( $total[0]['total'] ),
				);
			}
		);
	}

	/**
	 * 新しいDeviceを保存する。
	 *
	 * @param stdClass $input 作成契約.
	 * @return array|WP_Error Device.
	 */
	public function create( $input ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $input, $wpdb ) {
				$this->validate( 'device-create-request', $input );
				$data   = $this->data( $input );
				$result = $wpdb->insert( ODVR_DB::table( 'devices' ), $data, $this->formats() );
				if ( false === $result && false !== stripos( $wpdb->last_error, 'Duplicate entry' ) ) {
					$this->fail( 'odvr_slug_conflict', 409 );
				}
				$this->written( $result );
				return $this->item( $this->row( 'devices', $this->integer( (string) $wpdb->insert_id, true ) ) );
			}
		);
	}

	/**
	 * Device行だけをロックして更新する。
	 *
	 * @param int      $id ID.
	 * @param stdClass $input PATCH契約.
	 * @return array|WP_Error Device.
	 */
	public function update( $id, $input ) {
		global $wpdb;
		return $this->transaction(
			function () use ( $id, $input, $wpdb ) {
				$this->validate( 'device-patch-request', $input );
				$row     = $this->row( 'devices', $id, true );
				$profile = $this->profile( $row );
				if ( isset( $input->slug ) && $input->slug !== $profile['slug'] ) {
					$this->fail( 'odvr_slug_immutable', 409 );
				}
				$merged = (object) array_merge( $profile, (array) $input );
				$this->validate( 'device-create-request', $merged );
				if ( ! $merged->enabled ) {
					// Suite行をロックしない。Suite→Deviceとの逆順ロックを避ける.
					$using = $this->using_suites( $id );
					if ( $using ) {
						$this->fail( 'odvr_device_in_use', 409, array( 'using_suites' => $using ) );
					}
				}
				$this->written( $wpdb->update( ODVR_DB::table( 'devices' ), $this->data( $merged ), array( 'id' => $id ), $this->formats(), array( '%d' ) ) );
				return $this->item( $this->row( 'devices', $id ) );
			}
		);
	}

	/**
	 * Deviceを論理削除する。
	 *
	 * @param int $id ID.
	 * @return array|WP_Error Device.
	 */
	public function disable( $id ) {
		return $this->update(
			$id,
			(object) array(
				'schema_version' => 1,
				'enabled'        => false,
			)
		);
	}

	/**
	 * Suite側のロック済みDeviceも同じ検証を使う。
	 *
	 * @param array                   $row DB行.
	 * @param ODVR_Contract_Validator $validator 検証器.
	 * @return void
	 */
	public static function validate_row( $row, $validator ) {
		$repository = new self();
		$repository->checked( $validator->validate( 'device-create-request', (object) $repository->profile( $row ) ) );
	}

	/**
	 * 参照する全Suiteを取得する。archive済みも参照を保持する。
	 *
	 * @param int $id Device ID.
	 * @return array 利用Suite.
	 */
	private function using_suites( $id ) {
		global $wpdb;
		$using = array();
		$rows  = $this->rows( $wpdb->prepare( 'SELECT id, name, settings FROM %i ORDER BY id', ODVR_DB::table( 'suites' ) ) );
		foreach ( $rows as $row ) {
			$config = $this->suite_configuration( $row );
			if ( in_array( $this->id( $id ), array_map( 'intval', $config->device_ids ), true ) ) {
				$using[] = (object) array(
					'id'   => $this->integer( $row['id'], true ),
					'name' => $row['name'],
				);
			}
		}
		return $using;
	}

	/**
	 * DB値から完全な作成契約へ変換する。
	 *
	 * @param array $row 行.
	 * @return array Profile.
	 */
	private function profile( $row ) {
		return array(
			'schema_version'      => 1,
			'name'                => $row['name'],
			'slug'                => $row['slug'],
			'viewport_width'      => $this->integer( $row['viewport_width'], true ),
			'viewport_height'     => $this->integer( $row['viewport_height'], true ),
			'device_scale_factor' => (float) $row['device_scale_factor'],
			'is_mobile'           => $this->boolean( $row['is_mobile'] ),
			'has_touch'           => $this->boolean( $row['has_touch'] ),
			'user_agent'          => $row['user_agent'],
			'enabled'             => $this->boolean( $row['enabled'] ),
			'sort_order'          => $this->integer( $row['sort_order'] ),
		);
	}

	/**
	 * 表示項目へ変換する。
	 *
	 * @param array $row 行.
	 * @return array Device.
	 */
	private function item( $row ) {
		$item = $this->profile( $row );
		unset( $item['schema_version'] );
		$item['id']           = $this->integer( $row['id'], true );
		$item['using_suites'] = $this->using_suites( $item['id'] );
		$this->validate(
			'device-response',
			(object) array(
				'schema_version' => 1,
				'item'           => (object) $item,
			)
		);
		return $item;
	}

	/**
	 * SQLに渡す列だけを作る。
	 *
	 * @param stdClass $input 契約.
	 * @return array SQL値.
	 */
	private function data( $input ) {
		$data = (array) $input;
		unset( $data['schema_version'] );
		foreach ( array( 'is_mobile', 'has_touch', 'enabled' ) as $key ) {
			$data[ $key ] = (int) $data[ $key ];
		}
		// INSERT/UPDATEに渡す順序をformatsと一致させる.
		$keys = array( 'name', 'slug', 'viewport_width', 'viewport_height', 'device_scale_factor', 'is_mobile', 'has_touch', 'user_agent', 'enabled', 'sort_order' );
		return array_replace( array_fill_keys( $keys, null ), $data );
	}

	/**
	 * 固定列のSQL型を返す。
	 *
	 * @return array 型.
	 */
	private function formats() {
		return array( '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%d', '%d' );
	}
}
