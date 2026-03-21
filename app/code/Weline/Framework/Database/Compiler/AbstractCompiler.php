<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler;

use Weline\Framework\Database\Compiler\Dialect\DialectInterface;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Util\SelectFieldListSplitter;

/**
 * SQL 编译器抽象基类，提取 WHERE/JOIN/ORDER 等公共逻辑
 * @since 1.0.0
 */
abstract class AbstractCompiler implements CompilerInterface
{
    protected const WHERE_CONDITIONS = [
        '>', '<', '>=', '!=', '<=', '<>', 'like', 'not like', 'in', 'not in', 'find_in_set', '=',
    ];

    protected array $bindings = [];

    protected array $compileOptions = [];

    public function __construct(
        protected readonly DialectInterface $dialect
    ) {
    }

    /** @inheritdoc */
    public function compile(array $ast, array $options = []): CompiledQuery
    {
        $this->bindings = [];
        $this->compileOptions = $options;

        $action = $ast['action'] ?? 'select';
        $identityField = $options['identity_field'] ?? 'id';
        $tableAlias = $options['table_alias'] ?? 'main_table';

        $table = $this->formatTableFromAst($ast, $action !== 'insert');
        $joins = $this->buildJoins($ast['joins'] ?? [], $tableAlias);
        $wheres = $this->buildWheres($ast['where'] ?? [], $ast);
        $order = $this->buildOrder($ast['order'] ?? []);
        $groupBy = !empty($ast['group']) ? 'GROUP BY ' . $ast['group'] : '';
        $having = !empty($ast['having']) ? 'HAVING ' . $ast['having'] : '';
        $extra = $ast['extra'] ?? '';
        $limit = $ast['limit'] ?? '';

        $sql = match ($action) {
            'insert' => $this->buildInsert($ast, $table, array_merge($options, ['identity_field' => $identityField])),
            'update' => $this->buildUpdate($ast, $table, $wheres, array_merge($options, ['identity_field' => $identityField, 'table_alias' => $tableAlias])),
            'delete' => trim("DELETE FROM {$table} {$wheres} {$extra}"),
            'find', 'select' => $this->buildSelect($ast, $table, $joins, $wheres, $groupBy, $having, $extra, $order, $limit),
            default => throw new DbException(__('不支持的查询类型：{1}', [$action])),
        };

        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return new CompiledQuery($sql, $this->bindings, $action);
    }

    public function getDriverType(): string
    {
        return $this->dialect->getDriverType();
    }

    public function getSinceVersion(): string
    {
        return $this->dialect->getSinceVersion();
    }

    protected function formatTableFromAst(array $ast, bool $includeAlias = true): string
    {
        $from = $ast['from'] ?? [];
        $table = trim((string)($from['table'] ?? ''));
        $alias = (string)($from['alias'] ?? 'main_table');

        if (!empty($from['is_subquery']) && !empty($from['subquery_id'])) {
            $subquerySql = $this->compileRegisteredSubquery(
                $ast,
                (string)$from['subquery_id'],
                'from_' . (string)$from['subquery_id']
            );

            $aliasSql = '';
            if ($includeAlias && $alias !== '') {
                $aliasSql = ' AS ' . $this->dialect->quoteIdentifier($alias);
            }

            return '(' . $subquerySql . ')' . $aliasSql;
        }

        return $this->dialect->quoteTable($table, $includeAlias ? $alias : '');
    }

    protected function buildSelect(
        array $ast,
        string $table,
        string $joins,
        string $wheres,
        string $groupBy,
        string $having,
        string $extra,
        string $order,
        string $limit
    ): string {
        $fields = $this->formatFields($ast['select']['fields'] ?? '*', $ast['from']['alias'] ?? 'main_table');
        return trim("SELECT {$fields} FROM {$table} {$joins} {$wheres} {$groupBy} {$having} {$extra} {$order} {$limit}");
    }

    protected function buildWheres(array $wheres, array $ast = []): string
    {
        if (empty($wheres)) {
            return '';
        }

        $parts = [];
        $count = count($wheres);
        $idx = 0;
        foreach ($wheres as $key => $where) {
            $idx++;
            $isLast = ($idx === $count);
            $logic = '';
            if (!$isLast && isset($where[3])) {
                $logic = ' ' . strtoupper(trim((string)$where[3])) . ' ';
            } elseif (!$isLast) {
                $logic = ' AND ';
            }

            $field = $where[0];
            if (is_string($field) && str_contains($field, '(')) {
                $fieldQuoted = $field;
            } else {
                $fieldQuoted = $this->quoteFieldExpression((string)$field);
            }

            if (count($where) === 1) {
                $raw = $where[0];
                if (is_string($raw) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/i', trim(str_replace(['"', '`'], '', $raw)))) {
                    $parts[] = '(' . $this->quoteFieldExpression($raw) . ' IS NOT NULL)' . $logic;
                } else {
                    $parts[] = '(' . $raw . ')' . $logic;
                }
                continue;
            }

            if (($where[2] ?? null) === null) {
                $op = strtolower(trim((string)($where[1] ?? '=')));
                $isNot = in_array($op, ['!=', '<>', 'not', 'not ='], true);
                $parts[] = '(' . $fieldQuoted . ($isNot ? ' IS NOT NULL' : ' IS NULL') . ')' . $logic;
                continue;
            }

            $paramName = ':p_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $this->normalizeFieldName($fieldQuoted)) . '_' . $key;
            $op = strtolower((string)$where[1]);

            if ($this->isSubqueryReference($where[2])) {
                $subqueryId = (string)($where[2]['subquery_id'] ?? '');
                $subquerySql = $this->compileRegisteredSubquery($ast, $subqueryId, 'where_' . $key . '_' . $subqueryId);
                $parts[] = '(' . $fieldQuoted . ' ' . $this->normalizeOperator($op) . ' (' . $subquerySql . '))' . $logic;
                continue;
            }

            if ($op === 'find_in_set') {
                $parts[] = '(' . $this->buildFindInSetCondition($fieldQuoted, $where[2], $paramName) . ')' . $logic;
                continue;
            }

            if (in_array($op, ['in', 'not in'], true) && is_array($where[2])) {
                $placeholders = [];
                foreach ($where[2] as $ik => $item) {
                    $pn = $paramName . '_' . $ik;
                    $this->bindings[$pn] = $this->valueToBinding($item);
                    $placeholders[] = $pn;
                }
                $list = '(' . implode(',', $placeholders) . ')';
                $parts[] = '(' . $fieldQuoted . ' ' . strtoupper($op) . ' ' . $list . ')' . $logic;
                continue;
            }

            if (in_array($op, ['like', 'not like'], true)) {
                $this->bindings[$paramName] = $this->valueToBinding($where[2]);
                $parts[] = '(' . $fieldQuoted . ' ' . strtoupper($op) . ' ' . $paramName . ')' . $logic;
                continue;
            }

            $this->bindings[$paramName] = $this->valueToBinding($where[2]);
            $parts[] = '(' . $fieldQuoted . ' ' . $this->normalizeOperator($op) . ' ' . $paramName . ')' . $logic;
        }

        $sql = ' WHERE ' . trim(implode('', $parts));
        $sql = preg_replace('/\s+(AND|OR)\s*$/i', '', $sql);
        return $sql;
    }

    protected function buildJoins(array $joins, string $mainAlias): string
    {
        if (empty($joins)) {
            return '';
        }
        $out = '';
        foreach ($joins as $join) {
            $tableWithAlias = trim((string)($join[0] ?? ''));
            $condition = (string)($join[1] ?? '');
            $type = strtoupper((string)($join[2] ?? 'LEFT'));
            $parts = array_values(array_filter(explode(' ', $tableWithAlias), fn(string $p): bool => $p !== ''));
            $rawTable = $tableWithAlias;
            $alias = '';
            if (count($parts) >= 2) {
                $alias = trim($parts[count($parts) - 1], '`"');
                $rawTable = implode(' ', array_slice($parts, 0, -1));
            }
            $table = $this->dialect->quoteTable($rawTable);
            $aliasSql = $alias !== '' ? ' AS ' . $this->dialect->quoteIdentifier($alias) : '';
            $cond = $this->formatJoinCondition($condition);
            $out .= " {$type} JOIN {$table}{$aliasSql} ON {$cond}";
        }
        return $out;
    }

    protected function formatJoinCondition(string $condition): string
    {
        return preg_replace_callback(
            '/([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)/',
            fn(array $m): string => $this->dialect->quoteIdentifier($m[1]) . '.' . $this->dialect->quoteIdentifier($m[2]),
            $condition
        );
    }

    protected function buildOrder(array $order): string
    {
        if (empty($order)) {
            return '';
        }
        $parts = [];
        foreach ($order as $field => $dir) {
            $parts[] = $this->quoteFieldExpression(is_string($field) ? $field : (string)$field) . ' ' . $dir;
        }
        return $parts === [] ? '' : 'ORDER BY ' . implode(', ', $parts);
    }

    protected function formatFields(string $fields, string $tableAlias): string
    {
        if ($fields === '*' || $fields === '') {
            return '*';
        }
        $list = SelectFieldListSplitter::split($fields);
        $out = [];
        foreach ($list as $field) {
            if (preg_match('/^(.+?)\s+(AS|as)\s+(.+)$/i', $field, $m)) {
                $expr = $this->quoteFieldExpression(trim($m[1]));
                $alias = trim($m[3], '`"');
                $out[] = "{$expr} AS " . $this->dialect->quoteIdentifier($alias);
            } else {
                $out[] = $this->quoteFieldExpression($field);
            }
        }
        return implode(', ', $out);
    }

    protected function quoteFieldExpression(string $field): string
    {
        $field = trim(str_replace(['`', '"', '[', ']'], '', $field));
        if ($field === '') {
            return '';
        }

        // function expression, keep original.
        if (preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*\\s*\\(/i", $field)) {
            return $field;
        }

        // alias.* expression.
        if (preg_match("/^([^.]*?)\\.\\*$/", $field, $m)) {
            $alias = $m[1];
            return $this->dialect->quoteIdentifier($alias) . '.*';
        }

        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            return implode('.', array_map(fn(string $p): string => $this->dialect->quoteIdentifier($p), $parts));
        }

        return $this->dialect->quoteIdentifier($field);
    }

    protected function normalizeFieldName(string $field): string
    {
        return strtolower(trim($field, '`"[]'));
    }

    /**
     * 将 INSERT 行值转为绑定值，避免对象被强转 string 导致 Error
     * @param mixed $v
     * @return string|float|int|bool|null
     */
    protected function valueToBinding(mixed $v): string|float|int|bool|null
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_object($v)) {
            return method_exists($v, '__toString') ? $v->__toString() : json_encode($v);
        }
        return is_string($v) || is_int($v) || is_float($v) || is_bool($v) ? $v : (string)$v;
    }

    protected function isSubqueryReference(mixed $value): bool
    {
        return is_array($value) && !empty($value['is_subquery']) && !empty($value['subquery_id']);
    }

    protected function compileRegisteredSubquery(array $ast, string $subqueryId, string $prefix): string
    {
        if ($subqueryId === '' || !isset($ast['subqueries'][$subqueryId]) || !is_array($ast['subqueries'][$subqueryId])) {
            throw new DbException(__('子查询 %{1} 不存在', [$subqueryId]));
        }

        return $this->compileNestedSubquery($ast['subqueries'][$subqueryId], $prefix);
    }

    protected function compileNestedSubquery(array $subqueryAst, string $prefix): string
    {
        $subqueryOptions = $this->compileOptions;
        $subqueryOptions['table_alias'] = $subqueryAst['from']['alias'] ?? ($subqueryOptions['table_alias'] ?? 'main_table');

        /** @var static $compiler */
        $compiler = new static($this->dialect);
        $compiled = $compiler->compile($subqueryAst, $subqueryOptions);

        return $this->mergeSubqueryBindings($compiled, $prefix);
    }

    protected function mergeSubqueryBindings(CompiledQuery $compiled, string $prefix): string
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $prefix) ?: 'subquery';
        if ($compiled->bindings === []) {
            return $compiled->sql;
        }

        $mapping = [];
        foreach ($compiled->bindings as $oldParam => $value) {
            $oldParamName = ltrim((string)$oldParam, ':');
            $newParam = ':sq_' . $prefix . '_' . $oldParamName;
            $mapping[(string)$oldParam] = $newParam;
            $this->bindings[$newParam] = $value;
        }

        return strtr($compiled->sql, $mapping);
    }

    protected function buildFindInSetCondition(string $fieldQuoted, mixed $value, string $paramName): string
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $expressions = [];

        foreach ($values as $index => $item) {
            $pn = is_array($value) ? $paramName . '_' . $index : $paramName;
            $this->bindings[$pn] = $this->valueToBinding($item);
            $expressions[] = $this->buildFindInSetExpression($fieldQuoted, $pn);
        }

        return implode(' AND ', $expressions);
    }

    protected function buildFindInSetExpression(string $fieldQuoted, string $paramName): string
    {
        return match ($this->dialect->getDriverType()) {
            'mysql' => "FIND_IN_SET({$paramName}, COALESCE({$fieldQuoted}, '')) > 0",
            'pgsql' => "POSITION(',' || {$paramName} || ',' IN ',' || COALESCE(CAST({$fieldQuoted} AS TEXT), '') || ',') > 0",
            'sqlite' => "INSTR(',' || COALESCE(CAST({$fieldQuoted} AS TEXT), '') || ',', ',' || {$paramName} || ',') > 0",
            default => throw new DbException(__('不支持的数据库驱动：{1}', [$this->dialect->getDriverType()])),
        };
    }

    protected function normalizeOperator(string $op): string
    {
        return in_array($op, self::WHERE_CONDITIONS, true) ? strtoupper($op) : '=';
    }

    abstract protected function buildInsert(array $ast, string $table, array $options): string;

    abstract protected function buildUpdate(array $ast, string $table, string $wheres, array $options): string;
}
