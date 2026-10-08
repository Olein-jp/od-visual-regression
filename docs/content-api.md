# 公開コンテンツ選択API

Issue #3、仕様書 §15・§22・§23・§68 に対応する管理画面用の読み取りAPIです。`get_post_types()`・`WP_Query`・`get_permalink()` を使い、既存の投稿REST APIには依存しません。

## 認証

両エンドポイントはログインCookie、`wp_rest` nonce、`manage_odvr` 権限を必要とします。nonceは `X-WP-Nonce` ヘッダーまたは `_wpnonce` パラメーターで送ります。Cookieなしは401、nonce不正・権限なしは403です。nonce欠落はWordPressコアが未ログインとして扱い401になります。Cookieを伴わないApplication Password等によるアクセスは許可しません。

WordPressコアのCookie認証・REST nonce検証に加え、Controllerのpermission callbackでもCookieと現在ユーザーの一致・nonce・権限を検査します。[WordPressの認証仕様](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) を参照してください。

## 投稿タイプ

`GET /wp-json/odvr/v1/content/post-types`

```json
[
  { "post_type": "post", "label": "投稿" },
  { "post_type": "page", "label": "固定ページ" }
]
```

`public=true` かつ `is_post_type_viewable()` が真の投稿タイプを返します。公開CPTは `show_in_rest=false` でも含まれます。

## 投稿検索

`GET /wp-json/odvr/v1/content?post_type=page&search=サービス&page=1&per_page=20`

| パラメーター | 契約 |
|---|---|
| `post_type` | 省略時は全対象タイプ。指定時は投稿タイプ一覧に含まれる名前1つ |
| `search` | 省略時は空文字。200文字以下の文字列。制御文字を拒否し、検索前にプレーンテキスト化 |
| `page` | 1〜1,000,000の整数。既定値1 |
| `per_page` | 1〜100の整数。既定値20 |

```json
[
  {
    "object_id": 42,
    "post_type": "page",
    "label": "サービス",
    "url": "https://example.com/service/"
  }
]
```

公開済み・パスワードなしの投稿のみを対象にします。下書き・非公開・予約・承認待ち・ゴミ箱は含みません。ID昇順でページングし、固定表示投稿の特別扱いはしません。ラベルはHTMLタグを除いた文字列です。管理画面はラベルをテキストとして描画してください。

`X-WP-Total`・`X-WP-TotalPages` ヘッダーは公開条件に一致する投稿の件数とページ数を返します。該当なしの1ページ目は空配列・件数0、範囲外の2ページ目以降は400です。不正な検索条件も400です。

投稿URLは下記の形式検証を通ったものだけを返します。permalinkフィルター等が無効なURLを生成する場合、その項目を省くため、配列の件数は `per_page` や集計値より少なくなることがあります。

## Custom URLの共通検証契約

プラグイン初期化時に読み込まれる `ODVR_Target_URL::validate( $url )` をSuite CRUDから呼び出せます。

- 成功：`esc_url_raw()` による保存用URL文字列。
- 失敗：`WP_Error`、コード `odvr_invalid_target_url`、HTTPステータス400。
- 2048バイト以下のHTTP(S)絶対URLが必要。ホスト必須、資格情報（空のuserinfoを含む）を拒否。
- 空白・制御文字・バックスラッシュ・不正なpercent encoding・percent encoded制御文字、不正ポートを拒否。
- 国際化ドメインはPunycode、非ASCIIパスはpercent encodingで渡します。

この関数は通信・DNS解決を行いません。localhostやプライベートIPも形式としては許可します。Suiteへの保存と、撮影時のAllowed Origins・SSRF接続先検証は後続Issueの責務です。

## 検証

```sh
composer lint
find wordpress -name '*.php' -print0 | xargs -0 -n1 php -l
npm run env:start
npm run env:cli -- eval-file tests/content-api.php
```

WordPress実機に一時的な公開/非公開CPT、投稿、管理者/購読者を作成し、検索・絞り込み・ページング・公開範囲・認証・不正入力・URL形式を検査します。Cookieとnonceを設定してコア認証チェックとRESTディスパッチを実行します。HTTP経由のブラウザ操作テストではありません。検証データは終了時に削除します。CIではWordPress最新および6.7で実行します。
