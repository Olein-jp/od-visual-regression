# Runnerの結果受付・再送・完了

Issue #33 の製品実装。[共通契約](api-contracts.md)、[Run・固定参照](runs-and-baselines.md)、[管理API・Token](admin-api-and-dispatch.md)、[非公開Storage](private-storage.md)に接続する。Runner本体の製品APIクライアントと撮影接続は #36、実Dispatcherは #37、全体のローカル接続は #38。

## 入口と認証

`/wp-json/odvr/v1/runner` 配下で Manifest・Credentials・固定Baseline・Snapshots・Progress・Complete を提供する。HTTPS、`Authorization: Bearer ...`、現在サイト、Token Hash・TTL・Run状態を検証する。Manifest以外にも `X-ODVR-Execution-ID` を要求し、Runに固定したExecutionと完全一致させる。Progress/Completeの本文のExecutionも同じ値とする。queryでToken・Execution・サイトを指定できない。TokenをWordPressユーザー権限に変換せず、CookieでRunnerを許可しない。

BaselineのURL IDは過去の参照Snapshot ID。Tokenが指すRunの固定Manifestに含まれるIDだけを許可する。同サイト・同Suite・complete・利用時Version互換を検査し、参照Runの共有ファイルロックを取って状態・SHA・PNG完全デコードを再確認する。検証済みPNGをメモリに保持して配信するため、読み取り後に削除が始まっても読取を完了できる。`Content-Length`・`X-ODVR-Image-SHA256`・no-store・nosniffを返し、外部ブラウザへのCORS公開は行わない。

## 生multipartに必要なサーバー設定

PHP標準のフォーム展開は重複した項目を失う。SnapshotsへのPOSTは、専用Runner経路で `enable_post_data_reading=Off` とした生本文を使用する。Pluginの初期化時に `Content-Length` と実受信バイト数を照合し、42MiB+1までの上限付き取得を行ってWordPress RESTへ渡す。PHPの自動展開が有効なら503、生本文欠落も503、長さが不正なら411、全体上限超過は413。欠落をERROR Snapshotとして保存しない。chunked uploadは提供せず、Runnerは既知のバッファ長を送る。

生本文の設定はRunnerの専用経路だけに適用する。サイト全体へ適用するとWordPressの通常フォームも自動展開されなくなる。Apache mod_phpのVirtualHostでの設定例：

```apache
<LocationMatch "^/wp-json/odvr/v1/runner/">
    AuthType None
    Require all granted
    php_flag enable_post_data_reading Off
    php_value memory_limit 1024M
    php_value post_max_size 42M
    php_value upload_max_filesize 20M
    LimitRequestBody 44040192
</LocationMatch>
```

PHP-FPMは専用の経路・poolで同等のPHP設定を適用し、Webサーバーにも42MiBの本文上限を設定する。サーバーからWordPressへAuthorizationを転送する。TLS終端のproxyは信頼されたホスト設定でHTTPSを認識させる。RESTはHTTPSかつqueryなしのURLを必要とするため、通常のpermalinkとWordPressのREST prefixに合わせて経路を設定する。基本認証を外すのはRunner経路だけで、管理画面と撮影対象の保護は保持する。

接続診断は登録済みcallbackの無効なRun UUIDへ、無効Bearerだけを送り、PHPの401・Runner認証エラー・対応ヘッダーを確認する。前段Basicの401/HTML、リダイレクト、Authorization欠落、生本文非対応は失敗とする。Run・Job・Run Tokenを作らない。診断ヘッダーは設定の対応状態だけで秘密を含まない。

## 検証と一度だけの確定

scalarは全18項目を1回ずつ送る。未知項目・重複・配列記法・重複file・余分なヘッダー・不完全な境界を拒否する。整数は符号・先頭ゼロなし、booleanはtrue/false、nullableはnull、ratioは指数形式なしの0〜1（小数15桁以内）。scalar合計64KiB、各PNG20MiB、全体42MiB。ファイル名は保存パスに使わない。ERRORには画像を添付しない。

共通Schemaに加え、固定参照・Version・実Baseline寸法・画像組み合わせ・最大寸法/面積・dimension_changed・total_pixels・diff_pixels・比率・固定閾値を検査する。NO_BASELINEの理由は固定判定や現在のStorage検査と一致させ、正常な参照画像の省略を許可しない。エラーは定型メッセージだけを保存する。PNGはStorageで完全検証する。WordPressでpixelmatchは再実行せず、差分画素数は信頼したRunnerの報告を検査する。

比較ratioは分数から10桁へ丸める。結果のcanonical digestを既存 `metadata.result_digest` に、各PNGのSHAを既存の画像digest項目に保存する。結果と2つの画像SHAを合わせた全体digestを不変ファイル名と再送判定に使用する。新しい列・保存Versionは追加しない。境界・filename・要求時刻はdigestに含めない。

PNG検証・stagingをロック外で行い、Runファイルの排他ロック内でSuite→Run→Snapshotを順にロックする。Token・TTL・Deadline・Execution・pendingを再確認して、画像rename→Snapshot更新→COUNT更新→COMMITする。Progress/Complete/ManifestもHTTP操作ではDBロック後にToken・期限を再検査する。保持・削除処理はDBでdeletingをCOMMITした後にファイルロックを取るため、この順序で待ち合わせが循環しない。

pendingは一度だけ終端へ変わる。同じ全体digestの再送は元のSnapshot IDとreplayed=true、別内容は409。確定後に参照PNGが欠損しても元の受理を取り消さない。二枚目のrenameやDB確定前の失敗ではpendingを保ち、未参照画像を配信しない。同じ再送は完全検証した同名の不変PNGだけを再利用し、別内容を上書きしない。この要求の未使用stagingを回収し、異常終了で残った一時・孤立ファイルは既存の時間・DB参照・排他ロック付きcleanupで回収する。

Progressは実SnapshotのCOUNTを返し、受信回数を加算しない。finished Completeはpendingが残れば409、全件確定後は成功・エラー数からcomplete/partial/failedを計算する。failed Completeは成功結果を保持し、残pendingだけRUN_ABORTEDのERRORにする。同一Completeは保存済みの終端時刻・集計、別Completeは409。終端後のUpload/Progressは409、失効・期限切れTokenは401。

## 検証範囲

`tests/admin-api.php` 内の隔離Suiteで `tests/runner-api.php` を実行する。生multipart条件、PNG異常、固定Baseline・SHA、未知組み合わせ、別Execution、Version報告前、同時同一/異なるUpload（独立2つのWordPressプロセス）、画像間/DB障害と回復、Progress再送、Complete早着/同一/異なる再送、failedのpending終了、終端後操作、期限競合を検証する。

`tests/runner-ingress.php` はwp-envのApache mod_phpで、一時の経路別PHP設定とBasic設定を作り、実HTTPの生本文・重複・自動展開非対応・Basic遮断/除外を検証する。設定・fixtureはfinallyで復元/削除する。HTTPS判定は試験用のproxy fixtureであり、実TLS・証明書・外部proxyの転送は確認していない。CIでPHP7.4・WordPress6.7/最新版とMultisiteを検証する。実Runner撮影・Dispatcher・TLS・PHP-FPM/Nginx・クラウドは後続の結合検証で確認する。
