# MVP結合検証の着手条件

## 現在の判定

2026年10月8日、Issue #13は前提実装待ち。仕様書 [§37・§44・§57・§68・§74](specification-v1.1.md) と製品コードを照合した。WordPressからRunを開始して比較結果を閲覧する結合テストは、現状では実行できない。既存のRunnerテストの成功をMVP達成と扱わず、未実装機能をskipする結合テストも追加しない。

WordPressの通常初期化で登録するREST APIは公開コンテンツ選択用のみ（`wordpress/od-visual-regression/includes/class-odvr-plugin.php`）。Runnerの入口はローカルManifestとディレクトリを使うCLI（`apps/runner/src/index.ts`）。共通SchemaはDeviceとprototype Manifestのみ。Dispatcher、Docker/compose、Cloud Runの実装は存在しない。設計文書の手順は製品実装の完了を意味しない。

## 不足している前提実装

| 前提 | 必要な動作 | 設計の参照先 |
|---|---|---|
| WordPressのDB・管理API | Suite/Target/Deviceの保存、Run作成時のManifest固定、Baseline指定、Environment履歴、Retention保護 | [データライフサイクル](data-lifecycle-design.md) |
| 共通API・非公開Storage | Run Token、Manifest/Baseline取得、Upload/Progress/Complete、Snapshot UPSERT、権限付き画像取得、public URL拒否 | [API・認証・Storage](api-security-storage-design.md) |
| Dispatcher・Runner Job | HMAC受付、重複起動防止、秘密参照、WordPressへの結果送信、同じRunへのJob retry | [Dispatcher・Cloud Run](dispatcher-cloud-run-design.md) |
| ローカル接続・通信防御 | 固定の開発接続先、emulator/launcher、秘密adapter、本番から開発例外の排除、DNS rebinding防御 | [ネットワーク](network-security-design.md)、[ローカル統合設計](dispatcher-cloud-run-design.md#同一imageによるローカル統合と環境パラメータ) |
| 管理画面 | Tests/Runs/Devices/Settings、Run開始、Baseline操作、認証BlobによるBefore/After/Diff/Overlay | [管理画面](admin-ui-design.md) |

Issue #4・#5・#10・#11は設計への依存であり、これらの設計に基づく上記製品実装が揃った時点で結合テストに着手する。Issue #12のZIP生成では、この不足は解消しない。

## MVP項目と既存検証の対応

「部分」はコンポーネント単独の検証であり、WordPress→Dispatcher→Runner→WordPressの結合を保証しない。テスト名はリポジトリ相対パス。

| §74の項目 | 既存検証 | 結合検証で不足するもの |
|---|---|---|
| 1 Test Suite | なし | Suite保存・編集・Run開始 |
| 2 投稿タイプ取得、3 Page/Post/CPT選択 | 部分：`wordpress/od-visual-regression/tests/content-api.php` | 選択結果のSuite保存・撮影 |
| 4 Custom URL | 部分：同上（形式検証） | URL保存・撮影・失敗の継続 |
| 5 Desktop、6 Tablet、7 Mobile、8 Custom Device | 部分：`apps/runner/tests/browser.test.mjs`（Device設定）、`security.test.mjs`（Manifest検査） | 3プリセット全てとCustom Deviceの保存・Runへの固定・撮影 |
| 9 Cloud Run Dispatcher、10 Cloud Run Runner | なし | 実装、ローカル結合、実機smoke |
| 11 Full Page Screenshot、19 Ignore Selectors | 部分：`apps/runner/tests/browser.test.mjs` | WordPressからの設定反映・画像送信 |
| 12 Baseline | 部分：`apps/runner/tests/baseline.test.mjs` | WordPressでの指定・欠損時処理・保護 |
| 13 Pixel Diff、14 Difference Ratio | 部分：`apps/runner/tests/compare.test.mjs`、`baseline.test.mjs` | 判定と寸法変更の結果送信・保存・表示 |
| 15 Before、16 After、17 Diff、18 Overlay | なし（PNG生成はRunnerで検証） | 認証付き画像取得・Viewer |
| 20 Run History、21 Environment Snapshot | なし（ローカル結果・バージョン照合の検証のみ） | DB履歴、Environment更新と過去Runの固定値 |
| 22 HTTP Basic Auth | 部分：`apps/runner/tests/browser.test.mjs` | WordPress設定からの秘密受渡し・非漏えい |
| 23 Retention | なし | Baseline/実行中Runの保護、削除と画像の整合 |
| 24 SSRF Protection | 部分：`apps/runner/tests/security.test.mjs`、`browser.test.mjs` | DNS rebinding・接続先固定・cloud/local境界 |

## 実装後に通す受け入れシナリオ

固定fixtureではSuiteに公開CPT・Custom URLを登録し、3プリセットとCustom Deviceを使用する。初回撮影→Baseline指定→変更なし/小変更/大変更/高さ変更→比較→認証付きViewerを一連の手順で通す。外部の実サイトや本番の秘密情報に依存しない。

| Issue #13の受け入れ条件 | 必須の検証と証跡 |
|---|---|
| 1手順で主要動作を再現 | wp-env＋local Dispatcher/Runnerの起動、fixture作成、実行、結果assert、終了時cleanupまでの単一入口 |
| 失敗継続・差分・Environment履歴 | UNCHANGED/REVIEW/CHANGED、dimension_changed、欠損Baseline、HTTP失敗と正常対象の混在、Environment更新前後の履歴 |
| 再送の整合 | 同一Upload/Progress/Completeの再送とJob retry後も `(run_id,target_id,device_id)` の件数・集計・終端状態が一致 |
| 認証・秘密・画像・Retention | 失効/別Run Token拒否、管理権限なし拒否、nonce拒否、Basic認証成功/失敗/別Originへの非送信、public画像URL拒否、秘密のログ非漏えい、Baseline/実行中Run保護 |
| Cloud Run smoke・MVP対応表 | 上表の全項目を結合結果に対応付け、実機のExecution・IAM・ネットワーク・秘密cleanupの証跡を保存 |

Cloud Run smokeの実行可能な手順はImage・Job・infra実装後に確定する。必要設定は専用staging project/region、Image digest、Service URL、Job名、runtime SA、Firestore、Run秘密project、VPC/NAT/resolver/firewall、Scheduler、HTTPS WordPress callback、テスト専用秘密参照。local fixtureとクラウド実リソースは別設定にし、通常CIから有料リソースを自動作成しない。

実機では明示したstaging環境へ同じImage digestを配備し、WordPressからRun開始→Execution照合→画像送信→Complete→Viewerを確認する。retryと否定試験の後、Execution終端・Run Secret削除/削除再試行・Token失効を確認する。fixtureのRun/Suite/ユーザー/画像と、その試験で作成したクラウドリソースだけを所有情報に基づいてcleanupする。localでの成功をIAM・Cloud Run実機の成功と扱わない。

## 現状で実行できる検証

```sh
npm test
npm run env:start
npm run env:cli -- eval-file tests/bootstrap.php
npm run env:cli -- eval-file tests/content-api.php
npm run env:stop
```

これは既存コンポーネントの検証手順。WordPress検証はDockerと開発専用の単一サイトが必要で、結合テストの入口ではない。CIは既存RunnerテストとWordPressの初期化/公開コンテンツAPIを実行する。Issue #13の結合テストジョブ・Cloud Run smokeは未追加。

2026年10月8日の確認では、`npm test` の型検査・ビルドと既存45テストが成功（失敗0、skip0）。macOSの実行制限内ではChromium起動とfixtureサーバーのlistenが拒否されたため、実行制限外で再確認した。今回の変更は文書のみで、wp-env実機・GitHub Actions・Cloud Runは実行していない。この結果はIssue #13の受け入れ条件の達成を示さない。
