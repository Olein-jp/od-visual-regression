# Retention・削除・Uninstall

Issue #31 の実装。管理 API と画面への接続は後続の #32・#40 で行う。

## 自動保存期間

Suite の `retention` は `all`（Keep All）または `last` と件数で保存する。Last 10・Last 20 は件数 10・20、Custom は同じ契約で任意の許可件数を設定する。

最新 N 件は `created_at DESC, id DESC` の終端 Run を数える。queued・running・deleting はこの枠に数えない。Pinned、queued/running、最新 complete（`completed_at DESC, id DESC`）も保護する。これらから Run の `reference_run_id` と Snapshot の `baseline_snapshot_id` を再帰的に辿り、参照先をすべて保護する。Keep All では全履歴を保護する。未知の保存 Version、破損した保存契約、欠落・別 Suite・逆向きの参照は、削除候補に読み替えず処理を拒否する。

`ODVR_Retention::plan(suite_id)` は内部の候補・保護理由を返す。`request(suite_id, [], true)` は Suite ロック下で再計算する。毎時の `odvr_retention` は Suite 最大 10 件を巡回し、削除中 Run 最大 25 件を ID 降順で再開する。カーソルを保存し、失敗する最新 Run が古い Run の巡回を妨げない。WP Cron の実行はサイトへのアクセスなどに依存するため、時刻どおりの実行は保証しない。

## 手動削除と再開

`request(suite_id, [run_id, ...])` は Keep All・最新 N 件・最新 complete に含まれていても削除できる。Pinned、稼働中、削除集合の外から参照される Run は拒否する。参照元と参照先をまとめて選ぶ場合は、外からの参照がない閉じた集合全体を 1 トランザクションで `deleting` にし、Token hash を NULL にする。失敗時は一部だけ確定しない。

COMMIT 後に `resume(run_id)` が私有画像・一時画像、Snapshot、Run の順で削除する。参照元を先に完了させる。Run の排他ファイルロックは画像の読み取り終了を待ち、固定 lock ファイル自体は残す。画像が既に一部消えている場合も再開できる。失敗時は `deleting`、削除要求時刻、未削除の履歴を残し、定型の `deletion_error` を表示する。再試行で正常に完了すると診断値も消す。Token の復活・Baseline の別履歴への切り替えは行わない。

## 無効化と Uninstall

無効化ではサイトの停止状態を保存して全 Run Token を失効し、3 つの ODVR Cron を停止する。履歴、画像、管理権限を保持する。既に読み込まれたリクエストも保存済み停止状態で書き込みを拒否する。再有効化では DB の確認後に停止状態を解除する。古い Token は復活しない。期限を過ぎた稼働履歴は Run の期限処理で確定する。

Uninstall の既定も保持である。全 Token と Cron を停止し、DB・画像・管理権限を残す。明示削除は**削除対象の各サイト**で `odvr_delete_data_on_uninstall` を true に保存してから行う。現段階では画面がないため、運用者は対象サイトを確認して WP-CLI で設定できる。

```sh
wp --url=https://対象サイト.example option update odvr_delete_data_on_uninstall true --format=json
```

稼働 Run がある間は明示削除を拒否する。明示削除開始後はサイトを削除中に固定し、Pinned を外し、Run を削除中にして全 Token を失効する。私有保存領域の回収が成功した後だけ、現在サイトの ODVR 5 テーブル、既知の ODVR Options、`manage_odvr` を削除する。WordPress 投稿・ユーザー・Media Library・ODVR 私有領域外の Uploads を削除しない。

途中で失敗すると DB・選択値・削除中状態・定型診断を保持する。プラグインの再有効化は拒否する。同じ入口を再試行して完了させる。未完了データを元の稼働状態に戻さない。

## Multisite とサイト単位の操作

100 サイト以下のネットワークでは各サイトへ切り替え、そのサイトの選択値・テーブル prefix・Uploads を使う。未選択サイトの履歴・画像を保持する。親サイトの Repository インスタンスを子サイトで再利用すると拒否する。

100 サイトを超えるネットワークの一括 Uninstall は最初に拒否する。サイトごとに選択を確認し、プラグインファイルを残したまま WP-CLI でサイト単位の処理を完了させる。

```sh
wp --url=https://対象サイト.example eval 'require_once WP_PLUGIN_DIR . "/od-visual-regression/od-visual-regression.php"; $result = (new ODVR_Uninstaller())->current_site(); if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); } WP_CLI::success("サイトの保持・削除処理が完了しました。");'
```

全サイト処理後は各サイトの停止状態と削除選択に応じた残存データを確認し、配布ファイルの除去を行う。100 サイト超の入口を無制限処理へ変更して成功扱いしない。

## 検証

`tests/retention.php` は隔離した DB・画像領域で Last 10/20・Keep All・Custom、Run/Snapshot の参照閉包、未知 Version 拒否、Pinned/稼働保護、閉じた削除集合、画像削除の部分失敗と再開、Cron を検証する。独立した WordPress プロセスで Baseline 昇格と削除の競合、共有読取ロックと削除待機を確認する。既定保持、明示削除の失敗・再開、私有領域回収後の DB 削除も検証する。

`tests/uninstall-multisite.php` は WordPress の Uninstall 入口を実行し、選択した子サイトだけの削除、未選択子サイトの保持、親サイト 5 テーブル・投稿・私有領域外ファイルの不変、100 サイト上限を確認する。CI の WordPress 最新版・6.7 / PHP 7.4 で実行する。

ローカルファイルシステムと wp-env で検証している。NFS、プロセス強制終了・電源断、100 サイト超の全件運用は実機検証していない。私有保存先の環境要件は [private-storage.md](private-storage.md) を参照する。
