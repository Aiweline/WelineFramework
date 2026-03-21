# Active Task

- Updated: 2026-03-21 19:17
- Task File: `dev/ai/codex/tasks/2026-03-21/2026-03-21-1520-continue-db-ast-adapter-fixes.md`
- Status: completed

## Current Goal

完成数据库 AST、compiler、adapter 修复计划收尾，补齐回归测试、验证记录、计划状态与提交。

## Latest Progress

- 已完成 `QueryAst` 子查询 FROM/alias 状态保持修复，避免 `buildAst()` 和 `alias()` 抹掉子查询 AST。
- 已完成 compiler 主链修复：递归编译 FROM/WHERE 子查询、合并并重命名子查询绑定、按方言输出 `find_in_set`，并避免 insert 目标表带 alias。
- 已完成 MySQL/PgSQL/SQLite adapter `prepareSql()` 收口到 compiler 主链；SQLite 不支持的表注释/外键 DDL 现在显式抛 `DbException`。
- 已新增 `DatabaseAstCompilerRegressionTest` 回归测试，并完成针对本组改动的 lint 与 PHPUnit 验证。

## Verification

- `php -l app/code/Weline/Framework/Database/Connection/Api/Sql/QueryAst.php`
- `php -l app/code/Weline/Framework/Database/Compiler/AbstractCompiler.php`
- `php -l app/code/Weline/Framework/Database/Compiler/MysqlCompiler.php`
- `php -l app/code/Weline/Framework/Database/Connection/Adapter/Mysql/Query.php`
- `php -l app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php`
- `php -l app/code/Weline/Framework/Database/Connection/Adapter/Sqlite/Query.php`
- `php -l app/code/Weline/Framework/Database/Connection/Adapter/Sqlite/Connector.php`
- `php vendor/bin/phpunit app/code/Weline/Framework/Database/test/Unit/DatabaseAstCompilerRegressionTest.php`
  - 结果：11 tests / 32 assertions / 0 failures
  - 说明：runner 仍因 `No code coverage driver available` 与现有 PHPUnit deprecation 以非零退出，不是断言失败

## Next

- 如需继续做纯代码清理，可再移除 adapter 内未引用的 legacy helper 路径；当前计划对应的功能修复与回归覆盖已完成。
