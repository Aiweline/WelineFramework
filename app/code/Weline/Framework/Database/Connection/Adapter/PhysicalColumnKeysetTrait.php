<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter;

use PDO;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;

/**
 * Shared row-value keyset for MySQL, PostgreSQL and SQLite.
 * The comparison form is the subset those three engines all accept; quoting
 * stays on the connector so this trait does not pick a dialect identifier style.
 *
 * MySQL's default buffered execute() allocates the whole result before the
 * first fetch. The stream turns that off for this cursor only and restores it
 * when the generator closes.
 */
trait PhysicalColumnKeysetTrait
{
    /**
     * @param non-empty-list<string> $primaryKeyColumns
     * @param array<string, mixed>|null $afterPrimaryKey
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamPhysicalColumnKeyset(
        PhysicalTableIdentity $identity,
        array $primaryKeyColumns,
        string $columnName,
        ?array $afterPrimaryKey,
    ): \Generator {
        $columnName = trim($columnName);
        if ($primaryKeyColumns === [] || $columnName === '') {
            throw new \InvalidArgumentException('invalid physical column keyset request');
        }
        $metadata = $this->getPhysicalTableColumns($identity);
        $declaredPrimaryKeys = [];
        $knownColumns = [];
        foreach ($metadata as $column) {
            $name = trim((string)($column['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $knownColumns[$name] = true;
            if (!empty($column['primary_key'])) {
                $declaredPrimaryKeys[] = $name;
            }
        }
        if ($declaredPrimaryKeys !== array_values($primaryKeyColumns)) {
            throw new \RuntimeException('physical column keyset does not match complete primary key');
        }
        if (!isset($knownColumns[$columnName])) {
            throw new \RuntimeException('physical column keyset column is not in the table');
        }
        if ($afterPrimaryKey !== null && array_keys($afterPrimaryKey) !== $primaryKeyColumns) {
            throw new \InvalidArgumentException('physical column keyset cursor mismatch');
        }
        if ($afterPrimaryKey !== null && in_array(null, $afterPrimaryKey, true)) {
            throw new \RuntimeException('physical column keyset cursor contains null');
        }

        $quotedKeys = array_map(
            fn(string $column): string => $this->quoteIdentifier($column),
            $primaryKeyColumns,
        );
        $selectList = $quotedKeys;
        if (!in_array($columnName, $primaryKeyColumns, true)) {
            $selectList[] = $this->quoteIdentifier($columnName);
        }
        $quotedColumn = $this->quoteIdentifier($columnName);
        $sql = 'SELECT ' . implode(', ', $selectList)
            . ' FROM ' . $this->quotePhysicalTable($identity)
            . ' WHERE ' . $quotedColumn . ' IS NOT NULL';
        $parameters = [];
        if ($afterPrimaryKey !== null) {
            $placeholders = [];
            foreach ($primaryKeyColumns as $index => $column) {
                $placeholder = ':physical_col_key_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $afterPrimaryKey[$column];
            }
            $sql .= ' AND (' . implode(', ', $quotedKeys) . ') > ('
                . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY ' . implode(', ', $quotedKeys) . ' ASC';

        $pdo = $this->getWrappedConnection()->getPdo();
        $unbuffered = false;
        if (
            $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
        ) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $unbuffered = true;
        }
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            try {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (is_array($row)) {
                        yield $row;
                    }
                }
            } finally {
                $statement->closeCursor();
            }
        } finally {
            if ($unbuffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }
}
