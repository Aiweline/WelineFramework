<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

/**
 * 负责所有标识符（库/表/字段/索引等）的格式化与转义。
 */
interface IdentifierFormatterInterface
{
    /**
     * 统一清洗用户输入的标识符。
     */
    public function normalize(string $identifier): string;

    /**
     * 为单个标识符添加驱动所需的引号。
     */
    public function quote(string $identifier): string;

    /**
     * 将多段标识符（如 schema.table 或 table.column）组合并加引号。
     *
     * @param string ...$parts 已按从高到低（库->表->列）的顺序传入
     */
    public function quoteQualified(string ...$parts): string;
}

