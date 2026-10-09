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

ブラウザテストはネットワーク上の実サイトに依存せず、固定HTMLと制御可能なfixtureをChromiumで撮影・比較します。Linuxでブラウザ依存ライブラリが不足する場合は `npx playwright install --with-deps chromium` を使います。

### 撮影条件のfixtureテスト

```sh
npm run build
node --test apps/runner/tests/browser.test.mjs
```

`apps/runner/tests/fixtures/` のHTMLを仮想Originへの応答として返し、スクロールで現れる画像、最上部へ戻った表示、Lazy Load無効、遅延画像・フォントの成功とtimeout、壊れた画像、両方式のマスク、Device設定とCSS pixelでの出力寸法を検証します。遅延リソースは応答を明示的に解放するか保留し続けるため、外部回線速度に左右されません。テスト用フォントはインストール済みPlaywrightに同梱されるTTFを使います。

Basic認証はfixture専用の資格情報と、ループバックの一時ポート2つを使います。正常認証・誤ったパスワード・別Originの401に資格情報を送らないことを確認し、環境変数とサーバーを終了時に復元・終了します。これらはBrowser層の単体テストとしてContextを直接使います。本番のSSRFガードに例外や無効化設定は追加せず、内部IPを拒否する既存Browserテストも維持します。

撮影前のアニメーション・transition・smooth scroll抑止にはChromiumの構築可能なスタイルシートを使います。インラインstyle/scriptを禁止するCSP下でも撮影でき、ページ全体のCSPは無効化しません。fixtureでは同一Originの外部CSSが適用され、インラインscriptが拒否されることも検証します。CSPが拒否したページ側リソースを撮影処理で許可することはありません。

待機の対象は`document.fonts`と`document.images`です。フォントの取得失敗ではブラウザの代替フォントが使われる場合があります。CSS背景画像、iframe内の画像、Shadow DOM内部やJavaScriptで後から追加されるリソースの完全な安定化は保証しません。Lazy Load無効はRunnerの事前スクロールを省略する設定で、ブラウザ自身やサイトの読込動作は停止しません。フォント・スクロール・画像の各待機に`image_timeout_ms`を適用し、timeoutは`IMAGE_LOAD_FAILED`として扱います。スクロールのtimeout時にも最上部へ戻します。

## 撮影と比較

`docs/prototype-manifest.example.json` をコピーして対象URL・Device・設定を変更します。ページ内で参照するCSS・画像・フォント等のOriginも明示的にallowed_originsへ追加してください。許可しないリソースはブロックされ、表示に影響します。自動リダイレクトは拒否するため、最終URLを指定します。

```sh
mkdir -p artifacts
npm run runner -- docs/prototype-manifest.example.json artifacts/before
# WordPress・テーマ・プラグイン等を更新してから実行します。
npm run runner -- docs/prototype-manifest.example.json artifacts/after artifacts/before
```

出力先は存在しないディレクトリを指定します。同じRunを上書きしません。Baselineを指定するCLI引数は従来どおりです。比較前にBaselineの `result.json` を検査し、現在の撮影条件と照合します。

### Baselineの互換性

対象ID・Device IDごとに、次の条件が一致する場合だけ比較します。

- 対象URL（文字列の完全一致）
- Deviceのviewport幅・高さ、User Agent、device scale factor、mobile・touch設定
- マスクセレクター、lazy load、navigation・image timeout
- 許可Origin（リソースの読み込み条件に影響するため）
- Runner・Playwright・Chromiumの各バージョン（完全一致）

マスクセレクターと許可Originは順序を無視し、マスクセレクターの重複も無視します。対象のラベル、Deviceの表示名・slug、並列数は撮影条件の比較から除外します。Baseline画像はBaseline側のslugから特定します。 `pixel_threshold`・`review_threshold`・`changed_threshold` は撮影条件ではないため、変更しても比較でき、現在の設定で差分を再判定します。撮影画像の幅・高さが変わっただけの場合も比較でき、従来の `dimension_changed` 処理を維持します。Deviceのviewport設定自体を変更した場合は互換とみなしません。

各Snapshotの `baseline_compatibility.state` と `reasons` に検査結果と該当項目を記録します。

| state | 意味・処理 |
| --- | --- |
| COMPATIBLE | 同条件。画像比較を実行 |
| INCOMPATIBLE | 条件・バージョンの相違。比較を拒否しERROR |
| METADATA_MISSING | 設定・バージョン・Snapshot一覧の情報不足。比較を拒否しERROR |
| RESULT_MISSING | result.jsonの欠損。比較を拒否しERROR |
| RESULT_INVALID | JSONの構文・構造不正、Snapshotの重複やURL・画像パス・記録寸法の矛盾。比較を拒否しERROR |
| SNAPSHOT_MISSING | 対応するSnapshot記録の欠損。NO_BASELINE |
| IMAGE_MISSING | 対応するPNGの欠損。NO_BASELINE |
| IMAGE_INVALID | PNGの破損・画像寸法上限超過。比較を拒否しERROR |
| READ_FAILED | JSON・画像の読み取り失敗（欠損以外）。比較を拒否しERROR |

ERROR時の `error_code` は `BASELINE_` とstateの組み合わせです。現在の画像は保存し、他のSnapshotの撮影も続行します。ERRORが含まれるRunは終了コード1になります。

旧Baselineも、保存済みのconfiguration・各バージョン・Snapshot記録が揃っていて条件が一致すれば利用できます。画像だけのBaselineや情報不足の結果JSONを自動的に互換とみなすことはありません。情報不足の場合は現在の条件でBaselineを撮影し直してください。記録上ERRORのSnapshotは比較対象にしません。

互換性と既存の画像比較の検証は次で実行します。

```sh
npm run build
node --test apps/runner/tests/baseline.test.mjs apps/runner/tests/compare.test.mjs
```

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

WordPress→Dispatcher→Runner→画像保存・完了の最小結合は [共通Imageと固定ローカル接続](local-images.md) の手順で実行できます。管理画面/Viewerを含むMVP全体は後続Issueで検証します。Issue #13の不足と着手条件は [MVP結合検証の記録](mvp-integration-validation.md) を参照してください。

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

## 初期化・権限の検証

プラグインは `plugins_loaded` で通常実行時のフックを登録し、`init` で翻訳の場所を登録します。有効化時にAdministratorへ `manage_odvr` を付与します。管理機能の権限判定には `ODVR_Capabilities::can_manage()` を使い、将来のREST APIではnonceやRunner Tokenの検証も別途行います。無効化では履歴・画像・設定・権限を削除しません。

```sh
npm run env:start
npm run env:cli -- eval-file tests/bootstrap.php
npm run env:stop
```

検証スクリプトは有効化済みのプラグインと開発用の単一サイト環境を前提に、通常の有効化・再有効化・無効化、初期化の重複防止、Administrator/Editor/Subscriber/未ログインの権限、日本語翻訳を確認します。保存値と画像領域に作成した確認用ファイルが無効化で削除されないことも検証します。DB・Runは未実装のため、実際のRun履歴の保持は後続のDB実装時に検証します。テスト用ユーザー・保存値・ファイルは終了時に削除します。

スクリプトはAdministratorの権限を一時的に外して有効化フックによる再付与を確認するため、本番サイトでは実行しないでください。無効化しても権限は保持する設計です。Multisite・ネットワーク有効化の権限付与方式はデータ設計Issueで確定します。

CIではwp-envの既定版と最低対応版のWordPress 6.7の両方で、PHP 7.4の検証を実行します。
