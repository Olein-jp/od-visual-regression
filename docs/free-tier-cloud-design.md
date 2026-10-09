# 無料枠を基本とするクラウド構成・利用上限・通信防御

[Issue #57](https://github.com/Olein-jp/od-visual-regression/issues/57) の設計記録。確認日：2026-10-10（日本時間）。製品コード・依存・クラウド資源は変更しない。実装は #39、画面は #40/#41、結合検証は #13。

## 採用判断

**設計候補は定義したが、現時点では未採用。#57 は未解決、クラウド構築は停止を維持する。** 無料枠の共有残量、WordPressと撮影先への通信料金条件、公開GHCRからの実配備、NATなしの迂回通信拒否を確認できていない。下記の条件付き0円試算を現在の環境の見積りや利用者の合意と読み替えない。

確定事項は、常設NAT・固定/自動割当外部IPv4・VPC connector・VM・最小インスタンス・有料レジストリキャッシュを標準から除外すること、画像本体をWordPressに保存すること、無料残量不明では新規起動を止めること。既存のHMAC、受付台帳、起動不明時の再起動禁止、秘密の期限/削除、最小IAM、固定HTTP transport、撮影・比較・保存・ローカル接続は再利用する。

候補は `us-central1` のDispatcher（request-based、CPU1/512MiB、min0/max1、timeout30秒、concurrency1、CPU boost無効）とRunner Job（CPU2/2GiB、tasks1/parallelism1、timeout600秒、retry1、browser2）。別Executionの並行数はJobのparallelismでは制限できないため、課金アカウント全体の台帳で最大2にする。min0でも不正な公開リクエストの処理費は発生し得る。

## 公式料金・無料枠と採否

以下の全リンクを2026-10-10に確認。USD表示、契約通貨のSKUが優先。試用クレジット、CUD、他サービスの余剰枠を0円の根拠にしない。採用欄の「候補」は採用承認ではない。

| サービス・公式資料 | 無料条件・超過価格の目安 | 地域・集計範囲・採否 |
|---|---|---|
| [Cloud Run](https://cloud.google.com/run/pricing) | Service：180,000 vCPU秒/360,000 GiB秒/200万要求。Job：240,000 vCPU秒/450,000 GiB秒。us-central1超過：Service CPU $0.000024/秒、RAM $0.0000025/GiB秒、要求 $0.40/百万。Job CPU $0.000018/秒、RAM $0.000002/GiB秒。Jobは最低1分課金 | 課金アカウント集計、us-central1価格相当の割引。Service/Jobの枠を無条件に足し合わせない。us-central1候補、東京を同じ数量で無料としない |
| [インターネット送信](https://cloud.google.com/run/pricing)、[Network料金](https://cloud.google.com/vpc/network-pricing)、[無料プログラム](https://docs.cloud.google.com/free/docs/free-cloud-features) | Run料金は北米内1GiB/月を明記。Network表の地域別無料表示をRunの全宛先へそのまま適用しない。受信と送信を区別 | 北米内の適格経路だけを0円試算の仮定とする。日本向け・CDN・他地域・DNS/制御通信の扱いは実SKU/公式確認が必要。regionを米国へ変えるだけでは確定しない |
| [Artifact Registry](https://cloud.google.com/artifact-registry/pricing) | 0.5GiB月/課金アカウント、超過約$0.10/GiB月。同regionのGoogle宛転送無料、外向き/地域間は別料金 | 標準不採用。remote repositoryのcacheも[保管課金対象](https://docs.cloud.google.com/artifact-registry/docs/repositories/remote-overview)。cacheだけ無料としない |
| [公開GHCR](https://docs.github.com/en/billing/concepts/product-billing/github-packages)、[Cloud Run Job対応レジストリ](https://docs.cloud.google.com/run/docs/create-jobs) | public packageの保管/転送無料。Jobは公開GHCRを直接参照でき、最大1時間cache。private GHCR等はArtifact Registry remote repositoryが必要 | 公開・秘密なしのODVRイメージのみ候補。Google管理の一時cacheと利用者所有の有料remote repositoryを区別。後者を作らない。Service/Job双方のdigest直接配備は未測定 |
| [GitHub Actions](https://docs.github.com/en/actions/concepts/billing-and-usage)、[Cloud Build](https://cloud.google.com/build/pricing) | public repoの標準hosted runner利用は無料。Cloud Buildはdefault pool/e2-standard-2の2,500分/月、超過$0.006/分、転送/保管別 | 公開repoの標準Actionsでbuild候補。larger runner・有料cache/成果物保管を追加しない。Cloud Build不採用、0分 |
| [Firestore](https://cloud.google.com/firestore/pricing) | 1GiB保管、日次read50,000/write20,000/delete20,000、送信10GiB/月。超過us-central1：read $0.03/write $0.09/delete $0.01各10万。TTL削除・backup/PITR等は無料対象外 | us-central1、専用projectの(default) DB候補。1projectで無料DBは一つ、named DB不可。日次リセットは太平洋時間。TTL機能を使わず既存workerで明示削除 |
| [Secret Manager](https://cloud.google.com/secret-manager/pricing) | active6 version/月、access10,000/月、rotation通知3/月。超過version約$0.06/月、access $0.03/万。Enabled/Disabledとも課金、destroyed無料、時間比例 | 課金アカウント全project合算。automatic replicationは1locationとして扱う候補。Run秘密専用projectを分けても無料枠は増えない。世代をdisableだけで残さない |
| [Cloud Scheduler](https://cloud.google.com/scheduler/pricing) | 3job/月/課金アカウント、超過$0.10/job/31日。実行回数でなく定義数 | us-central1のOIDC reconcile/cleanup 1job候補、1分周期。既存利用2job以下を確認する。停止状態も定義数に数える |
| [Logging](https://cloud.google.com/products/observability/pricing) | 50GiB/project/月、超過$0.50/GiB。30日超保管は別料金。Required bucketは保管無料、network telemetryは別料金 | 既定保持以下の通常ログ候補。専用projectごとに確認。VPC Flow/Firewall/NATログ、外部sink、有料追加監視は標準不採用 |
| [NAT](https://cloud.google.com/nat/pricing)、[Direct VPC](https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc) | NAT gateway時間・処理$0.045/GiB・外部IP $0.005/時＋転送。Direct VPCはconnector不要だが転送費条件は別 | NAT/外部IPv4/connector/VM不採用。Direct VPC private-ranges-only候補、公開通信のfirewall保護は失われる |

課金アカウント内の他project/production/stagingも一覧化し、Run・Registry・秘密・Schedulerを横断集計する。無料試算は環境一つ/site一つ。他環境を追加したら再配分する。請求先、契約料金、既存利用、適格経路を確認できない現時点の残量はすべて「不明」。課金データの遅延があるため、監視表示だけで残量を保証しない。

## イメージの実測と配備経路

既存localイメージを変更せず読み取り確認した。両imageのrevision labelは `11676e022d326444945a00671c5859c2784c1ab8`。runner ID `c330cb805b88…`、dispatcher ID `7ff25b01e5fc…`。`docker image ls` の表示はrunner 3.66GB/dispatcher 415MB、`docker image inspect` のSizeは996,262,468/91,288,402 bytes（合計約1.013GiB）。表示差は同じ測定量と扱わず、どちらもRegistryの課金保管量の証拠ではない。圧縮manifest/layerの実保管量は未測定で、無料0.5GiBに適合したとは判断しない。再build・pushは今回行わない。

候補は `ghcr.io/olein-jp/<承認したcomponent名>@sha256:<検証済みmanifest>` → Cloud Run Service/Jobの直接import。amd64/platform・検証済みcommit・digest・公開可否を固定する。tag/latest、任意ホスト、private package、有料remote repositoryへの自動fallbackを拒否する。GHCR現行＋rollback用の2世代/componentを残し、実行中Executionのdigestは削除保護する。公開にはimage内容/履歴に秘密がない確認が必要。

#39で公開GHCRのanonymous pull、Service/Jobの直接配備、cold start・cache期限後の取得・旧digest rollbackを実証する。Google管理cacheの利用者課金がないことも対象SKUと資源一覧で確認する。直接取得不可なら停止し、Artifact Registryへ無断で切り替えない。代替Registryは圧縮layer実測、共有layer/旧世代/更新時二重保管/他用途を含むピーク0.4GiB以下を証明できた場合だけ別途再設計する。

## 提案利用量と条件付き月額試算

**下記は利用者未合意の小規模profile。** 初回baselineも1Run、失敗も1Runとして8Run/月（概ね比較4組）、2Target×3Device以下＝6Snapshot/Run、月48Snapshot（Task retry込み最大96処理）。1撮影60秒、撮影後処理/起動を含め1attempt600秒、retry1で最大1,200秒/Run。Cloud Run起動/終了の課金余裕120秒/Runを別に確保し、請求時間が収まらなければ受付を止める。既存の30分Task/1GiB受信等は無料profileの採用値ではない。

全サービスについて無料適格条件、他用途予約ゼロ、単一環境、対象経路が北米内無料であることを仮定した試算：

| 消費項目 | 最悪予約・月の量 | 無料枠内の費用 |
|---|---|---|
| Runner CPU/RAM | 8×(600×2attempt＋120)×2＝21,120 vCPU秒 / GiB秒。月hard cap各24,000 | $0 |
| Dispatcher・cleanup・照合 | 31日×1,440＝44,640 Scheduler要求＋外部/運用要求5,000以下。通常worker1秒目標（44,640＋外部/運用5,000＋起動等の余裕10,000＝59,640CPU秒）、異常worker最大10秒×全周期＋外部30秒×5,000では596,400CPU秒となり無料枠超過。そのため月60,000 CPU秒/30,000GiB秒で通常照合を停止し新規受付も停止。必須cleanup専用余裕20,000CPU秒/10,000GiB秒を別予約 | 合計80,000CPU秒/40,000GiB秒以下なら$0。無制限の異常照合は不可 |
| Cloud Run外向き | retryを含め64MiB/Run×8＝512MiB。その他応答/DNS/TLS/制御用64MiB/月＝合計576MiB。月上限0.625GiB（640MiB）、最低0.375GiB余裕 | **適格経路限定**で$0。日本向けを含む現環境は未確定 |
| Runner受信 | 撮影/Manifest/Baseline等128MiB/Run、retry含め月1GiB。受信無料でも相手の転送/Google API地域差を別確認 | 適格経路なら$0 |
| Firestore | 日次予約read10,000/write5,000/delete1,000（transaction retry5、empty query/index read、workerを含む）、保管0.1GiB/送信0.1GiB月以内 | $0、TTL/backup/PITR不使用 |
| Secret | 通常固定3version（Shared1/config2）＋同時Run2＝5、rotation時だけ6。Runは最長90分で8件なら12version時間/月。固定3×744＝2,232version時間＋Run12＝約3.016version月、access月5,000以下（config mount再読込含む） | $0、他用途含め瞬間6以下。rotation時は新規受付停止 |
| Registry/build | Artifact Registry/Cloud Build 0。公開GHCR 2世代/component、公開repo標準Actions、build月4回×30分を運用上限、成果物cacheを有料保管しない | $0、public条件に依存 |
| Scheduler/log | Scheduler1定義、log合計0.1GiB/月以内、保持30日以下 | $0 |
| 合計 | 上記条件付き、試用クレジットなし | $0＝0円。**現在の環境の0円認定ではない** |

PNGは1枚2MiB、current＋diffで4MiB/Snapshot以下、6Snapshot×4MiB×2attempt＝48MiB/Run。残り16MiBはmultipart、要求、Progress/Complete、再送、TLS/TCP等の余裕。大画像は縮小して成功とせず撮影失敗にする。制御APIの再送にも同じ共有送信予算を適用し、retryのたびにbyte予算を復元しない。ページPOST等も送信量へ数える。既存受信中心のHTTP wire計測はTLS record/TCP再送/ブラウザ直接通信を含まないため、これだけをGoogleの課金計測と同一視しない。低いアプリ送信上限と実転送の余裕を組み合わせても強制課金ゼロは保証できない。

Run数の一般上限は `min(floor(Cpu残量/(cpu×最悪秒)), floor(Ram残量/(GiB×最悪秒)), floor(送信残量/Run送信予約), Snapshot残量/6, 秘密/台帳/ログの制約)`。8を超える値は無料profileでは採用しない。各残量は共有既存利用と最低20%の安全余裕を差し引く（送信は上記でさらに大きな余裕）。北米外で無料送信量を確認できない場合は残量0として起動数0。利用量を増やす/CPU・memoryを上げる/新region追加には再試算が必要。

## 起動前予約・停止・cleanup

現在のsite別10件/分・未終端2件だけでは課金アカウント全体の月次上限を守れない。#39で既存FirestoreStore/AcceptanceLedgerを拡張し、次を同じtransactionで扱う。通信bodyを拡張して自己申告Snapshot数を信用する方式は採用しない。

1. HMACとcallback登録を確認した新規受付に、固定最大6Snapshot/全最悪予算を予約する。月次account quota、日次DB操作予算、全環境active枠、site枠、受付record `(site_id,run_uuid,digest)` を原子的にcommit。Secret作成とjobs.runはcommit後。既存UUID同digestは同じ予約を返し、別digestは409。起動後Manifestが6を超えれば失敗とし、予約を追加せず停止する。次段階で正確な見積りAPIを加える場合もサーバー検証を必須とする。
2. 予約にpolicy version、billing account識別、UTC課金月、Firestore太平洋日付、cost vector、lease generation、attempt ID、消費/解放状態を保存。月境界前22分以内の新起動を拒否し、境界をまたぐ不明実行は両月へ保守的に予約する。Task retry/同一再送に新規予算を与えない。起動不明でjobs.runを再送しない既存契約を維持。
3. 枠超過は429 `odvr_usage_limit_exceeded`、残量/設定/telemetry不明・cleanup障害・経路未採用は503 `odvr_usage_unavailable`。どちらも新規のSecret作成/Job起動0。status用量は非秘密の制限、予約済、消費、残量またはnull、計測時刻、有効期限、停止理由、policy versionだけ。上限エラーでWordPressに作成済Runがある場合は既存Runを失敗確定しTokenを失効、履歴を残す。画面から上限解除しない。
4. 期限切れleaseだけでは消費予約を返さない。未起動が確定した受付はSecret/IAM cleanupとlaunch不能の確定後に一度だけ計算枠を返せる。起動済/不明は最悪予約を当月消費として維持し、Execution終端と秘密削除を確認してactive枠だけ返す。実測値が完全でない間は月枠を払い戻さない。新規予約/月初リセットも旧不明実行を消さない。
5. 専用OIDC workerは1分周期・1呼出10秒/100record以内、API retry回数と操作予算を共有する。Secretは署名時刻＋90分のexpireTime/固定数値versionと期限付きIAM、終端後削除、孤立cleanupを維持。cleanup遅延5分またはversion数異常で新規停止。台帳は30日重複防止後に明示削除（Firestore TTL不使用）。月集計は当月＋前月保持、不要indexを制限。worker通常予算が尽きたら起動/通常pollを止め、既存秘密/予約回収だけを専用余裕で処理する。必須余裕まで不足なら全新規停止・運用対応、無料継続を宣言しない。

送信/時間/操作予算はJob内・再試行間でもサーバー側で共有し、telemetryを冪等なattempt/sequenceで集計する。消費の報告が失われても最大予約を保持する。課金アカウントを複数projectで共有する場合の予約台帳は一つの専用default DBへ集約し、Firestoreのcollection単位IAMによる隔離を仮定しない。別の台帳を各projectに作って同じ無料枠を重複配分しない。

無料枠の他用途割当・資源一覧・料金確認の証明は非秘密運用設定に記録し、有効期限最大24時間。専用請求先なら共有利用ゼロを確認できるが、作成自体は今回行わない。外部利用をリアルタイムに完全観測できず、証明も絶対保証ではない。失効/不整合/利用者が他用途資源を追加した場合は新規停止。既存照合のGETも有料処理なので無制限pollせず、画面backoffとserver rate capを入れる。

予算通知は強制停止ではない。公開HMACの不正要求、Cloud Runの一時的なmax超過/課金時間、TLS/TCP再送、直接socket、ログ/SDKの追加操作、並行transaction失敗、cleanup遅延、外部利用、課金計測遅延はアプリ予約では完全に止められない。ログは許可リスト/出力量上限＋不要request log除外、監査/Requiredの必要記録は保持する。有料Cloud Armor等を無料対策として追加しない。金銭的な絶対上限が必要ならこの候補を採用しない。

## NATなしの通信防御と公開判定

[既存設計](network-security-design.md)のアプリ層を維持し、Direct VPC `private-ranges-only`＋専用IPv4 subnet/tag＋private/reservedへのdenyを候補にする。公開宛はCloud Runの直接経路、VPCへ送る内部宛はdenyする。全特殊範囲がこの設定でVPCへ送られると仮定しない。Metadata/loopback/IPv6はアプリ検査と実測が必要。

| 境界 | 旧all-traffic＋NAT | 候補private-ranges-only＋NATなし |
|---|---|---|
| 通常HTTP/制御API | 全DNS回答/用途Origin/接続IP/Host/SNI固定、redirect禁止 | 同じ既存transportを使用、route.fulfillのみ、fallback禁止 |
| VPC private宛 | firewall deny＋アプリ拒否 | VPC経由の宛先にはdeny。特殊範囲/ローカル宛すべてを覆う保証なし |
| 公開TCP/UDP/任意port | VPC側はTCP80/443以外deny。公開Originの制限はアプリ側 | **VPC firewallを通らず、任意公開TCP/UDP/portの独立遮断を失う**。NATはSSRF防御ではないが、経路変更に伴うfirewall損失は残る |
| Metadata/loopback | 元からVPC firewallだけでは保証不可 | 同じ制約、信頼SDKのADCだけ許容、撮影ページ/transportは拒否 |
| ブラウザHTTP外/侵害 | ブラウザ抑止＋一部ネットワークdeny、OS隔離ではない | QUIC/WS/SW/WebRTC/WebTransport/prefetch抑止を維持。ただし設定/routeだけでは一般socketの防御なし |

代替は用途別Origin＋全回答分類＋literal固定transportと、既存browser feature抑止の検証である。公開通信全体のネットワーク遮断と同等とは扱わない。Cloud Run内iptables/privileged隔離やCSPだけでworker/UDPを全保護できるとも扱わない。

#39の実機検証では、同一digestについて許可fixtureへの成功、禁止先accept=0、TCP/UDP計測、一般socketからのcanaryを観測する。private/reserved IPv4、IPv4-mapped/ULA/IPv6/変換範囲、Metadata名・別名・literal、loopback、A/AAAA混在、DNS切替、redirect、iframe/popup初回/worker/Beacon/WS/SW/WebRTC/WebTransport/QUIC/background通信、許可外の公開Origin/portを含める。IPv6経路を未測定のまま成功扱いしない。ADC通信とユーザー由来通信を区別し、Metadata token endpointには触れない。

**一般socketからの許可外公開通信は候補では到達し得るため、そのcanary成功を想定した制御差分として記録する。** これを遮断合格としない。通常のページ機能からguard外へ出られれば不採用。侵害されたプロセスの任意socket/秘密メモリ流出まで拒否する安全要件なら候補は構造的に不適合で、実機試験だけで採用に変えない。より強い隔離基盤の別設計またはローカル継続を選ぶ。費用条件を優先して安全要件を無断で弱めない。

## 後続実装計画（5項目）

採用前は設計/ローカル検証まで。実機試験は対象・利用量・料金経路・残余リスクを提示して採用判断した後に行う。#39の完了や本書の存在だけを構築許可にしない。

| 順序・担当Issue | 変更予定ファイル | 変更と検証 |
|---|---|---|
| 1：#39 | `infra/cloud/{plan,deployment}.mjs`・同test、`infra/environments/*.example.json`、`infra/scripts/cloud-*.mjs` | 正のbudget_usd必須を見直し、追加費用目標0と無料quota証明/有効期限を別設定化。NAT/外部IP/all-traffic/Registry固定を除去、GHCR digest allowlist、低資源profile、停止gate。未採用/残量不明/有料fallback/旧設定は計画生成でも拒否するテスト |
| 2：#39 | `apps/dispatcher/src/{types,ledger,firestore-store,engine,config,server}.ts`、同tests、`packages/shared/src/`、`packages/schemas/src/` | account/global月・日・同時枠の原子予約、冪等精算、期限/境界、不明起動、cleanup/操作予算、status/定型エラー。並行10POST/同digest/再送/transaction競合/期限回収/停止中cleanupを検証。旧台帳移行は受付停止して未知を保守予約 |
| 3：#39 | `apps/runner/src/{job,config}.ts`、`apps/runner/src/security/`、`apps/runner/src/api/`、関連tests、`infra/cloud/` | Manifest6/画像2MiB/送受信/time/retryの共有予算、telemetry喪失時停止、既存固定transport維持。private-ranges-onlyと公開経路差分、GHCR import/cache/rollback、IAM/Secret否定/expiry/実retry、操作・実課金量を検証。任意プロキシやAPI overrideを増やさない |
| 4：#40 → #41 | `wordpress/od-visual-regression/includes/`、`wordpress/od-visual-regression/rest/`、`apps/admin/src/`（作成予定）、関連tests、`docs/admin-ui-design.md`・`docs/api-security-storage-design.md` | Settings/Run確認に上限・予約・消費・残量null・計測時刻・停止理由を表示。開始済Runの失敗と開始前拒否を区別し既存Run履歴/非公開Blobを維持。401/429/503/失効/応答喪失を検証、自動再Runなし |
| 5：#13（#39/#40/#41後） | `docs/{mvp-integration-validation,implementation-status,cloud-staging}.md`、既存結合fixture、`.github/workflows/` | baseline→比較→Viewer、上限直前/超過/不明/並行予約・retry・cleanup、実転送/保管/秘密/ログと遅延後請求の照合。通常CIはローカルだけ、手動実機記録と撤去を分離。未測定を0円/合格にしない |

## 採用に必要な条件・未解決事項

- 利用者が月8Run/6Snapshot/小画像/時間制限を利用目的に足りると判断すること。合意済みの利用量は現時点で存在しない。
- 実WordPress・撮影先/CDNの配置と送信SKUの無料適格条件、billing account全体の既存利用/productionとの配分が判明すること。日本向け無料条件が成立しないなら0円条件の候補は不成立。
- 公開GHCR配備とキャッシュ課金の確認、圧縮サイズ/世代/秘密非露出、2GiB・10分での撮影実測、SDK/config access/logの実利用が上限内であること。
- 公開通信の独立firewall制御を失う点が必要な安全要件に適合すること。guard迂回canaryの未測定は採用理由にならない。

以上が成立しない間、#57は閉じず、#39のAPI有効化・有料資源作成・デプロイは進めない。既存WordPressサーバー料金・ローカル電力等は追加クラウド費用と分ける。今回の検証は公式資料照合、既存imageメタデータ読み取り、予約/試算の文書検査までで、クラウド構築も実測も行っていない。

## Issue #57 受け入れ条件の照合

| 条件 | 今回の判定 |
|---|---|
| 公式料金/確認日/地域/共有範囲/固定費除外 | 文書化済み、実資源の確認は未実施 |
| 合意利用量で原則0円 | 未達。利用量未合意、通信無料条件・共有利用不明。条件付き試算のみ |
| 上限/安全余裕/予約/停止/cleanup/残余リスク | 設計済み、実装と実機測定は#39 |
| NAT前後の防御差・安全な推奨構成 | 差分と候補/否定条件を記録。安全性成立は未確認、未採用 |
| 仕様/設計/依存/5項目計画 | 文書更新済み、関連Issueへ本設計の担当範囲と停止条件を記録 |
| 採用条件/未解決/未採用中に構築しない | 停止維持。API有効化/資源作成/デプロイなし |
