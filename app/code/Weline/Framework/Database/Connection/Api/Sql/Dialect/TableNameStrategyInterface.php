<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

/**
 * 负责将逻辑表名转换为带前缀/Schema 的最终表名。
 */
interface TableNameStrategyInterface
{
    /**
     * @param string $logicalName 模型或业务声明的表名（未带前缀/引号）
     * @param string $defaultSchema 默认库/Schema（可为空）
     */
    public function resolve(string $logicalName, string $defaultSchema = ''): string;
}

