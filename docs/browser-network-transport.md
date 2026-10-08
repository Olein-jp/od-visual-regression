# ブラウザの固定取得

Issue #35で、Context全体のHTTP routeを[共通transport](pinned-http-transport.md)へ接続した。`route.fetch` / `route.continue`へのfallbackはない。Context routeは最初のPage作成前に登録し、popup初回、iframe、Worker、fetch/XHR、Beacon、画像/CSS/font/scriptも同じ経路で取得する。

Runは一つのtransportを共有し、Target×Deviceごとのキーで期限・要求数・受信量を分離する。Context終了はそのContextのAbortSignalで取得中socketを破棄し、他のContextを停止しない。Run終了はtransport全体をcloseする。BasicはRunごとに一つの信頼済み撮影HTTPS Originへだけ注入し、別Originでは注入しない。ブラウザへ資格情報を渡さない。プロトタイプはODVR_HTTP_AUTH_ORIGINを指定でき、省略時は先頭TargetのOriginを固定する。後続Targetに合わせて資格情報の適用Originを切り替えない。製品Credentials APIとの接続は#36。

## 応答と通信抑止

ChromiumのfulfillがSet-Cookieを改行で分割する実装に合わせ、複数cookieを保持する。Cookieのdomain/path/secure/HttpOnly規則はBrowserContextが管理し、transport独自のjarは持たない。展開済み応答には正しいContent-Lengthを渡す。

元CSPとCORSを保持する。Playwrightはcross-originのfulfillでAccess-Control-Allow-Originが欠落すると許可ヘッダーを補うため、欠落は明示的な空値にして元の拒否を維持する。既存のCSPへ追加のpolicyを併記し、connect-srcは許可HTTP(S) Originだけ、worker-srcも同じOriginだけに限定する。WS/WSSとblob/data Workerを抑止する。通常の同一Origin HTTP Workerは利用できるが、blob/data Worker依存サイトはMVPでは不完全な表示になり得る。

Service WorkerはContextでblock、WebSocketはContext routeでもcloseする。ページ・iframe・popupには初期スクリプトでWebRTC/WebTransport APIを抑止する。WorkerのWebTransport API自体はChromiumで残るため、QUIC無効化とブラウザDNS解決の拒否を併用する。background networking、prefetch、prerender、MediaRouter等を起動時に抑止し、WebRTCの非proxy UDPを禁止する。これらの設定とrouteをプロセス全体のsocket隔離として扱わない。

## 検証

`npm run build`後、browser/security/errors/run-lifecycleおよび共通transportのテストを実行する。実TLS fixtureはテスト用CAと単一RFC1918 IP/portだけを使用し、他者の公開IPへ通信しない。

- Basic成功・誤パスワード401・別Originへの認証非送信、ブラウザ由来Authorization/Metadata-Flavor除去
- 多値Set-Cookie・path/secure/HttpOnly・不正domain拒否、gzip、厳格CSP、実画像撮影
- iframe・popup初回・Worker取得とWorker fetch・XHR・Beacon・画像/CSSの実server到達
- 未許可先・Metadata・redirect・JS/meta遷移・WS/SW・blob Worker拒否、Worker WS/WebTransport試行時の禁止先TCP/UDPカウンターゼロ
- 欠落CORS拒否と明示CORS成功、Context close / Target期限でactive socketゼロ、共有Runの別Context継続
- 既存の遅延画像/font・マスク・比較・Device・エラー分類・秘密非保存・Run終了失敗の検証

ブラウザによるCSP拒否はtransport要求より前に起きる場合があり、transport診断件数に全て現れるとは限らない。Cloud Runの実経路、Metadata迂回、firewall、公開IPv6の多層遮断は#39で実測する。

CSPのscheme一致規則は[W3C CSP Level 3](https://www.w3.org/TR/CSP/#match-schemes)、QUIC停止の起動スイッチは[Chromiumの定義](https://chromium.googlesource.com/chromium/src/+/refs/tags/140.0.7339.19/components/network_session_configurator/common/network_switch_list.h)を参照した。保証範囲は使用中のPlaywright/Chromiumでの上記fixture検証に限定する。
