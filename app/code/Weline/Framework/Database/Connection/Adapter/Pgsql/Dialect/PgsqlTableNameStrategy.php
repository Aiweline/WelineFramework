<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\TableNameStrategyInterface;

class PgsqlTableNameStrategy implements TableNameStrategyInterface
{
    private ?string $runtimeSchema = null;

    public function __construct(
        private readonly IdentifierFormatterInterface $identifierFormatter,
        private readonly string $tablePrefix = '',
        private readonly string $defaultSchema = 'public',
        private ?\PDO $pdo = null
    ) {
    }

    /**
     * 设置 PDO 连接（用于动态获取 current_schema）
     */
    public function setPdo(\PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * 获取运行时 schema（优先使用 current_schema()）
     */
    private function getRuntimeSchema(): string
    {
        if ($this->runtimeSchema !== null) {
            return $this->runtimeSchema;
        }

        if ($this->pdo !== null) {
            try {
                $this->runtimeSchema = $this->pdo->query('SELECT current_schema()')->fetchColumn() ?: $this->defaultSchema;
                return $this->runtimeSchema;
            } catch (\Throwable $e) {
                // 查询失败，使用默认值
            }
        }

        return $this->defaultSchema;
    }

    public function resolve(string $logicalName, string $defaultSchema = ''): string
    {
        // 去除所有引号（反引号和双引号）
        $logicalName = str_replace(['`', '"'], '', trim($logicalName));

        // 使用运行时 schema（动态获取 current_schema）
        $schema = $this->getRuntimeSchema();

        // PostgreSQL 的两段名称始终是 schema.table。显式 schema 必须原样保留，
        // 再交给 formatter 分段引用；只有无点名称才使用运行时 schema。
        if (str_contains($logicalName, '.')) {
            [$explicitSchema, $tablePart] = explode('.', $logicalName, 2);
            $explicitSchema = trim($explicitSchema);
            if ($explicitSchema !== '') {
                $schema = $explicitSchema;
            }
            $logicalName = trim($tablePart);
        }

        // 处理表前缀
        $table = $this->tablePrefix && !str_starts_with($logicalName, $this->tablePrefix)
            ? $this->tablePrefix . $logicalName
            : $logicalName;

        return $this->identifierFormatter->quoteQualified($schema, $table);
    }
}
