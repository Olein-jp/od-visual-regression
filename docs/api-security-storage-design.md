# 共通API・Runner認証・非公開Storageの設計

対象は [Issue #4](https://github.com/Olein-jp/od-visual-regression/issues/4)。根拠は [仕様書v1.1](specification-v1.1.md) §10・§35・§37〜46・§68・§70、およびマージ済みの [DB・Run・Baseline・Retention設計](data-lifecycle-design.md)。本書は採用前の設計案であり、PRへの合意後に実装Issueへ分割する。今回は製品コード、Schema、クラウドリソースを変更しない。

実装済みの共通契約（#26）と管理表示・診断フィールドの確定内容は [api-contracts.md](api-contracts.md) を参照する。以下の設計当時の記述と、製品APIそのものの実装状況は区別する。

## 現状と推奨方針

- `prototype-manifest.schema.json` と `PrototypeManifest` はローカル実行用。Run ID、参照Snapshot、認証、通信Versionを持たず、製品のRun Manifestとは別契約である。
- `execute-run.ts` はローカルPNGと自由形式の結果JSONを生成する。HTTP Upload・Progress・Complete・Dispatcherは未実装。現在の結果は本書のSchemaに適合済みではない。
- WordPressはCookie/nonce/`manage_odvr`による公開コンテンツ選択APIとURL形式検証を実装済み。DB、Run用Token、非公開Storageは未実装。
- WordPressが管理・保存、Dispatcherが署名済み要求の受付・Job起動、Runnerが撮影・比較を担当する。Runnerには管理APIの権限を与えない。
- Schemaを通信の正本とし、NodeとPHPの共通fixtureで一致を検証する。DBのライフサイクル・ロック・COUNT規則は先行設計を引き継ぐ。

## 共通SchemaとVersionの責務

すべての新規JSON Payloadは `schema_version: 1` 必須、objectは `additionalProperties: false`。JSON Schema draft-07を用いる。製品版には次のファイルを追加する計画とし、プロトタイプSchemaを上書きしない。

| Schema予定ファイル | 必須項目と責務 |
|---|---|
| `run-manifest.schema.json` | schema_version、run、suite、targets、devices、settings、reference、allowed_origins。開始時の固定設定と実行再開情報 |
| `snapshot-result.schema.json` | schema_version、target_id、device_id、status、width、height、baseline_width、baseline_height、dimension_changed、diff_pixels、total_pixels、diff_ratio、duration_ms、http_status、error_code、error_message、no_baseline_reason。画像以外のUpload結果 |
| `dispatch-request.schema.json` | schema_version、site_id、run_uuid、callback_base、runner_token。Dispatcherへの一時的な通信専用 |
| `error.schema.json` | schema_version、code、message、data（status、retryable、request_id）。WordPressのWP_Error形に合わせた外部エラー |
| `progress-request.schema.json` | schema_version、runner_execution_id、versions。件数は送信せずDBから計算 |
| `complete-request.schema.json` | schema_version、runner_execution_id、versions、outcome、error_code、error_message。outcomeはfinished / failed |
| `runner-credentials.schema.json` | schema_version、http_auth（nullまたはorigin、username、password）。通常Manifestと履歴から分離した秘密通信 |
| `run-state.schema.json` | schema_version、run_uuid、status、total_snapshots、completed_snapshots、error_snapshots、pending_snapshots、completed_at、error_code。Progress/Complete応答の共通形 |

Versionは混同しない。`/odvr/v1`・Dispatcherの`/v1`はルートと認証方式のmajor、`schema_version`はPayload形のmajor、`settings_version`・`environment_version`・Snapshotの`metadata_version`は保存JSON、`odvr_db_version`はDB構造、Plugin/Runner/Dispatcher/Playwright/ChromiumのVersionはソフトウェアと環境の識別子。各保存Versionの初期値は1。Schemaの互換性をソフトウェアVersionから推測しない。未知の通信Versionは400 `odvr_unsupported_schema_version`、未知の保存Versionは書込を停止して診断する。v1では未知フィールドも拒否し、契約変更はSchema・fixture・両端を同時更新する。

### 共通値とPHP側の検証

- IDはJSON整数1〜2147483647。現在のDevice/Prototype上限を引き継ぎ、PHP 7.4の32bit環境でも丸めない。bigintのDB値がこの範囲を越えたらAPI変換を拒否して診断する。将来の文字列IDは別majorとする。
- UUIDは小文字のcanonical UUID v4。時刻はUTCのRFC3339秒精度（`2026-10-08T03:00:00Z`）、DB UTC datetimeとの変換を明示する。
- Targetは1〜100、Deviceは1〜10、組み合わせは最大1000。IDとDevice slugは各配列内で一意。TargetのURLは[既存契約](content-api.md)と同じ2048バイト以下、資格情報・制御文字なしのHTTP(S)絶対URL。ラベル200文字以下、投稿情報はobject_id（整数/null）とpost_type（20文字以下）。
- Device項目・上限、CaptureSettingsの項目・既定値は現行Schema/sharedを引き継ぐ。settingsには `settings_version: 1` も必須。`review_threshold < changed_threshold`、浮動小数は有限値、allowed_originsは正規化したHTTP(S) Originのみ1〜30件・一意とする。
- URL queryへ秘密を入れない。Target URLに含まれる任意の機密queryを自動判定できないため、保存URLは管理者専用にし、アクセスログへURLを出さずTarget IDで記録する。userinfoは禁止する。URL形式検証はSSRF対策の代わりにはならない。
- NodeはAjv + ajv-formatsで全Schemaを検証する。PHPは `ODVR_Contract_Validator` が同梱Schemaを読み、WordPressの `rest_validate_value_from_schema()` で基本型・required・enum・範囲を検証する。draft-07全体がWordPressで実行されるとは見なさない。未知キー、UUID、バイト長、unique ID、nullable/条件分岐、相互参照、閾値・比率は明示的なPHP検査を追加する。Schemaで使うkeywordを固定し、未対応keywordは検証器初期化時に失敗させる。
- JSONの数値文字列やboolean文字列を許可しない。multipartだけ下記の厳格な文字列変換を行い、検証前に不正値をsanitizeで正しい値へ変換しない。保存時には別途適切にsanitizeし、SQLはprepare、画面はtextとして描画する。

### Statusとエラー

Snapshot APIの `CAPTURED / NO_BASELINE / UNCHANGED / REVIEW / CHANGED / ERROR` はDBの `captured / no_baseline / unchanged / review / changed / error` と1対1。`PENDING`はManifestの再開情報専用でDBのpendingに対応し、Uploadでは拒否する。Run APIはDB同様 `queued / running / complete / partial / failed / deleting` を用いる。

```json
{
  "schema_version": 1,
  "code": "odvr_snapshot_conflict",
  "message": "この撮影結果は別の内容で確定済みです。",
  "data": { "status": 409, "retryable": false, "request_id": "request-123" }
}
```

| HTTP | 主なcode / 意味 |
|---|---|
| 400 | odvr_invalid_payload、odvr_unsupported_schema_version、odvr_invalid_image / 入力の修正が必要 |
| 401 | odvr_runner_unauthorized、odvr_dispatch_unauthorized / 不在・形式不正・期限切れ・失効を区別せず返す |
| 403 | odvr_forbidden / 管理nonce・権限不足 |
| 404 | odvr_not_found、odvr_baseline_unavailable / 存在しない、または認証スコープ外。Baseline不在理由はmissing / corrupt / incompatibleのみ |
| 409 | odvr_run_not_started、odvr_run_closed、odvr_snapshot_conflict、odvr_results_pending、odvr_execution_conflict / 状態または一意性の競合 |
| 413 / 415 | odvr_payload_too_large / odvr_unsupported_media_type |
| 429 / 503 | odvr_rate_limited / odvr_storage_unavailable、odvr_dispatch_unavailable / Retry-After付き、retryable=true |

ERROR Snapshot用codeは `SNAPSHOT_FAILED / NAVIGATION_FAILED / HTTP_ERROR / CAPTURE_FAILED / CONTEXT_CLOSE_FAILED / RUN_ABORTED / RUN_DEADLINE_EXCEEDED / DISPATCH_TIMEOUT`。外部通信エラーと結果エラーを分ける。生の例外、秘密、ファイルパス、SQL、クラウド応答bodyは返さず、error_messageは管理者向けの定型文（500文字以下）にする。認証不正はretryable=false、Run期限切れは再実行が必要。既存のWordPressコア認証エラーはcore形式のままであり、error.schemaはODVRが生成するエラーに適用する。Runnerは401/403/404でリソースを探索し直さない。

## 管理API

以下のパスは `/wp-json/odvr/v1` からの相対。すべて現在のサイトのCookie + `wp_rest` nonce + `manage_odvr` を必須とする。Runner Token、Application Password、別サイトCookieでは許可しない。nonceは `X-WP-Nonce` に送り、ログ/URLへ出さない。[WordPress公式のCookie認証](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)と[既存コンテンツAPI](content-api.md)に従う。

JSON bodyとJSON応答は上記Version/検証規則を適用。単一リソースは `{schema_version:1,item:{...}}`、一覧は `{schema_version:1,items:[...]}`。page既定1、per_page既定20・最大100、ID昇順、`X-WP-Total`/`X-WP-TotalPages`を返す。Run一覧だけcreated_at/id降順。既存のcontent APIの配列応答は変更しない。

| ルート・method | 入力 / 正常応答・境界 |
|---|---|
| GET/POST `/suites`、GET/PATCH/DELETE `/suites/{id}` | name、settings、device_ids。GET/PATCH=200、POST=201。DELETE=200でarchivedへ（実行中は409）、物理削除なし |
| GET/POST `/suites/{id}/targets`、PATCH/DELETE `/suites/{id}/targets/{target_id}` | object_id/post_typeまたはCustom URL、label、enabled、sort_order。POST=201、更新=200、DELETEはenabled=false。別Suite IDは404 |
| GET/POST `/devices`、GET/PATCH/DELETE `/devices/{id}` | DeviceProfile + enabled/sort_order。POST=201、更新=200、DELETEは無効化。slug変更/参照中の無効化は409 |
| GET `/suites/{id}/runs`、POST `/suites/{id}/runs` | POSTはbaseline_mode=pinned/previous/specific、specific時reference_run_id必須。固定Manifest/pending行作成後202、itemはrun_uuid/status/deadline_at。同Suite実行中は409 |
| GET/DELETE `/runs/{uuid}`、GET `/runs/{uuid}/snapshots` | Run環境・集計・結果（秘密/Token Hash/内部パスを除外）。DELETEは保護再検査後202 deleting、実行中/参照保護は409 |
| POST `/suites/{id}/baseline` | run_id。同Suiteのcompleteかつ全画像可読だけを昇格し200。履歴参照は変更しない |
| GET/PATCH `/settings` | Dispatcher URL、site_id、Retention、期限設定。GETは秘密の有無だけ。Run期限はToken TTL未満、queued期限15分・総期限90分が既定 |
| GET `/snapshots/{id}/image?kind=current\|diff\|baseline` | PNGバイナリ200。kind既定current、baselineは固定参照に解決、別サイト/欠損/deletingは404。秘密付きURLやPublic画像URLは返さない |

Suite設定とサイト設定は分離する。Shared SecretとHTTP Basic認証はMVPではwp-config.php/ホストのSecret injectionによる設定のみとし、Settings APIで平文の登録・取得を提供しない。PATCHで秘密フィールドを送れば400。GETは `dispatcher_secret_configured`、`http_auth_configured`、`http_auth_origin` を返す。DB Optionsには非秘密設定だけを保存する。

例：`POST /suites/1/runs` のbodyと202応答（itemの表示省略なし）。

```json
{ "schema_version": 1, "baseline_mode": "previous" }
```

```json
{
  "schema_version": 1,
  "item": {
    "run_uuid": "7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87",
    "status": "queued",
    "deadline_at": "2026-10-08T04:30:00Z"
  }
}
```

Dispatcher受付が不明でも202でqueued履歴を返し、後述の照合/期限処理で確定する。Runを無断で二重作成しない。DB/Storage保護の準備に失敗した場合は202にせず503。

## Dispatcherの受付契約

HTTPS `POST /v1/jobs`、Content-Type application/json。署名は仕様どおり `HMAC-SHA256(timestamp + "\n" + raw_body, shared_secret)` の小文字hex64桁、`X-ODVR-Timestamp`はUnix秒の十進文字列、`X-ODVR-Signature`で送る。受信raw body最大16KiB、±300秒、定数時間比較。JSONを再エンコードしたbodyで署名検証しない。site_id（1〜100文字、`[A-Za-z0-9_-]+`）から登録済みSecretとcallback_baseを選び、自己申告のURLで認証先を決めない。

```json
{
  "schema_version": 1,
  "site_id": "staging-1",
  "run_uuid": "7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87",
  "callback_base": "https://staging.example.com/wp-json/odvr/v1/runner",
  "runner_token": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
}
```

Tokenは形式例であり、実際は下記の乱数を使用する。callback_baseは登録値との完全一致、HTTPS、query/fragment/userinfoなし。WordPressもDispatcher URLを運用時の登録済みHTTPS URLに制限する。リダイレクトは禁止、動的ホスト指定を許可しない。

- 受付一意キーは `(site_id,run_uuid)`。認証・Schema検証後、永続的な受付記録を原子的に作る。記録は要求digest、受付状態、execution ID、非秘密のSecret参照だけ。再送が同じbodyなら202、起動済みなら200で同じexecution IDを返す。異なるToken/要求digestなら409。署名timestampは再送時更新してよい。
- 初回202例：`{"schema_version":1,"site_id":"staging-1","run_uuid":"7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87","status":"accepted","runner_execution_id":null}`。起動後の200ではstatus=started、runner_execution_idはCloud Run Executionの識別子。Dispatcher受付はRunのrunning遷移を意味しない。
- 内部起動処理の中断と再試行で二重Job起動を避ける永続leaseとExecution照合を必要とする。起動済みか不明なら新規起動しない。実際のクラウド上の照合・retry設定は #10で検証する。署名の時刻窓だけをreplay防止と見なさない。
- WordPressから受付を照合する `GET /v1/jobs/{run_uuid}?site_id=staging-1` も同じ署名ヘッダー（raw_bodyは空）を必須とし、照合先site_id/run_uuidは登録サイトの記録だけに限定する。応答は上記形、未受付は404。別siteの記録は返さない。照合は状態を変えないためreplayによる副作用なし。
- WordPressは平文Tokenを現在のリクエストメモリにだけ保持して同じbodyを限定再送する。応答喪失後の別PHPリクエストは照合だけ行う。平文の復元・Token再発行・無断の再dispatchは行わず、未受付ならqueued期限でfailedへ確定する。

## Run Token・Runner境界・失効

WordPressはRun作成時 `random_bytes(32)` で生成し、paddingなしbase64url43文字にする。Hashは `hash('sha256', token)` のhex64桁。十分な乱数のTokenにパスワード用の遅いHashは不要。DBのRunにはHashとcreated_at+2時間のUTC期限だけを保存し、平文をManifest・Environment・Snapshot metadata・管理API・ログへ入れない。

RunnerはHTTPSの `Authorization: Bearer {token}` だけで認証する。URL/query/body/cookieでTokenを受け付けない。exact43文字とbase64url文字を検査、Hash照合は `hash_equals()`。RunのUUID、現在サイト、期限（now >= expiresで失効）、有効なPlugin状態とHashを確認する。認証はWordPressユーザーへ変換せず、専用permission callbackでRunスコープを作る。プロキシ/PHPまでAuthorizationヘッダーが渡ることが実行環境要件。

| Tokenの対象状態 | 許可する操作 |
|---|---|
| queued | Manifest取得だけ。期限/Execution確認後にrunningへ遷移 |
| running | 自RunのManifest・Credentials・Upload・Progress・Complete、Manifestで固定した参照SnapshotのBaseline読取だけ |
| complete / partial / failed | 既に受理したものと同じComplete再送だけ。Manifest・Credentials・Baseline・Upload・Progressは拒否 |
| deleting / 無効化 / Uninstall / 期限切れ | 全拒否。HashをNULLにして失効 |

成功/部分完了/Runner報告failedではHashを元の2時間期限まで保持し、閉じたRunの再送権限をCompleteだけに絞る。管理側の期限処理・queued timeout・無効化・削除・強制失敗では即時HashをNULLにし、再送も不可。期限後のHashはcleanupするが、期限検査はcleanupの実行に依存しない。Token更新/延命は提供せず、新Runには新Tokenを発行する。

Runner Tokenが自RunのSnapshot IDを知っていても、管理画像や任意の過去Runは読めない。Baseline APIはTokenが指す現在Runの `reference_run_id` とTarget×Deviceごとの `baseline_snapshot_id` の両方に一致し、同サイト・同Suite・complete・非deletingであることを検査する。参照Runの他のSnapshot、Baselineのdiff、他Runへの書込は禁止。スコープ外は404、認証失敗は401、閉じた自Runの操作は409。

Runner TokenはDispatcherへのPOST時にのみ平文送信し、クラウド起動時はTTL付きのRun専用Secretへ置き、Jobには非秘密のSecret参照を渡す推奨方式とする。Runnerサービスアカウントだけが該当Secretを読み、環境変数/起動引数/Job overrideの監査記録へ平文を残さない。TTLは元の発行期限を越えず、終端・期限後の削除を再試行する。WordPress DBはHashのみ、Dispatcher受付履歴はSecret参照のみ。Secretの削除が遅れてもWordPress側の期限/スコープで拒否する。Secret基盤と最小IAM・削除方式の確定は #10の実装前条件であり、無期限の平文保存に置き換えない。

## Runner APIとPayload例

パスは `/wp-json/odvr/v1/runner` からの相対。すべて上記Tokenを必須とし、CORSによるブラウザ外部公開を行わない。Runner関連のJSON応答・Credentials・PNGは `Cache-Control: private, no-store`、ログはmethod/ルート種別/Run UUID/request_idだけとする。

| ルート | 正常応答・処理 |
|---|---|
| GET `/runs/{uuid}/manifest` | `X-ODVR-Execution-ID`（1〜255文字、制御文字なし）必須。200 Run Manifest。最初の取得でqueued→running、ID固定。retryは同じCloud Run Execution IDのみ。他IDは409。runningでは固定設定と最新snapshot_statesを返す |
| GET `/runs/{uuid}/credentials` | 200秘密専用Payload。自Run runningだけ。Basic不要ならhttp_auth=null。再取得可、履歴/キャッシュ禁止 |
| GET `/snapshots/{id}/baseline` | 200 PNG、固定参照だけ。欠損/破損404のodvr_baseline_unavailable。応答にX-ODVR-Image-SHA256、Content-Length。Runnerもdecode/digest確認 |
| POST `/runs/{uuid}/snapshots` | multipartを検証、200 `{schema_version:1,snapshot_id:51,status:"UNCHANGED",replayed:false}`。同一再送はreplayed=true。画像パスなし |
| POST `/runs/{uuid}/progress` | 200 run-state。versions初回補完・以後一致、件数はDBから再計算、期限延長なし |
| POST `/runs/{uuid}/complete` | 200 run-state。pendingがあれば409、同一再送も200。failed報告ならpendingをERRORにしてfailed確定 |

run-manifestのrunはuuid、suite_id、status、created_at、deadline_at、runner_execution_id、snapshot_statesを必須とする。snapshot_statesは全組み合わせのsnapshot_id/target_id/device_id/statusのみを持つ最大1000件。suiteはid/name。referenceはmode（pinned/previous/specific）、run_id（整数/null）、snapshots（全組み合わせのtarget_id/device_id/baseline_snapshot_id/reason）。reasonはnullまたはno_reference/new_target/new_device/incompatible/missing/corrupt。保存Manifestの固定部分を使い、runのstatus/Execution/snapshot_statesだけ最新DBから応答時に組み立てる。固定保存データを書き換えない。

以下は参照あり・1 Target×1 Deviceの完全なManifest例。

```json
{
  "schema_version": 1,
  "run": {
    "uuid": "7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87", "suite_id": 1,
    "status": "running", "created_at": "2026-10-08T03:00:00Z",
    "deadline_at": "2026-10-08T04:30:00Z", "runner_execution_id": "execution-1",
    "snapshot_states": [{ "snapshot_id": 51, "target_id": 1, "device_id": 1, "status": "PENDING" }]
  },
  "suite": { "id": 1, "name": "公開ページ" },
  "targets": [{ "id": 1, "url": "https://staging.example.com/", "label": "トップ", "object_id": null, "post_type": "" }],
  "devices": [{ "id": 1, "name": "Desktop", "slug": "desktop", "viewport_width": 1440, "viewport_height": 900, "device_scale_factor": 1, "is_mobile": false, "has_touch": false }],
  "settings": {
    "settings_version": 1, "navigation_timeout_ms": 30000, "image_timeout_ms": 10000,
    "lazy_load": true, "concurrency": 2, "pixel_threshold": 0.2,
    "review_threshold": 0.001, "changed_threshold": 0.01, "ignore_selectors": []
  },
  "reference": {
    "mode": "previous", "run_id": 8,
    "snapshots": [{ "target_id": 1, "device_id": 1, "baseline_snapshot_id": 41, "reason": null }]
  },
  "allowed_origins": ["https://staging.example.com"]
}
```

Progress例とComplete例。versionsはrunner/playwright/chromiumの非空Version文字列各100文字以下、初回認証済み報告でenvironment_version=1のRun環境に補完する。後続Job retryも一致必須。Execution IDはManifest取得で固定した値を再検査する。

```json
{
  "schema_version": 1, "runner_execution_id": "execution-1",
  "versions": { "runner": "0.1.0", "playwright": "1.64.0", "chromium": "150.0.0.0" }
}
```

```json
{
  "schema_version": 1, "runner_execution_id": "execution-1",
  "versions": { "runner": "0.1.0", "playwright": "1.64.0", "chromium": "150.0.0.0" },
  "outcome": "finished", "error_code": null, "error_message": null
}
```

```json
{
  "schema_version": 1, "run_uuid": "7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87",
  "status": "complete", "total_snapshots": 1, "completed_snapshots": 1,
  "error_snapshots": 0, "pending_snapshots": 0,
  "completed_at": "2026-10-08T03:02:00Z", "error_code": null
}
```

outcome=failedではRun全体のerror_code（RUN_ABORTEDなど）と定型error_message必須、正常finishedでは両方null。finishedは成功件数>0かつERROR=0でcomplete、成功>0かつERROR>0でpartial、成功=0でfailed。CHANGEDは撮影成功でありRun失敗ではない。

## multipart・PNG・Snapshot一意性

UploadのContent-Typeはmultipart/form-data。各scalarフィールドはsnapshot-resultの項目を1回ずつ送る（画像以外の上記必須項目全部）。JSON部品は使わない。整数は符号・先頭ゼロのない十進（0または正数）、booleanは `true` / `false`、比率は0〜1の小数（小数点以下最大15桁、指数形式不可）。nullable値だけ文字列 `null` を受け付ける。未知/重複フィールド、配列記法、重複file、数値の部分parseは拒否する。PHP標準のフォーム展開は重複キーを失うため、それだけで重複検証済みとはしない。実装ではPHP自動展開前のraw multipartを取得できる専用入口とサイズ制限付きparserを用意し、取得不能な環境はUpload非対応とする。duration_msは0〜5400000、http_statusは100〜599またはnull。初回Progressでversionsを補完してからUploadし、未補完のUploadは409で拒否する。

| status条件 | 必須画像と結果値 |
|---|---|
| CAPTURED | reference.run_id=null。image必須、width/height正整数、dimension_changed=false。Baseline/比較値とエラー/no_baseline_reasonはnull、diff_imageなし |
| NO_BASELINE | reference.run_idあり、固定参照なし/取得不可。image必須、width/height正整数。Baseline/比較値はnull、dimension_changed=false、理由new_target/new_device/incompatible/missing/corrupt必須。エラーnull、diff_imageなし |
| UNCHANGED / REVIEW / CHANGED | 固定Baseline取得成功。image/diff_image必須、両方の寸法・diff_pixels/total_pixels/diff_ratio必須、エラー/理由null |
| ERROR | error_code/定型error_message必須。image/diff_imageを送らない、寸法/比較値はnull、dimension_changed=false、no_baseline_reason=null。duration_ms必須、http_statusは分かる場合だけ |

Baseline欠損を無差分0へ変換しない。NO_BASELINEで固定Snapshotがある場合、missing/corruptはWordPress側Storage検査でも確認できることを必要とする。正常な固定Baselineを任意に省く要求は400。incompatibleは開始時の固定判定と一致必須。Baseline読取失敗が一時的503なら限定再送し、欠損扱いにはしない。

画像検証のMVP上限は1ファイル20MiB、Upload全体42MiB、scalar合計64KiB、1辺16384px、面積40,000,000px。Webサーバー/PHPのpost_max_size・upload_max_filesize・メモリ上限も合わせ、body欠落をERROR Snapshotへ読み替えない。画像をデコードする処理は画素数に比例するメモリを確保できることが必要で、不足環境は開始時の診断で拒否する。

- PHP upload error・実際のサイズ・PNG signature・MIME（finfo）・IHDRの寸法/上限を検査した上で完全decodeする。Content-Type、拡張子、IHDRだけでは許可しない。破損/末尾の非PNGデータ/アニメーションPNGを拒否する。PNG decoderとCRC/チャンク検査を実装時に選定し、PHP 7.4で検証する。クライアントのファイル名/パスを使わない。
- current PNGの実寸とwidth/heightを一致させる。固定Baselineの実寸とbaseline_width/heightも一致させる。diff PNGの寸法は `max(width,baseline_width) × max(height,baseline_height)`、total_pixelsもこの積、dimension_changedは寸法差と一致。正規化後のdiffにも面積上限を適用する。
- diff_pixelsは整数0〜total_pixels、ratioは `abs(diff_ratio - diff_pixels/total_pixels) <= 1e-10`、statusは固定閾値からWordPressでも再判定する。保存ratioは検査後に分数からdecimal(12,10)へ丸める。WordPressでpixelmatch再計算はしないため、差分画素数の真実性は信頼されたRunnerが担う。
- 各PNGのSHA-256をサーバーで計算し、metadata_version=1と正規化した結果値・画像digestから結果digestを作る。キー順を固定したJSON、整数、ratioは検証後の保存精度を使う。multipart境界・filename・再送時刻・HTTPヘッダーをdigestに含めない。

例としてUNCHANGEDのscalarは次の値を各multipart部品へ送る。imageとdiff_imageにはそれぞれ1440×900のPNGを添付する。

```json
{
  "schema_version": 1, "target_id": 1, "device_id": 1, "status": "UNCHANGED",
  "width": 1440, "height": 900, "baseline_width": 1440, "baseline_height": 900,
  "dimension_changed": false, "diff_pixels": 0, "total_pixels": 1296000,
  "diff_ratio": 0, "duration_ms": 3000, "http_status": 200,
  "error_code": null, "error_message": null, "no_baseline_reason": null
}
```

### 再送・Progress・Complete

一意キーは `(run_id,target_id,device_id)`。Run作成時に全pending Snapshotを作り、Manifest外の組み合わせは拒否する。UPSERTはpending→終端だけを更新し、終端の同一digestは元の行を返す、異なるdigestは409。error→成功への差し替えも拒否し、新Runで再撮影する。

成功件数はcaptured/no_baseline/unchanged/review/changedのCOUNT、エラーはerrorのCOUNT。受信回数を加算しない。Progressの重複/逆順で件数が増減せず、成功+エラー+pending=total。Runがrunningの間は同一Upload再送を許可し、Complete後のUpload/Progressは同一でも409。再開はManifestのsnapshot_statesからpendingだけを撮影する。応答喪失時は同じバッファ/結果を再送する。

CompleteはSuite→Run→Snapshotの先行設計のロック順でCOUNTと状態を確定。finishedかつpending>0は409。failedは残るpendingをRUN_ABORTEDのERRORに確定する。受理したCompleteの正規化digest（Execution/versions/outcome/errors）と最終応答をRunのenvironment JSON内 `completion` に保存する【先行設計への追加】。環境Version補完とこの制御情報だけが書換可能で、開始時の環境値は変更しない。新しいテーブル/列は追加しない。同一Completeには元のstatus/completed_at/集計を200で返し、違うCompleteには409。Deadline処理等で閉じたRunには受理済みCompleteがなく、Tokenも失効する。200応答前の障害でもcommit済みdigestで同一再送を識別する。

## 非公開Storageと競合・部分ファイル

保存先は現在サイトのwp_upload_dir().basedir配下 `od-visual-regression/suite-{uuid}/run-{uuid}/target-{id}/`。Media Libraryへ登録せず、URLをDB/APIへ保存しない。DBにはStorageルート相対パスだけ。ルート/各祖先のsymlinkを禁止し、realpath境界・固定UUID/整数/slugを検証する。0700ディレクトリ/0600ファイルは補助であり、HTTP公開拒否の代わりではない。

### Apache・Nginxの実行環境要件

Apache 2.4は実Storageパスをサーバー設定で拒否する。以下は設置例であり、現在のサイトの実パスに置き換える。`Require all denied` は [Apache公式](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#reqall)に従う。

```apache
<Directory "/var/www/site/wp-content/uploads/od-visual-regression">
    Require all denied
</Directory>
```

.htaccessを使う環境では同ディレクトリに `Require all denied` を置き、AllowOverride AuthConfigまたはAllowOverrideList Requireが有効で、上位設定により解除されないことを確認する。index.phpやディレクトリ一覧停止だけではPNGを保護できない。

Nginxは.htaccessを解釈しないため、実際のuploads URL prefixを明示的に拒否する。`^~`によるprefix優先は [Nginx公式](https://nginx.org/en/docs/http/ngx_http_core_module.html#location)に従う。

```nginx
location = /wp-content/uploads/od-visual-regression { return 403; }
location ^~ /wp-content/uploads/od-visual-regression/ { return 403; }
```

Multisiteの `/wp-content/uploads/sites/{blog_id}/od-visual-regression/`、uploads URL変更、alias、CDN、リバースプロキシの別ホストにも実際の対応prefixと実パスの拒否を設定する。汎用サンプルをそのまま設置済みと見なさない。CDNのuploads自動同期からStorageを除外し、過去コピーがあれば削除する。

製品実装は秘密でないランダムcanaryをStorageに置き、匿名HTTPで直URLを取得できないことを確認してからStorageをreadyにする。canary取得が200、通信失敗、Basic認証の401だけ、WAFだけの拒否など、Storage自身の拒否を確認できない場合はreadyにしない。運用者はBasic認証を通した場合も403/404になることと、別ホスト/alias/Multisite経路を確認する。診断不能なら新規Run/Uploadを停止し、非公開と表示しない。Web設定の継続性、CDNの将来変更はPlugin単独では保証できず、定期診断と運用上の再確認が必要。

### 保存手順

1. ロック外で認証・サイズ・画像・結果を検証し、同じファイルシステムの非公開 `.staging/{run-uuid}/{request-uuid}` にランダム名で保存する。公開OS一時領域へ長く置かず、検証失敗の一時ファイルは削除する。
2. Suite→Run→Snapshotをロックし、Token/期限/running/固定組み合わせ/digestを再検査する。同一再送はstagingを消して既存応答、異なる内容は409。ファイル検証中のDeadline/削除/別Uploadをこの段階で再確認する。
3. 相対パスはサーバーで `target-{id}/{slug}-{result_digest}.png` と `{slug}-{result_digest}-diff.png` を生成し、同一filesystemのrenameで確定する【仕様§34のファイル名例への追加】。digest付き不変名により、先行Uploadや読取中の画像を上書きしない。DB行が参照していないファイルは認証配信しない。
4. PNGの確定後にpending行を条件付き更新し、metadataへdigest、COUNTを更新してCOMMIT。二つのrenameの間やCOMMIT前に障害が起きても、DBがpendingなら部分画像を結果として配信しない。応答はCOMMIT後だけ。DB確定後は同名の再送でrenameしない。
5. 検証失敗/rollback/プロセス異常のstaging・孤立ファイルはcleanup対象。1時間以上未更新かつRunの書込ロックを獲得し、最新DBから未参照と確認したものだけを消す。書込側もロック獲得後にstagingの存在を再検査し、cleanupと競合すれば503として同じ結果を再送する。deleting RunはRetentionの削除処理が全ディレクトリを処理する。

DBトランザクションとfilesystemは一体ではない。digest付き名・配信前のDB確定確認・未参照cleanupで整合を回復する。ローカルの同一filesystem/atomic rename/ファイルロック対応を必須とし、uploadsをオブジェクトストレージへ置換するPluginや外部同期のある構成はMVP対象外。fsync等を含む停電耐性は実装環境で検証し、電源断後の完全永続性を本書だけで保証しない。

### 認証配信・閲覧と削除

管理画面は `fetch(image_endpoint, {credentials:'same-origin',headers:{'X-WP-Nonce':nonce}})` でPNGをBlobとして取得し、`URL.createObjectURL()` をimgに指定する。画像切替/画面終了時はrevokeする。nonce/Token付きimg URL、Public URLへのリダイレクト、X-Accel-Redirectによる未設定の内部配信は行わない。

配信はpermission callbackで認証後、Snapshot/Run/固定参照・deleting状態を検査し、Storage共有lockを獲得して状態を再検査、実ファイルをopen・decode/digest検査してからPHPでstreamする。`Content-Type: image/png`、`Content-Length`、`X-Content-Type-Options: nosniff`、`Cache-Control: private, no-store`。エラー応答もno-store、共有cache/CDNはREST画像とRunner全ルートをcache対象外にする。Range/条件付き304はMVPで提供せず、認証前のキャッシュ応答を避ける。

Storage lockファイルはRunディレクトリの外側（サイトの非公開 `.locks/run-{uuid}.lock`）に保持し、読取中のinodeを削除/再作成しない。削除は先行設計どおりDBでdeleting/Token失効を確定してから、DBロック外でStorage排他lockを取り、既存stream終了を待って削除する。読取は共有lock取得後の再検査でdeletingなら404。すでに認証されstream開始済みの読取は完了を許し、新規読取は拒否する。長時間stream/lock timeoutは削除失敗としてdeletingを維持し再試行する。共有lock保持中にSuite/RunのDB書込lockは取得しないため循環待ちを避ける。

保持RunからBaseline参照があるRunはRetention保護対象。読取失敗を別Run画像へ差し替えない。ディスク障害/ファイル欠損は404/503を明示し、修復または新Runが必要。削除は画像→Snapshot→Run、失敗はdeletingから再開する。

## HTTP Basic認証と秘密情報

WordPress側は仕様の `ODVR_HTTP_AUTH_USER` / `ODVR_HTTP_AUTH_PASSWORD` と、追加の `ODVR_HTTP_AUTH_ORIGIN` をwp-config.phpまたは運用Secret injectionで受け取る。両方未設定なら無効、片方だけ/空値/Origin不正はRun開始を拒否する。Originは同じサイトの許可されたHTTPS Origin1つで、RunnerのhttpCredentialsはそのOriginに限定する。複数Originの資格情報・ログイン後撮影は対象外。

Basic認証はDispatcherへ渡さない。通常Manifest、result.jsonのconfiguration、Environment、DB履歴、Options、URL、Progress/Complete、Job引数/環境overrideにも保存しない。Runnerは自RunのCredentials APIからTLSで取得し、メモリ内でBrowserContextへ適用、実行終了時に参照を破棄する。Credentialsは期限内runningで再取得できるが、開始時の秘密を履歴固定しないため実行中の運用Secret変更は撮影失敗になり得る。変更は実行停止中に行う。

Credentials例（例示用の値）：

```json
{
  "schema_version": 1,
  "http_auth": { "origin": "https://staging.example.com", "username": "capture-user", "password": "example-only" }
}
```

PHP/Dispatcher/Runner・APM・proxyのbody/Authorization/credentialsログを無効化し、ブラウザtrace/HAR/video/crash dumpもMVPでは保存しない。例外をそのままerror_messageへコピーしない。秘密を含む処理のrequest_idと定型codeだけを残す。wp-configとSecret基盤自体のアクセス・バックアップ保護はホスト運用の責務。

サイト全体のBasic認証とRunner Bearerは同じAuthorizationヘッダーを同時使用できない。運用環境は `/wp-json/odvr/v1/runner/`（query形式rest_routeを使うなら同等経路）をフロントのBasic認証から除外し、PHP側のRun Token認証を必須とする。管理APIや撮影URLのBasic保護は維持する。除外が設定できない環境ではRunner連携を不可と診断する。callback HTTPS証明書検証を無効化しない。

## 変更予定ファイルと5段階の実装順序

採用後の実装Issue案。PHPのパスは `wordpress/od-visual-regression/` を基準とする。DB/Run実装（#2の設計に基づく製品実装）の完了後に結合検証する。

| 順序 | 実装範囲・予定ファイル | 必要な検証 |
|---|---|---|
| 1 | 共通Schema/Version/型/エラー、`packages/schemas/src/{run-manifest,snapshot-result,dispatch-request,error,progress-request,complete-request,runner-credentials,run-state}.schema.json`、`packages/shared/src/index.ts`、`scripts/validate-schemas.mjs`、`includes/class-odvr-contract-validator.php`、Node/PHP共通fixture | 必須/未知/Version/数値文字列/null/ID境界/閾値/Unicode/条件分岐を両端で同じ判定。PHP 7.4・WP 6.7と既定版 |
| 2 | 管理API/Run固定化/Token、`includes/class-odvr-{suite,target,device,run,settings}-controller.php`、`class-odvr-run-manager.php`、`class-odvr-run-token.php`、`class-odvr-plugin.php`、`class-odvr-deactivator.php`、`tests/api-auth.php` | Cookie/nonce/権限、別サイト/別Suite、32bytes/Hashのみ/2時間境界、queued15分/総90分、無効化/失効/終端scope |
| 3 | 非公開Storage/画像配信、`includes/class-odvr-storage.php`、`class-odvr-image-controller.php`、`class-odvr-retention.php`、`tests/storage.php`、Web設定手順文書 | Apache/Nginx/Multisite/alias/CDNの直URL、Blob認証、symlink、PNG/CRC/digest/寸法/サイズ、部分rename/rollback/cleanup、読取と削除lock競合 |
| 4 | Runner API/Upload/COUNT/Completeとクライアント、`includes/class-odvr-runner-controller.php`、`class-odvr-snapshot-repository.php`、`apps/runner/src/jobs/execute-run.ts`、`src/api/client.ts`、`src/browser/context-factory.ts`、`tests/runner-api.php`、Runner契約テスト | Baseline固定scope、multipart重複/ERROR/比率、同時同一/異なるdigest、Progress逆順/再送、Complete早着/重複/異なる要求、期限とUpload競合、Job retry、Basic非漏洩 |
| 5 | Dispatcher/秘密引渡し/クラウド結合、`apps/dispatcher/src/{index,auth,jobs}.ts`、`infra/`のSecret/IAM/Job設定、Dispatcher契約テスト、`docs/security.md` | raw-body HMAC/±300秒/登録callback/replay、受付不明と照合/起動中断、Execution固定、Run Secret参照・期限cleanup・IAM、Basic保護サイトのBearer経路、全ログ/履歴に秘密がないこと |

## 受け入れ条件と今回の検証

| Issue #4の受け入れ条件 | 本書の対応 |
|---|---|
| API例・Schema・Versionの責務 | 共通Schema、管理API、Dispatcher、Manifest/Progress/Complete/Upload/Credentialsの例 |
| Token生成・期限・Run/Baseline境界・失効 | Run Tokenの生成/Hash、状態別scope、固定Baseline検査、終端Complete限定再送 |
| Snapshot一意性・集計・完了条件 | multipart条件表、digest、pending UPSERT、COUNT、Complete digest/早着/重複 |
| 画像直URL防止・環境要件・認証配信 | Apache/Nginx/canary、Blob取得、lockと保存/削除順序 |
| Basic認証非漏洩 | config専用・Credentials API・メモリ受渡し・ログ停止・Bearer経路除外 |
| 5項目以内の実装順・ファイル・権限/期限/再送検証 | 上記5段階表と次のリスク表 |

今回は指定仕様・現行Schema/shared/execute-run/securityと、直接依存するconfig/context-factory/compare、先行DB設計、既存content API契約を照合する。文書のJSON例の構文、ローカルリンク、空白、docsのみの変更を機械確認する。製品コード・Schemaに変更がないため全体ビルド/製品全テストは実行しない。認証・Web設定・DB/ファイル原子性・クラウド動作を実機で検証済みとは扱わない。

## リスク・未決事項

| 項目 | 決定・限界・後続で確認すること |
|---|---|
| 先行設計との接続 | 完了digest/応答をenvironment.completionへ追加、ファイル名をdigest付き不変名へ拡張。実装Issueで#2との対応を明記する。DBの5テーブルや終端後Upload禁止は維持 |
| 比較互換性 | URL/Device/Mask/Lazy Load等の撮影条件一致は開始時検査。厳密なfingerprint/Runner Version互換性は#6で確定し、結合実装前に反映。画像寸法差は比較可、閾値変更は互換性を壊さない |
| クラウド受付/秘密 | Dispatcher永続記録・起動照合・Run専用SecretのTTL/IAM/削除実装は#10で選定・検証が必要。Cloud Run全体retryも同一Executionであることを実機確認。平文Tokenを通常履歴へ保存する代替は禁止 |
| 非公開Storage環境 | Web設定・CDN除外はホスト側必須。Pluginは全aliasやCDN将来変更を保証できない。canaryだけでなく運用確認も必要。非対応filesystem/公開拒否不能なら実行不可 |
| PNG検証/メモリ | decoder/チャンク検査の実装選定とPHP 7.4対応は手順3で確認。上限を満たすPHPメモリが必要、寸法検査だけで完全decodeを省略しない |
| SSRF/DNS rebinding | 現行ガードの限界は[security.md](security.md)のとおり。#5の接続固定・非公開/Metadata遮断の実装・検証までCloud Run公開不可。localhost例外・汎用解除は追加しない |
| 秘密ローテーション | Basicの実行中変更をサポートしない。Shared Secret切替は送信を停止し両端を更新。TTL内Token流出はRun限定でも影響があるため秘密基盤/ログ抑制と即時失効を検証する |

Firefox、ログイン後撮影、通知、AI解析は対象外。設計採用前に実装Issueを追加せず、採用と製品の実装完了は分けて管理する。
