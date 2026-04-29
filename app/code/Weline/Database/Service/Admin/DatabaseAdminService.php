<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;

class DatabaseAdminService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory
    ) {
    }

    public function listDatabases(): array
    {
        return match ($this->dbType()) {
            'pgsql' => array_map(
                static fn(array $row): string => (string) ($row['n'] ?? ''),
                $this->queryRows(
                    'SELECT schema_name AS n FROM information_schema.schemata '
                    . "WHERE schema_name NOT IN ('pg_catalog', 'information_schema', 'pg_toast') "
                    . "AND schema_name NOT LIKE 'pg\\_%' ESCAPE '\\' "
                    . 'ORDER BY schema_name'
                )
            ),
            'sqlite' => array_filter([
                (string) ($this->connector()->getConfigProvider()->getDatabase() ?: 'main'),
            ]),
            default => array_map(
                static fn(array $row): string => (string) array_values($row)[0],
                $this->queryRows('SHOW DATABASES')
            ),
        };
    }

    public function listTables(string $database): array
    {
        $this->validateIdentifier($database, 'database');
        return match ($this->dbType()) {
            'pgsql' => array_map(
                static fn(array $row): string => (string) ($row['n'] ?? ''),
                $this->queryRows(
                    'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = '
                    . $this->quoteValue($database) . " AND table_type = 'BASE TABLE' ORDER BY table_name"
                )
            ),
            'sqlite' => array_map(
                static fn(array $row): string => (string) ($row['n'] ?? ''),
                $this->queryRows(
                    "SELECT name AS n FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
                )
            ),
            default => array_map(
                static fn(array $row): string => (string) array_values($row)[0],
                $this->queryRows(
                    'SHOW TABLES FROM ' . $this->connector()->quoteTable($database)
                )
            ),
        };
    }

    public function getTableMeta(string $database, string $table): array
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $connector = $this->connector();
        $qualified = $database . '.' . $table;

        if ($this->dbType() === 'pgsql') {
            $views = $this->queryRows(
                'SELECT table_name AS name FROM information_schema.views WHERE table_schema = '
                . $this->quoteValue($database) . ' ORDER BY table_name'
            );

            return [
                'columns' => $connector->getTableColumns($qualified),
                'indexes' => $connector->getTableIndexes($qualified),
                'foreign_keys' => $connector->getTableForeignKeys($qualified),
                'status' => [],
                'create_sql' => $connector->getCreateTableSql($qualified),
                'views' => $views,
            ];
        }

        if ($this->dbType() === 'sqlite') {
            $views = $this->queryRows("SELECT name AS name FROM sqlite_master WHERE type='view' ORDER BY name");

            return [
                'columns' => $connector->getTableColumns($table),
                'indexes' => $connector->getTableIndexes($table),
                'foreign_keys' => $connector->getTableForeignKeys($table),
                'status' => [],
                'create_sql' => $connector->getCreateTableSql($table),
                'views' => $views,
            ];
        }

        $qt = $connector->quoteTable($qualified);
        $columns = $this->queryRows('SHOW FULL COLUMNS FROM ' . $qt);
        $indexes = $this->queryRows('SHOW INDEX FROM ' . $qt);
        $statusRows = $this->queryRows(
            'SHOW TABLE STATUS FROM ' . $connector->quoteTable($database) . ' LIKE ' . $this->quoteValue($table)
        );
        $createRows = $this->queryRows('SHOW CREATE TABLE ' . $qt);
        $views = $this->queryRows(
            'SELECT TABLE_NAME AS name FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ' . $this->quoteValue($database)
        );

        return [
            'columns' => $columns,
            'indexes' => $indexes,
            'status' => $statusRows[0] ?? [],
            'create_sql' => $createRows[0]['Create Table'] ?? '',
            'foreign_keys' => $connector->getTableForeignKeys($qualified),
            'views' => $views,
        ];
    }

    public function getRows(
        string $database,
        string $table,
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        ?string $sortField = null,
        string $sortDirection = 'DESC'
    ): array {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $qt = $this->quotedTableForDml($database, $table);

        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $whereSql = '';
        if ($search !== null && $search !== '') {
            $columnNames = $this->listTableColumnNames($database, $table);
            $likeConds = [];
            $castTpl = in_array($this->dbType(), ['pgsql', 'sqlite'], true) ? 'CAST(%s AS TEXT)' : 'CAST(%s AS CHAR)';
            foreach ($columnNames as $field) {
                if ($field === '') {
                    continue;
                }
                $likeConds[] = sprintf(
                    $castTpl . ' LIKE %s',
                    $this->connector()->quoteIdentifier($field),
                    $this->quoteValue('%' . $search . '%')
                );
            }
            if ($likeConds) {
                $whereSql = ' WHERE ' . implode(' OR ', $likeConds);
            }
        }

        $orderSql = '';
        if ($sortField !== null && $sortField !== '') {
            $this->validateIdentifier($sortField, 'sortField');
            $direction = strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';
            $orderSql = sprintf(
                ' ORDER BY %s %s',
                $this->connector()->quoteIdentifier($sortField),
                $direction
            );
        }

        $countRow = $this->queryRows('SELECT COUNT(*) AS total FROM ' . $qt . $whereSql);
        $total = (int) ($countRow[0]['total'] ?? 0);
        $rows = $this->queryRows(
            'SELECT * FROM ' . $qt . $whereSql . $orderSql . sprintf(' LIMIT %d OFFSET %d', $pageSize, $offset)
        );

        return [
            'items' => $rows,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * @return list<string>
     */
    private function listTableColumnNames(string $database, string $table): array
    {
        $meta = $this->getTableMeta($database, $table);
        $columns = $meta['columns'] ?? [];
        $names = [];
        foreach ($columns as $col) {
            if (isset($col['name'])) {
                $names[] = (string) $col['name'];
                continue;
            }
            if (isset($col['Field'])) {
                $names[] = (string) $col['Field'];
            }
        }
        return $names;
    }

    public function insertRow(string $database, string $table, array $data): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        if ($data === []) {
            throw new \InvalidArgumentException((string) __('新增数据不能为空'));
        }

        $qt = $this->quotedTableForDml($database, $table);
        $columns = [];
        $values = [];
        foreach ($data as $field => $value) {
            $this->validateIdentifier((string) $field, 'field');
            $columns[] = $this->connector()->quoteIdentifier((string) $field);
            $values[] = $value === null ? 'NULL' : $this->quoteValue((string) $value);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $qt,
            implode(', ', $columns),
            implode(', ', $values)
        );

        return $this->connectionFactory->query($sql)->execute();
    }

    public function updateRow(string $database, string $table, array $pk, array $data): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        if ($pk === [] || $data === []) {
            throw new \InvalidArgumentException((string) __('更新操作必须提供主键与数据'));
        }

        $qt = $this->quotedTableForDml($database, $table);
        $setClauses = [];
        foreach ($data as $field => $value) {
            $this->validateIdentifier((string) $field, 'field');
            $setClauses[] = $this->connector()->quoteIdentifier((string) $field) . '=' . ($value === null ? 'NULL' : $this->quoteValue((string) $value));
        }

        $whereClauses = [];
        foreach ($pk as $field => $value) {
            $this->validateIdentifier((string) $field, 'pkField');
            $whereClauses[] = $this->connector()->quoteIdentifier((string) $field) . '=' . ($value === null ? 'NULL' : $this->quoteValue((string) $value));
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $qt,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );
        return $this->connectionFactory->query($sql)->execute();
    }

    public function deleteRows(string $database, string $table, array $conditions): int
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        if ($conditions === []) {
            throw new \InvalidArgumentException((string) __('删除操作必须提供条件'));
        }

        $qt = $this->quotedTableForDml($database, $table);
        $whereClauses = [];
        foreach ($conditions as $field => $value) {
            $this->validateIdentifier((string) $field, 'conditionField');
            $whereClauses[] = $this->connector()->quoteIdentifier((string) $field) . '=' . ($value === null ? 'NULL' : $this->quoteValue((string) $value));
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $qt,
            implode(' AND ', $whereClauses)
        );
        return $this->connectionFactory->query($sql)->execute();
    }

    public function exportCsv(string $database, string $table, int $limit = 2000): string
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $limit = max(1, min(20000, $limit));
        $qt = $this->quotedTableForDml($database, $table);
        $rows = $this->queryRows(sprintf(
            'SELECT * FROM %s LIMIT %d',
            $qt,
            $limit
        ));
        if ($rows === []) {
            return '';
        }

        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row));
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return (string) $content;
    }

    public function importCsv(string $database, string $table, string $csvContent): array
    {
        $this->validateIdentifier($database, 'database');
        $this->validateIdentifier($table, 'table');
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (!$lines || count($lines) < 2) {
            throw new \InvalidArgumentException((string) __('CSV 内容为空或格式不正确'));
        }

        $headers = str_getcsv((string) array_shift($lines));
        $inserted = 0;
        $errors = [];
        foreach ($lines as $lineNo => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            if (count($values) !== count($headers)) {
                $errors[] = __('第 %{1} 行列数不匹配', (string) ($lineNo + 2));
                continue;
            }
            try {
                $this->insertRow($database, $table, array_combine($headers, $values) ?: []);
                $inserted++;
            } catch (\Throwable $throwable) {
                $errors[] = __('第 %{1} 行导入失败: %{2}', [(string) ($lineNo + 2), $throwable->getMessage()]);
            }
        }

        return ['inserted' => $inserted, 'errors' => $errors];
    }

    private function connector(): ConnectorInterface
    {
        return $this->connectionFactory->getConnector();
    }

    /**
     * SQLite 无 database.table 限定语法，DML 仅引用表名。
     */
    private function quotedTableForDml(string $database, string $table): string
    {
        if ($this->dbType() === 'sqlite') {
            return $this->connector()->quoteTable($table);
        }
        return $this->connector()->quoteTable($database . '.' . $table);
    }

    private function dbType(): string
    {
        return strtolower($this->connector()->getConfigProvider()->getDbType());
    }

    private function queryRows(string $sql): array
    {
        return $this->connectionFactory->query($sql)->fetchArray();
    }

    private function quoteValue(string $value): string
    {
        return $this->connectionFactory
            ->getConnector()
            ->getWrappedConnection()
            ->quote($value);
    }

    private function validateIdentifier(string $identifier, string $field): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException((string) __('非法标识符 %{1}: %{2}', [$field, $identifier]));
        }
    }
}
