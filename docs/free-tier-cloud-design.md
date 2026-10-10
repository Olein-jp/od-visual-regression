# 不定期の更新テスト向けクラウド構成・従量課金・通信防御

## 最小撮影による実装検証（2026-10-10）

利用者は費用目安を確認し、できるだけ少ない撮影で実装完了まで進めることを希望した。最大20ページの製品容量を維持し、検証用の `minimal_validation` は1Target×1Device、正常系は更新前後2Runを基本にする。障害・競合・上限拒否は先にローカルで検証し、追加の実機Runは必要な再試行確認に限る。

| 設定 | 最小検証 | 製品の更新テスト |
| --- | --- | --- |
| 最大Target×Device | 1×1 | 20×3 |
| Task / retry / ブラウザ同時数 | 300秒 / 1 / 1 | 1800秒 / 1 / 2 |
| CPU / memory | 2 / 2GiB | 2 / 2GiB |
| 送信・受信の予約量（retry込み） | 各128MiB | 6GiB・2GiB |
| NAT/IPの保持予約 | 30分/Run | 90分/Run |

最小検証は無料枠を控除せず、CPU/RAM720秒（2試行＋120秒の余裕）・送受信量・NAT/IPを合算した主要費目が約$0.0988/Run。操作費等の余裕$0.05を加え、切り上げて$0.15/Runを計画予約する。正常系2Run＋管理用$0.50なら$0.80。**$1の試験枠は設定例であり、実請求の絶対上限やクラウド支出の承認ではない。** 実測・cleanup障害・共有利用の残余は既存の費用設計に従う。

環境設定はschema 2の `usage_policy` にprofile、有効期限、最大Run数、同時数、金額、管理用予約を固定する。旧 `budget_usd` は受け付けない。最小検証は同時1Run、計画上最大4Runまで。1Runのアプリ通信は制御32MiB＋撮影32MiBを各方向に制限し、retry込みで各128MiBを予約する。撮影数の上限超過はブラウザ起動前に失敗として完了し、ページを黙って省略しない。Runner/Dispatcherの固定設定にもprofileを登録する。

実装済みは計画の入力検査・試算、常設NAT/IPと有料Registry作成の除去、GHCR固定digest候補、Runnerの撮影数/通信/時間/同時数制限とJob時間照合。**アカウント全体の原子的な利用枠予約、一時NAT controller/lease/撤去、明示release/rollback、GHCR実配備、クラウド実測は未実装・未検証。** 計画ファイルの `preparation_only` を実行許可として扱わない。配備前にこれらの実装・検証を終え、対象と最小試験量、費用、終了と撤去の手順を具体化する。クラウド資源はまだ作成しない。


[Issue #57](https://github.com/Olein-jp/od-visual-regression/issues/57) の設計記録。確認日：2026-10-10（日本時間）。製品コード・依存・クラウド資源は変更しない。実装は #39、画面は #40/#41、結合検証は #13。

## 利用者が確定した条件と採用判断

2026-10-10、利用者から次の条件を確認した。PHP/WordPressコア更新時の不定期テストで、日常的な自動実行は想定しない。1サイトは最大20ページ。顧客ごとにサーバーが異なり、日本国内が主対象。必要な実行量に応じた従量課金は許容する。

費用条件を「無料枠を優先し、待機中の固定費を避け、必要な実行時の従量課金を許容する」へ改訂する。**原則0円の厳格条件、月8Run/6Snapshot/北米サーバー前提は撤回する。** 月の実行回数・顧客数・金額上限は未指定。少頻度を月8回と決めつけない。撮影Deviceは既存3プリセットを初期提案とし、20Target×3Device＝最大60Snapshot/Runを設計容量とする。初回baselineと更新後比較は2Run＝最大120Snapshotで、Task retryはこの他に消費する。

候補は東京 `asia-northeast1` のDispatcher（request-based、CPU1/512MiB、min0/max1、timeout30秒、concurrency1、CPU boost無効）とRunner Job（CPU2/2GiB、tasks1/parallelism1、timeout1800秒、retry1、browser2）。国内顧客への距離を優先し、無料枠を得るためにサーバー地域を限定しない。東京はCloud Run料金のTier 1で、下記の計算単価を適用する。配備時にSKU/契約通貨を再確認する。別Executionの並行数はJobのparallelismでは制限できないため、account共通台帳の最大2を初期提案とする。

常設NAT・外部IPv4・VPC connector・VM・最小インスタンス・有料レジストリcacheを標準から除外し、画像本体はWordPressに保存する。HMAC、永続受付台帳、起動不明時の再起動禁止、秘密の期限/削除、最小IAM、固定transportと既存撮影・比較・保存・ローカル接続を維持する。

**NATなし案は独立した公開通信のdenyを失うため不採用を推奨する。代案はテスト時だけNATを作成するall-traffic構成。代案の採用判断まではクラウド構築停止を維持する。** 公開GHCRの実配備、共有の保管/秘密/Scheduler等の待機費、運用上限は後続の検証対象。従量課金の許容を常設資源・任意金額・デプロイ承認に拡張しない。free残量不明だけを理由に停止する旧条件は撤回し、料金/経路/利用上限が有効に設定されていない場合に停止する。

## 公式料金・無料枠と採否

以下の全リンクを2026-10-10に確認。USD表示、契約通貨のSKUが優先。試用クレジット、CUD、他サービスの余剰枠を0円の根拠にしない。採用欄の「候補」は構築承認ではない。Cloud Runの地域選択をTokyoに変更し、Tier 1と単価を確認した。Network料金もTokyo/Premium Tierを選択して確認した。Firestoreのus-central1超過価格は比較用で東京の単価ではない。

| サービス・公式資料 | 無料条件・超過価格の目安 | 地域・集計範囲・採否 |
|---|---|---|
| [Cloud Run](https://cloud.google.com/run/pricing) | Service：180,000 vCPU秒/360,000 GiB秒/200万要求。Job：240,000 vCPU秒/450,000 GiB秒。東京の超過：Service CPU $0.000024/秒、RAM $0.0000025/GiB秒、要求 $0.40/百万。Job CPU $0.000018/秒、RAM $0.000002/GiB秒。Jobは最低1分課金 | 課金アカウント集計、us-central1価格相当の割引。Service/Jobの枠を無条件に足し合わせない。東京候補。共有枠による値引きは実請求で確認し、0円を保証しない |
| [インターネット送信](https://cloud.google.com/run/pricing)、[Network料金](https://cloud.google.com/vpc/network-pricing)、[無料プログラム](https://docs.cloud.google.com/free/docs/free-cloud-features) | Run料金は北米内1GiB/月を明記。Network表の地域別無料表示をRunの全宛先へそのまま適用しない。受信と送信を区別 | Tokyo/Premium Tier→アジア（韓国/インドネシア除く）の最初の1024GiBは$0.12/GiB、無料帯なし。日本国内WordPressへの送信をこの単価で見積もる。CDN・他地域・DNS/制御通信も実SKUで確認。無料条件未確認でも、有効な従量課金設定と利用枠があれば実行可能 |
| [Artifact Registry](https://cloud.google.com/artifact-registry/pricing) | 0.5GiB月/課金アカウント、超過約$0.10/GiB月。同regionのGoogle宛転送無料、外向き/地域間は別料金 | 標準不採用。remote repositoryのcacheも[保管課金対象](https://docs.cloud.google.com/artifact-registry/docs/repositories/remote-overview)。cacheだけ無料としない |
| [公開GHCR](https://docs.github.com/en/billing/concepts/product-billing/github-packages)、[Cloud Run Job対応レジストリ](https://docs.cloud.google.com/run/docs/create-jobs) | public packageの保管/転送無料。Jobは公開GHCRを直接参照でき、最大1時間cache。private GHCR等はArtifact Registry remote repositoryが必要 | 公開・秘密なしのODVRイメージのみ候補。Google管理の一時cacheと利用者所有の有料remote repositoryを区別。後者を作らない。Service/Job双方のdigest直接配備は未測定 |
| [GitHub Actions](https://docs.github.com/en/actions/concepts/billing-and-usage)、[Cloud Build](https://cloud.google.com/build/pricing) | public repoの標準hosted runner利用は無料。Cloud Buildはdefault pool/e2-standard-2の2,500分/月、超過$0.006/分、転送/保管別 | 公開repoの標準Actionsでbuild候補。larger runner・有料cache/成果物保管を追加しない。Cloud Build不採用、0分 |
| [Firestore](https://cloud.google.com/firestore/pricing) | 1GiB保管、日次read50,000/write20,000/delete20,000、送信10GiB/月。超過us-central1：read $0.03/write $0.09/delete $0.01各10万。TTL削除・backup/PITR等は無料対象外 | 東京、専用projectの(default) DB候補。1projectで無料DBは一つ、named DB不可。日次リセットは太平洋時間。TTL機能を使わず既存workerで明示削除 |
| [Secret Manager](https://cloud.google.com/secret-manager/pricing) | active6 version/月、access10,000/月、rotation通知3/月。超過version約$0.06/月、access $0.03/万。Enabled/Disabledとも課金、destroyed無料、時間比例 | 課金アカウント全project合算。automatic replicationは1locationとして扱う候補。Run秘密専用projectを分けても無料枠は増えない。世代をdisableだけで残さない |
| [Cloud Scheduler](https://cloud.google.com/scheduler/pricing) | 3job/月/課金アカウント、超過$0.10/job/31日。実行回数でなく定義数 | 東京のOIDC reconcile/cleanup 1job候補、1分周期。既存利用2job以下を確認する。停止状態も定義数に数える |
| [Logging](https://cloud.google.com/products/observability/pricing) | 50GiB/project/月、超過$0.50/GiB。30日超保管は別料金。Required bucketは保管無料、network telemetryは別料金 | 既定保持以下の通常ログ候補。専用projectごとに確認。VPC Flow/Firewall/NATログ、外部sink、有料追加監視は標準不採用 |
| [NAT](https://cloud.google.com/nat/pricing)、[Direct VPC](https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc) | NAT gateway時間・処理$0.045/GiB・外部IP $0.005/時＋転送。Direct VPCはconnector不要だが転送費条件は別 | 常設NAT/外部IPv4/connector/VM不採用。private-ranges-onlyは防御差から不採用推奨。一時NATの代案は下記、採用待ち |

課金アカウント内の他project/production/stagingを一覧化し、共有無料枠の消費を横断集計する。Dispatcher/Jobはmin0でも、不正要求や管理処理の費用を完全にゼロにはできない。非実行時にも保持する秘密version、受付台帳、image、Scheduler定義を区別する。Registryは公開GHCR、秘密/台帳/ログ/Schedulerは無料枠内を標準条件にして待機費を避ける。顧客数に比例するShared Secretやconfig世代の増加が枠を超える場合は、待機費のある別案として費用を提示し、実行時課金の許容だけで自動採用しない。

Schedulerはcleanup保証のため1分周期を候補とし、日常的な撮影は起動しない。空台帳でもworker呼出し/DB queryは消費するため、その月間量を待機時の管理費として示す。不要poll/起動時以外のSDK accessを抑え、無料枠内か実測する。無料残量が不明なら「不明」と表示するが、明示した実行費用上限が有効なら利用可能。料金そのものが不明、保管/管理費が固定費条件を満たすか不明なら構築しない。

## イメージの実測と配備経路

既存localイメージを変更せず読み取り確認した。両imageのrevision labelは `11676e022d326444945a00671c5859c2784c1ab8`。runner ID `c330cb805b88…`、dispatcher ID `7ff25b01e5fc…`。`docker image ls` の表示はrunner 3.66GB/dispatcher 415MB、`docker image inspect` のSizeは996,262,468/91,288,402 bytes（合計約1.013GiB）。表示差は同じ測定量と扱わず、どちらもRegistryの課金保管量の証拠ではない。圧縮manifest/layerの実保管量は未測定で、無料0.5GiBに適合したとは判断しない。再build・pushは今回行わない。

候補は `ghcr.io/olein-jp/<承認したcomponent名>@sha256:<検証済みmanifest>` → Cloud Run Service/Jobの直接import。amd64/platform・検証済みcommit・digest・公開可否を固定する。tag/latest、任意ホスト、private package、有料remote repositoryへの自動fallbackを拒否する。GHCR現行＋rollback用の2世代/componentを残し、実行中Executionのdigestは削除保護する。公開にはimage内容/履歴に秘密がない確認が必要。

#39で公開GHCRのanonymous pull、Service/Jobの直接配備、cold start・cache期限後の取得・旧digest rollbackを実証する。Google管理cacheの利用者課金がないことも対象SKUと資源一覧で確認する。直接取得不可なら停止し、Artifact Registryへ無断で切り替えない。代替Registryは圧縮layer実測、共有layer/旧世代/更新時二重保管/他用途を含むピーク0.4GiB以下を証明できた場合だけ別途再設計する。

## 実行量と従量課金の試算方法

旧「月8Run/6Snapshot/PNG2MiB/0円」profileを実装しない。最大20Target×初期3Device＝60Snapshot、既存の1画像20MiB・multipart42MiB契約を維持し、小画像制限で20ページの実用性を損なわない。1撮影120秒、browser2、Task1800秒/retry1を上限候補とする。60撮影がすべて最悪120秒なら並列2でも3600秒で、1attempt内の全成功は保証できない。上限で中止/部分失敗とし、時間を無断延長しない。通常実測で1800秒に収まらない場合は分割Run等を再設計する。

| 1Runの予約対象 | 最悪量・費用への対応 |
|---|---|
| CPU/RAM | 2attempt×1800秒＋起動/終了の余裕120秒＝3720秒。CPU2/2GiBで各7440 vCPU秒/GiB秒を予約。実際の課金時間超過も停止/検証対象。一時NAT代案では別途90分のネットワーク時間予算を予約 |
| Google→WordPress等の送信 | 60×42MiB×2attempt＝5040MiBのUpload envelope＋制御要求/再送/ページPOST/TLS等の余裕。全attempt共有6GiB/Runを初期上限候補。費用は6×対象送信単価を保守予約 |
| 受信 | 既存1GiB/attemptを全attempt合計2GiB/Runへ共有。Baseline/撮影/Manifest等を含む。Googleの受信無料と相手の転送料を区別 |
| 秘密/台帳/ログ | Run Secret最長90分、非秘密台帳30日、固定秘密/rotation世代/SDK accessとDB retry/index read/worker/logを含める。顧客数に比例する待機費を別計上 |
| 更新前後1組 | 上記の2Run分＝最大14880CPU秒/14880GiB秒・送信12GiB。初回baselineも別Run。Task retry込みで二重に予算を消費させない |

月額の総額は `待機保管/管理費＋N×(7440×東京CPU単価＋7440×東京RAM単価＋6GiB×該当送信単価＋受付/秘密/台帳/ログ操作費)` を保守的な上限試算の出発点とし、無料枠の実際の共有残量による控除を後から適用する。Nは実Run数で、今回合意した月固定値ではない。通常実行では予約より少なくなるが、PNGサイズ/撮影時間未測定の段階で金額を断定しない。東京のSKU、ネットワーク宛先、契約通貨、実利用量を#39の配備計画に添付する。試用クレジットは計算に入れない。

既存HTTP wire計測はTLS/TCP再送/ブラウザ直接通信をすべて含まないためGoogleの課金量と同一視しない。全attempt共有の送受信byte/時間/回数予算をサーバー側で制御し、計測誤差や公開不正要求等の残余費用を記録する。

## 東京の数値試算と待機費の判定

単価は2026-10-10の公式料金。USD・税/為替換算前。無料枠の控除0、1サイト20ページ×3Device、更新前後2Run、各Run retry込みの予約量を使う。通常利用の平均額や請求の絶対上限ではなく、設定容量から計算した予算の目安である。[Cloud Run料金](https://cloud.google.com/run/pricing)、[Tokyo/Premium Tierの転送料金](https://cloud.google.com/vpc/network-pricing)、[NAT料金](https://cloud.google.com/nat/pricing)が根拠。

| 更新前後1組の費目 | 計算 | 小計 |
|---|---|---|
| Runner計算 | 14880×($0.000018+$0.000002) | $0.2976 |
| 国内WordPress等へ送信 | 12GiB×$0.12 | $1.4400 |
| 一時NATの処理 | (送信12＋受信4)GiB×$0.045 | $0.7200 |
| 一時NAT/IPの保持 | 各Run作成～撤去90分、合計3時間×$0.06 | $0.1800 |
| **主要費目合計** | 上記4項目 | **約$2.64/組** |

NATの$0.06/時は、公式gateway最大料金帯（32台までの計算でも$0.0448/時）を$0.045へ切り上げ、Tokyoの未使用予約IPv4 $0.015/時を加えた保守単価。利用中IPv4は$0.005/時。手動割当1IP/1gatewayだけを許可する代案を前提にし、Run数をVM数と同一視しない。実際には利用中IP単価や短い稼働時間で小さくなり得る。自動IP増設はこの見積りの対象外で拒否する。国内サイトでも外部CDNの所在地は異なるため、国内送信単価を全宛先へ適用しない。未分類の宛先には確認済み最高宛先単価$0.23/GiBを予約すると主要費目は約$3.96/組になる。

これにDispatcher/管理controllerの処理、台帳/秘密/ログ操作、制御通信・実課金との差分、GHCR配備検証費を別計上する。主要費目を総請求額と表示しない。月額は実行組数×主要費目＋実測した管理/保管費（片方だけのRunは別計算）。月の回数や支出上限は未指定のまま残す。

**待機費は条件付きであり、現時点で0円保証は成立しない。** 固定config2version＋登録SサイトのShared Secret各1versionなら、他用途がなく全月保持時のSecret保管超過は `$0.06×max(0,2+S−6)/月`。5サイトなら$0.06/月、20サイトなら$0.96/月。実行用2versionとrotation世代は時間比例で追加し、disabled世代も含める。他用途の消費を加えると無料で保持できるサイト数は減る。サイト数を制限するか、利用終了時に登録秘密をdestroyし再利用時に再登録する運用が必要。秘密をFirestoreへ移して問題を隠さない。

1分周期の管理workerは31日で44640要求。CPU1/RAM0.5GiBのrequest-based Serviceなら、平均1秒/要求でCPU44640秒・RAM22320GiB秒・要求料金を含め無料控除前約$1.15/月、上限10秒/要求なら約$11.29/月（cold startやretry等は別）。共有無料枠に余裕があれば控除されるが、撮影がない月も消費する。専用default DBの空queryが1read/回なら1440read/日、31日で44640read。実際のindex/query/retryを別計測する。無料枠不足時に管理workerまで止めない。利用者の実行時課金許容を待機管理費許容へ読み替えず、配備前に共有残量・顧客数・管理処理の実測を提示する。

## 起動前予約・停止・cleanup

現在のsite別10件/分・未終端2件だけでは課金アカウント全体の月次上限を守れない。#39で既存FirestoreStore/AcceptanceLedgerを拡張し、次を同じtransactionで扱う。通信bodyを拡張して自己申告Snapshot数を信用する方式は採用しない。

1. HMACとcallback登録を確認した新規受付に、固定最大60Snapshot/全最悪予算を予約する。月次account quota、日次DB操作予算、全環境active枠、site枠、受付record `(site_id,run_uuid,digest)` を原子的にcommit。Secret作成とjobs.runはcommit後。既存UUID同digestは同じ予約を返し、別digestは409。起動後Manifestが20Target/3Deviceまたは60Snapshotを超えれば失敗とし、予約を追加せず停止する。次段階で正確な見積りAPIを加える場合もサーバー検証を必須とする。
2. 予約にpolicy version、billing account識別、UTC課金月、Firestore太平洋日付、cost vector、lease generation、attempt ID、消費/解放状態を保存。月境界前62分以内の新起動を拒否し、境界をまたぐ不明実行は両月へ保守的に予約する。Task retry/同一再送に新規予算を与えない。起動不明でjobs.runを再送しない既存契約を維持。
3. 設定した利用/従量費用の枠超過は429 `odvr_usage_limit_exceeded`、料金/課金上限設定/telemetry不明・cleanup障害・経路未採用は503 `odvr_usage_unavailable`。どちらも新規のSecret作成/Job起動0。status用量は非秘密の制限、予約済、消費、残量またはnull、計測時刻、有効期限、停止理由、policy versionだけ。上限エラーでWordPressに作成済Runがある場合は既存Runを失敗確定しTokenを失効、履歴を残す。画面から上限解除しない。
4. 期限切れleaseだけでは消費予約を返さない。未起動が確定した受付はSecret/IAM cleanupとlaunch不能の確定後に一度だけ計算枠を返せる。起動済/不明は最悪予約を当月消費として維持し、Execution終端と秘密削除を確認してactive枠だけ返す。実測値が完全でない間は月枠を払い戻さない。新規予約/月初リセットも旧不明実行を消さない。
5. 専用OIDC workerは1分周期・1呼出10秒/100record以内、API retry回数と操作予算を共有する。Secretは署名時刻＋90分のexpireTime/固定数値versionと期限付きIAM、終端後削除、孤立cleanupを維持。cleanup遅延5分またはversion数異常で新規停止。台帳は30日重複防止後に明示削除（Firestore TTL不使用）。月集計は当月＋前月保持、不要indexを制限。worker通常予算が尽きたら起動/通常pollを止め、既存秘密/予約回収だけを専用余裕で処理する。必須余裕まで不足なら全新規停止・運用対応、待機費ゼロを宣言しない。

送信/時間/操作予算はJob内・再試行間でもサーバー側で共有し、telemetryを冪等なattempt/sequenceで集計する。消費の報告が失われても最大予約を保持する。課金アカウントを複数projectで共有する場合の予約台帳は一つの専用default DBへ集約し、Firestoreのcollection単位IAMによる隔離を仮定しない。別の台帳を各projectに作って同じ無料枠を重複配分しない。

利用制御を無料枠だけのquotaから、無料優先＋明示した従量利用予算へ変更する。policyはregion/SKU/単価/通貨、1Runと月次account/siteのCPU/RAM/送受信/操作上限、従量費用の目安上限と有効期限を持つ。月の金額上限は利用者未指定で、#39の具体的見積り時に設定する。未設定を無制限と扱わない。無料枠不明なら無料控除0で保守予約し、支出枠内なら実行を許可する。料金/支出枠不明・設定失効では新規停止。月次/日次/同時枠の原子予約、期限回収、不明起動への払い戻し禁止は維持する。

Shared Secret/config/台帳/Scheduler等の待機費も確認し、従量の実行費と画面上で分ける。GET照合も無制限pollせずserver cap/backoffを適用。停止時の必須cleanup余裕を別予約し、cleanupまで止めて保管費を増やさない。

予算通知は強制停止ではない。公開HMACの不正要求、Cloud Runの一時的なmax超過/課金時間、TLS/TCP再送、直接socket、ログ/SDKの追加操作、並行transaction失敗、cleanup遅延、外部利用、課金計測遅延はアプリ予約では完全に止められない。ログは許可リスト/出力量上限＋不要request log除外、監査/Requiredの必要記録は保持する。有料Cloud Armor等を固定費条件の別途確認なしに追加しない。金銭的な絶対上限が必要ならこの候補を採用しない。

## NATなし案の不採用判断と通信防御

[既存設計](network-security-design.md)のアプリ層を維持し、検討したNATなし案はDirect VPC `private-ranges-only`＋専用IPv4 subnet/tag＋private/reservedへのdenyである。以下は不採用推奨の理由を残す比較記録。公開宛はCloud Runの直接経路、VPCへ送る内部宛はdenyする。全特殊範囲がこの設定でVPCへ送られると仮定しない。Metadata/loopback/IPv6はアプリ検査と実測が必要。

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

## 推奨代案：テスト時だけNATを用意する

NATなし案の公開port/UDP防御喪失は構造的で、実機試験で同等防御へ変わるものではない。安全要件を維持するには、**RunnerをDirect VPC all-trafficに固定し、一時Public NAT＋1外部IPv4を実行時だけ作成/撤去する代案**を推奨する。常設NATは復活させない。[Cloud NATとCloud Runの連携](https://docs.cloud.google.com/nat/docs/nat-product-interactions)はDirect VPCのService/Jobに対応する。NAT自体を防御とせず、既存のprivate/reserved高優先deny、公開TCP80/443と承認resolverのみallow、残りdeny、固定HTTP transportを保つ。公開Origin制限、Metadata/loopback、秘密メモリは引き続きアプリ/IAM側の責務で、全面的なプロセス隔離を保証しない。

代案は次の状態遷移を#39で実装する。新しい管理権限と障害時の継続課金を伴うため、現行のNATなし前提に無断で置き換えて構築しない。

1. Run受付は予算を原子予約し、network leaseを`preparing`へ進める。専用ネットワーク/Router/subnet/firewallは保持可能だが、外部IP/NATはidle時に保持しない。費用と所有範囲を別管理する。固定名のgateway/手動割当1IPを専用管理controllerが作成し（専用subnet範囲、endpoint type VM、1instanceに必要なport数の2倍を予約し、1IPのport容量不足なら起動を拒否）、設定・API operation完了・疎通/deny fixtureを確認して`ready`にする。準備完了前にJobを起動しない。生成応答不明では同じ資源を照合し、別名で再作成しない。既存Dispatcherの30秒要求内で作成完了を約束せず、受付台帳から非同期で起動を進める。
2. 撮影Runner、公開POST処理DispatcherにCompute編集権限を付けない。controllerはOIDC/IAM認証された専用経路とし、固定project/region/Router/IP名のallowlist、利用可能なIAM条件と専用project隔離を組み合わせる。IAMが資源単位へ絞れない操作はproject範囲の権限としてレビューする。ページ入力からネットワーク名/ルール/宛先を編集できない。controllerは公開インターネットから直接起動不可、min0とし、Google制御API/Firestore/Secret cleanupへNATに依存せず到達できる経路を分ける。Dispatcherも制御APIのみ直接利用する代案で、旧Dispatcherのall-trafficとは別に検証する。
3. 同じgatewayを使う最大2Executionのactive leaseを参照数だけでなくgeneration付きで台帳管理する。解放leaseだけでJob終端を推定しない。最後のExecution終端確認後、`closing`にして新規受付を止め、NAT削除→operation完了→手動IP解放→資源一覧の不存在を確認して`idle`へ戻す。旧generationのcleanupが新しいgatewayを消せないようにfencingする。終了待ちを次の顧客まで延ばさず、更新前後でWordPressを更新する待機時間もNATを残さない。
4. 初回資源作成から最大90分を運用期限候補とし、期限到達で受付停止、対象Executionキャンセル/終端照合、gateway/IP撤去を進める。期限をまたぐ新Runを追加しない。cleanup障害・孤立IP・ネットワーク設定不一致では新規503、cleanup retryと運用通知を継続し、手動で固定所有資源を照合/撤去できる手順を持つ。キャンセル不明のまま正常idleにはせず、緊急gateway切断は対象Runを失敗扱いにして記録する。別projectの資源を巻き込まない。
5. #39で準備失敗、API応答喪失、同時2Run、retry、90分期限、cancel失敗、削除/解放失敗、controller停止からの復旧と「終了後NAT/IPなし」を実証する。NAT/手動IPの自動TTLを仮定しない。残存すれば請求は継続し、上記$0.06/時の保守単価なら24時間で$1.44、30日で$43.20の残存時間費に達し得る（通信等は別）。予算通知は削除や強制支出停止の代わりにならない。

この代案を採用するか、クラウド化せずローカル接続を継続するかが残る判断。代案は常設費を意図的に持たず既存ネットワーク制限を維持するが、削除失敗時にも待機費が絶対0円という条件は満たせない。採用判断後も#39はローカル実装から始め、資源作成・公開前に具体的な検証費用と撤去対象を提示する。

## 後続実装計画（5項目）

採用前は設計/ローカル検証まで。実機試験は対象・最大20ページの利用量・実行時費用と待機費・残余リスクを提示して採用判断した後に行う。#39の完了や本書の存在だけを構築許可にしない。

| 順序・担当Issue | 変更予定ファイル | 変更と検証 |
|---|---|---|
| 1：#39 | `infra/cloud/{plan,deployment}.mjs`・同test、`infra/environments/*.example.json`、`infra/scripts/cloud-*.mjs` | 正のbudget_usd必須を見直し、待機固定費回避・実行従量許容と単価/従量利用上限/有効期限を別設定化。常設NAT/外部IP/Registry固定を除去、一時NAT/controller/leaseを採用判断後に追加、Runner all-trafficを維持、GHCR digest allowlist、更新テストprofile、停止gate。未採用/料金・利用枠不明/有料保管fallback/旧設定は計画生成でも拒否するテスト |
| 2：#39 | `apps/dispatcher/src/{types,ledger,firestore-store,engine,config,server}.ts`、同tests、`packages/shared/src/`、`packages/schemas/src/` | account/global月・日・同時枠の原子予約、冪等精算、期限/境界、不明起動、cleanup/操作予算、status/定型エラー。並行10POST/同digest/再送/transaction競合/期限回収/停止中cleanupを検証。旧台帳移行は受付停止して未知を保守予約 |
| 3：#39 | `apps/runner/src/{job,config}.ts`、`apps/runner/src/security/`、`apps/runner/src/api/`、関連tests、`infra/cloud/` | Manifest20Target×3Device/画像20MiB/送受信/time/retryの共有予算、telemetry喪失時停止、既存固定transport維持。一時NATのall-traffic/deny・作成から撤去・障害復旧、GHCR import/cache/rollback、IAM/Secret否定/expiry/実retry、操作・実課金量を検証。任意プロキシやAPI overrideを増やさない |
| 4：#40 → #41 | `wordpress/od-visual-regression/includes/`、`wordpress/od-visual-regression/rest/`、`apps/admin/src/`（作成予定）、関連tests、`docs/admin-ui-design.md`・`docs/api-security-storage-design.md` | Settings/Run確認に上限・予約・消費・残量null・計測時刻・停止理由を表示。開始済Runの失敗と開始前拒否を区別し既存Run履歴/非公開Blobを維持。401/429/503/失効/応答喪失を検証、自動再Runなし |
| 5：#13（#39/#40/#41後） | `docs/{mvp-integration-validation,implementation-status,cloud-staging}.md`、既存結合fixture、`.github/workflows/` | baseline→比較→Viewer、上限直前/超過/不明/並行予約・retry・cleanup、実行時転送/計算と待機時保管/秘密/管理ログを分け、遅延後請求の照合。通常CIはローカルだけ、手動実機記録と撤去を分離。未測定を0円/合格にしない |

## 採用に必要な条件・未解決事項

費用方針・利用用途・最大20ページ・国内顧客中心は利用者確認済み。無料送信の適格性や月8Runへの合意は採用の必須条件から外す。残る条件は次のとおり。

- Device3種類/同時2Execution/Task30分/共有byte上限を#39で実測して容量を確認し、顧客数・月次運用上限・東京単価/待機管理費を配備計画へ明示する。
- 公開GHCRの直接配備/cache/rollback、秘密非露出、共有の固定version/台帳/Scheduler/logの待機費条件を確認する。保管等の継続費が必要なら実行時課金と区別して提示する。
- NATなし案は独立firewall制御を維持できず不採用推奨。一時NAT代案の採用と、撤去失敗時の継続課金への対応を判断する。ページからguard迂回があれば不採用、全面的なプロセス隔離が必要なら別基盤を設計する。

#57は一時NAT代案への採用判断待ちとして未解決を維持する。#39のAPI有効化・資源作成・デプロイはまだ行わない。既存WordPressサーバー料金と追加Google Cloud料金を分ける。今回の検証は文書・料金資料・試算式の照合で、実機は未実施。

## 改訂後のIssue #57受け入れ条件

| 条件 | 今回の判定 |
|---|---|
| 不定期更新テスト・最大20ページ・国内顧客・従量許容 | 利用者確認済み、旧0円/月8Run条件を撤回 |
| 固定費回避と実行時/待機時費用の区分 | 東京計算/国内送信/NATの数値試算と待機費を記録、共有利用/実測は#39 |
| 20Target×3Device容量・retry/送受信/予約/停止/cleanup | 設計候補を更新、実装/実測は#39 |
| NAT前後の防御差・安全な推奨構成 | NATなしは不採用推奨、一時NAT代案と撤去障害対策を提示。採用判断待ち |
| 仕様/設計/依存/5項目計画 | 本条件へ更新、関連Issueに反映 |
| 採用前に構築しない | 停止維持、クラウド操作なし |
