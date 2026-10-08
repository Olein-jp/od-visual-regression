# 非公開Storageと認証画像配信

Issue #29で `ODVR_PNG`、`ODVR_Storage`、管理画像のREST配信を実装した。Run作成・UploadのDB確定は後続の#30/#33、Retentionのdeleting確定は#31、管理用接続診断の公開は#32で接続する。

## 環境設定と診断

サイトの `wp_upload_dir().basedir/od-visual-regression` を使用し、Media Libraryやpublic URLへ登録しない。ローカルfilesystem、同一filesystemのatomic rename、flock対応が必要。オブジェクトストレージへのuploads置換・自動CDN同期はMVP対象外。

GD・zlib・finfoと、最大40,000,000画素を完全検証できるメモリが必要。空きメモリを `画素数×16 + 圧縮サイズ×3 + 64MiB` で確認するため、PHPのmemory_limitは通常1GiB以上を用意する。Pluginは制限を無断で引き上げず、不足なら503で保存を拒否する。

Pluginはルートへ `.htaccess` の `Require all denied` を新規作成するが、これだけで保護済みとは表示しない。まず運用者が実Web設定を確認する。

Apache 2.4のサーバー設定例。実際のサイト別パスへ置き換える。

```apache
<Directory "/var/www/html/wp-content/uploads/od-visual-regression">
    Require all denied
</Directory>
```

.htaccessを使う場合はAllowOverride AuthConfigまたはAllowOverrideList Requireと、上位設定で解除されないことを確認する。Nginxは.htaccessを読まないため、実際のuploads URL prefixごとに明示する。

```nginx
location = /wp-content/uploads/od-visual-regression { return 403; }
location ^~ /wp-content/uploads/od-visual-regression/ { return 403; }
```

Multisiteの `/wp-content/uploads/sites/{blog_id}/od-visual-regression/`、変更したuploads URL、alias、CDN、リバースプロキシの各経路にも設定する。CDN同期から除外し、既存コピーがあれば削除する。Basicを通過した後もStorageが403/404になることを確認する。WAFの一括拒否やBasicの401だけではStorage保護を判断できない。

実設定の確認が完了した環境でだけwp-config.phpへ次を定義する。確認前にtrueを設定しない。

```php
define( 'ODVR_STORAGE_PROTECTION_VERIFIED', true );
// 追加の公開経路がある場合：各サイトのuploads base URLを列挙する。
define( 'ODVR_STORAGE_PUBLIC_BASES', array( 'https://uploads.example.test/site-uploads' ) );
```

`(new ODVR_Storage())->diagnose()` は固定inodeのflock競合と同一filesystemのrenameを実測し、秘密でないランダムcanaryを作り、uploads直下の対照ファイルが200・内容一致することと、Storageルート/.staging/.locksが403/404で内容を返さないことを全経路で確認し（1回5秒・診断全体20秒）、finallyでcanaryを除去する。通信失敗・redirect・200・401・対照ファイルまで拒否するWAF・環境設定未確認・メモリ不足はreadyにしない。Basic環境ではHTTPSのorigin限定の内部引数 `array('origin'=>..., 'username'=>..., 'password'=>...)` を診断へ渡す。値をOptionや応答・URLへ保存しない。

診断成功はサイトID・実パス・全公開経路へ固定し120秒で失効する。新規Run/Upload側は保存前に診断を更新し、`ready()`の成功を確認する。期限切れや環境変更後の書込は503。Web設定・alias/CDNの将来変更をPluginだけで保証せず、構成変更時に運用確認をやり直す。

## 内部保存APIと整合性

- `stage(run_uuid, png, width, height)` は検証後、Run排他ロックで `.staging/{run_uuid}/{request_uuid}.png` へ保存する。内部ticketはAPIへ返さない。
- `with_run_lock(run_uuid, true, callback)` の中で `promote(ticket, suite_uuid, target_id, device_slug, result_digest, diff)` を呼ぶ。サーバー生成の `suite-{uuid}/run-{uuid}/target-{id}/{slug}-{digest}[-diff].png` を返す。同名を上書きせず、Run排他ロックを持たないrenameは拒否する。
- Upload側はRunのStorage排他ロックの内側でSuite→Run→SnapshotのDBロックを取得し、状態再確認後にrename・DBのpending条件付き更新・COMMITをロック内で完了する。二枚目のrenameやCOMMITに失敗しても、未参照ファイルを配信しない。呼出側はWP_Errorを見落とさずrollbackする。
- DBにはStorageルート相対パスとmetadata_version=1、`image_sha256` / `diff_sha256`を保存する。クライアントのfilename/pathを使用しない。PNGの全祖先とファイルのsymlink、realpath境界、相対パス形式を検査する。

PNGはsignature・実サイズ・finfo・IHDRの型/寸法/画素数、全チャンクのCRCと並び、IDATの完全inflate・終端/展開長・各scanlineのfilter・GDの完全デコード・SHA-256を検査する。末尾データ・未知critical・APNG・圧縮された付加メタデータiCCP/zTXt/iTXtを拒否する。Adam7は各passを展開検査し、GDの既知のinterlace運用警告だけを区別する。他のdecode警告は拒否する。上限は1ファイル20MiB・1辺16384px・40,000,000画素。

## 配信・削除・cleanup

`GET /wp-json/odvr/v1/snapshots/{id}/image?kind=current|diff|baseline` はログインCookie、X-WP-Nonce、manage_odvrをすべて要求する。URLのnonceやToken、public URLへのredirectを使わない。管理画面はcredentials付きfetch→Blob→object URLで表示し、終了時にrevokeする。

Snapshotの確定状態、同サイトRun/Suite、deletingでないこと、固定BaselineのRun・Target・Device一致を確認する。共有Runロックの取得後にもDB参照を再検査し、実寸・digestを検証したPNGだけをPHPからstreamする。エラーも含めprivate/no-storeとnosniff、成功時はimage/png・Content-Length・X-ODVR-Image-SHA256。Range/条件付き304には応じず、毎回認証する。REST画像の共有cache/CDNキャッシュもホスト側で除外する。

ロックは `.locks/run-{uuid}.lock` の固定inodeを保持し、Runディレクトリの削除でunlinkしない。`delete_run(id)` はDBでdeleting・Token失効が確定し、Suite/保持Runの参照がない場合だけ排他ロックを取得して画像を削除する。既存streamの終了を最大5秒待ち、失敗時は503でdeletingを維持する。画像削除は冪等。DB行の物理削除はRetention側で後から行う。

`cleanup(uuid)` はRun行の書込ロック→Storage排他ロックの順で最新DB参照を確認し、1時間以上未更新のstaging・未参照PNGだけを削除する。新しいstaging・DB参照・deleting Runは保持する。毎時のodvr_storage_cleanupはstaging最大50件とRun最大25件をcursorで巡回する。無効化時はCronを止め、履歴・設定・画像を残す。

プロセス停止後の孤立ファイルはcleanupで回復する。PHP 7.4にfsyncはないため、停電時の完全永続性やNFS・ネットワークfilesystemを検証済みとは扱わない。

## 検証範囲

PHP 7.4 / WordPress 7.1.3・6.7のwp-env Apacheで、PNG破損/CRC/展開/20MiB境界/寸法/digest/Adam7、実Cookie/nonce/権限、直URL拒否、pendingとCOMMIT前、rollback、固定Baseline/diff、symlink、cleanup、独立プロセスの読取削除競合を検証する。独立Multisiteの実子サイトでも保存先・診断Options・uploads/sites直URL・孤立staging・親サイト保持を確認する。CIは両WordPress Versionで実行する。

Basicのorigin限定と複数aliasの成功/拒否は固定HTTP fixtureでも確認する。Nginx、実運用のBasic/TLS・alias・CDN、ネットワークfilesystem、停電耐性は本Issueでは実機未検証。手順の記載を設定完了の証拠にせず、各環境で上記canaryと運用確認を完了するまでreadyにしない。Uploadのraw multipart検証と部分失敗回復は[#33](runner-results-api.md)で実装・検証した。Run全体の撮影・結合検証は後続Issueで行う。
