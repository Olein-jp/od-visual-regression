# OD Visual Regression

WordPress更新前後の画面差分を検出するシステムです。添付仕様書の開発順序に沿って、Runnerの撮影・画像比較を実装しています。WordPressの管理画面・保存機能とCloud Run連携は未実装です。

## 構成

- `apps/runner`：Playwright・Chromiumによるページ撮影とpixelmatchによる比較
- `packages/shared`：Device型、プリセット、共通設定、差分判定
- `packages/schemas`：Device・プロトタイプManifest・製品APIのJSON SchemaとNode検証器
- `wordpress/od-visual-regression`：WordPressプラグインのひな形
- `docs`：仕様書、開発手順、セキュリティ上の制限、実装状況

## 開始

```sh
npm ci
npx playwright install chromium
npm run build
mkdir -p artifacts
npm run runner -- docs/prototype-manifest.example.json artifacts/before
npm run runner -- docs/prototype-manifest.example.json artifacts/after artifacts/before
```

撮影URLとリソースのOriginをManifestで指定します。内部IPへのアクセスは拒否するため、現在はlocalhost上のWordPressを撮影できません。

製品APIの共通契約・検証手順は [API契約](docs/api-contracts.md) を参照してください。

詳細は [開発手順](docs/development.md)、[実装状況](docs/implementation-status.md)、[セキュリティ](docs/security.md)、[仕様書 v1.1](docs/specification-v1.1.md) を参照してください。

## WordPress プラグインの配布

Python 3.9 以上を用意し、`npm run build:plugin` で `build/od-visual-regression.zip` を生成します。ZIP のルートは `od-visual-regression/` で、WordPress のプラグインアップロードからインストールできます。現在は PHP・翻訳を配布する開発途中のプラグインで、管理画面は未実装です。

配布対象はエントリーポイント、`includes/`・`rest/`・`admin/` の実行時 PHP、`assets/`、`admin/build/`、翻訳、配布用 readme・ライセンスです。開発用ディレクトリ、隠しファイル、認証情報の候補、ソースマップは除外します。秘密情報は実行時ファイルへ書き込まないでください。

管理画面を実装するときは `admin/src/` または `admin/package.json` を追加し、ビルド後に ZIP を生成してください。`admin/build/` に JS と同名の `.asset.php` が揃っていない場合は失敗します。追加の実行時ファイル形式やビルド方式を採用するときは、配布スクリプトの対象・検査も更新してください。CLI 引数や入力の検査、ZIP 生成に失敗しても既存 ZIP は置き換えません。

`npm run build:plugin -- --tag plugin-v0.1.0` でタグとプラグインヘッダーの Version を照合できます。Runner の Version は照合対象にしません。`npm run test:plugin-build` で配布対象と失敗時の保護を検証します。

GitHub Actions の **Plugin Build** は WordPress・配布処理の変更、`plugin-v*` タグ、手動実行で動作し、ZIP を `od-visual-regression-plugin` Artifact として保存します。管理画面実装時には、そのビルド手順をこの Workflow の ZIP 生成前に追加してください。公開 Release は作成しません。

## WordPress開発環境

PHP 7.4以上・Composer 2とDockerを使用します。初期対応範囲はWordPress 6.7以上・PHP 7.4以上です。

```sh
composer install
npm run env:start
npm run env:cli -- plugin list
composer lint
```

サイトは通常 `http://localhost:8888`、管理画面は `http://localhost:8888/wp-admin/` です。開発用ログインは `admin` / `password` です。ポート等はGit管理対象外の `.wp-env.override.json` で変更できます。翻訳手順は [languagesの説明](wordpress/od-visual-regression/languages/README.md) を参照してください。

既存のwp-env依存パッケージにはNode.js 24.18以上・npm 11.16以上を要求するものがあり、古い環境では警告が出ます。Runnerの最低要件はNode.js 20です。2026年10月8日のnpm依存検査では、既存開発ツールの依存関係に9件（critical 3件・moderate 6件）の指摘があります。
