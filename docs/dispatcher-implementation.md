# Dispatcher 製品入口

Issue #37 の実装。`npm run build` の後、引数なしの `npm run dispatcher` で開始する。`PORT`（既定8080）と、運用者が配置する `ODVR_DISPATCHER_CONFIG`（既定 `/etc/odvr/dispatcher.json`）を使う。設定は通常ファイル、所有者が現在のユーザーまたはroot、他者から書込不可、最大64 KiBとする。秘密そのものは設定に入れない。

## 固定登録設定

次の値はすべて例示用。実際の project、Secret の数値 version、Job generation、digest、Scheduler identity に置き換える。Shared Secret 用 project は Run Secret 用 project と分離する。

```json
{
  "schema_version": 1,
  "profile": "cloud",
  "sites": {
    "example-site": {
      "callback_base": "https://example.com/wp-json/odvr/v1/runner",
      "shared_versions": ["projects/111111111111/secrets/odvr-shared/versions/1"],
      "rotation_until": null
    }
  },
  "firestore": {"project": "odvr-example", "database": "(default)"},
  "secret_project": "222222222222",
  "job": {
    "name": "projects/odvr-example/locations/asia-northeast1/jobs/odvr-runner",
    "container": "runner",
    "image": "asia-northeast1-docker.pkg.dev/odvr-example/images/runner@sha256:1111111111111111111111111111111111111111111111111111111111111111",
    "generation": "1",
    "runner_service_account": "runner-sa@odvr-example.iam.gserviceaccount.com"
  },
  "scheduler": {
    "audience": "https://dispatcher.example.com/internal/reconcile",
    "subject": "123456789012345678",
    "email": "worker-sa@odvr-example.iam.gserviceaccount.com"
  }
}
```

Cloud profile は emulator、local 例外、長期認証ファイル、SDK debug/trace、別 universe/mTLS endpoint の環境設定を拒否する。Google SDK は公式の固定 endpoint、5秒 RPC、POST 自動 retry なしを使う。Firestore の二文書 transaction 内では外部 API を呼ばない。

Job は登録 generation/digest、単一 container、Task1、parallelism1、専用 SA、GEN2、timeout1800秒、retry1、CPU2、memory2Giを検査する。command は `node apps/runner/dist/job.js`、args は空とする。静的 env は空、または固定 `/etc/odvr/runner.json` の `ODVR_JOB_CONFIG` と `NODE_ENV=production` のみ。環境 override は site/Run/callback/数値 Secret version/元の秘密期限/attempt ID の6値だけ。Run Token と Basic 認証情報を渡さない。

## HTTP と認証

| 経路 | 処理 |
| --- | --- |
| `POST /v1/jobs` | 共通 dispatch-request。raw UTF-8 body 最大16 KiB。timestamp＋改行＋raw body の HMAC、±300秒、登録 site/callback/Versionを検証する。 |
| `GET /v1/jobs/{UUID}?site_id={site}` | 同じ HMAC、body は空。既存記録を読み取る。起動・更新しない。 |
| `POST /v1/connection-test` | Token/Runなしの共通診断契約。台帳の到達、固定Jobの構成、WordPressのBearer認証入口を確認する。Secret作成・Job起動をしない。 |
| `POST /internal/reconcile` | bodyなし。Scheduler OIDC署名・issuer・audience・subject・email・email_verifiedを確認し、照合と削除を行う。外部HMACでは利用できない。 |

JSON重複キー（escape相当を含む）、不正UTF-8、圧縮body、過大body、重複認証header、重複queryを拒否する。エラーは共通 error 契約・定型日本語メッセージ・no-store。ログはrequest_id/status/定型codeだけで、例外原文・body・header・Token・秘密参照を出さない。

callback診断は #34 の用途別固定 HTTP transport を使う。ランダムな不存在UUIDとダミーBearerによる固定 Manifest GET が、401・製品認証エラー・gateway/raw multipart/Bearer受け渡しの3headerを返すことを確認する。redirect、Cookie、Basic、別origin、DNS再解決による接続変更を許可しない。

## 永続受付と復旧

Firestore の `odvr_acceptance`（site/UUIDのSHA-256キー）と `odvr_sites`（siteのSHA-256キー）を同じ transaction で保存する。元のbodyや秘密を保存せず、digest、状態、時刻、固定image/generation、Secret version、attempt/Operation/Execution、lease、定型codeを保存する。新規受付はsiteごと10件/分、未終端2件。完全同一bodyの再送は同じ記録を返し、別digestは409。署名時刻を更新しても元の秘密期限を延長しない。

60秒leaseと単調増加generationを確定し、所有者/generation/期限が一致した更新だけを許す。Secret create/version/IAMの各段階を先に保存する。version追加の応答不明はmetadataだけから回復し、追加POSTを再送しない。bodyを失ってversionがない場合は失敗とする。所有権・複数versionの不整合があれば起動を禁止する。

`launching` とattempt IDを先に確定してから、etag付きの固定Job起動を一度だけ送る。応答不明・起動前停止でも再起動せず、Operation、全ページのExecution候補を照合する。site/UUID/callback/Secret version/attempt/期限/image/Job resourceと作成時刻が一致する候補を採用する。0件・複数件・不完全な一覧は202の受付不明として保持する。Googleの起動APIにidempotency keyはないため、未撮影になる可能性を残して二重起動を防ぐ。

202は秘密versionと権限の準備が済んだ記録に限る。同じHTTP要求内の処理終了後に返し、応答後のメモリ処理へ依存しない。Schedulerは1分ごとにworkerを呼ぶ。workerは期限到来の記録を最大100件読み、最大25件を処理する。各記録の次回時刻を更新し、回収済み記録は30日保持してから削除する。Execution一覧は最大100ページ、時間上限10秒（進行中のRPCは最大5秒）で、不完全なら次回照合する。

Run Secretは元の署名時刻＋90分で固定し、所有ラベルと元digestを持たせる。Runnerのsecret単位Accessorに同じ期限条件を付ける。署名時計の±5分を含めRunner入口は最大95分先の期限メタデータを認めるが、HTTP・撮影は開始から最大90分とManifestの期限で打ち切る。

ExecutionのTask retryを含む終端を確認してから秘密を削除する。起動失敗は回収し、不明起動は期限まで照合する。削除失敗は次のworkerで再試行する。期限付き孤立Secretも、専用project・所有ラベル・名前・期限を再確認して削除する。秘密削除と台帳保存は独立し、30日以内の再送拒否記録を残す。画像やWordPressのRun集計は変更しない。

## local と検証範囲

local設定は同じ登録形式に `local` を追加する。`callback_destination` は単一RFC1918の固定 origin/address/port、`firestore` は固定emulator host/port、`shared_directory`、`run_directory`、`queue_directory`、`worker_key_file` は絶対パス。共有鍵のダミーファイル名は数値version resourceのSHA-256、Run秘密のファイル名はRunnerと共通の固定名。秘密ファイルは0600、専用directoryは0700、worker鍵は43文字のダミーURL-safe値を使う。Cloud Run環境ではlocalを起動しない。

local Job adapterは秘密を含まない永続queueだけを作り、launcherが開始を記録するまで202を返す。有限launcher・tmpfs・Firestore emulator・同一Imageのcompose接続は #38 で実装する。本番入口の台帳はFirestoreで、FileStoreは独立プロセスの停止・再開テスト専用（transaction中の強制終了lockの自動横取りはしない）。

`npm run test:dispatcher` は実ファイルの永続adapter、独立プロセス、HTTP server、公式SDK method fixtureを使い、実クラウドを作らず検証する。SDK fixtureは実GoogleのOperation可視性、IAM反映、quota、Schedulerの配信、Firestore emulatorの動作を実測したものではない。#38でlocal結合、#39で実Cloud Run・IAM否定試験を行う。
