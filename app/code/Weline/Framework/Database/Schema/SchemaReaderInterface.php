<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * 读库表结构契约；{@see DbSchemaReader} 为默认实现。
 */
interface SchemaReaderInterface
{
    public function readTable(ConnectorInterface $connector, string $tableName): ?TableSchema;

    /**
     * @param list<string> $tableNames
     * @return array<string, TableSchema|null>
     */
    public function readTablesBatch(ConnectorInterface $connector, array $tableNames): array;
}
