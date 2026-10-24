<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

/**
 * Fake PDOStatement returned after a non-INSERT statement executed via exec() (no result set).
 *
 * PHP 8.4 quirk: userland PDOStatement subclasses that do not call parent::__construct()
 * are created "uninitialized"; any INHERITED method call on them throws
 * "PDOStatement@anonymous object is uninitialized". Every method callers may touch is
 * therefore DECLARED here so none are inherited.
 */
class FakeEmptyStatement extends \PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array
    {
        return [];
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, ...$args): mixed
    {
        return false;
    }

    public function fetchColumn($column = 0): mixed
    {
        return false;
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function columnCount(): int
    {
        return 0;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function closeCursor(): bool
    {
        return true;
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        return true;
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        return true;
    }

    public function setFetchMode($mode, ...$args): true
    {
        return true;
    }

    public function errorCode(): ?string
    {
        return null;
    }

    public function errorInfo(): array
    {
        return ['', null, null];
    }
}
