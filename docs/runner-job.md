# Runner 製品 Job

Issue #36 の実装。既存の `npm run runner -- manifest.json 出力先 [baseline]` はプロトタイプとして維持し、製品は `npm run runner:job` を引数なしで起動する。製品の画像・Run・集計は WordPress が保存する。[結果受付](runner-results-api.md)、[通信固定](pinned-http-transport.md)、[Dispatcher 設計](dispatcher-cloud-run-design.md)へ接続する。

## 登録設定と秘密

Job は `ODVR_JOB_CONFIG`（既定 `/etc/odvr/runner.json`）の運用者設定を読む。ファイルは通常ファイル・絶対パス・所有者 root または実行ユーザー・group/other 書込不可とし、read-only で配置する。未知キー・未登録 site・登録外 callback を拒否する。HTTP 要求や CLI 引数から接続先・Google endpoint・秘密プロジェクトを選べない。

クラウド用の登録例（値は非秘密の fixture）：

```json
{
  "schema_version": 1,
  "profile": "cloud",
  "sites": {
    "staging-1": {"callback_base": "https://staging.example.com/wp-json/odvr/v1/runner"}
  },
  "secret_project": "123456789012"
}
```

`secret_project` は Secret Manager が返す resource 名と一致する固定 project 番号を使用する。Runner は ADC を使う公式 Secret Manager SDK 6.3.0 で、固定 endpoint の数値 version を一度読み、10 秒 timeout・SDK retry 無効・CRC32C・応答 resource 名・43 byte の Token 形式を検査する。API・IAM の運用検証は #39。Google の[version 取得](https://docs.cloud.google.com/secret-manager/docs/access-secret-version)と[データ整合性](https://docs.cloud.google.com/secret-manager/docs/data-integrity)に従う。この Google 秘密取得は起動時の専用経路であり、撮影先や WordPress の応答から Google API を呼ばない。

Job の非秘密環境値は `ODVR_SITE_ID`、`ODVR_RUN_UUID`、`ODVR_TOKEN_EXPIRES_AT`（UTC ISO、現在から最大90分）、`ODVR_RUN_SECRET_VERSION`。callback override を使う場合は `ODVR_CALLBACK_BASE` が登録値と完全一致すること。Secret 名は `odvr-run-{site_id の SHA256 先頭24桁}-{run_uuid}`、固定 project の `versions/{正整数}` だけを許す。別 site/Run、latest、任意 project を拒否する。Cloud Run が注入する `CLOUD_RUN_EXECUTION` を使い、Task index=0/count=1 を要求する。長期鍵と SDK debug 出力を許可しない。

ローカルでは profile を `local`、`local_secret_directory` を専用 tmpfs の絶対パスにし、`local_destination` に `{origin,address,port}` を設定する。単一 RFC1918 IPv4 先だけを固定し、Cloud Run 環境での例外を拒否する。`ODVR_LOCAL_EXECUTION_ID` を launcher が生成する。秘密は同じ固定 Secret 名の 0600 通常ファイルへ置き、read-only で共有する。Token を平文の環境値・引数へ渡さない。ローカルの HTTP 例外はダミー資格情報だけで使う。Basic の Credentials 契約は HTTPS Origin を要求するため、Basic の結合試験にはローカル TLS を使用する。

## 実行と再開

制御 API は共有の固定 HTTP transport で、登録 callback 配下の Manifest/Credentials/Baseline/Upload/Progress/Complete だけに接続する。Bearer と Execution header を付け、Cookie と Basic を付けない。撮影は別 transport と Browser Context を所有する。Credentials の Basic は Manifest の許可 Origin に一致するときだけ撮影通信へ注入し、Browser の httpCredentials やページ・ログ・結果 JSON へ渡さない。

同じ Execution の pending だけを撮影する。参照なしは CAPTURED、固定 Baseline を比較できれば UNCHANGED/REVIEW/CHANGED、固定の新 Target/Device・非互換・欠損は理由付き NO_BASELINE とする。API の 404 は、固定 Baseline の `odvr_baseline_unavailable` と許可した理由が揃う場合だけ欠損に変換する。PNG の長さ・SHA・デコード失敗を正常な欠損に置き換えない。プロトタイプのローカル互換性エラーとは保存形式・状態を分ける。

Upload の multipart と Complete 本文は一度生成し、通信切断・再送可能な 429/5xx に対して同じ Buffer を初回＋2回、1秒/3秒＋jitter で送る。401/403/404、redirect、TLS/IP 拒否を retry で回避しない。再送を使い切った制御通信障害は Run を終端にせず非zero終了し、Task retry に渡す。

入口で deterministic な finished Complete を送る。queued の scope 拒否、running の未完了は409なので Manifestへ進む。前の Task が finished Complete を確定して応答を失った場合は、同じ Complete の保存応答だけで終了し撮影しない。非回復障害の failed Complete はその場で同一本文を限定再送する。failed Complete の全応答を失い、別 Task のメモリから元の完了要求を再現できない場合は終端へ別内容を確定せず停止する。この場合の実行状態は管理 API と Execution の照合で確認する。

対象の撮影・HTTP・Context 終了失敗は定型 ERROR を送り、残りを続ける。Browser 起動・Version 不一致・Browser 終了・結果保存失敗は Complete failed/RUN_ABORTED とし、WordPress が成功結果を保持して残 pending を終端にする。失効・権限拒否では追加 API を探索しない。Token/Secret 期限・Manifest deadline・Run作成から2時間の早い期限に加え最大90分で中断し、撮影 transport と Browser を終了する。

必要な場合だけ登録 `report_directory` へ `{run_uuid}-{execution_id}.json` を保存する。内容は Version・対象 ID・製品結果・outcome だけで、URL/Manifest/秘密/秘密参照/画像を含めない。600 の一時ファイルから置換し、保存失敗も Run 全体失敗に反映する。Job の出力は UUID・終端状態・件数と定型エラーだけ。failed/partial の WordPress 完了も Job 自体は正常終了し、確定後の不要な Task retry を避ける。

## 検証範囲

固定 TLS API fixture で契約・秘密の通信境界・Upload/Complete 応答喪失・同じ Buffer の再送・限定 retry・固定 Baseline SHA/理由・期限を検証した。製品 Executor を実 Chromium に接続し、TLS/Basic/Cookie/撮影/Upload/Complete を確認した。再開・対象失敗の継続・Browser/保存/cleanup failure は依存障害 fixture、クラウド秘密の scope/CRC/timeout は公式 SDK adapter の fixture で確認する。WordPress の欠損エラーは実 wp-env と Multisite の既存 API テストに追加した。Dispatcher の受付・起動は #37、wp-env からの一連の製品接続は #38、Cloud Run の実 IAM/Secret/API と資源は #39 で確認する。
