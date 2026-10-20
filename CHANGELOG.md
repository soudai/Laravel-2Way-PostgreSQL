# 変更履歴

## 未リリース — 2026-10-20

- 接続検証、トランザクション・savepoint、例外変換、実行診断の処理を整理しました。公開API・SQL文法・例外コード・返却件数の契約は維持しています。
- 実行API・DAO生成・PHPStanが、クエリ種別とDTO対応の共通定義を参照するようにしました。既存の生成PHP・manifest形式とハッシュの契約は維持しています。
- 公開ガイドをパッケージ名 `soudai/laravel-2way-postgresql` に統一し、PostgreSQLの検証環境、reader / writerの選択、reconnect時の確認と更新手順を追加しました。

動作要件・依存条件とMITライセンスに変更はありません。PostgreSQL 19は19rc1で検証済みで、正式版の検証は未完了です。

## 公開用パッケージの初期配置 — 2026-10-16

- LaravelでComposerから利用するためのソース・設定・PHPStan拡張を配置しました。
- 日本語HTMLガイドを追加し、GitHub Pagesの公開元を `main` の `/docs` に統一しました。
- MITライセンスを採用しました。
