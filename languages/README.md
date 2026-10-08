# 翻訳ファイル

テキストドメインは `od-visual-regression` です。PHP 内の表示文字列は `__()` や `esc_html__()` などの翻訳関数に通してください。文字列の元言語は英語を推奨します。

プラグイン名を翻訳する日本語の PO・MO を同梱しています。表示文字列を追加したら POT を再生成し、Poedit などで既存 PO を更新してください。

```php
echo esc_html__( 'Settings', 'od-visual-regression' );
```

1. WordPress 環境を起動して `npm run i18n:pot` を実行します。
2. POT を Poedit などで開き、対象言語の PO を作成します。日本語なら `od-visual-regression-ja.po` としてこのディレクトリに保存します。
3. `npm run i18n:mo` で MO を生成します。Poedit の保存時に MO を生成する方法でも構いません。
4. WordPress のサイト言語を変更し、翻訳した表示を確認します。

PO と MO は同じファイル名のベースを使用します。例：`od-visual-regression-ja.po` と `od-visual-regression-ja.mo`。POT・PO・MO は Git 管理対象です。

プラグインは `init` で同梱翻訳の場所を登録します。表示文字列の翻訳処理も `init` 以降で実行してください。JavaScript の翻訳が必要になった時点で `wp_set_script_translations()` などを追加します。
