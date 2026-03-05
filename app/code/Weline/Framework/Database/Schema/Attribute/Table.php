<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Attribute;

use Attribute;

/**
 * 声明式表注解，标注在 Model 类上。
 * 表名优先使用 Model::schema_table 常量，此处 comment 可选。
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly string $comment = '',
    ) {
    }
}
