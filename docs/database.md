# DB導入・更新（Version 1）

Issue #27で、[データライフサイクル設計](data-lifecycle-design.md)の5テーブルと索引を実装した。定義は `ODVR_DB_Schema`、導入・排他・診断と共通保存規則は `ODVR_DB` が担当する。Repository・Run・Retention・Tokenの処理は後続Issueで追加する。

有効化時はネットワーク一括有効化を最初に拒否し、DBの導入と診断が成功してからmanage_odvrを付与する。通常初期化はodvr_db_versionを比較し、Version一致後にDDLや全テーブル診断を繰り返さない。サイトの現在の `$wpdb->prefix` から固定サフィックスだけを連結し、base_prefixや固定wp_を使わない。

## 保存・利用の入口

- `ODVR_DB::table('suites'|'targets'|'devices'|'runs'|'snapshots')` は現在サイトのテーブル名を返す。未知サフィックスは拒否する。
- 書込・削除前に `ODVR_DB::writable()` を確認する。DB Version不一致・保存済み診断エラー・移行lockの存在時はWP_Errorを返す。通常処理のRepositoryでもこの境界を使用する。
- `utc_now()` はUTCのDB datetimeを返す。`utc_datetime()` はUTC秒精度の通信値とDB datetimeを往復変換し、nullだけを未発生時刻とする。ゼロ日付・実在しない日時・曖昧なタイムゾーンは拒否する。
- `stored_json()` はsettings / environment / metadataそれぞれのVersion 1を検査してwp_json_encodeでlongtextへ保存し、読取時に破損・未知Versionを拒否する。個々のJSON項目の契約検証は保存側のRepositoryが行う。不正JSONを空設定へ補正しない。

すべてのテーブルはInnoDB必須。診断は実テーブルの存在、列型・null・AUTO_INCREMENT、索引の列順・一意性、InnoDBのトランザクション対応とSAVEPOINT/ROLLBACKを確認する。ネイティブJSON型・物理外部キー・CASCADEは使用しない。

## 排他・再実行・復旧

odvr_db_upgrade_lockは非autoload optionで、owner UUIDと120秒のleaseを持つ。初回はINSERT IGNOREで既存ownerを上書きせず取得し、期限切れ再取得・延長・解除はDBの現在値に対するCASを使う。DDL/DMLの各段階前に所有を確認し、ownerを失った処理は次の更新やVersion確定へ進まない。古いownerは新ownerのlockを消さない。Optionsキャッシュも無効化する。add_optionはWordPressで重複時UPDATEを実行するため、このlockの取得では使用しない。書込可否も現在のVersion・診断・lockをDBから直接取得し、notoptionsの古いキャッシュで移行中の書込を許可しない。

Version 0または未導入から、dbDeltaによる冪等DDL・実構造診断・初期Device補完を行い、成功後にVersion 1を保存する。Deviceの初期投入はトランザクション内で行い、slugが既存なら値を変更しない。再有効化とVersion一致時の診断では初期投入を繰り返さない。DDLとDevice投入は別の段階であり、一体のトランザクションとは扱わない。

失敗時はVersionを進めず、作成済みテーブルと定型診断codeを保持して書込を止める。途中作成済みのテーブル・索引・Deviceを使って再実行できる。SQLエラー本文や秘密は診断option/画面へ出さない。未知Versionから自動的にダウングレードしない。既存MyISAM等も自動変換しない。

構造診断は `ODVR_DB::diagnose()`。この診断はトランザクションを開始・終了するため、Repositoryのトランザクション中には呼ばない。必要条件を満たさない場合は診断codeを保存して書込を止める。

バックアップと手動復旧後、`ODVR_DB::upgrade(true)` またはサイト単位の再有効化で再診断する。成功時だけ保存済み診断を解除する。移行中のlockや未知Versionを、診断せず手動で削除して書込を再開しない。

## Multisiteと保持

サイト単位の有効化だけに対応する。新規サイトへ自動導入せず、Options・Device・権限・5テーブルを現在サイトだけに導入する。サイト完全削除時はWordPressのwpmu_drop_tablesへ、そのサイトのODVRテーブルを追加する。他サイトへ自動switchしない。

通常の無効化はDB・Device・設定・画像・権限を保持する。Run Token失効やUninstall、Retentionの具体処理はそれぞれの後続Issueで実装する。

## 検証

```sh
npm run env:cli -- eval-file tests/bootstrap.php
npm run env:cli -- eval-file tests/database.php
composer lint
npm run test:plugin-build
```

database.phpは一時prefixと独立したDB接続を使い、5テーブルの新規導入・再導入・更新・一意索引、UTC/JSON、Device保持、DDL/初期投入失敗、rollback、未知Version、非InnoDB、lease競合・期限切れ・owner喪失を検証する。元のDB接続とキャッシュをfinallyで戻す。

CIはWordPress既定版/6.7・PHP 7.4でこれらを実行し、独立したtests環境をMultisiteへ変換してdatabase-multisite.phpも実行する。ネットワーク拒否、サイト分離、新規サイトへの自動導入なし、子サイト完全削除と親サイト保持を確認する。開発サイトは単一サイトのまま維持する。
