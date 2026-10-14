<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * FPC 失效：仅经活跃 FpcStoreAdapter，不直捅 CacheManager。
 */
final class FpcCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'fpc';
    }

    public function description(): string
    {
        return 'Full Page Cache 失效（urls / all）';
    }

    public function supportedEffects(): array
    {
        return [
            InvalidationEffect::CODE_PURGE_FPC_URLS,
            InvalidationEffect::CODE_PURGE_FPC_ALL,
        ];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        try {
            /** @var FpcStoreAdapterRegistry $registry */
            $registry = ObjectManager::getInstance(FpcStoreAdapterRegistry::class);
            $adapter = $registry->active();
        } catch (\Throwable) {
            return;
        }
        if ($adapter === null) {
            return;
        }
        if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_ALL) {
            $adapter->purgeAll((string)($effect->payload['reason'] ?? $change->resourceType()));

            return;
        }
        if ($effect->code !== InvalidationEffect::CODE_PURGE_FPC_URLS) {
            return;
        }
        $slugs = \is_array($effect->payload['slugs'] ?? null) ? $effect->payload['slugs'] : [];
        if ($slugs === []) {
            $impact = $change->toArray()['impact'] ?? [];
            $slugs = \is_array($impact['slugs'] ?? null) ? $impact['slugs'] : [];
        }
        $slugs = \array_values(\array_filter(\array_map(
            static fn(mixed $s): string => \ltrim(\trim((string)$s), '/'),
            $slugs,
        ), static fn(string $s): bool => $s !== ''));
        $urls = \is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [];
        $urls = \array_map('strval', $urls);
        if ($slugs !== []) {
            $hosts = self::hostsFromUrls($urls);
            if ($hosts === [] && $change->hasWebsiteContext()) {
                $hosts = self::hostsForWebsite((int)$change->websiteId());
            }
            if ($hosts !== []) {
                $adapter->purgeSlugs($hosts, $slugs);
            }
        }
        if ($urls === []) {
            return;
        }
        $adapter->purgeUrls($urls);
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private static function hostsFromUrls(array $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            $url = \trim((string)$url);
            if ($url === '' || \preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $parts = \parse_url($url);
            if (!\is_array($parts)) {
                continue;
            }
            $host = \strtolower((string)($parts['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $port = isset($parts['port']) ? (int)$parts['port'] : 0;
            $scheme = \strtolower((string)($parts['scheme'] ?? ''));
            if ($port > 0) {
                $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0);
                if ($defaultPort === 0 || $port !== $defaultPort) {
                    $host .= ':' . $port;
                }
            }
            $hosts[$host] = $host;
        }

        return \array_values($hosts);
    }

    /**
     * @return list<string>
     */
    private static function hostsForWebsite(int $websiteId): array
    {
        if ($websiteId < 0 || !\function_exists('w_query')) {
            return [];
        }
        try {
            $domains = \w_query('websites', 'getActiveWebsiteDomains', ['website_id' => $websiteId]);
            if (!\is_array($domains)) {
                return [];
            }
            $hosts = [];
            foreach ($domains as $row) {
                $host = '';
                if (\is_array($row)) {
                    $host = \strtolower(\trim((string)($row['domain'] ?? $row['host'] ?? '')));
                } elseif (\is_string($row)) {
                    $host = \strtolower(\trim($row));
                }
                $host = \preg_replace('#^https?://#i', '', $host) ?? $host;
                $host = \explode('/', $host, 2)[0] ?? '';
                if ($host !== '' && \preg_match('/^[a-z0-9._:-]+$/Di', $host) === 1) {
                    $hosts[$host] = $host;
                }
            }

            return \array_values($hosts);
        } catch (\Throwable) {
            return [];
        }
    }
}
