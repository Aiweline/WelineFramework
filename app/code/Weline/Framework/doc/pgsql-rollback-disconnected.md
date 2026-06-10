# PgSQL rollback disconnected recovery

## Background

In WLS mode, PostgreSQL connections can be reused across requests. When a query fails, the PgSQL adapter calls `rollBack()` before rethrowing the original `PDOException` so an aborted transaction does not leak into later operations.

## Failure mode

If the PostgreSQL server connection is already gone, PDO can throw `SQLSTATE[HY000]: General error: 7 no connection to the server` from the rollback cleanup itself. That secondary exception can replace the original query failure in the runtime stack.

## Fix

`Weline\Framework\Database\Connection\Adapter\Pgsql\Query::rollBack()` now treats transaction probing and rollback execution as cleanup for expected cleanup-only failures: missing active transaction and disconnected PostgreSQL server errors. Unexpected rollback failures are still rethrown.

## Verification

Run the focused regression test from the runtime workspace:

```powershell
E:\WelineFramework\DEV-workspace\vendor\bin\phpunit.bat --bootstrap app\bootstrap_phpunit.php --no-coverage --testdox app\code\Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql\PgsqlQueryRollbackDisconnectedTest.php
```
