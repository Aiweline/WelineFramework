<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutorInterface;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * RSSE 的 Schema DDL 执行器：只执行 DDL，不写旧 checkpoint / backup。
 *
 * 所有连接由调用方显式传入（bootstrap/index/ssl/shard），不依赖 factory 单例。
 */
final class SchemaDiffExecutor implements SchemaMigrationExecutorInterface
{
    public function __construct(private readonly SchemaDdlExecutor $ddlExecutor)
    {
    }

    public function execute(ConnectorInterface $connector, array $ops, array $context = []): void
    {
        foreach ($ops as $op) {
            if (!$op instanceof SchemaDiffOp) {
                throw new InvalidArgumentException('SchemaDiffExecutor 只接受 SchemaDiffOp 列表');
            }
            $this->ddlExecutor->apply($op, false, $connector);
        }
    }

    public function createBootstrapTable(ConnectorInterface $connector, TableSchema $schema): void
    {
        $connector->createTableFromSchema(
            $schema->tableName,
            $this->ddlExecutor->tableSchemaPayload($schema),
        );
    }
}
