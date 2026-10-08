# OD Visual Regression

WordPress プラグイン開発用のミニマム構成です。プラグイン本体は翻訳の読み込みだけを行うひな形で、ビジュアルリグレッション機能は未実装です。

## 必要な環境

- Node.js（wp-env の要件：18.12 以上。利用時点のサポート中の LTS を推奨）と npm
- Docker（Docker Desktop などを起動しておく）
- ローカルの PHP 7.4 以上と Composer 2（WPCS の導入・実行用）

プラグインの初期対応範囲は WordPress 6.7 以上・PHP 7.4 以上です。開発環境の PHP も 7.4 に設定しています。WordPress 本体は wp-env の既定の安定版を使用します。

wp-env 11.17.0 の一部の依存パッケージは Node.js 24.18 以上・npm 11.16 以上を要求するため、それより古い環境ではインストール時に要件警告が出ます。この構成の Docker 起動と翻訳生成は Node.js 20.19.2・npm 10.8.2 で動作確認しています。

## セットアップ

```sh
npm ci
composer install
npm run env:start
```

起動後のサイトは通常 `http://localhost:8888`、管理画面は `http://localhost:8888/wp-admin/` です。ローカル開発用のログインは `admin` / `password` です。実際の接続先は起動時の出力を確認してください。

wp-env 11.17.0 は既定でテスト環境も `8889` に起動し、その動作について非推奨警告を表示します。本構成では非推奨の設定項目を追加せず、wp-env の既定動作を使用しています。

```sh
npm run env:status
npm run env:logs
npm run env:stop
```

ローカルのポートなどを変更する場合は、Git 管理対象外の `.wp-env.override.json` を使用します。

## コードの確認

```sh
npm run lint:php
npm run format:php
```

WPCS で PHP を検査します。`format:php` はファイルを書き換える整形コマンドです。追加した PHP も検査対象になります。

WP-CLI を使う場合は、起動した環境に対して次のように実行します。

```sh
npm run env:cli -- plugin list
```

## 多言語化

```sh
npm run i18n:pot
npm run i18n:mo
```

翻訳の作成方法は [languages/README.md](languages/README.md) を参照してください。翻訳生成コマンドには起動済みの wp-env が必要です。

翻訳の読み込み時期は [WordPress の多言語化ガイド](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)、生成コマンドは [WP-CLI i18n](https://developer.wordpress.org/cli/commands/i18n/) に沿っています。

## 開発依存関係の確認結果

2026年10月8日の `npm audit` では、wp-env の依存関係に9件（critical 3件・moderate 6件）の指摘が残っています。対象は開発ツールで、プラグイン本体が実行時に読み込む依存関係ではありません。依存関係の強制的な差し替えは行っていません。
