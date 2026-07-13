<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

use Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface;

/**
 * Keeps the historical CDN contract stable for Framework-level adapters.
 */
final readonly class EdgeCacheAdapterBridge implements AdapterInterface
{
    public function __construct(
        private EdgeCacheAdapterInterface $adapter,
    ) {
    }

    public function getAdapterCode(): string
    {
        return $this->adapter->getAdapterCode();
    }

    public function getAdapterName(): string
    {
        return $this->adapter->getAdapterName();
    }

    public function getDescription(): string
    {
        return $this->adapter->getDescription();
    }

    public function getVersion(): string
    {
        return $this->adapter->getVersion();
    }

    public function purgeEverything(string $zoneId, array $credentials): array
    {
        return $this->adapter->purgeEverything($zoneId, $credentials);
    }

    public function purgeUrls(string $zoneId, array $urls, array $credentials): array
    {
        return $this->adapter->purgeUrls($zoneId, $urls, $credentials);
    }

    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        return $this->adapter->purgeHosts($zoneId, $hosts, $credentials);
    }

    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        return $this->adapter->purgeTags($zoneId, $tags, $credentials);
    }

    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        return $this->adapter->purgeCacheKeys($zoneId, $keys, $credentials);
    }

    public function getRules(string $zoneId, array $credentials): array
    {
        return $this->adapter->getRules($zoneId, $credentials);
    }

    public function putRules(string $zoneId, array $rules, array $credentials): array
    {
        return $this->adapter->putRules($zoneId, $rules, $credentials);
    }

    public function ensureZone(string $domain, array $credentials): array
    {
        return $this->adapter->ensureZone($domain, $credentials);
    }

    public function enableAttackMode(string $zoneId, array $credentials, array $attackData = []): array
    {
        return $this->adapter->enableAttackMode($zoneId, $credentials, $attackData);
    }

    public function disableAttackMode(string $zoneId, array $credentials): array
    {
        return $this->adapter->disableAttackMode($zoneId, $credentials);
    }

    public function supportsAttackMode(): bool
    {
        return $this->adapter->supportsAttackMode();
    }

    public function getRealIpHeaderKeys(): array
    {
        return $this->adapter->getRealIpHeaderKeys();
    }
}
