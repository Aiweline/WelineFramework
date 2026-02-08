<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection;

use PDOStatement;

/**
 * 数据库连接抽象，封装 PDO 操作，避免业务层直接依赖 PDO
 * @since 1.0.0
 */
interface ConnectionInterface
{
    public function prepare(string $sql): PDOStatement;

    /** 执行 SQL 并返回受影响行数 */
    public function execute(string $sql): int;

    public function lastInsertId(?string $name = null): string|false;

    public function quote(string $string, int $type = \PDO::PARAM_STR): string;

    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;

    public function inTransaction(): bool;

    public function getDriverType(): string;

    public function getServerVersion(): string;

    /**
     * 获取底层 PDO 实例（仅用于兼容过渡期，请勿在新代码中使用）
     * @deprecated 使用本接口方法替代，后续版本可能移除
     */
    public function getPdo(): \PDO;
}
