# 管理 API・Run Token・署名 Dispatch

Issue #32 の実装。共通契約は [api-contracts.md](api-contracts.md)、保存処理は [runs-and-baselines.md](runs-and-baselines.md) と [retention-and-uninstall.md](retention-and-uninstall.md) を参照する。Runner の Upload・Baseline PNG・Progress・Complete は [#33の実装](runner-results-api.md)、実 Dispatcher は #37、ローカル全体接続は #38、クラウド実機は #39 で行う。

## 管理 API

`/wp-json/odvr/v1` の Suite・Target・Device・Run・Baseline・Settings を既存 Repository へ接続する。Cookie、`X-WP-Nonce` の `wp_rest` nonce、現在サイトの `manage_odvr` を必須とする。URL の nonce、Bearer、Application Password は管理認証に使わない。Frontend の Basic header がある場合も Cookie・nonce・権限で管理認証し、Basic だけでは許可しない。body は JSON object のまま共通 Schema で検証し、数値文字列や未知フィールドを補正しない。URL の ID と body/query の ID を混ぜない。

一覧は page=1 / per_page=20、per_page 最大 100。ID 昇順、Run は作成時刻・ID 降順。`X-WP-Total` / `X-WP-TotalPages` を返し、範囲外のページは 400。Suite 一覧は status=all/active/archived、Target 一覧は include_disabled=true/false（既定 false）を指定できる。不要な query、別サイトの指定は拒否する。

作成は 201、更新・アーカイブ・無効化・Baseline 昇格は 200。Run 作成は保存時点の queued と UUID・期限を 202 で返す。Dispatch 中に Runner が開始・完了した場合や明確な拒否があった場合も、最新状態は Run の GET で確認する。作成応答に Token・Hash・秘密を含めない。Run の DELETE は保護再検査後に 202 deleting を返し、削除処理を Cron へ予約する。別 Suite の Target と Baseline 指定は 404。

Suite の集約情報、Device の参照 Suite、固定 Run の Environment・進捗・保護理由、Snapshot の PENDING と画像有無を返す。画像有無は保存された参照の有無であり、後からファイルが消えた場合は画像取得の 404 で確認する。画像のパスや公開 URL は返さない。Settings は保持 Run 数、削除失敗数、私有領域の使用バイト数も返す。容量走査は変更を行わず、10,000 エントリ・2 秒・整数上限・I/O の制約に達した場合は null（不明）にする。

ODVR の新規エラーは共通 error 契約と非秘密の request_id を返す。429/503 には Retry-After を付ける。WordPress コア認証エラーは core 形式を維持する。管理応答、Runner 応答、エラーを no-store / nosniff にする。

## 登録先と config 専用の秘密

Dispatcher URL は運用者が config に登録した 1 件を用いる。稼働 Run がある間は登録 URL・site_id の変更を拒否する。管理 API の Run 作成は Settings 行を Suite より先にロックし、読み込んだ設定の digest が変わっていれば保存前に 409 で拒否する。Settings の dispatcher_url がこの登録値と異なる場合は保存・送信を拒否する。Shared Secret と HTTP Basic は Settings の PATCH で登録せず、平文を GET しない。

```php
define( 'ODVR_DISPATCHER_URL', 'https://dispatcher.example.com' );
define( 'ODVR_DISPATCHER_SHARED_SECRET', getenv( 'ODVR_DISPATCHER_SHARED_SECRET' ) );
// Basicが必要なFrontendだけで設定する。不要なら3項目とも定義しない。
define( 'ODVR_HTTP_AUTH_USER', getenv( 'ODVR_HTTP_AUTH_USER' ) );
define( 'ODVR_HTTP_AUTH_PASSWORD', getenv( 'ODVR_HTTP_AUTH_PASSWORD' ) );
define( 'ODVR_HTTP_AUTH_ORIGIN', 'https://frontend.example.com' );
```

Shared Secret は 32 bytes 以上。各定数は Multisite のサイト ID をキーとする配列にも対応する。配列でそのサイトの値がない場合は未設定として扱う。Basic の一部だけの設定、空値、不正 Origin は実行不可。保存するのは非秘密設定と固定 Origin だけ。Origin が実行開始後に変更された場合は、その Run に別 Origin の Credentials を返さない。

site_id は Settings で保存する非秘密の登録識別子（既定 site-{WordPressサイトID}）。Dispatcher 側の登録と一致させる。callback_base は現在サイトの `rest_url('odvr/v1/runner')` から求め、body で上書きしない。HTTPS と query なしの REST URL、Authorization が PHP へ届く設定を必要とする。TLS を終端する reverse proxy の環境でも、WordPress が信頼した設定で HTTPS を認識することが必要。

## Run Token と Runner 取得入口

Run Manager が random_bytes(32) の base64url 43 文字を発行し、SHA-256 Hash と作成から 2 時間の UTC 期限だけを Run に保存する。Token は現在の要求メモリ内で Dispatch へ送る。履歴・Options・管理応答へ平文を保存しない。

Bearer header、現在サイト、Run UUID、Hash の定数時間比較、TTL、停止状態と Run の状態を専用の `ODVR_Runner_Auth` で検証する。Runner の HTTP 入口は HTTPS 必須。Token を URL・body・Cookie から取得せず、WordPress ユーザー権限へ変換しない。

| Run 状態 | Token の操作範囲 |
|---|---|
| queued | Manifest だけ |
| running | 自 Run の Manifest / Credentials / Baseline / Upload / Progress / Complete |
| complete / partial / Runner報告failed | 同じ内容の Complete 再送だけ。内容一致は Run Manager で再検査 |
| deleting / 管理側failed / 期限切れ / 無効化 | 全拒否 |

`GET /runner/runs/{uuid}/manifest` は `X-ODVR-Execution-ID` を必須にし、最初の取得で running・Execution を固定する。別 Execution は 409。`GET /runner/runs/{uuid}/credentials` は running だけで Basic を別応答にする。Runner のブラウザ向け CORS 公開を無効にする。期限認証は Cron に依存せず、期限処理の Cron でも終端後の期限切れ Hash を最大 25 件回収する。

## Dispatch と受付不明

固定 Manifest・pending・Token Hash の COMMIT 後に `POST /v1/jobs` へ送る。`X-ODVR-Timestamp` と `X-ODVR-Signature` は `HMAC-SHA256(timestamp + "\n" + raw_body, shared_secret)`。HTTP の body をそのまま署名し、リダイレクト・Cookie・Basic の送信を禁止する。TLS 検証、10 秒 timeout、応答 16 KiB 上限を適用する。

契約・site_id・UUID が一致する 202 accepted / 200 started だけを受理する。明確な 400/401/403、契約が一致する retryable=false の起動失敗 503 は failed と Token 失効を確定する。409、応答喪失、未知 body、リダイレクトなどは既存受付の可能性を残して queued を保持する。

受付不明の場合は同じ PHP 要求メモリ内で、1 秒と jitter の待機後に同じ body を 1 回だけ再送する。別 Run・Token・body を作らない。送信・再送直前にも停止状態・Token・期限を確認する。後続の Run GET は署名付き `GET /v1/jobs/{uuid}?site_id=...`（空 body）で照合するだけ。未受付や不明のままなら queued 期限で失敗を確定する。取得できた Execution を固定し、無断の再 Dispatch は行わない。受付と起動の重複防止の永続台帳は Dispatcher 側 #37 の責務。

## 接続診断と検証範囲

Runner経路のBasic除外・Bearer転送・生multipart対応も固定callbackで検査する。[経路設定](runner-results-api.md)を参照する。POST `/settings/connection-test` は `{schema_version:1}` だけを受け、任意 URL や Token を拒否する。保存済み設定・秘密設定・DB/Storage の公開拒否診断を確認し、登録 Dispatcher の `/v1/connection-test` へ署名して送る。Run・Job・Run Token を作らない。DB の原子的な比較で診断間隔を 30 秒に制限する。

`tests/admin-api.php` は WordPress の REST ディスパッチと実 DB・PNG を使い、Cookie/nonce/権限、CRUD・ページング、別 Suite/別サイト、秘密 PATCH、設定有無・使用量、Baseline 昇格と保護、Token TTL と終端再送、HTTP 拒否、CORS 抑止を確認する。PHP 7.4・WordPress 6.7 と最新版、Multisite で CI 実行する。

Dispatcher と Storage 診断の HTTP 境界は fixture で署名を検証し、応答喪失・同一再送・照合・明確な拒否を注入する。HTTPS の実ネットワーク、実 Dispatcher の永続台帳・Job 起動、外部 proxy の Authorization 転送はこの試験では確認していない。Storage の公開拒否は既存の [実機検証](private-storage.md) を維持する。後続のローカル・クラウド結合検証で通信を通す。
