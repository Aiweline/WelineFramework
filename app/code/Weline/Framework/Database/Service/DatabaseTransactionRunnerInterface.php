<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Service;

use Weline\Framework\Database\ConnectionFactory;

/**
 * 同一数据库连接上的业务 DML 事务边界。
 *
 * 禁止在回调内执行 DDL / SchemaMigrationExecutor；跨库副作用用 outbox/补偿。
 * 实现必须包装现有 {@see \Weline\Framework\Database\TransactionContext}，
 * 不得伪造不存在的 TransactionContext::run()。
 */
interface DatabaseTransactionRunnerInterface
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(ConnectionFactory $connection, callable $callback): mixed;
}
