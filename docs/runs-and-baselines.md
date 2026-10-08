# Run・Environment・Baseline

Issue #30 の製品実装。管理APIの認証・ルートは #32、結果UploadとRunner向けBaseline配信は #33、Dispatcher連携は #37 で接続する。

## 固定履歴

`ODVR_Run_Manager::create(suite_id, input, user_id, options, diagnostic_auth)` は、有効なSuiteの全Target×選択Deviceを保存する。最大100×10＝1000組。Suite→Target ID順→Device ID順→Run→Snapshot ID順でロックし、同Suiteにqueued/runningがあれば409で拒否する。

Storageの有効な診断が失効した場合は、ロック外で再診断する。BaselineのPNG検査もロック外で行い、トランザクション内で設定・参照元・Snapshotのdigestを再確認する。変更があれば409で再取得を求める。外部通信やPNGデコード中にDB行ロックを保持しない。

保存Manifestは `stored-run-manifest`。Suite名、Targetの投稿情報・URL・ラベル、Deviceの全撮影Profile、設定・判定閾値・許可Origin、参照Run/全Snapshot、参照元の3Versionを固定する。queued期限とBasic認証の固定Originも秘密なしで保存する。実行状態・Execution ID・現在のSnapshot状態は保存Manifestへ混ぜず、Runnerへ返す時だけ `run-manifest` として合成する。設定の編集は過去の履歴へ波及しない。

戻り値の `item` だけが管理API用。`runner_token` はDispatcherへ渡す内部値であり、管理レスポンス・ログへ出さない。32byteの暗号学的乱数をbase64urlで表し、DBにはSHA-256と2時間の期限だけを保存する。平文は再取得・復元しない。

## Environment

WordPress/PHP、テーマと親テーマの識別子・Version、有効PluginとMU Pluginの識別子・名前・Version、Locale、Site URLを取得する。Version不明はnull。Optionや秘密、Pluginの説明・Author等を収集しない。

Runner/Playwright/Chromiumは初回のProgressまたはCompleteで3項目同時に補完し、以降は完全一致を要求する。空白だけ・制御文字入りVersion、部分補完、未知の保存Versionを拒否する。Environment全体の更新APIは設けない。Completeの定型要求・digest・応答は `environment.completion` に保存し、公開時に除外する。

## Baseline

- Pinned：Suiteの指定を固定。未設定なら参照なし。不正な指定を別Runへ自動変更しない。
- Previous：同Suiteのcompleteを `(completed_at,id)` 降順で選ぶ。
- Specific：同Suiteのcomplete IDを明示する。partial/failed/deletingや別Suiteは拒否する。

Target/Device IDと完全URL、Viewport、DPR、User Agent、Mobile/Touch、撮影Timeout、Lazy Load、Maskの集合、許可Originの集合、設定Versionを比較する。表示名・slug・判定閾値・並行数・画像寸法は互換性を壊さない。欠損・破損は該当組だけ理由を固定する。

3Versionは参照元を開始時に保存する。今回の実Versionは初回認証報告後に `baseline_reference` またはRunnerの `productVersionsCompatible` で完全一致を検査する。利用時の非互換や画像不備で固定IDを書き換えず、別Baselineへ差し替えない。WordPress・テーマ・PluginのVersion差は比較の対象なので互換性拒否に使用しない。

昇格は同Suiteのcomplete・全比較用PNGの完全デコードとdigest一致・既知の3Versionを要求する。SuiteのPinned IDだけを変更する。実行中の固定参照は変えない。認証は後続のControllerで行う。

## 状態・期限

queued→runningは同じExecution IDだけが開始でき、開始時刻は初回だけ保存する。Progress単独で件数を増やさない。全Snapshotが終端のCompleteからcomplete/partial/failedを計算する。致命的失敗は成功結果を保持してpendingだけ定型ERRORにし、Runはfailedにする。同じComplete再送は保存済みの応答を返し、異なる要求・終端の再開を拒否する。

既定は作成15分後のqueued期限と90分後のRun期限。内部設定 `queued_timeout_seconds` / `run_timeout_seconds` は正整数で、queued < Run < Tokenの2時間を要求する。Heartbeatで延長しない。参照・開始・進捗・完了時とサイトの1分Cron（最大25件の巡回）で失効を確定する。WP-Cronはアクセス等に依存するため、期限の秒単位の即時処理を保証しない。

deletingは終端からだけ遷移でき、Tokenを無効にする。Pinned・稼働・保持Run/Snapshotから参照された履歴は削除できない。画像・DBの物理削除、自動Retentionの追加保護は #31 で接続する。

## 検証

`tests/runs.php` は実DB・Apache・独立2つのWP-CLIで、全組み合わせと1000組の上限、同時開始、履歴・Environment不変、全Baselineモード、欠損/破損、互換性、Version、complete/partial/failed、再送、期限、deletingを検証する。`tests/runs-multisite.php` は子サイトで同じ試験を行い親の5テーブル全件が不変であることを確認する。Runnerは製品互換性と既存の寸法変更比較を検証する。

Cloud Run実機、Runnerの製品APIを使う一連の撮影・再送、Retention連携は後続Issueの検証対象。MVP最大画像1000枚の昇格時間・実運用容量は未計測。
