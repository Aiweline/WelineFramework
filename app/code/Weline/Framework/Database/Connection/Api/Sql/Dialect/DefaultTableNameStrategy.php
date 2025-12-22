<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

/**
 * 默认实现：支持库名 + 前缀 + 表名，并委托 IdentifierFormatter 做引号。
 */
class DefaultTableNameStrategy implements TableNameStrategyInterface
{
    public function __construct(
        private readonly IdentifierFormatterInterface $identifierFormatter,
        private readonly string $tablePrefix = ''
    ) {
    }

    public function resolve(string $logicalName, string $defaultSchema = ''): string
    {
        $logicalName = trim($logicalName);
        if ($logicalName === '') {
            return $logicalName;
        }

        // 如果调用方已经携带 schema/库信息，按输入拆分后重新加引号
        if (str_contains($logicalName, '.')) {
            $parts = array_filter(explode('.', str_replace(['`', '"'], '', $logicalName)));
            $schema = count($parts) > 1 ? array_shift($parts) : $defaultSchema;
            $table = array_pop($parts) ?? '';
            $table = $this->applyPrefix($table);
            return $this->identifierFormatter->quoteQualified($schema ?: '', $table);
        }

        $table = $this->applyPrefix(str_replace(['`', '"'], '', $logicalName));
        return $this->identifierFormatter->quoteQualified($defaultSchema, $table);
    }

    private function applyPrefix(string $table): string
    {
        if ($this->tablePrefix === '') {
            return $table;
        }
        if (str_starts_with($table, $this->tablePrefix)) {
            return $table;
        }
        return $this->tablePrefix . $table;
    }
}

