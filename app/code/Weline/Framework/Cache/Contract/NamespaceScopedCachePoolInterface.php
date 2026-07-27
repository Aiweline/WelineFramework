<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Opt-in namespace capability.
 *
 * The legacy CachePoolInterface stays unchanged so third-party adapters and
 * lightweight fakes remain binary compatible.
 */
interface NamespaceScopedCachePoolInterface extends CachePoolInterface, RemembererInterface
{
    public function withNamespace(string $namespace): NamespaceScopedCachePoolInterface;

    /** @param list<string> $namespaces */
    public function withNamespaces(array $namespaces): NamespaceScopedCachePoolInterface;
}
