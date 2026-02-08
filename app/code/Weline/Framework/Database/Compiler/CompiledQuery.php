<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler;

/**
 * 编译结果值对象，包含 SQL 与绑定参数
 * @since 1.0.0
 */
final readonly class CompiledQuery
{
    public function __construct(
        public string $sql,
        public array $bindings = [],
        public string $action = 'select',
    ) {
    }
}
