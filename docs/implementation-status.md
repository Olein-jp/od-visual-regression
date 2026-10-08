# 実装状況

正本の仕様書は [specification-v1.1.md](specification-v1.1.md) です。

| 開発段階 | 状況 |
|---|---|
| Phase 1 Runner Prototype | URL・DeviceごとのChromium全ページ撮影、ローカルPNG出力を実装 |
| Phase 2 Visual Diff | pixelmatch、寸法正規化、差分率、判定、差分PNGを実装 |
| Phase 3 WordPress Plugin Core | 初期化・有効化/無効化・Administrator向けmanage_odvr権限を実装。DB・管理機能は未実装 |
| Phase 4 Runner API | 未実装 |
| Phase 5 Cloud Run | 未実装 |
| Phase 6 Admin UI | 未実装 |
| Phase 7 Hardening | 一部の入力・通信検査とエラー継続を実装。DNS rebinding等は未対応 |

共通Device型・プリセット・閾値と、DeviceおよびプロトタイプManifestのJSON Schemaを追加しています。prototype-manifest.schema.jsonはローカル撮影用で、将来のrun-manifest.schema.jsonとは区別します。WordPress API用のRun・Snapshot・Dispatch SchemaはPhase 3・4で確定します。

CIはTypeScriptの型検査・ビルド、Schema検証、Nodeの単体テストとChromium撮影テスト、PHP構文検査・WPCSを実施します。Dispatcher、Cloud Run用Docker Image、デプロイ、Plugin管理画面のビルド・ZIP配布は後続です。

## 今回の検証

2026年10月8日、Node.js 20.19.2・npm 10.8.2・Playwright 1.64.0の環境で、ビルド・Schema検証・12件のテスト・PHP構文検査・WPCSが成功しました。ChromiumのテストはmacOSの実行制限外で実施しました。公開サンプルページの撮影とBaseline比較も成功し、差分率0・UNCHANGED、PNGと差分PNGの保存を確認しています。

wp-envの設定は更新しましたが、停止中だったWordPress環境の起動・マウント反映は今回検証していません。Cloud Run、WordPressへのアップロード、管理画面からの実行は未検証・未実装です。

Issue #1ではWordPress実機で初期化・権限・翻訳・無効化時の保存値/ファイル保持を検証するスクリプトを追加しています。CIのWordPressジョブでもwp-envを起動して検証します。
