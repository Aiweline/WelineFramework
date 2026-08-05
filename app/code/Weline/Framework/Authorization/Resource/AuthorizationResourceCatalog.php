<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/** Immutable snapshot of definitions + bindings for one compile/upgrade round. */
final class AuthorizationResourceCatalog
{
    /**
     * @param array<string,AuthorizationResourceDefinition> $definitions keyed by source_id
     * @param list<AuthorizationResourceBinding> $bindings
     */
    public function __construct(
        public readonly array $definitions,
        public readonly array $bindings,
    ) {
    }

    /** @return list<string> */
    public function sourceIds(): array
    {
        return \array_keys($this->definitions);
    }

    public function getDefinition(string $sourceId): ?AuthorizationResourceDefinition
    {
        return $this->definitions[$sourceId] ?? null;
    }

    /**
     * @return list<AuthorizationResourceBinding>
     */
    public function bindingsFor(string $sourceId): array
    {
        $out = [];
        foreach ($this->bindings as $binding) {
            if ($binding->sourceId === $sourceId) {
                $out[] = $binding;
            }
        }
        return $out;
    }

    /** @return array{definitions:array<string,array<string,mixed>>,bindings:list<array<string,mixed>>} */
    public function toArray(): array
    {
        $definitions = [];
        foreach ($this->definitions as $sourceId => $definition) {
            $definitions[$sourceId] = $definition->toArray();
        }
        $bindings = [];
        foreach ($this->bindings as $binding) {
            $bindings[] = $binding->toArray();
        }
        return [
            'definitions' => $definitions,
            'bindings' => $bindings,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $definitions = [];
        foreach (($data['definitions'] ?? []) as $sourceId => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $definition = AuthorizationResourceDefinition::fromArray($row);
            $definitions[$definition->sourceId !== '' ? $definition->sourceId : (string)$sourceId] = $definition;
        }
        $bindings = [];
        foreach (($data['bindings'] ?? []) as $row) {
            if (\is_array($row)) {
                $bindings[] = AuthorizationResourceBinding::fromArray($row);
            }
        }
        return new self($definitions, $bindings);
    }
}
