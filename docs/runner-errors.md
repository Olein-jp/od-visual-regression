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

## Run全体の失敗と結果保存

出力ディレクトリを新規作成できた後は、Browserの起動・実行・終了の失敗も `result.json` に保存する。既存ディレクトリへの出力とBaselineへの上書きは、Browser起動前に拒否する。

- `status`: Runに失敗、Snapshotエラー、未実行があれば `ERROR`、全件成功なら `COMPLETED`。
- `run_error`: 主エラーの固定コード・日本語メッセージ。起動失敗は `BROWSER_LAUNCH_FAILED`、実行全体の失敗は `RUN_FAILED`、終了だけの失敗は `BROWSER_CLOSE_FAILED`。成功時は `null`。
- `cleanup_error`: Browser終了に失敗した場合の `BROWSER_CLOSE_FAILED`。主エラーとSnapshotの失敗を上書きしない。成功時は `null`。
- `planned_snapshots`: 予定されたTarget×Deviceの件数。
- `total_snapshots`: 終了処理まで到達して結果を記録した件数。Snapshotエラーも含む。
- `unexecuted_snapshots`: 予定件数から結果記録件数を引いた件数。
- `error_snapshots`: 実行されたSnapshotのエラー件数。未実行を成功・エラーのSnapshotとして生成しない。

起動失敗時はSnapshot結果が空、完了件数が0、全件が未実行となる。取得できなかったバージョンは `null`。終了失敗時は取得済みのSnapshot結果を保持する。CLIはRunまたはSnapshotの失敗で終了コード1を返す。例外の元メッセージはRunの結果とCLIにも出力しない。

結果は同じ出力ディレクトリの `result.json.tmp` に排他的に書き込み、完了後にrenameで `result.json` へ置換する。書込・置換に失敗した場合は一時ファイルの削除を試み、CLIは主エラー・終了処理エラーを維持した上で `RESULT_SAVE_FAILED` を出力して終了コード1を返す。ディスク容量、権限、ディレクトリ消失等により結果自体を保存できない場合がある。renameは部分JSONの公開を防ぐが、停電・OS障害に対する永続化保証はない。

SIGTERM・SIGINTの専用ハンドラーは設けていない。通常のシグナル終了、SIGKILL、プロセスクラッシュ、強制終了では終了処理や結果保存に到達する保証はなく、結果なし・一時ファイル・一部画像のみが残り得る。出力先を新しくして再実行し、残存データは運用側で確認・整理する。

検証: `npm run build` 後に `node --test apps/runner/tests/run-lifecycle.test.mjs apps/runner/tests/errors.test.mjs apps/runner/tests/browser.test.mjs apps/runner/tests/security.test.mjs` を実行する。
