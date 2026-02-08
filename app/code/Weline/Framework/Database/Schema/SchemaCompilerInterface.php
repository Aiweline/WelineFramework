<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * DDL 表/列编译接口：将列定义与表结构编译为方言 SQL
 * @since 1.0.0
 */
interface SchemaCompilerInterface
{
    /** 返回建表时的默认 additional 片段（如 MySQL 的 ENGINE=InnoDB...），无则返回空字符串 */
    public function getDefaultTableAdditional(): string;

    /** 将 Column 编译为方言列定义片段 */
    public function compileColumn(Column $column): string;

    public function getDriverType(): string;

    public function getSinceVersion(): string;
}
