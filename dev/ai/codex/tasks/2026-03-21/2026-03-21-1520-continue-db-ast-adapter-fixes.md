# Task Log

- Started: 2026-03-21 15:20
- Updated: 2026-03-21 19:17
- Status: completed
- Request: 继续完成 `dev/ai/plans/codex-db-ast-adapter-fixes.plan.md`

## Scope

- 收尾数据库 AST、compiler、adapter 修复计划
- 补回归测试与验证记录
- 更新计划状态并产出独立提交

## Completed Work

- 修复 `QueryAst` 在 `fromSubquery()`、`alias()`、`buildAst()` 路径中对 FROM 子查询状态的覆盖问题，保证 `table=''` 时仍保留 `is_subquery` 与 `subquery_id`。
- 扩展 `AbstractCompiler`，支持递归编译 FROM / WHERE 子查询，并将子查询绑定参数以 `:sq_<prefix>_...` 方式重命名后合并到父查询。
- 修复 `find_in_set` 方言输出：
  - MySQL: `FIND_IN_SET(...) > 0`
  - PgSQL: `POSITION(',' || ... ) > 0`
  - SQLite: `INSTR(',' || ... ) > 0`
- 修复 `MysqlCompiler::buildInsert()` 对 `QueryInterface::EXIST_UPDATE_ALL_FIELDS` 的展开逻辑，并避免 insert 目标表使用 alias。
- 将 MySQL / PgSQL / SQLite adapter 的 `prepareSql()` 主路径统一到 compiler，允许 `fromSubquery()` 场景直接编译并准备 statement。
- 将 SQLite 不支持的 `ALTER TABLE COMMENT`、`ADD FOREIGN KEY`、`DROP FOREIGN KEY` 改为显式抛出 `DbException`。
- 新增 `DatabaseAstCompilerRegressionTest`，覆盖子查询 AST 保留、方言 `find_in_set`、MySQL upsert 展开、adapter `fromSubquery()`、SQLite unsupported DDL 等回归点。

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
  - 说明：PHPUnit 仍报告 `No code coverage driver available` 与已有 deprecation，导致进程退出码非 0，但没有断言失败

## Changed Files

- `app/code/Weline/Framework/Database/Connection/Api/Sql/QueryAst.php`
- `app/code/Weline/Framework/Database/Compiler/AbstractCompiler.php`
- `app/code/Weline/Framework/Database/Compiler/MysqlCompiler.php`
- `app/code/Weline/Framework/Database/Connection/Adapter/Mysql/Query.php`
- `app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php`
- `app/code/Weline/Framework/Database/Connection/Adapter/Sqlite/Query.php`
- `app/code/Weline/Framework/Database/Connection/Adapter/Sqlite/Connector.php`
- `app/code/Weline/Framework/Database/test/Unit/DatabaseAstCompilerRegressionTest.php`
- `dev/ai/plans/codex-db-ast-adapter-fixes.plan.md`
- `dev/ai/codex/ACTIVE.md`

## Notes

- 工作区存在大量与本任务无关的脏改动，提交时需要严格限定文件范围。
- `Sqlite/Connector.php` 仍带有历史编码噪音注释，但本次功能修复集中在 unsupported DDL 行为，不继续扩大改动面。
- adapter 中仍保留部分未引用的 legacy helper 方法；当前 `prepareSql()` 已统一走 compiler 主链，功能上不再依赖旧分支。
