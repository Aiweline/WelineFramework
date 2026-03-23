<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Service;

use GuoLaiRen\PageBuilder\Helper\PageBuilderUrlCacheInvalidator;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;

class BlogSiteCacheInvalidator
{
    public function __construct(
        private readonly Domain $cdnDomainModel,
        private readonly CachePurger $cachePurger
    ) {
    }

    /**
     * @param array<int, int|string> $siteIds
     * @return array{
     *     application_cache_cleared: bool,
     *     site_ids: list<int>,
     *     purged_domains: list<array{site_id:int,domain_id:int,domain_name:string}>,
     *     errors: list<array{site_id:int,domain_id:int,domain_name:string,message:string}>
     * }
     */
    public function invalidateSiteIds(array $siteIds): array
    {
        $siteIds = $this->normalizeSiteIds($siteIds);
        $result = [
            'application_cache_cleared' => $this->clearApplicationCaches(),
            'site_ids' => $siteIds,
            'purged_domains' => [],
            'errors' => [],
        ];

        foreach ($siteIds as $siteId) {
            foreach ($this->getEnabledCdnDomainsBySiteId($siteId) as $domain) {
                $domainId = (int)($domain->getData(Domain::schema_fields_DOMAIN_ID) ?? 0);
                $domainName = trim((string)($domain->getData(Domain::schema_fields_DOMAIN_NAME) ?? ''));
                if ($domainId <= 0 && $domainName === '') {
                    continue;
                }

                try {
                    $this->cachePurger->purge($domainId > 0 ? $domainId : $domainName, 'everything');
                    $result['purged_domains'][] = [
                        'site_id' => $siteId,
                        'domain_id' => $domainId,
                        'domain_name' => $domainName,
                    ];
                } catch (\Throwable $throwable) {
                    $result['errors'][] = [
                        'site_id' => $siteId,
                        'domain_id' => $domainId,
                        'domain_name' => $domainName,
                        'message' => $throwable->getMessage(),
                    ];
                }
            }
        }

        return $result;
    }

    private function clearApplicationCaches(): bool
    {
        try {
            PageBuilderUrlCacheInvalidator::invalidateRouterAndRewrite();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, int|string> $siteIds
     * @return list<int>
     */
    private function normalizeSiteIds(array $siteIds): array
    {
        $result = [];
        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            if ($siteId > 0) {
                $result[$siteId] = $siteId;
            }
        }

        return array_values($result);
    }

    /**
     * @return list<object>
     */
    private function getEnabledCdnDomainsBySiteId(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $domainModel = clone $this->cdnDomainModel;

        return $domainModel->clear()
            ->where(Domain::schema_fields_SITE_ID, $siteId)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
    }
}
