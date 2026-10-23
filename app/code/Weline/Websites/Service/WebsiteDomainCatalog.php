<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Catalog\WebsiteDomainCatalogInterface;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

final class WebsiteDomainCatalog implements WebsiteDomainCatalogInterface
{
    public function __construct(
        private readonly WebsiteDomain $domain,
    ) {
    }

    public function publicBaseUrls(int $websiteId): array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return [];
        }
        $rows = (clone $this->domain)->fetchAssociationDomainsFromDatabase($websiteId);

        return self::baseUrlsFromRows($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    public static function baseUrlsFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $domain = \strtolower(\trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
            if ($domain === '' || \preg_match('/^[a-z0-9.-]+(:[0-9]+)?$/D', $domain) !== 1) {
                continue;
            }
            $subPath = \trim((string)($row[WebsiteDomain::schema_fields_SUB_PATH] ?? ''), '/');
            $scheme = (bool)($row[WebsiteDomain::schema_fields_HTTPS_ENABLED] ?? false) ? 'https' : 'http';
            $base = $scheme . '://' . $domain . ($subPath === '' ? '' : '/' . $subPath);
            $out[$base] = $base;
        }

        return \array_values($out);
    }
}
