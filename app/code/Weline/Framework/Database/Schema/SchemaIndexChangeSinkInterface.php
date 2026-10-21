<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;

/**
 * Optional sink for schema index ADD/DROP operations.
 *
 * The framework keeps synchronous DDL as the default. A module may provide an
 * implementation (for example Weline_Database) to defer performance indexes
 * into an asynchronous job table while table/column DDL remains synchronous.
 */
interface SchemaIndexChangeSinkInterface
{
    /**
     * @param SchemaDiffOp       $op       KIND_ADD_INDEX or KIND_DROP_INDEX
     * @param list<SchemaDiffOp> $tableOps all diff ops for this table in the current batch
     * @param array<string,mixed> $context schema-diff context (module_versions, operation_id, ...)
     *
     * @return bool true when the sink took ownership (executor must skip the DDL),
     *              false when the executor must apply the DDL synchronously
     */
    public function handleIndexOp(
        ConnectorInterface $connector,
        SchemaDiffOp $op,
        array $tableOps,
        array $context,
    ): bool;
}
