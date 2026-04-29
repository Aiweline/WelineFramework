<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Database\Service\BackupService;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;

class SchemaAdminService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly BackupService $backupService
    ) {
    }

    public function addColumn(string $database, string $table, string $column, string $definition): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($column, 'column');
        $this->backupBeforeHighRisk($table);
        $c = $this->connector();
        $qt = $c->quoteTable($this->qualifiedTable($database, $table));
        $sql = 'ALTER TABLE ' . $qt . ' ADD COLUMN ' . $c->quoteIdentifier($column) . ' ' . trim($definition);
        return $this->connectionFactory->query($sql)->execute();
    }

    public function modifyColumn(string $database, string $table, string $column, string $definition): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($column, 'column');
        $this->backupBeforeHighRisk($table);
        $c = $this->connector();
        $qt = $c->quoteTable($this->qualifiedTable($database, $table));
        $qc = $c->quoteIdentifier($column);
        $def = trim($definition);
        if ($this->dbType() === 'pgsql') {
            $sql = 'ALTER TABLE ' . $qt . ' ALTER COLUMN ' . $qc . ' TYPE ' . $def;
        } elseif ($this->dbType() === 'sqlite') {
            $sql = 'ALTER TABLE ' . $qt . ' ALTER COLUMN ' . $qc . ' TYPE ' . $def;
        } else {
            $sql = 'ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . $qc . ' ' . $def;
        }
        return $this->connectionFactory->query($sql)->execute();
    }

    public function dropColumn(string $database, string $table, string $column): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($column, 'column');
        $this->backupBeforeHighRisk($table);
        $sql = $this->connector()->buildAlterDropColumnSql($this->qualifiedTable($database, $table), $column);
        return $this->connectionFactory->query($sql)->execute();
    }

    public function addIndex(string $database, string $table, string $indexName, array $columns, bool $unique = false): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($indexName, 'indexName');
        if ($columns === []) {
            throw new \InvalidArgumentException((string) __('索引字段不能为空'));
        }
        foreach ($columns as $column) {
            $this->validateIdentifier((string) $column, 'indexColumn');
        }
        $sql = $this->connector()->buildAddIndexSql($this->qualifiedTable($database, $table), [
            'name' => $indexName,
            'columns' => $columns,
            'type' => $unique ? 'UNIQUE' : 'INDEX',
        ]);
        return $this->connectionFactory->query($sql)->execute();
    }

    public function dropIndex(string $database, string $table, string $indexName): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($indexName, 'indexName');
        $sql = $this->connector()->buildDropIndexSql($this->qualifiedTable($database, $table), $indexName);
        return $this->executeStatements($sql);
    }

    public function createOrReplaceView(string $database, string $viewName, string $selectSql): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($viewName, 'viewName');
        if (!preg_match('/^\s*SELECT\s+/i', $selectSql)) {
            throw new \InvalidArgumentException((string) __('视图语句必须以 SELECT 开头'));
        }

        $qv = $this->connector()->quoteTable($this->qualifiedTable($database, $viewName));
        $sql = 'CREATE OR REPLACE VIEW ' . $qv . ' AS ' . trim($selectSql);
        return $this->connectionFactory->query($sql)->execute();
    }

    public function dropView(string $database, string $viewName): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($viewName, 'viewName');
        $qv = $this->connector()->quoteTable($this->qualifiedTable($database, $viewName));
        $sql = 'DROP VIEW IF EXISTS ' . $qv;
        return $this->connectionFactory->query($sql)->execute();
    }

    private function executeStatements(string $sql): int
    {
        $sql = trim($sql);
        if ($sql === '') {
            return 0;
        }
        $segments = array_map('trim', explode(';', $sql));
        $segments = array_filter($segments, static fn(string $s): bool => $s !== '');
        if ($segments === []) {
            return 0;
        }
        $total = 0;
        foreach ($segments as $stmt) {
            $total += $this->connectionFactory->query($stmt)->execute();
        }
        return $total;
    }

    private function backupBeforeHighRisk(string $table): void
    {
        $migrationId = time();
        $this->backupService->smartBackupTable($table, $migrationId, true);
    }

    private function qualifiedTable(string $database, string $table): string
    {
        if ($this->dbType() === 'sqlite') {
            return $table;
        }
        return $database . '.' . $table;
    }

    private function connector(): ConnectorInterface
    {
        return $this->connectionFactory->getConnector();
    }

    private function dbType(): string
    {
        return strtolower($this->connector()->getConfigProvider()->getDbType());
    }

    private function validateIdentifier(string $identifier, string $field): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException((string) __('非法标识符 %{1}: %{2}', [$field, $identifier]));
        }
    }
}
