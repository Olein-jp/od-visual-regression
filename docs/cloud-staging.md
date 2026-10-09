# Cloud Run 基盤の準備と実機検証

[Issue #39](https://github.com/Olein-jp/od-visual-regression/issues/39) の準備段階。現時点では **未完了**。構築計画の生成とローカルでの定義検査を追加し、クラウド資源の作成・公開・実測は行っていない。Service/Job/Scheduler の構築、WIF による明示 release/rollback、既存 CI の path 別判定も後続作業として残る。計画だけを根拠に Issue を閉じない。

## 環境設定と計画

`infra/environments/staging.example.json` と `production.example.json` を、Git 管理外の `.odvr-cloud/` にコピーする。`null` は未確定値であり、そのままでは計画を生成できない。実 project ID と番号、Run Secret 専用 project ID と番号、region、運用者アカウント、専用 named configuration、整列済み RFC1918 `/16`〜`/26` subnet、検証用 site、費用上限の目安、cleanup 予定日数を設定する。運用者アカウントは構築用で、runtime/build/deploy SA と分ける。

`sites` の各値には `callback_base`、`shared_secret`、`shared_version` だけを指定する。callback は実在する公開 HTTPS の WordPress Runner API、Shared Secret は通常 project 内の Secret 名、version は固定数値文字列。Token・Basic・Shared Secret 本文は入力しない。callback の DNS 全回答と TLS、gateway・画像保護は、実機の事前検証で確認する。設定の構文検査は DNS 到達性の保証ではない。

```sh
npm run cloud:plan -- .odvr-cloud/staging.json
npm run cloud:plan -- .odvr-cloud/staging.json .odvr-cloud/production.json
npm run test:cloud-plan
```

別環境の設定も渡すと project ID と番号の再利用を拒否する。production 設定が未確定の間は staging 単独の準備だけを行い、production との分離を検証済みとは扱わない。設定ファイル・計画・実測の内部記録は `.odvr-cloud/` に置く。

出力は対象別の `gcloud` argv 配列と計画 SHA-256。**gcloud を実行しない**。全操作に `--project`、`--account`、`--configuration`、地域資源に `--region` または `--location` を付ける。Cloud SDK の未導入・未認証でも定義検査は可能。構築時は承認済み計画を対象資源ごとに確認して実行し、返却された project 番号・SA unique ID・Job generation・Service URL を記録する。現在の計画は新規作成用で非冪等。既存資源に一括再実行せず、describe と差分で整合を確認する。preflight の describe 結果を project ID/番号と比較するまでは後続コマンドを実行しない。

計画に含まれるのは API 有効化、専用 SA、immutable Registry、Firestore、VPC/subnet/NAT、egress firewall、runtime custom role と範囲限定 IAM、非秘密 config 用 Secret、制限付き WIF provider の準備。Shared Secret 本文の作成、Service/Job/Scheduler 作成、config version 追加、デプロイ権限、WIF の SA binding は含まれない。Shared Secret の IAM を設定する前に、運用者が Secret 本体と指定 version を別途作成・確認する。

## 作成前に確認・承認する範囲

Issue の検証条件は「クラウド実リソースの新規作成・デプロイは、対象と費用範囲を示して承認を得てから実施する」。実 project・請求先・region が確定したら、計画 hash、作成/変更する資源、見積り、最大試験回数、終了日時、cleanup 担当を提示して承認を得る。通常 push/PR CI はローカルの定義検査だけで、OIDC 権限やクラウド資格情報を持たない。

費用対象は Registry 保存/転送、Cloud NAT の稼働/転送・外部 IPv4、Firestore、Secret Manager、Cloud Run CPU/メモリ/通信、Scheduler、ログ。NAT などは試験していない時間も課金対象になり得る。`budget_usd` は承認時の目安で、支出の強制停止機能ではない。地域・料金・使用量が未確定なので、この文書に固定の金額を置かない。Cloud Billing の予算通知も費用の強制上限とは扱わない。

既存本番 WordPress を流用せず、ダミー資格情報の公開 HTTPS staging site を使う。wp-env の RFC1918 接続例外をクラウドへ渡さない。

CGI/FastCGI の共有サーバーで生multipartが未対応の場合は、[Runner専用入口](wordpress-cloud-gateway.md)の設置と実機診断を先に行う。ドメイン全体のフォーム展開設定を無効化しない。

## 通信と IAM の検証条件

専用 IPv4 subnet を作り、製品 Service/Job は Direct VPC egress **all-traffic** と専用 network tag を必須にする。現在の計画は private/link-local/予約範囲の deny を優先度100、公開 TCP80/443 allow を200、残り deny を300にする。UDP・別 port・VPC 内の別 DNS resolver は許可しない。Cloud Run の既定 resolver が使われる経路は実測し、未検査の resolver へ変更しない。[Direct VPC egress の仕様](https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc)では VPC firewall logging に制限があり、ログ不在を拒否の証拠にできない。

Metadata/既定 DNS/loopback などのローカル経路は VPC firewall だけで保証しない。SDK の ADC が必要とする Metadata 通信と、ページ・撮影 transport の拒否を区別して観測する。一般 socket から private canary へ接続し、相手側の accept がゼロであることも測定する。実 Runner image で共通 transport と browser の iframe/fetch/worker/Beacon/popup/WS/SW/WebRTC/WebTransport を試し、Metadata 名/literal、private IPv4、IPv6・変換経路に HTTP 到達がないことを確認する。Metadata の token 取得 endpoint は試験対象にしない。未制御のページ通信が残る場合は公開不可。

Dispatcher の Job 権限は対象 Job/Execution 条件付き custom role、Operation get は project 範囲、Firestore は専用 project の受付 DB、Run Secret の作成・metadata・version 追加・IAM・削除は専用秘密 project の custom role。Run Token の version access を Dispatcher に直接付与しない。Secret setIamPolicy による自己昇格と共通 Runner SA による同時 Run 間の残余リスクは [基礎設計](dispatcher-cloud-run-design.md)のとおり残る。IAM 条件の resource 名と permission の効き方は実測して確定する。

Runner は Run Secret ごとの期限条件付き Accessor に加え、**非秘密 config ファイルを mount するためだけ**に通常 project の `odvr-runner-config` の Accessor を持つ。project 全体、Shared Secret、Firestore、Job 更新/起動の権限を与えない。config は JSON を固定数値 version で read-only mount し、環境・Job override から任意 config を選ばせない。

WIF provider は実際に確認した GitHub の repository ID `1409590981`、owner ID `28924629`、main ref、対象 Environment の subject、明示 release workflow ref と固定 audience に制限する。[Google の WIF 手順](https://docs.cloud.google.com/iam/docs/workload-identity-federation-with-deployment-pipelines)を参照。現在は binding と release workflow が未実装で、WIF を使ったクラウド操作はできない。保護された Environment の required reviewer/branch 制限を確認してから、build と deploy の権限を別 SA へ付ける。

## デプロイと rollback の実装時に満たすこと

検証済み linux/amd64 image を同じ manifest のまま Registry へ promotion し、manifest digest の一致を確認する。Runner は task1/parallelism1、CPU2/memory2Gi、timeout1800s、retry1、browser2、GEN2、引数なし `node apps/runner/dist/job.js` に固定する。Dispatcher は CPU1/memory512Mi、timeout60s、concurrency8、min0/max2。profile は cloud、config は固定数値 version、API endpoint は既存 adapter の固定値を維持する。

初回 Service は非公開、Scheduler は停止状態で構築する。Service URL/Scheduler SA subject を取得し、Runner Job の実 generation/image/SA を config に記録して Dispatcher を更新する。config version を決める前に generation を推測しない。外部 HMAC 受付の公開は staging の許可範囲を確認した別操作にし、production 公開は全 canary 合格後に限る。

通常マージ/タグはデプロイしない。workflow_dispatch に environment/component/committed revision/digest/config version を明示し、進行中を cancel しない concurrency を使う。Runner 更新前に受付を止め、accepted/launching/launch_unknown を照合する。Job 更新は既存 Execution を再実行しない。Job generation が変わったら Dispatcher の登録設定を同期する。Scheduler OIDC audience は実 `/internal/reconcile` と一致させる。

履歴には旧 Service revision/traffic、Job 定義/digest/generation、両 config version、非秘密環境設定 hash を記録する。Service は旧 revision の traffic に戻す。Runner は旧 digest/Job 定義を再 deploy し、**新 generation** と一致する Dispatcher config を作成する。旧 generation の JSON をそのまま再利用しない。rollback でも Firestore 受付・UUID・Token 期限を消さず、Schema 非互換なら停止して前方修正する。

## staging 実測記録

各行に UTC 時刻、project/region、image digest、Git revision、実 resource 名、非秘密の期待/実結果、証拠ファイルを記録する。Secret payload・HTTP body/header・資格情報・SDK debug・環境 dump を記録しない。未測定の行を「合格」にしない。

| 対象 | 合格条件 | 現在 |
|---|---|---|
| 基盤 | project/番号・region・最小権限・private config・all-traffic が実定義と一致 | 未測定 |
| 結合 | HMAC受付→Operation→Executionの対応、撮影→PNG Upload→Complete、終了後cleanup | 未測定 |
| IAM拒否 | RunnerのShared Secret/他project/Firestore/Job変更、DispatcherのToken access/Job変更、fork/別EnvironmentのWIFを拒否 | 未測定 |
| 通信canary | 許可先成功、private一般socket拒否、ページ/transportのMetadataと迂回通信拒否、IPv6無効確認 | 未測定 |
| Run Secret | expiresAt固定、期限前のみIAM access、失効後拒否・自動削除、Execution終端後delete・孤立cleanup | 未測定 |
| Task retry | 実task attempt 0→1、同Execution ID、既保存結果を保持しpendingのみ再開、3回目なし | 未測定 |
| rollback | Service traffic復元とJob再deploy、新generation同期、台帳/稼働Run保持 | 未測定 |
| 秘密非露出 | ダミー資格情報がログ・台帳・image・Job override・CI成果物・監査記録にない | 未測定 |
| 後始末 | 試験Run/Secret/Scheduler/Job/Service/NAT/Registry/専用基盤を承認範囲で処理 | 未測定 |

期限試験を省くため製品の90分期限を短縮しない。実 retry は応答喪失などの回復可能な fixture 障害を検証用 WordPress 側に一時注入し、同 Execution の Cloud Run task attempt と台帳で確認する。製品 Job に任意 command/env override を渡して別試験入口にしない。

終了時は受付を止め、Scheduler を pause、終端 Execution と Run Secret 削除を照合し、所有ラベルを確認した試験資源だけを整理する。Firestore の削除保護は運用者が明示解除するまで維持する。staging project に他用途の資源がないことを確認してから撤去対象を決め、production の資源を削除しない。subnet は Cloud Run の IP 解放待ちで削除に1〜2時間かかる場合がある。[Direct VPC の IP 解放](https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc#ip-address-allocation)を踏まえ、NAT の残存・課金を含めて後日確認する。
