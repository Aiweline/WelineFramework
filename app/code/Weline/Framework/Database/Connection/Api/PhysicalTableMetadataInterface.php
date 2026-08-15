<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional exact-physical catalog capability; intentionally not part of ConnectorInterface. */
interface PhysicalTableMetadataInterface
{
    public function quotePhysicalTable(PhysicalTableIdentity $identity): string;

    public function physicalTableExists(PhysicalTableIdentity $identity): bool;

    public function getPhysicalCreateTableSql(PhysicalTableIdentity $identity): string;

    /** @return list<array<string, mixed>> */
    public function getPhysicalTableColumns(PhysicalTableIdentity $identity): array;

    public function dropPhysicalTableIfExists(PhysicalTableIdentity $identity): void;
}
