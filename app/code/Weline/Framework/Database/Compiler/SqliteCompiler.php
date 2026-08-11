<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler;

use Weline\Framework\Database\Compiler\Dialect\SqliteDialect;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Exception\DbException;

/**
 * SQLite SQL 编译器
 *
 * UPSERT 语法要求 SQLite 3.24+；RETURNING 仅在连接确认 SQLite 3.35+ 时生成。
 * @since 1.0.0
 */
final class SqliteCompiler extends AbstractCompiler
{
    public function __construct(?SqliteDialect $dialect = null)
    {
        parent::__construct($dialect ?? new SqliteDialect());
    }

    protected function buildInsert(array $ast, string $table, array $options): string
    {
        $insert = $ast['insert'] ?? [];
        $insertItems = $insert['insert'] ?? [];
        $insertOrUpdateItems = $insert['i_o_u'] ?? [];
        $identityField = $options['identity_field'] ?? 'id';
        $supportsReturning = (bool)($options['supports_returning'] ?? true);
        $existUpdateSql = $options['exist_update_sql'] ?? '';
        $insertUpdateFields = $options['insert_update_fields'] ?? [];
        $insertUpdateWhereFields = $options['insert_update_where_fields'] ?? [];

        $allItems = array_merge($insertItems, $insertOrUpdateItems);
        if (empty($allItems)) {
            return '';
        }

        $valueRows = [];
        $insertFields = [];
        $hasGeneratedIdentity = false;
        $hasExplicitIdentity = false;

        foreach ($allItems as $insertKey => $row) {
            $insertKey += 1;
            $rowHasExplicitIdentity = $identityField !== '' && !empty($row[$identityField]);
            if ($identityField !== '' && !$rowHasExplicitIdentity) {
                unset($row[$identityField]);
                $hasGeneratedIdentity = true;
            } elseif ($identityField !== '') {
                $hasExplicitIdentity = true;
            }
            if ($hasGeneratedIdentity && $hasExplicitIdentity) {
                throw new \Exception(__('插入的数据记录中不允许同时存在有主键和无主键的情况！'));
            }

            $rowFields = array_keys($row);
            if ($insertFields === []) {
                $insertFields = $rowFields;
            } elseif ($rowFields !== $insertFields) {
                throw new DbException(__('批量插入的数据字段必须保持一致。'));
            }

            $rowPlaceholders = [];
            foreach ($insertFields as $field) {
                $pk = ':' . md5("insert_{$field}_field_{$insertKey}");
                $this->bindings[$pk] = $this->valueToBinding($row[$field]);
                $rowPlaceholders[] = $pk;
            }
            $valueRows[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $values = implode(',', $valueRows);
        if ($values === '') {
            return '';
        }

        $insertFieldsQuoted = array_map(fn(string $field): string => $this->dialect->quoteIdentifier($field), $insertFields);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $insertFieldsQuoted) . ') VALUES ' . $values;

        // 仅当调用方显式设置 exist_update_sql 时才加 ON CONFLICT（SQLite 要求冲突列必须是 PRIMARY KEY 或 UNIQUE，不能为无约束列自动加）
        if ($existUpdateSql !== '') {
            $conflictFields = [];
            foreach ($insertUpdateWhereFields as $field) {
                $field = trim((string)$field);
                if ($field !== '' && in_array($field, $insertFields, true)) {
                    $conflictFields[] = $this->dialect->quoteIdentifier($field);
                }
            }
            if ($conflictFields !== []) {
                if ($existUpdateSql === QueryInterface::EXIST_UPDATE_ALL_FIELDS) {
                    $parts = [];
                    foreach ($insertFields as $field) {
                        if (in_array($field, $insertUpdateWhereFields, true) || ($identityField && $field === $identityField)) {
                            continue;
                        }
                        $quotedField = $this->dialect->quoteIdentifier($field);
                        $parts[] = "{$quotedField}=excluded.{$quotedField}";
                    }
                    $existUpdateSql = empty($parts) ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $parts);
                }
                $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') ' . $existUpdateSql;
            }
        }

        if ($identityField && $supportsReturning) {
            $sql .= ' RETURNING ' . $this->dialect->quoteIdentifier($identityField);
        }

        return $sql;
    }

    protected function buildUpdate(array $ast, string $table, string $wheres, array $options): string
    {
        if (trim($wheres) === '') {
            throw new DbException(__('请设置更新条件'));
        }

        $identityField = $options['identity_field'] ?? 'id';
        $decIncUpdates = $ast['dec_inc_updates'] ?? [];
        $update = $ast['update'] ?? ['single' => [], 'batch' => []];
        $single = $update['single'] ?? [];
        $batch = $update['batch'] ?? [];
        $extra = $ast['extra'] ?? '';

        $updateExpressions = [];

        foreach ($decIncUpdates as $field => $expr) {
            $q = $this->dialect->quoteIdentifier($field);
            $updateExpressions[$field] = "{$q} = {$q} {$expr}";
        }

        if ($batch !== []) {
            $ids = array_column($batch, $identityField);
            if ($ids !== []) {
                $placeholders = [];
                foreach ($ids as $k => $id) {
                    $pk = ':up_id_' . $k;
                    $this->bindings[$pk] = (string)$id;
                    $placeholders[] = $pk;
                }
                $idQ = $this->dialect->quoteIdentifier($identityField);
                $wheres .= (trim($wheres) !== '' ? ' AND ' : '') . "{$idQ} IN (" . implode(',', $placeholders) . ')';

                $keys = array_keys(current($batch));
                foreach ($keys as $col) {
                    if ($col === $identityField) {
                        continue;
                    }
                    $colQ = $this->dialect->quoteIdentifier($col);
                    $caseSql = "{$colQ} = CASE {$idQ} ";
                    foreach ($batch as $uk => $line) {
                        $uk += 1;
                        $whenKey = ':up_when_' . $identityField . '_' . $col . '_' . $uk;
                        $this->bindings[$whenKey] = (string)($line[$identityField] ?? '');
                        $thenKey = ':up_then_' . $col . '_' . $uk;
                        $val = $line[$col] ?? null;
                        $this->bindings[$thenKey] = $val === null ? null : (is_bool($val) ? ($val ? '1' : '0') : (string)$val);
                        $caseSql .= "WHEN {$whenKey} THEN {$thenKey} ";
                    }
                    $caseSql .= 'END';
                    $updateExpressions[$col] = $caseSql;
                }
            } else {
                if (count($batch) > 1) {
                    throw new \Exception(__('更新条数大于一条时请使用示例更新'));
                }
                foreach ($batch[0] ?? [] as $f => $v) {
                    $pk = ':up_' . md5($f);
                    $this->bindings[$pk] = $this->valueToBinding($v);
                    $updateExpressions[$f] = $this->dialect->quoteIdentifier($f) . ' = ' . $pk;
                }
            }
        }

        foreach ($single as $f => $v) {
            $pk = ':up_' . md5($f);
            $this->bindings[$pk] = $this->valueToBinding($v);
            $updateExpressions[$f] = $this->dialect->quoteIdentifier($f) . ' = ' . $pk;
        }

        if ($updateExpressions === []) {
            throw new DbException(__('没有要更新的字段'));
        }

        $setClause = implode(',', $updateExpressions);
        return trim("UPDATE {$table} SET {$setClause} {$wheres} {$extra}");
    }
}
