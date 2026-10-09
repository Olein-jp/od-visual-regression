# DNS rebinding防御とローカル接続の設計

Issue #5の設計成果物。根拠は[仕様v1.1](specification-v1.1.md) §61〜62・§68〜70、秘密の契約は[Issue #4の設計](api-security-storage-design.md)。本書は採用候補であり、実装済みの防御を示さない。今回は設計文書のみ変更する。WordPressが管理・保存、Dispatcherが受付・起動、Runnerが撮影・比較を担当する責務を維持する。

#34で用途別policyと共通HTTP transportを実装した。入口・予算・検証範囲は [pinned-http-transport.md](pinned-http-transport.md) を参照する。ブラウザ適用とクラウド経路の実測は後続Issueであり、以下の設計当時の記述と区別する。

## 現状と調査範囲

| 対象 | 現状と不足 |
|---|---|
| `apps/runner/src/security/url-validator.ts` | HTTP(S)、完全一致Origin、URL内資格情報、内部ホストを検査。ipaddr.jsでIPv4-mapped IPv6をIPv4として評価し、公開unicast以外を拒否。DNS全回答に非公開IPがあれば拒否するが、返す値はURLだけ |
| `apps/runner/src/security/network-guard.ts` | Context単位のHTTP routeで検査後に`route.fetch()`する。この取得が再度DNS解決するため、検査と実接続の間に隙間がある。自動redirectは0、304以外の3xxは拒否。WebSocketは閉じる |
| `apps/runner/src/browser/context-factory.ts` | Service Workerを禁止し、Basic認証を撮影Originへ限定。TLS検証を無効化せず、ダウンロードを禁止 |
| `apps/runner/tests/security.test.mjs` | IP分類、Origin、混在DNS、Manifest検査の単体テスト。実際のsocket接続先、redirect、ページ以外の通信は未検証 |
| 直接依存する`config.ts` / `jobs/execute-run.ts` | Manifestと撮影URLを事前検査し、ページ生成前にContextのguardを設定。ManifestとBaselineは現状ローカルファイル。callbackや製品APIクライアントはまだない |

入口・既存挙動・検証方法の調査は上記で終了する。ローカルのWordPressは現状撮影できない。現在の`route.fetch()`のtimeout 30秒だけでは、レスポンス量、Run全体の通信量、DNSとsocketの固定を保証できない。

## 方式比較と推奨

| 方式 | 接続固定・TLS/Host/SNI・IPv4/IPv6 | 判断 |
|---|---|---|
| DNS事前検査＋Playwrightによる取得 | ブラウザ側の再解決・接続選択を固定できない。TLSは保持できてもTOCTOUが残る | 現状。公開不可 |
| Chromiumのhost resolver設定でRun中のIPを固定 | Nodeのcallbackには適用されず、別のnetwork stack、接続再利用、IPv6、Origin変更への制御が分散する | 防御の補助に限定 |
| 明示forward proxy / CONNECTで接続固定 | proxy側でIPv4/IPv6を検査・固定できる。CONNECTならTLSはend-to-end。ただしトンネル内のOrigin・redirect・資格情報をproxyだけでは検査できない | 有力な代替だがMVPでは採用しない。TLS傍受CAも導入しない |
| Runnerの共通HTTP transportで解決・検査・固定接続し、Context routeへ応答を渡す | Nodeの接続生成を所有する。元のHost・証明書名・SNIを保持し、両familyを検査できる。APIにも同じtransportを使える | **推奨**。ブラウザの直接送信へfallbackしない |

推奨方式はHTTP取得アダプターであり、任意CONNECTを提供する汎用proxyではない。route handlerはURLとリクエスト情報を共通transportへ渡し、取得済み応答だけを`route.fulfill()`する。`route.fetch()` / `route.continue()` / 未検査のfetchを通信経路から除く。アダプターが利用不可ならRunを失敗させる。

ブラウザの任意socketや侵害されたプロセスまでrouteで保護できるとは扱わない。Cloud Run公開には後述の多層対策と迂回通信の実測を必須とする。

## 接続固定の契約

1. URLをWHATWG URLで正規化し、scheme・hostname・実効portの完全一致Originで判断する。suffix / wildcard / 任意portは禁止。userinfo、未知scheme、不正IP、zone IDを拒否。HTTPはクラウドの認証なし撮影のみ許容し、制御APIとBasic認証はHTTPS必須。
2. 制御用・撮影用の用途ポリシーを選ぶ。IP literalは直接分類、hostnameは承認されたresolverでA/AAAA相当の全結果を取得し、空・timeout・一つでも非公開なら拒否する。CNAMEの名前だけで許可しない。最終接続IPが必須。
3. IPv4-mapped IPv6をIPv4へ正規化する。RFC1918、loopback、link-local、ULA、unspecified、multicast、予約、CGNAT、文書用範囲、Metadataを拒否する。NAT64・6to4・Teredo等の変換/トンネル範囲もMVPでは拒否し、別family経由でIPv4拒否を迂回させない。
4. 検査結果を不変の`{ origin, address, family, port, resolvedAt, purpose }`として接続関数へ渡す。Node `http.request` / `https.request`の接続用`lookup`は、この検査済みliteralだけを返す。hostnameを再解決するfallback、環境変数由来proxy、自動Happy Eyeballsによる未検査IP選択を禁止。MVPは1回につき1 IP、失敗時の候補切替も検査済み結果内だけで最大1回。
5. URLのhostnameを保持してHostを生成し、HTTPSではDNS名をSNIと証明書検証名に使用する。IP literalはIP SANで検証し、不適切なSNIを付けない。`rejectUnauthorized`と通常の証明書検証を維持する。Host、SNI、portをページから指定させず、証明書不一致を無視しない。
6. socketの`remoteAddress` / `remotePort`を正規化して予定値と照合し、不一致ならHTTP本文・資格情報送信前に破棄する。主防御は接続前のliteral固定であり、接続後確認だけに頼らない。HTTPSでも検証完了前にHTTPを送らない。

MVPは接続pool・HTTP/2 coalescing・HTTP/3/QUICを使用しない。1リクエスト1接続で、各新規接続・再試行・許可されたAPI redirectごとに解決と検査をやり直す。DNS TTLにかかわらず前の回答を別接続へ流用しない。IPが途中で変わっても既存socketの宛先は変わらず、次の非公開回答は拒否する。将来poolを導入する場合は用途・Origin・IP・family・port・Runで分離し、寿命と失効を別途設計する。

Nodeの[HTTPS API](https://nodejs.org/api/https.html)と[HTTP API](https://nodejs.org/api/http.html)が接続用lookupやTLS設定を提供することを根拠とする。URL自体をIPへ書き換える実装は採用しない。具体的なsocket生成順序はリポジトリのNode版で確認する。

## 通信境界とredirect

Manifestの`allowed_origins`は撮影用の上限であり、秘密を送ってよい相手の一覧ではない。信頼済み設定と照合してRun開始時に固定し、ページや後から取得したManifestが制御Originを追加できないようにする。

| 入口・用途 | 境界・資格情報 |
|---|---|
| ページ、iframe、画像・CSS・font・script、fetch/XHR、workerのHTTP、sendBeacon | 全てページ生成前にContext routeを設定し、共通transportを使用。撮影用Originのみに限定。popupは最初の通信もContextで検査し、MVPでは生成後閉じる。ページ単位routeで代用しない |
| WebSocket、Service Worker | WS/WSSとも禁止。Service Workerは登録・既存利用ともblock。別Contextを勝手に作らない |
| WebRTC、WebTransport、QUIC、DNS prefetch、ブラウザbackground通信 | 撮影に不要なので抑止。routeで捕捉できると仮定せず、TCP/UDPやMetadataへの迂回を公開前試験で確認。未制御の通信が残る環境は公開不可 |
| data/blob/about | 外部socketを伴わない表示に限る。その中から生じるHTTPも同じ境界。file/ftpやローカルファイルへの遷移を禁止 |
| callback、Manifest、Credentials、Baseline、Upload、Progress、Complete | Dispatcherが登録済みサイトと照合した単一HTTPS制御Originと、固定Runner API経路へ限定。URL・Run ID・Baseline IDは#4の契約も検査。撮影Originから制御APIへアクセス権を継承しない。Baseline URLをManifestから任意取得しない |
| Dispatcher→GoogleのJob起動・運用認証 | 固定Google APIと専用クライアントの信頼済み経路。ユーザーURLに転用不可。Runnerの撮影用Originにも追加しない。IAMは必要最小限 |

Playwrightの[route仕様](https://playwright.dev/docs/api/class-route)ではredirect先へのroutingだけに依存できず、[ページのrouting仕様](https://playwright.dev/docs/api/class-page)にはpopup先頭とService Workerの制約がある。このためブラウザの自動redirectを許可して後段routeで検査する方式は採用しない。

**撮影のHTTP redirectは引き続き0回**。301/302/303/307/308を含む304以外の3xxを共通transportで拒否し、Location先へ接続しない。最終URLをTargetへ指定する。304はcache前提なので、MVPのcacheなし取得では条件付き要求を除去し、想定外304は取得失敗とする。meta refresh・JavaScript遷移は新規リクエストとして再検査し、Target単位の要求数・時間上限も適用する。

制御APIも原則redirectなし。導入時に必要性が確認された場合に限り、GETのManifest/Baselineに同一HTTPS Origin・固定API経路内で最大3回の手動redirectを許可する。毎段階で相対Locationを現在URLから解決→URL・用途・DNS検査→新規固定接続とし、HTTPS降格、Origin/port変更、loop、欠落Location、4段目を拒否。Upload/Progress/Complete/Credentialsは0回のまま。API GETの限定許可は実装Issueで明記するまで無効。

### 資格情報と応答を渡す範囲

Basicは承認済み撮影HTTPS Origin一つ、Run Tokenは制御HTTPS OriginとRunner API経路だけ。どちらもメモリ内で、別用途・別Origin・redirectへ無条件転送しない。CookieはBrowserContextと本来のドメイン/path/secure規則を維持し、transportの独立cookie jarに全Origin分を混在させない。Set-Cookieの複数値、CORS、CSP、Content-Encoding、Content-Lengthの取り扱いを結合試験する。ヘッダーの多値表現を失うアダプターでは撮影互換性を保証できない。

ブラウザ由来Authorization、Proxy-Authorization、Host、hop-by-hopヘッダー、Metadata-Flavorを除去し、Cookieは本来のリクエストに属する値だけ渡す。Basicは共通transportが信頼済み設定から注入し、ページが生成したAuthorizationを採用しない。APIのBearerも制御クライアントでのみ付ける。サイトのスクリプトによる任意認証ヘッダーを必要とする撮影はMVP対象外。

Issue #35で§70と#4設計の秘密適用先を「Origin限定の認証を共通transportへ適用」へ改訂した。秘密の取得元・スコープ・履歴へ保存しない契約は維持する。Contextへ秘密を重複保持しない。URL・ヘッダー・本文・trace/HARをログへ出さず、定型エラーのみ記録する。

## Cloud Runの多層対策と限界

- アプリ層：上記の用途別Origin、全DNS回答検査、接続固定、Metadata名とIP拒否を全ユーザー由来通信へ適用。Metadataは`metadata.google.internal`、IPv4 `169.254.169.254`、IPv6 `fd20:ce::254`、別名やIPv4-mapped表現でも拒否。Metadata-Flavorの除去だけを防御にしない。
- VPC層：Runner JobとDispatcherへDirect VPC egressの`all-traffic`を指定し、対象をnetwork tag等で固定する。撮影HTTP(S)用portと承認済みresolverだけを許可し、RFC1918/loopback/link-local/ULA/予約・変換範囲を高優先度でdeny、その他portとUDPをdefault denyにする。Cloud NATは外向き到達性を与えるだけでSSRF対策ではない。IPv6は対応経路・同等denyを確認するまでクラウドで無効、AAAAを無検査でIPv4へfallbackしない。
- 運用層：クラウドprofile、開発設定不在、egressとfirewallの実設定をデプロイ前に検査。必要なら組織ポリシーで`all-traffic`を強制。DNS/NAT/IPv6/経路変更後は拒否試験を再実施。Runnerのservice accountはJob起動や任意Secret読取を持たず、秘密受渡しは#4の専用経路に限定する。

[Direct VPC egress](https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc)はJobへの適用とnetwork tagによる制御を提供する。[VPC Service ControlsのCloud Run設定](https://cloud.google.com/run/docs/securing/using-vpc-service-controls)は`all-traffic`の強制方法を説明する。ただしVPC SCを一般的なSSRF遮断の代替にしない。

**MetadataとloopbackをVPC firewallだけで遮断できるとは保証しない。** [VPC firewallの仕様](https://docs.cloud.google.com/firewall/docs/firewalls)ではVM自身のMetadata等に例外があり、[Cloud Run runtime契約](https://docs.cloud.google.com/run/docs/container-contract)もインスタンスMetadataを公開する。Cloud Run内のlocal通信が全てVPC経由になるとは解釈しない。これらから、本設計ではMetadata遮断をアプリ層の必須条件とし、クラウド実測で補完する。

Cloud Runはprivileged containerをサポートしないため、コンテナ内iptablesやDockerのnetwork namespace設定をそのまま本番で使えると仮定しない。BrowserContext routeと機能抑止はHTTPレベルの防御であり、ブラウザ侵害による任意socket生成を防ぐOS境界ではない。ページからの正常API利用によるSSRFと、プロセス侵害のリスクを分ける。後者に強い隔離を要求する運用では別のブラウザ隔離基盤の設計が必要で、本書のCloud Run公開対象に含めない。

## ローカルDocker / wp-envの単一接続先例外

同一imageを使い、実行profileはデプロイ定義で固定する。既定は`cloud`。ローカル専用のread-only設定ファイルを開発composeからmountし、Manifest・Job payload・Dispatcher受付・WordPress設定からprofileや例外を指定させない。

例外は**一つの正規化Origin＋一つのIP literal＋一つのport**だけ。例えば開発専用bridge上のWordPressへ、`http://wordpress.test:80`を`172.30.0.10:80`に固定する。数値は例示であり、実際のwp-envの接続先をホスト側で確認してから設定する。Hostは`wordpress.test`のまま。CIDR、複数IP、wildcard、localhost全体、host.docker.internalの無制限許可、全解除フラグは禁止。

Dockerネットワークでは固定IPを割り当て、WordPressのHTTP portだけへ到達させる。wp-envのport公開を利用する場合もDockerホストで必要な単一port・具体的接続IPを確認する。Runner自身のloopbackとホスト上の全サービスを同一視しない。IP変更時はRunを止め、ホスト側の設定を更新する。例外Originは通常のallowed_originsにも含め、例外ファイルだけでURL権限を増やさない。

例外はRFC1918の開発bridge IPに限定し、loopback/link-local/Metadata/予約範囲は例外でも禁止。例外OriginについてDNSを使わず設定literalへ接続固定するため、名前の再解決による許可拡大が起きない。別Origin、別port、別IPへのredirectは拒否。ローカルも全通信用途に同じ単一接続先規則を適用するが、API path・Token・撮影Basicの権限は共用しない。ローカルHTTPの制御通信はダミー資格情報だけを用いる開発専用例外とし、本番Secretは読み込まない。

`cloud`では開発設定ファイルや関連設定値が一つでも存在すれば起動失敗。Cloud Runを示す実行環境では`local`も拒否する。環境識別変数の有無だけを安全境界にせず、クラウドデプロイ定義/CIはprofileをcloudへ固定し、local設定のmount・overrideを禁止、起動検査と両方で拒否する。クラウド設定を編集すれば例外を有効にできる逃げ道を製品設定に提供しない。

## 時間・容量・回数の上限

以下はMVP実装の初期値。Manifestから引き上げられず、信頼済み運用設定もhard cap以内だけ。撮影結果や#4のPNGサイズ契約とは別に、ネットワーク受信時点で適用する。

| 対象 | 上限・超過時 |
|---|---|
| DNS | 1解決5秒、結果最大32件。超過・部分回答・失敗は拒否 |
| TCP/TLS | 接続＋handshake 5秒。HTTP全体30秒、無受信idle 5秒。別タイマーで絶対期限を保証し、slow dripを止める |
| 撮影 | 1Target/device 120秒、最大500 HTTP要求、取得中もdeadlineで全socketを破棄 |
| リクエスト | URL 8 KiB、header合計32 KiB、body 1 MiB。Uploadのみ#4の1画像20 MiB・全体42 MiB・scalar合計64 KiBの契約を使用 |
| レスポンス | header 32 KiB、撮影1応答10 MiB、Manifest/Credentials 1 MiB、Baseline 20 MiB（#4の画像上限と一致）、その他制御API 1 MiB |
| 圧縮・総量 | Content-Lengthを信用せずstreamでwire/展開後の双方に同じbyte上限。撮影合計100 MiB/Target-device、Run全体1 GiB。展開bombやchunkedも途中でabort |
| 同時接続・再試行 | Run最大16接続、1撮影最大8。無限queueを禁止。接続候補再試行1回、送信済み要求の自動再送なし。APIの冪等再送は#4の契約内で別管理 |
| redirect | 撮影/更新API/Credentialsは0。将来の限定GETは最大3、loop/未許可Locationは接続前拒否 |
| Run | #4の総90分とToken期限の早い方で中止。失効・Context終了時にtransportもclose |

## 検証計画

実装後は「例外を投げた」だけでなく、禁止先のacceptカウンターが0、許可先のsocket宛先が固定値、秘密を受信した相手が承認先だけであることを合格条件にする。

| ケース | 再現方法・期待結果 |
|---|---|
| DNS rebinding | resolver fixtureで最初に公開IP、次にRFC1918/loopback/Metadataを返す。検査直後に回答を切り替える障壁を設け、現行の二重解決を再現。新transportは検査回答のliteralへ一度だけ接続し、次の新規接続は拒否。拒否先accept=0。実socket試験では隔離ネットワークに公開分類IPを割当て、インターネット上の他者IPに接続しない |
| DNS/family | A/AAAA混在、空回答、timeout、CNAMEから内部IP、IPv4-mapped IPv6、NAT64/トンネル、短TTL、候補切替、port不一致。混在は全拒否。IPv6未提供環境で試験を黙ってskipせず、公開前に対応環境で実施 |
| TLS/Host/SNI | 同じIPのvirtual hostを使い元Host/SNIの一致、DNS名証明書とIP SAN、期限切れ・別名・自己署名の拒否。秘密送信前の接続先照合、接続生成が別resolverへfallbackしないこと |
| redirect | 相対/絶対/別Origin/別port/HTTPS降格/loop/内部IP、各3xx。撮影と更新APIは全拒否。限定GETを導入するなら各hopで同じ検査と回数、再注入するTokenのscopeを確認 |
| 全ブラウザ入口 | ページ、iframe、画像/CSS/font/script、fetch/XHR、worker、Beacon、popup初回、JS/meta遷移で内部先・別Originを試す。WS/WSS、SW、WebRTC/WebTransport、background通信の接続を観測。許可fixtureだけ成功 |
| APIと秘密 | 登録外callback、Manifest内のURL上書き、別Run/Baseline、撮影から制御API、Basic→別Origin、Bearer→撮影、Cookie混在、ログ/エラー/結果への秘密混入を拒否。CORS/CSP/cookieと圧縮応答の撮影互換性も確認 |
| 上限・後始末 | slow DNS/TLS/body、slow drip、巨大header/chunked/圧縮bomb、連続遷移、期限・回数・総量境界、失効とclose。超過時にsocket/メモリ/queueが残らず、同時実行でもRun総量を超えない |
| ローカル | 設定済みWordPressだけ成功、同IP別port・別IP・別名・localhost・Metadata・例外経由redirectは失敗。設定ファイル不在/複数接続先/変更時は起動失敗。cloud＋local設定、Cloud Run＋local profile、Job overrideは起動/デプロイ拒否 |
| Cloud Run | 実JobでIPv4/IPv6、RFC1918/link-local/Metadata名・literalへの拒否を確認。VPCの拒否canaryには一般socketクライアントで接続しfirewallも独立検証。Metadataには共通transportとブラウザ各入口からアクセスしHTTP到達なしを確認。VPC外のlocal経路を別に観測し、firewallのログ不在だけで成功扱いしない。秘密のtoken取得endpointは試験の読取対象にしない |

## 変更予定ファイルと実装順序

採用後に実装Issueを作成する。以下は予定で、今回は追加・変更しない。

| 順序 | 変更予定 | 完了条件 |
|---|---|---|
| 1 | `apps/runner/src/security/{url-validator,destination-policy,pinned-http-client}.ts`、`apps/runner/tests/{security,pinned-http-client}.test.mjs` | 用途別ポリシー、DNS/IPv4/IPv6/TLS/socket固定、byte/time上限とrebinding試験 |
| 2 | `src/security/network-guard.ts`、`src/browser/context-factory.ts`、`src/jobs/execute-run.ts`、ブラウザ境界テスト | 全HTTPの取得置換、redirect拒否、BasicのOrigin限定注入、cookie/CORS/CSP互換、socket後始末・Run予算 |
| 3 | `apps/runner/src/api/client.ts`等の製品クライアント、Dispatcherのcallback検査、API契約テスト、#4設計/仕様§70 | 全制御通信へtransport適用、登録Origin/経路/Token固定。#4と依存順を調整し秘密適用先の仕様を更新 |
| 4 | `infra/`の開発compose/profileとCloud Run Job/VPC/firewall設定、起動検査、開発手順 | 単一Origin/IP/port例外、クラウドでの拒否、all-trafficと非公開IP遮断の実設定確認 |
| 5 | 結合security fixture、クラウド拒否canary、`docs/security.md` | 全入口・Metadata・上限・非漏洩・迂回試験に合格し、公開判定の証跡を保存 |

## 公開前の必須条件・リスク・未決事項

Cloud Run公開は、上記5段階の実装と検証、#4の認証/Storage契約との整合、クラウドprofile固定と多層遮断の証跡が全て揃うまで不可。設計採用と実装完了を混同しない。

| 項目 | 決定・後続確認 |
|---|---|
| DNS rebinding | Node側の検査済みliteral接続を選定。全入口で未検査transportが残らないことを実装時に確認 |
| ブラウザ応答互換 | Node経由取得ではcookie・CORS・圧縮・認証challenge等の挙動が変わり得る。多値headerを含むadapterの実装方式は手順2の実機試験で確定し、失敗時に直接通信へ戻さない |
| TLS・runtime版 | Node/Playwrightの固定版でcustom lookup・TLS名検証・routeの挙動を検証。参照した公式ページは最新版であり、リポジトリの版での実証ではない |
| Metadata・プロセス侵害 | アプリ経路は必ず遮断する。VPCだけでは保証不能。ブラウザ/Runner侵害に対する完全なsocket隔離は本書では保証しない。正常API利用でも迂回が残れば公開不可 |
| クラウド経路 | `infra/`未実装のため具体的subnet/tag/firewall優先度/resolver/IPv6可否は手順4で確定。公開IPv6経路を提供できない間は明示無効 |
| ローカル接続 | wp-envの実IP/portを実装時に確認。単一固定先が用意できない場合は専用bridgeを作り、許可範囲を広げない |
| 上限 | 初期値は安全側の設計値で、実測済みではない。大きなサイトやBaselineが上限で失敗する可能性は明記し、無制限化しない |
| 先行設計 | #4・§70の秘密適用先変更は採用時に同期。製品のWordPress保存責務、Dispatcher受付、Runner撮影比較は維持 |

今回の検証は指定仕様、既存guard/context/テストと直接依存への設計照合、公式API・クラウド制約の確認、文書リンク・差分検査まで。製品コード、Schema、クラウドリソースを変更せず、製品全テストやクラウド実測は行わない。Firefox、ログイン後撮影、通知、AI解析は対象外。

## Issue #38 実装状況

共通linux/amd64 Image、非root実行、固定wp-env bridge、Firestore emulator、tmpfs秘密、有限queue launcherと内部workerを実装した。[起動・停止・fixture・復元・再起動の範囲と制限](local-images.md)を参照する。Cloud実機は #39、MVP全シナリオは #13 で検証する。
