<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\TableNameStrategyInterface;

/**
 * Resolve cross-driver logical table names to SQLite's one-file namespace.
 */
final class SqliteTableNameStrategy implements TableNameStrategyInterface
{
    public function __construct(
        private readonly IdentifierFormatterInterface $identifierFormatter,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function resolve(string $logicalName, string $defaultSchema = ''): string
    {
        $logicalName = trim($logicalName);
        if ($logicalName === '') {
            return '';
        }

        $parts = array_values(array_filter(
            array_map(
                'trim',
                explode('.', str_replace(['`', '"', '[', ']'], '', $logicalName)),
            ),
            static fn(string $part): bool => $part !== '',
        ));
        $table = (string)end($parts);
        if ($table === '') {
            return '';
        }
        if ($this->tablePrefix !== '' && !str_starts_with($table, $this->tablePrefix)) {
            $table = $this->tablePrefix . $table;
        }

        return $this->identifierFormatter->quote($table);
    }
}
