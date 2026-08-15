<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Database\Service\BackupService;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalViewChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
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
        $identity = $this->physicalIdentity($database, $table);
        return $this->atomicHighRiskChange(
            $identity,
            'add_column',
            function (ConnectorInterface $connector) use ($identity, $column, $definition): int {
                self::assertPhysicalConnector($connector);
                $sql = 'ALTER TABLE ' . $connector->quotePhysicalTable($identity)
                    . ' ADD COLUMN ' . $connector->quoteIdentifier($column) . ' ' . trim($definition);
                return $this->executeConnectorStatement($connector, $sql);
            },
        );
    }

    public function modifyColumn(string $database, string $table, string $column, string $definition): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($column, 'column');
        $identity = $this->physicalIdentity($database, $table);
        return $this->atomicHighRiskChange(
            $identity,
            'modify_column',
            function (ConnectorInterface $connector) use ($identity, $column, $definition): int {
                self::assertPhysicalConnector($connector);
                $qt = $connector->quotePhysicalTable($identity);
                $qc = $connector->quoteIdentifier($column);
                $def = trim($definition);
                if ($this->dbType() === 'pgsql' || $this->dbType() === 'sqlite') {
                    $sql = 'ALTER TABLE ' . $qt . ' ALTER COLUMN ' . $qc . ' TYPE ' . $def;
                } else {
                    $sql = 'ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . $qc . ' ' . $def;
                }
                return $this->executeConnectorStatement($connector, $sql);
            },
        );
    }

    public function dropColumn(string $database, string $table, string $column): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($column, 'column');
        $identity = $this->physicalIdentity($database, $table);
        return $this->atomicHighRiskChange(
            $identity,
            'drop_column',
            function (ConnectorInterface $connector) use ($identity, $column): int {
                self::assertPhysicalConnector($connector);
                $sql = $connector->buildAlterDropColumnSql(
                    $connector->quotePhysicalTable($identity),
                    $column,
                );
                return $this->executeConnectorStatement($connector, $sql);
            },
        );
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
        $identity = $this->physicalIdentity($database, $table);
        $connector = $this->physicalConnector();
        $sql = $connector->buildAddIndexSql($connector->quotePhysicalTable($identity), [
            'name' => $indexName,
            'columns' => $columns,
            'type' => $unique ? 'UNIQUE' : 'INDEX',
        ]);
        return $this->executeStatement($sql);
    }

    public function dropIndex(string $database, string $table, string $indexName): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $this->validateIdentifier($indexName, 'indexName');
        $identity = $this->physicalIdentity($database, $table);
        return $this->atomicHighRiskChange(
            $identity,
            'drop_index',
            function (ConnectorInterface $connector) use ($identity, $indexName): int {
                self::assertPhysicalConnector($connector);
                $sql = $connector->buildDropIndexSql(
                    $connector->quotePhysicalTable($identity),
                    $indexName,
                );
                return $this->executeConnectorStatements($connector, $sql);
            },
        );
    }

    public function createOrReplaceView(string $database, string $viewName, string $selectSql): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($viewName, 'viewName');
        if (!preg_match('/^\s*SELECT\s+/i', $selectSql)) {
            throw new \InvalidArgumentException((string) __('视图语句必须以 SELECT 开头'));
        }

        $identity = $this->physicalViewIdentity($database, $viewName);
        return $this->atomicViewChange(
            $identity,
            'replace_view',
            static function (
                PhysicalViewMetadataInterface $connector,
                bool $existed,
            ) use ($identity, $selectSql): int {
                $connector->createOrReplacePhysicalView($identity, trim($selectSql), $existed);
                return 0;
            },
        );
    }

    public function dropView(string $database, string $viewName): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($viewName, 'viewName');
        $identity = $this->physicalViewIdentity($database, $viewName);
        return $this->atomicViewChange(
            $identity,
            'drop_view',
            static function (
                PhysicalViewMetadataInterface $connector,
                bool $existed,
            ) use ($identity): int {
                if ($existed) {
                    $connector->dropPhysicalViewIfExists($identity);
                }
                return 0;
            },
        );
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
            $total += $this->executeStatement($stmt);
        }
        return $total;
    }

    private function executeStatement(string $sql): int
    {
        $result = $this->connectionFactory->query($sql)->fetch();
        if ($result === false) {
            throw new \RuntimeException((string) __('数据库结构操作执行失败'));
        }
        return is_int($result) ? $result : 0;
    }

    private function executeConnectorStatement(ConnectorInterface $connector, string $sql): int
    {
        $result = $connector->query($sql)->fetch();
        if ($result === false) {
            throw new \RuntimeException((string) __('数据库结构操作执行失败'));
        }
        return is_int($result) ? $result : 0;
    }

    private function executeConnectorStatements(ConnectorInterface $connector, string $sql): int
    {
        $sql = trim($sql);
        if ($sql === '') {
            return 0;
        }
        $total = 0;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $total += $this->executeConnectorStatement($connector, $statement);
        }
        return $total;
    }

    /** @param callable(ConnectorInterface): int $ddl */
    private function atomicHighRiskChange(
        PhysicalTableIdentity $identity,
        string $action,
        callable $ddl,
    ): int
    {
        $connector = $this->physicalConnector();
        if (!$connector instanceof AtomicPhysicalTableChangeInterface) {
            throw new \RuntimeException('atomic physical table change capability unavailable');
        }

        return $connector->atomicPhysicalTableChange(
            $identity,
            function (ConnectorInterface $lockedConnector) use ($connector, $identity, $action, $ddl): int {
                if ($lockedConnector !== $connector) {
                    throw new \RuntimeException('atomic physical table connector changed during callback');
                }
                self::assertPhysicalConnector($lockedConnector);
                $this->backupBeforeHighRisk($identity, $lockedConnector, $action);
                return $ddl($lockedConnector);
            },
        );
    }

    private function backupBeforeHighRisk(
        PhysicalTableIdentity $identity,
        ConnectorInterface $connector,
        string $action,
    ): void
    {
        $migrationId = $this->backupService->beginPhysicalBackupOperation(
            $identity,
            $action,
            $connector,
        );
        $this->backupService->smartBackupPhysicalTable(
            $identity,
            $migrationId,
            physicalConnector: $connector,
        );
    }

    private function physicalIdentity(string $database, string $table): PhysicalTableIdentity
    {
        if ($this->dbType() === 'sqlite') {
            return new PhysicalTableIdentity('main', $table);
        }
        return new PhysicalTableIdentity($database, $table);
    }

    private function physicalViewIdentity(string $database, string $view): PhysicalViewIdentity
    {
        if ($this->dbType() === 'sqlite') {
            return new PhysicalViewIdentity('main', $view);
        }
        return new PhysicalViewIdentity($database, $view);
    }

    /** @param callable(PhysicalViewMetadataInterface, bool): int $ddl */
    private function atomicViewChange(
        PhysicalViewIdentity $identity,
        string $action,
        callable $ddl,
    ): int {
        $connector = $this->connector();
        if (!$connector instanceof PhysicalViewMetadataInterface) {
            throw new \RuntimeException('exact physical view capability unavailable');
        }
        if (!$connector instanceof AtomicPhysicalViewChangeInterface) {
            throw new \RuntimeException('atomic physical view change capability unavailable');
        }

        return $connector->atomicPhysicalViewChange(
            $identity,
            function (ConnectorInterface $locked, bool $existed) use (
                $connector,
                $identity,
                $action,
                $ddl,
            ): int {
                if ($locked !== $connector || !$locked instanceof PhysicalViewMetadataInterface) {
                    throw new \RuntimeException('atomic physical view connector changed during callback');
                }
                $migrationId = $this->backupService->beginPhysicalViewBackupOperation(
                    $identity,
                    $action,
                    $locked,
                );
                $this->backupService->backupPhysicalViewDefinition(
                    $identity,
                    $migrationId,
                    physicalConnector: $locked,
                );
                return $ddl($locked, $existed);
            },
        );
    }

    /** @return ConnectorInterface&PhysicalTableMetadataInterface */
    private function physicalConnector(): ConnectorInterface
    {
        $connector = $this->connector();
        if (!$connector instanceof PhysicalTableMetadataInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
        return $connector;
    }

    private static function assertPhysicalConnector(ConnectorInterface $connector): void
    {
        if (!$connector instanceof PhysicalTableMetadataInterface) {
            throw new \RuntimeException('exact physical table capability unavailable');
        }
    }

    private function connector(): ConnectorInterface
    {
        return $this->connectionFactory->getConnector();
    }

    private function dbType(): string
    {
        return strtolower($this->connectionFactory->getConfigProvider()->getDbType());
    }

    private function validateIdentifier(string $identifier, string $field): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException((string) __('非法标识符 %{1}: %{2}', [$field, $identifier]));
        }
    }
}
