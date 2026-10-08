<?php
/**
 * 秘密を含まない環境の固定と、許可されたVersion/完了情報の補完。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 環境値は開始後に再取得しない。 */
final class ODVR_Environment {
	/**
	 * WordPress/PHP・テーマ・有効Plugin/MU・Locale/Site URLだけを取得する。
	 *
	 * @return stdClass|WP_Error 固定環境.
	 */
	public static function capture() {
		global $wp_version;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$registry = get_plugins();
		$active   = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return new WP_Error( 'odvr_invalid_environment', __( '有効Pluginの環境を確認できません。', 'od-visual-regression' ), array( 'status' => 503 ) );
		}
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_unique( $active );
		sort( $active, SORT_STRING );
		$plugins = array();
		foreach ( $active as $id ) {
			$data      = $registry[ $id ] ?? array();
			$plugins[] = self::plugin( $id, $data );
		}
		$mu_plugins = array();
		$mu         = get_mu_plugins();
		ksort( $mu, SORT_STRING );
		foreach ( $mu as $id => $data ) {
			$mu_plugins[] = self::plugin( $id, $data );
		}
		$theme       = wp_get_theme();
		$parent      = $theme->parent();
		$environment = (object) array(
			'environment_version' => 1,
			'wordpress'           => $wp_version,
			'php'                 => PHP_VERSION,
			'theme'               => (object) array(
				'name'    => $theme->get_stylesheet(),
				'version' => '' === $theme->get( 'Version' ) ? null : $theme->get( 'Version' ),
			),
			'parent_theme'        => $parent ? (object) array(
				'name'    => $parent->get_stylesheet(),
				'version' => '' === $parent->get( 'Version' ) ? null : $parent->get( 'Version' ),
			) : null,
			'plugins'             => $plugins,
			'mu_plugins'          => $mu_plugins,
			'locale'              => get_locale(),
			'site_url'            => home_url( '/' ),
			'runner'              => null,
			'playwright'          => null,
			'chromium'            => null,
		);
		$validated   = ( new ODVR_Contract_Validator() )->validate( 'run-environment', $environment );
		return is_wp_error( $validated ) ? $validated : $environment;
	}

	/**
	 * Plugin headerから表示用の3項目だけを選ぶ。
	 *
	 * @param string $id 相対識別子.
	 * @param array  $data WordPressのheader.
	 * @return stdClass 固定Plugin情報.
	 */
	private static function plugin( $id, $data ) {
		return (object) array(
			'id'      => $id,
			'name'    => empty( $data['Name'] ) ? $id : wp_strip_all_tags( $data['Name'] ),
			'version' => empty( $data['Version'] ) ? null : $data['Version'],
		);
	}

	/**
	 * 保存環境を読み、未知Version/項目・破損JSONを拒否する。
	 *
	 * @param string $json 保存JSON.
	 * @return stdClass|WP_Error 環境.
	 */
	public static function decode( $json ) {
		$value     = json_decode( $json );
		$validator = new ODVR_Contract_Validator();
		if ( ! $value instanceof stdClass ) {
			return new WP_Error( 'odvr_invalid_stored_data', __( '保存環境が破損しています。', 'od-visual-regression' ), array( 'status' => 503 ) );
		}
		$version = $validator->validate_stored_version( 'environment', $value->environment_version ?? null );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		$valid = $validator->validate( 'stored-run-environment', $value );
		return is_wp_error( $valid ) ? $valid : $value;
	}

	/**
	 * 内部のcompletionを除き、管理API用の固定環境を返す。
	 *
	 * @param stdClass $value 保存環境.
	 * @return stdClass 表示用.
	 */
	public static function visible( $value ) {
		$copy = clone $value;
		unset( $copy->completion );
		return $copy;
	}

	/**
	 * 初回は3Versionを同時補完し、以後は完全一致以外を拒否する。
	 *
	 * @param stdClass $environment 保存環境.
	 * @param stdClass $versions 検証済みの3Version.
	 * @return stdClass|WP_Error 更新環境.
	 */
	public static function versions( $environment, $versions ) {
		$copy = clone $environment;
		foreach ( array( 'runner', 'playwright', 'chromium' ) as $key ) {
			if ( null !== $copy->$key && $copy->$key !== $versions->$key ) {
				return new WP_Error( 'odvr_version_conflict', __( '実行環境のVersionが一致しません。', 'od-visual-regression' ), array( 'status' => 409 ) );
			}
			$copy->$key = $versions->$key;
		}
		$valid = ( new ODVR_Contract_Validator() )->validate( 'stored-run-environment', $copy );
		return is_wp_error( $valid ) ? $valid : $copy;
	}

	/**
	 * 順序に依存しない内部digestを作る。
	 *
	 * @param mixed $value 検証済みJSON値.
	 * @return string SHA-256.
	 */
	public static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::ordered( $value ) ) );
	}

	/**
	 * JSON objectのキーだけをソートする。配列順序は保持する。
	 *
	 * @param mixed $value JSON値.
	 * @return mixed 正規化した値.
	 */
	private static function ordered( $value ) {
		if ( $value instanceof stdClass ) {
			$fields = get_object_vars( $value );
			ksort( $fields, SORT_STRING );
			return (object) array_map( array( __CLASS__, 'ordered' ), $fields );
		}
		return is_array( $value ) ? array_map( array( __CLASS__, 'ordered' ), $value ) : $value;
	}
}
