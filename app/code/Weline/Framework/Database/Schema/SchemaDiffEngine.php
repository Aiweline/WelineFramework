<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 比较声明式 TableSchema 与库表实际 TableSchema，产出 SchemaDiffOp 列表。
 */
final class SchemaDiffEngine
{
    /**
     * @return list<SchemaDiffOp>
     */
    public function diff(
        TableSchema $declared,
        ?TableSchema $actual,
        ?string $databaseType = null,
        ?callable $indexPhysicalIdentity = null,
    ): array {
        $ops = [];
        $tableName = $declared->tableName;
        $modelClass = $declared->modelClass;
        IndexDefinitionContract::assertDeclaredNames($declared->indexes, $indexPhysicalIdentity);

        if ($actual === null) {
            $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_CREATE_TABLE, $tableName, $declared, $modelClass);
            return $ops;
        }

        $declaredCols = $this->columnsByKey($declared->columns);
        $actualCols = $this->columnsByKey($actual->columns);
        $sqliteCompositePrimaryKey = $databaseType === 'sqlite'
            && count(array_filter(
                $declared->columns,
                static fn(ColumnDefinition $column): bool => $column->primaryKey,
            )) > 1;

        foreach ($declared->columns as $col) {
            if (!isset($actualCols[$col->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_COLUMN, $tableName, $col, $modelClass);
            } else {
                $existing = $actualCols[$col->name];
                if (!$this->columnEquals($col, $existing, $databaseType, $sqliteCompositePrimaryKey)
                    && !$this->skipTimestampCompatibleModify($col, $existing)) {
                    $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_MODIFY_COLUMN, $tableName, $col, $modelClass, $existing);
                }
            }
        }
        foreach ($actual->columns as $col) {
            if (!isset($declaredCols[$col->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_COLUMN, $tableName, $col, $modelClass);
            }
        }

        $declaredIndexes = $this->indexesByKey($declared->indexes);
        $actualIndexes = $this->indexesByKey($actual->indexes);
        foreach ($declaredIndexes as $name => $declaredIndex) {
            if (isset($actualIndexes[$name])
                && !IndexDefinitionContract::equals($declaredIndex, $actualIndexes[$name], $databaseType)) {
                throw new \RuntimeException(__(
                    '表 %{1} 的索引 %{2} 物理定义与 Schema 声明不一致',
                    [$tableName, $declaredIndex->name],
                ));
            }
        }
        // Index DDL is ordered after ADD_COLUMN by SchemaMigrationExecutor.  A
        // column declared in this target schema is therefore available to an
        // index in the same migration batch even when it is absent from the
        // pre-migration physical snapshot.  Undeclared/misspelled columns stay
        // excluded because they are not present in this declared set.
        $declaredColNames = array_fill_keys(array_map(fn (ColumnDefinition $c) => $c->name, $declared->columns), true);
        $explicitUniqueColumns = IndexDefinitionContract::explicitSingleUniqueColumnMap($declared->indexes);
        $reservedImplicitIndexNames = IndexDefinitionContract::reservedIdentities(
            $indexPhysicalIdentity,
            $declared->indexes,
            $actual->indexes,
        );
        $implicitUniqueActualNames = [];
        foreach ($declared->columns as $col) {
            if (!$col->unique || $col->primaryKey || isset($explicitUniqueColumns[$col->name])) {
                continue;
            }
            $matched = false;
            foreach ($actual->indexes as $actualIndex) {
                if ($actualIndex->type !== 'UNIQUE' || $actualIndex->columns !== [$col->name]) {
                    continue;
                }
                $implicitUniqueActualNames[strtolower($actualIndex->name)] = true;
                $matched = true;
                break;
            }
            if (!$matched
                && $databaseType === 'sqlite'
                && isset($actualCols[$col->name])
                && $actualCols[$col->name]->unique
            ) {
                // SQLite constraint-owned UNIQUE indexes are anonymous and
                // cannot be dropped independently. Connector column metadata
                // exposes their exact single-column semantic ownership.
                $matched = true;
            }
            if (!$matched) {
                $implicitName = IndexDefinitionContract::resolveImplicitName(
                    $tableName,
                    $col->name,
                    $reservedImplicitIndexNames,
                    $indexPhysicalIdentity,
                );
                $reservedImplicitIndexNames[strtolower($implicitName)] = true;
                if ($indexPhysicalIdentity !== null) {
                    $reservedImplicitIndexNames[strtolower($indexPhysicalIdentity($implicitName))] = true;
                }
                $ops[] = new SchemaDiffOp(
                    SchemaDiffOp::KIND_ADD_INDEX,
                    $tableName,
                    new IndexDefinition(
                        name: $implicitName,
                        columns: [$col->name],
                        type: 'UNIQUE',
                        comment: $col->comment,
                    ),
                    $modelClass,
                );
            }
        }
        foreach ($declared->indexes as $idx) {
            if (!isset($actualIndexes[strtolower($idx->name)])) {
                $allColsExist = true;
                foreach ($idx->columns as $col) {
                    if (!isset($declaredColNames[$col])) {
                        $allColsExist = false;
                        break;
                    }
                }
                if ($allColsExist) {
                    $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_INDEX, $tableName, $idx, $modelClass);
                }
            }
        }
        $matchedActualIndexNames = [];
        foreach ($actual->indexes as $idx) {
            $indexKey = strtolower($idx->name);
            if (isset($implicitUniqueActualNames[$indexKey])) {
                continue;
            }
            if (!isset($declaredIndexes[$indexKey])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_INDEX, $tableName, $idx, $modelClass);
                continue;
            }
            if ($databaseType === 'pgsql' && isset($matchedActualIndexNames[$indexKey])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_INDEX, $tableName, $idx, $modelClass);
                continue;
            }
            $matchedActualIndexNames[$indexKey] = true;
        }

        $declaredFks = $this->fksByKey($declared->foreignKeys);
        $actualFks = $this->fksByKey($actual->foreignKeys);
        foreach ($actual->foreignKeys as $fk) {
            if (!isset($declaredFks[$fk->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_FOREIGN_KEY, $tableName, $fk, $modelClass);
            }
        }
        foreach ($declared->foreignKeys as $fk) {
            if (!isset($actualFks[$fk->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_FOREIGN_KEY, $tableName, $fk, $modelClass);
            }
        }

        $declaredComment = (string) $declared->comment;
        $actualComment = (string) $actual->comment;
        // SQLite does not persist table comments. Treating an empty physical
        // comment as drift makes every setup run emit the same no-op DDL and
        // prevents a target-code read-only validation from ever reaching zero.
        if ($databaseType !== 'sqlite' && $declaredComment !== $actualComment) {
            $ops[] = new SchemaDiffOp(
                SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
                $tableName,
                $declaredComment,
                $modelClass,
                $actualComment
            );
        }

        return $ops;
    }

    /** @return array<string, ColumnDefinition> */
    private function columnsByKey(array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[$c->name] = $c;
        }
        return $out;
    }

    /** @return array<string, IndexDefinition> */
    private function indexesByKey(array $indexes): array
    {
        $out = [];
        foreach ($indexes as $i) {
            $out[strtolower($i->name)] = $i;
        }
        return $out;
    }

    /** @return array<string, ForeignKeyDefinition> */
    private function fksByKey(array $fks): array
    {
        $out = [];
        foreach ($fks as $f) {
            $out[$f->name] = $f;
        }
        return $out;
    }

    private function columnEquals(
        ColumnDefinition $a,
        ColumnDefinition $b,
        ?string $databaseType = null,
        bool $sqliteCompositePrimaryKey = false,
    ): bool {
        return $a->name === $b->name
            && $this->columnTypeCompatible($a, $b, $databaseType)
            && $this->normalizeLength($a->type, $a->length, $databaseType)
                === $this->normalizeLength($b->type, $b->length, $databaseType)
            && $a->nullable === $b->nullable
            && $a->primaryKey === $b->primaryKey
            && $this->columnAutoIncrementCompatible($a, $b, $databaseType, $sqliteCompositePrimaryKey)
            && $this->columnUniqueCompatible($a, $b)
            && $this->columnCommentCompatible($a, $b)
            && $this->columnDefaultCompatible($a, $b, $databaseType);
    }

    private function columnTypeCompatible(
        ColumnDefinition $declared,
        ColumnDefinition $actual,
        ?string $databaseType,
    ): bool {
        $declaredType = $this->normalizeType($declared->type);
        $actualType = $this->normalizeType($actual->type);
        if (\in_array($databaseType, ['pgsql', 'postgres', 'postgresql'], true)) {
            $declaredType = $this->normalizePgsqlType($declaredType);
            $actualType = $this->normalizePgsqlType($actualType);
        }
        if ($declaredType === $actualType) {
            return true;
        }
        if ($databaseType !== 'sqlite'
            || !$declared->primaryKey
            || !$declared->autoIncrement
            || !$actual->primaryKey
            || !$actual->autoIncrement) {
            return false;
        }

        // SQLite 的自增 rowid 别名只接受精确的 INTEGER PRIMARY KEY。
        // bigint/smallint 是声明侧语义，物理层必须收敛为 INTEGER。
        $integerFamily = ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'];
        return in_array($declaredType, $integerFamily, true)
            && in_array($actualType, $integerFamily, true);
    }

    private function columnDefaultCompatible(
        ColumnDefinition $declared,
        ColumnDefinition $actual,
        ?string $databaseType,
    ): bool {
        if ($declared->autoIncrement && $actual->autoIncrement) {
            // PostgreSQL reports the backing sequence expression while the
            // declaration describes AUTO_INCREMENT semantically.
            return true;
        }

        $expected = (string)($declared->default ?? '');
        $observed = (string)($actual->default ?? '');
        if ($expected === $observed) {
            return true;
        }
        $isPgsql = \in_array($databaseType, ['pgsql', 'postgres', 'postgresql'], true);
        if ($isPgsql) {
            $observed = $this->unwrapPgsqlLiteralDefault($observed);
            if ($expected === $observed) {
                return true;
            }
        }
        if ($this->numericDefaultCompatible($declared, $actual, $expected, $observed)) {
            return true;
        }
        if (!$isPgsql) {
            return false;
        }

        // information_schema exposes PostgreSQL literal defaults with their
        // storage cast, for example ''::character varying. The cast is not a
        // semantic schema difference from the portable declaration.
        if (\preg_match(
            "/^'(.*)'::(?:character varying|text)$/Ds",
            $observed,
            $matches,
        ) === 1) {
            $observed = \str_replace("''", "'", $matches[1]);
        }
        return $expected === $observed;
    }

    private function unwrapPgsqlLiteralDefault(string $observed): string
    {
        if (preg_match(
            "/^'(.*)'::(?:character varying|character|bpchar|text|numeric|decimal|real|double precision|smallint|integer|bigint)$/Ds",
            $observed,
            $matches,
        ) === 1) {
            return str_replace("''", "'", $matches[1]);
        }
        if (preg_match(
            '/^\(?([+-]?\d+(?:\.\d+)?)\)?::(?:numeric|decimal|real|double precision|smallint|integer|bigint)$/D',
            $observed,
            $matches,
        ) === 1) {
            return $matches[1];
        }
        return $observed;
    }

    private function numericDefaultCompatible(
        ColumnDefinition $declared,
        ColumnDefinition $actual,
        string $expected,
        string $observed,
    ): bool {
        $numericTypes = [
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'bigint',
            'decimal',
            'numeric',
            'float',
            'double',
            'real',
        ];
        if (!in_array($this->normalizeType($declared->type), $numericTypes, true)
            || !in_array($this->normalizeType($actual->type), $numericTypes, true)) {
            return false;
        }

        $expected = $this->normalizeNumericLiteral($expected);
        $observed = $this->normalizeNumericLiteral($observed);
        return $expected !== null && $expected === $observed;
    }

    private function normalizeNumericLiteral(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim((string)($matches[3] ?? ''), '0');
        $normalized = $integer . ($fraction === '' ? '' : '.' . $fraction);
        if (($matches[1] ?? '') === '-' && $normalized !== '0') {
            $normalized = '-' . $normalized;
        }

        return $normalized;
    }

    /** timestamp/datetime/date 间变更易触发 PostgreSQL USING/UPDATE 转换错误，跳过兼容的 MODIFY */
    private function columnUniqueCompatible(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        // Physical adapters expose uniqueness as an index/constraint and may
        // also mirror it onto the column. Index comparison below is the
        // authoritative source; comparing the mirrored flag here would create
        // a permanent MODIFY COLUMN loop for separately declared unique indexes.
        return true;
    }

    private function columnAutoIncrementCompatible(
        ColumnDefinition $declared,
        ColumnDefinition $actual,
        ?string $databaseType,
        bool $sqliteCompositePrimaryKey,
    ): bool {
        if ($databaseType === 'sqlite'
            && $sqliteCompositePrimaryKey
            && $declared->primaryKey
            && $declared->autoIncrement
            && !$actual->autoIncrement) {
            // SQLite only permits AUTOINCREMENT on one exact INTEGER PRIMARY
            // KEY column.  The CREATE adapter intentionally suppresses it for
            // a composite primary key while retaining the portable declaration.
            return true;
        }
        return $declared->autoIncrement === $actual->autoIncrement;
    }

    private function columnCommentCompatible(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        return $actual->comment === '' || (string) $declared->comment === (string) $actual->comment;
    }

    private function skipTimestampCompatibleModify(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        $tsTypes = ['timestamp', 'datetime', 'timestamptz', 'date', 'timestamp with time zone', 'timestamp without time zone'];
        $declaredNorm = $this->normalizeType($declared->type);
        $actualType = strtolower($actual->type);
        if (!in_array($actualType, $tsTypes, true)) {
            return false;
        }
        return $declaredNorm === 'timestamp' || $declaredNorm === 'date';
    }

    private function normalizeType(string $type): string
    {
        $t = strtolower($type);
        $map = [
            'integer' => 'int',
            'int' => 'int',
            'bigint' => 'bigint',
            'smallint' => 'smallint',
            'tinyint' => 'tinyint',
            'mediumint' => 'mediumint',
            'datetime' => 'timestamp',
            'timestamptz' => 'timestamp',
            'date' => 'date',
            'timestamp with time zone' => 'timestamp',
            'timestamp without time zone' => 'timestamp',
        ];
        return $map[$t] ?? $t;
    }

    private function normalizePgsqlType(string $type): string
    {
        return match ($type) {
            'decimal', 'numeric' => 'numeric',
            'tinyint', 'smallint' => 'smallint',
            'mediumint', 'int', 'year' => 'int',
            'float', 'real' => 'real',
            'double', 'double precision' => 'double',
            'char', 'character' => 'char',
            'varchar', 'character varying' => 'varchar',
            'tinytext', 'mediumtext', 'longtext', 'text' => 'text',
            'tinyblob', 'mediumblob', 'longblob', 'blob', 'bytea' => 'bytea',
            'json', 'jsonb' => 'jsonb',
            'bool', 'boolean' => 'boolean',
            default => $type,
        };
    }

    private function normalizeLength(string $type, int|string|null $length, ?string $databaseType = null): string
    {
        $normalizedType = $this->normalizeType($type);
        if (\in_array($databaseType, ['pgsql', 'postgres', 'postgresql'], true)) {
            $normalizedType = $this->normalizePgsqlType($normalizedType);
            if (\in_array($normalizedType, [
                'int',
                'bigint',
                'smallint',
                'real',
                'double',
                'text',
                'bytea',
                'jsonb',
                'boolean',
                'timestamp',
                'date',
                'time',
            ], true)) {
                return '';
            }
        }
        if (in_array($normalizedType, [
            'int',
            'bigint',
            'smallint',
            'tinyint',
            'mediumint',
            'tinytext',
            'text',
            'mediumtext',
            'longtext',
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
        ], true)) {
            return '';
        }
        $value = trim((string) ($length ?? ''));
        if (\in_array($normalizedType, ['decimal', 'numeric'], true)
            && preg_match('/^(\d+)(?:\s*,\s*(\d+))?$/D', $value, $matches) === 1) {
            return (int)$matches[1] . ',' . (int)($matches[2] ?? 0);
        }
        return $value === '0' ? '' : $value;
    }

}
