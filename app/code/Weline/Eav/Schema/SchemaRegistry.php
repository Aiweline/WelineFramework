<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Eav\Schema;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * EAV表注册中心 (OCP - 开放封闭原则)
 * 
 * 统一管理EAV模块的所有表结构，支持批量创建和依赖排序。
 * 新表只需实现SchemaInterface并注册，无需修改此类。
 */
class SchemaRegistry
{
    /**
     * @var array<string, SchemaInterface>
     */
    private array $schemas = [];

    /**
     * @var array<string, bool> 已排序的Schema类名
     */
    private array $sortedSchemas = [];

    /**
     * 注册Schema
     */
    public function register(SchemaInterface $schema): self
    {
        $this->schemas[$schema->getTableName()] = $schema;
        $this->sortedSchemas = []; // 清除排序缓存
        return $this;
    }

    /**
     * 批量注册Schema类名
     * 
     * @param array<class-string<SchemaInterface>> $schemaClasses
     */
    public function registerClasses(array $schemaClasses): self
    {
        foreach ($schemaClasses as $schemaClass) {
            $schema = ObjectManager::getInstance($schemaClass);
            $this->register($schema);
        }
        return $this;
    }

    /**
     * 获取所有已注册的Schema
     * 
     * @return array<string, SchemaInterface>
     */
    public function getAll(): array
    {
        return $this->schemas;
    }

    /**
     * 按依赖顺序获取Schema列表
     * 
     * @return SchemaInterface[]
     */
    public function getSortedSchemas(): array
    {
        if (empty($this->sortedSchemas)) {
            $this->sortByDependencies();
        }

        $result = [];
        foreach ($this->sortedSchemas as $tableName => $sorted) {
            if (isset($this->schemas[$tableName])) {
                $result[] = $this->schemas[$tableName];
            }
        }
        return $result;
    }

    /**
     * 按依赖关系排序
     */
    private function sortByDependencies(): void
    {
        $this->sortedSchemas = [];
        $visited = [];

        foreach ($this->schemas as $tableName => $schema) {
            $this->visitSchema($tableName, $schema, $visited);
        }
    }

    /**
     * 深度优先遍历排序
     */
    private function visitSchema(string $tableName, SchemaInterface $schema, array &$visited): void
    {
        if (isset($visited[$tableName])) {
            return;
        }

        $visited[$tableName] = true;

        // 先处理依赖
        foreach ($schema->getDependencies() as $dependencyClass) {
            $dependency = ObjectManager::getInstance($dependencyClass);
            $depTableName = $dependency->getTableName();
            if (isset($this->schemas[$depTableName])) {
                $this->visitSchema($depTableName, $this->schemas[$depTableName], $visited);
            }
        }

        $this->sortedSchemas[$tableName] = true;
    }

    /**
     * 创建所有表
     */
    public function createAllTables(ModelSetup $setup): void
    {
        $sortedSchemas = $this->getSortedSchemas();

        foreach ($sortedSchemas as $schema) {
            $this->createTable($setup, $schema);
        }
    }

    /**
     * 创建单个表
     */
    public function createTable(ModelSetup $setup, SchemaInterface $schema): void
    {
        $tableName = $schema->getTableName();
        
        // 检查表是否存在
        $connection = $setup->getModel()->getConnection();
        $connector = $connection->getConnector();
        $fullTableName = $connection->getConfigProvider()->getPrefix() . $tableName;
        
        if ($this->tableExists($connector, $fullTableName)) {
            $this->ensureColumns($connector, $fullTableName, $schema);
            $this->ensureIndexes($connector, $fullTableName, $schema);
            $this->insertInitialData($setup, $schema);
            return;
        }

        // 创建表
        $tableBuilder = $setup->createTable($schema->getTableComment(), $tableName);

        // 添加列
        foreach ($schema->getColumns() as $columnName => $columnDef) {
            $tableBuilder->addColumn(
                $columnName,
                $columnDef['type'],
                $columnDef['length'],
                $columnDef['options'],
                $columnDef['comment']
            );
        }

        // 添加索引
        foreach ($schema->getIndexes() as $indexName => $indexDef) {
            $tableBuilder->addIndex(
                $indexDef['type'],
                $indexName,
                $indexDef['columns'],
                $indexDef['comment'] ?? ''
            );
        }

        // 添加外键
        foreach ($schema->getForeignKeys() as $fkName => $fkDef) {
            $refTable = $connection->getConfigProvider()->getPrefix() . $fkDef['reference_table'];
            $tableBuilder->addForeignKey(
                $fkName,
                $fkDef['column'],
                $refTable,
                $fkDef['reference_column'],
                $fkDef['on_delete'] ?? false,
                $fkDef['on_update'] ?? false
            );
        }

        // 执行创建
        $tableBuilder->create();

        // 插入初始数据
        $this->insertInitialData($setup, $schema);
    }

    private function ensureColumns(ConnectorInterface $connector, string $tableName, SchemaInterface $schema): void
    {
        $existingColumns = $this->getExistingColumnMap($connector, $tableName);
        $missingColumns = [];
        foreach ($schema->getColumns() as $columnName => $columnDef) {
            $normalized = $this->normalizeIdentifier($columnName);
            if (isset($existingColumns[$normalized])) {
                continue;
            }
            $missingColumns[$columnName] = $columnDef;
        }
        if ($missingColumns === []) {
            return;
        }

        $alter = $connector->alterTable()->forTable($tableName, $this->resolvePrimaryKey($schema));
        $afterColumn = '';
        foreach ($schema->getColumns() as $columnName => $columnDef) {
            if (!isset($missingColumns[$columnName])) {
                $afterColumn = $columnName;
                continue;
            }

            $alter->addColumn(
                $columnName,
                $afterColumn,
                $columnDef['type'],
                $columnDef['length'] ?? 0,
                $this->normalizeAlterColumnOptions($connector, $columnDef['options'] ?? ''),
                $columnDef['comment'] ?? ''
            );
            $afterColumn = $columnName;
        }
        $alter->alter();
    }

    private function normalizeAlterColumnOptions(ConnectorInterface $connector, string $options): string
    {
        $dbType = method_exists($connector, 'getConfigProvider')
            ? strtolower((string)$connector->getConfigProvider()->getDbType())
            : '';
        if ($dbType !== 'pgsql') {
            return $options;
        }

        $hasAutoIncrement = str_contains(strtolower($options), 'auto_increment');
        $options = preg_replace('/\bnot\s+null\b/i', '', $options);
        $options = preg_replace('/\bunique\b/i', '', (string)$options);
        if (!$hasAutoIncrement) {
            return trim((string)$options);
        }

        $options = preg_replace('/\bauto_increment\b/i', '', (string)$options);
        $options = preg_replace('/\bprimary\s+key\b/i', '', (string)$options);
        $options = trim((string)$options);

        return trim('GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY ' . $options);
    }

    private function ensureIndexes(ConnectorInterface $connector, string $tableName, SchemaInterface $schema): void
    {
        foreach ($schema->getIndexes() as $indexName => $indexDef) {
            if ($this->indexExists($connector, $tableName, $indexName, $indexDef)) {
                continue;
            }

            $columns = $indexDef['columns'] ?? [];
            if (is_string($columns)) {
                $columns = array_map('trim', explode(',', $columns));
            }
            if ($columns === []) {
                continue;
            }

            $sql = $connector->buildAddIndexSql($tableName, [
                'name' => $indexName,
                'columns' => array_values($columns),
                'type' => $indexDef['type'] ?? TableInterface::index_type_KEY,
                'method' => $indexDef['method'] ?? 'BTREE',
            ]);
            if ($sql === '') {
                continue;
            }

            try {
                $connector->query($sql)->fetch();
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "EAV schema index creation failed table={$tableName} index={$indexName}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function getExistingColumnMap(ConnectorInterface $connector, string $tableName): array
    {
        $existingColumns = [];
        foreach ($connector->getTableColumns($tableName) as $column) {
            $name = $column['name'] ?? $column['column_name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $existingColumns[$this->normalizeIdentifier($name)] = true;
        }

        return $existingColumns;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return strtolower(trim(str_replace(['`', '"'], '', $identifier)));
    }

    private function resolvePrimaryKey(SchemaInterface $schema): string
    {
        foreach ($schema->getColumns() as $columnName => $columnDef) {
            if (str_contains(strtolower((string)($columnDef['options'] ?? '')), 'primary key')) {
                return $columnName;
            }
        }

        return (string)array_key_first($schema->getColumns());
    }

    private function indexExists(ConnectorInterface $connector, string $tableName, string $indexName, array $indexDef): bool
    {
        if ($connector->hasIndex($tableName, $indexName)) {
            return true;
        }

        $declaredColumns = $indexDef['columns'] ?? [];
        if (is_string($declaredColumns)) {
            $declaredColumns = array_map('trim', explode(',', $declaredColumns));
        }
        $declaredColumns = array_values(array_map(
            static fn(string $column): string => trim(str_replace(['`', '"'], '', $column)),
            $declaredColumns
        ));
        $declaredUnique = strtoupper((string)($indexDef['type'] ?? '')) === TableInterface::index_type_UNIQUE;

        foreach ($connector->getTableIndexes($tableName) as $actualIndex) {
            $actualColumns = array_values(array_map(
                static fn(string $column): string => trim(str_replace(['`', '"'], '', $column)),
                $actualIndex['columns'] ?? []
            ));
            if ($actualColumns === $declaredColumns && (bool)($actualIndex['unique'] ?? false) === $declaredUnique) {
                return true;
            }
        }

        return false;
    }

    /**
     * 插入初始数据
     */
    private function insertInitialData(ModelSetup $setup, SchemaInterface $schema): void
    {
        $initialData = $schema->getInitialData();
        if (empty($initialData)) {
            return;
        }

        $tableName = $schema->getTableName();
        $uniqueKey = $schema->getUniqueKey();
        
        $connection = $setup->getModel()->getConnection();
        $fullTableName = $connection->getConfigProvider()->getPrefix() . $tableName;

        // 逐条插入数据，避免批量插入时的参数绑定问题
        foreach ($initialData as $row) {
            if ($this->initialDataRowExists($connection, $fullTableName, $uniqueKey, $row)) {
                continue;
            }

            try {
                $this->insertInitialDataRow($connection, $fullTableName, $uniqueKey, $row);
            } catch (\Exception $e) {
                // 如果是唯一键冲突则忽略
                if (strpos($e->getMessage(), 'duplicate key') === false 
                    && strpos($e->getMessage(), 'Duplicate entry') === false
                    && strpos($e->getMessage(), 'UNIQUE constraint') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 检查表是否存在
     */
    private function initialDataRowExists($connection, string $tableName, string|array $uniqueKey, array $row): bool
    {
        $uniqueFields = is_array($uniqueKey) ? $uniqueKey : [$uniqueKey];
        $uniqueFields = array_values(array_filter($uniqueFields, static fn($field) => is_string($field) && $field !== ''));
        if (empty($uniqueFields)) {
            return false;
        }

        $connector = $connection->getConnector();
        if ($this->isPgsqlConnector($connector)) {
            return $this->initialDataRowExistsByPdo($connector, $tableName, $uniqueFields, $row);
        }

        $query = $connection->getQuery();
        $query->table($tableName);

        foreach ($uniqueFields as $field) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            $query->where($field, $row[$field]);
        }

        try {
            return !empty($query->limit(1)->select('1')->fetch());
        } catch (\Exception) {
            return false;
        }
    }

    private function initialDataRowExistsByPdo(ConnectorInterface $connector, string $tableName, array $uniqueFields, array $row): bool
    {
        $conditions = [];
        $parameters = [];
        foreach ($uniqueFields as $field) {
            if (!array_key_exists($field, $row)) {
                return false;
            }

            if ($row[$field] === null) {
                $conditions[] = $connector->quoteIdentifier($field) . ' IS NULL';
                continue;
            }

            $conditions[] = $connector->quoteIdentifier($field) . ' = ?';
            $parameters[] = $row[$field];
        }

        if (empty($conditions)) {
            return false;
        }

        try {
            $sql = 'SELECT 1 FROM ' . $connector->quoteTable($tableName) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1';
            $statement = $connector->getWrappedConnection()->prepare($sql);
            $statement->execute($parameters);
            return (bool)$statement->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }
    }

    private function insertInitialDataRow($connection, string $tableName, string|array $uniqueKey, array $row): void
    {
        $connector = $connection->getConnector();
        if ($this->isPgsqlConnector($connector)) {
            $columns = array_keys($row);
            if (empty($columns)) {
                return;
            }

            $quotedColumns = array_map(
                static fn(string $column): string => $connector->quoteIdentifier($column),
                $columns
            );
            $placeholders = array_fill(0, count($columns), '?');
            $sql = 'INSERT INTO ' . $connector->quoteTable($tableName)
                . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

            $statement = $connector->getWrappedConnection()->prepare($sql);
            $statement->execute(array_values($row));
            return;
        }

        $query = $connection->getQuery();
        $query->table($tableName)
            ->insert([$row], $uniqueKey)
            ->fetch();
    }

    private function isPgsqlConnector(ConnectorInterface $connector): bool
    {
        return strtolower((string)$connector->getConfigProvider()->getDbType()) === 'pgsql';
    }

    private function tableExists(ConnectorInterface $connector, string $tableName): bool
    {
        try {
            return $connector->tableExist($tableName);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 删除所有表（反向顺序）
     */
    public function dropAllTables(ModelSetup $setup): void
    {
        $sortedSchemas = array_reverse($this->getSortedSchemas());

        foreach ($sortedSchemas as $schema) {
            $this->dropTable($setup, $schema);
        }
    }

    /**
     * 删除单个表
     */
    public function dropTable(ModelSetup $setup, SchemaInterface $schema): void
    {
        $tableName = $schema->getTableName();
        $connection = $setup->getModel()->getConnection();
        $fullTableName = $connection->getConfigProvider()->getPrefix() . $tableName;

        if (!$this->tableExists($connection->getConnector(), $fullTableName)) {
            return;
        }

        $dbType = $connection->getConfigProvider()->getDbType();
        $query = $connection->getQuery();

        if ($dbType === 'pgsql') {
            $query->query("DROP TABLE IF EXISTS \"{$fullTableName}\" CASCADE")->fetch();
        } else {
            $query->query("SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS `{$fullTableName}`; SET FOREIGN_KEY_CHECKS = 1;")->fetch();
        }
    }

    /**
     * 获取所有EAV模块的Schema类
     * 
     * @return array<class-string<SchemaInterface>>
     */
    public static function getDefaultSchemas(): array
    {
        return [
            EavEntitySchema::class,
            EavAttributeTypeSchema::class,
            EavAttributeSetSchema::class,
            EavAttributeGroupSchema::class,
            EavAttributeSchema::class,
            EavAttributeOptionSchema::class,
        ];
    }
}
