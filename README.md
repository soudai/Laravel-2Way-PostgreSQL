# Laravel-2Way-PostgreSQL

Laravelの既存Database接続でSQLファイルを実行する、PostgreSQL専用の2-way SQLパッケージです。
SQLファイルはサンプル値のままDBクライアントでも実行でき、Laravelでは入力値をバインドして実行します。

## ドキュメント

[HTML利用ガイド](https://soudai.github.io/Laravel-2Way-PostgreSQL/)に、使い方・機能・設定オプション・API・Artisanコマンドをまとめています。
GitHub Pagesには、`main` の `docs/html/` の変更をGitHub Actionsで自動反映します。

[HTMLソース](docs/html/index.html)はリポジトリにも同梱しています。
リポジトリを取得し、`docs/html/index.html` をブラウザで開くとローカルでも閲覧できます。CSS・JavaScriptも同梱しています。

## 動作要件

- PHP 8.4 / 8.5（64bit、`pdo_pgsql`・`mbstring` 拡張）
- Laravel 12 / 13
- PostgreSQL 18 / 19（UTF8、`standard_conforming_strings=on`。19はRC1で検証済み、正式版は未検証）

## インストール

Laravelアプリのディレクトリで実行してください。GitHubをComposerのVCSリポジトリとして登録します。

```bash
composer config repositories.twoway vcs https://github.com/soudai/Laravel-2Way-PostgreSQL
composer require 'soudai/laravel-2way-postgresql:dev-main'
php artisan vendor:publish --tag=twoway-sql-config
```

ServiceProviderはLaravelのパッケージ検出で自動登録されます。
PHPの名前空間は `Hft\TwoWaySql` です。
`config/database.php` の利用する `pgsql` 接続には、次のPDOオプションを設定してください。

```php
'options' => [
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
],
```

## 最小の使用例

`resources/sql/value.sql` を作成します。

```sql
SELECT /*:value*/1::bigint AS value;
```

公開した `config/twoway-sql.php` の `queries` にSQLを登録します。

```php
'queries' => [
    'demo.value' => [
        'path' => 'value.sql',
        'kind' => 'read',
        'parameters' => ['value' => ['type' => 'int64']],
    ],
],
```

Laravelアプリから実行します。

```php
use Hft\TwoWaySql\Laravel\Facades\TwoWaySql;

$compiled = TwoWaySql::compile('demo.value', ['value' => 7]); // DB接続なし
$value = TwoWaySql::connection('pgsql')->scalar('demo.value', ['value' => 7]); // 7
```

SQLテンプレートの検査とキャッシュはArtisanから実行できます。

```bash
php artisan twoway:lint
php artisan twoway:cache
php artisan twoway:clear
```

## 実行時の契約

- `select` は配列、`one` は厳密に1行、`oneOrNull` は0または1行、`scalar` は厳密に1行1列を返します。
- 更新は `kind=write` と `execute`、RETURNING付き更新は `kind=write_returning` と `returning` を使います。既存のLaravelトランザクションに参加します。
- 宣言したパラメータはnullableでもキーが必須です。SQL内のサンプル値で入力を補完しません。空のINリストは展開時にエラーになります。
- SQL・設定は信頼できるアプリコードとして管理します。認可・テナント条件・SoftDeletes・結果件数の制限はSQLに記述してください。
- Laravelのクエリログや外部の監視ツールによるバインド値の記録は、アプリ側で制御してください。

PHPStan拡張を利用する場合は、アプリ側にPHPStan 2.1.3以降の2.xを導入し、`vendor/soudai/laravel-2way-postgresql/extension.neon` を設定に追加します。

## ライセンス

`proprietary`
