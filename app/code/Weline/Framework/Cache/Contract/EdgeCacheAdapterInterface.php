<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Platform-neutral contract for an edge or process cache adapter.
 */
interface EdgeCacheAdapterInterface
{
    public function getAdapterCode(): string;

    public function getAdapterName(): string;

    public function getDescription(): string;

    public function getVersion(): string;

    public function purgeEverything(string $zoneId, array $credentials): array;

    public function purgeUrls(string $zoneId, array $urls, array $credentials): array;

    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array;

    public function purgeTags(string $zoneId, array $tags, array $credentials): array;

    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array;

    public function getRules(string $zoneId, array $credentials): array;

    public function putRules(string $zoneId, array $rules, array $credentials): array;

    public function ensureZone(string $domain, array $credentials): array;

    public function enableAttackMode(string $zoneId, array $credentials, array $attackData = []): array;

    public function disableAttackMode(string $zoneId, array $credentials): array;

    public function supportsAttackMode(): bool;

    /** @return array<string> */
    public function getRealIpHeaderKeys(): array;
}
