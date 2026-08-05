# 事务协调与 afterCommit

## 适用范围

`TransactionCoordinatorInterface` 是 Framework 默认主库上的逻辑事务边界。它把嵌套 `begin/commit/rollback`、rollback-only、savepoint 和提交后回调绑定到同一个 Query owner 与同一 PDO object id。

本契约不接管业务代码直接开启的 PDO 事务，也不会把不同 DSN/不同物理连接伪装成一个原子事务。检测到未受管 PDO 事务、同配置第二个 PDO 或 owner 变更时会拒绝继续，必要时将当前事务标记为 rollback-only。

SQLite、MySQL 和 PostgreSQL Query adapter 都遵循这一契约。每次发布仍须在目标驱动上重复验证嵌套回滚、savepoint、物理提交/回滚失败与 callback 次数，不能用单一驱动结果替代三库门禁。

MySQL/PostgreSQL 的锁定读由 AST 编译为 `ORDER BY`、`LIMIT/OFFSET` 之后的 `FOR UPDATE`，不能生成 `FOR UPDATE LIMIT ...`。AST 的 null 条件必须保留调用方显式的 `IS NULL` / `IS NOT NULL`，且两者都不生成绑定参数。

## 公开接口

```php
interface TransactionCoordinatorInterface
{
    public function run(ConnectionFactory $connection, callable $callback): mixed;
    public function isActive(ConnectionFactory $connection): bool;
    public function markRollbackOnly(ConnectionFactory $connection, ?Throwable $cause = null): void;
    public function afterCommit(ConnectionFactory $connection, string $key, callable $callback): void;
    public function afterRollback(ConnectionFactory $connection, string $key, callable $callback): void;
    public function withSavepoint(ConnectionFactory $connection, string $purpose, callable $callback): mixed;
}

interface WriteIntentTransactionCoordinatorInterface extends TransactionCoordinatorInterface
{
    public function runWrite(ConnectionFactory $connection, callable $callback): mixed;
    public function isWriteIntent(ConnectionFactory $connection): bool;
}
```

`run()` 始终使用当前逻辑连接已登记的 owner Query；回调中的 Model 保存会通过 `TransactionContext` 复用它，不应手工 clone connector 或自建 PDO。共享 Query 的 Model owner 以对象弱引用判定，不能只保存 `spl_object_id()`：对象销毁后 PHP 可以复用旧编号。Model owner 切换时必须清空完整 Query runtime state，包括 upsert conflict 字段、AST、绑定值、批处理与 PDOStatement，避免上一模型的表或冲突字段污染下一次保存。

`WriteIntentTransactionCoordinatorInterface` 是加法式能力，不改变第三方对基础 `TransactionCoordinatorInterface` 的实现兼容性。其 `runWrite()` 用于必须在任何读取前取得 writer reservation 的根事务。SQLite 以受框架 busy deadline 管理的 `BEGIN IMMEDIATE` 实现；普通 `run()` 仍是 deferred，长只读事务不会抢占全库写锁。MySQL/PostgreSQL 的物理 begin 不变，但状态仍记录 write-intent。活动 deferred 事务不能中途升级；调用方必须从根边界选择 `runWrite()`，`isWriteIntent()` 可供要求该前置的服务 fail-fast。

```php
$connection = $model->getConnection();

$transactions->run($connection, function () use ($model, $connection, $transactions): void {
    $model->save();

    $transactions->afterCommit(
        $connection,
        'catalog_product_runtime_refresh',
        static function (): void {
            // 此处才可执行文件、进程快照、HTTP 或 IPC 副作用。
        },
    );
});
```

## 状态机

| 当前状态 | 操作 | 实际结果 |
|---|---|---|
| IDLE | begin | 登记 owner Query/PDO，执行物理 begin，depth=1 |
| ACTIVE | nested begin | 校验 owner/PDO，只使 depth+1 |
| ACTIVE, depth>1 | commit | 只使 depth-1，不物理提交 |
| ACTIVE, depth=1 | commit | 物理提交，先脱离事务上下文，再按登记顺序执行 afterCommit |
| ACTIVE, depth>1 | rollback | depth-1 并置 rollback-only，不物理回滚 |
| ACTIVE, depth=1 | rollback | 物理回滚，丢弃 afterCommit，执行 afterRollback |
| rollback-only | outer commit | 物理回滚并抛 `RollbackOnlyException` |
| physical commit 失败 | commit | 尽力回滚，执行 afterRollback，重抛提交异常 |
| physical commit 因断线失败 | commit | 将死 PDO 标记为不健康并丢弃该逻辑 owner 的全部 lease；afterRollback 在脱离状态后执行，后续访问重新取得健康连接 |
| rollback-only 的物理回滚因断线失败 | commit | 保留最初 rollback-only 业务 cause，记录物理回滚错误，丢弃死 PDO 后执行 afterRollback |
| physical rollback 因断线失败 | rollback | 脱离事务状态、丢弃死 PDO、执行 afterRollback，最后重抛原物理回滚异常 |
| 请求/Fiber 结束仍 ACTIVE | cleanup | 记录泄漏诊断并强制物理回滚 |

## afterCommit 与 afterRollback

- 活动事务内，回调按 `key` 去重，首次登记生效。
- 无活动事务时，`afterCommit()` 会立即执行；如果 PDO 其实处于未受管事务，则拒绝执行。
- 无活动事务时调用 `afterRollback()` 会抛 `TransactionStateException`。
- 物理提交后的某个 afterCommit 回调失败，只记录 `transaction_after_commit_callback_failed` 与白名单 `error_code`，后续回调仍继续。数据已提交，不会因此回滚或重放已执行回调。
- afterRollback 回调在事务状态脱离后执行；不应用它继续当前事务写入。
- MySQL `server has gone away`、PostgreSQL 连接终止等物理断线不得把带有过期 `inTransaction()` 状态的 PDO 归还连接池。协调器先丢弃 owner 连接，再执行回滚回调；回调若重新访问数据库，必须拿到新 PDO。
- rollback-only 的物理回滚如果同时失败，`RollbackOnlyException::getPrevious()` 优先保留最初导致 rollback-only 的业务异常；物理回滚失败只在没有原 cause 时作为 previous，并始终进入受控诊断日志。
- `AbstractModel::save()` 捕获写入异常后，只在 `TransactionContext` 仍登记当前 owner 时执行二次 rollback。若 commit/rollback failure 路径已经 detach/discard，Model 必须跳过 rollback，避免关闭后的 Connector 为异常收尾重新租一条 PDO；对外包装异常的 previous 始终保留最初业务失败。

## isolated Observer 与 savepoint

`withSavepoint()` 只能在 ACTIVE 事务内使用。`failure="isolated"` 的同步 Observer 通过 `ObserverExecutionPolicy` 进入 savepoint：

- Observer 成功：释放 savepoint，外层事务继续。
- Observer 失败：`ROLLBACK TO SAVEPOINT` 后恢复进入前的 depth、rollback-only 和 callback 快照，记录 `event_observer_isolated_failed`，不向调用方重抛。
- savepoint 建立、回滚或释放失败：整个事务置 rollback-only 并上抛。
- savepoint 回调不能直接 commit/rollback 外层事务，也不能更换 owner。

## 副作用分类

| 操作 | 位置 |
|---|---|
| 与业务主库同连接的 Model/SQL、revision、Outbox、namespace generation | 事务内 |
| 缓存进程快照、文件、HTTP、IPC、WLS publish、relay kick | afterCommit |
| 事务内临时状态清理 | afterRollback |

不得在物理提交前执行无法随 DB 回滚的副作用。对可靠异步事件，Outbox 必须与业务数据处于 Framework 默认主库的同一受管事务；其他连接不是可靠 Outbox 边界。

## 运维检查

1. 先执行 `php bin/w framework:compile` 确认 Provider/QueryProvider 索引可编译。
2. Schema 变更用 `php bin/w setup:upgrade --route`，不直接编辑 generated 文件。
3. 分别在 SQLite、MySQL、PostgreSQL 上验证嵌套 commit/rollback、rollback-only、savepoint 以及 afterCommit/afterRollback 各一次；未执行不得标注为“三库通过”。

## 实现入口

- `Database/Transaction/TransactionCoordinatorInterface.php`
- `Database/Transaction/TransactionCoordinator.php`
- `Database/Transaction/TransactionState.php`
- `Database/TransactionContext.php`
- `Database/AbstractModel.php`
