<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Schema;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * Contract for applying schema diffs / bootstrap tables, supplied by the
 * database module's release pipeline.
 */
interface SchemaDiffExecutorInterface
{
    /**
     * @param list<array<string, mixed>> $ops
     * @param array<string, mixed> $context
     */
    public function execute(ConnectorInterface $connector, array $ops, array $context = []): void;

    public function createBootstrapTable(ConnectorInterface $connector, TableSchema $schema): void;
}
