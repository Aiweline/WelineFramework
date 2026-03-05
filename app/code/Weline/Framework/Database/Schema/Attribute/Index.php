<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Attribute;

use Attribute;

/**
 * 声明式索引注解，标注在 Model 类上（可重复多个）。
 * 类型与 TableInterface::index_type_* 对齐：DEFAULT、UNIQUE、FULLTEXT、SPATIAL、KEY。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Index
{
    public function __construct(
        public readonly string $name,
        public readonly array|string $columns,
        public readonly string $type = 'DEFAULT',
        public readonly string $comment = '',
        public readonly string $method = 'BTREE',
    ) {
    }
}
