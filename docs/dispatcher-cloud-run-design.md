# Dispatcher・Cloud Run・ローカルDocker・デプロイの設計

[Issue #10](https://github.com/Olein-jp/od-visual-regression/issues/10)（Phase 5）の設計成果物。根拠は[仕様v1.1](specification-v1.1.md) §7〜8・§12〜13・§38〜40・§58〜66。先行する[API・認証・Storage設計](api-security-storage-design.md)（#4）、[ネットワーク設計](network-security-design.md)（#5）、[Runの状態・期限設計](data-lifecycle-design.md)を引き継ぐ。採用候補の設計であり、合意後に実装Issueへ分割する。今回は設計文書のみ変更し、製品コード・Schema・クラウドリソースは変更しない。

## Issue #57 の費用・通信経路改訂（2026-10-10）

費用・資源・通信経路・利用上限・後続計画の正本を[無料枠クラウド設計](free-tier-cloud-design.md)へ移す。**候補未採用、#57未解決、構築停止**。既存HMAC/台帳/起動不明/秘密期限/最小IAMを維持し、account共通予約・cleanup余裕を#39で追加する。月8Run・最大6Snapshot・Task600秒/retry1・CPU2/2GiB、Dispatcher min0/max1・30秒/concurrency1は未合意の候補。§4.0仕様と正本の停止gateが優先する。

公開GHCR digestの直接配備（有料remote repositoryなし）、Direct VPC private-ranges-only（NAT/外部IPv4なし）を評価する。公開TCP/UDP/portへのVPC firewall制御を失うため旧all-trafficと同等ではない。北米内送信枠、共有残量、実機迂回拒否の成立前に公開しない。

以下の#10当時の調査表・Image資源表・all-traffic/NAT/Registry構築値・旧5項目計画は**旧設計の記録**であり、採用構成/実行手順ではない。これらの費用/通信値は上記正本で置き換える。製品実装済の範囲は末尾の#32/#36/#37/#38とリンク先で確認する。変更予定ファイルと検証の現行5項目計画も正本を参照する。

## 現状と推奨構成（#10当時、費用・経路は失効）

調査範囲は指定のRunner package/入口、CI、security、指定仕様と、直接依存するconfig・Context・executeRun・先行設計・wp-env設定・lockfileに限定した。

| 対象 | 現状 / 推奨方針 |
|---|---|
| `apps/runner/package.json`・`src/index.ts` | Version 0.1.0、ローカルManifest/出力/baselineを引数に取るCLI。製品APIを呼ばず、Cloud Run用の入口はない。既存CLIを維持し、Job用入口を追加する |
| `config.ts`・`execute-run.ts`・Context | Prototype Schemaで検証、ローカルPNG/JSON保存、設定値による並列撮影。Basicは現在環境変数からContextへ設定。製品版は#4/#5のAPI・Origin限定transport・pending再開へ接続する |
| Docker・Dispatcher・infra | 未作成。DispatcherはNode HTTP Service、Runnerは有限時間のJob。ブラウザを含まない別ImageをDispatcherに使う |
| `.github/workflows/ci.yml` | 全push/PRでRunnerとWordPressの検証。Image build・クラウド認証・デプロイなし。path別検証と明示デプロイを分離する |
| `.wp-env.json`・security | 既存wp-envを利用できるが内部IP撮影は未対応。ローカル例外とDNS接続固定は#5の実装完了が前提 |

WordPressがRun・Token Hash・画像・結果を管理し、Dispatcherは受付と起動、Runnerは撮影と比較を担当する。クラウドは環境別の専用projectにArtifact Registry、Cloud Run Service `odvr-dispatcher`、Job `odvr-runner`、Firestore受付台帳、Secret Managerを置く。Cloud Schedulerが1分ごとに認証付き内部照合・cleanupを呼ぶ。202返却後のService内バックグラウンド処理やメモリ内queueを永続性の根拠にしない。

## HMAC受付・callback・再送

外部入口はHTTPS `POST /v1/jobs`。#4の `schema_version: 1`、site_id、run_uuid、callback_base、runner_tokenを使い、未知キーを拒否する。Tokenは32 random bytesのpaddingなしbase64url43文字、UUIDはcanonical小文字v4。WordPressはRun作成と同じPHPリクエスト内で送信し、queued期限15分を超えたdispatchは禁止する。

1. Content-Type application/json、圧縮なし、raw body最大16 KiBをstream時点で検査する。重複認証ヘッダー、重複JSONキー、不正UTF-8、途中で切れたbodyを拒否。raw bytesを保持し、site_idを選ぶためのparse結果はまだ信用しない。
2. 登録済みsite_idからShared Secretの固定versionとcallback許可値を選ぶ。timestampは先頭ゼロなしUnix秒十進、signatureは小文字hex64桁。`abs(now - timestamp) <= 300`、`HMAC-SHA256(timestamp + "\n" + raw_body, shared_secret)`を等長bufferで定数時間比較する。JSON再エンコードや空白除去はしない。
3. 認証後にSchema/相互条件を検証する。callback_baseは登録された完全一致HTTPS値だけ（query/fragment/userinfoなし、固定Runner API prefix、redirectなし）。Job名/project/region、profile、Secret名、任意overrideはクライアントから選べない。登録値と実際の接続先は#5の用途別DNS/接続固定でも検査する。
4. Firestore transactionで `(site_id, run_uuid)` を一意に予約する。保存はrequest digest、状態、時刻、lease owner/generation、起動attempt ID、Secret参照/version、Operation名、Execution名、定型エラーだけ。body・Token・署名・Basicを台帳に保存しない。digestはraw bodyのSHA-256（Tokenは十分な乱数）でtimestampを含めない。

Shared Secretはsite別の32 random bytes以上。WordPressはwp-config/ホストの秘密注入、DispatcherはSecret Managerから読む。登録configにはcallbackとSecretの非秘密参照だけを保存する。rotation時は明示した新旧2 versionを最大10分だけ受け付け、台帳の再送判定はSecret versionでは変えない。未知site・秘密取得不可・署名不正の詳細を外へ返さず、秘密基盤障害は定型503、認証失敗は同じ401にする。時刻窓だけではreplayを防げない。

| 入力・状態 | 期待動作 |
|---|---|
| 正常HMAC、時差±300秒以内 | 永続受付と秘密準備が完了後202 accepted。初回応答はExecution未確定ならnull |
| bodyの1 byte改ざん、別siteの署名、±301秒以上、形式不正 | 401 `odvr_dispatch_unauthorized`、Secret作成/Job起動なし。±300秒ちょうどは許可 |
| 正しく署名された不正Schema / 登録外callback | 400 `odvr_invalid_payload`、副作用なし |
| 同じraw bodyのreplay・timestamp更新再送・同時POST | 同じ受付を返す。accepted/起動照合中は202、startedは200と同一Execution ID。新たな起動はしない |
| 同じsite/UUIDで異なるbody（Token変更や空白変更も含む） | 409 `odvr_dispatch_conflict`。WordPressは同一bodyのままtimestampと署名だけ更新する |
| 明確に起動失敗した受付への同一再送 | 定型503 `odvr_dispatch_unavailable`、retryable=false。自動再起動なし、新Runは管理者が作成する |
| 台帳/秘密基盤障害、受付の確定前 | 503 retryable=true、Retry-After。WordPressは同じメモリ内bodyを最大2回、1秒/3秒＋jitterで再送可。別PHPリクエストは照合のみ |
| 受付上限 | site別新規Run 10件/分・未終端2件を初期値にし、transactionで検査して429。既存受付の照合・同一再送は新規件数へ加算しない |

202例は `{"schema_version":1,"site_id":"staging-1","run_uuid":"7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87","status":"accepted","runner_execution_id":null}`。200ではstatus=startedとExecution名の末尾IDを返す。エラーは#4のerror形を使い、内部の起動照合中状態は外部ではacceptedとする。

`GET /v1/jobs/{run_uuid}?site_id=...` は#4どおり空raw bodyに同じHMAC。署名自体はpath/queryを含まないため、署名を検証したsite_idの記録だけを読める権限とし、UUIDごとの読み取り権限とは見なさない。未受付404、accepted=202、started=200、確定失敗=上表の503、Cache-Control: no-store。GETは状態を変更しない。内部照合入口はGoogle OIDCと専用service accountを追加検証し、公開HMACでは呼べない。

## 起動状態・Execution照合・期限

[Cloud Run v2 jobs.run](https://docs.cloud.google.com/run/docs/reference/rest/v2/projects.locations.jobs/run)はOperationを返し、実行時env overrideには `run.jobs.runWithOverrides` が必要。要求にidempotency keyや指定Execution名はない。このAPI形から、外部APIと台帳を一つのtransactionにできず、完全なexactly-once起動を保証できないと判断する。安全側に「不明な起動は再送しない」を採用する。

受付状態は `provisioning → accepted → launching → started`、明確な失敗はfailed、送信結果不明はlaunch_unknown。provisioningのまま落ちても台帳は残り、照合workerが秘密version/IAMの準備状況を確認してacceptedかfailedへ進める。202はTokenの永続化・version固定・IAM準備まで確認してから返す。

- 初回POSTは受付後、同じHTTP要求内で起動処理を試みる。間に合わなければ202で返し、Schedulerがacceptedを拾う。起動/照合workerは60秒leaseと単調増加generationをtransactionで取り、更新はowner/generation一致時だけ。Firestore transaction内では外部APIを呼ばない。
- `launching` と一意のattempt IDをcommitしてから、固定Jobへ`:run`を一度だけ送信する。SDK/HTTPのPOST自動retryを無効にする。overrideはsite_id、run_uuid、登録callback_base、Run専用Secretの数値version参照、attempt IDのみ。Token/Basic/Shared Secretは入れない。
- Operation名が得られたら即保存し、GET pollingでExecutionを確定する。Job完了までHTTP要求を保持しない。永続化前に落ちた場合はlaunch_unknownとし、leaseが切れても`:run`し直さない。APIを送る直前に落ちた場合も同じ安全側の扱いで、未実行Runになることを許容する。
- [Execution API](https://docs.cloud.google.com/run/docs/reference/rest/v2/projects.locations.jobs.executions)のlistを全page取得し、固定Jobと受付時間範囲の候補をGETしてtemplate内の非秘密attempt ID/site/UUID/Secret参照を完全一致で照合する。1件ならstarted、0件は可視性遅延と未送信を区別できないため不明を維持、複数は異常として通知し新規起動禁止。時刻や「最新Execution」だけで対応付けない。
- Operationの明確な作成失敗、またはAPIの認証/入力拒否はfailed。通信切断/timeout/5xxは起動済みの可能性があるためlaunch_unknown。started後のExecution失敗はdispatch失敗へ巻き戻さず、Runner/WordPressのRun状態で扱う。
- Runnerは[実行環境の `CLOUD_RUN_EXECUTION`](https://docs.cloud.google.com/run/docs/container-contract)を `X-ODVR-Execution-ID` に使う。Task retryでも同じID、別Executionは#4のManifest開始ロックで409。Cloud Run Task index=0、Task count=1を起動時に検査する。台帳には完全なresource名も保存するがWordPressへ送るIDは末尾名に統一する。
- 受付の非秘密記録は初回受付から30日保持し、期限を過ぎた同一UUIDの再利用を運用上禁止する。30日を超えた新しい署名要求への永久的な重複防止は保証しない。元の署名replayは時刻窓外、元Tokenは期限切れなのでWordPressは拒否する。秘密削除や起動lease失効で台帳を早期削除しない。

初期値はqueued15分・Run総90分・Token2時間を維持する。Task timeout30分、retry1回なので稼働は最大60分に待機/retry間隔が加わる。Cloud Runの待機上限は保証されず、15分でWordPressが開始を拒否、90分で終端/失効させる。Dispatcherは受付から10分を起動着手上限にし、以後は照合のみ。遅れたJobはManifest認証で止まり撮影しない。RunnerはManifest期限とToken期限の早い方で中断し、retryは同一Executionのpendingだけ再開する。回復可能な障害はComplete(failed)を確定せず非zero終了してretryへ渡し、非回復障害は定型failedを報告する。Complete後に終了応答を失っても同一Completeの再送だけを行う。Scheduler停止やWordPress停止でも期限は認証時に検査し、Cronの遅れで延命しない。

## 秘密の受け渡し・cleanup・ログ

| 秘密 | 保持と利用 |
|---|---|
| Shared Secret | WordPressホストとDispatcherだけ。site登録のSecret固定versionから読む。Runner・Manifest・通常envに渡さない |
| Run Token | WordPressのリクエストメモリ→TLS/HMAC POST→Run専用Secretの1 version→RunnerがAPIで読んだメモリ。WordPress DBはHash、Dispatcher台帳/Job overrideは参照だけ |
| HTTP Basic | WordPressホスト→認証済みRunner Credentials API→メモリ。#5の承認撮影Origin専用transportで注入し、撮影Origin以外やredirectへ転送しない |
| Google認証 | 実行service accountのADC。GitHubはOIDC/WIFの短期認証。サービスアカウント鍵JSONを保存しない |

Run専用Secret名はsite/UUIDから生成した非秘密の一意名とし、受付台帳と同じ名前へ固定する。作成時にexpireTimeを必須化する。#4の元Token期限を超えないため、WordPressは最初の署名timestampをRun作成後15分以内に生成し、Secret期限はそのtimestamp+90分に固定する。WordPressのToken2時間に対し15分以上の余裕を取り、最大5分の時刻差でも期限を越えない。再送timestampや受付時刻で延長しない。queued未開始や総期限の認証拒否はこの保存期限と独立して効く。この時間条件を両端fixtureで検証し、満たせない場合は受付を止める。

[Secret Managerの有効期限](https://docs.cloud.google.com/secret-manager/docs/creating-and-managing-expiring-secrets)はSecret全versionの自動削除を提供するが、期限は設定時点から60秒以上先である必要がある。残り60秒未満なら秘密作成/起動を拒否する。Runnerのsecret単位Accessor bindingには `request.time < timestamp(expireTime)` 条件を付け、project全体のAccessorは与えない。固定数値versionを使用し、latestへ追従しない。

Secret作成・version追加・IAM設定と台帳commitも非原子的。provisioningを先に保存し、作成済みSecretはget、version追加の応答不明はlist/getによるメタデータ照合で固定versionの存在を確認する。Token追加を盲目的に再実行しない。Token bodyを失いversionが存在しない場合はfailed、複数versionや所有ラベル不一致は隔離して起動禁止。Runner権限付与前にversion固定を済ませる。IAM反映の一時的失敗はRun期限内の限定retryだけ許す。

RunnerはComplete確定後に秘密の削除を要求するためのGoogle権限を持たない。内部workerはExecution終端を確認してRun Secretを削除する（Completeだけで削除してTask retryを壊さない）。queued起動失敗は即削除、起動不明は照合を続けexpireTimeまで保持する。削除失敗はSchedulerで再試行し、期限の自動削除とWordPressの失効を独立した防御にする。台帳なしの孤立Secretも所有ラベル・期限を照合してcleanupする。Cloud Run Execution終端はWordPressのcomplete/partialとは別なので、dispatch台帳から画像やRun集計を変更しない。

ログは許可リスト方式でrequest_id、site_id、run_uuid、状態、Execution ID、定型error code、所要時間だけ。HTTP body/header・SDK debug・Google応答body・例外原文・環境変数dump・ページconsole・trace/HARを収集しない。Token/Basic/Shared SecretをURL、shell引数、Image layer、CI成果物へ置かない。Secret APIの監査記録はresource/操作の追跡に使い、payloadの非露出をダミー秘密の検索試験で確認する。Cloud IAM管理者による読取や侵害されたRunnerのメモリ流出は防げず、秘密参照自体も管理者限定にする。

## Image・リソース・最小IAM

| 対象 | 初期設定 |
|---|---|
| Dispatcher Service | Node、PORTをlisten、0.0.0.0、CPU 1、memory 512 MiB、request timeout60秒、concurrency8、max instances2。外部WordPressのためCloud Run呼出しは公開、アプリHMAC必須。内部ルートは別OIDC検証 |
| Runner Job | CPU 2、memory 2 GiB、tasks1、parallelism1、task timeout1800秒、max retries1。Browser concurrency2。Job taskの並列1とBrowser並列2を混同しない |
| Registry | 環境別Artifact Registryのdispatcher/runner別repository、immutable tag、digest指定。linux/amd64でbuildしMacでも同じplatformを使う |
| Network | #5のDirect VPC egress all-traffic、専用tag/subnet、NAT/resolver/firewall、開発例外拒否。Google APIへの制御経路を明示し撮影Originへ追加しない。Metadataをfirewallだけで遮断できると保証しない |

Runner ImageはlockfileのPlaywright（現在1.64.0）と完全一致する公式Image版＋digestを基底にする。`npm ci`で同版を確認し、対応Chromiumだけを使う。[Playwright Dockerの版一致要件](https://playwright.dev/docs/docker)に従い、基底Imageにnpm packageが含まれるとは仮定しない。multi-stage buildで開発ツール/WordPress/秘密を最終Imageに含めず、非rootユーザーで動かす。Dispatcherは固定Node版/digestを別に管理する。Cloud RunでChromiumのsandbox/共有メモリがローカルと同じとは保証せず、侵害耐性の限界は#5に従う。OOM時はmemoryの実測調整を先に行い、撮影並列を無断で増やさない。

| Identity | 必要権限と境界 |
|---|---|
| Dispatcher runtime SA | 対象Jobだけのcustom role（run.jobs.run/runWithOverrides/get、run.executions.list/get）、照合用run.operations.getを必要なproject範囲で付与。対象Shared Secretだけのsecretmanager.versions.access。Firestoreの受付読書き。Run Secret作成/metadata/version追加/IAM設定を専用秘密projectのcustom roleで許可するが、Token version accessとJob update/deployは与えない |
| Runner runtime SA | Run Secretごとのroles/secretmanager.secretAccessor、期限条件付き。Job起動/更新、Shared Secret、Firestore、画像Storageの権限なし。Google APIで自分のRun Secretを読む経路のみ |
| 内部worker | 同じDispatcher Image/SAが処理し、秘密のdelete/list/get権限をRun秘密用projectだけ追加する。Scheduler caller SAはService invokerのみ、アプリ内で許可したOIDC subject/audienceに限定 |
| Build/deploy SA | Buildは対象Registryへのwriterのみ。Deployは対象Service/Jobのcreate/update/getと、それぞれのruntime SAへのiam.serviceAccounts.actAs。初期構築/IAM/VPC/Firestore/Secret基盤設定は別のinfra管理identityで行う |
| Cloud Run service agent | 対象Registryのpullに必要なreader。別project配置時は明示付与。runtime SAと混同しない |

Secret作成とsetIamPolicyには広い権限が必要で、名前prefixだけで完全に限定できるとは扱わない。Run秘密用projectをShared Secret/他業務から分離し、Dispatcherが自身へAccessorを付けられる権限昇格の残余リスクを明記する。より強い境界が必要な運用では専用credential brokerを別設計にする。Firestoreのcollection単位IAMを仮定せず専用project/databaseに隔離し、必要permissionとIAM resource scopeは実装時に否定試験する。Editor/Owner/run.admin/secretmanager.adminの一括付与を実行SAにしない。

Runner SAをJob間で共用するため、secret単位bindingでも、そのSAは同時に有効な他RunのSecretをIAM上は読める。通常のRunnerは渡された参照とsite/UUIDだけを使用し、任意参照を入力として受け付けないが、侵害されたプロセスのRun間隔離は保証しない。Runごとの強いIAM隔離が必須ならRun別identity/Jobまたはbrokerが必要で、共用JobのMVPをそのまま採用しない。

## 同一Imageによるローカル統合と環境パラメータ

以下は後続実装後の手順であり、現状では実行できない。composeはWordPressを作り直さず既存wp-env/Localへ接続する。

1. CIで作成済みのDispatcher/Runner digestを環境設定へ記録し、`docker compose --profile local pull`。両コンテナをlinux/amd64で動かし、独自のlocal用Imageを再buildしない。Docker daemon socketはDispatcherへmountしない。
2. `npm run env:start` または既存Localを起動する。ホスト側でHTTPの実IP/portとWordPress canonical URLを確認し、#5の開発専用read-only設定に**単一Origin・RFC1918 literal IP・port**を記録する。host.docker.internalは解決先を調べるためだけに使い、広い例外として許可しない。Localがloopbackでしか公開できない場合は、専用bridgeの固定IP relayからそのWordPressの単一portへだけ転送する。Runnerのloopbackを許可する変更はしない。
3. Dispatcher、内部worker、Firestore emulator、local専用Secret adapter、有限時間のRunner launcherをcomposeで起動する。Secret adapterは専用tmpfsへ0600でTokenを保持しTTL/cleanupを模擬する。launcherはRunner Imageと起動引数を固定、共有の単一queueを読み、1実行＋retry1を同じlocal Execution IDで実施する。秘密はread-onlyファイル参照だけ渡しenv/引数に出さない。raw Docker APIへのアクセスは不要とする。
4. WordPressへlocal Dispatcher URLとダミーsite Secretを設定し、Run作成→署名受付→Job入口→Manifest→撮影→Upload→Completeを通す。local Execution IDはlauncherが生成しAPI header形式に合わせる。例外Originは通常allowed_originsにも必要。制御API/撮影の権限・経路を分け、HTTP例外ではダミー資格情報だけを使う。
5. 同一digestをstaging Cloud Runへ指定し、クラウドprofile・実IAM/Secret/APIで同じ結合fixtureを検証する。local launcherはCloud Run制御面のエミュレーションであり、Operation/IAM/スケジューリングを検証済みとは見なさない。

composeのlocal profileはCloud Runでは禁止する。configファイル/関連local値がcloudで存在すれば起動失敗。WordPress URLにHTTPS化やDNSを導入してもprofile判定を弱めない。別port/別IP/redirect/Metadataへはlocalでも接続禁止。単一固定接続先が用意できない環境は統合試験非対応とする。

| 設定分類 | 管理予定 |
|---|---|
| 共通非秘密 | site登録callback、Schema version、Job名、browser concurrency、受付/時刻/容量上限 |
| 環境別非秘密 | project、region、Registry/digest、Service URL、runtime SA、Firestore database、Run秘密project、VPC/subnet/tag、Scheduler、cloud/local profile |
| 秘密参照 | site Shared Secretの固定version、digest/expiry付きRun Secret参照。設定にpayloadを書かない |
| local専用 | read-only接続先設定、ダミー秘密、tmpfs、emulator/launcher。Gitへ平文Tokenや本番Secretを保存しない |

予定する `infra/environments/{local,staging,production}.example.json` に非秘密の必須キーを定義し、実設定と秘密fileはGit管理外にする。gcloudは個別named configurationでproject/region/accountを確認し、スクリプトにも `--project`/`--region` を必須指定して誤環境を防ぐ。

## Version・CI・明示デプロイ・rollback

Runner/Dispatcher/PluginのVersionとTagは独立し、`runner-vX.Y.Z` / `dispatcher-vX.Y.Z` / `plugin-vX.Y.Z`。Schema majorとソフトウェアVersionは別。Imageにはcomponent version、Git SHA、lockfile hash、Playwright/Chromium版を付け、実行時報告と対応付ける。

| 変更path | PRで行う検証/Build |
|---|---|
| `apps/runner/**` | Runner型検査・単体/Chromium fixture・Image smoke |
| `apps/dispatcher/**` | raw HMAC・受付/起動/秘密adapter・障害fixture・Dispatcher Image smoke |
| `packages/**`、root package/lock/tsconfig | Runner/Dispatcher双方と共有Schema検証。PHP共通契約へ影響があればWordPressも実行 |
| `wordpress/**`、composer/PHPCS/wp-env | PHP/WPCS/wp-env。管理JSが追加された段階でそのbuild |
| `infra/**`・Docker/compose・CI | 定義検証、両Image smoke、local統合、cloud/local混在拒否。workflow変更は関連全job |
| `docs/**`のみ | 文書リンク・差分・仕様照合。Image deployや製品全テスト不要 |

path判定jobは常に走らせ、必須の集約checkを常に完了させる（workflow全体のpath skipでrequired checkを待機させない）。PRにクラウド資格情報を与えず、forkやpull_request_targetで未信頼コードを秘密付き実行しない。Actionsは採用時のcommit SHAへ固定する。

マージやTagだけでデプロイしない。`workflow_dispatch`でcomponent、environment、既存release tag/SHA、検証済みdigestを明示し、保護されたGitHub Environmentで承認・branch制限する。環境/componentごとのconcurrencyで直列化し、進行中のdeployを自動cancelしない。認証は[GitHub OIDCとWIF](https://docs.cloud.google.com/iam/docs/workload-identity-federation-with-deployment-pipelines)でrepository/ownerの数値ID、ref、environment、audienceを検査し、`id-token: write`と`contents: read`に限定する。長期SA鍵は作らない。

後続の `infra/scripts/{build-images,deploy-runner,deploy-dispatcher,verify,rollback}.sh` は構築と起動を分ける。API/Registry/IAM/VPC/Firestore/Scheduler/Secret基盤を先に整備し、`gcloud run jobs deploy`（tasks1、parallelism1、task-timeout1800s、max-retries1、cpu2、memory2Gi、専用SA/VPC）、`gcloud run deploy`（上記Service資源）をそれぞれ実行する。deployで `--execute-now` は使わず、検証用Runの起動を別操作にする。秘密はCLI値へ埋め込まない。WIF providerやIAM/VPC変更を通常component deployに混ぜない。

Dispatcherは新revisionへtrafficを切り替える前にhealth、登録設定、署名fixtureを確認する。RunnerはJob更新前に受付を停止し、launching/不明起動を照合してから更新する。台帳acceptedに予定digest/Job generationを保存し、再開前に一致を検査する。不一致なら起動せず保留して運用者が解決する。更新は既存Executionを置換しない。canary RunでExecutionのImage digest、Manifest/Upload/Complete、拒否試験と秘密非露出を確認して受付再開する。

デプロイ前に両componentのdigest、Service revision/traffic、Job定義、非秘密設定versionを履歴へ保存する。Dispatcherは前revisionへtrafficを戻し、Runnerは前digestとJob定義を再deployする。JobにはServiceのtraffic rollbackを流用しない。rollbackでもToken期限・台帳・一意キーを消さず、起動済みExecutionを再dispatchしない。Schema互換性を満たさない前版へのrollbackは停止し、前方修正へ切り替える。実行中Runは元のExecution/Imageで完了または期限失敗させる。

## 変更予定ファイルと実装順序（5項目）

今回は下記ファイルを作成/変更しない。#4/#5の設計採用は実装完了を意味しない。結合検証と公開は関連製品実装の完了後に行う。

| 順序 | 予定ファイル・作業 | 完了条件 |
|---|---|---|
| 1 | `apps/dispatcher/{package.json,src/index.ts,src/auth.ts,src/config.ts}`、`packages/schemas/src/dispatch-request.schema.json`、共通error/fixture、WordPress dispatch client | raw HMAC、callback登録、Version、再送/照合契約。#4と両端fixture一致 |
| 2 | `apps/dispatcher/src/{acceptance-store,secret-store,job-launcher,reconciler}.ts`、`tests/*.test.mjs`、Runner Job入口/製品API client | 永続台帳、Run Secret、lease/不明起動、同Execution retry、Token/Basic非露出。#4/#5実装と接続 |
| 3 | `apps/{runner,dispatcher}/Dockerfile`、`.dockerignore`、`docker-compose.yml`、`infra/local/{launcher,secret-adapter}`、local profile、`docs/development.md` | 同一digest、版一致、既存wp-env/Local単一先への統合、restart/障害再現 |
| 4 | `infra/{environments,cloud-run,iam,network}`、`infra/scripts/*.sh`、Scheduler/Firestore/Secret設定、cloud起動検査 | 最小IAM、期限付き秘密、Task/資源、#5の通信拒否、gcloud構築/起動/rollbackのstaging実測 |
| 5 | `.github/workflows/{ci,build-images,deploy-runner,deploy-dispatcher}.yml`、運用文書/security | path別検証、WIF、明示deploy、canary、rollback、非漏洩の証跡。受入れ後に実装Issueを作成 |

## 検証・リスク・未決事項

| 検証対象 | 合格条件（実装後） |
|---|---|
| HMAC | 同じraw bytesの成功、空白/改行/UTF-8改ざん拒否、timestamp±300/±301、重複header/キー、size超過、未知site/callback。拒否時Job/Secret作成0 |
| replay/同時起動 | POST同時10件、再送timestamp変更、異なるToken、Service restart、lease失効。起動送信1回、同一応答/Execution、別body409 |
| 起動障害 | 台帳commit前後、Secret create/version/IAM間、`:run`送信直前・直後・Operation保存前、poll timeout、Execution可視性遅延、重複候補を注入。不明時再起動0、Scheduler再開で照合可能 |
| Run retry/期限 | retry1と同Execution、pending再開/結果digest衝突、queued15分、総90分、Token2時間、Secret expiry計算と時刻差、終了後同Complete。失効後のUpload/撮影禁止 |
| 秘密 | ダミーToken/Basic/Shared Secretを全文検索し、ログ/CI成果物/Image layer/Job定義/override/監査記録/台帳に平文なし。許可先だけ受信、別Secret/siteのIAM読取拒否、cleanup再試行 |
| ローカル | wp-envとLocal各一例で起動からCompleteまで成功。同digest/platform、別IP/port/redirect拒否、cloud＋local値拒否、再起動後の台帳維持、tmpfs消失はfail closed |
| Cloud Run | stagingで実Operation→Execution照合、Image/版、Task1/並列1/Browser2/retry1/timeout、OOM/遅延起動、#5のMetadata/内部IP拒否、IAM否定試験 |
| CI/運用 | path各分類、required check完了、未信頼PRのWIF拒否、誤project/region拒否、承認後deploy、Service/Job別rollback、台帳と実行中Run維持 |

本設計で未実測の事項は、Run Secretの作成数/quota/費用、Secret IAM反映時間、Firestore運用費用、Cloud Runの待機と可視性遅延、Chromiumの2 GiBでの安定性、Mac上amd64実行性能、Local/wp-envの具体的接続IP、custom roleのresource scopeである。手順3〜4で測定・確定し、基準を満たせない場合は公開しない。ブラウザ侵害への完全な隔離、永久的なUUID拒否、外部APIのexactly-once起動は保証しない。起動不明で未撮影になるRunは安全側の仕様として管理画面へ示す。

今回の検証範囲は指定仕様・先行設計・既存入口/CIとの照合、2026年10月8日に確認した上記公式API資料、相対リンク/差分/受入れ条件の検査。製品全テスト、Docker起動、クラウド作成/実測は行わない。Firefox、ログイン後撮影、通知、AI解析、依存更新は対象外。

## Issue #32 の実装

管理 REST・非秘密 Settings・Run Token・Manifest/Credentials・WordPress の署名 Dispatch 送信と照合を実装した。[入口・設定・検証範囲](admin-api-and-dispatch.md)を参照する。Dispatcher の永続台帳・Job 起動と結果 Upload の HTTP 接続は後続で実装する。

## Issue #36 実装状況

製品 Runner の固定 WordPress API client・pending 再開・Upload/Progress/Complete・Job 入口・Run 秘密 adapter を実装した。[設定・終了処理・検証範囲](runner-job.md)を参照する。Dispatcher の非秘密 override には元の Run Secret expiry を `ODVR_TOKEN_EXPIRES_AT` として含め、固定 project 番号・site/UUID から導出した数値 version と照合する。既存プロトタイプ CLI は維持する。実 Dispatcher 起動は #37、wp-env 接続は #38、Cloud 実機は #39 で検証する。

## Issue #37 実装状況

`apps/dispatcher` に raw HMAC HTTP受付、Firestore受付台帳、期限付きSecret、固定Job起動、Operation/Execution照合、認証付き内部workerとcleanupを実装した。具体的な設定・入口・復旧条件と実測範囲は [Dispatcher製品入口](dispatcher-implementation.md) を参照する。通常CIは永続adapter/公式SDK fixtureで検証し、localの有限launcher接続は #38、実Cloud Run/IAMの実測は #39 で行う。

## Issue #38 実装状況

共通linux/amd64 Image、非root実行、固定wp-env bridge、Firestore emulator、tmpfs秘密、有限queue launcherと内部workerを実装した。[起動・停止・fixture・復元・再起動の範囲と制限](local-images.md)を参照する。Cloud実機は #39、MVP全シナリオは #13 で検証する。
