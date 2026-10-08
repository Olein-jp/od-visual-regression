# 開発手順

仕様書のPhase 1・2を実装した段階です。WordPress側は翻訳を読み込むひな形です。

## 必要な環境

RunnerはNode.js 20以上を使用します。モノレポ全体では既存のwp-env依存関係の要求に合わせたNode.js・npmが必要です。PHP 7.4以上、Composer 2、WordPress環境にはDockerが必要です。

```sh
npm ci
composer install
npx playwright install chromium
npm run build
npm run schemas:validate
npm test
composer lint
```

ブラウザテストはネットワーク上の実サイトに依存せず、Chromium内の固定HTMLを撮影・比較します。Linuxでブラウザ依存ライブラリが不足する場合は `npx playwright install --with-deps chromium` を使います。

## 撮影と比較

`docs/prototype-manifest.example.json` をコピーして対象URL・Device・設定を変更します。ページ内で参照するCSS・画像・フォント等のOriginも明示的にallowed_originsへ追加してください。許可しないリソースはブロックされ、表示に影響します。自動リダイレクトは拒否するため、最終URLを指定します。

```sh
mkdir -p artifacts
npm run runner -- docs/prototype-manifest.example.json artifacts/before
# WordPress・テーマ・プラグイン等を更新してから実行します。
npm run runner -- docs/prototype-manifest.example.json artifacts/after artifacts/before
```

出力先は存在しないディレクトリを指定します。同じRunを上書きしません。変更前後では同じManifest、Runner、Playwright、Chromiumを使用してください。結果のconfigurationと各バージョンを照合できます。プロトタイプはBaseline設定の互換性を自動判定しません。

```text
artifacts/after/
├── result.json
└── target-1/
    ├── desktop.png
    └── desktop-diff.png
```

初回はCAPTURED、比較時に対応するBaselineがなければNO_BASELINE、比較成功時はUNCHANGED・REVIEW・CHANGEDです。失敗はERRORとして記録し、残りの組み合わせを続行します。ERRORがあれば終了コード1になります。差分の検出だけでは終了コードを変更しません。

ratioは0〜1です。UNCHANGEDは0.001以下、REVIEWは0.001超〜0.01未満、CHANGEDは0.01以上です。寸法の不足領域を透明色で補完し、片方にしか存在しない領域を必ず差分として数えます。画像展開・比較は最大4,000万画素に制限します。

HTTP Basic認証にはODVR_HTTP_AUTH_USERとODVR_HTTP_AUTH_PASSWORDを実行環境へ設定します。秘密情報をManifestへ記載しないでください。WordPressログインは未対応です。

## WordPress

```sh
npm run env:start
npm run env:cli -- plugin list
npm run i18n:pot
npm run i18n:mo
```

wp-envへ渡すプラグインの配置先を `wordpress/od-visual-regression` へ変更しました。既存環境は再起動してマウントを反映します。Composerとwp-envの操作はリポジトリルートで行います。

## 参照した公式資料

- [Playwrightの撮影API](https://playwright.dev/docs/api/class-page#page-screenshot)
- [Service Workerとリクエスト制御](https://playwright.dev/docs/service-workers)
- [リダイレクトを制御するRoute API](https://playwright.dev/docs/api/class-route#route-fetch)
- [pixelmatch](https://github.com/mapbox/pixelmatch)
