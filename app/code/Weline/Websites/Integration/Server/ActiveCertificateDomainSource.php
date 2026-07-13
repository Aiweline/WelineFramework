<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Server;

use Weline\Server\Api\Tls\ActiveCertificateDomainSourceInterface;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\DefaultWebsiteService;

final class ActiveCertificateDomainSource implements ActiveCertificateDomainSourceInterface
{
    private const DEFAULT_WEBSITE_ID = 0;
    private const MAX_LIMIT = 2000;
    private const DATABASE_PAGE_LIMIT = 1000;

    public function __construct(
        private readonly WebsiteDomain $domainModel,
        private readonly Website $websiteModel,
        private readonly DefaultWebsiteService $defaultWebsiteService,
    ) {
    }

    public function getActiveCertificateDomains(int $limit = self::MAX_LIMIT): array
    {
        $limit = \max(1, \min(self::MAX_LIMIT, $limit));
        $pageSize = \min(self::DATABASE_PAGE_LIMIT, $limit);
        $domains = [];
        $seen = [];
        $processedRows = 0;

        for ($page = 1; $processedRows < $limit; $page++) {
            $rows = (clone $this->domainModel)->clearQuery()
                ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                ->pagination($page, $pageSize)
                ->select()
                ->fetchArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($processedRows >= $limit) {
                    break 2;
                }
                $processedRows++;
                if (!\is_array($row)) {
                    continue;
                }
                $domain = \strtolower(\trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                $websiteId = (int)($row[WebsiteDomain::schema_fields_WEBSITE_ID] ?? self::DEFAULT_WEBSITE_ID);
                $identity = $domain . '|' . $websiteId;
                if ($domain === '' || isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $domains[] = ['domain' => $domain, 'website_id' => $websiteId];
            }

            if (\count($rows) < $pageSize) {
                break;
            }
        }

        if ($domains !== []) {
            return $domains;
        }

        $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $websites = (clone $this->websiteModel)->clearQuery()->select()->fetchArray();
        foreach ($websites as $website) {
            if (!\is_array($website)) {
                continue;
            }
            $websiteId = (int)($website[Website::schema_fields_ID] ?? self::DEFAULT_WEBSITE_ID);
            if ($websiteId < self::DEFAULT_WEBSITE_ID) {
                continue;
            }
            $domain = \parse_url((string)($website[Website::schema_fields_URL] ?? ''), PHP_URL_HOST);
            $domain = \is_string($domain) ? \strtolower(\trim($domain)) : '';
            $identity = $domain . '|' . $websiteId;
            if ($domain === '' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $domains[] = ['domain' => $domain, 'website_id' => $websiteId];
        }

        return $domains;
    }
}
