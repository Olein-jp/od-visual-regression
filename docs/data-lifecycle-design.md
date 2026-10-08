# DBとRun・Baseline・Retentionの設計

対象は [Issue #2](https://github.com/Olein-jp/od-visual-regression/issues/2)、根拠は [仕様書v1.1](specification-v1.1.md) の§14〜19・§27〜34・§44・§73。これは実装前の設計案であり、採用はこの文書のPRへの合意で確定する。製品コード・JSON Schema・クラウド設定は変更しない。

DB導入・更新・診断と初期Device（#27）の実装内容は [database.md](database.md) を参照する。Suite/Target/Device Repository（#28）は[configuration-repositories.md](configuration-repositories.md)を参照する。以下の設計当時の記述と、Run/Retentionの実装状況は区別する。

## 現状と基本方針

RunnerはローカルのManifestとPNGを使って撮影・比較できるが、WordPressのDB・Repository・Run管理は未実装。`packages/shared/src/index.ts` はDevice・撮影設定・差分判定を定義しており、現在のManifestはプロトタイプ専用である。

mainにはIssue #1のPR #15による初期化・管理権限・無効化時のデータ保持が実装済み。今回の文書はその基盤を前提とし、DB導入や通常実行へのコード変更は含めない。

- WordPressに5つの専用テーブルを持ち、画像はWordPressの非公開Storageへ保存する。第6の関連テーブルは追加しない。
- Suiteは編集可能な設定、Runは開始時の設定を固定した履歴。Manifest生成で現在のSuite・Target・Device・投稿から情報を再取得しない。
- Run結果は差分の有無と実行成否を分ける。CHANGEDでも全撮影成功ならRunは成功。
- 同じSuiteでは実行中Runを1件までとし、異なるSuiteの実行は許可する。
- テーブルはInnoDB必須。非対応環境ではDB導入・更新を失敗として扱い、Run作成を許可しない。

## 保存の共通規則

`{prefix}` は現在のサイトの `$wpdb->prefix`。固定の `wp_` やネットワーク共通の `$wpdb->base_prefix` は使わない。文字コード・照合順序は `$wpdb->get_charset_collate()` に従う。[WordPressのテーブル作成ガイド](https://developer.wordpress.org/plugins/creating-tables-with-plugins/)

全テーブルの `id` は `bigint(20) unsigned NOT NULL AUTO_INCREMENT`、主キーは `id`。外部IDのNULLは「該当なし」であり0と区別する。時刻はUTCの `datetime`、未発生の時刻はNULL。ゼロ日付を使わない。UUIDは小文字の正規化済みUUID、`char(36) NOT NULL`。

JSONはMySQLのネイティブJSON型に依存せず `longtext` へ `wp_json_encode()` で保存する。配列の型・上限・Versionをアプリケーションで検証し、不正なJSONを空設定として読み替えない。JSON/TEXTにDBの暗黙DEFAULTを要求せず、INSERT時に明示する。IDをJSON数値へ変換する際の安全範囲はAPI契約 #4で統一し、範囲外の値を丸めない。

物理外部キーやDBのCASCADEは導入しない。関連の検証と削除はRepositoryとトランザクションで行い、別Suite・別サイトへの関連を拒否する。SQLの値は `$wpdb->prepare()` 等で扱い、テーブル名は固定の許可済みサフィックスだけから構成する。

以下の型はすべてNOT NULLを原則とし、表の「NULL可」だけが例外。statusは拡張可能な `varchar(20)` とし、アプリケーションで許可値を検査する。

## 5テーブルの定義

### `{prefix}odvr_suites`

| 列 | 型・保存内容 |
|---|---|
| id / uuid | 共通ID / UUID |
| name | varchar(200) |
| status | varchar(20)：active / archived |
| baseline_run_id | bigint(20) unsigned、NULL可 |
| settings | longtext：Version付き設定JSON |
| created_by | bigint(20) unsigned：作成ユーザーID。ユーザー削除後も履歴上の数値は維持 |
| created_at / updated_at | datetime |

索引：`UNIQUE KEY uuid (uuid)`、`KEY status_updated (status,updated_at,id)`、`KEY baseline_run (baseline_run_id)`。

設定JSONに `settings_version: 1`、重複のない `device_ids`、撮影設定、Ignore Selector、許可Origin、判定閾値を保存する。DeviceはSuite間で共有し、Suiteとの関連を `device_ids` で表す。撮影設定の項目名・既定値は現在のCaptureSettingsを引き継ぐ。差分率は0〜1、0.001以下はUNCHANGED、0.001超〜0.01未満はREVIEW、0.01以上はCHANGED。`review_threshold < changed_threshold` を必須とする。

Dispatcher接続・Secret・HTTP Basic認証・Retention既定値はサイト設定。後から確定した製品API契約に従い、Suite別のRetention値はSuiteの保存JSONに保持し、サイト既定値とは区別する。サイト設定はOptions API、秘密情報の扱いは #4で確定する。

### `{prefix}odvr_targets`

| 列 | 型・保存内容 |
|---|---|
| id / suite_id | 共通ID / bigint(20) unsigned |
| object_id | bigint(20) unsigned、Custom URLはNULL |
| post_type | varchar(20)、Custom URLは空文字 |
| label | varchar(200) |
| url | text：HTTP(S)のURL、最大2048文字 |
| enabled | tinyint(1) unsigned、0 / 1 |
| sort_order | int(11) unsigned |
| created_at | datetime |

索引：`KEY suite_order (suite_id,enabled,sort_order,id)`、`KEY object_id (object_id)`。

Target IDはSuite間で移動・再利用しない。WordPress ObjectのURLはSuite保存時に取得し、Run開始時に公開状態とpermalinkを再確認してManifestへ固定する。Custom URLは保存値を検証して固定する。投稿が消えている・非公開になっている場合はRun作成を拒否し、修正対象を返す。

Targetの編集は将来のRunにだけ反映する。削除は `enabled=0` による論理削除とし、履歴の参照元IDを保持する。過去Runのラベル・URL・投稿情報はManifestが正本なので、現在のTargetを編集しても履歴は変わらない。論理削除したTargetを画面で再追加する場合は新IDにする。

### `{prefix}odvr_devices`

| 列 | 型・保存内容 |
|---|---|
| id | 共通ID |
| name / slug | varchar(100) / varchar(50) |
| viewport_width / viewport_height | int(11) unsigned |
| user_agent | text、未指定は空文字 |
| device_scale_factor | double：0.1〜4の有限値 |
| is_mobile / has_touch / enabled | tinyint(1) unsigned、0 / 1 |
| sort_order | int(11) unsigned |

索引：`UNIQUE KEY slug (slug)`、`KEY enabled_order (enabled,sort_order,id)`。

初回だけDesktop・Tablet・Mobileを初期投入する。再有効化・更新でユーザーが編集した値を上書きしない。slugは現在のDevice Schemaと同じ形式とし、画像パスを含むため作成後は変更不可。編集・無効化は将来のRunにだけ影響する。

Suite保存時とRun開始時に `device_ids` が同サイトの有効Deviceを指すことを検証する。Deviceの削除は論理削除で、参照するSuiteがあれば削除を拒否して利用箇所を返す。Deviceの物理削除はMVPでは行わず、Uninstallの明示削除時に処理する。

### `{prefix}odvr_runs`

| 列 | 型・保存内容 |
|---|---|
| id / uuid / suite_id | 共通ID / UUID / bigint(20) unsigned |
| reference_run_id | bigint(20) unsigned、初回等はNULL |
| status | queued / running / complete / partial / failed / deleting |
| triggered_by | bigint(20) unsigned：開始ユーザーID |
| runner_execution_id | varchar(255)、NULL可 |
| runner_token_hash | char(64)、NULL可：SHA-256等のHash。方式は #4で固定 |
| runner_token_expires_at | datetime、NULL可 |
| environment | longtext：Version付き環境JSON |
| manifest | longtext：Version付き開始時Manifest【仕様への追加】 |
| total_snapshots | int(11) unsigned：作成時に固定 |
| completed_snapshots | int(11) unsigned：成功結果数 |
| error_snapshots | int(11) unsigned：ERROR結果数 |
| started_at / completed_at | datetime、NULL可 |
| created_at / updated_at | datetime【updated_atは追加】 |
| deadline_at | datetime：Runの失敗判定期限【追加】 |
| error_code / error_message | varchar(64) / text、NULL可：Run全体の失敗【追加】 |
| deletion_requested_at | datetime、NULL可【追加】 |

索引：`UNIQUE KEY uuid (uuid)`、`KEY suite_history (suite_id,created_at,id)`、`KEY suite_status (suite_id,status)`、`KEY reference_run (reference_run_id)`、`KEY deadline (status,deadline_at)`。

追加列は、Run開始後の履歴再現、異常終了、再開可能な削除のために必要。開始時ManifestにはVersion、Suite名、Target ID/ラベル/投稿情報/URL、Device IDと全プロファイル、撮影設定・閾値・許可Origin、Baseline選択方式、参照Run ID、Target×Deviceごとの参照Snapshot IDを含める。Basic認証・Bearer Token・Shared Secretは含めない。WordPress Object・Device・投稿ユーザーを後から取得できなくても履歴を表示できる。

Environmentは開始時にWordPress/PHP、テーマと親テーマの識別子・Version、有効プラグインの識別子・Version、MU Plugins、Locale、Site URLを保存する。Runner/Playwright/Chromiumは初回の認証済みRunner応答で追加し、以後の応答で一致を検査する。この補完以外は変更不可。MU PluginのVersion不明はnullとして記録し、無断で推測しない。環境差分は参照Runとの比較、参照なしなら比較なし。

### `{prefix}odvr_snapshots`

| 列 | 型・保存内容 |
|---|---|
| id / run_id / target_id / device_id | 共通ID / bigint(20) unsigned |
| baseline_snapshot_id | bigint(20) unsigned、NULL可 |
| status | pending / captured / no_baseline / unchanged / review / changed / error |
| url | text：開始時ManifestのURL |
| image_path / diff_path | text、NULL可：Storageルートからの相対パス |
| width / height / baseline_width / baseline_height | int(11) unsigned、NULL可 |
| dimension_changed | tinyint(1) unsigned、0 / 1 |
| diff_pixels / total_pixels | bigint(20) unsigned、NULL可 |
| diff_ratio | decimal(12,10) unsigned、NULL可：0〜1 |
| http_status | smallint(5) unsigned、NULL可 |
| duration_ms | int(11) unsigned、NULL可 |
| error_code / error_message | varchar(64) / text、NULL可 |
| metadata | longtext：Version付き結果・固定ラベル・Device情報・結果digest |
| created_at / updated_at | datetime【updated_atは追加】 |

索引：`UNIQUE KEY run_target_device (run_id,target_id,device_id)`、`KEY run_status (run_id,status)`、`KEY baseline_snapshot (baseline_snapshot_id)`。

pending以外は終端結果。比較なしはcaptured、参照Runはあるが対応する比較画像がない場合はno_baseline。比較できない場合にdiff_ratio=0を保存せず、比較項目はNULLにする。APIの大文字statusとDBの小文字との対応は #4で定義する。

## Runの作成と履歴の固定

1. `manage_odvr` とnonce、導入済みDB Versionを検査し、Suite ID・要求形式を検証する。WordPress投稿の公開状態とpermalink等を取得する。
2. トランザクションを開始し、Suite行を `SELECT ... FOR UPDATE` でロックする。その時点の設定・有効Target・Deviceを読み、Target行、Device行のID昇順でロックして検証する。前段で取得した投稿情報が別Targetを指すことになった場合はROLLBACKして取得をやり直す。同Suiteにqueued/runningがあれば競合として拒否する。
3. 同じロックの下でBaselineを解決し、Manifest・Environment・期限・Token Hash付きRunと、固定されたTarget×Device全件のpending Snapshotを作成する。公開状態の変更を含むWordPress投稿の変更はODVRのトランザクションとは独立であり、その後のサイト変更自体は撮影エラー/差分として記録する。
4. 件数と一意性を確認してCOMMITする。失敗ならRun・SnapshotをまとめてROLLBACKする。空Target/Deviceは禁止。MVPの上限は現在のManifestと同じ100 Target×10 Device、最大1000 Snapshot。
5. COMMIT後にDispatcherへ送る。クラウドAPI呼出しをDBロック内で行わない。Hash以外のTokenを永続化しない。Dispatcherへの同一要求の再送・Execution ID取得は #4・#10で確定する。

外部送信前にWordPressプロセスが落ちてもqueued履歴は残る。Tokenの平文をDBから復元して自動再送することはしない。受付の成否が不明なRunは照合または期限切れを待ち、新しいRunを無断で作らない。

Suite名・対象・Device・設定・Baselineの変更は新しいRunだけに反映する。Manifestと参照Snapshot IDは作成後に書き換えない。Suiteは実行中でも編集可能だが、実行中Runに差分を混入させない。

## Baselineの選択・互換性・昇格

| 選択方式 | Run作成時の動作 |
|---|---|
| Pinned Baseline | Suiteのbaseline_run_idを固定。未設定なら参照なしで初回撮影を許可 |
| Previous Successful Run | 同Suiteのcompleteかつ削除中でないRunから `(completed_at,id)` の降順で1件選ぶ。なければ参照なし |
| Specific Run | 明示IDが同Suiteのcompleteであることを検査。存在しない・別Suite・partial/failed・削除中なら拒否 |

Pinnedが設定済みなのに不正・欠損なら、参照なしへ黙って変更せずエラーにする。Pinnedは自動更新しない。partial/failedはMVPでBaseline候補にしない。completeには「全件正常に撮影できた」Runを含み、CHANGEDの有無は無関係。

対応付けは同Suiteの `(target_id,device_id)`。新規Target/DeviceならBaseline SnapshotはNULL。参照Runは必ず作成済みの過去Runに限り、reference_run_idの循環は許可しない。IDが一致してもURL・Deviceの撮影条件・Mask・Lazy Load等の設定が異なる場合は互換性なしとしてNULLにし、Manifestに理由を残す。完全一致だけを必須にする項目とRunner Version等の扱いは #6・#4で確定する。閾値だけの変更は撮影互換性を壊さず、寸法変化も比較できる。

Baselineには比較用画像が必要で、参照Snapshotのdiff画像は不要。対象のSnapshot/PNGが欠損・破損していてもRun全体を中断せず、該当対象をno_baselineとして理由を記録する。現在画像の失敗はerror。Baseline参照は開始時に固定し、実行途中で別Runへ差し替えない。

昇格はmanage_odvrとnonceを検査し、Suite行をロックして、同Suiteのcomplete・全撮影画像が読めるRunを選ぶ。Suiteのbaseline_run_idだけを更新し、過去Runのreference_run_idは変えない。Retention開始と同じロックを使い、deletingへ遷移済みのRunの昇格は拒否する。実行中Runがあっても昇格可能だが、その実行中Runの参照は変更されない。

## 状態遷移と失敗の扱い

| 現状態 | 条件 | 次状態・処理 |
|---|---|---|
| queued | 有効TokenでManifestを取得し、実行開始を確定 | running、started_atを初回だけ保存 |
| queued | Dispatcherの確定した起動失敗、または作成15分後も開始なし | failed、Run errorを保存し、pendingをerrorにする |
| running | Complete要求で全結果が終端、ERROR=0 | complete、completed_atを保存 |
| running | 全結果が終端、成功>0かつERROR>0 | partial |
| running | 全結果が終端、成功=0 | failed |
| running | 致命的失敗・deadline到達 | failed、未処理pendingをerrorへ確定 |
| running | 部分結果のUploadまたはProgress | 状態は維持。Progress単独では件数を増やさない |
| complete/partial/failed | 同一Completeの再送 | 変更せず元の結果を返す |
| complete/partial/failed | 削除の再検査を通過 | deleting |
| deleting | ファイルとDBの削除を完了 | 行を物理削除 |

`deadline_at` は初期値created_at+90分。Cloud Runの30分・再試行1回に余裕を持たせ、Tokenの2時間より短くする。Heartbeatで延長しない。15分/90分はサイト設定で管理し、Token期限を越える値は拒否する。Cloud Runの待機時間・retryと整合する最終値は #10で検証する。

queuedのUpload・Progress・Completeは拒否し、Manifest取得による開始確定を先に必要とする。致命的なRun全体の失敗は、撮影に成功したSnapshotがあってもfailedとし、Runのerror_codeで理由を示す。

Complete要求時にpendingが残る場合は「未完了」として拒否する。終了直前にUploadを失った場合は同じ結果を再送し、無断で成功扱いにしない。Runの異常終了でpendingをerrorに確定した後、遅れて来たUploadは拒否する。

定期処理は期限を過ぎたRunを同じロックと状態条件で処理し、二重起動しても1回だけ終端にする。WP-Cronの実行時刻は保証しないため、管理画面のRun取得でも期限切れを確認し、運用時は外部CronまたはWP-CLIから同じ処理を呼べるようにする。無効化中はCronを停止し、再有効化後に期限切れを処理する。

## 再送・UPSERT・集計・競合

全件のpending Snapshotを先に作成するため、Uploadが自由なTarget/Deviceを追加してはいけない。Manifestの組み合わせを検査した上で、一意キー `(run_id,target_id,device_id)` に対してUPSERT相当の更新を行う。

- pending→終端を許可する。終端結果の同一内容・同一画像digestの再送は元の結果を返し、画像や件数を増やさない。
- 終端後に異なる内容を同じキーへ送った場合は競合として拒否する。成功結果のerror化、errorからの無断な差し替えもしない。撮影失敗の再実行は新しいRunとする。
- Cloud Run Job再試行はManifestと確定済み結果を取得し、pendingだけを処理する。Uploadの応答喪失時は同じ画像バッファを再送し、再撮影画像で確定済み結果を上書きしない。
- 画像は一意な一時領域に検証・保存し、DB更新と並行する他Uploadが上書きしないようにする。正規パスへの確定とDB参照の整合、孤立した一時ファイルのcleanupはStorage設計 #4で確定する。digestはmetadataへ保存する。
- `completed_snapshots` はcaptured/no_baseline/unchanged/review/changedの件数、`error_snapshots` はerrorの件数。進捗は両者の和÷total_snapshots。pendingはどちらにも数えない。
- 件数は受信回数から加算せず、Snapshotの実データからCOUNTして再計算する。再送・Job retry・Complete再送で件数を増やさない。常に成功+エラー+pending=totalを保つ。

Suite編集、Run作成、Baseline昇格、Upload、Complete、期限処理、削除は、Suite→Target→Device→Run→Snapshotの順に必要な行をロックする。同種の複数行はID昇順とする。同一Suiteの短い書込を直列化して、開始・結果・参照・削除の競合を防ぐ。外部通信と大きなファイル検証はロック外に置く。トランザクション中のロックTimeout/Deadlockは回数制限付きで処理全体を再試行し、途中のCOUNTや保存だけを続行しない。

Device編集はDevice行だけをロックし、後からSuite行をロックしない。Suite保存は利用するDevice行もロックする。Deviceの論理削除はDevice行をロックしてから、READ COMMITTEDの最新読取で全Suite設定の参照を検査する。参照検査のためにDevice→Suiteの順でロックせず、Suite保存との循環待ちを作らない。

通常Repositoryはarchived Suiteでの新規Run作成と、deleting Runへの通常操作を拒否する。終端Runは新規Upload・Progressを拒否し、有効Tokenでの同一Complete再送だけを許可する。Token期限後の再送は認証エラーとし、管理APIから結果を確認する。

APIとSQLの失敗はWP_Error等で上位へ返し、成功レスポンスに読み替えない。

## Retentionと削除

サイト設定のRetentionはKeep All、Last 10、Last 20、Custom N。Nは正の整数、Keep Allは数値0と混同しない。各Suite単位で適用し、終端Runを `(created_at,id)` の降順で並べた最新N件を残す。queued/runningは件数枠に含めず必ず残す。

保護集合は以下の順で求める。

1. 最新N件、queued/running、現在のPinned Baseline、最新のcomplete Runを保護する。Keep Allでは全Runを保護する。
2. 保護するRunの `reference_run_id` と、そのSnapshotの `baseline_snapshot_id` が属するRunを保護する。
3. 新しく保護されたRunにも2を繰り返し、参照関係の閉包を取る。
4. 残りの終端Runが削除候補。削除対象同士の参照はまとめて削除できるが、残すRunからの参照は切らない。

したがってLast Nは「上限N件」ではなく「最新N件以上を維持する」。Previous Successful Runが連鎖する運用では古いRunが保護され、削除できないことがある。無断でBefore画像や履歴参照を失わせるより保護を優先し、保護理由と件数・容量を管理画面に表示する。既存参照を切って容量だけ削減する機能はMVPに含めない。

手動削除も権限・nonceと保護を検査する。Pinned、実行中、残すRunに参照されているRunは削除不可。手動の最新Run削除は、Pinnedや他の参照がなければ許可するが、削除後のPrevious Successful候補が変わることを確認画面に示す。自動Retentionで保護する最新completeの保護は、この明示操作には適用しない。

### 削除と実行開始の競合

削除候補の計算だけでは削除を許可しない。Suite行をロックし、保護集合をその場で再計算して対象全件の終端状態・外部からの参照を確認する。対象Runをdeletingへ変え、Token HashをNULLにし、deletion_requested_atを保存してCOMMITする。以後、Run開始時のBaseline選択・昇格・画像取得・UploadはそのRunを拒否する。

複数Runの削除候補は閉じた集合として処理し、参照元から参照先の順に削除する。内部参照の片方だけをdeletingにしてはいけない。画像を同時に閲覧中のリクエストとの競合は #4のStorage設計で扱い、取得失敗を別画像へ読み替えない。

### DBと画像の削除順序

1. 上記の短いDBトランザクションでdeletingを確定する。
2. ロック外でStorageルート・Suite UUID・Run UUIDから求めた当該Runディレクトリを削除する。入力されたパスを直接削除せず、境界・symlink・パス正規化を検査する。不存在は成功扱い。
3. ファイル削除成功後、Suite→Runをロックしてdeleting状態を再検査し、Snapshot行→Run行の順に削除する。
4. ファイル削除失敗ならDB行とdeleting状態を残し、リトライと管理者向け診断を可能にする。終端状態へ巻き戻してBaselineに再利用しない。
5. 中断後はdeletingとdeletion_requested_atから再開する。DB行を先に消して、削除対象パスを復元できなくしてはいけない。

Suiteの削除操作はMVPではarchivedへの変更とし、実行中Runがあれば拒否する。Target・Run・Baselineの履歴を削除しない。archiveしたSuiteもRun閲覧とRetentionの対象。Suite全体の物理削除は明示的なUninstall削除以外には提供しない。

## DB導入・更新とVersion

DB構造のVersionはプラグインVersionと分け、サイトの `odvr_db_version` に保持する。初回は設計採用後の最初のDB実装でVersion 1を定義する。初期化の入口は有効化と `plugins_loaded` 時のVersion比較。[更新時にもVersion検査が必要であることは公式ガイドに従う](https://developer.wordpress.org/plugins/creating-tables-with-plugins/#adding-an-upgrade-function)。

- 初回は5テーブルのCREATE定義に `ENGINE=InnoDB` を指定して `dbDelta()` に渡す。既存テーブルのEngineが異なる場合は自動変換せず、診断と手動移行を必要とする。SQLは1列1行・PRIMARY KEYの書式等のdbDelta要件に合わせる。返却メッセージだけを成功証明にせず、実テーブル・型・一意キー・InnoDBを検査する。[dbDeltaの公式リファレンス](https://developer.wordpress.org/reference/functions/dbdelta/)
- #27の実装検証でWordPressのadd_optionは重複時UPDATEを持つと確認したため、初回取得に限りINSERT IGNOREを採用する。古いnotoptionsキャッシュ下でも先行ownerを上書きしない。
- マイグレーションの排他はサイト単位の非autoload option `odvr_db_upgrade_lock` とowner UUID・有効期限で管理する。`INSERT IGNORE`による初回獲得後、期限切れの再取得・解除はowner/期限を条件にした原子的な比較更新で行う。Optionsのキャッシュも無効化する。古いownerが新しいlockを解除してはいけない。移行中は通常のRun書込・削除を停止し、長い移行はleaseを更新する。ownerを失った処理は次のDDL/DMLステップへ進まない。
- DDLとDMLは同じ原子的な処理と見なさない。構造の追加・変更とデータの補完をVersion別に冪等化し、中断・再実行を許可する。DDLをRunのDMLトランザクションへ混ぜない。
- 成功検証後だけVersionを進める。失敗時は旧Version、診断、途中のデータを残し、Run書込と削除を停止する。再試行は既存列・索引・初期Deviceを再利用する。
- Deviceの初期投入はslugの存在検査で重複を防ぐ。将来の列削除・縮小・索引変更はdbDelta任せにせず、バックアップ・明示マイグレーション・検証を必要とする。
- ダウングレードで未対応のDB Versionを読んだ場合も書込・削除を停止する。逆マイグレーションを自動実行しない。整合性を確認できる範囲で診断を表示する。

Migration失敗時に最初から空DBを作り直さない。通常実行ではVersion一致後にDDL・全テーブル走査を繰り返さない。DB Schema定義、Repository、Run Managerは別責務にする。

## Multisiteと無効化・Uninstall

MVPは単一サイトとMultisiteのサイト単位の有効化を対象にする。Multisiteでの各サイトの5テーブル・Options・Uploads・Capabilityは独立し、同じ数値IDでも別サイトの履歴へアクセスできないようにする。管理機能は現在のサイトのmanage_odvrを使い、Super AdminはWordPressの標準権限判定に従う。

ネットワーク一括有効化はMVPでは拒否する。Activatorはnetwork_wideを受け取り、データ・権限を変更する前に「サイト単位で有効化する」旨を示す。新規サイトへ自動導入せず、サイト単位の有効化時に導入する。現在の #1実装はこの拒否を実装していないので、DB導入Issueで追加する。

MVPの処理は他サイトへ自動的にswitchしない。サイトを切り替える将来の管理処理では `switch_to_blog()` と `restore_current_blog()` を対応させ、Repository・Storage・rolesのコンテキストを更新する。[WordPressの公式注意事項](https://developer.wordpress.org/reference/functions/switch_to_blog/)

- 無効化：ODVRのCronだけを解除し、DB・画像・設定・権限は保持する。queued/runningのTokenは失効させ、再有効化時に期限切れ処理で失敗へ確定する。外部Jobの停止ができなくても新規結果は保存しない。
- サイトのアーカイブ/停止：データ保持。新規Runは許可しない。
- WordPressによるサイトの完全削除：通常のWordPress管理操作の責務とし、ODVRの5テーブルも削除対象へ加える。別サイトのテーブル・Storageを巻き込まないことを検証する。
- Uninstallの既定値：DB・画像・設定を保持。外部JobのTokenは失効させ、ODVRのCronは解除する。保持した権限は再導入のためそのままにする。
- 明示的なデータ削除を利用者が事前に選んだ場合だけ、UninstallでそのサイトのODVR画像→5テーブル→ODVR Options→manage_odvrを削除する。WordPress投稿・ユーザー・Media Libraryは削除しない。削除対象はハードコードしたODVR資産だけとする。
- 明示削除は事前に実行中Runがない状態にする。画像削除失敗ならDB・削除設定を残して診断し、成功したように扱わない。再インストール後に削除を再試行できる。
- Multisiteでのネットワークからのプラグイン削除は、各サイトの事前選択をページングして処理する。既定値保持のサイトは残し、削除を選んだサイトだけ処理する。大規模ネットワークの一括物理削除は手動運用手順を必要とし、ネットワークが100サイトを超える場合は、先にサイト単位の手動削除手順で処理し、Uninstall内で無制限の同期ループを行わない。上限に達した処理を全件削除成功として扱わず、未処理サイトのデータは保持する。

Uninstallは `WP_UNINSTALL_PLUGIN` を確認して実行する。取り消せないデータ削除を初回有効化や通常の無効化で行わない。

## 変更予定のファイルと5段階の実装順序

以下は設計採用後の実装Issue分割案。現段階では実装Issueを新規作成しない。

| 順序 | 実装範囲 | 主な変更予定ファイル | 最初に必要な検証 |
|---|---|---|---|
| 1 | DB導入・Version更新・初期Device・サイト単位の有効化 | includes/class-odvr-database.php、class-odvr-activator.php、class-odvr-plugin.php、tests/database.php | 新規/再導入/更新失敗/再実行、一意索引、最低WP/PHP、Multisite、ネットワーク拒否 |
| 2 | Suite・Target・Device Repositoryと編集時検証 | includes/class-odvr-suite-repository.php、class-odvr-target-repository.php、class-odvr-device-repository.php、tests/repositories.php | ID境界、JSON、Device参照、論理削除、別サイト、編集競合 |
| 3 | Run開始・Manifest固定・Environment・Baseline選択/昇格 | includes/class-odvr-run-manager.php、class-odvr-run-repository.php、class-odvr-environment.php、tests/runs.php | 履歴不変、全組み合わせ作成、同時開始、Baseline全モードと保護 |
| 4 | Snapshot UPSERT・状態遷移・COUNT・期限処理 | includes/class-odvr-snapshot-repository.php、class-odvr-run-manager.php、tests/snapshots.php | 再送/衝突/Job retry、Complete早着/再送、遅延Upload、各終端状態 |
| 5 | Retention・再開可能な削除・無効化/Cron・Uninstall | includes/class-odvr-retention.php、class-odvr-storage.php、class-odvr-deactivator.php、uninstall.php、tests/retention.php | 参照閉包、Baseline/実行中保護、削除競合、画像削除失敗と再開、既定保持 |

クラス名・ファイル名は #1のODVR接頭辞とWPCS規約を踏襲する。RESTやToken/Storageの最終契約は #4、通信制御は #5、画像互換性は #6、クラウドの待機・再送は #10へ引き継ぐ。手順4の画像確定と手順5の物理削除はStorage契約の採用後に実装する。

## 検証ケースと受け入れ条件の対応

| Issue #2の条件 | この設計の該当箇所 | 実装時の確認 |
|---|---|---|
| 5テーブル・関連・移行 | テーブル定義、DB導入・更新、Multisite | wp-envで新規/再実行/中断再開。実テーブル・索引・Engine・Versionを確認 |
| Run開始後の履歴不変 | Runのmanifest、作成手順、論理削除 | Suite名/設定、Target URL/ラベル、Deviceを編集・無効化し、過去Manifestと結果が変わらない |
| Baselineと状態遷移 | Baseline表、状態遷移表、UPSERT | 全選択モード、他Suite・失敗Runの拒否、欠損PNG、単一対象失敗、全失敗、期限切れを確認 |
| Retentionの保護と削除順 | 参照閉包、競合、DB/画像削除 | N=1/10/20/Custom、参照連鎖、Run開始と削除の競合、ファイル失敗、中断再開を確認 |
| 5項目以内の実装順と未決事項 | 変更予定表、次節 | 各Issueに対象ファイル・検証と設計依存を引き継ぐ |

追加で、同時Upload2件、同一/異なるdigest、応答喪失、Job retry、二重Complete、Deadline直後のUploadを検証する。再送後もSnapshot数・成功/エラー件数・参照・画像が同じであることを確認する。

WordPress Object/Custom URLは資格情報をURLへ埋め込まず、URLとログの秘匿条件は #4の入力契約へ引き継ぐ。Manifest・Environment・ラベル等も管理者専用データとして扱う。

DBの検証はWordPress 6.7と既定版、PHP 7.4、InnoDBで行い、MySQL/MariaDBのサポート対象環境で型・索引・JSON処理・DDL再試行を検証する。Multisiteでは同じIDを持つ2サイトを用意し、読取・更新・削除が越境しないことを検証する。

このPRは設計文書のみなので、製品の全テストやDB導入を実行しない。仕様の必須項目と既存CaptureSettingsを照合し、Markdown・リンク・変更範囲を確認する。トランザクション・DDL・Storageの動作を検証済みと表記しない。

## リスク・未決事項

| 項目 | 今回の決定 / 次に確定する範囲 |
|---|---|
| 参照連鎖と容量 | Run履歴とBefore画像を優先して参照を保護する。Last NでもN件以上になり得る。UIで保護理由を示す。参照切断・画像複製による圧縮は対象外 |
| ロックとトランザクション | InnoDB、Suite単位の排他を前提とする。実装時にDevice編集・移行の排他、Deadlock・ロックTimeoutを含めて検証する |
| ファイルとDBの整合 | deletingから再開できる順序は確定。Uploadの一時領域・atomic rename・読取中の削除・PNG/digest検査・Web公開防止は #4で確定 |
| APIとのstatus差 | DB値と件数定義を確定。JSON Schema、認証、エラーコード、Tokenの閉じたRunへの再送権限は #4で統一 |
| 比較互換性 | 開始時のSnapshot対応と理由記録を確定。厳密な条件fingerprint、Version許容範囲は #6・#4で確定 |
| 外部Jobと期限 | queued15分・総90分を初期値とする。Dispatcherの受付照合、Cloud Runのretry・待機時間、Token期限との整合は #10・#4で検証 |
| Multisiteの運用 | サイト単位導入、ネットワーク一括有効化拒否は確定。サイト削除・ネットワークUninstallの安全なページングと運用手順はDB/削除実装Issueで検証 |
| Secretの保管 | Manifest/履歴に平文Token・Basic認証・Shared Secretを保存しない。サイト設定の保管・Runnerへの安全な受渡しは #4 |

設計採用後は、上記の未決部分が関係する実装へ着手する前に、対応Issueの決定内容を確認する。プロトタイプのSchemaや実装を、この設計が反映済みであるかのように変更しない。

## Issue #30 の製品実装

固定Manifest・Environment、Baseline全モード/利用時Version検査、Runの状態遷移・期限・Complete再送を実装しました。保存形式、内部Tokenの扱い、検証と後続Issueの境界は [runs-and-baselines.md](runs-and-baselines.md) を参照してください。
