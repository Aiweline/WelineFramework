<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql;

use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;

/**
 * Declarative TableSchema → createTable() orchestration shared by connectors.
 * Dialect-specific PRIMARY KEY / AUTO_INCREMENT rules live in adapter overrides.
 */
trait CreatesTableFromSchemaTrait
{
    /**
     * @param array{
     *   comment?: string,
     *   columns?: list<array{name:string,type?:string,length?:int|string|null,nullable?:bool,primaryKey?:bool,autoIncrement?:bool,default?:mixed,comment?:string,unique?:bool}>,
     *   indexes?: list<array{name:string,columns:list<string>,type?:string,method?:string,comment?:string}>,
     *   foreignKeys?: list<array{name:string,columns:list<string>,referencesTable:string,referencesColumns:list<string>,onDeleteCascade?:bool,onUpdateCascade?:bool}>
     * } $schema
     */
    public function createTableFromSchema(string $tableName, array $schema): void
    {
        $comment = (string)($schema['comment'] ?? '');
        /** @var list<array<string,mixed>> $columns */
        $columns = array_values($schema['columns'] ?? []);
        /** @var list<array<string,mixed>> $indexes */
        $indexes = array_values($schema['indexes'] ?? []);
        /** @var list<array<string,mixed>> $foreignKeys */
        $foreignKeys = array_values($schema['foreignKeys'] ?? []);

        $pkColumns = [];
        $autoIncrementPkColumns = [];
        foreach ($columns as $col) {
            if (!empty($col['primaryKey'])) {
                $name = (string)($col['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $pkColumns[] = $name;
                if (!empty($col['autoIncrement'])) {
                    $autoIncrementPkColumns[] = $name;
                }
            }
        }
        $hasCompositePk = count($pkColumns) > 1;

        /** @var CreateInterface $create */
        $create = $this->createTable();
        $create->createTable($tableName, $comment);

        foreach ($columns as $col) {
            $name = (string)($col['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $create->addColumn(
                $name,
                (string)($col['type'] ?? 'text'),
                $col['length'] ?? null,
                $this->buildCreateSchemaColumnOptions($col, $hasCompositePk),
                (string)($col['comment'] ?? ''),
            );
        }

        if ($hasCompositePk) {
            $pkColumns = $this->orderCompositePrimaryKeyColumns($pkColumns, $autoIncrementPkColumns, $indexes);
            $quoted = array_map(fn(string $column): string => $this->quoteIdentifier($column), $pkColumns);
            $create->addConstraints('PRIMARY KEY (' . implode(', ', $quoted) . ')');
        }

        foreach ($indexes as $idx) {
            $create->addIndex(
                (string)($idx['type'] ?? 'INDEX'),
                (string)($idx['name'] ?? ''),
                array_values($idx['columns'] ?? []),
                (string)($idx['comment'] ?? ''),
                (string)($idx['method'] ?? ''),
            );
        }

        foreach ($foreignKeys as $fk) {
            $create->addForeignKey(
                (string)($fk['name'] ?? ''),
                implode(',', array_values($fk['columns'] ?? [])),
                $this->formatTableName((string)($fk['referencesTable'] ?? '')),
                implode(',', array_values($fk['referencesColumns'] ?? [])),
                !empty($fk['onDeleteCascade']),
                !empty($fk['onUpdateCascade']),
            );
        }

        $create->addAdditional($this->getDefaultTableAdditional());
        // Declarative schemas must converge to exactly the declared columns.
        // Legacy create() callers keep the historical implicit timestamp columns.
        $create->create(false);
    }

    /** @param array<string,mixed> $col */
    protected function buildCreateSchemaColumnOptions(array $col, bool $hasCompositePk): string
    {
        $opts = [];
        if (!empty($col['primaryKey']) && !$hasCompositePk) {
            $opts[] = 'PRIMARY KEY';
        }
        if (!empty($col['autoIncrement'])) {
            $opts[] = 'AUTO_INCREMENT';
        }
        if (empty($col['nullable']) && empty($col['primaryKey'])) {
            $opts[] = 'NOT NULL';
        }
        if (array_key_exists('default', $col) && $col['default'] !== null) {
            $type = strtolower((string)($col['type'] ?? ''));
            $dbType = '';
            try {
                $dbType = strtolower((string)$this->getConfigProvider()->getDbType());
            } catch (\Throwable) {
                $dbType = '';
            }
            // MySQL/MariaDB：TEXT/BLOB/JSON/GEOMETRY 不允许（非 NULL）DEFAULT。
            $forbidDefault = $dbType === 'mysql'
                && preg_match('/\b(text|blob|json|geometry|point|linestring|polygon)\b/', $type) === 1;
            if (!$forbidDefault) {
                $default = $col['default'];
                if (is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP') {
                    $opts[] = 'DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_string($default)) {
                    $opts[] = "DEFAULT '" . str_replace("'", "''", $default) . "'";
                } else {
                    $opts[] = 'DEFAULT ' . $default;
                }
            }
        }
        if (!empty($col['unique']) && empty($col['primaryKey'])) {
            $opts[] = 'UNIQUE';
        }

        return implode(' ', $opts);
    }

    /**
     * @param list<string> $pkColumns
     * @param list<string> $autoIncrementPkColumns
     * @param list<array<string,mixed>> $indexes
     * @return list<string>
     */
    protected function orderCompositePrimaryKeyColumns(
        array $pkColumns,
        array $autoIncrementPkColumns,
        array $indexes,
    ): array {
        return $pkColumns;
    }
}
