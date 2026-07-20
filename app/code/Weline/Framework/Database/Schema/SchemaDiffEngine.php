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
    public function diff(TableSchema $declared, ?TableSchema $actual, ?string $databaseType = null): array
    {
        $ops = [];
        $tableName = $declared->tableName;
        $modelClass = $declared->modelClass;

        if ($actual === null) {
            $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_CREATE_TABLE, $tableName, $declared, $modelClass);
            return $ops;
        }

        $declaredCols = $this->columnsByKey($declared->columns);
        $actualCols = $this->columnsByKey($actual->columns);

        foreach ($declared->columns as $col) {
            if (!isset($actualCols[$col->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_COLUMN, $tableName, $col, $modelClass);
            } else {
                $existing = $actualCols[$col->name];
                if (!$this->columnEquals($col, $existing, $databaseType)
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
        $actualColNames = array_fill_keys(array_map(fn (ColumnDefinition $c) => $c->name, $actual->columns), true);
        foreach ($declared->indexes as $idx) {
            if (!isset($actualIndexes[$idx->name])) {
                $allColsExist = true;
                foreach ($idx->columns as $col) {
                    if (!isset($actualColNames[$col])) {
                        $allColsExist = false;
                        break;
                    }
                }
                if ($allColsExist) {
                    $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_ADD_INDEX, $tableName, $idx, $modelClass);
                }
            }
        }
        foreach ($actual->indexes as $idx) {
            if (!isset($declaredIndexes[$idx->name])) {
                $ops[] = new SchemaDiffOp(SchemaDiffOp::KIND_DROP_INDEX, $tableName, $idx, $modelClass);
            }
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
            $out[$i->name] = $i;
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

    private function columnEquals(ColumnDefinition $a, ColumnDefinition $b, ?string $databaseType = null): bool
    {
        return $a->name === $b->name
            && $this->columnTypeCompatible($a, $b, $databaseType)
            && $this->normalizeLength($a->type, $a->length) === $this->normalizeLength($b->type, $b->length)
            && $a->nullable === $b->nullable
            && $a->primaryKey === $b->primaryKey
            && $a->autoIncrement === $b->autoIncrement
            && $this->columnUniqueCompatible($a, $b)
            && $this->columnCommentCompatible($a, $b)
            && (string) ($a->default ?? '') === (string) ($b->default ?? '');
    }

    private function columnTypeCompatible(
        ColumnDefinition $declared,
        ColumnDefinition $actual,
        ?string $databaseType,
    ): bool {
        $declaredType = $this->normalizeType($declared->type);
        $actualType = $this->normalizeType($actual->type);
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

    /** timestamp/datetime/date 间变更易触发 PostgreSQL USING/UPDATE 转换错误，跳过兼容的 MODIFY */
    private function columnUniqueCompatible(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        // Physical adapters expose uniqueness as an index/constraint and may
        // also mirror it onto the column. Index comparison below is the
        // authoritative source; comparing the mirrored flag here would create
        // a permanent MODIFY COLUMN loop for separately declared unique indexes.
        return true;
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

    private function normalizeLength(string $type, int|string|null $length): string
    {
        if (in_array($this->normalizeType($type), ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            return '';
        }
        $value = trim((string) ($length ?? ''));
        return $value === '0' ? '' : $value;
    }
}
