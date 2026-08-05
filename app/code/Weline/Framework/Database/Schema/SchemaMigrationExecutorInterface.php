<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * Schema DDL 执行契约；{@see SchemaMigrationExecutor} 为默认实现。
 */
interface SchemaMigrationExecutorInterface
{
    /**
     * @param list<SchemaDiffOp> $ops
     * @param array<string, mixed> $context
     */
    public function execute(ConnectorInterface $connector, array $ops, array $context = []): void;
}
