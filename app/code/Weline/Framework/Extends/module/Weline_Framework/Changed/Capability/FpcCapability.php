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
        /** @var FpcStoreAdapterRegistry $registry */
        $registry = ObjectManager::getInstance(FpcStoreAdapterRegistry::class);
        $adapter = $registry->active();
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
            // URL 主机可能只是本机/预览入口；站点自身的全部域名必须一起清。
            $hosts = self::hostsFromUrls($urls);
            if ($change->hasWebsiteContext()) {
                $hosts = \array_values(\array_unique(\array_merge(
                    $hosts,
                    self::hostsForWebsite((int)$change->websiteId()),
                )));
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
     * 该站点的全部店面主机：站点绑定域名 + 站点 URL 主机（只取本站，不混入其它站点）。
     *
     * @return list<string>
     */
    private static function hostsForWebsite(int $websiteId): array
    {
        if ($websiteId < 0 || !\function_exists('w_query')) {
            return [];
        }
        $candidates = [];
        $domains = \w_query('websites', 'getWebsiteDomains', ['website_id' => $websiteId]);
        foreach (\is_array($domains) ? $domains : [] as $row) {
            if (\is_array($row) && (int)($row['website_id'] ?? -1) === $websiteId) {
                $candidates[] = (string)($row['domain'] ?? '');
            }
        }
        $website = \w_query('websites', 'getWebsiteById', ['website_id' => $websiteId, 'force_reload' => false]);
        if (\is_array($website)) {
            $candidates[] = self::hostsFromUrls([(string)($website['url'] ?? '')])[0] ?? '';
        }
        $hosts = [];
        foreach ($candidates as $host) {
            $host = \strtolower(\trim($host));
            if ($host !== '' && \preg_match('/^[a-z0-9._:-]+$/Di', $host) === 1) {
                $hosts[$host] = $host;
            }
        }

        return \array_values($hosts);
    }
}
