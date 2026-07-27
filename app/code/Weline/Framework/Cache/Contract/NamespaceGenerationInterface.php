<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Public authority for cache namespace generations.
 *
 * Modules publish invalidation through this contract; cache consumers read a
 * request-frozen fingerprint. The database-backed repository remains the only
 * authoritative implementation.
 */
interface NamespaceGenerationInterface
{
    /** @param list<string> $namespaces */
    public function fingerprint(array $namespaces): string;

    /**
     * @param list<string> $namespaces
     * @return array{authority_clock:int,changes:array<string,int>}
     */
    public function bumpMany(array $namespaces): array;

    /** @return array{authority_clock:int,changes:array<string,int>} */
    public function bump(string $namespace): array;
}
