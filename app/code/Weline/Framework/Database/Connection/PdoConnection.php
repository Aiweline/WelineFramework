<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection;

use PDO;
use PDOStatement;

/**
 * 基于 PDO 的 ConnectionInterface 实现
 * @since 1.0.0
 */
final class PdoConnection implements ConnectionInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $driverType,
    ) {
    }

    public function prepare(string $sql): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('PDO::prepare failed: ' . ($this->pdo->errorInfo()[2] ?? 'unknown'));
        }
        return $stmt;
    }

    public function execute(string $sql): int
    {
        $result = $this->pdo->exec($sql);
        return $result !== false ? $result : 0;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $id = $name === null ? $this->pdo->lastInsertId() : $this->pdo->lastInsertId($name);
        return $id !== false ? (string)$id : false;
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string
    {
        $q = $this->pdo->quote($string, $type);
        return $q !== false ? $q : "'" . str_replace("'", "''", $string) . "'";
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function getDriverType(): string
    {
        return $this->driverType;
    }

    public function getServerVersion(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /** @deprecated 使用本接口方法替代 */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
