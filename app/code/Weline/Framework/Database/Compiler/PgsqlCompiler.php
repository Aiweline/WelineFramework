<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler;

use Weline\Framework\Database\Compiler\Dialect\PgsqlDialect;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Exception\DbException;

/**
 * PostgreSQL 16+ SQL 编译器
 * @since 1.0.0 支持 PostgreSQL 16+
 */
final class PgsqlCompiler extends AbstractCompiler
{
    public function __construct(?PgsqlDialect $dialect = null)
    {
        parent::__construct($dialect ?? new PgsqlDialect());
    }

    protected function buildInsert(array $ast, string $table, array $options): string
    {
        $insert = $ast['insert'] ?? [];
        $insertItems = $insert['insert'] ?? [];
        $insertOrUpdateItems = $insert['i_o_u'] ?? [];
        $identityField = $options['identity_field'] ?? 'id';
        $existUpdateSql = $options['exist_update_sql'] ?? '';
        $insertUpdateFields = $options['insert_update_fields'] ?? [];
        $insertUpdateWhereFields = $options['insert_update_where_fields'] ?? [];

        $allItems = array_merge($insertItems, $insertOrUpdateItems);
        if (empty($allItems)) {
            return '';
        }

        $identitySql = '';
        $values = '';
        $hasIdentityInsert = false;
        $hasNormalInsert = false;

        foreach ($allItems as $insertKey => $row) {
            $insertKey += 1;
            if ($identityField && empty($row[$identityField])) {
                unset($row[$identityField]);
                $fields = array_keys($row);
                $fieldsQuoted = array_map(fn(string $f): string => $this->dialect->quoteIdentifier($f), $fields);
                $identitySql .= 'INSERT INTO ' . $table . ' (' . implode(',', $fieldsQuoted) . ') VALUES (';
                foreach ($row as $f => $v) {
                    $pk = ':' . md5("insert_{$f}_field_{$insertKey}");
                    $this->bindings[$pk] = $this->valueToBinding($v);
                    $identitySql .= $pk . ', ';
                }
                $identitySql = rtrim($identitySql, ', ') . '); ';
                $hasIdentityInsert = true;
            } else {
                $values .= '(';
                foreach ($row as $f => $v) {
                    $pk = ':' . md5("insert_{$f}_field_{$insertKey}");
                    $this->bindings[$pk] = $this->valueToBinding($v);
                    $values .= $pk . ', ';
                }
                $values = rtrim($values, ', ') . '),';
                $hasNormalInsert = true;
            }
        }

        if ($hasIdentityInsert && $hasNormalInsert) {
            throw new \Exception(__('插入的数据记录中不允许同时存在有主键和无主键的情况！'));
        }

        $values = rtrim($values, ',');
        $sql = $identitySql;

        if ($values !== '') {
            $first = reset($allItems);
            $insertFields = array_keys($first);
            $insertFieldsQuoted = array_map(fn(string $f): string => $this->dialect->quoteIdentifier($f), $insertFields);
            $sql .= 'INSERT INTO ' . $table . ' (' . implode(',', $insertFieldsQuoted) . ') VALUES ' . $values;

            if ($existUpdateSql !== '') {
                $conflictFields = [];
                foreach ($insertUpdateWhereFields as $f) {
                    $f = trim((string)$f);
                    if ($f !== '' && in_array($f, $insertFields, true)) {
                        $conflictFields[] = $this->dialect->quoteIdentifier($f);
                    }
                }
                if ($conflictFields !== []) {
                    if ($existUpdateSql === QueryInterface::EXIST_UPDATE_ALL_FIELDS) {
                        $parts = [];
                        foreach ($insertFields as $f) {
                            if (in_array($f, $insertUpdateWhereFields, true) || ($identityField && $f === $identityField)) {
                                continue;
                            }
                            $q = $this->dialect->quoteIdentifier($f);
                            $parts[] = "{$q}=EXCLUDED.{$q}";
                        }
                        $existUpdateSql = empty($parts) ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $parts);
                    }
                    $sql .= ' ON CONFLICT (' . implode(', ', $conflictFields) . ') ' . $existUpdateSql;
                }
            }

            if ($identityField) {
                $sql .= ' RETURNING ' . $this->dialect->quoteIdentifier($identityField);
            }
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
                        // PostgreSQL PDO 绑定参数默认为 text 类型，需对整数/布尔/浮点值添加 CAST
                        if ($val === null) {
                            $this->bindings[$thenKey] = null;
                            $caseSql .= "WHEN {$whenKey} THEN {$thenKey} ";
                        } elseif (is_bool($val)) {
                            $this->bindings[$thenKey] = $val ? '1' : '0';
                            $caseSql .= "WHEN {$whenKey} THEN CAST({$thenKey} AS INTEGER) ";
                        } elseif (is_int($val) || (is_string($val) && is_numeric($val) && !str_contains($val, '.'))) {
                            $this->bindings[$thenKey] = (string)$val;
                            $caseSql .= "WHEN {$whenKey} THEN CAST({$thenKey} AS INTEGER) ";
                        } elseif (is_float($val) || (is_string($val) && is_numeric($val) && str_contains($val, '.'))) {
                            $this->bindings[$thenKey] = (string)$val;
                            $caseSql .= "WHEN {$whenKey} THEN CAST({$thenKey} AS NUMERIC) ";
                        } else {
                            $this->bindings[$thenKey] = (string)$val;
                            $caseSql .= "WHEN {$whenKey} THEN {$thenKey} ";
                        }
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
