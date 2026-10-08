# Runnerの失敗診断

Snapshot単位で `error_code` と固定の日本語 `error_message` を保存する。例外の元メッセージ、リクエストヘッダー、認証情報は保存・ログ出力しない。1件の失敗後も、残りのTarget×Deviceを処理する。

| error_code | 原因 |
| --- | --- |
| HTTP_ERROR | 主文書のHTTP 400以上、またはHTTP応答なし |
| NAVIGATION_TIMEOUT | ページ遷移のタイムアウト |
| DNS_ERROR | DNS解決失敗・解決先なし |
| TLS_ERROR | 証明書・TLS接続の失敗 |
| PAGE_CRASH | ページのクラッシュ |
| IMAGE_LOAD_FAILED | 画像破損、画像・フォント・Lazy Loadの待機失敗 |
| SCREENSHOT_FAILED | 画像撮影・撮影画像のデコード失敗 |
| URL_BLOCKED | スキーム・URL形式・URL内認証情報の拒否 |
| ORIGIN_BLOCKED | 許可Origin以外へのアクセス |
| IP_BLOCKED | 内部ホスト・非公開IP・DNS解決先の非公開IP |
| REDIRECT_BLOCKED | 主文書のリダイレクト拒否 |
| NETWORK_ERROR | 上記以外の通信失敗 |
| RESOURCE_BLOCKED | 副リソースの取得失敗・通信制限による不完全な画面 |
| FILE_SAVE_FAILED | Snapshot画像・差分画像のディレクトリ作成／保存失敗 |
| BROWSER_ERROR | Context作成などのBrowser処理失敗 |
| CONTEXT_CLOSE_FAILED | 他の失敗がない場合のContext終了失敗 |
| SNAPSHOT_FAILED | その他のSnapshot処理失敗 |

`http_status` は主文書の応答コードを保持し、応答がないときは `null` とする。ガードが拒否したリダイレクトの応答コードも保持する。`duration_ms` は終了処理まで含めた経過時間。

`metadata.network` に以下を保存する。

- `blocked_resource_count`: 拒否・取得失敗・副リソースHTTPエラー・WebSocket拒否の総件数。
- `blocked_resource_reasons`: 安定したエラーコードごとの件数。URLやレスポンス本文は含めない。
- `navigation_error_code`: 主文書の通信／アクセス拒否理由（ある場合）。
- `navigation_http_status`: ガードが受け取った主文書のHTTP Status（ある場合）。

副リソースの失敗が1件でもあれば、撮影できても正常結果にはしない。画像待機が先に失敗した場合は `IMAGE_LOAD_FAILED` を主原因に残し、ブロック理由をmetadataで確認できる。副フレームの拒否は主文書の拒否として扱わない。Context終了時に追加の失敗があった場合は、元のエラーを保持し `metadata.cleanup_error_code` に記録する。

結果のSnapshot URLとconfigurationのTarget URLからは、全query・fragment・URL内認証情報を除去する。撮影自体には元のURLを使う。Baseline比較には `metadata.url_fingerprint`（元URLのSHA-256ダイジェスト）も使用し、query変更を検出する。従来のBaselineは従来どおりURLで比較する。ダイジェストのない従来結果は機密queryを含む可能性があるため、過去の結果ファイルは新しい処理で再生成する必要がある。

検証: `npm run build` 後に `node --test apps/runner/tests/errors.test.mjs apps/runner/tests/browser.test.mjs apps/runner/tests/security.test.mjs` を実行する。
