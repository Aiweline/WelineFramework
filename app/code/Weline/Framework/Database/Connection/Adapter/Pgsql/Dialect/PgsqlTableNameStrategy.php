<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\TableNameStrategyInterface;

class PgsqlTableNameStrategy implements TableNameStrategyInterface
{
    public function __construct(
        private readonly IdentifierFormatterInterface $identifierFormatter,
        private readonly string $tablePrefix = '',
        private readonly string $defaultSchema = 'public'
    ) {
    }

    public function resolve(string $logicalName, string $defaultSchema = ''): string
    {
        // 去除所有引号（反引号和双引号）
        $logicalName = trim($logicalName, '`"');
        
        // PostgreSQL 始终使用 public schema，忽略传入的数据库名
        $schema = $this->defaultSchema;

        // 如果表名包含点号，检查第一部分
        if (str_contains($logicalName, '.')) {
            [$firstPart, $tablePart] = explode('.', $logicalName, 2);
            $firstPart = trim($firstPart, '`"');
            
            // 如果第一部分是已知的 schema（如 public, information_schema 等），使用它
            // 否则，第一部分可能是数据库名，忽略它，使用 public schema
            if (in_array(strtolower($firstPart), ['public', 'information_schema', 'pg_catalog', 'pg_toast'])) {
                $schema = $firstPart;
                $logicalName = $tablePart;
            } else {
                // 第一部分是数据库名或其他，忽略它，使用表名部分
                $logicalName = trim($tablePart, '`"');
            }
        }

        // 处理表前缀
        $table = $this->tablePrefix && !str_starts_with($logicalName, $this->tablePrefix)
            ? $this->tablePrefix . $logicalName
            : $logicalName;

        return $this->identifierFormatter->quoteQualified($schema, $table);
    }
}

