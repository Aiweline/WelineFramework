<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

/**
 * Fake PDOStatement returned after an INSERT executed via exec() (no real result set).
 *
 * PHP 8.4 quirk: userland PDOStatement subclasses that do not call parent::__construct()
 * are created "uninitialized"; any INHERITED method call on them throws
 * "PDOStatement@anonymous object is uninitialized". Every method callers may touch is
 * therefore DECLARED here so none are inherited.
 */
class FakeInsertStatement extends \PDOStatement
{
    private mixed $lastId;
    private string $identityField;

    public function __construct(mixed $lastId, string $identityField)
    {
        $this->lastId = $lastId;
        $this->identityField = $identityField;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [[$this->identityField => $this->lastId]];
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        return [$this->identityField => $this->lastId];
    }

    public function fetchColumn($column = 0): mixed
    {
        return $this->lastId;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function columnCount(): int
    {
        return 1;
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
