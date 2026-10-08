# Suite・Target・Deviceの保存

Issue #28で、Suite / Target / Device Repositoryと共通のサイト境界・トランザクション処理を実装した。REST公開とCookie/nonce/権限の入口は#32、Run固定・Baselineは#30。Repositoryを認証済みAPIの代用として公開しない。

## 呼出しと保存形式

`ODVR_Suite_Repository`はcreate(input, created_by) / get(id) / update(id, input) / archive(id) / list_items(page, per_page, status)を提供する。`ODVR_Target_Repository`はSuite IDをすべての操作に要求し、create / get / update / disable / list_itemsを提供する。`ODVR_Device_Repository`はcreate / get / update / disable / list_itemsを提供する。JSON入力はstdClass、戻り値は表示用itemの配列またはWP_Error。一覧はitems / totalで、ID昇順・最大100件ずつ取得する。

共通Schemaで未知キー、型、ID範囲、閾値、重複Device ID、URL、Versionを検査する。DB整数もAPI範囲へ丸めない。SQLは固定の許可済みテーブルとprepare、wpdbのINSERT/UPDATEで構成する。Repository作成時のサイトIDとprefixを固定し、switch_to_blog後に旧インスタンスを使う操作を拒否する。

Suiteのsettings列は `{settings_version:1, settings:{settings_version:1,...}, device_ids:[...], allowed_origins:[...], retention:{...}}`。CaptureSettings内のVersionと保存ルートのVersionを両方検査する。retentionは後から確定した製品Suite契約に従うSuite別設定で、サイトの既定値・接続・秘密設定とは分離する。破損JSON・未知保存Version・未知保存項目を空設定に読み替えない。

## 検証と履歴保持

- 公開かつパスワード保護のない投稿・public/viewable CPTだけを参照する。show_in_restがfalseでも選択可能。URLは自己申告値に依存せず現在のpermalinkを保存する。Suite編集時も有効Targetの公開状態を再検査する。
- Custom URLは形式だけを検査し、保存時に到達性や撮影許可を判定しない。同じURLの別Target IDは別対象。
- 有効TargetはSuiteごとに最大100。無効化したTargetは残し、再有効化を拒否し、再追加は新ID。論理削除済み行は通常の一覧から除外できる。参照投稿が消失・非公開でも無効化できる。
- 選択DeviceはSuiteごとに最大10。有効な同サイトDeviceだけを選べる。サイトの登録総数へ10件上限を適用しない。slugは一意で作成後変更不可。参照中の無効化はusing_suites付き409。archive済みSuiteも参照を保持する。
- using_suitesは参照する全Suiteを返す。一覧のページ上限100を参照数へ適用していた共通Schemaを修正し、101件の参照でもDevice表示と無効化拒否の応答を検証できるようにした。
- Suiteは実行中queued/runningがなければarchiveできる。archiveは冪等、以後の設定編集・新規Target追加は拒否する。Run・Snapshot・Baselineの行や固定Manifestを書き換えず、物理削除しない。

## 編集競合

短い書込全体をREAD COMMITTEDで実行する。Suite編集とTarget操作はSuiteをロックし、必要なTargetとDeviceはID昇順にロックする。Target追加の件数確認とINSERTは同じSuiteロック内。部分更新はロック取得後の最新行にmergeする。同じ項目への連続編集は後の変更が優先され、独立した項目の更新は失わない。

Device編集はDevice行だけをロックする。無効化時の全Suite参照は最新読取で検査し、Device→Suiteの逆順ロックを作らない。Suite保存は選択Deviceをロックして有効性を検査する。Deadlock/lock timeoutのみ全処理を一度再試行し、それ以外はrollbackしてWP_Errorを返す。外部トランザクション内からの呼出しは拒否し、呼出側の書込を無断COMMIT/ROLLBACKしない。

DBの移行中・診断失敗・未知DB Versionでは書込を停止する。commit直前にも現在のDB状態とサイト境界を確認する。

## 検証

```sh
composer lint
npm run env:cli -- eval-file tests/repositories.php
npm run env:cli -- eval-file tests/content-api.php
```

PHP 7.4、WordPress 7.1.3/6.7で実機検証する。Repository試験は独立DB接続の一時prefix/Optionsと試験投稿を使用し、finallyで片付ける。競合試験の子プロセスも一時prefixだけを受理する。JSON/ID/URL/参照・100 Target×10 Device・論理削除・archive・有限再試行・rollback・採番上限を検査する。

実際に同時接続を使い、Suiteの別項目PATCH、Device無効化とSuite保存、100件境界のTarget追加を競合させる。Multisiteでは同じ試験に加え、実子サイトのSuite/Target/Device編集、旧サイトRepositoryの拒否、親5テーブルの全行保持を確認する。CIのlatest/6.7で単一サイトと独立したMultisiteを実行する。Run作成やRESTを検証済みとは扱わない。
