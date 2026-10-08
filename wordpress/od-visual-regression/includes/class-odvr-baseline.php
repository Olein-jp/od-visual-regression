<?php
/**
 * 固定された撮影条件とVersionの比較。閾値・寸法変化は互換性を壊さない。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runnerと同じ条件だけで互換性を決める。 */
final class ODVR_Baseline {
	/**
	 * Target×Deviceの撮影条件が同じかを検査する。
	 *
	 * @param stdClass $previous 過去の固定Manifest.
	 * @param stdClass $current 今回の固定Manifest.
	 * @param int      $target_id Target ID.
	 * @param int      $device_id Device ID.
	 * @return bool 撮影互換.
	 */
	public static function conditions( $previous, $current, $target_id, $device_id ) {
		$old_target = self::find( $previous->targets, $target_id );
		$new_target = self::find( $current->targets, $target_id );
		$old_device = self::find( $previous->devices, $device_id );
		$new_device = self::find( $current->devices, $device_id );
		if ( ! $old_target || ! $new_target || ! $old_device || ! $new_device || $old_target->url !== $new_target->url ) {
			return false;
		}
		foreach ( array( 'viewport_width', 'viewport_height', 'user_agent', 'is_mobile', 'has_touch' ) as $key ) {
			if ( ( $old_device->$key ?? null ) !== ( $new_device->$key ?? null ) ) {
				return false;
			}
		}
		if ( (float) $old_device->device_scale_factor !== (float) $new_device->device_scale_factor ) {
			return false;
		}
		foreach ( array( 'settings_version', 'navigation_timeout_ms', 'image_timeout_ms', 'lazy_load' ) as $key ) {
			if ( $previous->settings->$key !== $current->settings->$key ) {
				return false;
			}
		}
		return self::set( $previous->settings->ignore_selectors ) === self::set( $current->settings->ignore_selectors ) && self::set( $previous->allowed_origins ) === self::set( $current->allowed_origins );
	}

	/**
	 * 全3Versionが既知かつ同じ場合だけ互換とする。
	 *
	 * @param stdClass|null $previous 参照Runの3Version.
	 * @param stdClass      $current 今回のVersionまたは補完済み環境.
	 * @return bool Version互換.
	 */
	public static function versions( $previous, $current ) {
		if ( ! $previous instanceof stdClass ) {
			return false;
		}
		foreach ( array( 'runner', 'playwright', 'chromium' ) as $key ) {
			if ( ! isset( $previous->$key, $current->$key ) || $previous->$key !== $current->$key ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * IDで固定情報を取り出す。
	 *
	 * @param array $items 固定情報.
	 * @param int   $id ID.
	 * @return stdClass|null 情報.
	 */
	public static function find( $items, $id ) {
		foreach ( $items as $item ) {
			if ( $id === $item->id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Origin/Maskの順序と重複を正規化する。
	 *
	 * @param array $values 文字列.
	 * @return array 集合.
	 */
	private static function set( $values ) {
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}
}
