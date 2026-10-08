# 製品APIの共通契約（Schema v1）

Issue #26で、[API設計](api-security-storage-design.md)と[管理画面設計](admin-ui-design.md)の通信契約を実装した。Schemaの正本は `packages/schemas/src/*.schema.json`、共通型は `packages/shared/src/contracts.ts`。既存の `PrototypeManifest` / `prototype-manifest.schema.json` はローカル撮影用として維持する。

## 契約と入口

- Runner用：Run Manifest、Snapshot結果、Progress、Complete、Credentials、Run State、Snapshot Upload応答。
- Dispatcher用：Dispatch要求・応答と署名済み接続診断要求。
- 管理用：Suite / Target / Deviceの作成・部分更新・単一/一覧応答、Run作成・単一/一覧応答、Snapshot単一/一覧応答、Baseline要求、Settings部分更新・応答、接続診断要求・応答。
- ODVRエラー：code / message / data。dataはstatus / retryable / request_id、Device参照競合時には `using_suites` を追加できる。

新規JSONは `schema_version: 1` 必須、objectは未知キーを拒否する。PATCHもVersion以外に少なくとも1項目が必要。秘密の書込用フィールドはSettings契約に含めない。接続診断は保存済み設定だけを使用するため、管理要求はVersionのみ。Dispatcher診断要求はVersion / site_id / callback_baseのみ。

Nodeの入口は `packages/schemas/validate.mjs` の `validateContract(name, value, context?)`。Ajv + ajv-formatsによるdraft-07検証後、閾値、URL、参照の組み合わせ、状態、集計、比率等を検査する。エラーは定型codeだけを返し、入力値を例外へ埋め込まない。

PHPの入口は `ODVR_Contract_Validator::validate()`。JSONは `json_decode($raw)` でobjectをstdClassのまま渡し、空objectと配列を区別する。数値文字列とboolean文字列は拒否する。JSONの `1` と `1.0` は同じ整数値として扱う。WordPressの基本検証に加え、使用keywordだけを再帰評価し、未対応keyword / formatは初期化時に拒否する。失敗はWP_Error、成功はtrue。APIの認証・状態変更をこの検証器だけで実装済みとは扱わない。

`context.settings` には固定したreview_threshold / changed_threshold、`context.reference_run_id` には固定した参照Run IDまたはnullを渡す。Snapshot受理時は両方を必須とする。PNGの実寸・digest・固定Baselineの可読性・Manifest内の組み合わせ・DB状態の確認は後続のStorage / Runner APIで行う。

## 管理応答の表示フィールド

画面が推測せずに判断できるよう、以下を定義した。

| 応答 | 追加表示値 |
|---|---|
| Suite | `target_count` / `device_count` / `latest_run` / `baseline_run_id`、設定・選択Device・許可Origin・Retention |
| Device | `enabled` / `sort_order` / `using_suites`（id / name） |
| Run | 固定したsuite_name / targets / devices / settings、開始時environment / reference_environment、集計、deadline、error、`protection.reasons` / `protection.referenced_by`、`deletion_error` |
| Snapshot | `has_current_image` / `has_diff_image` / `has_baseline_image`。パスや公開URLは返さない。PENDINGは寸法・比較・エラーがnull、duration_ms=0 |
| Settings | `retained_runs` / `storage_bytes`（診断不能ならnull）/ `deletion_failures`、秘密設定の有無とHTTP BasicのOrigin |
| 接続診断 | `item.checks` のsettings / storage / dispatcher（passed / failed）、checked_at / code / message |

Environmentの表示契約はenvironment_version / wordpress / php / theme / parent_theme / plugins / mu_plugins / locale / site_url / runner / playwright / chromium。プラグイン識別子はid、テーマはnameで識別する。Version不明・Runner補完前はnull。秘密や内部的なComplete digestは管理応答へ含めない。

Retentionは `{mode:"all",count:null}` または `{mode:"last",count:N}`（正整数）。保護理由はpinned / active / referenced。自動保持の最新N件・最新complete等と手動削除保護を混同しない。件数はAPIのJSON整数として扱い、IDは1〜2147483647の範囲に制限する。

## Versionの区別

| Version | 初期値・扱い |
|---|---|
| 通信ルート `/odvr/v1` / Dispatcher `/v1` | ルート・認証方式のmajor |
| schema_version | 1。未知値はodvr_unsupported_schema_version |
| settings_version / environment_version / metadata_version | 各保存JSONの形。初期値1。設定・環境の未知値は検証拒否し、保存処理で書込停止を診断する |
| odvr_db_version | DBの構造。初期値1。導入・更新は#27 |
| Plugin / Runner / Dispatcher / Playwright / Chromium | ソフトウェアの識別子。Schema互換性の判定には代用しない |

これらの定数はPHP検証器とsharedにそれぞれ公開する。保存JSONの入口では `validateStoredVersion()` / `validate_stored_version()` でsettings / environment / metadataを個別に検証する。DB更新やSnapshot metadata保存は後続Issueの責務。

## multipartと配布

`convertMultipart()` / `convert_multipart()` は、重複を維持できる順序付き `[name,value]` のscalar部品リストを受け取る。整数は符号・先頭ゼロなし、booleanはtrue / false、ratioは0〜1・小数15桁まで、nullableだけ文字列nullを受け付ける。未知部品・重複・配列記法・部分parse・指数形式を拒否する。変換後にsnapshot-resultの契約検証を行う。

PHPの自動フォーム展開後の連想配列では重複検証できない。raw multipart parser、file部品、bodyサイズ制限は#33で実装する。このscalar変換器をraw parserの代用にしない。

配布ZIPは正本Schemaを `od-visual-regression/schemas/` へ直接同梱する。`runner-credentials.schema.json` は秘密情報を持たない契約定義として含む。開発用コピーは `npm run env:start` の前処理で生成し、Git管理しない。正本にSchemaを追加・変更した場合、稼働中のwp-envへは `node scripts/sync-schemas.mjs` で反映する。

共通fixtureの正常例・不正例は `wordpress/od-visual-regression/tests/contract-fixtures.json`、ネストの必須/型/境界例を加えた生成JSONは `npm run test:contracts` で作成する。NodeとPHPは同じ生成JSONで判定する。

```sh
npm run schemas:validate
npm run test:contracts
npm run env:start
npm run env:cli -- eval-file tests/contracts.php
npm run build
composer lint
npm run test:plugin-build
```

CIではWordPress既定版と6.7、PHP 7.4で実行する。製品のDB/API/Storage/Dispatcherへの接続は後続Issueで検証する。
