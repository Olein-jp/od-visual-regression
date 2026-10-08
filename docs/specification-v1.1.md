# OD Visual Regression
## WordPress Visual Regression Testing Plugin + Google Cloud Run Runner

**Version:** 1.1 Draft  
**Repository:** `od-visual-regression`  
**Plugin Name:** OD Visual Regression  
**Plugin Slug:** `od-visual-regression`  
**PHP Prefix:** `odvr_`  
**REST Namespace:** `odvr/v1`

---

# 1. 目的

OD Visual Regression は、WordPress のステージング・テスト環境において、WordPress本体・テーマ・プラグイン・PHP等の更新前後の表示差分を自動検出するためのVisual Regression Testingシステムである。

主な目的は、

- 更新前後の視覚的差分を自動検出する
- 人間による目視確認対象を絞り込む
- 確認精度を一定水準に保つ
- WordPress保守時の確認作業を再現可能にする
- 更新履歴と画面差分を関連付けて保存する

ことである。

従来の、

```text
アップデート
↓
主要ページを目視
↓
なんとなく問題がなさそうなら完了
```

という確認を、

```text
更新前スナップショット
↓
アップデート
↓
再撮影
↓
自動差分抽出
↓
差分のあるページのみ確認
```

に置き換える。

---

# 2. 想定利用環境

主な対象環境：

- ローカル開発環境
- ステージング環境
- テスト環境

本番環境への常時導入は主目的としない。

対象コンテンツ：

- 固定ページ
- 投稿
- カスタム投稿タイプ
- 任意URL

対応画面条件：

- Desktop
- Tablet
- Mobile
- 任意Viewport
- 任意User Agent

---

# 3. システム全体構成

システムは以下の3コンポーネントで構成する。

```text
┌──────────────────────────────────┐
│ WordPress Staging                │
│                                  │
│ OD Visual Regression Plugin      │
│                                  │
│ ・Test Suite管理                  │
│ ・対象ページ管理                  │
│ ・Run管理                         │
│ ・Baseline管理                    │
│ ・Screenshot保存                  │
│ ・Diff結果保存                    │
│ ・管理画面                        │
└────────────────┬─────────────────┘
                 │
                 │ HTTPS
                 ▼
┌──────────────────────────────────┐
│ Google Cloud Run Service         │
│                                  │
│ ODVR Dispatcher                  │
│                                  │
│ ・WordPressからの受付             │
│ ・署名検証                        │
│ ・Cloud Run Job起動               │
└────────────────┬─────────────────┘
                 │
                 ▼
┌──────────────────────────────────┐
│ Google Cloud Run Job             │
│                                  │
│ ODVR Runner                      │
│                                  │
│ Node.js                          │
│ Playwright                       │
│ Chromium                         │
│ pixelmatch                       │
│                                  │
│ ・ページアクセス                  │
│ ・Screenshot                     │
│ ・Baseline比較                    │
│ ・Diff生成                        │
│ ・WordPressへ結果送信             │
└──────────────────────────────────┘
```

---

# 4. 基本アーキテクチャ方針

## 4.1 WordPressをデータ管理の本体とする

永続データはWordPress側へ保存する。

保存対象：

- Test Suite
- Target
- Device
- Run
- Environment情報
- Screenshot
- Diff Image
- Difference Ratio
- Baseline
- Error情報

Cloud Run側には恒久データを保存しない。

---

# 5. Monorepo方針

WordPress Plugin、Dispatcher、Runnerを同一Gitリポジトリで管理する。

Repository：

```text
od-visual-regression
```

採用理由：

- 1つの製品として密接に連携する
- API変更を1つのPRで管理できる
- Schema変更を各コンポーネントへ同時反映できる
- Docker / CI / Cloud Run設定を一元管理できる
- ローカル開発環境を統一できる

ただし、

```text
WordPress Plugin
Dispatcher
Runner
```

は独立したBuild Artifactとする。

---

# 6. Repository Directory Structure

標準ディレクトリ構成は以下とする。

```text
od-visual-regression/
│
├── apps/
│   │
│   ├── dispatcher/
│   │   ├── src/
│   │   │   ├── index.ts
│   │   │   ├── server.ts
│   │   │   ├── config.ts
│   │   │   │
│   │   │   ├── routes/
│   │   │   │   ├── health.ts
│   │   │   │   └── jobs.ts
│   │   │   │
│   │   │   ├── services/
│   │   │   │   ├── cloud-run-job.ts
│   │   │   │   └── signature.ts
│   │   │   │
│   │   │   ├── middleware/
│   │   │   │   ├── auth.ts
│   │   │   │   └── error-handler.ts
│   │   │   │
│   │   │   └── utils/
│   │   │
│   │   ├── tests/
│   │   │
│   │   ├── Dockerfile
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   └── vitest.config.ts
│   │
│   └── runner/
│       ├── src/
│       │   ├── index.ts
│       │   ├── config.ts
│       │   │
│       │   ├── api/
│       │   │   └── wordpress-client.ts
│       │   │
│       │   ├── browser/
│       │   │   ├── browser-manager.ts
│       │   │   ├── context-factory.ts
│       │   │   ├── screenshot.ts
│       │   │   └── stabilize-page.ts
│       │   │
│       │   ├── visual/
│       │   │   ├── compare.ts
│       │   │   ├── diff.ts
│       │   │   └── normalize-image.ts
│       │   │
│       │   ├── security/
│       │   │   ├── url-validator.ts
│       │   │   └── network-guard.ts
│       │   │
│       │   ├── jobs/
│       │   │   ├── execute-run.ts
│       │   │   └── execute-snapshot.ts
│       │   │
│       │   └── utils/
│       │
│       ├── tests/
│       │
│       ├── Dockerfile
│       ├── package.json
│       ├── tsconfig.json
│       └── vitest.config.ts
│
├── wordpress/
│   └── od-visual-regression/
│       │
│       ├── od-visual-regression.php
│       ├── uninstall.php
│       ├── readme.txt
│       │
│       ├── includes/
│       │   ├── class-plugin.php
│       │   ├── class-activator.php
│       │   ├── class-deactivator.php
│       │   ├── class-database.php
│       │   │
│       │   ├── class-suite-repository.php
│       │   ├── class-target-repository.php
│       │   ├── class-device-repository.php
│       │   ├── class-run-repository.php
│       │   ├── class-snapshot-repository.php
│       │   │
│       │   ├── class-run-manager.php
│       │   ├── class-storage.php
│       │   ├── class-environment.php
│       │   ├── class-dispatcher-client.php
│       │   ├── class-runner-auth.php
│       │   ├── class-capabilities.php
│       │   └── class-rest-api.php
│       │
│       ├── rest/
│       │   ├── class-suites-controller.php
│       │   ├── class-runs-controller.php
│       │   ├── class-devices-controller.php
│       │   ├── class-content-controller.php
│       │   ├── class-snapshots-controller.php
│       │   └── class-runner-controller.php
│       │
│       ├── admin/
│       │   ├── src/
│       │   │   ├── index.js
│       │   │   ├── app.js
│       │   │   │
│       │   │   ├── pages/
│       │   │   │   ├── tests.js
│       │   │   │   ├── test-edit.js
│       │   │   │   ├── runs.js
│       │   │   │   ├── run-detail.js
│       │   │   │   ├── devices.js
│       │   │   │   └── settings.js
│       │   │   │
│       │   │   ├── components/
│       │   │   │   ├── target-selector.js
│       │   │   │   ├── device-selector.js
│       │   │   │   ├── diff-viewer.js
│       │   │   │   ├── image-slider.js
│       │   │   │   ├── run-progress.js
│       │   │   │   └── status-badge.js
│       │   │   │
│       │   │   ├── hooks/
│       │   │   ├── api/
│       │   │   └── utils/
│       │   │
│       │   ├── build/
│       │   ├── package.json
│       │   └── webpack.config.js
│       │
│       ├── assets/
│       │   ├── css/
│       │   └── images/
│       │
│       └── languages/
│
├── packages/
│   │
│   ├── schemas/
│   │   ├── src/
│   │   │   ├── run-manifest.schema.json
│   │   │   ├── snapshot-result.schema.json
│   │   │   ├── device-profile.schema.json
│   │   │   ├── dispatch-request.schema.json
│   │   │   └── error.schema.json
│   │   │
│   │   └── package.json
│   │
│   └── shared/
│       ├── src/
│       │   ├── types/
│       │   ├── constants/
│       │   ├── errors/
│       │   └── index.ts
│       │
│       ├── package.json
│       └── tsconfig.json
│
├── infra/
│   │
│   └── cloud-run/
│       ├── README.md
│       │
│       ├── dispatcher/
│       │   ├── service.yaml
│       │   └── iam.md
│       │
│       ├── runner/
│       │   ├── job.yaml
│       │   └── iam.md
│       │
│       └── scripts/
│           ├── deploy-dispatcher.sh
│           ├── deploy-runner.sh
│           └── setup.sh
│
├── docker/
│   └── development/
│       └── README.md
│
├── scripts/
│   ├── build-plugin.sh
│   ├── release-plugin.sh
│   └── validate-schemas.sh
│
├── .github/
│   └── workflows/
│       ├── ci.yml
│       ├── plugin-build.yml
│       ├── runner-deploy.yml
│       └── dispatcher-deploy.yml
│
├── docs/
│   ├── architecture.md
│   ├── api.md
│   ├── security.md
│   ├── development.md
│   └── deployment.md
│
├── docker-compose.yml
├── package.json
├── package-lock.json
├── .gitignore
├── .editorconfig
├── LICENSE
└── README.md
```

---

# 7. apps/dispatcher

Google Cloud Run Serviceとして稼働する。

責務：

```text
WordPressからJob開始要求を受ける
↓
HMAC署名を検証
↓
Request validation
↓
Cloud Run Job実行
↓
202 Accepted
```

Dispatcher自身ではPlaywrightを動かさない。

Dispatcher自身ではScreenshotを生成しない。

---

# 8. apps/runner

Google Cloud Run Jobとして稼働する。

責務：

```text
WordPress Manifest取得
↓
Browser起動
↓
URLアクセス
↓
表示安定化
↓
Screenshot
↓
Baseline取得
↓
Diff
↓
WordPressへUpload
```

RunnerはStatelessとする。

---

# 9. wordpress/

WordPress Pluginの配布対象。

最終的には、

```text
od-visual-regression.zip
```

として生成する。

ZIP内容：

```text
od-visual-regression/
├── od-visual-regression.php
├── includes/
├── rest/
├── admin/build/
├── assets/
├── languages/
└── ...
```

開発用Node modulesやsrcは配布ZIPへ含めない。

---

# 10. packages/schemas

コンポーネント間通信仕様の正本とする。

例：

```text
Run Manifest
Snapshot Result
Device Profile
Dispatch Request
API Error
```

はJSON Schemaで定義する。

これにより、

```text
WordPress
Dispatcher
Runner
```

間のPayload仕様ズレを防ぐ。

---

# 11. packages/shared

Node.js側で共有するTypeScriptコード。

共有候補：

- TypeScript型
- Status constants
- Error Codes
- Device constants
- API constants
- Schema loader
- Validation helpers

---

# 12. infra/

Google Cloud Run設定を保存する。

対象：

- Cloud Run Service
- Cloud Run Job
- IAM
- Environment Variable
- Deployment Script

クラウド設定をコード管理可能な形にする。

---

# 13. Version管理

MonorepoだがVersionは独立管理する。

例：

```text
WordPress Plugin
1.0.0

Runner
1.2.0

Dispatcher
1.0.3
```

Tag例：

```text
plugin-v1.0.0

runner-v1.2.0

dispatcher-v1.0.3
```

---

# 14. Test Suite

Visual Regression Testの設定単位。

保持情報：

- name
- targets
- devices
- baseline
- thresholds
- ignore selectors
- screenshot settings

例：

```text
Regular Maintenance
```

---

# 15. Target

撮影対象。

種類：

```text
WordPress Post Object

Custom URL
```

WordPress Objectの場合：

```text
post_id
post_type
permalink
```

を保持する。

---

# 16. Device Profile

初期プリセット：

```text
Desktop
1440 × 900

Tablet
768 × 1024

Mobile
390 × 844
```

保持項目：

```text
name

viewport_width

viewport_height

user_agent

device_scale_factor

is_mobile

has_touch
```

---

# 17. Run

Test Suiteの1回の実行。

例：

```text
Run #1
更新前

Run #2
WooCommerce更新後

Run #3
PHP変更後
```

Runは削除されない限り履歴として保持する。

---

# 18. Snapshot

以下の組み合わせで生成。

```text
Run
×
Target
×
Device
```

例：

```text
Run #4
Company
Mobile
```

---

# 19. Baseline

比較基準となるRun。

Suiteに、

```text
baseline_run_id
```

を持つ。

Run開始時には、

```text
Pinned Baseline

Previous Successful Run

Specific Run
```

を選択可能とする。

---

# 20. WordPress管理画面

メニュー：

```text
OD Visual Regression

├ Tests
├ Runs
├ Devices
└ Settings
```

---

# 21. Tests

表示：

```text
Regular Maintenance

30 Pages
3 Devices

Baseline
Run #18

Last Run
Run #21

[ Run Test ]

[ Edit ]
```

---

# 22. Test編集

STEP 1：

```text
Post Type
```

選択。

STEP 2：

```text
Target
```

選択。

STEP 3：

```text
Device
```

選択。

STEP 4：

```text
Difference Settings
```

設定。

---

# 23. 投稿タイプ取得

利用：

```php
get_post_types()
```

投稿取得：

```php
WP_Query
```

URL：

```php
get_permalink()
```

REST APIを経由しない。

これにより、

```text
show_in_rest = false
```

のCPTも利用可能。

---

# 24. Runs画面

例：

```text
Run #22

Status
Complete

Environment changes

WooCommerce
10.2 → 10.3


Pages              Desktop     Tablet     Mobile

Home               ✓           ✓          ✓

Company            ✓           REVIEW     ✓

Service            CHANGED     CHANGED    ✓
```

---

# 25. Snapshot Viewer

4モード：

```text
Before

After

Diff

Overlay
```

OverlayはBefore / After Sliderを提供。

---

# 26. Difference Status

初期値：

```text
UNCHANGED
0 ～ 0.1%

REVIEW
0.1 ～ 1%

CHANGED
1%以上
```

設定変更可能。

---

# 27. Environment Snapshot

Run開始時：

```text
WordPress Version

PHP Version

Theme

Theme Version

Active Plugins

Plugin Versions

MU Plugins

Locale

Site URL

Runner Version

Playwright Version

Chromium Version
```

を保存。

---

# 28. Database

専用テーブルを使用。

```text
wp_odvr_suites

wp_odvr_targets

wp_odvr_devices

wp_odvr_runs

wp_odvr_snapshots
```

---

# 29. wp_odvr_suites

```text
id

uuid

name

status

baseline_run_id

settings

created_by

created_at

updated_at
```

---

# 30. wp_odvr_targets

```text
id

suite_id

object_id

post_type

label

url

enabled

sort_order

created_at
```

---

# 31. wp_odvr_devices

```text
id

name

slug

viewport_width

viewport_height

user_agent

device_scale_factor

is_mobile

has_touch

enabled

sort_order
```

---

# 32. wp_odvr_runs

```text
id

uuid

suite_id

reference_run_id

status

triggered_by

runner_execution_id

runner_token_hash

runner_token_expires_at

environment

total_snapshots

completed_snapshots

error_snapshots

started_at

completed_at

created_at
```

---

# 33. wp_odvr_snapshots

```text
id

run_id

target_id

device_id

baseline_snapshot_id

status

url

image_path

diff_path

width

height

baseline_width

baseline_height

dimension_changed

diff_pixels

total_pixels

diff_ratio

http_status

duration_ms

error_code

error_message

metadata

created_at
```

---

# 34. WordPress Storage

保存：

```text
wp-content/uploads/
od-visual-regression/
```

構造：

```text
od-visual-regression/

└ suite-{uuid}/

   └ run-{uuid}/

      └ target-{id}/

         ├ desktop.png
         ├ desktop-diff.png
         ├ tablet.png
         ├ tablet-diff.png
         ├ mobile.png
         └ mobile-diff.png
```

Media Libraryには登録しない。

---

# 35. Screenshot保護

Screenshotは管理者専用情報として扱う。

画像は直接Public URLへ公開しない。

管理画面からの画像取得：

```text
/wp-json/odvr/v1/snapshots/{id}/image
```

Permission：

```text
manage_odvr
```

---

# 36. Capability

Activation時：

```text
manage_odvr
```

を追加。

Administratorへ付与する。

---

# 37. Run Flow

```text
User
↓
Run Test
↓
WordPress Run作成
↓
Temporary Runner Token生成
↓
Dispatcher POST
↓
Cloud Run Job起動
↓
Runner
↓
Manifest取得
↓
Screenshots
↓
Diff
↓
Result Upload
↓
Run Complete
```

---

# 38. Dispatcher API

```text
POST /v1/jobs
```

Request：

```json
{
  "site_id": "site-id",
  "run_uuid": "uuid",
  "callback_base": "https://staging.example.com/wp-json/odvr/v1/runner",
  "runner_token": "token"
}
```

---

# 39. Dispatcher認証

Headers：

```text
X-ODVR-Timestamp

X-ODVR-Signature
```

署名：

```text
HMAC-SHA256(
    timestamp
    + "\n"
    + raw_body,
    shared_secret
)
```

許容：

```text
±300 seconds
```

---

# 40. Runner Token

Run毎に生成。

最低：

```text
32 random bytes
```

DBにはHashのみ保存。

有効期限：

```text
2 hours
```

Authorization：

```text
Bearer {runner_token}
```

---

# 41. Runner Manifest

Endpoint：

```text
GET
/odvr/v1/runner/runs/{uuid}/manifest
```

内容：

```json
{
  "run": {},
  "targets": [],
  "devices": [],
  "settings": {},
  "reference": {},
  "allowed_origins": []
}
```

---

# 42. Baseline Image API

```text
GET
/odvr/v1/runner/snapshots/{id}/baseline
```

---

# 43. Snapshot Upload

```text
POST
/odvr/v1/runner/runs/{uuid}/snapshots
```

multipart：

```text
target_id

device_id

image

diff_image

width

height

baseline_width

baseline_height

diff_pixels

total_pixels

diff_ratio

duration_ms

http_status

status
```

---

# 44. Idempotency

以下の組み合わせを一意として扱う。

```text
run_id

target_id

device_id
```

Retry時はINSERTではなくUPSERT。

---

# 45. Progress API

```text
POST
/odvr/v1/runner/runs/{uuid}/progress
```

---

# 46. Complete API

```text
POST
/odvr/v1/runner/runs/{uuid}/complete
```

---

# 47. Screenshot処理

各ページ：

```text
Context生成
↓
Device設定
↓
Navigate
↓
DOM wait
↓
Font wait
↓
Image wait
↓
Lazy Load
↓
Topへ戻る
↓
Animation disable
↓
Mask
↓
Screenshot
↓
Diff
↓
Upload
```

---

# 48. Navigation

基本：

```text
waitUntil:
domcontentloaded
```

`networkidle` は必須にしない。

---

# 49. Fonts

撮影前：

```javascript
await page.evaluate(
    () => document.fonts.ready
);
```

---

# 50. Images

通常画像：

```text
img.complete
```

を確認。

Timeout初期値：

```text
10 sec
```

---

# 51. Lazy Load

撮影前にページ下部までスクロール。

```text
Top
↓
75% viewport
↓
...
↓
Bottom
↓
Top
```

設定で無効化可能。

---

# 52. Screenshot

基本：

```javascript
page.screenshot({
    fullPage: true,
    type: 'png',
    scale: 'css',
    animations: 'disabled',
    caret: 'hide'
});
```

---

# 53. Dynamic Content

Ignore Selector設定を提供。

例：

```css
.cookie-banner
.random-content
iframe
.current-time
```

共通属性：

```html
data-odvr-ignore
```

---

# 54. Diff Engine

利用：

```text
pixelmatch
```

保持：

```text
diff_pixels

total_pixels

diff_ratio
```

計算：

```text
diff_ratio =
diff_pixels / total_pixels
```

---

# 55. Pixel Threshold

初期：

```text
0.2
```

Suite単位で変更可能。

---

# 56. Screenshot Size Difference

Baseline：

```text
1440 × 5000
```

Current：

```text
1440 × 5200
```

の場合：

```text
Canvas
1440 × 5200
```

へ統一。

不足領域を補完。

さらに：

```text
dimension_changed = true
```

を保存。

---

# 57. HTTP / Browser Error

保存：

```text
HTTP Status

Navigation Timeout

DNS Error

TLS Error

Page Crash

Screenshot Error
```

1ページ失敗でRun全体を中断しない。

---

# 58. Runner Concurrency

MVP初期：

```text
Cloud Run Job Task
1

Browser concurrency
2
```

とする。

---

# 59. Cloud Run Job

初期推奨：

```text
CPU
1～2 vCPU

Memory
2 GiB

Task Count
1

Timeout
30 min

Retries
1
```

---

# 60. Cloud Run Resources

構成：

```text
Google Artifact Registry

Cloud Run Service
odvr-dispatcher

Cloud Run Job
odvr-runner
```

---

# 61. Local Development

ローカル：

```text
Mac
│
├ WordPress development environment
│
└ Docker
   │
   ├ Dispatcher
   └ Runner
```

Cloud Runと同一Docker Imageを利用する。

---

# 62. docker-compose.yml

用途：

```text
Dispatcher local execution

Runner local execution

Local integration test
```

WordPress環境そのものは既存のLocal / wp-env / Docker等を利用できるため、必須ではない。

---

# 63. Root package.json

npm workspaceを利用する。

例：

```json
{
  "private": true,
  "workspaces": [
    "apps/*",
    "packages/*"
  ]
}
```

WordPress Plugin管理画面のnpm依存はWordPress側で独立管理してもよい。

---

# 64. CI

Pull Request時：

```text
TypeScript lint
↓
TypeScript Test
↓
JSON Schema validation
↓
PHP Syntax Check
↓
PHPCS
↓
Plugin JS Build
```

を実施。

---

# 65. Runner Deploy

変更対象：

```text
apps/runner/**
packages/**
```

の場合：

```text
Docker Build
↓
Artifact Registry
↓
Cloud Run Job Update
```

---

# 66. Dispatcher Deploy

変更対象：

```text
apps/dispatcher/**
packages/**
```

の場合：

```text
Docker Build
↓
Artifact Registry
↓
Cloud Run Service Deploy
```

---

# 67. Plugin Build

変更：

```text
wordpress/**
```

の場合：

```text
npm build
↓
不要ファイル除外
↓
ZIP作成
```

Artifact：

```text
od-visual-regression.zip
```

---

# 68. Security

最低限以下を実装する。

```text
HMAC Authentication

Temporary Runner Token

Capability Check

REST Nonce

SSRF Protection

Allowed Origins

Private IP Block

Google Metadata Endpoint Block

Input Validation

Output Escaping

SQL Prepared Statements
```

---

# 69. SSRF

禁止：

```text
localhost

127.0.0.0/8

169.254.0.0/16

RFC1918

file://

ftp://
```

Manifestに含まれる：

```text
allowed_origins
```

のみアクセス可能。

---

# 70. HTTP Basic Authentication

ステージングサイト対応。

設定：

```text
HTTP Auth User

HTTP Auth Password
```

Runnerは検査済みIPへ固定接続する共通HTTP transportで、指定された撮影HTTPS OriginだけにBasic認証を注入する。BrowserContextのHTTP Credentialsには渡さず、ページ由来のAuthorizationは除去する。資格情報はメモリ内だけに保持し、Manifest・履歴・ログへ保存しない。

可能であれば：

```text
ODVR_HTTP_AUTH_USER

ODVR_HTTP_AUTH_PASSWORD
```

を `wp-config.php` で指定可能とする。

---

# 71. WordPressログイン状態

MVPでは、

```text
Logged-out Frontend
```

のみ対象。

Authenticated Visual TestはPhase 2。

---

# 72. Settings

## Runner

```text
Dispatcher URL

Shared Secret

Connection Test
```

## Screenshot

```text
Navigation Timeout

Image Timeout

Lazy Load

Concurrency

Pixel Threshold
```

## Storage

```text
Run Retention
```

## Authentication

```text
HTTP Basic Authentication
```

---

# 73. Run Retention

選択：

```text
Keep All

Last 10

Last 20

Custom
```

Baseline Runは削除対象外。

---

# 74. MVP

v1.0：

1. Test Suite
2. 投稿タイプ取得
3. Page / Post / CPT選択
4. Custom URL
5. Desktop
6. Tablet
7. Mobile
8. Custom Device
9. Cloud Run Dispatcher
10. Cloud Run Runner
11. Full Page Screenshot
12. Baseline
13. Pixel Diff
14. Difference Ratio
15. Before
16. After
17. Diff
18. Overlay
19. Ignore Selectors
20. Run History
21. Environment Snapshot
22. HTTP Basic Auth
23. Retention
24. SSRF Protection

---

# 75. Phase 2候補

```text
Firefox

WebKit

Authenticated pages

Interaction scenarios

Form interactions

Scheduled Tests

Email notification

Slack notification

GitHub integration

Object Storage

AI Diff Analysis
```

---

# 76. 開発順序

## Phase 1
Runner Prototype

```text
URL
↓
Playwright
↓
PNG
```

## Phase 2
Visual Diff

```text
Baseline
↓
Current
↓
Diff PNG
```

## Phase 3
WordPress Plugin Core

```text
Suite
Target
Device
Run
Snapshot
```

## Phase 4
Runner API

```text
Manifest
Upload
Progress
Complete
```

## Phase 5
Cloud Run

```text
Dispatcher
↓
Cloud Run Job
```

## Phase 6
Admin UI

```text
Tests
Runs
Diff Viewer
Settings
```

## Phase 7
Hardening

```text
Security
Retry
Cleanup
Logging
Error Handling
```

---

# 77. リポジトリ設計の原則

OD Visual Regressionは、

```text
1 Product
1 Repository
3 Deployable Components
```

として管理する。

つまり、

```text
Repository
│
├ WordPress Plugin
│
├ Dispatcher
│
└ Runner
```

は同じリポジトリに置くが、

```text
Build
Version
Release
Deploy
```

は個別に行う。

---

# 78. 最終アーキテクチャ

```text
                           User
                            │
                            ▼
                 WordPress Admin UI
                            │
                            ▼
               OD Visual Regression
                            │
                   Create Run
                            │
                            ▼
                  Cloud Run Service
                     Dispatcher
                            │
                            ▼
                    Cloud Run Job
                       Runner
                            │
              ┌─────────────┴─────────────┐
              │                           │
              ▼                           ▼
       WordPress Manifest          Target Website
              │                           │
              │                           ▼
              │                       Playwright
              │                           │
              │                           ▼
              │                      Screenshot
              │                           │
              ▼                           ▼
        Baseline PNG ─────────────→ Image Compare
                                          │
                              ┌───────────┴───────────┐
                              ▼                       ▼
                         Current PNG               Diff PNG
                              │                       │
                              └───────────┬───────────┘
                                          ▼
                               WordPress REST API
                                          │
                                          ▼
                                  WordPress Storage
                                          │
                                          ▼
                                      DB Records
                                          │
                                          ▼
                                   Results Viewer
```

---

# 79. 最終方針

本システムでは、

```text
WordPress
=
管理・保存・UI

Cloud Run Service
=
Job受付・起動

Cloud Run Job
=
Browser実行・撮影・比較
```

という責務分離を維持する。

Visual Regression固有のブラウザ処理をWordPress/PHPへ持ち込まないことで、

- Xserver
- さくら
- ConoHa
- VPS
- その他一般的なWordPress環境

でもプラグイン側の動作要件を低く保つ。

またMonorepoとすることで、Plugin・Runner・Dispatcher・API Schemaを一体的に開発しつつ、各コンポーネントを独立してBuild / Deploy / Releaseできる構成とする。

この構成を **OD Visual Regression v1.x の標準開発構成**とする。