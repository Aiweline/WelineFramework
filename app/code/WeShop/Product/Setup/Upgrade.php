<?php

declare(strict_types=1);

namespace WeShop\Product\Setup;

use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Model\ProductLayoutSchedule;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $dbSetup = $setup->getDbSetup();
        $connection = $dbSetup->getConnector();
        $layoutTable = $dbSetup->getTable(ProductLayout::schema_table);
        $scheduleTable = $dbSetup->getTable(ProductLayoutSchedule::schema_table);

        $this->ensureEntityTypeDefault($connection, $layoutTable);
        $this->ensureEntityTypeDefault($connection, $scheduleTable);

        $this->dropIndexIfExists($connection, 'idx_unique_product_layout');
        $this->createIndexIfMissing(
            $connection,
            $layoutTable,
            'idx_unique_product_layout',
            ['entity_type', 'product_id', 'layout_type'],
            true
        );
        $this->createIndexIfMissing(
            $connection,
            $layoutTable,
            'idx_weshop_product_layout_entity_type',
            ['entity_type']
        );
        $this->createIndexIfMissing(
            $connection,
            $scheduleTable,
            'idx_weshop_product_layout_schedule_entity_type',
            ['entity_type']
        );
        $this->createIndexIfMissing(
            $connection,
            $scheduleTable,
            'idx_weshop_product_layout_schedule_entity_lookup',
            ['entity_type', 'product_id', 'layout_type', 'status']
        );
    }

    private function ensureEntityTypeDefault(ConnectorInterface $connection, string $table): void
    {
        $this->executeSql(
            $connection,
            'UPDATE ' . $this->quoteIdent($table)
            . " SET entity_type = '" . ProductLayout::ENTITY_PRODUCT . "'"
            . " WHERE entity_type IS NULL OR entity_type = ''"
        );
    }

    private function dropIndexIfExists(ConnectorInterface $connection, string $indexName): void
    {
        if (!$this->indexExists($connection, $indexName)) {
            return;
        }

        $this->executeSql($connection, 'DROP INDEX IF EXISTS ' . $this->quoteIdent($indexName));
    }

    /**
     * @param list<string> $columns
     */
    private function createIndexIfMissing(
        ConnectorInterface $connection,
        string $table,
        string $indexName,
        array $columns,
        bool $unique = false
    ): void {
        if ($this->indexExists($connection, $indexName)) {
            return;
        }

        $columnSql = implode(', ', array_map([$this, 'quoteIdent'], $columns));
        $this->executeSql(
            $connection,
            'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX '
            . $this->quoteIdent($indexName)
            . ' ON ' . $this->quoteIdent($table)
            . ' (' . $columnSql . ')'
        );
    }

    private function indexExists(ConnectorInterface $connection, string $indexName): bool
    {
        $rows = $connection->query(
            "SELECT 1 AS found FROM pg_indexes WHERE schemaname = current_schema()"
            . " AND indexname = '" . $this->escapeLiteral($indexName) . "' LIMIT 1"
        )->fetchArray();

        return $rows !== [];
    }

    private function executeSql(ConnectorInterface $connection, string $sql): void
    {
        $connection->query($sql)->fetch();
    }

    private function quoteIdent(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function escapeLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
