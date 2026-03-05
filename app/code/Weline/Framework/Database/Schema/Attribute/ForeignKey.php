<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Attribute;

use Attribute;

/**
 * 声明式外键注解，标注在 Model 类上（可重复多个）。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ForeignKey
{
    public function __construct(
        public readonly string $name,
        public readonly array|string $columns,
        public readonly string $referencesTable,
        public readonly array|string $referencesColumns,
        public readonly bool $onDeleteCascade = false,
        public readonly bool $onUpdateCascade = false,
    ) {
    }
}
