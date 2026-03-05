<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 列定义 DTO，与 #[Col] 及 SHOW COLUMNS 解析结果对齐。
 */
final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int|string|null $length = null,
        public readonly bool $nullable = true,
        public readonly bool $primaryKey = false,
        public readonly bool $autoIncrement = false,
        public readonly mixed $default = null,
        public readonly string $comment = '',
        public readonly bool $unique = false,
    ) {
    }
}
