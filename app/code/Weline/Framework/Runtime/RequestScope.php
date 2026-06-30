<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final class RequestScope
{
    /**
     * @param array<string, object> $instances
     */
    public function __construct(private array $instances = [])
    {
    }

    public function get(string $id): ?object
    {
        return $this->instances[$id] ?? null;
    }

    public function set(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function remove(string $id): void
    {
        unset($this->instances[$id]);
    }

    /**
     * @return array<string, object>
     */
    public function all(): array
    {
        return $this->instances;
    }

    /**
     * @param array<string, object> $instances
     */
    public function replace(array $instances): void
    {
        $this->instances = $instances;
    }

    public function isEmpty(): bool
    {
        return $this->instances === [];
    }
}
