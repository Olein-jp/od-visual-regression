# 検査済みIPに固定するHTTP transport

Issue #34で、[ネットワーク設計](network-security-design.md)の用途別policyと固定HTTP clientを実装した。ブラウザの取得置換は#35、製品APIクライアントは#36、クラウドの経路・firewall実測は#39で接続する。

## 接続と境界

`DestinationPolicy` は信頼済みの撮影Origin一覧と単一制御API baseを初期化時に固定する。captureとmanifest / credentials / baseline / upload / progress / completeを分離し、制御通信は用途に対応するRunner API経路だけを許可する。Manifestやページ由来の値でpolicyを変更しない。

resolverの全回答（最大32件）を検査し、空・失敗・一つでも非公開IPなら拒否する。IPv4-mapped IPv6はIPv4に正規化する。NAT64・6to4・Teredo、IPv6特殊割当・文書用範囲、Metadataを拒否する。IP literalの接続にDNSは使わない。

結果は不変のorigin / address / family / port / resolvedAt / purpose。`PinnedHttpClient` の既定connectorはhostnameを再解決せず、検査済みliteralへTCP接続する。Happy Eyeballs・環境proxy・接続pool・自動redirectを使わない。候補再試行も行わず、送信済み要求の再送はAPIクライアントの明示処理に委ねる。

TCP接続後にremoteAddress / remotePortを正規化して照合する。TLSは元hostnameのSNI・証明書名、IP literalはIP SANを検証し、不適切なSNIを付けない。rejectUnauthorizedを維持する。TCP/TLSの検査がすべて終わったsocketだけをHTTP agentへ渡し、本文と認証情報はその後に送る。

Connector / resolver / CAの注入はテストまたは信頼済み起動コード用で、通信Payloadに対応するフィールドを提供しない。自己署名を無条件で許可するflagはない。

## ヘッダー・資格情報・応答

ブラウザ由来のAuthorization / Proxy-Authorization / Host / Metadata-Flavor、hop-by-hopとConnection指定のヘッダー、条件付きcacheヘッダー、自己申告Content-Lengthを除去する。HostとContent-Lengthは実際の要求から生成する。Cookieはその要求に属する値だけを渡し、独立Cookie jarを持たない。

Basicはcaptureの指定Originだけ、Bearerは制御用途の固定API経路だけに注入する。HTTPでの認証は、local profileの単一接続先と明示した開発用ダミー資格情報だけ。Run TokenのUUID/Baselineスコープ等は#36の制御クライアントとWordPress APIで追加検査する。

応答はstatus / 多値headerペア / 展開済みBuffer。Set-Cookieの複数値・CSP・CORS等を保持し、gzip / deflate / Brotliの展開後はContent-Encodingを除去しContent-Lengthを再計算する。任意の圧縮方式・HTTP upgradeは拒否する。すべての3xxを拒否し、想定外304も取得失敗とする。Locationへ接続しない。

## 予算と後始末

NETWORK_CAPSの設計値をhard capとする。信頼済み起動設定で引き下げられるが、Manifest等から引き上げられない。

| 対象 | 上限 |
|---|---|
| DNS / TCP+TLS / HTTP全体 / idle | 5秒 / 5秒 / 30秒 / 5秒 |
| 1 Target×Device | 120秒・500要求・8同時接続・100MiB（wire/展開後それぞれ） |
| Run | 最大90分と指定deadlineの早い方・16同時接続・1GiB（wire/展開後それぞれ） |
| 送信 | URL 8KiB・header 32KiB・body 1MiB。Uploadのみ42MiB |
| 応答 | header 32KiB・撮影10MiB・Baseline 20MiB・その他制御1MiB |

wireはHTTP socketのdataで測り、header・chunk framing・圧縮bodyも含める。TLSではTLS層から出たHTTP wireを測り、暗号化record/handshakeのサイズはHTTP body予算に含めない。展開後もstreamで別に測る。超過時はBufferへ残さずsocket/decoderを破棄する。Content-Lengthだけを信用しない。

絶対期限とidleを別々に管理し、slow dripで期限を延ばさない。同時接続が埋まった場合はqueueせず拒否する。Run/Target byte予算超過は同じ予算の取得中要求も中止する。Run終了・Context終了・Token失効時にはcloseし、signalによる個別abortにも対応する。DNS待機の結果が後で返っても接続を開始しない。

local profileは正規化Origin一つ・RFC1918 IPv4 literal一つ・実効port一つだけを許可する。そのOriginも撮影Origin一覧に必要。loopback・link-local・ULA・Metadataは例外に含めない。cloudでlocal設定を渡した場合、またはCloud Run実行環境でlocal profileを選んだ場合は起動を拒否する。read-only設定ファイル・起動profileの読込とデプロイ側の固定は#38/#39で実装する。

## 検証と限界

```sh
npm run build
node --test apps/runner/tests/pinned-http-client.test.mjs apps/runner/tests/security.test.mjs apps/runner/tests/errors.test.mjs
```

実HTTP/TLS fixtureは、ホストの単一RFC1918 IPと試験ごとのportへ固定し、local policyを使う。テスト用CAだけを明示してHost/SNI・DNS証明書/IP SAN・未信頼/別名証明書の拒否、資格情報非送信、Cookie/CSP/圧縮、多値header、redirect先accept=0、wire/展開bomb/header/bodyの上限、期限・回数・接続数・Run/Target byte予算・abort/closeを検証する。IPv6の実socket不一致でもHTTP byte送信0を検査する。RFC1918 interfaceやIPv6 socketが利用できない環境ではskipせずテストを失敗させる。

DNS切替試験は注入したresolverと接続境界で、公開literalを一度だけ渡し、検査後に回答をMetadataへ切り替えても再解決しないこと、次の要求では接続関数へ到達しないことを確認する。公開IPv6の分類・pin・peer照合も検証する。他者の公開IPへ試験通信は送らない。

クラウドで公開IP/IPv6の実経路、DNS resolver、VPCとMetadataの遮断を実測済みとは扱わない。既存ブラウザguardは#35が完了するまで旧方式であり、#34だけで全ブラウザ通信が固定済みとは扱わない。
