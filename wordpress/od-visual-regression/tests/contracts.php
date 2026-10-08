<?php
/**
 * Nodeと同じfixtureをWordPress/PHP 7.4で検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

/**
 * 契約・厳格変換・未対応keywordの拒否を検証する。
 *
 * @return void
 * @throws RuntimeException 判定が一致しない場合.
 */
function odvr_test_contracts() {
	$validator = new ODVR_Contract_Validator();
	// 固定fixtureの読取。秘密情報や利用者指定のパスは使わない.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$fixtures = json_decode( file_get_contents( __DIR__ . '/contract-fixtures.generated.json' ) );
	if ( ! is_array( $fixtures ) ) {
		throw new RuntimeException( '共通fixtureを読み込めません。' );
	}
	foreach ( $fixtures as $fixture ) {
		$value  = $fixture->multipart ? $validator->convert_multipart( $fixture->value ) : $fixture->value;
		$result = is_wp_error( $value ) ? $value : $validator->validate( $fixture->schema, $value, (array) $fixture->context );
		if ( ( true === $result ) !== $fixture->valid ) {
			throw new RuntimeException( esc_html( '判定不一致: ' . $fixture->name ) );
		}
	}
	foreach ( array( 'settings', 'environment', 'metadata' ) as $kind ) {
		if ( true !== $validator->validate_stored_version( $kind, 1 ) ) {
			throw new RuntimeException( '保存Version 1を受理できません。' );
		}
		foreach ( array( 2, 0, '1', null, true ) as $version ) {
			if ( ! is_wp_error( $validator->validate_stored_version( $kind, $version ) ) ) {
				throw new RuntimeException( '未知保存Versionを拒否できません。' );
			}
		}
	}
	$unknown = $validator->validate( 'unknown', new stdClass() );
	if ( ! is_wp_error( $unknown ) || 'odvr_unknown_contract' !== $unknown->get_error_code() ) {
		throw new RuntimeException( '未知契約を拒否できません。' );
	}
	// 一時Schemaで未対応keywordを初期化時に拒否することを確認する.
	$directory = get_temp_dir() . 'odvr-contract-' . wp_generate_uuid4();
	wp_mkdir_p( $directory );
	$file = $directory . '/unsupported.schema.json';
	try {
		foreach ( array( array( 'oneOf' => array() ), array( 'format' => 'email' ), array( 'properties' => array( 'nested' => array( 'patternProperties' => array() ) ) ) ) as $extra ) {
			$schema = array_merge(
				array(
					'$schema' => 'http://json-schema.org/draft-07/schema#',
					'type'    => 'object',
				),
				$extra
			);
			// テスト用の固定SchemaだけをOS一時領域へ置く.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, wp_json_encode( $schema ) );
			$rejected = false;
			try {
				new ODVR_Contract_Validator( $directory );
			} catch ( RuntimeException $error ) {
				$rejected = 'odvr_unsupported_schema_keyword' === $error->getMessage();
			}
			if ( ! $rejected ) {
				throw new RuntimeException( '未対応keywordを初期化で拒否できません。' );
			}
		}
	} finally {
		wp_delete_file( $file );
		// 空のテストディレクトリだけを削除する.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $directory );
	}
	WP_CLI::success( count( $fixtures ) . '件の共通契約fixtureと未対応keywordの拒否を確認しました。' );
}

try {
	odvr_test_contracts();
} catch ( Throwable $odvr_test_error ) {
	WP_CLI::error( $odvr_test_error->getMessage() );
}
