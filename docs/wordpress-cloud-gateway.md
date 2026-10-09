# CGI/FastCGIでのRunner専用入口

Cloud Run の検証サイトが CGI/FastCGI の共有サーバーを使う場合の、WordPress直下設置用の入口。通常のAPI・ログイン・フォームとPHP設定を分ける。2026年10月9日の staging 接続診断ではプラグイン登録とBearer転送は成功、生multipartは未対応だった。**ファイル設置後の実機検証はまだ行っていない。**

PHP の `enable_post_data_reading` は起動後の `ini_set` では変更できない。CGI/FastCGI は実行する PHP ファイルのディレクトリの `.user.ini` を読むため、専用ディレクトリのPHPへ内部rewriteし、その経路にだけ設定を適用する。[PHP公式の設定仕様](https://www.php.net/manual/en/configuration.file.per-user.php)、[ディレクティブ仕様](https://www.php.net/manual/en/ini.core.php#ini.enable-post-data-reading)を参照する。エックスサーバーの Xアクセラレータ Ver.2 も `.user.ini` を使い、反映に最大5分程度かかる場合がある。[公式の説明](https://www.xserver.ne.jp/manual/man_server_xaccelerator.php)を踏まえた構成だが、すべての契約・サーバーで動作を保証するものではなく、設置後の製品接続診断で確定する。

## 設置

1. 検証サイトのWordPress直下（`wp-admin`、`wp-content`、`wp-blog-header.php` がある場所）に `odvr-runner-gateway/` を作り、`infra/wordpress/gateway/` の `index.php`、`.user.ini`、`.htaccess` を置く。公開URLは `/wp-json/odvr/v1/runner/...` を維持する。このテンプレートはルートURLにWordPressを置いた単一サイト用。subdirectory・Multisite・独自REST prefixにはそのまま使わない。
2. 既存のWordPress直下 `.htaccess` をバックアップする。`infra/wordpress/root-htaccess.snippet` のブロックだけを既存の WordPress rewrite より前へ追加する。ファイル全体を置き換えない。`# BEGIN WordPress` 内に入れるとWordPressの更新で消える場合がある。
3. `.user.ini` をWordPress直下やドメイン全体には置かない。既存 `.user.ini` やサーバーパネルのドメイン全体設定を変更しない。CGI/FastCGI で mod_php 用の `php_flag` / `php_value` を追加しない。
4. 設定の反映後、固定ダミーBearerを使う既存の接続診断で401、`odvr_runner_unauthorized`、`X-ODVR-Runner-Gateway: 1`、`X-ODVR-Bearer-Header: present`、`X-ODVR-Raw-Multipart: available` を確認する。同時にトップページ、通常REST、ログイン/通常POSTフォームが従来どおり動き、専用 `index.php` の直接URLが404、`.user.ini` の直接URLが403/404となることを確認する。PHP情報を公開する診断ページは作らない。

この入口は検査済みのmethod・固定Runner pathだけを既存WordPress RESTへ渡す。query・直接入口・他API・別method・不正長さ・42MiB超過・PHP設定未反映をWordPress起動前に拒否する。WordPressでのBearer/Run/Execution/期限検査は既存 controller が引き続き担当する。HTTPSの判定とAuthorizationの転送はWebサーバー側の実測が必要。設定が無効な場合はfail closedとし、通常index.phpへfallbackしない。

## 検証と撤去

ローカル検証は `npm run test:cloud-plan` 内の実PHP入口試験。WordPressのbootstrap fixtureを用い、固定経路への渡し方、他経路・query・method拒否、未反映・本文長さ境界を検証する。CLIは `.user.ini` を読み込まないため、この試験はFastCGIの設定適用を実測したものではない。実WordPress・CGI/FastCGI・Apache rewrite・生multipart・フォーム互換はstagingで別途確認する。[結果受付の契約](runner-results-api.md)と画像保存先の非公開診断も満たしてからCloud Run試験へ進む。

撤去時はWordPress直下 `.htaccess` から `# BEGIN ODVR Runner Gateway`〜`# END ODVR Runner Gateway` の自分が追加したブロックだけを削除する。通常経路の復帰を確認してから専用ディレクトリを削除する。既存のWordPress rewriteや他用途のサーバー設定を削除しない。
