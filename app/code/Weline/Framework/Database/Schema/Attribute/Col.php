<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Attribute;

use Attribute;

/**
 * 声明式列注解。约定：标注在 schema_fields_* 常量上（列名=常量值，注释=comment 参数）。
 * 类型与 Framework Database TableInterface::column_type_* 对齐。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS_CONSTANT)]
final class Col
{
    /**
     * @param string $type 列类型
     * @param int|string|null $length 长度
     * @param bool $nullable 是否可空
     * @param bool $primaryKey 是否主键
     * @param bool $autoIncrement 是否自增
     * @param mixed $default 默认值
     * @param string $comment 注释
     * @param bool $unique 是否唯一
     * @param string|null $name 列名（与属性名不同时指定，避免与父类属性冲突）
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $length = null,
        public readonly bool $nullable = true,
        public readonly bool $primaryKey = false,
        public readonly bool $autoIncrement = false,
        public readonly mixed $default = null,
        public readonly string $comment = '',
        public readonly bool $unique = false,
        public readonly ?string $name = null,
    ) {
    }
}
