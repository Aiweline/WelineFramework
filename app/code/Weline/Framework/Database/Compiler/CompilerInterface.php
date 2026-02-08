<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler;

/**
 * SQL 编译器接口：将 AST 编译为方言 SQL + 绑定参数
 * @since 1.0.0
 */
interface CompilerInterface
{
    /**
     * 根据 action 编译完整 AST 为 SQL + 绑定参数
     * @param array<string, mixed> $ast 查询 AST
     * @param array{identity_field?: string, table_alias?: string} $options 编译选项
     */
    public function compile(array $ast, array $options = []): CompiledQuery;

    /** 返回数据库类型标识，如 mysql / pgsql / sqlite */
    public function getDriverType(): string;

    /** 返回支持的最低数据库版本，如 8.0 / 16.0 / 3.45 */
    public function getSinceVersion(): string;
}
