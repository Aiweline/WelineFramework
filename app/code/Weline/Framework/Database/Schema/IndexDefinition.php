<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 索引定义 DTO。
 */
final class IndexDefinition
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $type = 'DEFAULT',
        public readonly string $comment = '',
        public readonly string $method = 'BTREE',
    ) {
    }
}
