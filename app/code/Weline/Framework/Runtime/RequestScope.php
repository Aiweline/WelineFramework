<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final class RequestScope
{
    /** @var array<string, array<string, true>> tag => class => true */
    private array $tags = [];

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

    public function set(string $id, object $object, ?string $tag = null): void
    {
        $this->instances[$id] = $object;
        $this->untag($id);
        if ($tag !== null) {
            $this->tags[$tag][$id] = true;
        }
    }

    public function remove(string $id): void
    {
        unset($this->instances[$id]);
        $this->untag($id);
    }

    /**
     * @return list<string>
     */
    public function classesByTag(string $tag): array
    {
        return \array_keys($this->tags[$tag] ?? []);
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
        $this->tags = [];
    }

    private function untag(string $id): void
    {
        foreach ($this->tags as $tag => $classes) {
            if (!isset($classes[$id])) {
                continue;
            }
            unset($classes[$id]);
            if ($classes === []) {
                unset($this->tags[$tag]);
            } else {
                $this->tags[$tag] = $classes;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->instances === [];
    }
}
