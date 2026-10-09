# CGI/FastCGIでのRunner専用入口

Cloud Run の検証サイトが CGI/FastCGI の共有サーバーを使う場合の、WordPress直下設置用の入口。通常のAPI・ログイン・フォームとPHP設定を分ける。2026年10月9日の staging 接続診断ではプラグイン登録とBearer転送は成功、生multipartは未対応だった。設置後、`.user.ini` の配置修正により生本文対応は available となった。専用rewriteでAuthorizationが失われることを確認し、転送前に保存する規則を追加した。Authorization転送のstaging適用は成功したが、multipartの本文は消失し、JSON/octet-streamは認証拒否へ到達した。実PHP-CGIでも `.user.ini` がOffのまま自動展開される挙動を再現したため、画像POST限定の内部型保存・PHP手前での型切り替え・入口での復元を追加した。この追加修正のstaging適用、認証済み画像保存と通常POSTフォームの実測は未完了。

PHP の `enable_post_data_reading` は起動後の `ini_set` では変更できない。CGI/FastCGI は実行する PHP ファイルのディレクトリの `.user.ini` を読むため、専用ディレクトリのPHPへ内部rewriteし、その経路にだけ設定を適用する。[PHP公式の設定仕様](https://www.php.net/manual/en/configuration.file.per-user.php)、[ディレクティブ仕様](https://www.php.net/manual/en/ini.core.php#ini.enable-post-data-reading)を参照する。エックスサーバーの Xアクセラレータ Ver.2 も `.user.ini` を使い、反映に最大5分程度かかる場合がある。[公式の説明](https://www.xserver.ne.jp/manual/man_server_xaccelerator.php)を踏まえた構成だが、すべての契約・サーバーで動作を保証するものではなく、設置後の製品接続診断で確定する。

## 設置

1. 検証サイトのWordPress直下（`wp-admin`、`wp-content`、`wp-blog-header.php` がある場所）に `odvr-runner-gateway/` を作り、`infra/wordpress/gateway/` の `index.php`、`.user.ini`、`.htaccess` を置く。公開URLは `/wp-json/odvr/v1/runner/...` を維持する。このテンプレートはルートURLにWordPressを置いた単一サイト用。subdirectory・Multisite・独自REST prefixにはそのまま使わない。
2. 既存のWordPress直下 `.htaccess` をバックアップする。`infra/wordpress/root-htaccess.snippet` のブロックだけを既存の WordPress rewrite より前へ追加する。専用rewriteより前の `RewriteRule ... - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` も含める。既存WordPressのAuthorization転送は専用rewriteの `[END]` で省略されるため、この保存規則が必要になる。ファイル全体を置き換えない。`# BEGIN WordPress` 内に入れるとWordPressの更新で消える場合がある。
3. `.user.ini` をWordPress直下やドメイン全体には置かない。既存 `.user.ini` やサーバーパネルのドメイン全体設定を変更しない。CGI/FastCGI で mod_php 用の `php_flag` / `php_value` を追加しない。
4. 設定の反映後、固定ダミーBearerを使う既存の接続診断で401、`odvr_runner_unauthorized`、`X-ODVR-Runner-Gateway: 1`、`X-ODVR-Bearer-Header: present`、`X-ODVR-Raw-Multipart: available` を確認する。同時にトップページ、通常REST、ログイン/通常POSTフォームが従来どおり動き、専用 `index.php` の直接URLが404、`.user.ini` の直接URLが403/404となることを確認する。PHP情報を公開する診断ページは作らない。専用入口の503は、ディレクトリ別設定への対応、`.user.ini` のファイル名一致・存在・読み取り可否を真偽値で返す。FTPでは隠しファイル表示を有効にして `.user.ini` が転送されているか確認する。診断にはサーバーのパス・環境変数・資格情報を含めない。

この入口は検査済みのmethod・固定Runner pathだけを既存WordPress RESTへ渡す。query・直接入口・他API・別method・不正長さ・42MiB超過・PHP設定未反映をWordPress起動前に拒否する。WordPressでのBearer/Run/Execution/期限検査は既存 controller が引き続き担当する。HTTPSの判定とAuthorizationの転送はWebサーバー側の実測が必要。設定が無効な場合はfail closedとし、通常index.phpへfallbackしない。

## 検証と撤去

ローカル検証は `npm run test:cloud-plan` 内の実PHP入口試験。WordPressのbootstrap fixtureを用い、固定経路への渡し方、他経路・query・method拒否、未反映・本文長さ境界を検証する。CLIは `.user.ini` を読み込まないため、この試験はFastCGIの設定適用を実測したものではない。実WordPress・CGI/FastCGI・Apache rewrite・生multipart・フォーム互換はstagingで別途確認する。[結果受付の契約](runner-results-api.md)と画像保存先の非公開診断も満たしてからCloud Run試験へ進む。

撤去時はWordPress直下 `.htaccess` から `# BEGIN ODVR Runner Gateway`〜`# END ODVR Runner Gateway` の自分が追加したブロックだけを削除する。通常経路の復帰を確認してから専用ディレクトリを削除する。既存のWordPress rewriteや他用途のサーバー設定を削除しない。

Authorization転送の修正は、一時的なlocalhostの実Apache/CGIで旧規則のヘッダー欠落と修正規則の `REDIRECT_HTTP_AUTHORIZATION` 保持を比較確認した。WordPress RESTはこの変数をAuthorizationとして読み取る。[Apacheの環境変数規則](https://httpd.apache.org/docs/2.4/rewrite/flags.html#flag_e)、[WordPress RESTのヘッダー取得](https://developer.wordpress.org/reference/classes/wp_rest_server/get_headers/)を参照。検証用ヘッダーのみを使い、値は証跡に記録していない。

## CGIのmultipart自動展開への対策

`.user.ini` にOffを指定しても、適用前にmultipartが展開されて生本文が消える場合がある。[PHPの既知の報告](https://bugs.php.net/bug.php?id=75741)と同様の挙動を実PHP-CGI 8.4.8で再現した。設定値だけのGET診断を画像受信成功と扱わず、POSTの実バイト数を別途検証する。

ルートsnippetはPOSTのRunner snapshots経路とmultipart型だけを選び、元の型を内部環境変数へ保存する。専用ディレクトリの `.htaccess` でPHPに渡す直前の型をoctet-streamにし、専用 `index.php` がWordPress起動前に元のmultipart型へ戻す。送信者のHTTP契約・境界・本文・Content-Lengthは維持し、PHPの標準フォーム展開に重複項目を失わせない。`HTTP_ODVR_RAW_MULTIPART` 等のクライアントヘッダーからは復元せず、他経路・method・改行・過大な型・型の不一致を拒否する。mod_headersが適用されない場合は受信を継続できず、公開前に実POST診断で検出する。

一時localhostの実Apache/PHP-CGIで、同じ93バイトのmultipartが旧設定では0バイト/自動展開1項目、修正後は93バイト/自動展開0項目となり、元のmultipart型とBearerが保持されることを確認した。23件の定義/入口試験では、クライアントヘッダー偽装・他経路・型不一致・改行注入も拒否した。stagingでの正常認証による画像保存は別途実測する。
