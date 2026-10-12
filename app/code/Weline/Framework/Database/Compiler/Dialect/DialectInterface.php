<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler\Dialect;

/**
 * 数据库方言接口：标识符引号、表名、函数名等
 * @since 1.0.0
 */
interface DialectInterface
{
    /** 引用标识符（字段/表名） */
    public function quoteIdentifier(string $identifier): string;

    /** 引用表名，可选别名 */
    public function quoteTable(string $table, string $alias = ''): string;

    /** 是否支持 RETURNING 子句 */
    public function supportsReturning(): bool;

    /** LIMIT/OFFSET 语法片段，如 " LIMIT :limit OFFSET :offset" */
    public function limitOffset(int $limit, int $offset): string;

    /** 当前时间戳函数名，如 NOW() / CURRENT_TIMESTAMP / datetime('now') */
    public function currentTimestamp(): string;

    /**
     * Unix epoch 秒表达式（整数）。
     * @param bool $utc true=UTC；false=连接会话时区（SQLite 仍为 UTC）
     */
    public function unixEpochExpression(bool $utc = true): string;

    /**
     * 将时间戳表达式按分钟桶向下取整，返回可 GROUP BY 的 'YYYY-MM-DD HH:MM:00' 文本表达式。
     * $timestampExpr 须已是安全标识符/表达式（由调用方 quote）。
     */
    public function timeBucketExpression(string $timestampExpr, int $intervalMinutes): string;

    /**
     * 提取 JSON 列路径为未加引号的文本标量（供绑定普通 PHP 字符串比较）。
     * $path 使用点分路径，如 testId / a.b（不含 $./）。
     */
    public function jsonExtractText(string $columnExpr, string $path): string;

    /** 布尔字面量 */
    public function booleanLiteral(bool $value): string;

    /** 支持的最低数据库版本 */
    public function getSinceVersion(): string;

    /** 数据库类型标识 */
    public function getDriverType(): string;

    /** 运行时校验服务器版本是否兼容 */
    public function validateVersion(string $serverVersion): void;
}
