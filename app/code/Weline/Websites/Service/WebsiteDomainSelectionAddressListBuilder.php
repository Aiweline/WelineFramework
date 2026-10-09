<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;

/**
 * Build website address list from DomainSelect posts.
 *
 * pool_ids is preferred when present; domain_values (comma-separated hostnames)
 * covers legacy website_domain rows and chips that are not yet linked to a pool.
 */
final class WebsiteDomainSelectionAddressListBuilder
{
    /**
     * @param null|\Closure(int): ?array{domain:string,pool_id:int} $resolvePoolById
     * @param null|\Closure(string): int $resolvePoolIdByDomain
     */
    public function __construct(
        private readonly WebsiteSubPathValidator $subPathValidator,
        private readonly ?\Closure $resolvePoolById = null,
        private readonly ?\Closure $resolvePoolIdByDomain = null,
    ) {
    }

    /**
     * @return list<array{domain:string,sub_path:string,pool_id:int}>
     */
    public function build(array|string $poolIds, array|string $domainValues, string $subPath = ''): array
    {
        $subPath = $this->subPathValidator->assertValid($subPath);
        $list = [];
        $seen = [];

        foreach ($this->normalizeIntIds($poolIds) as $poolId) {
            $resolved = ($this->resolvePoolById ?? $this->defaultResolvePoolById(...))($poolId);
            if ($resolved === null) {
                continue;
            }
            $domain = $resolved['domain'];
            $key = $domain . '|' . $subPath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = [
                'domain' => $domain,
                'sub_path' => $subPath,
                'pool_id' => (int) $resolved['pool_id'],
            ];
        }

        foreach ($this->normalizeDomainNames($domainValues) as $domain) {
            $key = $domain . '|' . $subPath;
            if (isset($seen[$key])) {
                continue;
            }
            $poolId = ($this->resolvePoolIdByDomain ?? $this->defaultResolvePoolIdByDomain(...))($domain);
            $seen[$key] = true;
            $list[] = [
                'domain' => $domain,
                'sub_path' => $subPath,
                'pool_id' => (int) $poolId,
            ];
        }

        return $list;
    }

    /**
     * @return array{domain:string,pool_id:int}|null
     */
    private function defaultResolvePoolById(int $poolId): ?array
    {
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
        $pool->loadByPoolId($poolId);
        if (!$pool->getPoolId()) {
            return null;
        }
        $domain = \strtolower(\trim((string) $pool->getDomain()));
        if ($domain === '') {
            return null;
        }

        return [
            'domain' => $domain,
            'pool_id' => (int) $pool->getPoolId(),
        ];
    }

    private function defaultResolvePoolIdByDomain(string $domain): int
    {
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class, [], false);
        $pool->loadByDomain($domain);

        return (int) $pool->getPoolId();
    }

    /**
     * @return list<int>
     */
    private function normalizeIntIds(array|string $poolIds): array
    {
        if (\is_array($poolIds)) {
            return \array_values(\array_filter(\array_map('intval', $poolIds)));
        }

        return \array_values(\array_filter(\array_map('intval', \explode(',', (string) $poolIds))));
    }

    /**
     * @return list<string>
     */
    private function normalizeDomainNames(array|string $domainValues): array
    {
        $raw = \is_array($domainValues)
            ? $domainValues
            : \explode(',', (string) $domainValues);
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $domain = \strtolower(\trim((string) $item));
            $domain = (string) \preg_replace('#^https?://#i', '', $domain);
            $domain = \trim($domain, "/ \t");
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $out[] = $domain;
        }

        return $out;
    }
}
